<?php
/**
 * Write-Ahead Journal (WAL) for Full Elementor MCP.
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
	 * Keys indicating sensitive data to redact from stored state and logs.
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
	 * Recursively sanitizes and canonicalizes data for deterministic serialization.
	 *
	 * - Redacts sensitive keys matching credential patterns.
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
			if ( self::is_sensitive_key( $key ) ) {
				$clean[ $key ] = '[REDACTED]';
				continue;
			}

			$clean[ $key ] = self::canonicalize_data( $value );
		}

		if ( $is_assoc ) {
			ksort( $clean, SORT_STRING );
		}

		return $clean;
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
	 * @param string|null $serialized JSON string.
	 * @return mixed Decoded state or empty array if null.
	 */
	public static function deserialize_state( ?string $serialized ): mixed {
		if ( null === $serialized || '' === trim( $serialized ) ) {
			return array();
		}

		return json_decode( $serialized, true );
	}

	/**
	 * Computes a deterministic SHA-256 integrity hash of state.
	 *
	 * @param mixed $state Raw or serialized state.
	 * @return string SHA-256 hex string (64 characters).
	 */
	public static function hash_state( mixed $state ): string {
		if ( is_string( $state ) && '' !== $state && ( str_starts_with( $state, '{' ) || str_starts_with( $state, '[' ) ) ) {
			// Already serialized JSON candidate.
			$decoded = json_decode( (string) $state, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$serialized = self::serialize_state( $decoded );
				if ( ! is_wp_error( $serialized ) ) {
					return hash( 'sha256', $serialized );
				}
			}
		}

		$serialized = self::serialize_state( $state );
		if ( is_wp_error( $serialized ) ) {
			return hash( 'sha256', '' );
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
		$stripped = wp_strip_all_tags( $message );

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
	 * CRITICAL INVARIANT:
	 * A journal row with canonical before_state and before_hash MUST be successfully
	 * written and persisted before the caller performs ANY persistent mutation.
	 * If database persistence fails, this method returns WP_Error and the mutation
	 * MUST NOT proceed.
	 *
	 * Required parameters in $params:
	 * - ability: (string) Registered ability name.
	 * - action: (string) Mutation action verb.
	 * - object_type: (string) Target entity type.
	 * - fencing_token: (int) Active fencing token (>= 1).
	 * - before_state: (mixed) Captured pre-mutation state.
	 * Optional parameters:
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
		$action        = trim( (string) ( $params['action'] ?? '' ) );
		$object_type   = trim( (string) ( $params['object_type'] ?? '' ) );
		$fencing_token = (int) ( $params['fencing_token'] ?? 0 );

		if ( '' === $ability || '' === $action || '' === $object_type ) {
			return new \WP_Error(
				'invalid_journal_parameters',
				__( 'Ability, action, and object_type are required for journal begin.', 'full-elementor-mcp' )
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

		$object_id       = max( 0, (int) ( $params['object_id'] ?? 0 ) );
		$user_id         = ! empty( $params['user_id'] ) ? (int) $params['user_id'] : get_current_user_id();
		$credential_uuid = isset( $params['credential_uuid'] ) ? sanitize_text_field( (string) $params['credential_uuid'] ) : null;

		// Serialize pre-mutation state.
		$before_state_raw = $params['before_state'] ?? null;
		$before_state_str = self::serialize_state( $before_state_raw );
		if ( is_wp_error( $before_state_str ) ) {
			return $before_state_str;
		}

		$before_hash = hash( 'sha256', $before_state_str );
		$table       = Full_Elementor_MCP_Database_Installer::get_journal_table();

		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (
				created_at, updated_at, ability, action, object_type, object_id,
				created_object_id, fencing_token, before_state, before_hash,
				after_hash, status, error_message, user_id, credential_uuid
			) VALUES (
				UTC_TIMESTAMP(), UTC_TIMESTAMP(), %s, %s, %s, %d,
				NULL, %d, %s, %s,
				NULL, %s, NULL, %d, %s
			)",
			$ability,
			$action,
			$object_type,
			$object_id,
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

		$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
		$sql   = $wpdb->prepare(
			"UPDATE {$table}
			SET created_object_id = %d, updated_at = UTC_TIMESTAMP()
			WHERE id = %d
			  AND status = %s
			  AND fencing_token = %d",
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
			// Check if already updated to identical created_object_id (idempotent re-entry).
			$row = self::get_entry( $journal_id );
			if ( $row && (int) ( $row['created_object_id'] ?? 0 ) === $created_object_id && self::STATUS_PENDING === $row['status'] ) {
				return true;
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
	 * Enforces conditional atomic UPDATE to prevent lost updates or stale writer commits.
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

		// Idempotent commit check.
		if ( self::STATUS_COMMITTED === $entry['status'] ) {
			if ( (int) $entry['fencing_token'] === $fencing_token ) {
				return true;
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

		$after_serialized = self::serialize_state( $after_state );
		if ( is_wp_error( $after_serialized ) ) {
			return $after_serialized;
		}

		$after_hash = hash( 'sha256', $after_serialized );
		$table      = Full_Elementor_MCP_Database_Installer::get_journal_table();

		$sql = $wpdb->prepare(
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
				return true;
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
	 * Rolls back a journaled mutation using its registered strategy.
	 *
	 * Requirements:
	 * 1. Fetch journal row.
	 * 2. Verify status allows rollback (pending, committed, or failed).
	 * 3. Locate registered mutation strategy in Full_Elementor_MCP_Mutation_Registry.
	 * 4. Verify strategy supports rollback (supports_rollback === true). Fails closed if not.
	 * 5. If caller provided owner_id and caller_fencing_token, verify lock ownership via
	 *    Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership(). Stale writer rejected!
	 * 6. Conflict detection: if committed after_hash exists and live state can be captured,
	 *    verify live state hash equals after_hash before overwriting (unless forced).
	 * 7. Restore BEFORE state using strategy restore callback.
	 * 8. Only when restoration succeeds, mark journal status as rolled_back.
	 *
	 * @param int         $journal_id           Journal row ID.
	 * @param string|null $current_owner_id     Caller's lock owner ID (if caller holds lock).
	 * @param int         $caller_fencing_token Caller's fencing token (if caller holds lock).
	 * @param array       $options              Optional flags (e.g. 'force' => bool).
	 * @return array{success: bool, journal_id: int, status: string, object_id: int}|\WP_Error
	 */
	public static function rollback(
		int $journal_id,
		?string $current_owner_id = null,
		int $caller_fencing_token = 0,
		array $options = array()
	) {
		global $wpdb;

		if ( $journal_id <= 0 ) {
			return new \WP_Error(
				'invalid_journal_parameters',
				__( 'Invalid journal ID for rollback.', 'full-elementor-mcp' )
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

		// Resolve target object ID and args.
		$is_create = Full_Elementor_MCP_Mutation_Registry::CATEGORY_WP_OBJECT_CREATE === $strategy['category'];
		if ( $is_create ) {
			$target_id = (int) ( $entry['created_object_id'] ?? 0 );
			if ( $target_id <= 0 ) {
				// Object was never created or recorded. Mark rolled_back directly.
				return self::mark_rolled_back_internal( $journal_id, $entry['status'], 0 );
			}
		} else {
			$target_id = (int) $entry['object_id'];
		}

		$resolver_args = array(
			'post_id'           => $target_id,
			'object_id'         => $target_id,
			'page_id'           => $target_id,
			'created_object_id' => $target_id,
		);

		// Verify fencing ownership immediately before write if caller claims ownership.
		if ( ! empty( $current_owner_id ) && $caller_fencing_token > 0 ) {
			$resource_key = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( $entry['ability'], $resolver_args );
			if ( ! is_wp_error( $resource_key ) ) {
				$fencing_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership(
					$resource_key,
					$current_owner_id,
					$caller_fencing_token
				);
				if ( is_wp_error( $fencing_check ) ) {
					return $fencing_check;
				}
			}
		}

		// Conflict detection: verify live state has not diverged from committed after_hash.
		// Creation mutations skip content divergence check and directly proceed to trashing the created object.
		if ( ! $is_create && self::STATUS_COMMITTED === $entry['status'] && ! empty( $entry['after_hash'] ) && empty( $options['force'] ) ) {
			$capture_fn = $strategy['capture_after'] ?? $strategy['capture_before'];
			if ( is_callable( $capture_fn ) ) {
				$live_state = $capture_fn( $target_id, $resolver_args, null );
				if ( ! is_wp_error( $live_state ) && null !== $live_state ) {
					$live_hash = self::hash_state( $live_state );
					if ( ! hash_equals( (string) $entry['after_hash'], $live_hash ) ) {
						return new \WP_Error(
							'journal_state_conflict',
							__( 'Cannot rollback: live state has diverged from journal committed state (newer modifications detected).', 'full-elementor-mcp' )
						);
					}
				}
			}
		}

		// Execute strategy restore callback.
		$restore_fn      = $strategy['restore_before'];
		$restore_context = array_merge(
			$entry,
			array(
				'object_id'         => $target_id,
				'created_object_id' => $target_id,
				'post_id'           => $target_id,
			)
		);

		if ( $is_create ) {
			$restore_result = $restore_fn( null, $restore_context );
		} else {
			$before_state_decoded = self::deserialize_state( $entry['before_state'] );
			$restore_result       = $restore_fn( $before_state_decoded, $restore_context );
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
	 * INVARIANT:
	 * An operation is ONLY considered abandoned when its associated lock lease has
	 * expired + the grace period (default 60 seconds) has elapsed. Active leases
	 * or leases within the grace period are never touched.
	 *
	 * Unsupported mutation strategies fail closed and are marked failed rather
	 * than falsely reported as recovered.
	 *
	 * @param int $grace_seconds Grace period in seconds (default 60).
	 * @return array<int, array<string, mixed>> Recovery summary reports.
	 */
	public static function recover_pending( int $grace_seconds = self::RECOVERY_GRACE_PERIOD_SECONDS ): array {
		global $wpdb;

		$table        = Full_Elementor_MCP_Database_Installer::get_journal_table();
		$tokens_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$grace        = max( 10, $grace_seconds );

		// Query pending journal entries.
		$pending_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT 50",
				self::STATUS_PENDING
			),
			ARRAY_A
		);

		if ( empty( $pending_rows ) ) {
			return array();
		}

		$results = array();

		foreach ( $pending_rows as $row ) {
			$journal_id = (int) $row['id'];
			$ability    = (string) $row['ability'];
			$object_id  = (int) ( $row['created_object_id'] ?: $row['object_id'] );

			$resolver_args = array(
				'object_id'         => $object_id,
				'id'                => $object_id,
				'post_id'           => $object_id,
				'page_id'           => $object_id,
				'kit_id'            => $object_id,
				'snippet_id'        => $object_id,
				'created_object_id' => $object_id,
			);

			$resource_key = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( $ability, $resolver_args );
			$is_abandoned = false;

			if ( ! is_wp_error( $resource_key ) ) {
				$lock_key  = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource_key );
				$lock_info = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT fencing_token, expires_at,
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

				if ( ! empty( $lock_info ) ) {
					// Lock row exists. Check if expired + grace period passed.
					if ( ! empty( $lock_info['is_abandoned'] ) ) {
						$is_abandoned = true;
					}
				} else {
					// No lock row exists in tokens table.
					// Check if journal entry is older than default lease TTL + grace period.
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
			} else {
				// Cannot resolve resource key. Check raw journal age.
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

			// If not abandoned (still active or within grace period), do not touch.
			if ( ! $is_abandoned ) {
				continue;
			}

			// For abandoned operations, verify strategy support.
			$strategy = Full_Elementor_MCP_Mutation_Registry::get( $ability );
			if ( ! $strategy || empty( $strategy['supports_rollback'] ) ) {
				// Unsupported strategy: fail closed and record failure.
				self::mark_failed(
					$journal_id,
					'abandoned_pending_unsupported_strategy'
				);
				$results[] = array(
					'journal_id' => $journal_id,
					'ability'    => $ability,
					'status'     => self::STATUS_FAILED,
					'reason'     => 'unsupported_strategy',
				);
				continue;
			}

			// Rollback supported strategy.
			$rollback_result = self::rollback( $journal_id, null, 0, array( 'force' => false ) );
			if ( is_wp_error( $rollback_result ) ) {
				self::mark_failed(
					$journal_id,
					sprintf( 'Recovery rollback failed: %s', $rollback_result->get_error_message() )
				);
				$results[] = array(
					'journal_id' => $journal_id,
					'ability'    => $ability,
					'status'     => self::STATUS_FAILED,
					'reason'     => $rollback_result->get_error_code(),
				);
			} else {
				$results[] = array(
					'journal_id' => $journal_id,
					'ability'    => $ability,
					'status'     => self::STATUS_ROLLED_BACK,
					'reason'     => 'recovered',
				);
			}
		}

		return $results;
	}
}
