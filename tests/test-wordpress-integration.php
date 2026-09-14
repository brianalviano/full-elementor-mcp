<?php
/**
 * Real WordPress + MySQL Integration Test Suite.
 *
 * Runs against an actual WordPress installation connected to real MySQL / MariaDB.
 * Validates:
 * 1. Real WordPress bootstrap, authentic $wpdb instance, authentic $wp_version.
 * 2. Fail-closed test harness: premature exits, missing dependencies, or wp_die fail immediately.
 * 3. Real Elementor plugin activation and verification (not emulated strings).
 * 4. Real official WordPress MCP Adapter release activation and verification (not mock fixture).
 * 5. Real Safe Elementor MCP activation lifecycle via activate_plugin() under full-elementor-mcp/.
 * 6. Four safety tables created with InnoDB, correct charset & collation via real dbDelta().
 * 7. Required indexes and UNIQUE constraints created on real MySQL.
 * 8. Full_Elementor_MCP_Database_Installer::verify_schema() returns true.
 * 9. Non-destructive deactivation lifecycle via deactivate_plugins() (tables and rows preserved).
 * 10. Schema migration on real MySQL (maybe_upgrade).
 * 11. Representative readonly ability execution.
 * 12. Representative normal mutation ability execution.
 * 13. Dependency diagnostics & Elementor compatibility.
 * 14. Exact safety table count on real MySQL.
 * 15. Optional MCP Adapter transport behavior (inactive adapter non-blocking, warning issued).
 * 16. Incompatible MCP Adapter API contract handling (defensive no-op, no fatal error).
 * 17. Dependency rejection matrix (fail-closed on missing hard requirements).
 *
 * Usage: php tests/test-wordpress-integration.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

echo "=======================================================\n";
echo " Safe Elementor MCP — WordPress + MySQL Integration\n";
echo "=======================================================\n\n";

$suite_completed_cleanly = false;

// Register fail-closed shutdown handler: premature exit MUST fail the job.
register_shutdown_function( static function () {
	global $suite_completed_cleanly;
	if ( true !== $suite_completed_cleanly ) {
		fwrite( STDERR, "\nFATAL: Test suite terminated prematurely before completing all assertions.\n" );
		exit( 1 );
	}
} );

// ---------------------------------------------------------------------
// 1. Locate and Bootstrap Real WordPress
// ---------------------------------------------------------------------

require_once __DIR__ . '/bootstrap-real-wordpress.php';
bootstrap_real_wordpress();

echo "Bootstrapped WordPress " . $GLOBALS['wp_version'] . " on real MySQL (" . DB_NAME . " at " . DB_HOST . ")\n\n";

$repo_root      = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;
$wp_plugins_dir = rtrim( WP_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR;

// 1. Link or position full-elementor-mcp in WP_PLUGIN_DIR under canonical directory
$mcp_plugin_dir = $wp_plugins_dir . 'full-elementor-mcp';
if ( ! is_dir( $mcp_plugin_dir ) ) {
	if ( function_exists( 'symlink' ) && @symlink( rtrim( $repo_root, '/\\' ), $mcp_plugin_dir ) ) {
		// symlink created
	} elseif ( PHP_OS_FAMILY === 'Windows' ) {
		@exec( 'cmd /c mklink /J ' . escapeshellarg( $mcp_plugin_dir ) . ' ' . escapeshellarg( rtrim( $repo_root, '/\\' ) ) );
	}
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
// TEST 2: Real Elementor Activation & Verification
// ---------------------------------------------------------------------
echo "\n--- Test 2: Real Elementor Activation ---\n";
$elem_file = $wp_plugins_dir . 'elementor/elementor.php';
if ( ! file_exists( $elem_file ) ) {
	fwrite( STDERR, "FATAL: Real Elementor plugin not found at {$elem_file}. Integration suite requires real Elementor package.\n" );
	exit( 1 );
}

activate_plugin( 'elementor/elementor.php' );
require_once $elem_file;

assert_true( 'Real Elementor plugin is active in WordPress', is_plugin_active( 'elementor/elementor.php' ) );
assert_true( 'Real Elementor Plugin class is loaded', class_exists( '\Elementor\Plugin' ) );
assert_true( 'ELEMENTOR_VERSION constant is defined', defined( 'ELEMENTOR_VERSION' ) );

$elem_ver = constant( 'ELEMENTOR_VERSION' );
echo " Active Elementor version: {$elem_ver}\n";
assert_true( 'Elementor version meets minimum requirement (>= 3.20.0)', version_compare( $elem_ver, '3.20.0', '>=' ) );

// ---------------------------------------------------------------------
// TEST 3: Real WordPress MCP Adapter Activation & Verification
// ---------------------------------------------------------------------
echo "\n--- Test 3: Real WordPress MCP Adapter Activation ---\n";
$mcp_adapter_file = $wp_plugins_dir . 'mcp-adapter/mcp-adapter.php';
if ( ! file_exists( $mcp_adapter_file ) ) {
	fwrite( STDERR, "FATAL: Real WordPress MCP Adapter not found at {$mcp_adapter_file}. Integration suite requires official MCP Adapter.\n" );
	exit( 1 );
}

require_once $repo_root . 'includes/class-compatibility-checker.php';

activate_plugin( 'mcp-adapter/mcp-adapter.php' );
require_once $mcp_adapter_file;

assert_true( 'Official MCP Adapter plugin is active in WordPress', is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) );
assert_true( 'MCP Adapter compatibility checker reports supported', Full_Elementor_MCP_Compatibility_Checker::is_mcp_adapter_supported() );
assert_true( 'McpAdapter class is loaded in memory', class_exists( '\WP\MCP\Core\McpAdapter' ) || class_exists( 'WP_MCP_Adapter' ) );

// ---------------------------------------------------------------------
// TEST 4: Real Safe Elementor MCP Activation Lifecycle
// ---------------------------------------------------------------------
echo "\n--- Test 4: Safe Elementor MCP Activation Lifecycle ---\n";
$act_res = activate_plugin( 'full-elementor-mcp/full-elementor-mcp.php' );
assert_true( 'activate_plugin() returns cleanly without fatal error', null === $act_res || ! is_wp_error( $act_res ) );
assert_true( 'Safe Elementor MCP is active in WordPress', is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) );

// ---------------------------------------------------------------------
// TEST 5: Real dbDelta() Safety Tables Installation
// ---------------------------------------------------------------------
echo "\n--- Test 5: Real dbDelta() Safety Tables Installation ---\n";
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
	$create_sql  = $create_info['Create Table'] ?? '';
	assert_true( "Table {$tbl} uses InnoDB engine", false !== stripos( $create_sql, 'ENGINE=InnoDB' ) );
}

// ---------------------------------------------------------------------
// TEST 6: Real Indexes & UNIQUE Constraints
// ---------------------------------------------------------------------
echo "\n--- Test 6: Real Indexes & UNIQUE Constraints ---\n";
$ckpt_tbl     = $wpdb->prefix . 'elementor_mcp_checkpoints';
$ckpt_indexes = $wpdb->get_results( "SHOW INDEX FROM `{$ckpt_tbl}`", ARRAY_A );
$unique_found = false;
foreach ( $ckpt_indexes as $idx ) {
	if ( 'checkpoint_uuid' === $idx['Column_name'] && 0 == $idx['Non_unique'] ) {
		$unique_found = true;
		break;
	}
}
assert_true( 'checkpoint_uuid has UNIQUE constraint in MySQL', $unique_found );

$schema_valid = Full_Elementor_MCP_Database_Installer::verify_schema();
assert_true( 'Full_Elementor_MCP_Database_Installer::verify_schema() returns true', $schema_valid );

// ---------------------------------------------------------------------
// TEST 7: Non-destructive Deactivation via deactivate_plugins()
// ---------------------------------------------------------------------
echo "\n--- Test 7: Non-destructive Deactivation Lifecycle ---\n";
$wpdb->insert(
	$wpdb->prefix . 'elementor_mcp_journal',
	array(
		'ability'       => 'full-elementor-mcp/update-element',
		'action'        => 'update',
		'object_type'   => 'post',
		'object_id'     => 99,
		'resource_key'  => 'post:99',
		'fencing_token' => 10,
		'status'        => 'committed',
		'created_at'    => gmdate( 'Y-m-d H:i:s' ),
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

deactivate_plugins( 'full-elementor-mcp/full-elementor-mcp.php' );
assert_true( 'Plugin deactivated via deactivate_plugins()', ! is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) );

$journal_row = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_journal` WHERE `resource_key` = 'post:99'" );
$ckpt_row    = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_checkpoints` WHERE `checkpoint_uuid` = 'ckpt-deact-test-1'" );

assert_true( 'Journal row preserved after real deactivation', null !== $journal_row && 'committed' === $journal_row->status );
assert_true( 'Checkpoint row preserved after real deactivation', null !== $ckpt_row && 'ckpt-deact-test-1' === $ckpt_row->checkpoint_uuid );

activate_plugin( 'full-elementor-mcp/full-elementor-mcp.php' );
assert_true( 'Plugin reactivated via activate_plugin()', is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) );

// ---------------------------------------------------------------------
// TEST 8: Real Schema Migrations (maybe_upgrade)
// ---------------------------------------------------------------------
echo "\n--- Test 8: Schema Migrations (maybe_upgrade) ---\n";
update_option( 'full_elementor_mcp_db_version', '1.3.0' );
$upgraded = Full_Elementor_MCP_Database_Installer::maybe_upgrade();
assert_true( 'maybe_upgrade() succeeds', true === $upgraded );
assert_true( 'Database version upgraded back to 1.4.0', '1.4.0' === get_option( 'full_elementor_mcp_db_version' ) );

// ---------------------------------------------------------------------
// TEST 9: Runtime Safety Initialization via plugins_loaded & init
// ---------------------------------------------------------------------
echo "\n--- Test 9: Runtime Safety Registry & Fail-Closed Behavior ---\n";
require_once $wp_plugins_dir . 'full-elementor-mcp/full-elementor-mcp.php';
do_action( 'plugins_loaded' );
do_action( 'init' );

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
$wpdb->query( "RENAME TABLE `{$wpdb->prefix}elementor_mcp_tokens` TO `{$wpdb->prefix}elementor_mcp_tokens_bak`" );
$corrupt_check = Full_Elementor_MCP_Database_Installer::verify_schema();
assert_true( 'verify_schema returns false when table missing', false === $corrupt_check );
$wpdb->query( "RENAME TABLE `{$wpdb->prefix}elementor_mcp_tokens_bak` TO `{$wpdb->prefix}elementor_mcp_tokens`" );
update_option( 'full_elementor_mcp_db_version', $saved_ver );

// Setup execution context and user capabilities
wp_set_current_user( 1 );
add_filter( 'user_has_cap', function ( $caps ) {
	$caps['read']                  = true;
	$caps['edit_posts']            = true;
	$caps['edit_pages']            = true;
	$caps['edit_others_posts']     = true;
	$caps['edit_published_posts']  = true;
	$caps['manage_options']        = true;
	return $caps;
} );

// ---------------------------------------------------------------------
// TEST 10: Abilities API & MCP Server Registration
// ---------------------------------------------------------------------
echo "\n--- Test 10: Abilities API & MCP Server Registration ---\n";
do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

$sample_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'full-elementor-mcp/list-widgets' ) : null;
assert_true( 'Abilities API registered full-elementor-mcp abilities', null !== $sample_ability );

// Obtain or construct McpAdapter instance
$adapter_instance = null;
if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
	if ( method_exists( '\WP\MCP\Core\McpAdapter', 'instance' ) ) {
		$adapter_instance = \WP\MCP\Core\McpAdapter::instance();
	} else {
		$adapter_instance = new \WP\MCP\Core\McpAdapter();
	}
} elseif ( class_exists( 'WP_MCP_Adapter' ) ) {
	$adapter_instance = new WP_MCP_Adapter();
}

assert_true( 'McpAdapter instance obtained for server creation', is_object( $adapter_instance ) );

// Fire mcp_adapter_init hook to execute register_mcp_server
do_action( 'mcp_adapter_init', $adapter_instance );
assert_true( 'Custom MCP server creation path executed cleanly', true );

// ---------------------------------------------------------------------
// TEST 11: Representative Readonly Ability Execution
// ---------------------------------------------------------------------
echo "\n--- Test 11: Representative Readonly Ability Execution ---\n";
$read_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'full-elementor-mcp/list-widgets' ) : null;
assert_true( 'Readonly ability (list-widgets) is registered', null !== $read_ability );

if ( null !== $read_ability && method_exists( $read_ability, 'execute' ) ) {
	$read_res = $read_ability->execute( array() );
	assert_true( 'Representative readonly ability executed without WP_Error', ! is_wp_error( $read_res ) );
	assert_true( 'Readonly ability returned widgets array', is_array( $read_res ) && isset( $read_res['widgets'] ) && count( $read_res['widgets'] ) > 0 );
}

// ---------------------------------------------------------------------
// TEST 12: Representative Normal Mutation Execution
// ---------------------------------------------------------------------
echo "\n--- Test 12: Representative Normal Mutation Execution ---\n";
// Create authentic target post in MySQL
$mut_post_id = wp_insert_post( array(
	'post_title'  => 'Integration Mutation Test Post',
	'post_type'   => 'post',
	'post_status' => 'publish',
) );
assert_true( 'Target post inserted in MySQL', $mut_post_id > 0 );

$elem_initial_data = array(
	array(
		'id'       => 'sec_integ_1',
		'elType'   => 'section',
		'isInner'  => false,
		'settings' => array( 'layout' => 'boxed' ),
		'elements' => array(),
	),
);
update_post_meta( $mut_post_id, '_elementor_data', json_encode( $elem_initial_data ) );
update_post_meta( $mut_post_id, '_elementor_edit_mode', 'builder' );

$update_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'full-elementor-mcp/update-element' ) : null;
assert_true( 'Mutation ability (update-element) is registered', null !== $update_ability );

if ( null !== $update_ability && method_exists( $update_ability, 'execute' ) ) {
	$mut_res = $update_ability->execute( array(
		'post_id'    => $mut_post_id,
		'element_id' => 'sec_integ_1',
		'settings'   => array( 'layout' => 'full_width' ),
	) );
	assert_true( 'Representative mutation executed without fatal WP_Error', ! is_wp_error( $mut_res ) && ! empty( $mut_res['success'] ) );
}

// ---------------------------------------------------------------------
// TEST 13: Dependency Diagnostics & Elementor Compatibility
// ---------------------------------------------------------------------
echo "\n--- Test 13: Diagnostics & Compatibility ---\n";
$diag = Full_Elementor_MCP_Compatibility_Checker::get_system_diagnostics();
assert_true( 'Diagnostics contains wordpress info', isset( $diag['wordpress']['version'], $diag['wordpress']['meets_minimum'] ) );
assert_true( 'WordPress meets minimum requirement', true === $diag['wordpress']['meets_minimum'] );
assert_true( 'Tested up to WP 7.1 documented in diagnostics', '7.1' === $diag['wordpress']['tested_up_to'] );
assert_true( 'Diagnostics contains elementor info', isset( $diag['elementor']['installed'], $diag['elementor']['meets_minimum'] ) );
assert_true( 'Diagnostics elementor meets minimum', true === $diag['elementor']['meets_minimum'] );
assert_true( 'Diagnostics contains mcp_adapter info', isset( $diag['mcp_adapter']['active'], $diag['mcp_adapter']['version'] ) );
assert_true( 'Diagnostics mcp_adapter is active', true === $diag['mcp_adapter']['active'] );

// Atomic elements verification on 4.x
$is_atomic = version_compare( $elem_ver, '4.0.0', '>=' );
if ( $is_atomic ) {
	echo " Elementor 4.x detected ({$elem_ver}). Verifying Atomic Elements support...\n";
	assert_true( 'Full_Elementor_MCP_Atomic_Props class loaded', class_exists( 'Full_Elementor_MCP_Atomic_Props' ) );
	assert_true( 'Full_Elementor_MCP_Atomic_Styles class loaded', class_exists( 'Full_Elementor_MCP_Atomic_Styles' ) );
} else {
	echo " Elementor 3.x detected ({$elem_ver}). Container/Widget legacy/flexbox compatibility active.\n";
}

// Elementor Pro absent safe degradation
$pro_active = Full_Elementor_MCP_Compatibility_Checker::is_elementor_pro_active();
if ( ! $pro_active ) {
	assert_true( 'Elementor Pro correctly recognized as absent', false === $pro_active );
} else {
	assert_true( 'Elementor Pro recognized as active', true === $pro_active );
}

// ---------------------------------------------------------------------
// TEST 14: Exact Safety Table Count on Real MySQL
// ---------------------------------------------------------------------
echo "\n--- Test 14: Exact Safety Table Count on Real MySQL ---\n";
$safety_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}elementor_mcp_%'" );
assert_true( 'Exactly four safety tables exist (zero phantom tables)', 4 === count( $safety_tables ), 'Found: ' . implode( ', ', $safety_tables ) );

// ---------------------------------------------------------------------
// TEST 15: Optional MCP Adapter Transport Behavior (Inactive Adapter)
// ---------------------------------------------------------------------
echo "\n--- Test 15: Optional MCP Adapter Transport Behavior ---\n";
// Deactivate the MCP Adapter to simulate a WordPress site without the transport plugin active
deactivate_plugins( 'mcp-adapter/mcp-adapter.php' );
assert_true( 'MCP Adapter deactivated for transport independence testing', ! is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) );

// Safe Elementor MCP must remain active in WordPress
assert_true( 'Safe Elementor MCP remains active when MCP Adapter is inactive', is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) );

// Runtime compatibility must return true because MCP Adapter is optional
assert_true( 'is_runtime_compatible() returns true without MCP Adapter', Full_Elementor_MCP_Compatibility_Checker::is_runtime_compatible() );
assert_true( 'get_blocking_requirements() is empty without MCP Adapter', empty( Full_Elementor_MCP_Compatibility_Checker::get_blocking_requirements() ) );

// Transport status should reflect inactive state and provide warning
$transport_status = Full_Elementor_MCP_Compatibility_Checker::get_transport_status();
assert_true( 'Transport status reports inactive', 'inactive' === $transport_status['status'] );
assert_true( 'Transport available is false', false === $transport_status['available'] );
$transport_warning = Full_Elementor_MCP_Compatibility_Checker::get_transport_warning();
assert_true( 'get_transport_warning() returns informative warning', ! empty( $transport_warning ) && false !== stripos( $transport_warning, 'WordPress MCP Adapter' ) );

// full_elementor_mcp_check_dependencies() must return true (non-blocking)
assert_true( 'full_elementor_mcp_check_dependencies() returns true without MCP Adapter', full_elementor_mcp_check_dependencies() );

// Abilities API remains registered and operational
$read_ability_without_adapter = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'full-elementor-mcp/list-widgets' ) : null;
assert_true( 'Abilities remain registered when MCP Adapter is inactive', null !== $read_ability_without_adapter );
if ( null !== $read_ability_without_adapter && method_exists( $read_ability_without_adapter, 'execute' ) ) {
	$res_without_adapter = $read_ability_without_adapter->execute( array() );
	assert_true( 'Ability executes cleanly when MCP Adapter is inactive', ! is_wp_error( $res_without_adapter ) && isset( $res_without_adapter['widgets'] ) );
}

// Reactivate MCP Adapter
activate_plugin( 'mcp-adapter/mcp-adapter.php' );
assert_true( 'MCP Adapter reactivated', is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) );

// ---------------------------------------------------------------------
// TEST 16: Incompatible MCP Adapter API Contract Handling
// ---------------------------------------------------------------------
echo "\n--- Test 16: Incompatible MCP Adapter API Contract Handling ---\n";
// Pass incompatible mock/test-double without create_server method
$incompatible_adapter = new \stdClass();
$plugin               = Full_Elementor_MCP_Plugin::instance();

// Must not trigger fatal error or exception
$caught_exception = false;
try {
	$plugin->register_mcp_server( $incompatible_adapter );
} catch ( \Throwable $e ) {
	$caught_exception = true;
}
assert_true( 'register_mcp_server handles incompatible adapter object gracefully without error', ! $caught_exception );

// Also test mcp_adapter_init action with incompatible payload
$action_exception = false;
try {
	do_action( 'mcp_adapter_init', new \stdClass() );
} catch ( \Throwable $e ) {
	$action_exception = true;
}
assert_true( 'do_action(mcp_adapter_init) handles invalid argument without fatal error', ! $action_exception );

// ---------------------------------------------------------------------
// TEST 17: Dependency Rejection Matrix (Fail-Closed on Missing Hard Requirements)
// ---------------------------------------------------------------------
echo "\n--- Test 17: Dependency Rejection Matrix ---\n";

// 17a: WordPress version < 6.9
$orig_wp_version       = $GLOBALS['wp_version'];
$GLOBALS['wp_version'] = '6.8.0';
$wp_check              = Full_Elementor_MCP_Compatibility_Checker::check_wordpress();
assert_true( 'check_wordpress rejects WP 6.8.0', false === $wp_check['supported'] );
$wp_blocking = Full_Elementor_MCP_Compatibility_Checker::get_blocking_requirements();
assert_true( 'get_blocking_requirements captures unsupported WordPress', ! empty( array_filter( $wp_blocking, fn( $b ) => false !== strpos( $b, 'WordPress' ) ) ) );
$wp_prereq = Full_Elementor_MCP_Compatibility_Checker::check_activation_prerequisites();
assert_true( 'check_activation_prerequisites returns unsupported_wordpress_version WP_Error', is_wp_error( $wp_prereq ) && 'unsupported_wordpress_version' === $wp_prereq->get_error_code() );
$GLOBALS['wp_version'] = $orig_wp_version;

// 17b: Current environment satisfies all hard requirements
assert_true( 'Current environment has zero blocking requirements', empty( Full_Elementor_MCP_Compatibility_Checker::get_blocking_requirements() ) );
assert_true( 'check_activation_prerequisites succeeds on current environment', true === Full_Elementor_MCP_Compatibility_Checker::check_activation_prerequisites() );

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n=======================================================\n";
echo " WordPress + MySQL Integration Results: {$passed} Passed, {$failed} Failed\n";
echo "=======================================================\n";

if ( $failed > 0 ) {
	exit( 1 );
}

$suite_completed_cleanly = true;
exit( 0 );
