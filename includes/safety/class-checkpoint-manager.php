<?php
/**
 * Centralized Checkpoint Manager.
 *
 * Manages the complete lifecycle of durable, encrypted checkpoints:
 * - Policy-driven capture before qualifying mutations
 * - Cryptographic persistence with AEAD at rest
 * - Retention pruning based on DB UTC
 * - Guarded restore engine with pre-restore safety snapshots and post-restore verification
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized checkpoint coordinator and restore engine.
 */
final class Full_Elementor_MCP_Checkpoint_Manager {

	/**
	 * Restore capability levels.
	 */
	public const CAPABILITY_EXACT         = Full_Elementor_MCP_Checkpoint_Strategies::CAPABILITY_EXACT;
	public const CAPABILITY_RECOVERY_ONLY = Full_Elementor_MCP_Checkpoint_Strategies::CAPABILITY_RECOVERY_ONLY;
	public const CAPABILITY_UNSUPPORTED   = Full_Elementor_MCP_Checkpoint_Strategies::CAPABILITY_UNSUPPORTED;

	/**
	 * Current payload schema version for new checkpoints.
	 */
	public const CURRENT_PAYLOAD_SCHEMA_VERSION = 2;

	/**
	 * Requirement levels for automatic checkpoint policy.
	 */
	public const REQUIREMENT_NONE     = 'none';
	public const REQUIREMENT_OPTIONAL = 'optional';
	public const REQUIREMENT_REQUIRED = 'required';

	/**
	 * Default retention settings.
	 */
	public const DEFAULT_RETENTION_DAYS    = 30;
	public const DEFAULT_MAX_PER_RESOURCE  = 20;
	public const DEFAULT_PRUNE_BATCH_LIMIT = 50;

	/**
	 * Test-only fault injection hook for simulating process crashes during checkpoint operations.
	 *
	 * @var ?\Closure(string, array<string, mixed>): void
	 */
	private static ?\Closure $test_fault_hook = null;

	/**
	 * Sets or clears a test-only fault injection hook.
	 *
	 * @param ?\Closure(string, array<string, mixed>): void $hook Hook closure or null.
	 */
	public static function set_test_fault_hook( ?\Closure $hook ): void {
		self::$test_fault_hook = $hook;
	}

	/**
	 * Determines the checkpoint requirement level for a given mutation call.
	 *
	 * @param string               $ability  The ability being invoked.
	 * @param array<string, mixed> $input    Input arguments.
	 * @param array<string, mixed> $strategy Registered mutation strategy.
	 * @return string REQUIREMENT_NONE, REQUIREMENT_OPTIONAL, or REQUIREMENT_REQUIRED.
	 */
	public static function get_checkpoint_requirement( string $ability, array $input = array(), ?array $strategy = null ): string {
		// 1. CREATE operations create brand-new resources; no meaningful "before" snapshot exists:
		if ( ! empty( $strategy['created_object_tracking'] ) ) {
			return self::REQUIREMENT_NONE;
		}

		$sec_profile = class_exists( 'Full_Elementor_MCP_Security_Strategies' )
			? Full_Elementor_MCP_Security_Strategies::get_security_profile( $ability, $input )
			: array();

		// 2. Active global kit state mutations require checkpoints:
		if ( 'full-elementor-mcp/set-active-kit' === $ability || str_contains( $ability, 'global-' ) ) {
			return self::REQUIREMENT_REQUIRED;
		}

		// 3. Protected resources (e.g. homepage, posts page) require checkpoints:
		if ( ! empty( $sec_profile['protected_resource_possible'] ) ) {
			return self::REQUIREMENT_REQUIRED;
		}

		// 4. Executable custom code updates on existing snippets require checkpoints:
		if ( ! empty( $sec_profile['executable_content'] ) && str_contains( $ability, 'code-snippet' ) ) {
			return self::REQUIREMENT_REQUIRED;
		}

		// 5. Irreversible / permanent deletions require a recovery snapshot:
		if ( ! empty( $sec_profile['irreversible'] ) ) {
			return self::REQUIREMENT_REQUIRED;
		}

		// 6. High-risk mutations on existing resources:
		if ( ! empty( $sec_profile['high_risk'] ) ) {
			return self::REQUIREMENT_REQUIRED;
		}

		return self::REQUIREMENT_OPTIONAL;
	}

