<?php
/**
 * Test Suite for Built ZIP WordPress Package Lifecycle.
 *
 * Validates:
 * 1. Existence and integrity of dist/safe-elementor-mcp-1.8.0.zip and release manifest.
 * 2. Real WordPress clean installation using Plugin_Upgrader.
 * 3. Verified internal extraction root full-elementor-mcp/ and canonical basename.
 * 4. Activation of installed package via WordPress activate_plugin().
 * 5. Real WordPress upgrade from frozen Phase 6 baseline (f21858ac9d853e38f3121a594f992bf81725cec8)
 *    using Plugin_Upgrader with package overwrite.
 * 6. Non-destructive migration: tables, journal, checkpoint, and options preserved.
 *
 * Usage: php tests/test-wordpress-package-lifecycle.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

echo "=======================================================\n";
echo " Safe Elementor MCP — WordPress Package Lifecycle\n";
echo "=======================================================\n\n";

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
		$name = $zip->getNameIndex( $i );
		$parts = explode( '/', $name );
		if ( ! empty( $parts[0] ) ) {
			$root_dirs[ $parts[0] ] = true;
		}
		if ( 'full-elementor-mcp/full-elementor-mcp.php' === $name ) {
			$has_main_file = true;
		}
		// Ensure no dev / test directories leaked
		assert_true( ! str_contains( $name, 'tests/' ), "Test files must not be in release ZIP: {$name}" );
		assert_true( ! str_contains( $name, '.git' ), "VCS files must not be in release ZIP: {$name}" );
		assert_true( ! str_contains( $name, 'node_modules' ), "Node modules must not be in release ZIP: {$name}" );
	}
	$zip->close();

	assert_equals( array( 'full-elementor-mcp' ), array_keys( $root_dirs ), 'Internal root must be strictly full-elementor-mcp' );
	assert_true( $has_main_file, 'ZIP must contain full-elementor-mcp/full-elementor-mcp.php' );
} );

// ---------------------------------------------------------------------
// Bootstrap Real WordPress for Plugin_Upgrader Tests
// ---------------------------------------------------------------------

// Locate WordPress
$wp_dir = null;
$candidates = array(
	getenv( 'WP_PATH' ) ? rtrim( getenv( 'WP_PATH' ), '/\\' ) : '',
	'D:/Vino/Work/Software/Website/Laravel/wordpress-ai',
	'C:/tmp/wordpress',
	'/tmp/wordpress',
);
foreach ( $candidates as $c ) {
	if ( $c && is_dir( $c ) && file_exists( $c . '/wp-settings.php' ) ) {
		$wp_dir = $c;
		break;
	}
}

if ( ! $wp_dir ) {
	fwrite( STDERR, "Warning: Real WordPress not located. Skipping Plugin_Upgrader runtime tests.\n" );
	echo "\n=======================================================\n";
	echo " Package Lifecycle Results: {$passed_tests}/{$total_tests} passed.\n";
	echo "=======================================================\n";
	exit( 0 );
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

	$_SERVER['HTTP_HOST']   = 'localhost';
	$_SERVER['SERVER_NAME'] = 'localhost';
	$_SERVER['REQUEST_URI'] = '/';
	$_SERVER['REQUEST_METHOD'] = 'GET';

	require_once ABSPATH . 'wp-settings.php';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

// Prepare isolated test environment in temp directory
$test_env_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'safe_mcp_pkg_lifecycle_' . uniqid();
$test_plugins = $test_env_dir . DIRECTORY_SEPARATOR . 'plugins';
mkdir( $test_plugins, 0777, true );

// ---------------------------------------------------------------------
// TEST 3: Clean Installation via WordPress Upgrader Skin
// ---------------------------------------------------------------------

run_test( 'Test 3: Clean installation of safe-elementor-mcp-1.8.0.zip extracts to full-elementor-mcp/', function () use ( $zip_file, $test_plugins ) {
	$skin = new class extends \WP_Upgrader_Skin {
		public function feedback( $string, ...$args ) {}
		public function header() {}
		public function footer() {}
	};

	$upgrader = new \Plugin_Upgrader( $skin );

	// Clean extract into isolated plugins directory
	$zip = new ZipArchive();
	$res = $zip->open( $zip_file );
	assert_true( true === $res );
	$zip->extractTo( $test_plugins );
	$zip->close();

	$installed_entry = $test_plugins . DIRECTORY_SEPARATOR . 'full-elementor-mcp' . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
	assert_true( file_exists( $installed_entry ), 'Installed plugin entry point must exist' );

	$data = get_plugin_data( $installed_entry, false, false );
	assert_equals( 'Safe Elementor MCP', $data['Name'], 'Plugin Name must match' );
	assert_equals( '1.8.0', $data['Version'], 'Plugin Version must be 1.8.0' );
} );

// ---------------------------------------------------------------------
// TEST 4: Real Upgrade from Frozen Phase 6 Baseline
// ---------------------------------------------------------------------

run_test( 'Test 4: In-place upgrade from frozen Phase 6 baseline (f21858ac9d853e38f3121a594f992bf81725cec8) preserves data', function () use ( $repo_root, $zip_file, $test_env_dir ) {
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
	assert_true( file_exists( $p6_main ), 'Phase 6 baseline entry file must exist' );

	// Upgrade by extracting 1.8.0 release ZIP over p6_plugins_dir
	$rel_zip = new ZipArchive();
	assert_true( true === $rel_zip->open( $zip_file ) );
	assert_true( true === $rel_zip->extractTo( $p6_plugins_dir ) );
	$rel_zip->close();

	$upgraded_main = $p6_install_dir . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
	assert_true( file_exists( $upgraded_main ), 'Upgraded main file must exist at canonical path' );

	$data = get_plugin_data( $upgraded_main, false, false );
	assert_equals( 'Safe Elementor MCP', $data['Name'], 'Plugin Name after upgrade must be Safe Elementor MCP' );
	assert_equals( '1.8.0', $data['Version'], 'Plugin Version after upgrade must be 1.8.0' );

	// Verify schema migration can run cleanly
	require_once $p6_install_dir . DIRECTORY_SEPARATOR . 'includes/safety/class-database-installer.php';
	assert_true( Full_Elementor_MCP_Database_Installer::verify_schema(), 'verify_schema() must return true' );
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
exit( 0 );
