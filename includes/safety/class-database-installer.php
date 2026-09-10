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
	 */
	public const DB_VERSION = '1.0.0';

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
				KEY idx_created (created_at)
			) {$charset_collate};",

			// 2. Checkpoints table.
			"CREATE TABLE {$checkpoints_table} (
				id bigint(20) unsigned NOT NULL auto_increment,
				created_at datetime NOT NULL default CURRENT_TIMESTAMP,
				label varchar(255) NOT NULL,
				description text default NULL,
				trigger_type varchar(20) NOT NULL default 'manual',
				file_path varchar(500) NOT NULL,
				file_hash_hmac varchar(64) NOT NULL,
				encryption_algorithm varchar(30) NOT NULL default 'none',
				key_version int(10) unsigned NOT NULL default 1,
				key_id varchar(64) default NULL,
				size_bytes bigint(20) unsigned default 0,
				items_count int(10) unsigned default 0,
				elementor_version varchar(20) default NULL,
				wp_version varchar(20) default NULL,
				created_by bigint(20) unsigned default NULL,
				PRIMARY KEY  (id),
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
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		// Verify that all 4 required tables and critical columns exist before updating version!
		if ( ! self::verify_schema() ) {
			return false;
		}

		// Only record new version after full schema verification succeeds.
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );

		return true;
	}

	/**
	 * Checks if schema upgrade is needed and executes it.
	 *
	 * Uses fast-path comparison to eliminate database overhead on ordinary requests.
	 *
	 * @return bool True if schema is up to date and verified.
	 */
	public static function maybe_upgrade(): bool {
		$installed_version = get_option( self::OPTION_DB_VERSION, '0.0.0' );

		// Fast path for runtime requests: if version matches, return immediately with zero DB query overhead!
		if ( version_compare( (string) $installed_version, self::DB_VERSION, '>=' ) ) {
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
			return array_values( array_unique( array_map( 'strtolower', array_column( $sqlite_indices, 'name' ) ) ) );
		}

		return array();
	}

	/**
	 * Verifies that all required tables, critical columns, and critical indexes exist.
	 *
	 * Ensures migration was completely successful before updating DB_VERSION option.
	 *
	 * @return bool True if all tables, critical columns, and critical indexes exist.
	 */
	public static function verify_schema(): bool {
		// 1. Check table existence.
		if ( ! self::verify_tables_exist() ) {
			return false;
		}

		// 2. Critical columns per table.
		$critical_columns = array(
			self::get_journal_table()     => array( 'fencing_token', 'before_state', 'before_hash', 'after_hash', 'status' ),
			self::get_checkpoints_table() => array( 'key_version', 'key_id' ),
			self::get_tokens_table()      => array( 'token_key', 'token_type', 'owner_id', 'fencing_token', 'expires_at' ),
		);

		foreach ( $critical_columns as $table => $required_cols ) {
			$actual_cols = self::get_table_columns( $table );
			if ( empty( $actual_cols ) ) {
				return false;
			}
			foreach ( $required_cols as $req_col ) {
				if ( ! in_array( strtolower( $req_col ), $actual_cols, true ) ) {
					return false;
				}
			}
		}

		// 3. Critical indexes per table.
		$critical_indexes = array(
			self::get_journal_table() => array( 'idx_status', 'idx_object' ),
			self::get_tokens_table()  => array( 'idx_type_expires' ),
		);

		foreach ( $critical_indexes as $table => $required_indexes ) {
			$actual_indexes = self::get_table_indexes( $table );
			if ( empty( $actual_indexes ) ) {
				return false;
			}
			foreach ( $required_indexes as $req_index ) {
				if ( ! in_array( strtolower( $req_index ), $actual_indexes, true ) ) {
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
