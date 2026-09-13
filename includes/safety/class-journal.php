<?php
/**
 * Write-Ahead Journal (WAL) for Safe Elementor MCP.
 *
 * Implements a durable, crash-resilient Write-Ahead Logging system that persists
 * pre-mutation state before any persistent changes occur. Enforces an explicit
 * state machine, deterministic state hashing, conflict detection, fencing token
 * validation, and safe rollback orchestration.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages Write-Ahead Journal entries, state serialization, rollback, and recovery.
 */
class Full_Elementor_MCP_Journal {

	/**
	 * Journal state machine statuses.
	 */
	public const STATUS_PENDING     = 'pending';
	public const STATUS_COMMITTED   = 'committed';
	public const STATUS_ROLLED_BACK = 'rolled_back';
	public const STATUS_FAILED      = 'failed';

	/**
	 * Grace period in seconds after lock lease expiry before an abandoned operation can be recovered.
	 */
	public const RECOVERY_GRACE_PERIOD_SECONDS = 60;

	/**
	 * Maximum length for stored error messages.
	 */
	public const MAX_ERROR_MESSAGE_LENGTH = 500;

	/**
	 * Maximum size in bytes for serialized before_state in WAL payload (4 MB).
	 */
	public const MAX_BEFORE_STATE_BYTES = 4194304;

	/**
	 * Permitted status transitions in the journal state machine.
	 *
	 * Legal paths:
	 * - pending -> committed
	 * - pending -> rolled_back
	 * - pending -> failed
	 * - committed -> rolled_back
	 * - failed -> rolled_back
	 *
	 * Strictly prohibited paths:
	 * - committed -> pending
	 * - committed -> failed
	 * - rolled_back -> * (terminal state)
	 * - failed -> pending
	 * - failed -> committed
	 *
	 * @var array<string, string[]>
	 */
	private const LEGAL_TRANSITIONS = array(
		self::STATUS_PENDING     => array(
			self::STATUS_COMMITTED,
			self::STATUS_ROLLED_BACK,
			self::STATUS_FAILED,
		),
		self::STATUS_COMMITTED   => array(
			self::STATUS_ROLLED_BACK,
		),
		self::STATUS_FAILED      => array(
			self::STATUS_ROLLED_BACK,
		),
		self::STATUS_ROLLED_BACK => array(),
	);

	/**
	 * Keys indicating sensitive data to redact from logs and error messages.
	 *
	 * @var string[]
	 */
	private const SENSITIVE_KEY_PATTERNS = array(
		'password',
		'user_pass',
		'pass',
		'token',
		'secret',
		'api_key',
		'apikey',
		'auth',
		'authorization',
		'cookie',
		'nonce',
		'access_token',
		'refresh_token',
		'app_password',
		'application_password',
		'private_key',
	);

	/**
	 * Checks whether a status transition is permitted by the state machine.
	 *
	 * @param string $from_status Starting status.
	 * @param string $to_status   Target status.
	 * @return bool True if permitted.
	 */
	public static function can_transition( string $from_status, string $to_status ): bool {
		$allowed = self::LEGAL_TRANSITIONS[ $from_status ] ?? array();
		return in_array( $to_status, $allowed, true );
	}

