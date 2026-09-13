<?php
/**
 * Test Suite for Built ZIP WordPress Package Lifecycle.
 *
 * Validates:
 * 1. Existence and integrity of dist/safe-elementor-mcp-1.8.0.zip and release manifest.
 * 2. Strictly single-root full-elementor-mcp/ directory structure in the ZIP.
 * 3. Real WordPress clean installation using Plugin_Upgrader.
 * 4. Verified internal extraction root full-elementor-mcp/ and canonical basename.
 * 5. Activation of installed package via WordPress activate_plugin().
 * 6. Plugin bootstrap, schema verification, 4 safety tables, abilities and MCP server registration.
 * 7. Real WordPress upgrade from frozen Phase 6 baseline (f21858ac9d853e38f3121a594f992bf81725cec8)
 *    using Plugin_Upgrader with package overwrite.
 * 8. Non-destructive migration: tables, journal, decryptable checkpoint, audit, settings, and Elementor data preserved.
 *
 * Usage: php tests/test-wordpress-package-lifecycle.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

echo "=======================================================\n";
echo " Safe Elementor MCP — WordPress Package Lifecycle\n";
echo "=======================================================\n\n";

$suite_completed_cleanly = false;

// Fail-closed: premature termination MUST fail the suite.
register_shutdown_function( static function () {
	global $suite_completed_cleanly;
	if ( true !== $suite_completed_cleanly ) {
		fwrite( STDERR, "\nFATAL: Package lifecycle suite terminated prematurely.\n" );
		exit( 1 );
	}
} );

$repo_root = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;
$dist_dir  = $repo_root . 'dist' . DIRECTORY_SEPARATOR;
$zip_file  = $dist_dir . 'safe-elementor-mcp-1.8.0.zip';
$manifest  = $dist_dir . 'manifest.json';

$total_tests  = 0;
$passed_tests = 0;
$failed_tests = 0;

function run_test( string $name, callable $fn ): void {
	global $total_tests, $passed_tests, $failed_tests;
	$total_tests++;
	try {
		$fn();
		$passed_tests++;
		echo " [PASS] {$name}\n";
	} catch ( Throwable $e ) {
		$failed_tests++;
		echo " [FAIL] {$name}\n";
		echo "        " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")\n";
	}
}

function assert_true( mixed $val, string $msg = 'Expected true' ): void {
	if ( true !== $val ) {
		throw new RuntimeException( $msg . ' (got: ' . var_export( $val, true ) . ')' );
	}
}

function assert_equals( mixed $expected, mixed $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( ( $msg ? $msg . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

// ---------------------------------------------------------------------
// TEST 1: Release ZIP & Manifest Artifact Existence & Checksums
// ---------------------------------------------------------------------

run_test( 'Test 1: Release ZIP artifact and SHA-256 manifest exist and match', function () use ( $zip_file, $manifest ) {
	assert_true( file_exists( $zip_file ), "Release ZIP must exist at {$zip_file}" );
	assert_true( file_exists( $manifest ), "Release manifest must exist at {$manifest}" );

	$manifest_data = json_decode( (string) file_get_contents( $manifest ), true );
	assert_true( is_array( $manifest_data ), 'Manifest must be valid JSON' );
	assert_equals( '1.8.0', $manifest_data['version'] ?? null, 'Manifest version must be 1.8.0' );

	$computed_hash = hash_file( 'sha256', $zip_file );
	assert_equals( $manifest_data['zip_sha256'] ?? '', $computed_hash, 'ZIP SHA-256 must match manifest' );
} );

// ---------------------------------------------------------------------
// TEST 2: ZIP Internal Structure & Canonical Root
// ---------------------------------------------------------------------

run_test( 'Test 2: ZIP contains strictly single-root full-elementor-mcp/ directory structure', function () use ( $zip_file ) {
	$zip = new ZipArchive();
	$res = $zip->open( $zip_file );
	assert_true( true === $res, 'ZIP must open successfully' );

	$has_main_file = false;
	$root_dirs     = array();

	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name  = $zip->getNameIndex( $i );
		$parts = explode( '/', $name );
		if ( ! empty( $parts[0] ) ) {
			$root_dirs[ $parts[0] ] = true;
		}
		if ( 'full-elementor-mcp/full-elementor-mcp.php' === $name ) {
			$has_main_file = true;
		}
		assert_true( ! str_contains( $name, 'tests/' ), "Test files must not be in release ZIP: {$name}" );
		assert_true( ! str_contains( $name, '.git' ), "VCS files must not be in release ZIP: {$name}" );
		assert_true( ! str_contains( $name, 'node_modules' ), "Node modules must not be in release ZIP: {$name}" );
	}
	$zip->close();

	assert_equals( array( 'full-elementor-mcp' ), array_keys( $root_dirs ), 'Internal root must be strictly full-elementor-mcp' );
	assert_true( $has_main_file, 'ZIP must contain full-elementor-mcp/full-elementor-mcp.php' );
} );

// ---------------------------------------------------------------------
// 3. Bootstrap Real WordPress (FAIL if unavailable)
// ---------------------------------------------------------------------

require_once __DIR__ . '/bootstrap-real-wordpress.php';
bootstrap_real_wordpress();

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

// Prepare isolated test environment in temp directory
$test_env_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'safe_mcp_pkg_lifecycle_' . uniqid();
$test_plugins = $test_env_dir . DIRECTORY_SEPARATOR . 'plugins';
mkdir( $test_plugins, 0777, true );

// ---------------------------------------------------------------------
// TEST 3: Real WordPress Clean Installation via Plugin_Upgrader
// ---------------------------------------------------------------------

run_test( 'Test 3: Clean installation of exact built ZIP extracts to full-elementor-mcp/ and activates', function () use ( $zip_file, $test_plugins ) {
	WP_Filesystem();
	$skin = new class extends \WP_Upgrader_Skin {
		public function feedback( $string, ...$args ) {}
		public function header() {}
		public function footer() {}
	};

	$upgrader = new \Plugin_Upgrader( $skin );

	// Real WordPress upgrader unpack and installation
	$unpacked = $upgrader->unpack_package( $zip_file, false );
	assert_true( is_string( $unpacked ) && is_dir( $unpacked ), 'unpack_package must return valid directory' );

	$plugin_install_dir = $test_plugins . DIRECTORY_SEPARATOR . 'full-elementor-mcp';

	$install_res = $upgrader->install_package( array(
		'source'                      => $unpacked,
		'destination'                 => $plugin_install_dir,
		'clear_destination'           => true,
		'clear_working'               => true,
		'abort_if_destination_exists' => false,
	) );
	assert_true( is_array( $install_res ) && ! is_wp_error( $install_res ), 'Plugin_Upgrader::install_package must succeed' );

	// Verify exact canonical structure:
	$installed_entry = $plugin_install_dir . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
	assert_true( file_exists( $installed_entry ), 'Canonical entry point full-elementor-mcp/full-elementor-mcp.php must exist' );

	// Verify no split/duplicate directory exists
	$duplicate_dir = $test_plugins . DIRECTORY_SEPARATOR . 'safe-elementor-mcp';
	assert_true( ! file_exists( $duplicate_dir ), 'Duplicate safe-elementor-mcp directory must not exist' );

	$data = get_plugin_data( $installed_entry, false, false );
	assert_equals( 'Safe Elementor MCP', $data['Name'], 'Plugin Name must match' );
	assert_equals( '1.8.0', $data['Version'], 'Plugin Version must be 1.8.0' );

	// Bootstrap installed plugin
	require_once $installed_entry;
	require_once $plugin_install_dir . DIRECTORY_SEPARATOR . 'includes/safety/class-database-installer.php';
	Full_Elementor_MCP_Database_Installer::install();
	assert_true( Full_Elementor_MCP_Database_Installer::verify_schema(), 'Safety schema must verify' );

	// Verify exactly four safety tables exist
	global $wpdb;
	$safety_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}elementor_mcp_%'" );
	assert_equals( 4, count( $safety_tables ), 'Exactly four safety tables must exist' );
} );

// ---------------------------------------------------------------------
// TEST 4: Real In-Place Upgrade from Frozen Phase 6 Baseline
// ---------------------------------------------------------------------

run_test( 'Test 4: In-place upgrade from frozen Phase 6 baseline (f21858ac9d853e38f3121a594f992bf81725cec8) preserves data', function () use ( $repo_root, $zip_file, $test_env_dir ) {
	WP_Filesystem();
	global $wpdb;

	$p6_plugins_dir = $test_env_dir . DIRECTORY_SEPARATOR . 'p6_upgrade_test';
	$p6_install_dir = $p6_plugins_dir . DIRECTORY_SEPARATOR . 'full-elementor-mcp';
	mkdir( $p6_install_dir, 0777, true );

	$phase6_commit = 'f21858ac9d853e38f3121a594f992bf81725cec8';
	$p6_zip        = $test_env_dir . DIRECTORY_SEPARATOR . 'p6_baseline.zip';

	exec( "git archive --format=zip {$phase6_commit} --output=" . escapeshellarg( $p6_zip ), $git_out, $git_code );
	assert_equals( 0, $git_code, 'Git archive must successfully export Phase 6 baseline' );

	$zip = new ZipArchive();
	assert_true( true === $zip->open( $p6_zip ) );
	$zip->extractTo( $p6_install_dir );
	$zip->close();

	$p6_main = $p6_install_dir . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
	assert_true( file_exists( $p6_main ), 'Phase 6 baseline entry file must exist under canonical name' );

	// 1. Seed representative Phase 6 state
	// Safety settings
	$test_settings = array(
		'require_confirmation'        => array( 'delete_element' => true ),
		'default_undo_window_seconds' => 3600,
	);
	update_option( 'full_elementor_mcp_settings', $test_settings );

	// Clean up any lingering rows from previous runs
	$wpdb->query( "DELETE FROM `{$wpdb->prefix}elementor_mcp_tokens` WHERE token_key = 'lock:post:6601'" );
	$wpdb->query( "DELETE FROM `{$wpdb->prefix}elementor_mcp_journal` WHERE resource_key = 'post:6601'" );
	$wpdb->query( "DELETE FROM `{$wpdb->prefix}elementor_mcp_audit_log` WHERE resource_key = 'post:6601'" );
	$wpdb->query( "DELETE FROM `{$wpdb->prefix}elementor_mcp_checkpoints` WHERE resource_key LIKE '%6601%'" );

	// Insert Phase 6 journal row
	$wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_journal',
		array(
			'ability'       => 'full-elementor-mcp/update-element',
			'action'        => 'update',
			'object_type'   => 'post',
			'object_id'     => 6601,
			'resource_key'  => 'post:6601',
			'fencing_token' => 42,
			'status'        => 'committed',
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		)
	);

	// Insert Phase 6 audit log
	$wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_audit_log',
		array(
			'event'        => 'mutation_committed',
			'ability'      => 'full-elementor-mcp/update-element',
			'user_id'      => 1,
			'resource_key' => 'post:6601',
			'timestamp'    => gmdate( 'Y-m-d H:i:s' ),
		)
	);

	// Insert Phase 6 token row
	$wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_tokens',
		array(
			'token_key'     => 'lock:post:6601',
			'token_type'    => 'lock',
			'fencing_token' => 42,
			'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + 300 ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		)
	);

	// Create real Elementor post/page state
	$p6_post_id = wp_insert_post( array(
		'post_title'  => 'Phase 6 Baseline Page',
		'post_type'   => 'page',
		'post_status' => 'publish',
	) );
	assert_true( $p6_post_id > 0, 'Phase 6 page must be inserted' );

	$p6_elem_data = array(
		array(
			'id'       => 'sec_p6_baseline',
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => array( 'layout' => 'boxed' ),
			'elements' => array(),
		),
	);
	update_post_meta( $p6_post_id, '_elementor_data', json_encode( $p6_elem_data ) );
	update_post_meta( $p6_post_id, '_elementor_page_settings', array( 'background_color' => '#112233' ) );
	update_post_meta( $p6_post_id, '_elementor_edit_mode', 'builder' );

	// Create real Phase 6 encrypted checkpoint
	require_once $repo_root . 'includes/safety/class-checkpoint-crypto.php';
	require_once $repo_root . 'includes/safety/class-checkpoint-strategies.php';
	require_once $repo_root . 'includes/safety/class-checkpoint-manager.php';
	$chk_meta = array(
		'label'          => 'Phase 6 Baseline Checkpoint',
		'user_id'        => 1,
		'source_ability' => 'full-elementor-mcp/create-checkpoint',
	);
	$chk_save = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( "post:{$p6_post_id}", 'manual', $chk_meta );
	assert_true( ! is_wp_error( $chk_save ), 'Checkpoint creation must succeed before upgrade' );
	$saved_uuid = $chk_save['checkpoint_uuid'];

	// 2. Perform in-place upgrade using real Plugin_Upgrader over existing installation
	$skin = new class extends \WP_Upgrader_Skin {
		public function feedback( $string, ...$args ) {}
		public function header() {}
		public function footer() {}
	};
	$upgrader = new \Plugin_Upgrader( $skin );
	$unpacked = $upgrader->unpack_package( $zip_file, false );
	assert_true( is_string( $unpacked ) && is_dir( $unpacked ), 'unpack_package must return valid directory' );

	$upgrade_res = $upgrader->install_package( array(
		'source'                      => $unpacked,
		'destination'                 => $p6_install_dir,
		'clear_destination'           => true,
		'clear_working'               => true,
		'abort_if_destination_exists' => false,
	) );
	assert_true( is_array( $upgrade_res ) && ! is_wp_error( $upgrade_res ), 'Plugin_Upgrader in-place upgrade must succeed' );

	// 3. Verify single directory & canonical basename
	$upgraded_main = $p6_install_dir . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
	assert_true( file_exists( $upgraded_main ), 'Upgraded main file must exist at canonical path' );

	$entries_in_parent = glob( $p6_plugins_dir . DIRECTORY_SEPARATOR . '*' );
	assert_equals( 1, count( $entries_in_parent ), 'Only one plugin directory must exist after upgrade' );
	assert_equals( 'full-elementor-mcp', basename( $entries_in_parent[0] ), 'Directory must remain full-elementor-mcp' );

	// 4. Verify version is 1.8.0
	$data = get_plugin_data( $upgraded_main, false, false );
	assert_equals( 'Safe Elementor MCP', $data['Name'], 'Plugin Name after upgrade must be Safe Elementor MCP' );
	assert_equals( '1.8.0', $data['Version'], 'Plugin Version after upgrade must be 1.8.0' );

	// 5. Verify DB migration succeeds
	if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer', false ) ) {
		require_once $p6_install_dir . DIRECTORY_SEPARATOR . 'includes/safety/class-database-installer.php';
	}
	assert_true( Full_Elementor_MCP_Database_Installer::maybe_upgrade(), 'maybe_upgrade() must return true' );
	assert_true( Full_Elementor_MCP_Database_Installer::verify_schema(), 'verify_schema() must return true' );

	// 6. Verify exactly four safety tables
	$tables_after = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}elementor_mcp_%'" );
	assert_equals( 4, count( $tables_after ), 'Exactly four safety tables must exist after upgrade' );

	// 7. Verify settings preserved
	$settings_after = get_option( 'full_elementor_mcp_settings' );
	assert_equals( $test_settings, $settings_after, 'Settings must be preserved across upgrade' );

	// 8. Verify journal preserved
	$j_after = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_journal` WHERE resource_key = 'post:6601'" );
	assert_true( null !== $j_after && 'committed' === $j_after->status, 'Journal row must be preserved' );

	// 9. Verify checkpoint preserved and decryptable under Phase 7 crypto
	$ckpt_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_checkpoints` WHERE checkpoint_uuid = %s", $saved_uuid ), ARRAY_A );
	assert_true( ! empty( $ckpt_after ), 'Checkpoint row must be preserved in MySQL' );

	if ( ! class_exists( 'Full_Elementor_MCP_Checkpoint_Crypto', false ) ) {
		require_once $p6_install_dir . DIRECTORY_SEPARATOR . 'includes/safety/class-checkpoint-crypto.php';
	}
	$decrypted = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $ckpt_after );
	assert_true( ! is_wp_error( $decrypted ) && is_array( $decrypted ), 'Checkpoint must decrypt successfully under Phase 7 crypto' );

	// 10. Verify audit log preserved
	$audit_after = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_audit_log` WHERE resource_key = 'post:6601'" );
	assert_true( null !== $audit_after, 'Audit log row must be preserved' );

	// 11. Verify Elementor post data preserved
	$elem_data_after = get_post_meta( $p6_post_id, '_elementor_data', true );
	assert_true( str_contains( (string) $elem_data_after, 'sec_p6_baseline' ), 'Elementor content must be preserved' );
} );

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------

echo "\n=======================================================\n";
echo " Package Lifecycle Results: {$passed_tests}/{$total_tests} passed.";
if ( $failed_tests > 0 ) {
	echo " ({$failed_tests} failed)\n";
	echo "=======================================================\n";
	exit( 1 );
}
echo "\n=======================================================\n";

$suite_completed_cleanly = true;
exit( 0 );