	/**
	 * Captures current resource state, encrypts with AEAD, and persists an immutable checkpoint row.
	 *
	 * @param string               $resource_key    Canonical resource key (e.g. 'post:123' or 'global:elementor-kit-state').
	 * @param string               $checkpoint_type 'automatic', 'pre_restore', 'recovery', or 'manual'.
	 * @param array<string, mixed> $meta            Additional metadata (source_ability, source_journal_id, state, state_schema, user_id, credential_uuid).
	 * @return array{id: int, checkpoint_uuid: string, resource_key: string, state_hash: string, restore_capability: string}|\WP_Error
	 */
	public static function capture_and_save( string $resource_key, string $checkpoint_type = 'automatic', array $meta = array() ) {
		global $wpdb;

		// 1. Resolve strategy and declared restore capability:
		$strategy_name = Full_Elementor_MCP_Checkpoint_Strategies::resolve_strategy( $resource_key );
		if ( null === $strategy_name && 'recovery' !== $checkpoint_type ) {
			return new \WP_Error(
				'checkpoint_strategy_missing',
				sprintf(
					/* translators: %s: resource key */
					__( 'No checkpoint strategy available for resource "%s".', 'full-elementor-mcp' ),
					$resource_key
				),
				array( 'resource_key' => $resource_key )
			);
		}

		$restore_capability = Full_Elementor_MCP_Checkpoint_Strategies::get_restore_capability( $resource_key, $checkpoint_type, $meta );

		// 2. Obtain state: only reuse pre-captured state if explicitly marked with trusted state_schema:
		$state        = null;
		$state_schema = (string) ( $meta['state_schema'] ?? '' );
		if ( isset( $meta['state'] ) && is_array( $meta['state'] ) && ( 'checkpoint_strategy_v2' === $state_schema || 'checkpoint_strategy_v1' === $state_schema ) ) {
			$state = $meta['state'];
		} else {
			$state = Full_Elementor_MCP_Checkpoint_Strategies::capture( $resource_key, $meta );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}

		// 3. Validate state schema before encryption (fails closed if shape is incompatible):
		$schema_check = Full_Elementor_MCP_Checkpoint_Strategies::validate_state_schema( $resource_key, $state );
		if ( is_wp_error( $schema_check ) ) {
			return $schema_check;
		}

		// 4. Resolve object type and object ID:
		$object_type = 'resource';
		$object_id   = 0;
		if ( str_starts_with( $resource_key, 'post:' ) ) {
			$object_type = 'post';
			$object_id   = (int) substr( $resource_key, 5 );
		} elseif ( str_starts_with( $resource_key, 'global:' ) ) {
			$object_type = 'global';
			$object_id   = (int) ( $state['active_kit_id'] ?? 0 );
		}

		$table = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();

		$user_id    = (int) ( $meta['user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
		$cred_uuid  = $meta['credential_uuid'] ?? null;
		$ability    = $meta['source_ability'] ?? null;
		$journal_id = isset( $meta['source_journal_id'] ) ? (int) $meta['source_journal_id'] : null;

		$payload_schema_version = self::CURRENT_PAYLOAD_SCHEMA_VERSION;
		$uuid                   = '';
		$checkpoint_id          = 0;
		$enc                    = null;
		$inserted               = false;

		// 5. Bounded retry loop for unique UUID generation and DB insertion:
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			if ( ! empty( $meta['checkpoint_uuid'] ) ) {
				$uuid = sanitize_text_field( (string) $meta['checkpoint_uuid'] );
			} else {
				$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
				if ( empty( $uuid ) ) {
					$uuid = bin2hex( random_bytes( 16 ) );
				}
			}

			// Encrypt state via AEAD with bound AAD including checkpoint_type and restore_capability:
			$envelope_meta = array(
				'checkpoint_type'         => $checkpoint_type,
				'checkpoint_uuid'         => $uuid,
				'resource_key'            => $resource_key,
				'restore_capability'      => $restore_capability,
				'payload_schema_version'  => $payload_schema_version,
				'crypto_envelope_version' => 2,
			);

			$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $envelope_meta );
			if ( is_wp_error( $enc ) ) {
				return $enc;
			}

			$query = $wpdb->prepare(
				"INSERT INTO {$table} (
					checkpoint_uuid,
					created_at,
					resource_key,
					object_type,
					object_id,
					checkpoint_type,
					restore_capability,
					payload_schema_version,
					crypto_envelope_version,
					encryption_algorithm,
					key_version,
					key_id,
					nonce,
					auth_tag,
					encrypted_payload,
					state_hash,
					compression_algorithm,
					size_bytes,
					label,
					description,
					trigger_type,
					file_path,
					file_hash_hmac,
					source_ability,
					source_journal_id,
					created_by,
					credential_uuid,
					is_pinned
				) VALUES (
					%s,
					UTC_TIMESTAMP(),
					%s,
					%s,
					%d,
					%s,
					%s,
					%d,
					%d,
					%s,
					%d,
					%s,
					%s,
					%s,
					%s,
					%s,
					%s,
					%d,
					%s,
					%s,
					%s,
					%s,
					%s,
					%s,
					%d,
					%d,
					%s,
					%d
				)",
				$uuid,
				$resource_key,
				$object_type,
				$object_id,
				$checkpoint_type,
				$restore_capability,
				$payload_schema_version,
				(int) ( $enc['crypto_envelope_version'] ?? 2 ),
				$enc['encryption_algorithm'],
				$enc['key_version'],
				$enc['key_id'],
				$enc['nonce'],
				$enc['auth_tag'],
				$enc['encrypted_payload'],
				$enc['state_hash'],
				'none',
				$enc['size_bytes'],
				$meta['label'] ?? ( 'Snapshot ' . $uuid ),
				$meta['description'] ?? null,
				$checkpoint_type,
				'',
				'',
				$ability,
				$journal_id,
				$user_id > 0 ? $user_id : null,
				$cred_uuid,
				! empty( $meta['is_pinned'] ) ? 1 : 0
			);

			$res = $wpdb->query( $query );
			if ( false !== $res && $res > 0 ) {
				$inserted      = true;
				$checkpoint_id = (int) $wpdb->insert_id;
				break;
			}
			if ( ! empty( $meta['checkpoint_uuid'] ) ) {
				break;
			}
		}

		if ( ! $inserted ) {
			return new \WP_Error(
				'checkpoint_persist_failed',
				__( 'Failed to write checkpoint row to database.', 'full-elementor-mcp' )
			);
		}