	/**
	 * Checks if a key represents sensitive credential data.
	 *
	 * @param string|int $key Key to test.
	 * @return bool True if sensitive.
	 */
	public static function is_sensitive_key( string|int $key ): bool {
		if ( ! is_string( $key ) ) {
			return false;
		}

		$normalized = strtolower( trim( $key ) );
		foreach ( self::SENSITIVE_KEY_PATTERNS as $pattern ) {
			if ( str_contains( $normalized, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively canonicalizes data for deterministic serialization and hashing.
	 *
	 * INVARIANT:
	 * Operational state required for restoration is NEVER destructively altered.
	 * Legitimate Elementor data containing generic terms like 'token' or 'key'
	 * is preserved exactly.
	 *
	 * - Recursively sorts keys of associative arrays (ksort) so hash is stable.
	 * - Preserves numeric sequential order of indexed lists.
	 * - Strips PHP object types to avoid untrusted object deserialization vulnerabilities.
	 *
	 * @param mixed $data Input data.
	 * @return mixed Canonicalized data.
	 */
	public static function canonicalize_data( mixed $data ): mixed {
		if ( null === $data || is_scalar( $data ) ) {
			return $data;
		}

		if ( is_object( $data ) ) {
			$data = (array) $data;
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		$is_assoc = array_keys( $data ) !== range( 0, count( $data ) - 1 );
		$clean    = array();

		foreach ( $data as $key => $value ) {
			$clean[ $key ] = self::canonicalize_data( $value );
		}

		if ( $is_assoc ) {
			ksort( $clean, SORT_STRING );
		}

		return $clean;
	}

	/**
	 * Redacts sensitive credential patterns from data structures intended for logs or metadata.
	 *
	 * @param mixed $data Input data.
	 * @return mixed Redacted data.
	 */
	public static function redact_credentials( mixed $data ): mixed {
		if ( null === $data || is_scalar( $data ) ) {
			return $data;
		}

		if ( is_object( $data ) ) {
			$data = (array) $data;
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		$redacted = array();

		foreach ( $data as $key => $value ) {
			if ( self::is_sensitive_key( (string) $key ) ) {
				$redacted[ $key ] = '[REDACTED]';
				continue;
			}

			$redacted[ $key ] = self::redact_credentials( $value );
		}

		return $redacted;
	}

	/**
	 * Serializes state into canonical, deterministic JSON.
	 *
	 * @param mixed $state Raw state.
	 * @return string|\WP_Error Serialized canonical JSON string or WP_Error.
	 */
	public static function serialize_state( mixed $state ) {
		$canonical = self::canonicalize_data( $state );

		$json = wp_json_encode(
			$canonical,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);

		if ( false === $json || JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'serialization_failed',
				sprintf(
					/* translators: %s: json error message */
					__( 'Failed to serialize state to canonical JSON: %s', 'full-elementor-mcp' ),
					json_last_error_msg()
				)
			);
		}

		return $json;
	}

	/**
	 * Deserializes canonical JSON state into native PHP structure.
	 *
	 * Returns WP_Error if the JSON is malformed.
	 *
	 * @param string|null $serialized JSON string.
	 * @return mixed Decoded state, empty array if null/empty, or WP_Error if malformed.
	 */
	public static function deserialize_state( ?string $serialized ): mixed {
		if ( null === $serialized || '' === trim( $serialized ) ) {
			return array();
		}

		$decoded = json_decode( $serialized, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'deserialization_failed',
				sprintf(
					/* translators: %s: json error message */
					__( 'Malformed JSON in journal state: %s', 'full-elementor-mcp' ),
					json_last_error_msg()
				)
			);
		}

		return $decoded;
	}

	/**
	 * Computes a deterministic SHA-256 integrity hash of state.
	 *
	 * Fails closed and returns WP_Error if serialization fails. Never returns a fake hash.
	 *
	 * @param mixed $state Raw or serialized state.
	 * @return string|\WP_Error SHA-256 hex string (64 characters) or WP_Error.
	 */
	public static function hash_state( mixed $state ) {
		if ( is_string( $state ) && '' !== $state && ( str_starts_with( $state, '{' ) || str_starts_with( $state, '[' ) ) ) {
			// Already serialized JSON candidate. Verify and re-canonicalize.
			$decoded = json_decode( (string) $state, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$serialized = self::serialize_state( $decoded );
				if ( ! is_wp_error( $serialized ) ) {
					return hash( 'sha256', $serialized );
				}
				return $serialized;
			}
		}

		$serialized = self::serialize_state( $state );
		if ( is_wp_error( $serialized ) ) {
			return $serialized;
		}

		return hash( 'sha256', $serialized );
	}

	/**
	 * Sanitizes and truncates error messages before database persistence.
	 *
	 * @param string $message Raw error message.
	 * @return string Sanitized and bounded message.
	 */
	public static function sanitize_error_message( string $message ): string {
		$stripped = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $message ) : strip_tags( $message );

		// Redact Bearer tokens, passwords, authorization patterns.
		$stripped = (string) preg_replace( '#Bearer\s+[A-Za-z0-9._\-~+/]+=*#i', 'Bearer [REDACTED]', $stripped );
		$stripped = (string) preg_replace( '#(password|secret|key|token|nonce)\s*[:=]\s*[\'"][^\'"]+[\'"]#i', '$1: [REDACTED]', $stripped );

		if ( mb_strlen( $stripped ) > self::MAX_ERROR_MESSAGE_LENGTH ) {
			$stripped = mb_substr( $stripped, 0, self::MAX_ERROR_MESSAGE_LENGTH - 3 ) . '...';
		}

		return trim( $stripped );
	}

	/**
	 * Writes a durable Write-Ahead Journal entry BEFORE mutation execution.
	 *
	 * CRITICAL INVARIANTS:
	 * 1. Derives or strictly validates action, object_type, category, and rollback capability
	 *    from the registered mutation strategy. Callers cannot contradict the strategy contract.
	 * 2. For mutations requiring rollback, before_state MUST be explicitly present.
	 * 3. For creation operations, before_state is canonicalized to `{"exists": false}`.
	 * 4. Stores the exact canonical resource_key used for locking.
	 * 5. A journal row with canonical before_state and before_hash MUST be successfully
	 *    persisted before any persistent resource changes occur.
	 *
	 * Required parameters in $params:
	 * - ability: (string) Registered ability name.
	 * - fencing_token: (int) Active fencing token (>= 1).
	 * Optional parameters:
	 * - action: (string) Normalized action (derived if omitted, validated if passed).
	 * - object_type: (string) Target entity type (derived if omitted, validated if passed).
	 * - resource_key: (string) Canonical resource key (resolved if omitted).
	 * - before_state: (mixed) Captured pre-mutation state (required for rollbackable updates).
	 * - object_id: (int) Primary object ID (0 for creations).
	 * - user_id: (int) Acting WordPress user ID.
	 * - credential_uuid: (string) Authenticated credential UUID.
	 *
	 * @param array<string, mixed> $params Entry parameters.
	 * @return int|\WP_Error Inserted journal ID or WP_Error on failure.
	 */
	public static function begin( array $params ) {
		global $wpdb;

		$ability       = trim( (string) ( $params['ability'] ?? '' ) );
		$fencing_token = (int) ( $params['fencing_token'] ?? 0 );

		if ( '' === $ability ) {
			return new \WP_Error(
				'invalid_journal_parameters',
				__( 'Ability name is required for journal begin.', 'full-elementor-mcp' )
			);
		}

		if ( $fencing_token < 1 ) {
			return new \WP_Error(
				'invalid_journal_parameters',
				__( 'Fencing token must be a positive integer (>= 1) to begin journal.', 'full-elementor-mcp' )
			);
		}

		// Fail closed if strategy is unknown in mutation registry.
		$strategy = Full_Elementor_MCP_Mutation_Registry::get( $ability );
		if ( ! $strategy ) {
			return new \WP_Error(
				'mutation_strategy_missing',
				sprintf(
					/* translators: %s: ability name */
					__( 'Cannot begin journal: ability "%s" has no registered mutation strategy.', 'full-elementor-mcp' ),
					esc_html( $ability )
				)
			);
		}

		// Derive or strictly validate action and object_type against strategy contract.
		$expected_action      = (string) $strategy['action'];
		$expected_object_type = (string) $strategy['object_type'];
		$action               = trim( (string) ( $params['action'] ?? $expected_action ) );
		$object_type          = trim( (string) ( $params['object_type'] ?? $expected_object_type ) );

		if ( $action !== $expected_action || $object_type !== $expected_object_type ) {
			return new \WP_Error(
				'strategy_contract_mismatch',
				sprintf(
					/* translators: 1: action, 2: object_type, 3: expected action, 4: expected object_type */
					__( 'Supplied action/object_type ("%1$s"/"%2$s") contradicts registered strategy ("%3$s"/"%4$s").', 'full-elementor-mcp' ),
					$action,
					$object_type,
					$expected_action,
					$expected_object_type
				)
			);
		}

		// Always resolve the expected canonical resource key from strategy and args.
		$actual_args  = isset( $params['args'] ) && is_array( $params['args'] ) ? $params['args'] : $params;
		$expected_key = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( $ability, $actual_args );
		if ( is_wp_error( $expected_key ) ) {
			return $expected_key;
		}

		$provided_key = trim( (string) ( $params['resource_key'] ?? '' ) );
		if ( '' !== $provided_key && $provided_key !== $expected_key ) {
			return new \WP_Error(
				'strategy_resource_mismatch',
				sprintf(
					/* translators: 1: provided key, 2: expected key */
					__( 'Caller-provided resource key "%1$s" does not match expected canonical resource key "%2$s".', 'full-elementor-mcp' ),
					esc_html( $provided_key ),
					esc_html( $expected_key )
				)
			);
		}
		$resource_key = $expected_key;

		// Resolve expected object ID from strategy and actual args.
		$is_create = Full_Elementor_MCP_Mutation_Registry::CATEGORY_WP_OBJECT_CREATE === $strategy['category'];
		if ( $is_create ) {
			$expected_object_id = 0;
		} else {
			$expected_object_id = Full_Elementor_MCP_Mutation_Registry::resolve_object_id( $ability, $actual_args );
			if ( is_wp_error( $expected_object_id ) ) {
				return $expected_object_id;
			}
		}

		if ( isset( $params['object_id'] ) ) {
			$provided_object_id = (int) $params['object_id'];
			if ( $provided_object_id !== $expected_object_id ) {
				return new \WP_Error(
					'strategy_object_mismatch',
					sprintf(
						/* translators: 1: provided ID, 2: expected ID */
						__( 'Caller-provided object ID %1$d does not match expected strategy object ID %2$d.', 'full-elementor-mcp' ),
						$provided_object_id,
						$expected_object_id
					)
				);
			}
		}
		$object_id = $expected_object_id;

		// Enforce before_state contract and derive durable rollback capability.
		$supports_rollback = Full_Elementor_MCP_Mutation_Registry::supports_rollback_for_args( $ability, $actual_args );

		if ( $is_create ) {
			$before_state_raw = array( 'exists' => false );
		} else {
			if ( $supports_rollback ) {
				if ( ! array_key_exists( 'before_state', $params ) || null === $params['before_state'] ) {
					return new \WP_Error(
						'missing_before_state',
						sprintf(
							/* translators: %s: ability name */
							__( 'before_state must be explicitly provided for rollbackable ability "%s".', 'full-elementor-mcp' ),
							esc_html( $ability )
						)
					);
				}
				$before_state_raw = $params['before_state'];
			} else {
				// Non-rollbackable: do NOT persist arbitrary caller before_state (prevent secret leakage).
				$before_state_raw = null;
			}
		}

		// Serialize pre-mutation state.
		if ( null !== $before_state_raw ) {
			$before_state_str = self::serialize_state( $before_state_raw );
			if ( is_wp_error( $before_state_str ) ) {
				return $before_state_str;
			}

			// Size limit check on WAL payload.
			if ( strlen( $before_state_str ) > self::MAX_BEFORE_STATE_BYTES ) {
				return new \WP_Error(
					'journal_state_too_large',
					sprintf(
						/* translators: 1: state size in bytes, 2: maximum allowed bytes */
						__( 'Serialized before_state size (%1$d bytes) exceeds WAL payload limit of %2$d bytes.', 'full-elementor-mcp' ),
						strlen( $before_state_str ),
						self::MAX_BEFORE_STATE_BYTES
					)
				);
			}

			$before_hash = hash( 'sha256', $before_state_str );
		} else {
			$before_state_str = null;
			$before_hash      = null;
		}

		$user_id         = ! empty( $params['user_id'] ) ? (int) $params['user_id'] : get_current_user_id();
		$credential_uuid = isset( $params['credential_uuid'] ) ? sanitize_text_field( (string) $params['credential_uuid'] ) : null;
		$table           = Full_Elementor_MCP_Database_Installer::get_journal_table();

		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (
				created_at, updated_at, ability, action, object_type, object_id,
				created_object_id, resource_key, rollback_supported, fencing_token, before_state, before_hash,
				after_hash, status, error_message, user_id, credential_uuid
			) VALUES (
				UTC_TIMESTAMP(), UTC_TIMESTAMP(), %s, %s, %s, %d,
				NULL, %s, %d, %d, %s, %s,
				NULL, %s, NULL, %d, %s
			)",
			$ability,
			$action,
			$object_type,
			$object_id,
			$resource_key,
			$supports_rollback ? 1 : 0,
			$fencing_token,
			$before_state_str,
			$before_hash,
			self::STATUS_PENDING,
			$user_id > 0 ? $user_id : null,
			! empty( $credential_uuid ) ? $credential_uuid : null
		);

		$inserted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $inserted || 0 === $inserted ) {
			return new \WP_Error(
				'journal_write_failed',
				__( 'Failed to write initial journal entry to database before mutation.', 'full-elementor-mcp' )
			);
		}

		$journal_id = (int) $wpdb->insert_id;
		if ( $journal_id <= 0 ) {
			// Fallback ID retrieval for test harnesses / drivers where insert_id is not populated.
			$journal_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE ability = %s AND fencing_token = %d ORDER BY id DESC LIMIT 1",
					$ability,
					$fencing_token
				)
			);
		}

