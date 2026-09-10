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
	 * Installs or upgrades all 4 required database tables.
	 *
	 * @return bool True if all tables exist or were created.
	 */
	public static function install(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		if ( empty( $charset_collate ) ) {
			$charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		$journal_table     = self::get_journal_table();
		$checkpoints_table = self::get_checkpoints_table();
		$audit_log_table   = self::get_audit_log_table();
		$tokens_table      = self::get_tokens_table();

		// 1. Write-Ahead Journal table.
		$sql_journal = "CREATE TABLE IF NOT EXISTS {$journal_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			ability VARCHAR(100) NOT NULL,
			action VARCHAR(20) NOT NULL,
			object_type VARCHAR(30) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_object_id BIGINT UNSIGNED DEFAULT NULL,
			fencing_token BIGINT UNSIGNED NOT NULL DEFAULT 0,
			before_state LONGTEXT DEFAULT NULL,
			before_hash VARCHAR(64) DEFAULT NULL,
			after_hash VARCHAR(64) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			error_message TEXT DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			credential_uuid VARCHAR(64) DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_object (object_type, object_id),
			KEY idx_created (created_at)
		) {$charset_collate};";

		// 2. Checkpoints table.
		$sql_checkpoints = "CREATE TABLE IF NOT EXISTS {$checkpoints_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			label VARCHAR(255) NOT NULL,
			description TEXT DEFAULT NULL,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'manual',
			file_path VARCHAR(500) NOT NULL,
			file_hash_hmac VARCHAR(64) NOT NULL,
			encryption_algorithm VARCHAR(30) NOT NULL DEFAULT 'none',
			key_version INT UNSIGNED NOT NULL DEFAULT 1,
			key_id VARCHAR(64) DEFAULT NULL,
			size_bytes BIGINT UNSIGNED DEFAULT 0,
			items_count INT UNSIGNED DEFAULT 0,
			elementor_version VARCHAR(20) DEFAULT NULL,
			wp_version VARCHAR(20) DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_created (created_at)
		) {$charset_collate};";

		// 3. Forensic Audit Log table.
		$sql_audit_log = "CREATE TABLE IF NOT EXISTS {$audit_log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			event VARCHAR(50) NOT NULL,
			ability VARCHAR(100) DEFAULT NULL,
			object_type VARCHAR(30) DEFAULT NULL,
			object_id BIGINT UNSIGNED DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			credential_uuid VARCHAR(64) DEFAULT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			args_sanitized LONGTEXT DEFAULT NULL,
			result_status VARCHAR(20) DEFAULT NULL,
			change_id BIGINT UNSIGNED DEFAULT NULL,
			execution_time_ms INT UNSIGNED DEFAULT 0,
			PRIMARY KEY (id),
			KEY idx_timestamp (timestamp),
			KEY idx_event (event),
			KEY idx_ability (ability),
			KEY idx_change (change_id)
		) {$charset_collate};";

		// 4. Tokens, Locks & Idempotency table.
		$sql_tokens = "CREATE TABLE IF NOT EXISTS {$tokens_table} (
			token_key VARCHAR(128) NOT NULL,
			token_type VARCHAR(20) NOT NULL,
			owner_id VARCHAR(64) DEFAULT NULL,
			fencing_token BIGINT UNSIGNED NOT NULL DEFAULT 0,
			payload LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME NOT NULL,
			used TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (token_key),
			KEY idx_type_expires (token_type, expires_at),
			KEY idx_used (used)
		) {$charset_collate};";

		// Execute direct DDL for maximum reliability in REST/CLI environments.
		$wpdb->query( $sql_journal ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql_checkpoints ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql_audit_log ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql_tokens ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );

		return self::verify_tables_exist();
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
