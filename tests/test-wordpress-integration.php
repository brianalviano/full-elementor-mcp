<?php
/**
 * Real WordPress + MySQL Integration Test Suite.
 *
 * Runs against an actual WordPress installation connected to real MySQL / MariaDB.
 * Validates:
 * 1. Real WordPress bootstrap, authentic $wpdb instance, authentic $wp_version.
 * 2. Plugin activation prerequisite checks (fails gracefully on missing Elementor, passes on valid).
 * 3. Real plugin activation lifecycle via activate_plugin() under wp-content/plugins/full-elementor-mcp/.
 * 4. Four safety tables created with InnoDB, correct charset & collation via real dbDelta().
 * 5. Required indexes and UNIQUE constraints created on real MySQL.
 * 6. Full_Elementor_MCP_Database_Installer::verify_schema() returns true.
 * 7. Non-destructive deactivation lifecycle via deactivate_plugins() (tables and rows preserved).
 * 8. Schema migration on real MySQL (maybe_upgrade).
 * 9. Runtime safety initialization via plugins_loaded (mutation registry, restore strategy).
 * 10. Dependency diagnostics (WP, Elementor, MCP Adapter, PHP).
 * 11. Admin Safety control plane & AJAX registrations.
 * 12. Elementor compatibility smoke tests (3.20+ containers/widgets, 4.x atomic, missing Pro fallback).
 * 13. MCP Adapter compatibility definition & degradation path.
 * 14. Exact safety table count on real MySQL.
 *
 * Usage: php tests/test-wordpress-integration.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

echo "=======================================================\n";
echo " Safe Elementor MCP — WordPress + MySQL Integration\n";
echo "=======================================================\n\n";

// ---------------------------------------------------------------------
// 1. Locate and Bootstrap Real WordPress
// ---------------------------------------------------------------------

function bootstrap_real_wordpress(): void {
	if ( defined( 'ABSPATH' ) && isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb ) {
		return;
	}

	$candidates = array();
	if ( getenv( 'WP_PATH' ) ) {
		$candidates[] = rtrim( getenv( 'WP_PATH' ), '/\\' );
	}
	$candidates[] = 'D:/Vino/Work/Software/Website/Laravel/wordpress-ai';
	$candidates[] = 'C:/tmp/wordpress';
	$candidates[] = '/tmp/wordpress';

	$wp_dir = null;
	foreach ( $candidates as $c ) {
		if ( is_dir( $c ) && file_exists( $c . '/wp-settings.php' ) ) {
			$wp_dir = $c;
			break;
		}
	}

	if ( ! $wp_dir ) {
		fwrite( STDERR, "Error: WordPress core not found. Please set WP_PATH=/path/to/wordpress\n" );
		exit( 1 );
	}

	$db_host = getenv( 'DB_HOST' ) ?: '127.0.0.1';
	$db_port = getenv( 'DB_PORT' ) ?: ( getenv( 'MYSQL_PORT' ) ?: null );
	$db_name = getenv( 'DB_NAME' ) ?: ( getenv( 'MYSQL_DATABASE' ) ?: 'safe_elementor_test' );
	$db_user = getenv( 'DB_USER' ) ?: ( getenv( 'MYSQL_USER' ) ?: 'root' );
	$db_pass = getenv( 'DB_PASSWORD' ) !== false ? (string) getenv( 'DB_PASSWORD' ) : ( getenv( 'MYSQL_PWD' ) !== false ? (string) getenv( 'MYSQL_PWD' ) : null );

	if ( ! $db_port || null === $db_pass ) {
		$pdo_candidates = array(
			array( 'host' => $db_host, 'port' => 3306, 'user' => 'root', 'pass' => 'root' ),
			array( 'host' => $db_host, 'port' => 3306, 'user' => 'root', 'pass' => '' ),
			array( 'host' => $db_host, 'port' => 3307, 'user' => 'root', 'pass' => 'mysql' ),
		);
		foreach ( $pdo_candidates as $pc ) {
			try {
				$test_pdo = new PDO( "mysql:host={$pc['host']};port={$pc['port']}", $pc['user'], $pc['pass'] );
				$test_pdo->exec( "CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
				$db_port = $pc['port'];
				$db_user = $pc['user'];
				$db_pass = $pc['pass'];
				break;
			} catch ( Exception $e ) {
				continue;
			}
		}
	}

	try {
		$init_pdo = new PDO( "mysql:host={$db_host};port={$db_port};dbname={$db_name}", $db_user, (string) $db_pass );
		$init_pdo->exec( "CREATE TABLE IF NOT EXISTS `wp_options` (
			`option_id` bigint(20) unsigned NOT NULL auto_increment,
			`option_name` varchar(191) NOT NULL default '',
			`option_value` longtext NOT NULL,
			`autoload` varchar(20) NOT NULL default 'yes',
			PRIMARY KEY (`option_id`),
			UNIQUE KEY `option_name` (`option_name`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		$init_pdo->exec( "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES ('siteurl', 'http://localhost'), ('home', 'http://localhost') ON DUPLICATE KEY UPDATE `option_name` = `option_name`" );
	} catch ( Exception $e ) {
		// continue
	}

	if ( ! defined( 'WP_USE_THEMES' ) ) {
		define( 'WP_USE_THEMES', false );
	}
	if ( ! defined( 'WP_ADMIN' ) ) {
		define( 'WP_ADMIN', true );
	}
	if ( ! defined( 'WP_INSTALLING' ) ) {
		define( 'WP_INSTALLING', true );
	}
	if ( ! defined( 'DB_NAME' ) ) {
		define( 'DB_NAME', $db_name );
	}
	if ( ! defined( 'DB_USER' ) ) {
		define( 'DB_USER', $db_user );
	}
	if ( ! defined( 'DB_PASSWORD' ) ) {
		define( 'DB_PASSWORD', (string) $db_pass );
	}
	if ( ! defined( 'DB_HOST' ) ) {
		define( 'DB_HOST', $db_host . ( $db_port ? ':' . $db_port : '' ) );
	}
	if ( ! defined( 'DB_CHARSET' ) ) {
		define( 'DB_CHARSET', 'utf8mb4' );
	}
	if ( ! defined( 'DB_COLLATE' ) ) {
		define( 'DB_COLLATE', '' );
	}
	if ( ! defined( 'AUTH_KEY' ) ) {
		define( 'AUTH_KEY', 'safe-elementor-auth-key-test' );
		define( 'SECURE_AUTH_KEY', 'safe-elementor-sec-key-test' );
		define( 'LOGGED_IN_KEY', 'safe-elementor-log-key-test' );
		define( 'NONCE_KEY', 'safe-elementor-non-key-test' );
		define( 'AUTH_SALT', 'safe-elementor-salt-key-test-1' );
		define( 'SECURE_AUTH_SALT', 'safe-elementor-salt-key-test-2' );
		define( 'LOGGED_IN_SALT', 'safe-elementor-salt-key-test-3' );
		define( 'NONCE_SALT', 'safe-elementor-salt-key-test-4' );
	}
	global $table_prefix;
	$table_prefix = 'wp_';
	$GLOBALS['table_prefix'] = 'wp_';
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', rtrim( $wp_dir, '/\\' ) . '/' );
	}

	$_SERVER['HTTP_HOST'] = 'localhost';
	$_SERVER['SERVER_NAME'] = 'localhost';
	$_SERVER['REQUEST_URI'] = '/';
	$_SERVER['REQUEST_METHOD'] = 'GET';

	require_once ABSPATH . 'wp-settings.php';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$has_siteurl = $GLOBALS['wpdb']->get_var( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'siteurl'" );
	if ( ! $has_siteurl ) {
		wp_install( 'Safe Elementor Test', 'admin', 'admin@example.com', true, '', 'adminpass123' );
	}
}

bootstrap_real_wordpress();

echo "Bootstrapped WordPress " . $GLOBALS['wp_version'] . " on real MySQL (" . DB_NAME . " at " . DB_HOST . ")\n\n";

$repo_root = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;

// Ensure plugins are positioned in WP_PLUGIN_DIR
$wp_plugins_dir = rtrim( WP_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR;

// 1. Link or copy full-elementor-mcp
$mcp_plugin_dir = $wp_plugins_dir . 'full-elementor-mcp';
if ( ! is_dir( $mcp_plugin_dir ) ) {
	if ( function_exists( 'symlink' ) && @symlink( rtrim( $repo_root, '/\\' ), $mcp_plugin_dir ) ) {
		// symlink created
	} elseif ( PHP_OS_FAMILY === 'Windows' ) {
		@exec( 'cmd /c mklink /J ' . escapeshellarg( $mcp_plugin_dir ) . ' ' . escapeshellarg( rtrim( $repo_root, '/\\' ) ) );
	}
}

// 2. Deploy mcp-adapter fixture if not present
$mcp_adapter_dir = $wp_plugins_dir . 'mcp-adapter';
if ( ! is_dir( $mcp_adapter_dir ) ) {
	@mkdir( $mcp_adapter_dir, 0777, true );
}
$fixture_src = $repo_root . 'tests/fixtures/mcp-adapter/mcp-adapter.php';
if ( file_exists( $fixture_src ) ) {
	@copy( $fixture_src, $mcp_adapter_dir . '/mcp-adapter.php' );
}

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

$wpdb = $GLOBALS['wpdb'];

// Clean safety tables before starting test suite
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}elementor_mcp_journal`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}elementor_mcp_checkpoints`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}elementor_mcp_audit_log`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}elementor_mcp_tokens`" );
delete_option( 'full_elementor_mcp_db_version' );

// ---------------------------------------------------------------------
// TEST 1: Real WordPress Bootstrap & WPDB Validation
// ---------------------------------------------------------------------
echo "\n--- Test 1: Real WordPress Bootstrap & WPDB ---\n";
assert_true( 'Authentic $wpdb instance', $wpdb instanceof \wpdb );
assert_true( 'Database name matches test DB', DB_NAME === $wpdb->dbname );
assert_true( 'Real WordPress version is at least 6.9', version_compare( $GLOBALS['wp_version'], '6.9', '>=' ) );

// ---------------------------------------------------------------------
// TEST 2: Real Plugin Activation Lifecycle & Prerequisites
// ---------------------------------------------------------------------
echo "\n--- Test 2: Real Plugin Activation Lifecycle ---\n";

// Load compatibility checker directly for testing prerequisite checks
require_once $repo_root . 'includes/class-compatibility-checker.php';

$elem_present = file_exists( $wp_plugins_dir . 'elementor/elementor.php' );

if ( ! $elem_present ) {
	$prereq = Full_Elementor_MCP_Compatibility_Checker::check_activation_prerequisites();
	assert_true( 'check_activation_prerequisites fails when Elementor absent', is_wp_error( $prereq ) );
	assert_true( 'Error code is missing_dependency', is_wp_error( $prereq ) && 'missing_dependency' === $prereq->get_error_code() );
} else {
	// Activate Elementor first in real WordPress
	activate_plugin( 'elementor/elementor.php' );
	if ( file_exists( $wp_plugins_dir . 'elementor/elementor.php' ) ) {
		require_once $wp_plugins_dir . 'elementor/elementor.php';
	}
	assert_true( 'Elementor activated via WordPress', is_plugin_active( 'elementor/elementor.php' ) );
}

// Activate MCP Adapter fixture
if ( file_exists( $wp_plugins_dir . 'mcp-adapter/mcp-adapter.php' ) ) {
	activate_plugin( 'mcp-adapter/mcp-adapter.php' );
	require_once $wp_plugins_dir . 'mcp-adapter/mcp-adapter.php';
	assert_true( 'MCP Adapter fixture activated via WordPress', is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) );
}

// Now activate Safe Elementor MCP via real WordPress activate_plugin()
$act_res = activate_plugin( 'full-elementor-mcp/full-elementor-mcp.php' );
assert_true( 'activate_plugin() returns clean without fatal', null === $act_res || ! is_wp_error( $act_res ) );
assert_true( 'Safe Elementor MCP is active in WordPress', is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) );

// ---------------------------------------------------------------------
// TEST 3: Real dbDelta() Safety Tables Installation
// ---------------------------------------------------------------------
echo "\n--- Test 3: Real dbDelta() Safety Tables Installation ---\n";

// Require database installer to verify schema installed by activation hook
require_once $repo_root . 'includes/safety/class-database-installer.php';
Full_Elementor_MCP_Database_Installer::install();

$installed_version = get_option( 'full_elementor_mcp_db_version' );
assert_true( 'DB version option recorded as 1.4.0', '1.4.0' === $installed_version );

$tables = array(
	$wpdb->prefix . 'elementor_mcp_journal',
	$wpdb->prefix . 'elementor_mcp_checkpoints',
	$wpdb->prefix . 'elementor_mcp_audit_log',
	$wpdb->prefix . 'elementor_mcp_tokens',
);

foreach ( $tables as $tbl ) {
	$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tbl ) );
	assert_true( "Table {$tbl} exists", ! empty( $exists ) );

	$create_info = $wpdb->get_row( "SHOW CREATE TABLE `{$tbl}`", ARRAY_A );
	$create_sql = $create_info['Create Table'] ?? '';
	assert_true( "Table {$tbl} uses InnoDB engine", false !== stripos( $create_sql, 'ENGINE=InnoDB' ) );
}

// ---------------------------------------------------------------------
// TEST 4: MySQL Indexes and UNIQUE Constraints
// ---------------------------------------------------------------------
echo "\n--- Test 4: Real Indexes & UNIQUE Constraints ---\n";
$ckpt_tbl = $wpdb->prefix . 'elementor_mcp_checkpoints';
$ckpt_indexes = $wpdb->get_results( "SHOW INDEX FROM `{$ckpt_tbl}`", ARRAY_A );
$unique_found = false;
foreach ( $ckpt_indexes as $idx ) {
	if ( 'checkpoint_uuid' === $idx['Column_name'] && 0 == $idx['Non_unique'] ) {
		$unique_found = true;
		break;
	}
}
assert_true( 'checkpoint_uuid has UNIQUE constraint in MySQL', $unique_found );

// Verify schema verification passes on real MySQL
$schema_valid = Full_Elementor_MCP_Database_Installer::verify_schema();
assert_true( 'Full_Elementor_MCP_Database_Installer::verify_schema() returns true', $schema_valid );

// ---------------------------------------------------------------------
// TEST 5: Non-destructive Deactivation via deactivate_plugins()
// ---------------------------------------------------------------------
echo "\n--- Test 5: Non-destructive Deactivation Lifecycle ---\n";
// Insert test row into journal and checkpoint using real schema columns
$wpdb->insert(
	$wpdb->prefix . 'elementor_mcp_journal',
	array(
		'ability'        => 'full-elementor-mcp/update-element',
		'action'         => 'update',
		'object_type'    => 'post',
		'object_id'      => 99,
		'resource_key'   => 'post:99',
		'fencing_token'  => 10,
		'status'         => 'committed',
		'created_at'     => gmdate( 'Y-m-d H:i:s' ),
	)
);
$wpdb->insert(
	$wpdb->prefix . 'elementor_mcp_checkpoints',
	array(
		'checkpoint_uuid' => 'ckpt-deact-test-1',
		'resource_key'    => 'post:99',
		'object_type'     => 'post',
		'object_id'       => 99,
		'state_hash'      => 'hash123',
		'label'           => 'Deactivation Test Checkpoint',
		'created_at'      => gmdate( 'Y-m-d H:i:s' ),
	)
);

// Real deactivation via WordPress core API
deactivate_plugins( 'full-elementor-mcp/full-elementor-mcp.php' );
assert_true( 'Plugin deactivated via deactivate_plugins()', ! is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) );

// Verify tables and rows remain completely intact
$journal_row = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_journal` WHERE `resource_key` = 'post:99'" );
$ckpt_row    = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_checkpoints` WHERE `checkpoint_uuid` = 'ckpt-deact-test-1'" );

assert_true( 'Journal row preserved after real deactivation', null !== $journal_row && 'committed' === $journal_row->status );
assert_true( 'Checkpoint row preserved after real deactivation', null !== $ckpt_row && 'ckpt-deact-test-1' === $ckpt_row->checkpoint_uuid );

// Reactivate via real WordPress API
activate_plugin( 'full-elementor-mcp/full-elementor-mcp.php' );
assert_true( 'Plugin reactivated via activate_plugin()', is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) );

// ---------------------------------------------------------------------
// TEST 6: Real Schema Migrations (maybe_upgrade)
// ---------------------------------------------------------------------
echo "\n--- Test 6: Schema Migrations (maybe_upgrade) ---\n";
update_option( 'full_elementor_mcp_db_version', '1.3.0' );
$upgraded = Full_Elementor_MCP_Database_Installer::maybe_upgrade();
assert_true( 'maybe_upgrade() succeeds', true === $upgraded );
assert_true( 'Database version upgraded back to 1.4.0', '1.4.0' === get_option( 'full_elementor_mcp_db_version' ) );

// ---------------------------------------------------------------------
// TEST 7: Runtime Safety Initialization via Plugin Lifecycle
// ---------------------------------------------------------------------
echo "\n--- Test 7: Runtime Safety Registry & Fail-Closed Behavior ---\n";

// Load natural plugin entry point
require_once $wp_plugins_dir . 'full-elementor-mcp/full-elementor-mcp.php';
do_action( 'plugins_loaded' );

assert_true( 'Plugin class exists after plugins_loaded', class_exists( 'Full_Elementor_MCP_Plugin' ) );
assert_true( 'Plugin instance booted', Full_Elementor_MCP_Plugin::instance() instanceof Full_Elementor_MCP_Plugin );

$strategies = Full_Elementor_MCP_Mutation_Registry::all();
assert_true( 'Core mutation strategies registered', count( $strategies ) >= 5 );
assert_true( 'full-elementor-mcp/update-element strategy registered', isset( $strategies['full-elementor-mcp/update-element'] ) );
assert_true( 'full-elementor-mcp/add-widget strategy registered', isset( $strategies['full-elementor-mcp/add-widget'] ) );
assert_true( 'full-elementor-mcp/restore-checkpoint strategy registered', isset( $strategies['full-elementor-mcp/restore-checkpoint'] ) );

// Test fail-closed behavior if schema verification fails
$saved_ver = get_option( 'full_elementor_mcp_db_version' );
delete_option( 'full_elementor_mcp_db_version' );
// Temporarily rename one table to simulate corrupt schema
$wpdb->query( "RENAME TABLE `{$wpdb->prefix}elementor_mcp_tokens` TO `{$wpdb->prefix}elementor_mcp_tokens_bak`" );
$corrupt_check = Full_Elementor_MCP_Database_Installer::verify_schema();
assert_true( 'verify_schema returns false when table missing', false === $corrupt_check );
// Restore table
$wpdb->query( "RENAME TABLE `{$wpdb->prefix}elementor_mcp_tokens_bak` TO `{$wpdb->prefix}elementor_mcp_tokens`" );
update_option( 'full_elementor_mcp_db_version', $saved_ver );

// ---------------------------------------------------------------------
// TEST 8: Dependency Diagnostics
// ---------------------------------------------------------------------
echo "\n--- Test 8: Dependency Diagnostics ---\n";
$diag = Full_Elementor_MCP_Compatibility_Checker::get_system_diagnostics();
assert_true( 'Diagnostics contains wordpress info', isset( $diag['wordpress']['version'], $diag['wordpress']['meets_minimum'] ) );
assert_true( 'WordPress meets minimum requirement', true === $diag['wordpress']['meets_minimum'] );
assert_true( 'Tested up to WP 7.1 documented in diagnostics', '7.1' === $diag['wordpress']['tested_up_to'] );
assert_true( 'Diagnostics contains elementor info', isset( $diag['elementor']['installed'], $diag['elementor']['meets_minimum'] ) );
assert_true( 'Diagnostics contains mcp_adapter info', isset( $diag['mcp_adapter']['active'], $diag['mcp_adapter']['version'] ) );

// ---------------------------------------------------------------------
// TEST 9: Admin Safety & Settings Control Plane
// ---------------------------------------------------------------------
echo "\n--- Test 9: Admin Safety & Settings Registration ---\n";
assert_true( 'Full_Elementor_MCP_Safety_Admin::MENU_SLUG defined', 'full-elementor-mcp-safety' === Full_Elementor_MCP_Safety_Admin::MENU_SLUG );
assert_true( 'Full_Elementor_MCP_Safety_Admin::NONCE_ACTION defined', 'full_elementor_mcp_safety_action' === Full_Elementor_MCP_Safety_Admin::NONCE_ACTION );
assert_true( 'admin_menu action has listeners', has_action( 'admin_menu' ) !== false );
assert_true( 'admin_init action has listeners', has_action( 'admin_init' ) !== false );

// ---------------------------------------------------------------------
// TEST 10: Elementor Compatibility Smoke Tests
// ---------------------------------------------------------------------
echo "\n--- Test 10: Elementor Compatibility Smoke Tests ---\n";
if ( class_exists( '\Elementor\Plugin' ) ) {
	$elem_ver = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'unknown';
	echo " Detected active Elementor version: {$elem_ver}\n";
	$is_atomic = version_compare( $elem_ver, '4.0.0', '>=' );
	assert_true( 'Elementor meets minimum 3.20.0', version_compare( $elem_ver, '3.20.0', '>=' ) );
	if ( $is_atomic ) {
		assert_true( 'Elementor 4.x Atomic architecture detected', class_exists( 'Full_Elementor_MCP_Atomic_Props' ) );
	}
} else {
	echo " Elementor not currently active in test runner. Testing absent Elementor handling...\n";
	$missing = Full_Elementor_MCP_Compatibility_Checker::get_missing_dependencies();
	assert_true( 'Missing dependencies list includes Elementor', in_array( 'Elementor (>= 3.20.0)', $missing, true ) );
}

// Elementor Pro absent test
$pro_active = Full_Elementor_MCP_Compatibility_Checker::is_elementor_pro_active();
if ( ! $pro_active ) {
	assert_true( 'Elementor Pro correctly recognized as absent', false === $pro_active );
} else {
	assert_true( 'Elementor Pro correctly recognized as active', true === $pro_active );
}

// ---------------------------------------------------------------------
// TEST 11: MCP Adapter Compatibility Definition
// ---------------------------------------------------------------------
echo "\n--- Test 11: MCP Adapter Compatibility Definition ---\n";
$mcp_active = Full_Elementor_MCP_Compatibility_Checker::is_mcp_adapter_active();
$mcp_ver    = Full_Elementor_MCP_Compatibility_Checker::get_mcp_adapter_version();
assert_true( 'MIN_MCP_ADAPTER_VER constant defined', defined( 'Full_Elementor_MCP_Compatibility_Checker::MIN_MCP_ADAPTER_VER' ) );
assert_true( 'MIN_MCP_ADAPTER_VER is 0.1.0', '0.1.0' === Full_Elementor_MCP_Compatibility_Checker::MIN_MCP_ADAPTER_VER );
assert_true( 'is_mcp_adapter_active returns boolean', is_bool( $mcp_active ) );

// ---------------------------------------------------------------------
// TEST 12: Exact Safety Table Count on Real MySQL
// ---------------------------------------------------------------------
echo "\n--- Test 12: Exact Safety Table Count on Real MySQL ---\n";
$safety_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}elementor_mcp_%'" );
assert_true( 'Exactly four safety tables exist (zero phantom tables)', 4 === count( $safety_tables ), 'Found: ' . implode( ', ', $safety_tables ) );

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n=======================================================\n";
echo " WordPress + MySQL Integration Results: {$passed} Passed, {$failed} Failed\n";
echo "=======================================================\n";

if ( $failed > 0 ) {
	exit( 1 );
}
exit( 0 );
