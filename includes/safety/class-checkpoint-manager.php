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
	 * @param array<string, mixed> $meta            Additional metadata (source_ability, source_journal_id, state, user_id, credential_uuid).
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

		// 2. Obtain state (reusing pre-captured state or capturing freshly under lock):
		$state = $meta['state'] ?? null;
		if ( ! is_array( $state ) ) {
			$state = Full_Elementor_MCP_Checkpoint_Strategies::capture( $resource_key, $meta );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}

		// 3. Generate server-side UUIDv4:
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		if ( empty( $uuid ) ) {
			$uuid = bin2hex( random_bytes( 16 ) );
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

		// 5. Encrypt state via AEAD with bound AAD:
		$payload_schema_version = 1;
		$envelope_meta          = array(
			'checkpoint_uuid'        => $uuid,
			'resource_key'           => $resource_key,
			'payload_schema_version' => $payload_schema_version,
		);

		$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $envelope_meta );
		if ( is_wp_error( $enc ) ) {
			return $enc;
		}

		// 6. Persist immutable checkpoint row using DB UTC:
		$table = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();

		$user_id   = (int) ( $meta['user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
		$cred_uuid = $meta['credential_uuid'] ?? null;
		$ability   = $meta['source_ability'] ?? null;
		$journal_id = isset( $meta['source_journal_id'] ) ? (int) $meta['source_journal_id'] : null;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (
					checkpoint_uuid,
					created_at,
					resource_key,
					object_type,
					object_id,
					checkpoint_type,
					restore_capability,
					payload_schema_version,
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
			)
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'checkpoint_persist_failed',
				__( 'Failed to write checkpoint row to database.', 'full-elementor-mcp' )
			);
		}

		$checkpoint_id = (int) $wpdb->insert_id;

		return array(
			'id'                 => $checkpoint_id,
			'checkpoint_uuid'    => $uuid,
			'resource_key'       => $resource_key,
			'state_hash'         => $enc['state_hash'],
			'restore_capability' => $restore_capability,
		);
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
		$latest_ids = $wpdb->get_col( "SELECT MAX(id) FROM {$table} GROUP BY resource_key" );
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
	 * Orchestrates:
	 * 1. Checkpoint fetching and capability validation ('exact' required)
	 * 2. Resource binding verification
	 * 3. AEAD decryption and AAD authentication
	 * 4. Tree Validator check on restored elements
	 * 5. Current state capture & idempotent no-op check
	 * 6. Concurrency lock acquisition with fresh fencing token
	 * 7. Pre-restore safety snapshot creation of current state
	 * 8. WAL journal logging for the restore mutation
	 * 9. Fenced persistence writes through Safe_Writes
	 * 10. Post-restore state capture & exact hash comparison
	 * 11. Rollback on verification failure
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

		// 2. Validate capability:
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

		// 3. Validate target resource binding:
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

		// 4. Decrypt and verify payload:
		$state = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		// 5. Pre-write Elementor Tree validation:
		if ( isset( $state['elementor']['data'] ) && is_array( $state['elementor']['data'] ) ) {
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

		// 6. Capture current resource state & check idempotent no-op:
		$current_state = Full_Elementor_MCP_Checkpoint_Strategies::capture( $resource_key );
		if ( ! is_wp_error( $current_state ) ) {
			$current_hash = Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $current_state );
			if ( ! is_wp_error( $current_hash ) && hash_equals( (string) $row['state_hash'], $current_hash ) ) {
				return array(
					'restored'        => false,
					'noop'            => true,
					'resource_key'    => $resource_key,
					'checkpoint_uuid' => $row['checkpoint_uuid'],
					'state_hash'      => $row['state_hash'],
				);
			}
		}

		// 7. Concurrency Lock Acquisition:
		$req_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$owner_id = 'rst_' . $req_uuid;

		$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 60 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$fencing_token = (int) $lock['fencing_token'];
		$user_id       = (int) ( $options['user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
		$cred_uuid     = $options['credential_uuid'] ?? null;

		// 8. Pre-Restore Safety Checkpoint:
		$pre_restore_result = self::capture_and_save(
			$resource_key,
			'pre_restore',
			array(
				'state'             => is_array( $current_state ) ? $current_state : null,
				'source_ability'    => 'checkpoint-restore',
				'label'             => 'Pre-Restore Snapshot before ' . $row['checkpoint_uuid'],
				'user_id'           => $user_id,
				'credential_uuid'   => $cred_uuid,
			)
		);

		if ( is_wp_error( $pre_restore_result ) ) {
			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			return $pre_restore_result;
		}

		self::ensure_restore_strategy_registered();

		// 9. Begin WAL Journal entry for restore:
		$journal_id = Full_Elementor_MCP_Journal::begin( array(
			'ability'            => 'checkpoint-restore',
			'action'             => 'restore',
			'object_type'        => 'resource',
			'object_id'          => (int) $row['object_id'],
			'resource_key'       => $resource_key,
			'fencing_token'      => $fencing_token,
			'before_state'       => is_array( $current_state ) ? $current_state : array(),
			'user_id'            => $user_id,
			'credential_uuid'    => $cred_uuid,
			'rollback_supported' => true,
		) );

		if ( is_wp_error( $journal_id ) ) {
			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			return $journal_id;
		}

		$journal_id = (int) $journal_id;

		// 10. Enter Mutation Context with active fence:
		$context_token = null;
		try {
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
		} catch ( \Throwable $ctx_err ) {
			Full_Elementor_MCP_Journal::mark_failed( $journal_id, $ctx_err->getMessage(), $fencing_token );
			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			return new \WP_Error( 'mutation_context_failed', $ctx_err->getMessage() );
		}

		// 11. Execute persistent restore writes through Safe_Writes:
		$write_error = null;
		try {
			$write_res = Full_Elementor_MCP_Checkpoint_Strategies::restore( $resource_key, $state, $fencing_token, $owner_id );
			if ( is_wp_error( $write_res ) ) {
				$write_error = $write_res;
			}
		} catch ( \Throwable $e ) {
			$write_error = new \WP_Error( 'checkpoint_restore_failed', $e->getMessage() );
		}

		if ( null !== $write_error ) {
			// Attempt rollback to pre-restore current state:
			$rb_ok = false;
			if ( is_array( $current_state ) ) {
				$rb_res = Full_Elementor_MCP_Checkpoint_Strategies::restore( $resource_key, $current_state, $fencing_token, $owner_id );
				$rb_ok  = ( true === $rb_res );
			}

			if ( $context_token ) {
				Full_Elementor_MCP_Mutation_Context::leave( $context_token );
			}
			Full_Elementor_MCP_Journal::mark_failed( $journal_id, $write_error->get_error_code(), $fencing_token );
			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );

			if ( ! $rb_ok ) {
				return new \WP_Error(
					'checkpoint_restore_recovery_required',
					__( 'Restore failed midway and automatic rollback could not be verified. Recovery is required.', 'full-elementor-mcp' ),
					array(
						'checkpoint_uuid' => $row['checkpoint_uuid'],
						'journal_id'      => $journal_id,
						'resource_key'    => $resource_key,
						'original_error'  => $write_error->get_error_code(),
					)
				);
			}

			return $write_error;
		}

		// 12. Exact Post-Restore Verification:
		$post_verify = Full_Elementor_MCP_Checkpoint_Strategies::capture( $resource_key );
		$post_hash   = is_array( $post_verify ) ? Full_Elementor_MCP_Checkpoint_Crypto::hash_state( $post_verify ) : '';

		if ( ! is_string( $post_hash ) || ! hash_equals( (string) $row['state_hash'], $post_hash ) ) {
			// Persistent re-read differed from checkpoint! Verification failed.
			$rb_ok = false;
			if ( is_array( $current_state ) ) {
				$rb_res = Full_Elementor_MCP_Checkpoint_Strategies::restore( $resource_key, $current_state, $fencing_token, $owner_id );
				$rb_ok  = ( true === $rb_res );
			}

			if ( $context_token ) {
				Full_Elementor_MCP_Mutation_Context::leave( $context_token );
			}
			Full_Elementor_MCP_Journal::mark_failed( $journal_id, 'checkpoint_restore_verification_failed', $fencing_token );
			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );

			if ( ! $rb_ok ) {
				return new \WP_Error(
					'checkpoint_restore_recovery_required',
					__( 'Post-restore verification failed and rollback could not be completed.', 'full-elementor-mcp' ),
					array(
						'checkpoint_uuid' => $row['checkpoint_uuid'],
						'journal_id'      => $journal_id,
						'resource_key'    => $resource_key,
					)
				);
			}

			return new \WP_Error(
				'checkpoint_restore_verification_failed',
				__( 'Persistent state re-read after restore does not match checkpoint state hash.', 'full-elementor-mcp' ),
				array(
					'expected_hash' => $row['state_hash'],
					'actual_hash'   => $post_hash,
				)
			);
		}

		// 13. Commit WAL journal & release lock:
		Full_Elementor_MCP_Journal::commit( $journal_id, (string) $row['state_hash'], $fencing_token );
		if ( $context_token ) {
			Full_Elementor_MCP_Mutation_Context::leave( $context_token );
		}
		Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );

		return array(
			'restored'           => true,
			'checkpoint_uuid'    => $row['checkpoint_uuid'],
			'pre_restore_uuid'   => $pre_restore_result['checkpoint_uuid'],
			'resource_key'       => $resource_key,
			'state_hash'         => $row['state_hash'],
		);
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
				return (string) ( $args['resource_key'] ?? '' );
			},
			'object_id_resolver'    => static function ( array $args ): int {
				return (int) ( $args['object_id'] ?? 0 );
			},
			'capture_before'        => static function ( int $id, array $args ) {
				return Full_Elementor_MCP_Checkpoint_Strategies::capture( (string) ( $args['resource_key'] ?? '' ) );
			},
			'restore_before'        => static function ( mixed $state, array $args ) {
				return true;
			},
			'supports_rollback'     => true,
			'is_destructive'        => false,
		) );
	}
}