		if ( $journal_id <= 0 ) {
			return new \WP_Error(
				'journal_write_failed',
				__( 'Journal row was written but ID could not be retrieved.', 'full-elementor-mcp' )
			);
		}

		return $journal_id;
	}

	/**
	 * Durably records the newly created object ID immediately after creation.
	 *
	 * Necessary for creation mutations so rollback knows what entity to trash/delete
	 * if a failure or crash occurs later in the request.
	 *
	 * @param int $journal_id        Journal row ID.
	 * @param int $created_object_id Created WordPress entity ID.
	 * @param int $fencing_token     Expected fencing token.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function record_created_object_id( int $journal_id, int $created_object_id, int $fencing_token ): bool|\WP_Error {
		global $wpdb;

		if ( $journal_id <= 0 || $created_object_id <= 0 || $fencing_token <= 0 ) {
			return new \WP_Error(
				'invalid_journal_parameters',
				__( 'Invalid parameters for recording created object ID.', 'full-elementor-mcp' )
			);
		}

		$row = self::get_entry( $journal_id );
		if ( ! $row ) {
			return new \WP_Error(
				'journal_not_found',
				__( 'Journal entry not found.', 'full-elementor-mcp' )
			);
		}

		$strategy = Full_Elementor_MCP_Mutation_Registry::get( (string) $row['ability'] );
		if ( ! $strategy || empty( $strategy['created_object_tracking'] ) ) {
			return new \WP_Error(
				'invalid_journal_operation',
				sprintf(
					/* translators: %s: ability name */
					__( 'Ability "%s" does not support created object tracking.', 'full-elementor-mcp' ),
					esc_html( (string) $row['ability'] )
				)
			);
		}

		$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
		$sql   = $wpdb->prepare(
			"UPDATE {$table}
			SET created_object_id = %d, updated_at = UTC_TIMESTAMP()
			WHERE id = %d
			  AND status = %s
			  AND fencing_token = %d
			  AND (created_object_id IS NULL OR created_object_id = 0)",
			$created_object_id,
			$journal_id,
			self::STATUS_PENDING,
			$fencing_token
		);

		$updated = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $updated ) {
			return new \WP_Error(
				'journal_write_failed',
				__( 'Database query failed while recording created object ID in journal.', 'full-elementor-mcp' )
			);
		}

		if ( 0 === $updated ) {
			$recheck = self::get_entry( $journal_id );
			if ( ! $recheck ) {
				return new \WP_Error( 'journal_not_found', __( 'Journal entry not found during verification.', 'full-elementor-mcp' ) );
			}

			if ( self::STATUS_PENDING !== $recheck['status'] ) {
				return new \WP_Error(
					'journal_state_conflict',
					__( 'Could not record created object ID: journal is not in pending status.', 'full-elementor-mcp' )
				);
			}

			if ( (int) $recheck['fencing_token'] !== $fencing_token ) {
				return new \WP_Error(
					'stale_writer_conflict',
					__( 'Could not record created object ID: fencing token mismatch.', 'full-elementor-mcp' )
				);
			}

			$existing_id = (int) ( $recheck['created_object_id'] ?? 0 );
			if ( $existing_id === $created_object_id ) {
				// Idempotent retry under same fencing token and pending status.
				return true;
			}

			if ( $existing_id > 0 && $existing_id !== $created_object_id ) {
				return new \WP_Error(
					'created_object_conflict',
					sprintf(
						/* translators: 1: existing ID, 2: attempted ID */
						__( 'Cannot overwrite already recorded created_object_id %1$d with new ID %2$d (write-once identity violation).', 'full-elementor-mcp' ),
						$existing_id,
						$created_object_id
					)
				);
			}

			return new \WP_Error(
				'journal_state_conflict',
				__( 'Could not record created object ID: journal row not found, status not pending, or fencing token mismatch.', 'full-elementor-mcp' )
			);
		}

		return true;
	}

	/**
	 * Commits a journal entry upon successful mutation.
	 *
	 * Calculates and stores after_hash, and transitions status from pending to committed.
	 * Enforces conditional atomic UPDATE and idempotent validation of matching result hash.
	 *
	 * @param int   $journal_id    Journal row ID.
	 * @param mixed $after_state   Resulting post-mutation state.
	 * @param int   $fencing_token Expected fencing token.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function commit( int $journal_id, mixed $after_state, int $fencing_token ): bool|\WP_Error {
		global $wpdb;

		if ( $journal_id <= 0 || $fencing_token <= 0 ) {
			return new \WP_Error(
				'invalid_journal_parameters',
				__( 'Invalid journal ID or fencing token for commit.', 'full-elementor-mcp' )
			);
		}

		$entry = self::get_entry( $journal_id );
		if ( ! $entry ) {
			return new \WP_Error(
				'journal_not_found',
				__( 'Journal entry not found.', 'full-elementor-mcp' )
			);
		}

		// For tracked creation strategies, require created_object_id to be durably recorded first,
		// and ALWAYS generate after_state from authoritative persistent recapture.
		$strategy  = Full_Elementor_MCP_Mutation_Registry::get( $entry['ability'] );
		$is_create = $strategy && ( Full_Elementor_MCP_Mutation_Registry::CATEGORY_WP_OBJECT_CREATE === $strategy['category'] || ! empty( $strategy['created_object_tracking'] ) );
		if ( $is_create ) {
			$created_id = (int) ( $entry['created_object_id'] ?? 0 );
			if ( $created_id <= 0 ) {
				return new \WP_Error(
					'created_object_id_required',
					__( 'Cannot commit creation mutation: created_object_id must be durably recorded prior to commit.', 'full-elementor-mcp' )
				);
			}

			// Authoritatively recapture persistent state. Caller result payload must never replace safety fingerprint.
			$after_state = Full_Elementor_MCP_Mutation_Registry::capture_created_object_callback( $created_id );
		}

		$after_serialized = self::serialize_state( $after_state );
		if ( is_wp_error( $after_serialized ) ) {
			return $after_serialized;
		}

		$after_hash = hash( 'sha256', $after_serialized );

		// Idempotent commit check.
		if ( self::STATUS_COMMITTED === $entry['status'] ) {
			if ( (int) $entry['fencing_token'] === $fencing_token ) {
				if ( hash_equals( (string) $entry['after_hash'], $after_hash ) ) {
					return true;
				}
				return new \WP_Error(
					'journal_state_conflict',
					__( 'Journal entry already committed with different after-state hash.', 'full-elementor-mcp' )
				);
			}
			return new \WP_Error(
				'stale_writer_conflict',
				__( 'Journal entry already committed under a different fencing token.', 'full-elementor-mcp' )
			);
		}

		if ( ! self::can_transition( $entry['status'], self::STATUS_COMMITTED ) ) {
			return new \WP_Error(
				'invalid_journal_transition',
				sprintf(
					/* translators: 1: current status, 2: target status */
					__( 'Illegal journal state transition from "%1$s" to "%2$s".', 'full-elementor-mcp' ),
					esc_html( $entry['status'] ),
					self::STATUS_COMMITTED
				)
			);
		}

		$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
		$sql   = $wpdb->prepare(
			"UPDATE {$table}
			SET after_hash = %s, status = %s, updated_at = UTC_TIMESTAMP()
			WHERE id = %d
			  AND status = %s
			  AND fencing_token = %d",
			$after_hash,
			self::STATUS_COMMITTED,
			$journal_id,
			self::STATUS_PENDING,
			$fencing_token
		);

		$updated = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $updated ) {
			return new \WP_Error(
				'journal_write_failed',
				__( 'Database write failed during journal commit.', 'full-elementor-mcp' )
			);
		}

		if ( 0 === $updated ) {
			// Idempotent check after query.
			$recheck = self::get_entry( $journal_id );
			if ( $recheck && self::STATUS_COMMITTED === $recheck['status'] && (int) $recheck['fencing_token'] === $fencing_token ) {
				if ( hash_equals( (string) $recheck['after_hash'], $after_hash ) ) {
					return true;
				}
				return new \WP_Error(
					'journal_state_conflict',
					__( 'Journal entry committed concurrently with different after-state hash.', 'full-elementor-mcp' )
				);
			}

			return new \WP_Error(
				'journal_state_conflict',
				__( 'Conditional commit failed: journal status changed or fencing token mismatch.', 'full-elementor-mcp' )
			);
		}

		return true;
	}

	/**
	 * Marks a journal entry as failed with a sanitized error message.
	 *
	 * Uses conditional state transition and validates affected rows.
	 *
	 * @param int    $journal_id    Journal row ID.
	 * @param string $error_message Error description.
	 * @param int    $fencing_token Optional expected fencing token.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function mark_failed( int $journal_id, string $error_message, int $fencing_token = 0 ): bool|\WP_Error {
		global $wpdb;

		if ( $journal_id <= 0 ) {
			return new \WP_Error(
				'invalid_journal_parameters',
				__( 'Invalid journal ID.', 'full-elementor-mcp' )
			);
		}

		$entry = self::get_entry( $journal_id );
		if ( ! $entry ) {
			return new \WP_Error(
				'journal_not_found',
				__( 'Journal entry not found.', 'full-elementor-mcp' )
			);
		}

		if ( self::STATUS_FAILED === $entry['status'] ) {
			return true;
		}

		if ( ! self::can_transition( $entry['status'], self::STATUS_FAILED ) ) {
			return new \WP_Error(
				'invalid_journal_transition',
				sprintf(
					/* translators: 1: current status, 2: target status */
					__( 'Illegal journal state transition from "%1$s" to "%2$s".', 'full-elementor-mcp' ),
					esc_html( $entry['status'] ),
					self::STATUS_FAILED
				)
			);
		}

		$sanitized_error = self::sanitize_error_message( $error_message );
		$table           = Full_Elementor_MCP_Database_Installer::get_journal_table();

		if ( $fencing_token > 0 ) {
			$sql = $wpdb->prepare(
				"UPDATE {$table}
				SET error_message = %s, status = %s, updated_at = UTC_TIMESTAMP()
				WHERE id = %d
				  AND status = %s
				  AND fencing_token = %d",
				$sanitized_error,
				self::STATUS_FAILED,
				$journal_id,
				self::STATUS_PENDING,
				$fencing_token
			);
		} else {
			$sql = $wpdb->prepare(
				"UPDATE {$table}
				SET error_message = %s, status = %s, updated_at = UTC_TIMESTAMP()
				WHERE id = %d
				  AND status = %s",
				$sanitized_error,
				self::STATUS_FAILED,
				$journal_id,
				self::STATUS_PENDING
			);
		}

		$updated = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $updated ) {
			return new \WP_Error(
				'journal_write_failed',
				__( 'Database write failed while marking journal failed.', 'full-elementor-mcp' )
			);
		}

		if ( 0 === $updated ) {
			$recheck = self::get_entry( $journal_id );
			if ( $recheck && self::STATUS_FAILED === $recheck['status'] ) {
				return true;
			}
			return new \WP_Error(
				'journal_state_conflict',
				__( 'Failed to transition journal to failed status: status mismatch or concurrent modification.', 'full-elementor-mcp' )
			);
		}

		return true;
	}

	/**
	 * Retrieves a single journal entry by ID.
	 *
	 * @param int $journal_id Journal row ID.
	 * @return array<string, mixed>|null Entry row or null if not found.
	 */
	public static function get_entry( int $journal_id ): ?array {
		global $wpdb;

		if ( $journal_id <= 0 ) {
			return null;
		}

		$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $journal_id ),
			ARRAY_A
		);

		return ! empty( $row ) ? $row : null;
	}

	/**
	 * Lists journal entries with filtering and pagination.
	 *
	 * Omits raw before_state by default for performance and safety.
	 *
	 * @param array<string, mixed> $filters Filter parameters (resource_key, status, since, ability, limit, offset).
	 * @return array<int, array<string, mixed>> Matching journal records.
	 */
	public static function list_entries( array $filters = array() ): array {
		global $wpdb;

		$table  = Full_Elementor_MCP_Database_Installer::get_journal_table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['resource_key'] ) ) {
			$where[]  = 'resource_key = %s';
			$params[] = sanitize_text_field( (string) $filters['resource_key'] );
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( (string) $filters['status'] );
		}
		if ( ! empty( $filters['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( (string) $filters['since'] );
		}
		if ( ! empty( $filters['ability'] ) ) {
			$where[]  = 'ability = %s';
			$params[] = sanitize_text_field( (string) $filters['ability'] );
		}

		$limit  = isset( $filters['limit'] ) ? max( 1, min( 100, (int) $filters['limit'] ) ) : 20;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		$where_clause = implode( ' AND ', $where );
		$fields       = 'id, created_at, updated_at, ability, action, object_type, object_id, created_object_id, resource_key, rollback_supported, fencing_token, before_hash, after_hash, status, error_message, user_id, credential_uuid';

		$sql      = "SELECT {$fields} FROM {$table} WHERE {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$prepared = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rolls back a journaled mutation using its registered strategy.
	 *
	 * MANDATORY INVARIANTS:
	 * 1. Requires active lock ownership: non-empty $current_owner_id and $caller_fencing_token >= 1.
	 * 2. Resolves deterministic rollback resource key. If resolution fails -> FAIL CLOSED.
	 * 3. Immediately before restore callback: calls assert_fencing_token_ownership().
	 *    If validation fails -> DO NOT restore.
	 * 4. Conflict detection: if committed after_hash exists and force is not literal boolean true,
	 *    live state MUST be captured and its hash MUST match after_hash.
	 *    If live state capture/hash fails -> FAIL CLOSED with 'journal_state_unverifiable'.
	 * 5. Literal boolean force (`true === ($options['force'] ?? false)`): bypasses conflict check,
	 *    but NEVER bypasses lock ownership or fencing!
	 * 6. Executes restore callback. If restore fails -> do NOT mark rolled_back.
	 * 7. Conditionally updates journal row to rolled_back.
	 *
	 * @param int                  $journal_id           Journal row ID.
	 * @param string               $current_owner_id     Active lock owner ID.
	 * @param int                  $caller_fencing_token Active fencing token (>= 1).
	 * @param array<string, mixed> $options              Optional flags ('force' => bool).
	 * @return array{success: bool, journal_id: int, status: string, object_id: int}|\WP_Error
	 */
	public static function rollback(
		int $journal_id,
		string $current_owner_id,
		int $caller_fencing_token,
		array $options = array()
	) {
		global $wpdb;

		if ( $journal_id <= 0 ) {
			return new \WP_Error(
				'invalid_journal_parameters',
				__( 'Invalid journal ID for rollback.', 'full-elementor-mcp' )
			);
		}

		$current_owner_id = trim( $current_owner_id );
		if ( '' === $current_owner_id || $caller_fencing_token < 1 ) {
			return new \WP_Error(
				'rollback_fencing_required',
				__( 'Active lock ownership and fencing token (>= 1) are strictly required for rollback.', 'full-elementor-mcp' )
			);
		}

		$entry = self::get_entry( $journal_id );
		if ( ! $entry ) {
			return new \WP_Error(
				'journal_not_found',
				__( 'Journal entry not found for rollback.', 'full-elementor-mcp' )
			);
		}

		// Check if already rolled back (idempotent).
		if ( self::STATUS_ROLLED_BACK === $entry['status'] ) {
			return array(
				'success'             => true,
				'already_rolled_back' => true,
				'journal_id'          => $journal_id,
				'status'              => self::STATUS_ROLLED_BACK,
				'object_id'           => (int) ( $entry['created_object_id'] ?: $entry['object_id'] ),
			);
		}

		if ( ! self::can_transition( $entry['status'], self::STATUS_ROLLED_BACK ) ) {
			return new \WP_Error(
				'invalid_journal_transition',
				sprintf(
					/* translators: 1: current status, 2: target status */
					__( 'Illegal journal state transition from "%1$s" to "%2$s".', 'full-elementor-mcp' ),
					esc_html( $entry['status'] ),
					self::STATUS_ROLLED_BACK
				)
			);
		}

		// Locate registered strategy.
		$strategy = Full_Elementor_MCP_Mutation_Registry::get( $entry['ability'] );
		if ( ! $strategy ) {
			return new \WP_Error(
				'mutation_strategy_missing',
				sprintf(
					/* translators: %s: ability name */
					__( 'Cannot rollback: ability "%s" has no registered mutation strategy.', 'full-elementor-mcp' ),
					esc_html( $entry['ability'] )
				)
			);
		}

		// Verify strategy supports rollback.
		if ( empty( $strategy['supports_rollback'] ) ) {
			return new \WP_Error(
				'mutation_not_rollbackable',
				sprintf(
					/* translators: %s: ability name */
					__( 'Mutation strategy for ability "%s" does not support rollback.', 'full-elementor-mcp' ),
					esc_html( $entry['ability'] )
				)
			);
		}

		// Verify durable persisted rollback capability.
		if ( array_key_exists( 'rollback_supported', $entry ) && empty( $entry['rollback_supported'] ) ) {
			return new \WP_Error(
				'mutation_not_rollbackable',
				sprintf(
					/* translators: %d: journal ID */
					__( 'Cannot rollback: journal entry #%d was recorded as non-rollbackable.', 'full-elementor-mcp' ),
					$journal_id
				)
			);
		}

		// Resolve target object ID.
		$is_create = Full_Elementor_MCP_Mutation_Registry::CATEGORY_WP_OBJECT_CREATE === $strategy['category'];
		if ( $is_create ) {
			$target_id = (int) ( $entry['created_object_id'] ?? 0 );
			if ( $target_id <= 0 ) {
				return new \WP_Error(
					'created_object_identity_unknown',
					__( 'Cannot rollback creation mutation: created_object_id is missing or untracked. Entity may exist in unrecorded state.', 'full-elementor-mcp' )
				);
			}
		} else {
			$target_id = (int) $entry['object_id'];
		}

		$resolver_args = array(
			'post_id'               => $target_id,
			'object_id'             => $target_id,
			'page_id'               => $target_id,
			'created_object_id'     => $target_id,
			'id'                    => $target_id,
			'resource_key'          => (string) ( $entry['resource_key'] ?? '' ),
			'rollback_resource_key' => (string) ( $entry['resource_key'] ?? '' ),
			'target_resource'       => (string) ( $entry['resource_key'] ?? '' ),
			'object_type'           => (string) ( $entry['object_type'] ?? '' ),
		);

		// Resolve deterministic rollback resource key. Fail closed if unresolved.
		$rollback_resource_key = Full_Elementor_MCP_Mutation_Registry::resolve_rollback_resource_key(
			$entry['ability'],
			$entry,
			$resolver_args
		);

		if ( is_wp_error( $rollback_resource_key ) || '' === trim( (string) $rollback_resource_key ) ) {
			return is_wp_error( $rollback_resource_key )
				? $rollback_resource_key
				: new \WP_Error(
					'invalid_rollback_resource_key',
					__( 'Could not resolve deterministic rollback resource key.', 'full-elementor-mcp' )
				);
		}

		// Assert fencing token ownership immediately before any persistent write.
		$fencing_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership(
			$rollback_resource_key,
			$current_owner_id,
			$caller_fencing_token
		);
		if ( is_wp_error( $fencing_check ) ) {
			return $fencing_check;
		}

		// Strict literal boolean force check.
		$is_force = ( true === ( $options['force'] ?? false ) );

		// Conflict detection and crash-safe idempotent rollback reconciliation.
		if ( ! $is_force ) {
			if ( self::STATUS_COMMITTED === $entry['status'] ) {
				if ( $is_create ) {
					// Check if already trashed/deleted from prior successful rollback attempt (idempotent retry).
					$status = function_exists( 'get_post_status' ) ? get_post_status( $target_id ) : false;
					if ( false === $status || 'trash' === $status || 'trashed' === $status ) {
						// Created object is already gone; reconcile journal status.
						return self::mark_rolled_back_internal( $journal_id, $entry['status'], $target_id );
					}

					// Recapture created object state and compare against committed after_hash.
					$current_state = Full_Elementor_MCP_Mutation_Registry::capture_created_object_callback( $target_id );
					$current_hash  = self::hash_state( $current_state );
					if ( is_wp_error( $current_hash ) || '' === $current_hash ) {
						return new \WP_Error(
							'journal_state_unverifiable',
							__( 'Cannot rollback: created object state hashing failed during conflict verification.', 'full-elementor-mcp' )
						);
					}

					if ( empty( $entry['after_hash'] ) || ! hash_equals( (string) $entry['after_hash'], $current_hash ) ) {
						return new \WP_Error(
							'journal_state_conflict',
							__( 'Cannot rollback created object: entity has been modified after creation (newer work detected).', 'full-elementor-mcp' )
						);
					}
				} else {
					$capture_fn = $strategy['capture_after'] ?? $strategy['capture_before'];
					if ( ! is_callable( $capture_fn ) ) {
						return new \WP_Error(
							'journal_state_unverifiable',
							__( 'Cannot rollback: strategy does not provide state capture for conflict verification.', 'full-elementor-mcp' )
						);
					}

					$live_state = $capture_fn( $target_id, $resolver_args, null );
					if ( is_wp_error( $live_state ) || null === $live_state ) {
						return new \WP_Error(
							'journal_state_unverifiable',
							sprintf(
								/* translators: %s: failure detail */
								__( 'Cannot rollback: live state could not be captured for verification: %s', 'full-elementor-mcp' ),
								is_wp_error( $live_state ) ? $live_state->get_error_message() : 'State returned null'
							)
						);
					}

					$live_hash = self::hash_state( $live_state );
					if ( is_wp_error( $live_hash ) || '' === $live_hash ) {
						return new \WP_Error(
							'journal_state_unverifiable',
							__( 'Cannot rollback: live state hashing failed during conflict verification.', 'full-elementor-mcp' )
						);
					}

					// Crash-safe idempotent retry: resource was already successfully restored to before_state!
					if ( hash_equals( (string) $entry['before_hash'], $live_hash ) ) {
						return self::mark_rolled_back_internal( $journal_id, $entry['status'], $target_id );
					}

					if ( empty( $entry['after_hash'] ) || ! hash_equals( (string) $entry['after_hash'], $live_hash ) ) {
						return new \WP_Error(
							'journal_state_conflict',
							__( 'Cannot rollback: live state has diverged from journal committed state (newer modifications detected).', 'full-elementor-mcp' )
						);
					}
				}
			} else {
				// STATUS_PENDING or STATUS_FAILED:
				if ( $is_create ) {
					// Check if already trashed/deleted from prior rollback attempt (idempotent retry).
					$status = function_exists( 'get_post_status' ) ? get_post_status( $target_id ) : false;
					if ( false === $status || 'trash' === $status || 'trashed' === $status ) {
						return self::mark_rolled_back_internal( $journal_id, $entry['status'], $target_id );
					}

					// For CREATE journals without an authoritative committed after_hash,
					// do NOT automatically trash. Fail closed with manual_recovery_required.
					if ( empty( $entry['after_hash'] ) ) {
						return new \WP_Error(
							'manual_recovery_required',
							__( 'Cannot safely rollback uncommitted created entity without authoritative baseline: manual recovery required.', 'full-elementor-mcp' )
						);
					}

					$current_state = Full_Elementor_MCP_Mutation_Registry::capture_created_object_callback( $target_id );
					$current_hash  = self::hash_state( $current_state );
					if ( is_wp_error( $current_hash ) || ! hash_equals( (string) $entry['after_hash'], $current_hash ) ) {
						return new \WP_Error(
							'journal_state_conflict',
							__( 'Cannot rollback created object: entity state diverged or unverifiable.', 'full-elementor-mcp' )
						);
					}
				} else {
					$is_same_generation = (
						$rollback_resource_key === (string) $entry['resource_key'] &&
						$caller_fencing_token === (int) $entry['fencing_token']
					);

					if ( ! $is_same_generation ) {
						// Attempting rollback under a NEW generation / different fence.
						$capture_fn = $strategy['capture_before'] ?? ( $strategy['capture_after'] ?? null );
						if ( ! is_callable( $capture_fn ) ) {
							return new \WP_Error(
								'journal_state_unverifiable',
								__( 'Cannot rollback pending/failed journal: state cannot be verified.', 'full-elementor-mcp' )
							);
						}

						$current_state = $capture_fn( $target_id, $resolver_args, null );
						$current_hash  = ( is_wp_error( $current_state ) || null === $current_state ) ? '' : self::hash_state( $current_state );

						if ( ! is_wp_error( $current_hash ) && '' !== $current_hash && hash_equals( (string) $entry['before_hash'], $current_hash ) ) {
							// Current state already equals before_hash: no write needed, reconcile status safely.
							return self::mark_rolled_back_internal( $journal_id, $entry['status'], $target_id );
						}

						// Current state differs from before_hash and no authoritative after_hash exists:
						return new \WP_Error(
							'manual_recovery_required',
							__( 'Cannot rollback pending or failed journal under a new generation: live state differs from before-state and no committed baseline exists.', 'full-elementor-mcp' )
						);
					}
					// If is_same_generation: caller owns original journal generation on same resource,
					// allowed as same-execution failure cleanup.
				}
			}
		}

		// Execute strategy restore callback.
		$restore_fn      = $strategy['restore_before'];
		$restore_context = array_merge(
			$entry,
			array(
				'object_id'             => $target_id,
				'created_object_id'     => $target_id,
				'post_id'               => $target_id,
				'rollback_resource_key' => $rollback_resource_key,
				'resource_key'          => $rollback_resource_key,
				'current_owner_id'      => $current_owner_id,
				'owner_id'              => $current_owner_id,
				'caller_fencing_token'  => $caller_fencing_token,
				'fencing_token'         => $caller_fencing_token,
			)
		);

		// Re-assert fencing immediately before actual restore to close any race window.
		$fencing_recheck = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership(
			$rollback_resource_key,
			$current_owner_id,
			$caller_fencing_token
		);
		if ( is_wp_error( $fencing_recheck ) ) {
			return $fencing_recheck;
		}

		if ( $is_create ) {
			$restore_result = $restore_fn( null, $restore_context );
		} else {
			$before_state_decoded = self::deserialize_state( $entry['before_state'] );
			if ( is_wp_error( $before_state_decoded ) ) {
				return $before_state_decoded;
			}
			$restore_result = $restore_fn( $before_state_decoded, $restore_context );
		}

		// If restore failed, do NOT mark rolled_back. Record error safely.
		if ( false === $restore_result || is_wp_error( $restore_result ) ) {
			$error_text = is_wp_error( $restore_result )
				? $restore_result->get_error_message()
				: 'Strategy restore callback returned false';

			$table           = Full_Elementor_MCP_Database_Installer::get_journal_table();
			$sanitized_error = self::sanitize_error_message( $error_text );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET error_message = %s, updated_at = UTC_TIMESTAMP() WHERE id = %d",
					$sanitized_error,
					$journal_id
				)
			);

			return new \WP_Error(
				'rollback_failed',
				sprintf(
					/* translators: %s: failure detail */
					__( 'Rollback failed during state restoration: %s', 'full-elementor-mcp' ),
					$error_text
				)
			);
		}

		// Requirement 5: Verify rollback result against BEFORE state.
		if ( $is_create ) {
			// For created objects, verify the post is trashed or no longer active.
			$status = function_exists( 'get_post_status' ) ? get_post_status( $target_id ) : false;
			if ( false !== $status && 'trash' !== $status && 'trashed' !== $status ) {
				$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
				$err   = 'Created object rollback verification failed: post is still active (' . $status . ')';
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET error_message = %s, updated_at = UTC_TIMESTAMP() WHERE id = %d", $err, $journal_id ) );
				return new \WP_Error( 'rollback_verification_failed', $err );
			}
		} else {
			// For existing objects, capture actual live state and verify hash matches before_hash.
			$capture_fn = $strategy['capture_before'] ?? ( $strategy['capture_after'] ?? null );
			if ( is_callable( $capture_fn ) ) {
				$restored_state = $capture_fn( $target_id, $resolver_args, null );
				if ( is_wp_error( $restored_state ) || null === $restored_state ) {
					return new \WP_Error(
						'journal_state_unverifiable',
						sprintf(
							/* translators: %s: failure detail */
							__( 'Rollback state capture failed during post-restore verification: %s', 'full-elementor-mcp' ),
							is_wp_error( $restored_state ) ? $restored_state->get_error_message() : 'State returned null'
						)
					);
				}

				$restored_hash = self::hash_state( $restored_state );
				if ( is_wp_error( $restored_hash ) || '' === $restored_hash ) {
					return new \WP_Error(
						'journal_state_unverifiable',
						__( 'Rollback state hashing failed during post-restore verification.', 'full-elementor-mcp' )
					);
				}

				if ( ! hash_equals( (string) $entry['before_hash'], $restored_hash ) ) {
					$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
					$err   = 'Rollback verification failed: restored state hash does not match expected before_hash.';
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET error_message = %s, updated_at = UTC_TIMESTAMP() WHERE id = %d", $err, $journal_id ) );
					return new \WP_Error( 'rollback_verification_failed', $err );
				}
			}
		}

		// Mark rolled back conditionally.
		return self::mark_rolled_back_internal( $journal_id, $entry['status'], $target_id );
	}

	/**
	 * Conditionally updates journal row status to rolled_back.
	 *
	 * @param int    $journal_id  Journal row ID.
	 * @param string $from_status Expected current status.
	 * @param int    $target_id   Target entity ID.
	 * @return array{success: bool, journal_id: int, status: string, object_id: int}|\WP_Error
	 */
	private static function mark_rolled_back_internal( int $journal_id, string $from_status, int $target_id ) {
		global $wpdb;

		$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
		$sql   = $wpdb->prepare(
			"UPDATE {$table}
			SET status = %s, updated_at = UTC_TIMESTAMP()
			WHERE id = %d
			  AND status = %s",
			self::STATUS_ROLLED_BACK,
			$journal_id,
			$from_status
		);

		$updated = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $updated || 0 === $updated ) {
			// Idempotent re-check.
			$recheck = self::get_entry( $journal_id );
			if ( $recheck && self::STATUS_ROLLED_BACK === $recheck['status'] ) {
				return array(
					'success'    => true,
					'journal_id' => $journal_id,
					'status'     => self::STATUS_ROLLED_BACK,
					'object_id'  => $target_id,
				);
			}

			return new \WP_Error(
				'journal_write_failed',
				__( 'Failed to transition journal row to rolled_back status in database.', 'full-elementor-mcp' )
			);
		}

		return array(
			'success'    => true,
			'journal_id' => $journal_id,
			'status'     => self::STATUS_ROLLED_BACK,
			'object_id'  => $target_id,
		);
	}

	/**
	 * Recovers abandoned pending journal rows following crash or unexpected shutdown.
	 *
	 * CRITICAL SAFETY INVARIANTS:
	 * 1. An operation is ONLY considered abandoned when its associated lock lease has
	 *    expired + the grace period (default 60 seconds) has elapsed. Active leases
	 *    or leases within the grace period are NEVER touched.
	 * 2. Binds journal generation to resource history: if the lock was taken over
	 *    by a newer writer (fencing token > journal fencing token), the pending journal
	 *    is STALE and MUST NOT overwrite newer work. It is marked failed.
	 * 3. Never calls unfenced rollback. Acquires a NEW recovery lock generation,
	 *    obtains a NEW fencing token, asserts fencing ownership immediately before restore,
	 *    and releases the recovery lock afterward.
	 * 4. Unsupported mutation strategies fail closed and are marked failed.
	 *
	 * @param int $grace_seconds Grace period in seconds (default 60).
	 * @return array<int, array<string, mixed>> Recovery summary reports.
	 */
	public static function recover_pending( int $grace_seconds = self::RECOVERY_GRACE_PERIOD_SECONDS, array $context = array() ): array {
		global $wpdb;

		$table        = Full_Elementor_MCP_Database_Installer::get_journal_table();
		$tokens_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$grace        = max( 10, $grace_seconds );

		$req_uuid       = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$user_id        = (int) ( $context['user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
		$source_ability = (string) ( $context['ability'] ?? 'recovery_scan' );

		if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
			Full_Elementor_MCP_Audit_Logger::log(
				Full_Elementor_MCP_Audit_Logger::EVENT_RECOVERY_SCAN_STARTED,
				array(
					'ability'      => $source_ability,
					'user_id'      => $user_id,
					'request_uuid' => $req_uuid,
					'severity'     => Full_Elementor_MCP_Audit_Logger::SEVERITY_NOTICE,
				)
			);
		}

		// Query pending journal entries.
		$pending_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT 50",
				self::STATUS_PENDING
			),
			ARRAY_A
		);

		$results = array();

		if ( ! empty( $pending_rows ) ) {
			foreach ( $pending_rows as $row ) {
				$journal_id    = (int) $row['id'];
				$ability       = (string) $row['ability'];
				$journal_fence = (int) $row['fencing_token'];
				$resource_key  = (string) ( $row['resource_key'] ?? '' );
				$strategy      = Full_Elementor_MCP_Mutation_Registry::get( $ability );

				if ( '' === trim( $resource_key ) ) {
					$mf = self::mark_failed( $journal_id, 'abandoned_pending_unresolvable_key', $journal_fence );
					$results[] = array(
						'journal_id' => $journal_id,
						'ability'    => $ability,
						'status'     => is_wp_error( $mf ) ? 'recovery_failed' : self::STATUS_FAILED,
						'reason'     => is_wp_error( $mf ) ? $mf->get_error_code() : 'unresolvable_resource_key',
						'error'      => is_wp_error( $mf ) ? $mf->get_error_message() : null,
					);
					continue;
				}

				// Check abandonment of the original mutation lock lease.
				$lock_key  = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource_key );
				$lock_info = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT fencing_token, expires_at, used,
						       (expires_at <= UTC_TIMESTAMP()) AS is_expired,
						       (expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)) AS is_abandoned
						FROM {$tokens_table}
						WHERE token_key = %s
						  AND token_type = 'lock'",
						$grace,
						$lock_key
					),
					ARRAY_A
				);

				$is_abandoned = false;
				if ( ! empty( $lock_info ) ) {
					if ( ! empty( $lock_info['is_abandoned'] ) ) {
						$is_abandoned = true;
					}
				} else {
					$min_age_seconds = Full_Elementor_MCP_Lock_Manager::DEFAULT_LEASE_TTL + $grace;
					$age_check       = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT (created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)) AS is_old
							FROM {$table} WHERE id = %d",
							$min_age_seconds,
							$journal_id
						)
					);
					if ( ! empty( $age_check ) ) {
						$is_abandoned = true;
					}
				}

				// If lease is still active or within grace period, do not touch.
				if ( ! $is_abandoned ) {
					continue;
				}

				// Requirement 3: Conservative Abandoned CREATE Recovery.
				$is_create = $strategy && Full_Elementor_MCP_Mutation_Registry::CATEGORY_WP_OBJECT_CREATE === $strategy['category'];
				if ( $is_create ) {
					$created_id = absint( $row['created_object_id'] ?? 0 );
					if ( $created_id > 0 ) {
						// Object was created! Do NOT automatically trash it. Report and transition to safe failure.
						$mf = self::mark_failed(
							$journal_id,
							'abandoned_create_requires_manual_recovery',
							$journal_fence
						);
						$results[] = array(
							'journal_id'        => $journal_id,
							'ability'           => $ability,
							'status'            => is_wp_error( $mf ) ? 'recovery_failed' : self::STATUS_FAILED,
							'reason'            => is_wp_error( $mf ) ? $mf->get_error_code() : 'abandoned_create_requires_manual_recovery',
							'created_object_id' => $created_id,
						);
						if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
							Full_Elementor_MCP_Audit_Logger::log(
								Full_Elementor_MCP_Audit_Logger::EVENT_RECOVERY_MANUAL_REQUIRED,
								array(
									'ability'      => $ability,
									'resource_key' => $resource_key,
									'change_id'    => $journal_id,
									'user_id'      => $user_id,
									'request_uuid' => $req_uuid,
									'severity'     => Full_Elementor_MCP_Audit_Logger::SEVERITY_WARNING,
									'error_code'   => 'manual_recovery_required',
									'metadata'     => array(
										'journal_id'        => $journal_id,
										'reason'            => 'abandoned_create_requires_manual_recovery',
										'created_object_id' => $created_id,
									),
								)
							);
						}
						continue;
					}

					// Created object ID untracked: fail closed without touching target entity.
					$mf = self::mark_failed(
						$journal_id,
						'abandoned_create_untracked_id',
						$journal_fence
					);
					$results[] = array(
						'journal_id' => $journal_id,
						'ability'    => $ability,
						'status'     => is_wp_error( $mf ) ? 'recovery_failed' : self::STATUS_FAILED,
						'reason'     => is_wp_error( $mf ) ? $mf->get_error_code() : 'untracked_created_id',
					);
					continue;
				}

				// Check strategy support:
				if ( ! $strategy || empty( $strategy['supports_rollback'] ) ) {
					$mf = self::mark_failed( $journal_id, 'abandoned_pending_unsupported_strategy', $journal_fence );
					$results[] = array(
						'journal_id' => $journal_id,
						'ability'    => $ability,
						'status'     => is_wp_error( $mf ) ? 'recovery_failed' : self::STATUS_FAILED,
						'reason'     => is_wp_error( $mf ) ? $mf->get_error_code() : 'unsupported_strategy',
						'error'      => is_wp_error( $mf ) ? $mf->get_error_message() : null,
					);
					continue;
				}

				// Check fencing generation against current token on the resource:
				$current_fencing_token = (int) ( $lock_info['fencing_token'] ?? 0 );
				if ( $current_fencing_token > $journal_fence ) {
					// Lock was acquired by a newer generation! The pending journal is STALE and MUST NOT overwrite newer work.
					$mf = self::mark_failed( $journal_id, 'abandoned_pending_stale_generation', $journal_fence );
					$results[] = array(
						'journal_id' => $journal_id,
						'ability'    => $ability,
						'status'     => is_wp_error( $mf ) ? 'recovery_failed' : self::STATUS_FAILED,
						'reason'     => is_wp_error( $mf ) ? $mf->get_error_code() : 'stale_generation',
						'error'      => is_wp_error( $mf ) ? $mf->get_error_message() : null,
					);
					continue;
				}

				// Requirement 11: Conservative Pending Existing-Resource Recovery.
				// Pending operations have no committed after_hash. Verify live state.
				$capture_fn = $strategy['capture_before'] ?? ( $strategy['capture_after'] ?? null );
				$target_id  = (int) $row['object_id'];
				$args       = array( 'object_id' => $target_id, 'post_id' => $target_id );

				if ( is_callable( $capture_fn ) ) {
					$live_state = $capture_fn( $target_id, $args, null );
					$live_hash  = is_wp_error( $live_state ) || null === $live_state ? '' : self::hash_state( $live_state );

					if ( ! is_wp_error( $live_hash ) && '' !== $live_hash && hash_equals( (string) $row['before_hash'], $live_hash ) ) {
						// Live state matches before_state: mutation left no changes. Safe clean resolution without write.
						$mf = self::mark_failed(
							$journal_id,
							'abandoned_pending_clean_noop',
							$journal_fence
						);
						$results[] = array(
							'journal_id' => $journal_id,
							'ability'    => $ability,
							'status'     => is_wp_error( $mf ) ? 'recovery_failed' : self::STATUS_FAILED,
							'reason'     => is_wp_error( $mf ) ? $mf->get_error_code() : 'clean_noop',
							'error'      => is_wp_error( $mf ) ? $mf->get_error_message() : null,
						);
						continue;
					}

					// Live state diverged or unverifiable: fail closed; do not speculatively overwrite.
					$mf = self::mark_failed(
						$journal_id,
						'abandoned_pending_divergent_state_manual_recovery_required',
						$journal_fence
					);
					$results[] = array(
						'journal_id' => $journal_id,
						'ability'    => $ability,
						'status'     => is_wp_error( $mf ) ? 'recovery_failed' : self::STATUS_FAILED,
						'reason'     => is_wp_error( $mf ) ? $mf->get_error_code() : 'manual_recovery_required',
						'error'      => is_wp_error( $mf ) ? $mf->get_error_message() : null,
					);
					if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
						Full_Elementor_MCP_Audit_Logger::log(
							Full_Elementor_MCP_Audit_Logger::EVENT_RECOVERY_MANUAL_REQUIRED,
							array(
								'ability'      => $ability,
								'resource_key' => $resource_key,
								'change_id'    => $journal_id,
								'user_id'      => $user_id,
								'request_uuid' => $req_uuid,
								'severity'     => Full_Elementor_MCP_Audit_Logger::SEVERITY_WARNING,
								'error_code'   => 'manual_recovery_required',
								'metadata'     => array(
									'journal_id' => $journal_id,
									'reason'     => 'abandoned_pending_divergent_state_manual_recovery_required',
								),
							)
						);
					}
					continue;
				}

				// Unverifiable live state.
				$mf = self::mark_failed(
					$journal_id,
					'abandoned_pending_unverifiable_live_state',
					$journal_fence
				);
				$results[] = array(
					'journal_id' => $journal_id,
					'ability'    => $ability,
					'status'     => is_wp_error( $mf ) ? 'recovery_failed' : self::STATUS_FAILED,
					'reason'     => is_wp_error( $mf ) ? $mf->get_error_code() : 'unverifiable_live_state',
					'error'      => is_wp_error( $mf ) ? $mf->get_error_message() : null,
				);
			}
		}

		if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
			$counts = array(
				'processed'       => count( $results ),
				'recovery_failed' => 0,
				'manual_required' => 0,
				'clean_noop'      => 0,
			);
			foreach ( $results as $r ) {
				$status = $r['status'] ?? '';
				$reason = (string) ( $r['reason'] ?? '' );
				if ( 'recovery_failed' === $status ) {
					$counts['recovery_failed']++;
				} elseif ( 'clean_noop' === $reason ) {
					$counts['clean_noop']++;
				} elseif ( str_contains( $reason, 'manual' ) ) {
					$counts['manual_required']++;
				}
			}

			Full_Elementor_MCP_Audit_Logger::log(
				Full_Elementor_MCP_Audit_Logger::EVENT_RECOVERY_COMPLETED,
				array(
					'ability'       => $source_ability,
					'user_id'       => $user_id,
					'request_uuid'  => $req_uuid,
					'result_status' => empty( $counts['recovery_failed'] ) ? 'success' : 'warning',
					'severity'      => empty( $counts['recovery_failed'] ) ? Full_Elementor_MCP_Audit_Logger::SEVERITY_INFO : Full_Elementor_MCP_Audit_Logger::SEVERITY_WARNING,
					'metadata'      => $counts,
				)
			);
		}

		return $results;
	}
}
