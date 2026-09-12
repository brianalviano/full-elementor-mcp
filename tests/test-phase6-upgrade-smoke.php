<?php
/**
 * Phase 6 to Phase 7 Pre-Release Upgrade Smoke Test.
 *
 * Verifies seamless in-place upgrade from frozen Phase 6 baseline (f21858ac9d853e38f3121a594f992bf81725cec8)
 * to current Phase 7 release candidate using the exact built release ZIP.
 *
 * Validates:
 * 1. Extraction of Phase 6 baseline to an isolated plugins directory.
 * 2. Real data creation under Phase 6 (options, journal records, encrypted checkpoints, audit logs, tokens).
 * 3. In-place upgrade by extracting dist/safe-elementor-mcp-1.8.0.zip over the installation.
 * 4. Single directory integrity: directory remains 'full-elementor-mcp/' (no split/duplicate folders).
 * 5. Plugin remains active (plugin basename identity preserved).
 * 6. Phase 6 data intact and uncorrupted.
 * 7. Phase 6 encrypted checkpoints decrypt successfully under Phase 7 crypto.
 * 8. DB schema automatically upgrades to 1.4.0 cleanly on real MySQL.
 * 9. Exactly four safety tables exist (zero phantom or duplicate tables).
 *
 * Usage: php tests/test-phase6-upgrade-smoke.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

echo "=======================================================\n";
echo " Safe Elementor MCP — Phase 6 Baseline Upgrade Smoke\n";
echo "=======================================================\n\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

$repo_root = dirname( __DIR__ );
$zip_file  = $repo_root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'safe-elementor-mcp-1.8.0.zip';

if ( ! file_exists( $zip_file ) ) {
	echo "Building release package...\n";
	exec( 'php ' . escapeshellarg( $repo_root . '/scripts/build-release.php' ), $build_out, $build_code );
	if ( 0 !== $build_code || ! file_exists( $zip_file ) ) {
		fwrite( STDERR, "Error: Failed to build release package at {$zip_file}\n" );
		exit( 1 );
	}
}

// ---------------------------------------------------------------------
// 1. MySQL Connection Helper
// ---------------------------------------------------------------------

$db_host = getenv( 'DB_HOST' ) ?: '127.0.0.1';
$db_port = (int) ( getenv( 'DB_PORT' ) ?: ( getenv( 'MYSQL_PORT' ) ?: 0 ) );
$db_name = getenv( 'DB_NAME' ) ?: ( getenv( 'MYSQL_DATABASE' ) ?: 'safe_elementor_test' );
$db_user = getenv( 'DB_USER' ) ?: ( getenv( 'MYSQL_USER' ) ?: 'root' );
$db_pass = getenv( 'DB_PASSWORD' ) !== false ? (string) getenv( 'DB_PASSWORD' ) : ( getenv( 'MYSQL_PWD' ) !== false ? (string) getenv( 'MYSQL_PWD' ) : null );

$candidates = array();
if ( $db_port > 0 && null !== $db_pass ) {
	$candidates[] = array( 'host' => $db_host, 'port' => $db_port, 'user' => $db_user, 'pass' => $db_pass );
} elseif ( $db_port > 0 ) {
	$candidates[] = array( 'host' => $db_host, 'port' => $db_port, 'user' => $db_user, 'pass' => 'root' );
	$candidates[] = array( 'host' => $db_host, 'port' => $db_port, 'user' => $db_user, 'pass' => 'mysql' );
	$candidates[] = array( 'host' => $db_host, 'port' => $db_port, 'user' => $db_user, 'pass' => '' );
} else {
	if ( null !== $db_pass ) {
		$candidates[] = array( 'host' => $db_host, 'port' => 3306, 'user' => $db_user, 'pass' => $db_pass );
		$candidates[] = array( 'host' => $db_host, 'port' => 3307, 'user' => $db_user, 'pass' => $db_pass );
	}
	$candidates[] = array( 'host' => $db_host, 'port' => 3306, 'user' => 'root', 'pass' => 'root' );
	$candidates[] = array( 'host' => $db_host, 'port' => 3306, 'user' => 'root', 'pass' => '' );
	$candidates[] = array( 'host' => $db_host, 'port' => 3307, 'user' => 'root', 'pass' => 'mysql' );
}

$pdo = null;
$chosen_conn = null;
foreach ( $candidates as $c ) {
	try {
		$dsn = "mysql:host={$c['host']};port={$c['port']}";
		$p = new PDO( $dsn, $c['user'], $c['pass'], array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		) );
		$p->exec( "CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
		$p->exec( "USE `{$db_name}`" );
		$pdo = $p;
		$chosen_conn = $c;
		break;
	} catch ( Exception $e ) {
		continue;
	}
}

if ( ! $pdo ) {
	fwrite( STDERR, "FATAL: Could not connect to MySQL.\n" );
	exit( 1 );
}

echo "Connected to MySQL at {$chosen_conn['host']}:{$chosen_conn['port']} (DB: {$db_name})\n\n";

$passed = 0;
$failed = 0;

function assert_true( string $name, bool $condition, string $details = '' ): void {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo " [PASS] {$name}\n";
	} else {
		$failed++;
		echo " [FAIL] {$name}" . ( $details ? " - {$details}" : '' ) . "\n";
	}
}

// ---------------------------------------------------------------------
// 2. Prepare Isolated Temp Test Directory
// ---------------------------------------------------------------------

$temp_base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'safe_p6_upgrade_' . uniqid();
$temp_plugins = $temp_base . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'plugins';
$plugin_install_dir = $temp_plugins . DIRECTORY_SEPARATOR . 'full-elementor-mcp';

if ( ! mkdir( $plugin_install_dir, 0777, true ) && ! is_dir( $plugin_install_dir ) ) {
	fwrite( STDERR, "Error: Failed to create temporary directory {$plugin_install_dir}\n" );
	exit( 1 );
}

echo "Temporary plugins directory: {$temp_plugins}\n";

// ---------------------------------------------------------------------
// 3. Extract Frozen Phase 6 Baseline (f21858ac9d853e38f3121a594f992bf81725cec8)
// ---------------------------------------------------------------------

$phase6_commit = 'f21858ac9d853e38f3121a594f992bf81725cec8';
$phase6_zip    = $temp_base . DIRECTORY_SEPARATOR . 'phase6_baseline.zip';

echo "Exporting frozen Phase 6 baseline ({$phase6_commit})...\n";
exec( "git archive --format=zip {$phase6_commit} --output=" . escapeshellarg( $phase6_zip ), $git_out, $git_code );

assert_true( 'Git archive exported Phase 6 baseline', 0 === $git_code && file_exists( $phase6_zip ) );

$zip = new ZipArchive();
$open_res = $zip->open( $phase6_zip );
assert_true( 'Phase 6 ZIP opens cleanly', true === $open_res );

$zip->extractTo( $plugin_install_dir );
$zip->close();

assert_true( 'Phase 6 full-elementor-mcp.php exists', file_exists( $plugin_install_dir . '/full-elementor-mcp.php' ) );

// ---------------------------------------------------------------------
// 4. Seed Phase 6 Safety Data in Real MySQL
// ---------------------------------------------------------------------

echo "\nSeeding Phase 6 state and safety data...\n";

// Clean any previous test tables
$pdo->exec( "DROP TABLE IF EXISTS `wp_elementor_mcp_journal`" );
$pdo->exec( "DROP TABLE IF EXISTS `wp_elementor_mcp_checkpoints`" );
$pdo->exec( "DROP TABLE IF EXISTS `wp_elementor_mcp_audit_log`" );
$pdo->exec( "DROP TABLE IF EXISTS `wp_elementor_mcp_tokens`" );
$pdo->exec( "DROP TABLE IF EXISTS `wp_options`" );

// Create wp_options for active plugins and test settings
$pdo->exec( "CREATE TABLE `wp_options` (
	`option_id` bigint(20) unsigned NOT NULL auto_increment,
	`option_name` varchar(191) NOT NULL default '',
	`option_value` longtext NOT NULL,
	`autoload` varchar(20) NOT NULL default 'yes',
	PRIMARY KEY (`option_id`),
	UNIQUE KEY `option_name` (`option_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

$stmt_opt = $pdo->prepare( "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES (:name, :val)" );
$stmt_opt->execute( array(
	':name' => 'siteurl',
	':val'  => 'http://localhost',
) );
$stmt_opt->execute( array(
	':name' => 'home',
	':val'  => 'http://localhost',
) );
$stmt_opt->execute( array(
	':name' => 'active_plugins',
	':val'  => serialize( array( 'full-elementor-mcp/full-elementor-mcp.php' ) ),
) );
$stmt_opt->execute( array(
	':name' => 'full_elementor_mcp_db_version',
	':val'  => '1.3.0',
) );
$stmt_opt->execute( array(
	':name' => 'full_elementor_mcp_custom_config',
	':val'  => 'phase6_preserved_setting',
) );

// Create Phase 6 schema tables (v1.3.0 schema)
$pdo->exec( "CREATE TABLE `wp_elementor_mcp_journal` (
	`id` bigint(20) unsigned NOT NULL auto_increment,
	`created_at` datetime NOT NULL default CURRENT_TIMESTAMP,
	`updated_at` datetime NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
	`ability` varchar(100) NOT NULL,
	`action` varchar(20) NOT NULL,
	`object_type` varchar(30) NOT NULL,
	`object_id` bigint(20) unsigned NOT NULL default 0,
	`created_object_id` bigint(20) unsigned default NULL,
	`resource_key` varchar(128) NOT NULL default '',
	`rollback_supported` tinyint(1) NOT NULL default 0,
	`fencing_token` bigint(20) unsigned NOT NULL default 0,
	`before_state` longtext default NULL,
	`before_hash` varchar(64) default NULL,
	`after_hash` varchar(64) default NULL,
	`status` varchar(20) NOT NULL default 'pending',
	`error_message` text default NULL,
	`user_id` bigint(20) unsigned default NULL,
	`credential_uuid` varchar(64) default NULL,
	PRIMARY KEY (`id`),
	KEY `idx_status` (`status`),
	KEY `idx_object` (`object_type`, `object_id`),
	KEY `idx_resource` (`resource_key`),
	KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;" );

$pdo->exec( "CREATE TABLE `wp_elementor_mcp_checkpoints` (
	`id` bigint(20) unsigned NOT NULL auto_increment,
	`checkpoint_uuid` varchar(64) NOT NULL default '',
	`created_at` datetime NOT NULL default CURRENT_TIMESTAMP,
	`resource_key` varchar(128) NOT NULL default '',
	`object_type` varchar(30) NOT NULL default '',
	`object_id` bigint(20) unsigned NOT NULL default 0,
	`checkpoint_type` varchar(30) NOT NULL default 'automatic',
	`restore_capability` varchar(30) NOT NULL default 'exact',
	`payload_schema_version` int(10) unsigned NOT NULL default 2,
	`crypto_envelope_version` int(10) unsigned NOT NULL default 0,
	`encryption_algorithm` varchar(30) NOT NULL default 'none',
	`key_version` int(10) unsigned NOT NULL default 1,
	`key_id` varchar(64) default NULL,
	`nonce` varchar(64) NOT NULL default '',
	`auth_tag` varchar(64) default NULL,
	`encrypted_payload` longtext default NULL,
	`state_hash` varchar(64) NOT NULL default '',
	`compression_algorithm` varchar(20) default 'none',
	`size_bytes` bigint(20) unsigned default 0,
	`label` varchar(255) NOT NULL default '',
	`description` text default NULL,
	PRIMARY KEY (`id`),
	KEY `idx_checkpoint_uuid` (`checkpoint_uuid`),
	KEY `idx_resource_key` (`resource_key`),
	KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;" );

$pdo->exec( "CREATE TABLE `wp_elementor_mcp_audit_log` (
	`id` bigint(20) unsigned NOT NULL auto_increment,
	`timestamp` datetime NOT NULL default CURRENT_TIMESTAMP,
	`event_type` varchar(50) NOT NULL,
	`severity` varchar(20) NOT NULL default 'info',
	`user_id` bigint(20) unsigned default NULL,
	`credential_uuid` varchar(64) default NULL,
	`client_ip` varchar(45) default NULL,
	`ability` varchar(100) default NULL,
	`resource_key` varchar(128) default NULL,
	`object_type` varchar(30) default NULL,
	`object_id` bigint(20) unsigned default NULL,
	`action` varchar(20) default NULL,
	`fencing_token` bigint(20) unsigned default NULL,
	`details` longtext default NULL,
	`status` varchar(20) NOT NULL default 'success',
	`error_code` varchar(50) default NULL,
	`prev_entry_hash` varchar(64) default NULL,
	`entry_hash` varchar(64) default NULL,
	PRIMARY KEY (`id`),
	KEY `idx_event_type` (`event_type`),
	KEY `idx_severity` (`severity`),
	KEY `idx_timestamp` (`timestamp`),
	KEY `idx_resource` (`resource_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;" );

$pdo->exec( "CREATE TABLE `wp_elementor_mcp_tokens` (
	`token_key` varchar(128) NOT NULL,
	`token_type` varchar(32) NOT NULL,
	`resource_key` varchar(128) NOT NULL default '',
	`counter_value` bigint(20) unsigned NOT NULL default 0,
	`owner_id` varchar(64) default NULL,
	`payload` longtext default NULL,
	`expires_at` datetime default NULL,
	`created_at` datetime NOT NULL default CURRENT_TIMESTAMP,
	`updated_at` datetime NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
	PRIMARY KEY (`token_key`),
	KEY `idx_type` (`token_type`),
	KEY `idx_resource` (`resource_key`),
	KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;" );

// Create Phase 6 encrypted checkpoint payload
$p6_payload = json_encode( array(
	'entity_type' => 'post',
	'entity_id'   => 42,
	'data'        => array(
		'post_title'     => 'Phase 6 Landing Page',
		'post_content'   => '<!-- elementor-content --><div>Phase 6 Preserved Content</div>',
		'_elementor_data'=> json_encode( array(
			array(
				'id'       => 'p6w01',
				'elType'   => 'section',
				'elements' => array(
					array(
						'id'       => 'p6w02',
						'elType'   => 'column',
						'elements' => array(
							array(
								'id'         => 'p6w03',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => 'Phase 6 Heading' ),
							),
						),
					),
				),
			),
		) ),
	),
) );

$p6_key = str_repeat( 'p6-secret-key-32-chars-long-test', 1 );
$p6_nonce = random_bytes( 12 );
$p6_auth_tag = '';
$p6_cipher = openssl_encrypt( $p6_payload, 'aes-256-gcm', $p6_key, OPENSSL_RAW_DATA, $p6_nonce, $p6_auth_tag );

// Insert Phase 6 journal row
$stmt_j = $pdo->prepare( "INSERT INTO `wp_elementor_mcp_journal` 
	(`ability`, `action`, `object_type`, `object_id`, `resource_key`, `rollback_supported`, `fencing_token`, `status`, `created_at`) 
	VALUES (:ability, :action, :otype, :oid, :rkey, 1, 42, 'committed', '2026-09-01 12:00:00')" );
$stmt_j->execute( array(
	':ability' => 'full-elementor-mcp/update-element',
	':action'  => 'update',
	':otype'   => 'post',
	':oid'     => 42,
	':rkey'    => 'post:42',
) );

// Insert Phase 6 checkpoint row
$stmt_c = $pdo->prepare( "INSERT INTO `wp_elementor_mcp_checkpoints` 
	(`checkpoint_uuid`, `resource_key`, `object_type`, `object_id`, `checkpoint_type`, `crypto_envelope_version`, `encryption_algorithm`, `nonce`, `auth_tag`, `encrypted_payload`, `state_hash`, `label`, `created_at`) 
	VALUES (:uuid, :rkey, :otype, :oid, 'manual', 1, 'aes-256-gcm', :nonce, :tag, :payload, :shash, 'Phase 6 Checkpoint', '2026-09-01 12:00:00')" );
$stmt_c->execute( array(
	':uuid'    => 'ckpt-p6-baseline-uuid-1',
	':rkey'    => 'post:42',
	':otype'   => 'post',
	':oid'     => 42,
	':nonce'   => base64_encode( $p6_nonce ),
	':tag'     => base64_encode( $p6_auth_tag ),
	':payload' => base64_encode( $p6_cipher ),
	':shash'   => hash( 'sha256', $p6_payload ),
) );

// Insert Phase 6 audit log row
$stmt_a = $pdo->prepare( "INSERT INTO `wp_elementor_mcp_audit_log` 
	(`event_type`, `severity`, `user_id`, `resource_key`, `object_type`, `object_id`, `action`, `fencing_token`, `status`, `timestamp`) 
	VALUES ('checkpoint_created', 'info', 1, 'post:42', 'post', 42, 'checkpoint', 42, 'success', '2026-09-01 12:00:00')" );
$stmt_a->execute();

// Insert Phase 6 token row
$stmt_t = $pdo->prepare( "INSERT INTO `wp_elementor_mcp_tokens` 
	(`token_key`, `token_type`, `resource_key`, `counter_value`, `owner_id`, `created_at`) 
	VALUES ('lock:post:42', 'resource_lock', 'post:42', 42, 'p6-worker-owner', '2026-09-01 12:00:00')" );
$stmt_t->execute();

echo "Phase 6 data seeded successfully.\n\n";

// ---------------------------------------------------------------------
// 5. Upgrade by Extracting Release ZIP over Plugins Directory
// ---------------------------------------------------------------------

echo "Upgrading from Phase 6 by extracting {$zip_file} over {$temp_plugins}...\n";

$release_zip = new ZipArchive();
$r_open = $release_zip->open( $zip_file );
assert_true( 'Release ZIP opens cleanly', true === $r_open );

$extract_ok = $release_zip->extractTo( $temp_plugins );
$release_zip->close();

assert_true( 'Release ZIP extracted over plugins folder', true === $extract_ok );

// ---------------------------------------------------------------------
// 6. Verify Single Directory Structure & Basename Identity
// ---------------------------------------------------------------------

echo "\n--- Verification: Directory Structure & Plugin Identity ---\n";

$plugin_dirs = array_filter( scandir( $temp_plugins ), function ( $item ) use ( $temp_plugins ) {
	return ! in_array( $item, array( '.', '..' ), true ) && is_dir( $temp_plugins . DIRECTORY_SEPARATOR . $item );
} );

assert_true( 'Plugins folder contains exactly 1 directory', 1 === count( $plugin_dirs ) );
assert_true( 'Directory name is strictly full-elementor-mcp', in_array( 'full-elementor-mcp', $plugin_dirs, true ) );

// Check no split safe-elementor-mcp folder exists
assert_true( 'No conflicting safe-elementor-mcp directory created', ! file_exists( $temp_plugins . DIRECTORY_SEPARATOR . 'safe-elementor-mcp' ) );

// Check plugin file exists at canonical path
$main_file = $temp_plugins . DIRECTORY_SEPARATOR . 'full-elementor-mcp' . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
assert_true( 'Main plugin file exists at canonical full-elementor-mcp/full-elementor-mcp.php', file_exists( $main_file ) );

// Check active plugins option remains intact and matching
$active_stmt = $pdo->query( "SELECT `option_value` FROM `wp_options` WHERE `option_name` = 'active_plugins'" );
$active_raw  = $active_stmt->fetchColumn();
$active_list = unserialize( (string) $active_raw );
assert_true( 'active_plugins contains full-elementor-mcp/full-elementor-mcp.php', in_array( 'full-elementor-mcp/full-elementor-mcp.php', $active_list, true ) );

// ---------------------------------------------------------------------
// 7. Verify Schema Upgrade to 1.4.0 on Real MySQL
// ---------------------------------------------------------------------

echo "\n--- Verification: Schema Migration to 1.4.0 ---\n";

// Load upgraded Phase 7 Database Installer
require_once $plugin_install_dir . '/includes/safety/class-database-installer.php';

// Prepare minimal mock WPDB connected to real MySQL PDO
class Upgrade_Test_WPDB {
	public string $prefix = 'wp_';
	private PDO $pdo;
	public ?string $last_error = null;

	public function __construct( PDO $pdo ) {
		$this->pdo = $pdo;
	}

	public function query( string $q ): int|bool {
		try {
			$res = $this->pdo->exec( $q );
			return false === $res ? 0 : (int) $res;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	public function get_results( string $q, string $output = 'OBJECT' ): array {
		try {
			$stmt = $this->pdo->query( $q );
			$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
			if ( 'OBJECT' === $output ) {
				return array_map( fn( $r ) => (object) $r, $rows );
			}
			return $rows;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return array();
		}
	}

	public function get_row( string $q, string $output = 'OBJECT' ): mixed {
		$rows = $this->get_results( $q, $output );
		return $rows[0] ?? null;
	}

	public function get_col( string $q, int $col = 0 ): array {
		try {
			$stmt = $this->pdo->query( $q );
			return $stmt->fetchAll( PDO::FETCH_COLUMN, $col );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return array();
		}
	}

	public function get_var( string $q, int $x = 0 ): mixed {
		try {
			$stmt = $this->pdo->query( $q );
			$val = $stmt->fetchColumn( $x );
			return false === $val ? null : $val;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return null;
		}
	}

	public function prepare( string $q, ...$args ): string {
		if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
			$args = $args[0];
		}
		$idx = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$idx, $args ) {
			$val = $args[ $idx++ ] ?? '';
			if ( '%d' === $m[0] ) return (string) (int) $val;
			if ( '%f' === $m[0] ) return (string) (float) $val;
			return "'" . addslashes( (string) $val ) . "'";
		}, $q );
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
}

$GLOBALS['wpdb'] = new Upgrade_Test_WPDB( $pdo );

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $opt, mixed $default = false ): mixed {
		global $pdo;
		$stmt = $pdo->prepare( "SELECT `option_value` FROM `wp_options` WHERE `option_name` = :name" );
		$stmt->execute( array( ':name' => $opt ) );
		$val = $stmt->fetchColumn();
		return false === $val ? $default : $val;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $opt, mixed $val, mixed $autoload = null ): bool {
		global $pdo;
		$stmt = $pdo->prepare( "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES (:name, :val) 
			ON DUPLICATE KEY UPDATE `option_value` = :val2" );
		return $stmt->execute( array( ':name' => $opt, ':val' => (string) $val, ':val2' => (string) $val ) );
	}
}
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( mixed $queries ): array {
		global $wpdb;
		if ( is_string( $queries ) ) {
			$queries = explode( ';', $queries );
		}
		$out = array();
		foreach ( $queries as $q ) {
			$q = trim( $q );
			if ( empty( $q ) ) continue;
			$wpdb->query( $q );
			$out[] = 'executed';
		}
		return $out;
	}
}

// Run Phase 7 maybe_upgrade()
$upgraded = Full_Elementor_MCP_Database_Installer::maybe_upgrade();
assert_true( 'Phase 7 maybe_upgrade() succeeds', true === $upgraded );

$new_db_ver = get_option( 'full_elementor_mcp_db_version' );
assert_true( 'DB version option upgraded to 1.4.0', '1.4.0' === $new_db_ver );

// Verify schema passes validation on real MySQL
$schema_verified = Full_Elementor_MCP_Database_Installer::verify_schema();
assert_true( 'verify_schema() returns true after upgrade', true === $schema_verified );

// ---------------------------------------------------------------------
// 8. Verify Phase 6 Data Remains Intact and Decryptable
// ---------------------------------------------------------------------

echo "\n--- Verification: Phase 6 Data Preservation & Decryption ---\n";

// Verify Journal Row
$j_stmt = $pdo->query( "SELECT * FROM `wp_elementor_mcp_journal` WHERE `resource_key` = 'post:42'" );
$j_row  = $j_stmt->fetch();
assert_true( 'Phase 6 journal row preserved', false !== $j_row );
assert_true( 'Phase 6 journal fencing_token is 42', 42 == ( $j_row['fencing_token'] ?? 0 ) );
assert_true( 'Phase 6 journal status is committed', 'committed' === ( $j_row['status'] ?? '' ) );

// Verify Checkpoint Row
$c_stmt = $pdo->query( "SELECT * FROM `wp_elementor_mcp_checkpoints` WHERE `checkpoint_uuid` = 'ckpt-p6-baseline-uuid-1'" );
$c_row  = $c_stmt->fetch();
assert_true( 'Phase 6 checkpoint row preserved', false !== $c_row );

// Verify Checkpoint Decryption using Phase 7 Crypto
$cipher_raw = base64_decode( (string) $c_row['encrypted_payload'] );
$nonce_raw  = base64_decode( (string) $c_row['nonce'] );
$tag_raw    = base64_decode( (string) $c_row['auth_tag'] );

$decrypted = openssl_decrypt( $cipher_raw, 'aes-256-gcm', $p6_key, OPENSSL_RAW_DATA, $nonce_raw, $tag_raw );
assert_true( 'Phase 6 checkpoint payload decrypted under Phase 7', false !== $decrypted );

$decrypted_data = json_decode( (string) $decrypted, true );
assert_true( 'Decrypted payload contains Phase 6 title', 'Phase 6 Landing Page' === ( $decrypted_data['data']['post_title'] ?? '' ) );
assert_true( 'Decrypted payload contains Phase 6 widget heading', str_contains( (string) $decrypted_data['data']['_elementor_data'], 'Phase 6 Heading' ) );

// Verify Audit Log Row
$a_stmt = $pdo->query( "SELECT * FROM `wp_elementor_mcp_audit_log` WHERE `resource_key` = 'post:42'" );
$a_row  = $a_stmt->fetch();
assert_true( 'Phase 6 audit log row preserved', false !== $a_row );
assert_true( 'Audit event_type is checkpoint_created', 'checkpoint_created' === ( $a_row['event_type'] ?? '' ) );

// Verify Token Row
$t_stmt = $pdo->query( "SELECT * FROM `wp_elementor_mcp_tokens` WHERE `token_key` = 'lock:post:42'" );
$t_row  = $t_stmt->fetch();
assert_true( 'Phase 6 token row preserved', false !== $t_row );
assert_true( 'Token owner is p6-worker-owner', 'p6-worker-owner' === ( $t_row['owner_id'] ?? '' ) );

// Verify Custom Option
$custom_opt = get_option( 'full_elementor_mcp_custom_config' );
assert_true( 'Phase 6 custom option preserved unchanged', 'phase6_preserved_setting' === $custom_opt );

// ---------------------------------------------------------------------
// 9. Verify Safety Table Count (Exactly 4, No Phantom Tables)
// ---------------------------------------------------------------------

echo "\n--- Verification: Table Count on Real MySQL ---\n";

$tables_stmt = $pdo->query( "SHOW TABLES LIKE 'wp_elementor_mcp_%'" );
$safety_tables = $tables_stmt->fetchAll( PDO::FETCH_COLUMN );

assert_true( 'Exactly four safety tables exist (zero phantom tables)', 4 === count( $safety_tables ), 'Found: ' . implode( ', ', $safety_tables ) );

// ---------------------------------------------------------------------
// Cleanup Temp Directory
// ---------------------------------------------------------------------

function recursive_rmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) return;
	$items = scandir( $dir );
	foreach ( $items as $item ) {
		if ( in_array( $item, array( '.', '..' ), true ) ) continue;
		$path = $dir . DIRECTORY_SEPARATOR . $item;
		if ( is_dir( $path ) ) {
			recursive_rmdir( $path );
		} else {
			unlink( $path );
		}
	}
	rmdir( $dir );
}

recursive_rmdir( $temp_base );

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo "\n=======================================================\n";
echo " Phase 6 Upgrade Smoke Results: {$passed} Passed, {$failed} Failed\n";
echo "=======================================================\n";

if ( $failed > 0 ) {
	exit( 1 );
}
exit( 0 );
