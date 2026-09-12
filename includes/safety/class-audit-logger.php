<?php
/**
 * Append-Only Forensic Audit Logger for Full Elementor MCP.
 *
 * Provides immutable, centralized forensic audit logging for safety events,
 * mutations, checkpoint operations, and operator actions.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative Audit Logger.
 *
 * Strictly append-only: Application code inserts events and prunes old rows
 * according to retention, but NEVER updates historical audit rows.
 *
 * @since 1.8.0
 */
final class Full_Elementor_MCP_Audit_Logger {

	// -------------------------------------------------------------------------
	// Event Taxonomy Constants
	// -------------------------------------------------------------------------

	public const EVENT_MUTATION_STARTED             = 'mutation_started';
	public const EVENT_MUTATION_COMMITTED           = 'mutation_committed';
	public const EVENT_MUTATION_FAILED              = 'mutation_failed';
	public const EVENT_MUTATION_ROLLED_BACK         = 'mutation_rolled_back';
	public const EVENT_MUTATION_RECOVERY_REQUIRED   = 'mutation_recovery_required';

	public const EVENT_MUTATION_BLOCKED_SCOPE        = 'mutation_blocked_scope';
	public const EVENT_MUTATION_BLOCKED_PERMISSION   = 'mutation_blocked_permission';
	public const EVENT_MUTATION_BLOCKED_DISABLED     = 'mutation_blocked_disabled';
	public const EVENT_MUTATION_BLOCKED_CONFIRMATION = 'mutation_blocked_confirmation';
	public const EVENT_MUTATION_BLOCKED_SECURITY     = 'mutation_blocked_security';

	public const EVENT_CHECKPOINT_CREATED          = 'checkpoint_created';
	public const EVENT_CHECKPOINT_CREATE_FAILED    = 'checkpoint_create_failed';
	public const EVENT_CHECKPOINT_RESTORE_STARTED  = 'checkpoint_restore_started';
	public const EVENT_CHECKPOINT_RESTORED         = 'checkpoint_restored';
	public const EVENT_CHECKPOINT_RESTORE_FAILED   = 'checkpoint_restore_failed';

	public const EVENT_UNDO_STARTED                = 'undo_started';
	public const EVENT_UNDO_COMPLETED              = 'undo_completed';
	public const EVENT_UNDO_FAILED                 = 'undo_failed';

	public const EVENT_CONFIRMATION_ISSUED         = 'confirmation_issued';
	public const EVENT_CONFIRMATION_CONSUMED       = 'confirmation_consumed';
	public const EVENT_CONFIRMATION_REJECTED       = 'confirmation_rejected';

	public const EVENT_IDEMPOTENT_REPLAY           = 'idempotent_replay';

	public const EVENT_RECOVERY_SCAN_STARTED       = 'recovery_scan_started';
	public const EVENT_RECOVERY_COMPLETED          = 'recovery_completed';
	public const EVENT_RECOVERY_MANUAL_REQUIRED    = 'recovery_manual_required';

	// -------------------------------------------------------------------------
	// Severity Constants
	// -------------------------------------------------------------------------

	public const SEVERITY_INFO     = 'info';
	public const SEVERITY_NOTICE   = 'notice';
	public const SEVERITY_WARNING  = 'warning';
	public const SEVERITY_ERROR    = 'error';
	public const SEVERITY_CRITICAL = 'critical';

	public const SEV_DEBUG    = 'debug';
	public const SEV_INFO     = 'info';
	public const SEV_NOTICE   = 'notice';
	public const SEV_WARNING  = 'warning';
	public const SEV_ERROR    = 'error';
	public const SEV_CRITICAL = 'critical';

	/**
	 * Maximum allowed serialized byte size for audit metadata (32 KiB).
	 */
	public const MAX_SERIALIZED_BYTES = 32768;

	/**
	 * Default retention period in days.
	 */
	public const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Sensitive keys that must always be redacted from audit args/metadata.
	 */
	private const SENSITIVE_KEYS = array(
		'password',
		'post_password',
		'authorization',
		'cookie',
		'nonce',
		'confirmation_token',
		'idempotency_key',
		'api_key',
		'secret',
		'access_token',
		'refresh_token',
		'bearer_token',
		'private_key',
		'raw_key',
		'encrypted_payload',
		'ciphertext',
	);

	/**
	 * Potentially large or executable content keys that must be summarized rather than stored raw.
	 */
	private const CONTENT_SUMMARY_KEYS = array(
		'code',
		'custom_css',
		'css',
		'html',
		'raw_html',
		'elements',
		'data',
		'before_state',
		'after_state',
		'payload',
		'svg_content',
		'file_content',
	);

