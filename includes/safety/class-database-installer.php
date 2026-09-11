<?php
/**
 * Database installer and migration coordinator for safety subsystem.
 *
 * Creates and manages the four core safety tables:
 * - elementor_mcp_journal
 * - elementor_mcp_checkpoints
 * - elementor_mcp_audit_log
 * - elementor_mcp_tokens
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles table creation, migrations, and schema verifications.
 */
class Full_Elementor_MCP_Database_Installer {

	/**
	 * Current database schema version.
	 *
	 * 1.3.0: Unambiguous historical crypto envelope generations and payload schema v2.
	 */
	public const DB_VERSION = '1.3.0';

	/**
	 * Option key storing installed schema version.
	 */
	public const OPTION_DB_VERSION = 'full_elementor_mcp_db_version';

	/**
	 * Returns full table name for the journal table.
	 */
	public static function get_journal_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'elementor_mcp_journal';
	}

	/**
	 * Returns full table name for the checkpoints table.
	 */
	public static function get_checkpoints_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'elementor_mcp_checkpoints';
	}

	/**
	 * Returns full table name for the audit log table.
	 */
	public static function get_audit_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'elementor_mcp_audit_log';
	}

	/**
	 * Returns full table name for the tokens/locks/idempotency table.
	 */
	public static function get_tokens_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'elementor_mcp_tokens';
	}

	/**
	 * Returns dbDelta-compliant table definitions for all 4 safety tables.
	 *
	 * Note: dbDelta requires two spaces after PRIMARY KEY and fields on separate lines.
	 *
	 * @return string[] Array of SQL CREATE TABLE statements.
	 */
	public static function get_schema_definitions(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		if ( empty( $charset_collate ) ) {
			$charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		$journal_table     = self::get_journal_table();
		$checkpoints_table = self::get_checkpoints_table();
		$audit_log_table   = self::get_audit_log_table();
		$tokens_table      = self::get_tokens_table();

		return array(
			// 1. Write-Ahead Journal table.
			"CREATE TABLE {$journal_table} (
				id bigint(20) unsigned NOT NULL auto_increment,
				created_at datetime NOT NULL default CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
				ability varchar(100) NOT NULL,
				action varchar(20) NOT NULL,
				object_type varchar(30) NOT NULL,
				object_id bigint(20) unsigned NOT NULL default 0,
				created_object_id bigint(20) unsigned default NULL,
				resource_key varchar(128) NOT NULL default '',
				rollback_supported tinyint(1) NOT NULL default 0,
				fencing_token bigint(20) unsigned NOT NULL default 0,
				before_state longtext default NULL,
				before_hash varchar(64) default NULL,
				after_hash varchar(64) default NULL,
				status varchar(20) NOT NULL default 'pending',
				error_message text default NULL,
				user_id bigint(20) unsigned default NULL,
				credential_uuid varchar(64) default NULL,
				PRIMARY KEY  (id),
				KEY idx_status (status),
				KEY idx_object (object_type, object_id),
				KEY idx_resource (resource_key),
				KEY idx_created (created_at)
			) {$charset_collate};",

			// 2. Checkpoints table: durable, encrypted historical snapshots.
			"CREATE TABLE {$checkpoints_table} (
				id bigint(20) unsigned NOT NULL auto_increment,
				checkpoint_uuid varchar(64) NOT NULL default '',
				created_at datetime NOT NULL default CURRENT_TIMESTAMP,
				resource_key varchar(128) NOT NULL default '',
				object_type varchar(30) NOT NULL default '',
				object_id bigint(20) unsigned NOT NULL default 0,
				checkpoint_type varchar(30) NOT NULL default 'automatic',
				restore_capability varchar(30) NOT NULL default 'exact',
				payload_schema_version int(10) unsigned NOT NULL default 2,
				crypto_envelope_version int(10) unsigned NOT NULL default 0,
				encryption_algorithm varchar(30) NOT NULL default 'none',
				key_version int(10) unsigned NOT NULL default 1,
				key_id varchar(64) default NULL,
				nonce varchar(64) NOT NULL default '',
				auth_tag varchar(64) default NULL,
				encrypted_payload longtext default NULL,
				state_hash varchar(64) NOT NULL default '',
				compression_algorithm varchar(20) default 'none',
				size_bytes bigint(20) unsigned default 0,
				label varchar(255) NOT NULL default '',
				description text default NULL,
				trigger_type varchar(20) NOT NULL default 'manual',
				file_path varchar(500) NOT NULL default '',
				file_hash_hmac varchar(64) NOT NULL default '',
				source_ability varchar(100) default NULL,
				source_journal_id bigint(20) unsigned default NULL,
				created_by bigint(20) unsigned default NULL,
				credential_uuid varchar(64) default NULL,
				is_pinned tinyint(1) NOT NULL default 0,
				PRIMARY KEY  (id),
				UNIQUE KEY idx_checkpoint_uuid (checkpoint_uuid),
				KEY idx_resource (resource_key),
				KEY idx_checkpoint_type (checkpoint_type),
				KEY idx_created (created_at)
			) {$charset_collate};",

			// 3. Forensic Audit Log table.
			"CREATE TABLE {$audit_log_table} (
				id bigint(20) unsigned NOT NULL auto_increment,
				timestamp datetime NOT NULL default CURRENT_TIMESTAMP,
				event varchar(50) NOT NULL,
				ability varchar(100) default NULL,
				object_type varchar(30) default NULL,
				object_id bigint(20) unsigned default NULL,
				user_id bigint(20) unsigned default NULL,
				credential_uuid varchar(64) default NULL,
				ip_address varchar(45) default NULL,
				args_sanitized longtext default NULL,
				result_status varchar(20) default NULL,
				change_id bigint(20) unsigned default NULL,
				execution_time_ms int(10) unsigned default 0,
				PRIMARY KEY  (id),
				KEY idx_timestamp (timestamp),
				KEY idx_event (event),
				KEY idx_ability (ability),
				KEY idx_change (change_id)
			) {$charset_collate};",

			// 4. Tokens, Locks & Idempotency table.
			"CREATE TABLE {$tokens_table} (
				token_key varchar(128) NOT NULL,
				token_type varchar(20) NOT NULL,
				owner_id varchar(64) default NULL,
				fencing_token bigint(20) unsigned NOT NULL default 0,
				payload longtext default NULL,
				created_at datetime NOT NULL default CURRENT_TIMESTAMP,
				expires_at datetime NOT NULL,
				used tinyint(1) NOT NULL default 0,
				PRIMARY KEY  (token_key),
				KEY idx_type_expires (token_type, expires_at),
				KEY idx_used (used)
			) {$charset_collate};",
		);
	}

	/**
	 * Executes schema installation/upgrade via dbDelta or direct query fallback.
	 *
	 * Updates the DB version option ONLY after full schema verification succeeds.
	 *
	 * @param string $from_version Currently installed schema version (e.g. '0.0.0' or '1.0.0').
	 *                             Reserved for future incremental schema migration dispatch (e.g. 1.0.0 -> 1.1.0)
	 *                             when schema alterations are introduced in future versions.
	 * @return bool True if upgrade was successful and verified.
	 */
	public static function upgrade( string $from_version = '0.0.0' ): bool {
		global $wpdb;

		$schemas = self::get_schema_definitions();

		if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $schemas );
		} else {
			// Direct query fallback for test harness or stripped environments.
			foreach ( $schemas as $sql ) {
				$res = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( false === $res ) {
					// Fallback for mock test harnesses whose regex strips KEY but leaves orphaned UNIQUE:
					$compat_sql = preg_replace( '/\bUNIQUE\s+KEY\s+([a-zA-Z0-9_]+)\s*\(([^)]+)\)/i', 'KEY $1 ($2)', $sql );
					if ( $compat_sql !== $sql ) {
						$wpdb->query( $compat_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					}
				}
			}
		}

		// Ensure any missing columns from expected schema are added via ALTER TABLE migration:
		self::ensure_missing_columns();

		// Migrate existing checkpoint records and enforce checkpoint_uuid single-column uniqueness:
		self::migrate_checkpoint_uuids_and_unique_constraint();

		// Migrate historical crypto envelope versions using bounded authenticated trial detection:
		self::migrate_crypto_envelopes();

		// Verify that all 4 required tables, columns, indexes, and unique constraints exist!
		if ( ! self::verify_schema() ) {
			return false;
		}

		// Only record new version after full schema verification succeeds.
		$saved = update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
		if ( ! $saved && get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
			return false;
		}

		return true;
	}

	/**
	 * Ensures all expected columns exist on all four safety tables, issuing ALTER TABLE ADD COLUMN where missing.
	 */
	public static function ensure_missing_columns(): void {
		global $wpdb;

		$col_defs = array(
			'checkpoint_uuid'         => "varchar(64) NOT NULL default ''",
			'resource_key'            => "varchar(128) NOT NULL default ''",
			'object_type'             => "varchar(30) NOT NULL default ''",
			'object_id'               => "bigint(20) unsigned NOT NULL default 0",
			'checkpoint_type'         => "varchar(30) NOT NULL default 'automatic'",
			'restore_capability'      => "varchar(30) NOT NULL default 'exact'",
			'payload_schema_version'  => "int(10) unsigned NOT NULL default 2",
			'crypto_envelope_version' => "int(10) unsigned NOT NULL default 0",
			'nonce'                   => "varchar(64) NOT NULL default ''",
			'auth_tag'                => "varchar(64) default NULL",
			'encrypted_payload'       => "longtext default NULL",
			'state_hash'              => "varchar(64) NOT NULL default ''",
			'compression_algorithm'   => "varchar(20) default 'none'",
			'source_ability'          => "varchar(100) default NULL",
			'source_journal_id'       => "bigint(20) unsigned default NULL",
			'credential_uuid'         => "varchar(64) default NULL",
			'is_pinned'               => "tinyint(1) NOT NULL default 0",
			'rollback_supported'      => "tinyint(1) NOT NULL default 0",
		);

		$expected = self::get_expected_schema();
		foreach ( $expected as $table => $spec ) {
			$actual = self::get_table_columns( $table );
			if ( empty( $actual ) ) {
				continue;
			}
			foreach ( $spec['columns'] as $col ) {
				$col_lower = strtolower( $col );
				if ( ! in_array( $col_lower, $actual, true ) && isset( $col_defs[ $col_lower ] ) ) {
					$def = $col_defs[ $col_lower ];
					$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col_lower} {$def}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
		}
	}

	/**
	 * Migrates existing checkpoint records and ensures checkpoint_uuid has a single-column UNIQUE guarantee.
	 *
	 * 1. Inspects existing checkpoint rows.
	 * 2. Assigns a unique server-generated UUID to any blank or duplicate checkpoint UUIDs.
	 * 3. Preserves all checkpoint payload and metadata (never deletes rows).
	 * 4. Removes/replaces conflicting old non-unique index if needed.
	 * 5. Creates a true single-column UNIQUE index on checkpoint_uuid.
	 */
	public static function migrate_checkpoint_uuids_and_unique_constraint(): void {
		global $wpdb;

		$chk_table = self::get_checkpoints_table();
		$cols      = self::get_table_columns( $chk_table );
		if ( empty( $cols ) ) {
			return;
		}

		// 1. Inspect existing rows in checkpoints table:
		$rows = $wpdb->get_results( "SELECT id, checkpoint_uuid FROM {$chk_table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $rows ) && is_array( $rows ) ) {
			$seen_uuids = array();
			foreach ( $rows as $row ) {
				$row_id = (int) ( $row['id'] ?? 0 );
				$uuid   = trim( (string) ( $row['checkpoint_uuid'] ?? '' ) );

				$needs_new_uuid = false;
				if ( '' === $uuid ) {
					$needs_new_uuid = true;
				} elseif ( isset( $seen_uuids[ $uuid ] ) ) {
					$needs_new_uuid = true;
				}

				if ( $needs_new_uuid ) {
					do {
						$new_uuid = function_exists( 'wp_generate_uuid4' )
							? wp_generate_uuid4()
							: sprintf(
								'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
								mt_rand( 0, 0xffff ),
								mt_rand( 0, 0xffff ),
								mt_rand( 0, 0xffff ),
								mt_rand( 0, 0x0fff ) | 0x4000,
								mt_rand( 0, 0x3fff ) | 0x8000,
								mt_rand( 0, 0xffff ),
								mt_rand( 0, 0xffff ),
								mt_rand( 0, 0xffff )
							);
					} while ( isset( $seen_uuids[ $new_uuid ] ) );

					$seen_uuids[ $new_uuid ] = true;
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$chk_table} SET checkpoint_uuid = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$new_uuid,
							$row_id
						)
					);
				} else {
					$seen_uuids[ $uuid ] = true;
				}
			}
		}

		// 2. Ensure single-column uniqueness on checkpoint_uuid:
		if ( ! self::verify_single_column_unique_constraint( $chk_table, 'checkpoint_uuid' ) ) {
			// Drop old non-unique index if it exists:
			@$wpdb->query( "ALTER TABLE {$chk_table} DROP INDEX idx_checkpoint_uuid" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			@$wpdb->query( "DROP INDEX IF EXISTS idx_checkpoint_uuid ON {$chk_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			@$wpdb->query( "DROP INDEX IF EXISTS {$chk_table}_idx_checkpoint_uuid" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Add single-column UNIQUE index:
			@$wpdb->query( "ALTER TABLE {$chk_table} ADD UNIQUE KEY idx_checkpoint_uuid (checkpoint_uuid)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			@$wpdb->query( "CREATE UNIQUE INDEX IF NOT EXISTS {$chk_table}_idx_checkpoint_uuid ON {$chk_table}(checkpoint_uuid)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Migrates historical checkpoints by identifying actual crypto envelope versions.
	 *
	 * Detects between Historical Format A (envelope v1: 6-field AAD) and
	 * Historical Format B / Modern (envelope v2: 8-field capability-bound AAD).
	 *
	 * Performs bounded trial AEAD authentication:
	 * 1. Attempt authentication with envelope v2 AAD
	 * 2. Attempt authentication with envelope v1 AAD
	 * 3. Exactly one successful trial identifies the envelope format (2 or 1)
	 * 4. If key material is missing or authentication fails, assigns 0 (unresolved legacy)
	 *
	 * Never rewrites ciphertext, never exposes plaintext, and never guesses.
	 */
	public static function migrate_crypto_envelopes(): void {
		global $wpdb;

		$chk_table = self::get_checkpoints_table();
		$cols      = self::get_table_columns( $chk_table );
		if ( empty( $cols ) || ! in_array( 'crypto_envelope_version', $cols, true ) ) {
			return;
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Checkpoint_Crypto' ) ) {
			return;
		}

		// Inspect historical rows whose envelope version is ambiguous (0, 1, or NULL):
		$rows = $wpdb->get_results(
			"SELECT id, checkpoint_uuid, resource_key, object_type, object_id, checkpoint_type, restore_capability, payload_schema_version, crypto_envelope_version, encryption_algorithm, key_version, key_id, nonce, auth_tag, encrypted_payload, state_hash FROM {$chk_table} WHERE crypto_envelope_version IS NULL OR crypto_envelope_version IN (0, 1)",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$row_id   = (int) ( $row['id'] ?? 0 );
			$detected = Full_Elementor_MCP_Checkpoint_Crypto::detect_envelope_version( $row );

			// If key is unavailable or verification failed, leave in explicit unresolved state (0):
			$new_envelope  = ( $detected > 0 ) ? $detected : 0;
			$curr_envelope = isset( $row['crypto_envelope_version'] ) ? (int) $row['crypto_envelope_version'] : null;

			if ( $curr_envelope !== $new_envelope ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$chk_table} SET crypto_envelope_version = %d WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$new_envelope,
						$row_id
					)
				);
			}
		}
	}

	/**
	 * Centralized specification of required safety schema constraints across all 4 tables.
	 *
	 * Ensures tokens, journal, checkpoints, and audit log tables contain all safety-critical
	 * columns, indexes, and primary key / uniqueness constraints before marking DB_VERSION installed.
	 *
	 * @return array<string, array{columns: string[], indexes: string[], primary: string}>
	 */
	public static function get_expected_schema(): array {
		return array(
			// Tokens table: enforces atomicity, locking, fencing, and idempotency.
			self::get_tokens_table()      => array(
				'columns'       => array(
					'token_key',
					'token_type',
					'owner_id',
					'fencing_token',
					'payload',
					'created_at',
					'expires_at',
					'used',
				),
				'indexes'       => array(
					'idx_type_expires',
					'idx_used',
				),
				'primary'       => 'token_key',
				'single_unique' => 'token_key',
			),
			// Journal table: Write-Ahead Journaling for atomicity and crash recovery.
			self::get_journal_table()     => array(
				'columns'       => array(
					'id',
					'created_at',
					'updated_at',
					'ability',
					'action',
					'object_type',
					'object_id',
					'created_object_id',
					'resource_key',
					'rollback_supported',
					'fencing_token',
					'before_state',
					'before_hash',
					'after_hash',
					'status',
					'error_message',
					'user_id',
					'credential_uuid',
				),
				'indexes'       => array(
					'idx_status',
					'idx_object',
					'idx_resource',
					'idx_created',
				),
				'primary'       => 'id',
				'single_unique' => 'id',
			),
			// Checkpoints table: full-site and resource rollback points.
			self::get_checkpoints_table() => array(
				'columns'       => array(
					'id',
					'checkpoint_uuid',
					'created_at',
					'resource_key',
					'object_type',
					'object_id',
					'checkpoint_type',
					'restore_capability',
					'payload_schema_version',
					'crypto_envelope_version',
					'encryption_algorithm',
					'key_version',
					'key_id',
					'nonce',
					'auth_tag',
					'encrypted_payload',
					'state_hash',
					'compression_algorithm',
					'size_bytes',
					'label',
					'description',
					'trigger_type',
					'file_path',
					'file_hash_hmac',
					'source_ability',
					'source_journal_id',
					'created_by',
					'credential_uuid',
					'is_pinned',
				),
				'indexes'       => array(
					'idx_created',
					'idx_checkpoint_uuid',
					'idx_resource',
				),
				'primary'       => 'id',
				'single_unique' => 'checkpoint_uuid',
			),
			// Audit log table: forensic mutation history.
			self::get_audit_log_table()   => array(
				'columns'       => array(
					'id',
					'timestamp',
					'event',
					'ability',
					'object_type',
					'object_id',
					'user_id',
					'credential_uuid',
					'ip_address',
					'args_sanitized',
					'result_status',
					'change_id',
					'execution_time_ms',
				),
				'indexes'       => array(
					'idx_timestamp',
					'idx_event',
					'idx_ability',
					'idx_change',
				),
				'primary'       => 'id',
				'single_unique' => 'id',
			),
		);
	}

	/**
	 * Checks if schema upgrade is needed and executes it.
	 *
	 * Uses fast-path comparison to eliminate database overhead on ordinary requests.
	 * Rejects unsupported downgrade states if installed version is higher than code version.
	 *
	 * @return bool True if schema is up to date and verified.
	 */
	public static function maybe_upgrade(): bool {
		$installed_version = get_option( self::OPTION_DB_VERSION, '0.0.0' );

		// Reject unsupported downgrade state: database schema is from a newer future version than this code.
		if ( version_compare( (string) $installed_version, self::DB_VERSION, '>' ) ) {
			return false;
		}

		// Fast path for runtime requests: if version matches, return immediately with zero DB query overhead!
		if ( version_compare( (string) $installed_version, self::DB_VERSION, '=' ) ) {
			return true;
		}

		return self::upgrade( (string) $installed_version );
	}

	/**
	 * Installs or upgrades all 4 required database tables on activation.
	 *
	 * @return bool True if all tables exist and verified.
	 */
	public static function install(): bool {
		return self::upgrade( '0.0.0' );
	}

	/**
	 * Retrieves list of column names for a given table.
	 *
	 * @param string $table Table name.
	 * @return string[] Array of lowercase column names.
	 */
	public static function get_table_columns( string $table ): array {
		global $wpdb;

		// 1. MySQL / MariaDB standard query.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $cols ) && is_array( $cols ) ) {
			return array_values( array_map( 'strtolower', $cols ) );
		}

		// 2. SQLite PRAGMA fallback for test environments.
		$info = $wpdb->get_results( "PRAGMA table_info({$table})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $info ) && is_array( $info ) ) {
			return array_values( array_map( 'strtolower', array_column( $info, 'name' ) ) );
		}

		return array();
	}

	/**
	 * Retrieves list of index names for a given table.
	 *
	 * @param string $table Table name.
	 * @return string[] Array of lowercase index names.
	 */
	public static function get_table_indexes( string $table ): array {
		global $wpdb;

		// 1. MySQL / MariaDB standard query.
		$indices = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $indices ) && is_array( $indices ) ) {
			return array_values( array_unique( array_map( 'strtolower', array_column( $indices, 'Key_name' ) ) ) );
		}

		// 2. SQLite PRAGMA fallback for test environments.
		$sqlite_indices = $wpdb->get_results( "PRAGMA index_list({$table})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $sqlite_indices ) && is_array( $sqlite_indices ) ) {
			$names = array();
			foreach ( $sqlite_indices as $row ) {
				$name = (string) ( $row['name'] ?? '' );
				if ( str_starts_with( $name, $table . '_' ) ) {
					$name = substr( $name, strlen( $table ) + 1 );
				}
				$names[] = strtolower( $name );
			}
			return array_values( array_unique( $names ) );
		}

		return array();
	}

	/**
	 * Verifies that a table possesses a single-column uniqueness guarantee on the specified column.
	 *
	 * Specifically ensures the column either serves as a single-column PRIMARY KEY or has a single-column
	 * UNIQUE index. Composite keys (e.g. PRIMARY KEY (token_key, owner_id)) and non-unique indexes
	 * are strictly rejected.
	 *
	 * @param string $table  Table name.
	 * @param string $column Expected unique column name.
	 * @return bool True if a single-column unique/primary constraint exists on the column.
	 */
	public static function verify_single_column_unique_constraint( string $table, string $column ): bool {
		global $wpdb;

		$col_lower = strtolower( $column );

		// 1. MySQL / MariaDB standard SHOW INDEX inspection.
		$indices = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $indices ) && is_array( $indices ) ) {
			$grouped_indexes = array();
			foreach ( $indices as $row ) {
				$key_name = (string) ( $row['Key_name'] ?? '' );
				if ( ! isset( $grouped_indexes[ $key_name ] ) ) {
					$grouped_indexes[ $key_name ] = array(
						'non_unique' => (int) ( $row['Non_unique'] ?? 1 ),
						'columns'    => array(),
					);
				}
				$col_name = strtolower( (string) ( $row['Column_name'] ?? '' ) );
				if ( '' !== $col_name ) {
					$grouped_indexes[ $key_name ]['columns'][] = $col_name;
				}
			}

			foreach ( $grouped_indexes as $key_name => $info ) {
				// Must be unique: Non_unique == 0 (includes PRIMARY and UNIQUE indexes).
				if ( 0 === $info['non_unique'] ) {
					// Must be strictly single-column matching our target column.
					if ( 1 === count( $info['columns'] ) && $info['columns'][0] === $col_lower ) {
						return true;
					}
				}
			}
		}

		// 2. SQLite PRAGMA inspection fallback for test harnesses.
		$info = $wpdb->get_results( "PRAGMA table_info({$table})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $info ) && is_array( $info ) ) {
			$pk_cols = array();
			foreach ( $info as $col ) {
				if ( ! empty( $col['pk'] ) ) {
					$pk_cols[] = strtolower( (string) ( $col['name'] ?? '' ) );
				}
			}
			if ( 1 === count( $pk_cols ) && $pk_cols[0] === $col_lower ) {
				return true;
			}
		}

		$idx_list = $wpdb->get_results( "PRAGMA index_list('{$table}')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $idx_list ) && is_array( $idx_list ) ) {
			foreach ( $idx_list as $idx ) {
				if ( ! empty( $idx['unique'] ) ) {
					$idx_name = (string) ( $idx['name'] ?? '' );
					$idx_cols = $wpdb->get_results( "PRAGMA index_info('{$idx_name}')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( ! empty( $idx_cols ) && 1 === count( $idx_cols ) ) {
						if ( strtolower( (string) ( $idx_cols[0]['name'] ?? '' ) ) === $col_lower ) {
							return true;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Verifies that a table possesses a PRIMARY KEY constraint (or unique constraint on expected column).
	 *
	 * @param string $table        Table name.
	 * @param string $expected_col Expected column name (e.g. 'token_key' or 'id').
	 * @return bool True if primary key constraint exists on expected column.
	 */
	public static function verify_table_has_primary_key( string $table, string $expected_col = '' ): bool {
		global $wpdb;

		// 1. MySQL / MariaDB standard index inspection.
		$indices = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $indices ) && is_array( $indices ) ) {
			foreach ( $indices as $row ) {
				$key_name = strtolower( (string) ( $row['Key_name'] ?? '' ) );
				if ( 'primary' === $key_name ) {
					if ( '' === $expected_col || strtolower( (string) ( $row['Column_name'] ?? '' ) ) === strtolower( $expected_col ) ) {
						return true;
					}
				}
			}
		}

		// 2. SQLite PRAGMA table_info fallback (inspects pk column flag).
		$info = $wpdb->get_results( "PRAGMA table_info({$table})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $info ) && is_array( $info ) ) {
			foreach ( $info as $col ) {
				if ( ! empty( $col['pk'] ) ) {
					if ( '' === $expected_col || strtolower( (string) ( $col['name'] ?? '' ) ) === strtolower( $expected_col ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Verifies that all required tables, critical columns, critical indexes, and PRIMARY constraints exist.
	 *
	 * Ensures migration was completely successful before updating DB_VERSION option.
	 *
	 * @return bool True if all tables, critical columns, indexes, and primary constraints exist.
	 */
	public static function verify_schema(): bool {
		// 1. Check table existence.
		if ( ! self::verify_tables_exist() ) {
			return false;
		}

		$expected_schema = self::get_expected_schema();

		foreach ( $expected_schema as $table => $spec ) {
			// 2. Verify all required columns.
			$actual_cols = self::get_table_columns( $table );
			if ( empty( $actual_cols ) ) {
				return false;
			}
			foreach ( $spec['columns'] as $col ) {
				if ( ! in_array( strtolower( $col ), $actual_cols, true ) ) {
					return false;
				}
			}

			// 3. Verify all required indexes.
			if ( ! empty( $spec['indexes'] ) ) {
				$actual_indexes = self::get_table_indexes( $table );
				if ( empty( $actual_indexes ) ) {
					return false;
				}
				foreach ( $spec['indexes'] as $idx ) {
					if ( ! in_array( strtolower( $idx ), $actual_indexes, true ) ) {
						return false;
					}
				}
			}

			// 4. Verify primary key constraint.
			if ( ! empty( $spec['primary'] ) ) {
				if ( ! self::verify_table_has_primary_key( $table, $spec['primary'] ) ) {
					return false;
				}
			}

			// 5. Verify single-column unique constraint.
			if ( ! empty( $spec['single_unique'] ) ) {
				if ( ! self::verify_single_column_unique_constraint( $table, $spec['single_unique'] ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Checks if all 4 tables exist in the current database.
	 *
	 * @return bool True if all tables exist.
	 */
	public static function verify_tables_exist(): bool {
		global $wpdb;

		$tables = array(
			self::get_journal_table(),
			self::get_checkpoints_table(),
			self::get_audit_log_table(),
			self::get_tokens_table(),
		);

		foreach ( $tables as $table ) {
			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drops all 4 safety tables. Only used on clean uninstall.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$tables = array(
			self::get_journal_table(),
			self::get_checkpoints_table(),
			self::get_audit_log_table(),
			self::get_tokens_table(),
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table};" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		delete_option( self::OPTION_DB_VERSION );
	}
}