		return array(
			'id'                 => $checkpoint_id,
			'checkpoint_uuid'    => $uuid,
			'resource_key'       => $resource_key,
			'state_hash'         => $enc['state_hash'],
			'restore_capability' => $restore_capability,
		);
	}

	/**
	 * Creates a manual encrypted checkpoint under canonical lock and fencing.
	 *
	 * Acquires canonical resource lock to prevent torn multi-surface snapshots,
	 * asserts fencing, captures authoritative strategy state, and writes the
	 * immutable encrypted checkpoint.
	 *
	 * @param string               $resource_key Target canonical resource key.
	 * @param array<string, mixed> $meta         Metadata (label, user_id, etc.).
	 * @return array<string, mixed>|\WP_Error Checkpoint record on success, or WP_Error on failure.
	 */
	public static function create_manual_checkpoint( string $resource_key, array $meta = array() ) {
		$resource_key = trim( $resource_key );
		if ( '' === $resource_key ) {
			return new \WP_Error( 'invalid_resource_key', __( 'Resource key cannot be empty for manual checkpoint.', 'full-elementor-mcp' ) );
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Lock_Manager' ) || ! class_exists( 'Full_Elementor_MCP_Checkpoint_Strategies' ) ) {
			return new \WP_Error( 'safety_subsystem_unavailable', __( 'Safety lock or strategies unavailable.', 'full-elementor-mcp' ) );
		}

		$req_uuid  = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$owner_id  = 'ckpt_manual_' . $req_uuid;
		$user_id   = (int) ( $meta['user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
		$cred_uuid = $meta['credential_uuid'] ?? null;
		$ability   = (string) ( $meta['source_ability'] ?? 'full-elementor-mcp/create-checkpoint' );

		$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 60 );
		if ( is_wp_error( $lock ) ) {
			if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
				Full_Elementor_MCP_Audit_Logger::log(
					Full_Elementor_MCP_Audit_Logger::EVENT_CHECKPOINT_CREATE_FAILED,
					array(
						'ability'         => $ability,
						'resource_key'    => $resource_key,
						'severity'        => Full_Elementor_MCP_Audit_Logger::SEVERITY_ERROR,
						'error_code'      => $lock->get_error_code(),
						'user_id'         => $user_id,
						'credential_uuid' => $cred_uuid,
						'request_uuid'    => $req_uuid,
					)
				);
			}
			return $lock;
		}

		$fencing_token = (int) $lock['fencing_token'];
		$lock_released = false;

		try {
			$fence_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $fencing_token );
			if ( is_wp_error( $fence_check ) ) {
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_CHECKPOINT_CREATE_FAILED,
						array(
							'ability'         => $ability,
							'resource_key'    => $resource_key,
							'severity'        => Full_Elementor_MCP_Audit_Logger::SEVERITY_ERROR,
							'error_code'      => $fence_check->get_error_code(),
							'user_id'         => $user_id,
							'credential_uuid' => $cred_uuid,
							'request_uuid'    => $req_uuid,
						)
					);
				}
				return $fence_check;
			}

			$state = Full_Elementor_MCP_Checkpoint_Strategies::capture( $resource_key, $meta );
			if ( is_wp_error( $state ) ) {
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_CHECKPOINT_CREATE_FAILED,
						array(
							'ability'         => $ability,
							'resource_key'    => $resource_key,
							'severity'        => Full_Elementor_MCP_Audit_Logger::SEVERITY_ERROR,
							'error_code'      => $state->get_error_code(),
							'user_id'         => $user_id,
							'credential_uuid' => $cred_uuid,
							'request_uuid'    => $req_uuid,
						)
					);
				}
				return $state;
			}

			$meta['state']        = $state;
			$meta['state_schema'] = 'checkpoint_strategy_v2';
			$meta['label']        = ! empty( $meta['label'] ) ? sanitize_text_field( (string) $meta['label'] ) : 'Manual Checkpoint';

			$res = self::capture_and_save( $resource_key, 'manual', $meta );

			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			$lock_released = true;

			if ( is_wp_error( $res ) ) {
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_CHECKPOINT_CREATE_FAILED,
						array(
							'ability'         => $ability,
							'resource_key'    => $resource_key,
							'severity'        => Full_Elementor_MCP_Audit_Logger::SEVERITY_ERROR,
							'error_code'      => $res->get_error_code(),
							'user_id'         => $user_id,
							'credential_uuid' => $cred_uuid,
							'request_uuid'    => $req_uuid,
						)
					);
				}
				return $res;
			}

			if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
				Full_Elementor_MCP_Audit_Logger::log(
					Full_Elementor_MCP_Audit_Logger::EVENT_CHECKPOINT_CREATED,
					array(
						'ability'         => $ability,
						'resource_key'    => $resource_key,
						'checkpoint_uuid' => $res['checkpoint_uuid'],
						'result_status'   => 'success',
						'user_id'         => $user_id,
						'credential_uuid' => $cred_uuid,
						'request_uuid'    => $req_uuid,
						'metadata'        => array(
							'checkpoint_type'    => 'manual',
							'label'              => $meta['label'],
							'restore_capability' => $res['restore_capability'] ?? 'exact',
						),
					)
				);
			}

			return $res;
		} finally {
			if ( ! $lock_released ) {
				Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			}
		}
	}

	/**
	 * Retrieves raw checkpoint database row by numeric ID or UUID.
	 *
	 * @param int|string $id_or_uuid Numeric ID or UUID string.
	 * @return array<string, mixed>|null Checkpoint row or null if not found.
	 */
	public static function get_checkpoint( int|string $id_or_uuid ): ?array {
		global $wpdb;
		$table = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();

		if ( is_numeric( $id_or_uuid ) && (int) $id_or_uuid > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id_or_uuid ),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE checkpoint_uuid = %s LIMIT 1", (string) $id_or_uuid ),
				ARRAY_A
			);
		}

		return ! empty( $row ) && is_array( $row ) ? $row : null;
	}

	/**
	 * Lists checkpoint metadata rows with filtering and pagination.
	 *
	 * Omits encrypted_payload, nonce, auth_tag by default for security and performance.
	 * Annotates rows with historical profile classification.
	 *
	 * @param array<string, mixed> $filters Filter parameters (resource_key, checkpoint_type, restore_capability, since, limit, offset).
	 * @return array<int, array<string, mixed>> Matching checkpoint records.
	 */
	public static function list_checkpoints( array $filters = array() ): array {
		global $wpdb;

		$table  = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['resource_key'] ) ) {
			$where[]  = 'resource_key = %s';
			$params[] = sanitize_text_field( (string) $filters['resource_key'] );
		}
		if ( ! empty( $filters['checkpoint_type'] ) ) {
			$where[]  = 'checkpoint_type = %s';
			$params[] = sanitize_key( (string) $filters['checkpoint_type'] );
		}
		if ( ! empty( $filters['restore_capability'] ) ) {
			$where[]  = 'restore_capability = %s';
			$params[] = sanitize_key( (string) $filters['restore_capability'] );
		}
		if ( ! empty( $filters['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( (string) $filters['since'] );
		}

		$limit  = isset( $filters['limit'] ) ? max( 1, min( 100, (int) $filters['limit'] ) ) : 20;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		$where_clause = implode( ' AND ', $where );
		$fields       = 'id, checkpoint_uuid, created_at, resource_key, object_type, object_id, checkpoint_type, restore_capability, payload_schema_version, crypto_envelope_version, encryption_algorithm, key_version, state_hash, size_bytes, label, source_ability, source_journal_id, created_by, is_pinned';

		$sql      = "SELECT {$fields} FROM {$table} WHERE {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$prepared = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['user_id']            = $row['created_by'] ?? null;
			$row['historical_profile'] = self::classify_historical_profile( $row );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Decrypts a checkpoint row into its validated plaintext state array.
	 *
	 * @param int|string|array<string, mixed> $checkpoint Numeric ID, UUID string, or raw DB row.
	 * @return array<string, mixed>|\WP_Error Plaintext state dictionary or WP_Error.
	 */
	public static function decrypt_checkpoint( int|string|array $checkpoint ) {
		$row = is_array( $checkpoint ) ? $checkpoint : self::get_checkpoint( $checkpoint );

		if ( empty( $row ) ) {
			return new \WP_Error( 'checkpoint_not_found', __( 'Checkpoint not found.', 'full-elementor-mcp' ) );
		}

		return Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row );
	}

	/**
	 * Prunes historical checkpoints according to retention policies using DB UTC.
	 *
	 * Guarantees:
	 * - Newest checkpoint per resource is NEVER pruned.
	 * - Pinned checkpoints (is_pinned = 1) are NEVER pruned.
	 * - Deletions are bounded by batch limit.
	 *
	 * @param string|null $resource_key    Optional resource key to limit pruning scope.
	 * @param int         $retention_days  Maximum age in days (default 30).
	 * @param int         $max_per_resource Maximum checkpoints retained per resource (default 20).
	 * @param int         $batch_limit     Maximum rows deleted per invocation (default 50).
	 * @return int Count of pruned rows.
	 */
	public static function prune(
		?string $resource_key = null,
		int $retention_days = self::DEFAULT_RETENTION_DAYS,
		int $max_per_resource = self::DEFAULT_MAX_PER_RESOURCE,
		int $batch_limit = self::DEFAULT_PRUNE_BATCH_LIMIT
	): int {
		global $wpdb;
		$table = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();

		// 1. Identify newest checkpoint ID per resource (these must NEVER be pruned):
		$latest_ids  = $wpdb->get_col( "SELECT MAX(id) FROM {$table} GROUP BY resource_key" );
		$exclude_ids = ! empty( $latest_ids ) ? array_map( 'intval', $latest_ids ) : array( 0 );
		$exclude_in  = implode( ',', $exclude_ids );

		$total_pruned = 0;

		// 2. Age-based pruning (older than retention_days, excluding latest and pinned):
		$where_res = '';
		if ( ! empty( $resource_key ) ) {
			$where_res = $wpdb->prepare( ' AND resource_key = %s', $resource_key );
		}

		$old_candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE id NOT IN ({$exclude_in})
				   AND is_pinned = 0
				   AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				   {$where_res}
				 ORDER BY id ASC
				 LIMIT %d",
				$retention_days,
				$batch_limit
			)
		);

		if ( ! empty( $old_candidates ) ) {
			$del_ids = implode( ',', array_map( 'intval', $old_candidates ) );
			$deleted = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$del_ids})" );
			if ( is_int( $deleted ) ) {
				$total_pruned += $deleted;
			}
		}

		// 3. Count-based pruning per resource (exceeding max_per_resource):
		$res_list = ! empty( $resource_key )
			? array( $resource_key )
			: $wpdb->get_col( "SELECT DISTINCT resource_key FROM {$table}" );

		foreach ( (array) $res_list as $res ) {
			if ( empty( $res ) ) {
				continue;
			}

			$count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE resource_key = %s", $res )
			);

			if ( $count > $max_per_resource ) {
				$overflow = $count - $max_per_resource;
				$limit    = min( $overflow, $batch_limit );

				$overflow_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM {$table}
						 WHERE resource_key = %s
						   AND id NOT IN ({$exclude_in})
						   AND is_pinned = 0
						 ORDER BY id ASC
						 LIMIT %d",
						$res,
						$limit
					)
				);

				if ( ! empty( $overflow_ids ) ) {
					$del_ids = implode( ',', array_map( 'intval', $overflow_ids ) );
					$deleted = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$del_ids})" );
					if ( is_int( $deleted ) ) {
						$total_pruned += $deleted;
					}
				}
			}
		}

		return $total_pruned;
	}

	// -------------------------------------------------------------------------
	// Checkpoint Restore Engine
	// -------------------------------------------------------------------------

	/**
	 * Restores a historical checkpoint to its target resource with full Phase 4 safety.
	 *
	 * Order of Operations:
	 * 1. Fetch checkpoint record
	 * 2. Validate payload schema version
	 * 3. Decrypt and authenticate payload via AEAD / bound AAD
	 * 4. Validate restore capability ('exact' required)
	 * 5. Validate target resource binding and strategy identity
	 * 6. Validate state schema & pre-write Elementor Tree validation
	 * 7. Generate restore owner ID
	 * 8. ACQUIRE CANONICAL RESOURCE LOCK BEFORE CAPTURING AUTHORITATIVE STATE
	 * 9. Capture authoritative CURRENT resource state under lock
	 * 10. Decide idempotent no-op under lock
	 * 11. Create pre_restore safety checkpoint from locked current state
	 * 12. Begin WAL journal entry with same locked current state
	 * 13. Enter mutation context with active fence
	 * 14. Execute persistent restore writes through Safe_Writes
	 * 15. Post-restore state verification (exact hash check)
	 * 16. On failure: exact-verified rollback to locked current state
	 * 17. Commit actual restored state to WAL journal (check commit result)
	 * 18. Release lock and leave context safely in finally block
	 *
	 * @param int|string           $id_or_uuid Checkpoint numeric ID or UUID.
	 * @param array<string, mixed> $options    Optional controls (target_resource_key, user_id, credential_uuid).
	 * @return array<string, mixed>|\WP_Error Restore outcome or WP_Error.
	 */
	public static function restore( int|string $id_or_uuid, array $options = array() ) {
		// 1. Fetch checkpoint record:
		$row = self::get_checkpoint( $id_or_uuid );
		if ( empty( $row ) ) {
			return new \WP_Error( 'checkpoint_not_found', __( 'Target checkpoint not found.', 'full-elementor-mcp' ) );
		}

		$resource_key = (string) $row['resource_key'];

		// 1.1. Validate and authenticate crypto envelope generation:
		$envelope_version = isset( $row['crypto_envelope_version'] ) ? (int) $row['crypto_envelope_version'] : 0;
		if ( 0 === $envelope_version ) {
			$detected = Full_Elementor_MCP_Checkpoint_Crypto::detect_envelope_version( $row );
			if ( $detected > 0 ) {
				$envelope_version = $detected;
				$row['crypto_envelope_version'] = $detected;
				$table = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET crypto_envelope_version = %d WHERE id = %d", $detected, (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				return new \WP_Error(
					'checkpoint_legacy_envelope_unresolved',
					__( 'Historical checkpoint crypto envelope format is unresolved and key material is unavailable for authenticated detection.', 'full-elementor-mcp' ),
					array( 'checkpoint_uuid' => $row['checkpoint_uuid'] ?? '' )
				);
			}
		}

		// Security Boundary: Envelope v1 did NOT bind or authenticate restore_capability in AAD.
		// Never trust unauthenticated DB metadata for exact restore decision!
		if ( 1 === $envelope_version ) {
			return new \WP_Error(
				'checkpoint_legacy_capability_untrusted',
				__( 'Legacy envelope v1 checkpoints did not authenticate restore capability and cannot be restored as exact state. Recovery inspection only.', 'full-elementor-mcp' ),
				array(
					'checkpoint_uuid'         => $row['checkpoint_uuid'] ?? '',
					'crypto_envelope_version' => 1,
					'effective_capability'    => self::CAPABILITY_RECOVERY_ONLY,
				)
			);
		}

		if ( 2 !== $envelope_version ) {
			return new \WP_Error(
				'checkpoint_envelope_unsupported',
				sprintf(
					/* translators: %d: envelope version */
					__( 'Unsupported checkpoint crypto envelope version: %d.', 'full-elementor-mcp' ),
					$envelope_version
				),
				array( 'crypto_envelope_version' => $envelope_version )
			);
		}

		// 2. Validate payload schema version (fail closed on unknown schemas):
		$schema_version = (int) ( $row['payload_schema_version'] ?? 1 );
		if ( 1 !== $schema_version && 2 !== $schema_version ) {
			return new \WP_Error(
				'checkpoint_schema_unsupported',
				sprintf(
					/* translators: %d: schema version */
					__( 'Unsupported checkpoint payload schema version: %d.', 'full-elementor-mcp' ),
					$schema_version
				),
				array( 'payload_schema_version' => $schema_version )
			);
		}

		// 3. Decrypt and verify payload via AEAD (validates AAD, tags, and state hash):
		$state = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		// 3.1. Resolve internal semantic payload profile:
		$payload_profile = Full_Elementor_MCP_Checkpoint_Strategies::resolve_payload_profile( $row, $state );
		if ( is_wp_error( $payload_profile ) ) {
			return $payload_profile;
		}

		// 4. Validate capability only after authenticated metadata is trustworthy:
		if ( self::CAPABILITY_EXACT !== $row['restore_capability'] ) {
			return new \WP_Error(
				'checkpoint_restore_unsupported',
				sprintf(
					/* translators: %s: capability */
					__( 'Checkpoint has "%s" capability and does not support exact automatic restoration.', 'full-elementor-mcp' ),
					$row['restore_capability']
				),
				array( 'capability' => $row['restore_capability'] )
			);
		}

		// 4.1. Reject legacy schema v1 snippet restore only when lacking modern exact fields:
		if ( Full_Elementor_MCP_Checkpoint_Strategies::PROFILE_LEGACY_B16_SNIPPET_V1 === $payload_profile ) {
			return new \WP_Error(
				'checkpoint_legacy_snippet_not_exact',
				__( 'Historical snippet checkpoints lack required exact fields (template_type, edit_mode) and cannot perform exact restoration. Classified as recovery-only.', 'full-elementor-mcp' ),
				array(
					'checkpoint_uuid'      => $row['checkpoint_uuid'] ?? '',
					'effective_capability' => self::CAPABILITY_RECOVERY_ONLY,
				)
			);
		}

		// 5. Validate target resource binding & decrypted state identity:
		if ( ! empty( $options['target_resource_key'] ) && $options['target_resource_key'] !== $resource_key ) {
			return new \WP_Error(
				'checkpoint_resource_mismatch',
				sprintf(
					/* translators: 1: checkpoint key, 2: target key */
					__( 'Checkpoint was captured for resource "%1$s" but target was "%2$s". Cross-resource restores are prohibited.', 'full-elementor-mcp' ),
					$resource_key,
					$options['target_resource_key']
				)
			);
		}

		$strategy_check = Full_Elementor_MCP_Checkpoint_Strategies::validate_state_schema( $resource_key, $state, $payload_profile );
		if ( is_wp_error( $strategy_check ) ) {
			return $strategy_check;
		}

		// 6. Pre-write Elementor Tree validation:
		if ( isset( $state['elementor']['data'] ) ) {
			if ( ! is_array( $state['elementor']['data'] ) ) {
				return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Restored Elementor data must be an array.', 'full-elementor-mcp' ) );
			}
			if ( class_exists( 'Full_Elementor_MCP_Tree_Validator' ) ) {
				$tree_val = Full_Elementor_MCP_Tree_Validator::validate_document( $state['elementor']['data'], array( 'operation' => 'restore', 'resource_key' => $resource_key ) );
				if ( is_wp_error( $tree_val ) ) {
					return new \WP_Error(
						'post_mutation_validation_failed',
						sprintf(
							/* translators: %s: message */
							__( 'Restored Elementor tree failed validation: %s', 'full-elementor-mcp' ),
							$tree_val->get_error_message()
						),
						$tree_val->get_error_data()
					);
				}
			}
		}

		// 7. Generate restore owner and acquire canonical resource lock BEFORE capturing authoritative current state:
		$req_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$owner_id = 'rst_' . $req_uuid;

		$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 60 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$fencing_token = (int) $lock['fencing_token'];
		$user_id       = (int) ( $options['user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
		$cred_uuid     = $options['credential_uuid'] ?? null;

		$context_token = null;
		$lock_released = false;

		try {
			// 8. Capture authoritative CURRENT resource state UNDER LOCK (always modern v2 for recovery snapshot):
			$current_state_v2 = Full_Elementor_MCP_Checkpoint_Strategies::capture( $resource_key );
			if ( is_wp_error( $current_state_v2 ) ) {
				return $current_state_v2;
			}

			// For no-op comparison: compare state hash in the checkpoint's profile domain:
			if ( Full_Elementor_MCP_Checkpoint_Strategies::PROFILE_LEGACY_B16_POST_V1 === $payload_profile ) {
				$current_state_compat = Full_Elementor_MCP_Checkpoint_Strategies::capture_as_schema_v1( $resource_key );
				if ( is_wp_error( $current_state_compat ) ) {
					$current_hash = '';
				} else {
					$current_hash = Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $current_state_compat );
				}
			} elseif ( Full_Elementor_MCP_Checkpoint_Strategies::PROFILE_TRANSITIONAL_346_POST === $payload_profile ) {
				$current_state_compat = Full_Elementor_MCP_Checkpoint_Strategies::capture_as_transitional_346_post_v1( $resource_key );
				if ( is_wp_error( $current_state_compat ) ) {
					$current_hash = '';
				} else {
					$current_hash = Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $current_state_compat );
				}
			} elseif ( Full_Elementor_MCP_Checkpoint_Strategies::PROFILE_TRANSITIONAL_346_SNIP === $payload_profile ) {
				$current_state_compat = Full_Elementor_MCP_Checkpoint_Strategies::capture_as_transitional_346_snippet_v1( $resource_key );
				if ( is_wp_error( $current_state_compat ) ) {
					$current_hash = '';
				} else {
					$current_hash = Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $current_state_compat );
				}
			} else {
				$current_hash = Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $current_state_v2 );
			}

			if ( is_wp_error( $current_hash ) ) {
				return $current_hash;
			}

			// 9. Decide idempotent no-op under lock:
			if ( hash_equals( (string) $row['state_hash'], (string) $current_hash ) ) {
				// 9.1. Immediately re-assert fencing token ownership under lock:
				$fence_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $fencing_token );
				if ( is_wp_error( $fence_check ) ) {
					return $fence_check;
				}

				// 9.2. Call confirmation consumer and CHECK its result:
				if ( isset( $options['_confirmation_consumer'] ) && is_callable( $options['_confirmation_consumer'] ) ) {
					$consume_res = ( $options['_confirmation_consumer'] )();
					if ( is_wp_error( $consume_res ) ) {
						return $consume_res;
					}
				}

				Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
				$lock_released = true;
				return array(
					'restored'              => false,
					'noop'                  => true,
					'resource_key'          => $resource_key,
					'checkpoint_uuid'       => $row['checkpoint_uuid'],
					'state_hash'            => $row['state_hash'],
					'confirmation_consumed' => true,
				);
			}

			// 10. Pre-Restore Safety Checkpoint from locked current state (ALWAYS modern v2 schema and envelope 2):
			$pre_restore_result = self::capture_and_save(
				$resource_key,
				'pre_restore',
				array(
					'state'           => $current_state_v2,
					'state_schema'    => 'checkpoint_strategy_v2',
					'source_ability'  => 'checkpoint-restore',
					'label'           => 'Pre-Restore Snapshot before ' . $row['checkpoint_uuid'],
					'user_id'         => $user_id,
					'credential_uuid' => $cred_uuid,
				)
			);

			if ( is_wp_error( $pre_restore_result ) ) {
				return $pre_restore_result;
			}

			self::ensure_restore_strategy_registered();

			// 11. Begin WAL Journal entry using the SAME locked current state (authoritative modern v2):
			$journal_id = Full_Elementor_MCP_Journal::begin( array(
				'ability'            => 'checkpoint-restore',
				'action'             => 'restore',
				'object_type'        => 'resource',
				'object_id'          => (int) $row['object_id'],
				'resource_key'       => $resource_key,
				'fencing_token'      => $fencing_token,
				'before_state'       => $current_state_v2,
				'user_id'            => $user_id,
				'credential_uuid'    => $cred_uuid,
				'rollback_supported' => true,
			) );

			if ( is_wp_error( $journal_id ) ) {
				return $journal_id;
			}

			$journal_id = (int) $journal_id;

			if ( null !== self::$test_fault_hook ) {
				call_user_func( self::$test_fault_hook, 'after_restore_wal_begin', array(
					'journal_id'    => $journal_id,
					'resource_key'  => $resource_key,
					'fencing_token' => $fencing_token,
					'owner_id'      => $owner_id,
					'checkpoint_id' => $row['id'],
				) );
			}

			// 12. Enter Mutation Context with active fence:
			$context_token = Full_Elementor_MCP_Mutation_Context::enter( array(
				'ability'         => 'checkpoint-restore',
				'request_uuid'    => $req_uuid,
				'user_id'         => $user_id,
				'credential_uuid' => $cred_uuid,
				'resource_key'    => $resource_key,
				'object_id'       => (int) $row['object_id'],
				'owner_id'        => $owner_id,
				'fencing_token'   => $fencing_token,
				'journal_id'      => $journal_id,
				'is_dry_run'      => false,
				'is_rollback'     => false,
				'is_readonly'     => false,
				'is_create'       => false,
			) );

			// 12.8. Final pre-write fencing assertion immediately before confirmation consumption:
			$fence_preflight = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $fencing_token );
			if ( is_wp_error( $fence_preflight ) ) {
				Full_Elementor_MCP_Journal::mark_failed( $journal_id, $fence_preflight->get_error_code(), $fencing_token );
				return $fence_preflight;
			}

			// 12.9. Atomic confirmation consumption at final execution readiness:
			if ( isset( $options['_confirmation_consumer'] ) && is_callable( $options['_confirmation_consumer'] ) ) {
				$consume_res = ( $options['_confirmation_consumer'] )();
				if ( is_wp_error( $consume_res ) ) {
					Full_Elementor_MCP_Journal::mark_failed( $journal_id, $consume_res->get_error_code(), $fencing_token );
					return $consume_res;
				}
			}

			// 13. Execute persistent restore writes through Safe_Writes with payload profile dispatch:
			$write_error = null;
			try {
				$write_res = Full_Elementor_MCP_Checkpoint_Strategies::restore( $resource_key, $state, $fencing_token, $owner_id, $payload_profile );
				if ( is_wp_error( $write_res ) ) {
					$write_error = $write_res;
				}
			} catch ( \Throwable $e ) {
				$write_error = new \WP_Error( 'checkpoint_restore_failed', $e->getMessage() );
			}

			if ( null !== $write_error ) {
				// Rollback to pre-restore current state using modern v2 schema:
				$rb_res          = Full_Elementor_MCP_Checkpoint_Strategies::restore( $resource_key, $current_state_v2, $fencing_token, $owner_id, 2 );
				$recaptured      = Full_Elementor_MCP_Checkpoint_Strategies::capture( $resource_key );
				$recaptured_hash = is_array( $recaptured ) ? Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $recaptured ) : '';
				$current_v2_hash = Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $current_state_v2 );
				$rb_exact        = is_string( $recaptured_hash ) && is_string( $current_v2_hash ) && hash_equals( $current_v2_hash, $recaptured_hash );

				Full_Elementor_MCP_Journal::mark_failed( $journal_id, $write_error->get_error_code(), $fencing_token );

				if ( ! $rb_exact ) {
					return new \WP_Error(
						'checkpoint_restore_recovery_required',
						__( 'Restore failed midway and automatic rollback could not be verified. Recovery is required.', 'full-elementor-mcp' ),
						array(
							'checkpoint_uuid' => $row['checkpoint_uuid'],
							'journal_id'      => $journal_id,
							'resource_key'    => $resource_key,
							'original_error'  => $write_error->get_error_code(),
							'_safety_outcome' => array(
								'write_started'     => true,
								'rollback_verified' => false,
								'known_safe'        => false,
								'recovery_required' => true,
								'journal_id'        => $journal_id,
							),
						)
					);
				}

				$existing_data = is_array( $write_error->get_error_data() ) ? $write_error->get_error_data() : array();
				$merged_data   = array_merge(
					$existing_data,
					array(
						'checkpoint_uuid' => $row['checkpoint_uuid'],
						'journal_id'      => $journal_id,
						'resource_key'    => $resource_key,
						'_safety_outcome' => array(
							'write_started'     => true,
							'rollback_verified' => true,
							'known_safe'        => true,
							'recovery_required' => false,
							'journal_id'        => $journal_id,
						),
					)
				);

				return new \WP_Error( $write_error->get_error_code(), $write_error->get_error_message(), $merged_data );
			}

			// 14. Exact Post-Restore Verification in the matching profile domain:
			if ( Full_Elementor_MCP_Checkpoint_Strategies::PROFILE_LEGACY_B16_POST_V1 === $payload_profile ) {
				$post_verify = Full_Elementor_MCP_Checkpoint_Strategies::capture_as_schema_v1( $resource_key );
			} elseif ( Full_Elementor_MCP_Checkpoint_Strategies::PROFILE_TRANSITIONAL_346_POST === $payload_profile ) {
				$post_verify = Full_Elementor_MCP_Checkpoint_Strategies::capture_as_transitional_346_post_v1( $resource_key );
			} elseif ( Full_Elementor_MCP_Checkpoint_Strategies::PROFILE_TRANSITIONAL_346_SNIP === $payload_profile ) {
				$post_verify = Full_Elementor_MCP_Checkpoint_Strategies::capture_as_transitional_346_snippet_v1( $resource_key );
			} else {
				$post_verify = Full_Elementor_MCP_Checkpoint_Strategies::capture( $resource_key );
			}
			$post_hash = is_array( $post_verify ) ? Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $post_verify ) : '';

			if ( ! is_string( $post_hash ) || ! hash_equals( (string) $row['state_hash'], $post_hash ) ) {
				// Verification failed! Rollback using modern v2 schema and assert exact equality:
				$rb_res          = Full_Elementor_MCP_Checkpoint_Strategies::restore( $resource_key, $current_state_v2, $fencing_token, $owner_id, 2 );
				$recaptured      = Full_Elementor_MCP_Checkpoint_Strategies::capture( $resource_key );
				$recaptured_hash = is_array( $recaptured ) ? Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $recaptured ) : '';
				$current_v2_hash = Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $current_state_v2 );
				$rb_exact        = is_string( $recaptured_hash ) && is_string( $current_v2_hash ) && hash_equals( $current_v2_hash, $recaptured_hash );

				Full_Elementor_MCP_Journal::mark_failed( $journal_id, 'checkpoint_restore_verification_failed', $fencing_token );

				if ( ! $rb_exact ) {
					return new \WP_Error(
						'checkpoint_restore_recovery_required',
						__( 'Post-restore verification failed and rollback could not be completed. Recovery is required.', 'full-elementor-mcp' ),
						array(
							'checkpoint_uuid' => $row['checkpoint_uuid'],
							'journal_id'      => $journal_id,
							'resource_key'    => $resource_key,
							'_safety_outcome' => array(
								'write_started'     => true,
								'rollback_verified' => false,
								'known_safe'        => false,
								'recovery_required' => true,
								'journal_id'        => $journal_id,
							),
						)
					);
				}

				return new \WP_Error(
					'checkpoint_restore_verification_failed',
					__( 'Persistent state re-read after restore does not match checkpoint state hash.', 'full-elementor-mcp' ),
					array(
						'expected_hash'   => $row['state_hash'],
						'actual_hash'     => $post_hash,
						'checkpoint_uuid' => $row['checkpoint_uuid'],
						'journal_id'      => $journal_id,
						'resource_key'    => $resource_key,
						'_safety_outcome' => array(
							'write_started'     => true,
							'rollback_verified' => true,
							'known_safe'        => true,
							'recovery_required' => false,
							'journal_id'        => $journal_id,
						),
					)
				);
			}

			// 15. Commit authoritative modern restored state to WAL journal (always modern v2):
			$commit_state = Full_Elementor_MCP_Checkpoint_Strategies::capture( $resource_key );
			$commit_res   = Full_Elementor_MCP_Journal::commit( $journal_id, is_array( $commit_state ) ? $commit_state : $post_verify, $fencing_token );
			if ( true !== $commit_res ) {
				return new \WP_Error(
					'checkpoint_restore_journal_commit_failed',
					__( 'Persistent restore completed but WAL journal commit failed. Recovery is required.', 'full-elementor-mcp' ),
					array(
						'checkpoint_uuid' => $row['checkpoint_uuid'],
						'journal_id'      => $journal_id,
						'resource_key'    => $resource_key,
						'_safety_outcome' => array(
							'write_started'     => true,
							'rollback_verified' => false,
							'known_safe'        => false,
							'recovery_required' => true,
							'journal_id'        => $journal_id,
						),
					)
				);
			}

			Full_Elementor_MCP_Mutation_Context::leave( $context_token );
			$context_token = null;

			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			$lock_released = true;

			return array(
				'restored'         => true,
				'checkpoint_uuid'  => $row['checkpoint_uuid'],
				'pre_restore_uuid' => $pre_restore_result['checkpoint_uuid'],
				'journal_id'       => $journal_id,
				'resource_key'     => $resource_key,
				'state_hash'       => $row['state_hash'],
			);
		} finally {
			if ( $context_token ) {
				Full_Elementor_MCP_Mutation_Context::leave( $context_token );
			}
			if ( ! $lock_released ) {
				Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			}
		}
	}

	/**
	 * Ensures the checkpoint-restore mutation strategy is registered in the Mutation Registry.
	 */
	public static function ensure_restore_strategy_registered(): void {
		if ( ! class_exists( 'Full_Elementor_MCP_Mutation_Registry' ) ) {
			return;
		}

		if ( null !== Full_Elementor_MCP_Mutation_Registry::get( 'checkpoint-restore' ) ) {
			return;
		}

		Full_Elementor_MCP_Mutation_Registry::register( array(
			'ability'               => 'checkpoint-restore',
			'action'                => 'restore',
			'object_type'           => 'resource',
			'category'              => Full_Elementor_MCP_Mutation_Registry::CATEGORY_COMPOSITE,
			'resource_key_resolver' => static function ( array $args ): string {
				return (string) ( $args['resource_key'] ?? ( $args['rollback_resource_key'] ?? ( ! empty( $args['post_id'] ) ? 'post:' . $args['post_id'] : ( ! empty( $args['object_id'] ) ? 'post:' . $args['object_id'] : '' ) ) ) );
			},
			'object_id_resolver'    => static function ( array $args ): int {
				return (int) ( $args['object_id'] ?? ( $args['post_id'] ?? 0 ) );
			},
			'capture_before'        => static function ( int $id, array $args ) {
				$res_key = (string) ( $args['resource_key'] ?? ( $args['rollback_resource_key'] ?? ( $id > 0 ? 'post:' . $id : '' ) ) );
				return Full_Elementor_MCP_Checkpoint_Strategies::capture( $res_key );
			},
			'capture_after'         => static function ( int $id, array $args ) {
				$res_key = (string) ( $args['resource_key'] ?? ( $args['rollback_resource_key'] ?? ( $id > 0 ? 'post:' . $id : '' ) ) );
				return Full_Elementor_MCP_Checkpoint_Strategies::capture( $res_key );
			},
			'restore_before'        => static function ( mixed $state, array $args ) {
				$res_key = (string) ( $args['resource_key'] ?? ( $args['rollback_resource_key'] ?? ( ! empty( $args['object_id'] ) ? 'post:' . $args['object_id'] : '' ) ) );
				if ( empty( $res_key ) || ! is_array( $state ) ) {
					return new \WP_Error(
						'invalid_restore_state',
						__( 'Cannot rollback checkpoint restore: invalid state or resource key.', 'full-elementor-mcp' )
					);
				}
				$fencing_token = (int) ( $args['fencing_token'] ?? ( $args['caller_fencing_token'] ?? 0 ) );
				$owner_id      = (string) ( $args['owner_id'] ?? ( $args['current_owner_id'] ?? '' ) );

				$context_token = null;
				if ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) && ! Full_Elementor_MCP_Mutation_Context::has_active_context() ) {
					$context_token = Full_Elementor_MCP_Mutation_Context::enter( array(
						'ability'       => 'checkpoint-restore',
						'resource_key'  => $res_key,
						'object_id'     => (int) ( $args['object_id'] ?? 0 ),
						'owner_id'      => $owner_id,
						'fencing_token' => $fencing_token,
						'is_rollback'   => true,
					) );
				}

				try {
					return Full_Elementor_MCP_Checkpoint_Strategies::restore( $res_key, $state, $fencing_token, $owner_id );
				} finally {
					if ( $context_token ) {
						Full_Elementor_MCP_Mutation_Context::leave( $context_token );
					}
				}
			},
			'supports_rollback'     => true,
			'is_destructive'        => false,
		) );
	}

	/**
	 * Delegate for MCP ability 'full-elementor-mcp/restore-checkpoint'.
	 *
	 * Supports dry_run preview as well as non-dry-run execution.
	 *
	 * @param array<string, mixed> $input Input arguments.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public static function execute_restore_ability( array $input ) {
		$id_or_uuid = $input['checkpoint_id'] ?? ( $input['checkpoint_uuid'] ?? ( $input['id'] ?? null ) );
		if ( empty( $id_or_uuid ) ) {
			return new \WP_Error( 'missing_checkpoint_identifier', __( 'checkpoint_id or checkpoint_uuid is required for restore-checkpoint.', 'full-elementor-mcp' ) );
		}

		$is_dry_run = ( true === ( $input['dry_run'] ?? false ) ) || ( true === ( $input['_safety']['dry_run'] ?? false ) );

		$row = self::get_checkpoint( $id_or_uuid );
		if ( empty( $row ) ) {
			return new \WP_Error( 'checkpoint_not_found', __( 'Target checkpoint not found.', 'full-elementor-mcp' ), array( 'checkpoint' => $id_or_uuid ) );
		}

		$resource_key = (string) $row['resource_key'];

		$target_selector = $input['target_resource'] ?? ( $input['target_resource_key'] ?? null );
		if ( ! empty( $target_selector ) && (string) $target_selector !== (string) $row['resource_key'] ) {
			return new \WP_Error(
				'checkpoint_resource_mismatch',
				__( 'Specified target resource does not match checkpoint resource.', 'full-elementor-mcp' ),
				array(
					'expected' => (string) $row['resource_key'],
					'provided' => (string) $target_selector,
				)
			);
		}

		$user_id         = isset( $input['_user_id'] ) ? (int) $input['_user_id'] : get_current_user_id();
		$credential_uuid = isset( $input['_cred_uuid'] ) ? (string) $input['_cred_uuid'] : null;

		// Audit restore start:
		if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
			Full_Elementor_MCP_Audit_Logger::log(
				Full_Elementor_MCP_Audit_Logger::EVENT_CHECKPOINT_RESTORE_STARTED,
				array(
					'ability'         => 'full-elementor-mcp/restore-checkpoint',
					'resource_key'    => $resource_key,
					'checkpoint_uuid' => $row['checkpoint_uuid'],
					'user_id'         => $user_id,
					'credential_uuid' => $credential_uuid,
					'metadata'        => array( 'dry_run' => $is_dry_run ),
				)
			);
		}

		if ( $is_dry_run ) {
			$dec     = self::decrypt_checkpoint( $row );
			$can_dec = ! is_wp_error( $dec );
			$profile = $can_dec ? Full_Elementor_MCP_Checkpoint_Strategies::resolve_payload_profile( $row, $dec ) : 'unknown';

			return array(
				'dry_run'               => true,
				'allowed'               => true,
				'checkpoint_uuid'       => $row['checkpoint_uuid'],
				'resource_key'          => $resource_key,
				'restore_capability'    => $row['restore_capability'],
				'can_decrypt'           => $can_dec,
				'payload_profile'       => is_string( $profile ) ? $profile : 'unknown',
				'confirmation_required' => true,
			);
		}

		$options = array(
			'user_id'                => $user_id,
			'credential_uuid'        => $credential_uuid,
			'_confirmation_consumer' => $input['_confirmation_consumer'] ?? null,
		);

		$res = self::restore( $id_or_uuid, $options );

		if ( is_wp_error( $res ) ) {
			if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
				Full_Elementor_MCP_Audit_Logger::log(
					Full_Elementor_MCP_Audit_Logger::EVENT_CHECKPOINT_RESTORE_FAILED,
					array(
						'ability'         => 'full-elementor-mcp/restore-checkpoint',
						'resource_key'    => $resource_key,
						'checkpoint_uuid' => $row['checkpoint_uuid'],
						'severity'        => Full_Elementor_MCP_Audit_Logger::SEVERITY_ERROR,
						'error_code'      => $res->get_error_code(),
						'user_id'         => $user_id,
						'credential_uuid' => $credential_uuid,
					)
				);
			}
			return $res;
		}

		if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
			Full_Elementor_MCP_Audit_Logger::log(
				Full_Elementor_MCP_Audit_Logger::EVENT_CHECKPOINT_RESTORED,
				array(
					'ability'         => 'full-elementor-mcp/restore-checkpoint',
					'resource_key'    => $resource_key,
					'checkpoint_uuid' => $row['checkpoint_uuid'],
					'change_id'       => $res['journal_id'] ?? null,
					'result_status'   => 'success',
					'user_id'         => $user_id,
					'credential_uuid' => $credential_uuid,
					'metadata'        => array(
						'pre_restore_uuid' => $res['pre_restore_uuid'] ?? null,
						'noop'             => ! empty( $res['noop'] ),
					),
				)
			);
		}

		return $res;
	}

	/**
	 * Classifies the historical profile of a checkpoint from its metadata.
	 *
	 * Never decrypts the payload.
	 *
	 * @param array<string, mixed> $row Checkpoint DB row.
	 * @return string Historical profile label.
	 */
	public static function classify_historical_profile( array $row ): string {
		$envelope_version = isset( $row['crypto_envelope_version'] ) ? (int) $row['crypto_envelope_version'] : 2;
		if ( 1 === $envelope_version ) {
			return 'legacy_518_v1';
		}
		$schema_version = isset( $row['payload_schema_version'] ) ? (int) $row['payload_schema_version'] : 2;
		if ( 2 === $schema_version ) {
			return 'modern_v2';
		}
		return 'transitional_v1';
	}
}