	/**
	 * Logs a forensic audit event.
	 *
	 * Guarantees:
	 * - Generates an immutable event UUID.
	 * - Uses DB UTC timestamp.
	 * - Strictly sanitizes args and metadata, redacting secrets and summarizing large trees/code.
	 * - Enforces the 32 KiB serialized size limit.
	 * - Append-only: never modifies existing rows.
	 *
	 * @param string               $event Event identifier (one of EVENT_* constants).
	 * @param array<string, mixed> $data  Event details.
	 * @return string|\WP_Error Generated event_uuid on success, or WP_Error on failure.
	 */
	public static function log( string $event, array $data = array() ) {
		global $wpdb;

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return new \WP_Error( 'audit_unavailable', __( 'Database installer unavailable for audit logging.', 'full-elementor-mcp' ) );
		}

		$table = Full_Elementor_MCP_Database_Installer::get_audit_log_table();

		// 1. Generate immutable event UUID:
		$event_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );

		// 2. Resolve request UUID (use provided or context, but NEVER trust raw caller input):
		$request_uuid = null;
		if ( ! empty( $data['request_uuid'] ) && is_string( $data['request_uuid'] ) ) {
			$request_uuid = sanitize_text_field( $data['request_uuid'] );
		} elseif ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) && Full_Elementor_MCP_Mutation_Context::has_active_context() ) {
			$ctx          = Full_Elementor_MCP_Mutation_Context::current();
			$request_uuid = ! empty( $ctx['request_uuid'] ) ? (string) $ctx['request_uuid'] : null;
		}

		// 3. Resolve actor and credential info:
		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		if ( $user_id <= 0 && class_exists( 'Full_Elementor_MCP_Mutation_Context' ) && Full_Elementor_MCP_Mutation_Context::has_active_context() ) {
			$ctx     = Full_Elementor_MCP_Mutation_Context::current();
			$user_id = (int) ( $ctx['user_id'] ?? 0 );
		}

		$credential_uuid = isset( $data['credential_uuid'] ) ? sanitize_text_field( (string) $data['credential_uuid'] ) : null;
		if ( empty( $credential_uuid ) && class_exists( 'Full_Elementor_MCP_Mutation_Context' ) && Full_Elementor_MCP_Mutation_Context::has_active_context() ) {
			$ctx             = Full_Elementor_MCP_Mutation_Context::current();
			$credential_uuid = ! empty( $ctx['credential_uuid'] ) ? (string) $ctx['credential_uuid'] : null;
		}

		// 4. Resolve IP address (direct REMOTE_ADDR only):
		$ip_address = self::resolve_ip_address();

		// 5. Severity resolution:
		$severity = isset( $data['severity'] ) ? sanitize_key( (string) $data['severity'] ) : self::SEVERITY_INFO;
		if ( ! in_array( $severity, array( self::SEVERITY_INFO, self::SEVERITY_NOTICE, self::SEVERITY_WARNING, self::SEVERITY_ERROR, self::SEVERITY_CRITICAL, self::SEV_DEBUG ), true ) ) {
			$severity = self::SEVERITY_INFO;
		}

		// 6. Target identifiers:
		$ability         = isset( $data['ability'] ) ? sanitize_text_field( (string) $data['ability'] ) : null;
		$object_type     = isset( $data['object_type'] ) ? sanitize_text_field( (string) $data['object_type'] ) : null;
		$object_id       = isset( $data['object_id'] ) ? (int) $data['object_id'] : null;
		$resource_key    = isset( $data['resource_key'] ) ? sanitize_text_field( (string) $data['resource_key'] ) : '';
		$change_id       = isset( $data['change_id'] ) ? (int) $data['change_id'] : ( isset( $data['journal_id'] ) ? (int) $data['journal_id'] : null );
		$checkpoint_uuid = isset( $data['checkpoint_uuid'] ) ? sanitize_text_field( (string) $data['checkpoint_uuid'] ) : null;
		$result_status   = isset( $data['result_status'] ) ? sanitize_key( (string) $data['result_status'] ) : null;
		$exec_time_ms    = isset( $data['execution_time_ms'] ) ? (int) $data['execution_time_ms'] : 0;
		$error_code      = isset( $data['error_code'] ) ? sanitize_key( (string) $data['error_code'] ) : null;

		// 7. Sanitize args:
		$args_sanitized_str = null;
		if ( isset( $data['args'] ) && is_array( $data['args'] ) ) {
			$sanitized_args     = self::sanitize_audit_args( $data['args'] );
			$args_sanitized_str = self::serialize_bounded( $sanitized_args );
		}

		// 8. Sanitize metadata:
		$meta_sanitized_str = null;
		if ( isset( $data['metadata'] ) && is_array( $data['metadata'] ) ) {
			$sanitized_meta     = self::sanitize_audit_args( $data['metadata'] );
			$meta_sanitized_str = self::serialize_bounded( $sanitized_meta );
		}

		// 9. Execute append-only insert with DB UTC timestamp:
		$query = $wpdb->prepare(
			"INSERT INTO {$table} (
				event_uuid,
				timestamp,
				event,
				ability,
				object_type,
				object_id,
				resource_key,
				request_uuid,
				checkpoint_uuid,
				user_id,
				credential_uuid,
				ip_address,
				severity,
				error_code,
				args_sanitized,
				metadata_sanitized,
				result_status,
				change_id,
				execution_time_ms
			) VALUES (
				%s,
				UTC_TIMESTAMP(),
				%s,
				%s,
				%s,
				%d,
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
				%s,
				%d,
				%d
			)",
			$event_uuid,
			$event,
			$ability,
			$object_type,
			$object_id,
			$resource_key,
			$request_uuid,
			$checkpoint_uuid,
			$user_id > 0 ? $user_id : null,
			$credential_uuid,
			$ip_address,
			$severity,
			$error_code,
			$args_sanitized_str,
			$meta_sanitized_str,
			$result_status,
			$change_id,
			$exec_time_ms
		);

		$res = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $res || 0 === $res ) {
			return new \WP_Error(
				'audit_write_failed',
				__( 'Failed to write audit event to database.', 'full-elementor-mcp' )
			);
		}

		return $event_uuid;
	}

	/**
	 * Resolves IP address safely from direct REMOTE_ADDR.
	 *
	 * @return string|null Validated IP address or null.
	 */
	public static function resolve_ip_address(): ?string {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}

		$raw = (string) $_SERVER['REMOTE_ADDR'];
		$filtered = filter_var( $raw, FILTER_VALIDATE_IP );

		return false !== $filtered ? (string) $filtered : null;
	}

	/**
	 * Centralized sanitization for audit arguments and metadata.
	 *
	 * - Strips all secret-bearing keys.
	 * - Replaces large or executable code/markup/trees with safe structural summaries.
	 * - Recurses into nested structures.
	 *
	 * @param array<string, mixed> $input Raw input data.
	 * @return array<string, mixed> Sanitized array.
	 */
	public static function sanitize_audit_args( array $input ): array {
		$output = array();

		foreach ( $input as $key => $value ) {
			$lower_key = strtolower( (string) $key );

			// 1. Redact direct sensitive keys:
			if ( in_array( $lower_key, self::SENSITIVE_KEYS, true )
				|| str_contains( $lower_key, 'token' )
				|| str_contains( $lower_key, 'secret' )
				|| str_contains( $lower_key, 'password' )
				|| str_contains( $lower_key, 'pass' )
				|| str_contains( $lower_key, 'auth' )
				|| str_contains( $lower_key, 'key' )
				|| str_contains( $lower_key, 'cookie' )
				|| str_contains( $lower_key, 'nonce' )
			) {
				$output[ $key ] = '[REDACTED]';
				continue;
			}

			// 2. Summarize large content / executable fields:
			if ( in_array( $lower_key, self::CONTENT_SUMMARY_KEYS, true ) ) {
				if ( is_string( $value ) ) {
					$output[ $key ] = array(
						'redacted' => true,
						'length'   => strlen( $value ),
						'sha256'   => hash( 'sha256', $value ),
					);
				} elseif ( is_array( $value ) ) {
					$json_str = wp_json_encode( $value );
					$output[ $key ] = array(
						'redacted'       => true,
						'count'          => count( $value ),
						'elements_count' => count( $value ),
						'sha256'         => hash( 'sha256', false !== $json_str ? $json_str : '' ),
					);
				} else {
					$output[ $key ] = '[REDACTED]';
				}
				continue;
			}

			// 3. Recurse into nested arrays:
			if ( is_array( $value ) ) {
				$output[ $key ] = self::sanitize_audit_args( $value );
				continue;
			}

			// 4. Scalar values:
			if ( is_scalar( $value ) || null === $value ) {
				// Prevent long arbitrary strings from exhausting memory:
				if ( is_string( $value ) && strlen( $value ) > 512 ) {
					$output[ $key ] = array(
						'redacted' => true,
						'length'   => strlen( $value ),
						'sha256'   => hash( 'sha256', $value ),
					);
				} else {
					$output[ $key ] = $value;
				}
			} else {
				$output[ $key ] = '[NON_SCALAR]';
			}
		}

		return $output;
	}

	/**
	 * Serializes an array to JSON while strictly enforcing the maximum size limit.
	 *
	 * @param array<string, mixed> $data
	 * @return string Serialized JSON string.
	 */
	public static function serialize_bounded( array $data ): string {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			$json = wp_json_encode( array( 'error' => 'json_encode_failed' ) );
		}

		if ( strlen( (string) $json ) > self::MAX_SERIALIZED_BYTES ) {
			// Replace with summary if limit exceeded:
			$summary = array(
				'truncated'     => true,
				'original_size' => strlen( (string) $json ),
				'sha256'        => hash( 'sha256', (string) $json ),
				'top_keys'      => array_keys( $data ),
			);
			return (string) wp_json_encode( $summary );
		}

		return (string) $json;
	}

	/**
	 * Prunes audit log records older than retention threshold using DB UTC.
	 *
	 * Critical protection: Preserves unresolved safety events and errors indefinitely
	 * or until explicitly cleared so operator forensic trails are not destroyed.
	 *
	 * @param int $retention_days Maximum age in days (default 90).
	 * @param int $batch_limit    Maximum records to delete in one batch (default 100).
	 * @return int Count of deleted rows.
	 */
	public static function prune( int $retention_days = self::DEFAULT_RETENTION_DAYS, int $batch_limit = 100 ): int {
		global $wpdb;

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return 0;
		}

		$table = Full_Elementor_MCP_Database_Installer::get_audit_log_table();

		// Exclude critical/error severity and unresolved recovery events from automatic pruning:
		$protected_events = array(
			self::EVENT_RECOVERY_MANUAL_REQUIRED,
			self::EVENT_MUTATION_RECOVERY_REQUIRED,
			self::EVENT_CHECKPOINT_RESTORE_FAILED,
			self::EVENT_UNDO_FAILED,
		);
		$safe_events = array();
		foreach ( $protected_events as $ev ) {
			$safe_events[] = "'" . preg_replace( '/[^a-z0-9_\-]/', '', $ev ) . "'";
		}
		$exclude_events_sql = implode( ',', $safe_events );

		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE timestamp < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				   AND severity NOT IN ('error', 'critical')
				   AND event NOT IN ({$exclude_events_sql})
				 ORDER BY id ASC
				 LIMIT %d",
				$retention_days,
				$batch_limit
			)
		);

		if ( empty( $candidates ) ) {
			return 0;
		}

		$ids_in  = implode( ',', array_map( 'intval', $candidates ) );
		$deleted = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids_in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Queries audit log events with bounded pagination and safe allowlisted filtering.
	 *
	 * @param array<string, mixed> $filters Allowlisted filter criteria.
	 * @param int                  $limit   Page limit (max 100).
	 * @param int                  $offset  Offset.
	 * @return array<string, mixed>[] Array of audit rows.
	 */
	public static function query( array $filters = array(), int $limit = 25, int $offset = 0 ): array {
		global $wpdb;

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return array();
		}

		$table         = Full_Elementor_MCP_Database_Installer::get_audit_log_table();
		$limit         = max( 1, min( 200, $limit ) );
		$offset        = max( 0, $offset );
		$where_clauses = array( '1=1' );
		$params        = array();

		$event = $filters['event'] ?? ( $filters['event_type'] ?? null );
		if ( ! empty( $event ) ) {
			$where_clauses[] = 'event = %s';
			$params[]        = sanitize_key( (string) $event );
		}

		if ( ! empty( $filters['ability'] ) ) {
			$where_clauses[] = 'ability = %s';
			$params[]        = sanitize_text_field( (string) $filters['ability'] );
		}

		$res_key = $filters['resource_key'] ?? ( $filters['resource_filter'] ?? null );
		if ( ! empty( $res_key ) ) {
			$where_clauses[] = 'resource_key = %s';
			$params[]        = sanitize_text_field( (string) $res_key );
		}

		if ( ! empty( $filters['severity'] ) ) {
			$where_clauses[] = 'severity = %s';
			$params[]        = sanitize_key( (string) $filters['severity'] );
		}

		if ( isset( $filters['user_id'] ) && is_numeric( $filters['user_id'] ) ) {
			$where_clauses[] = 'user_id = %d';
			$params[]        = (int) $filters['user_id'];
		}

		if ( isset( $filters['change_id'] ) && is_numeric( $filters['change_id'] ) ) {
			$where_clauses[] = 'change_id = %d';
			$params[]        = (int) $filters['change_id'];
		}

		if ( ! empty( $filters['checkpoint_uuid'] ) ) {
			$where_clauses[] = 'checkpoint_uuid = %s';
			$params[]        = sanitize_text_field( (string) $filters['checkpoint_uuid'] );
		}

		if ( ! empty( $filters['request_uuid'] ) ) {
			$where_clauses[] = 'request_uuid = %s';
			$params[]        = sanitize_text_field( (string) $filters['request_uuid'] );
		}

		if ( ! empty( $filters['result_status'] ) ) {
			$where_clauses[] = 'result_status = %s';
			$params[]        = sanitize_key( (string) $filters['result_status'] );
		}

		$date_from = $filters['date_from'] ?? ( $filters['since'] ?? null );
		if ( ! empty( $date_from ) ) {
			$where_clauses[] = 'timestamp >= %s';
			$params[]        = sanitize_text_field( (string) $date_from );
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where_clauses[] = 'timestamp <= %s';
			$params[]        = sanitize_text_field( (string) $filters['date_to'] );
		}

		$where_sql = implode( ' AND ', $where_clauses );
		$params[]  = $limit;
		$params[]  = $offset;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
			...$params
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns count of audit records matching filters.
	 *
	 * @param array<string, mixed> $filters
	 * @return int
	 */
	public static function count( array $filters = array() ): int {
		global $wpdb;

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return 0;
		}

		$table         = Full_Elementor_MCP_Database_Installer::get_audit_log_table();
		$where_clauses = array( '1=1' );
		$params        = array();

		$event = $filters['event'] ?? ( $filters['event_type'] ?? null );
		if ( ! empty( $event ) ) {
			$where_clauses[] = 'event = %s';
			$params[]        = sanitize_key( (string) $event );
		}

		if ( ! empty( $filters['ability'] ) ) {
			$where_clauses[] = 'ability = %s';
			$params[]        = sanitize_text_field( (string) $filters['ability'] );
		}

		$res_key = $filters['resource_key'] ?? ( $filters['resource_filter'] ?? null );
		if ( ! empty( $res_key ) ) {
			$where_clauses[] = 'resource_key = %s';
			$params[]        = sanitize_text_field( (string) $res_key );
		}

		if ( ! empty( $filters['severity'] ) ) {
			$where_clauses[] = 'severity = %s';
			$params[]        = sanitize_key( (string) $filters['severity'] );
		}

		if ( isset( $filters['user_id'] ) && is_numeric( $filters['user_id'] ) ) {
			$where_clauses[] = 'user_id = %d';
			$params[]        = (int) $filters['user_id'];
		}

		if ( isset( $filters['change_id'] ) && is_numeric( $filters['change_id'] ) ) {
			$where_clauses[] = 'change_id = %d';
			$params[]        = (int) $filters['change_id'];
		}

		if ( ! empty( $filters['checkpoint_uuid'] ) ) {
			$where_clauses[] = 'checkpoint_uuid = %s';
			$params[]        = sanitize_text_field( (string) $filters['checkpoint_uuid'] );
		}

		if ( ! empty( $filters['request_uuid'] ) ) {
			$where_clauses[] = 'request_uuid = %s';
			$params[]        = sanitize_text_field( (string) $filters['request_uuid'] );
		}

		if ( ! empty( $filters['result_status'] ) ) {
			$where_clauses[] = 'result_status = %s';
			$params[]        = sanitize_key( (string) $filters['result_status'] );
		}

		$date_from = $filters['date_from'] ?? ( $filters['since'] ?? null );
		if ( ! empty( $date_from ) ) {
			$where_clauses[] = 'timestamp >= %s';
			$params[]        = sanitize_text_field( (string) $date_from );
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where_clauses[] = 'timestamp <= %s';
			$params[]        = sanitize_text_field( (string) $filters['date_to'] );
		}

		$where_sql = implode( ' AND ', $where_clauses );

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$params );
		} else {
			$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Retrieves single audit event record by numeric ID or event UUID.
	 *
	 * @param int|string $id_or_uuid
	 * @return array<string, mixed>|null
	 */
	public static function get_event( int|string $id_or_uuid ): ?array {
		global $wpdb;

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return null;
		}

		$table = Full_Elementor_MCP_Database_Installer::get_audit_log_table();

		if ( is_numeric( $id_or_uuid ) && (int) $id_or_uuid > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id_or_uuid ),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE event_uuid = %s LIMIT 1", (string) $id_or_uuid ),
				ARRAY_A
			);
		}

		return ! empty( $row ) && is_array( $row ) ? $row : null;
	}
}
