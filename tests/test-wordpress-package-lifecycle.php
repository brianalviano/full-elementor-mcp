<?php
/**
 * Test Suite for Authentic WordPress Package Lifecycle.
 *
 * Validates:
 * 1. Existence and integrity of dist/safe-elementor-mcp-1.8.0.zip and release manifest.
 * 2. Strictly single-root full-elementor-mcp/ directory structure in the ZIP.
 * 3. Authentic WordPress clean installation using user-facing WordPress installation path
 *    (wp plugin install or public Plugin_Upgrader::install()) into real WP_PLUGIN_DIR,
 *    verifying activation, schema, four tables, abilities, and MCP server registration.
 * 4. Authentic frozen Phase 6 baseline (f21858ac9d853e38f3121a594f992bf81725cec8) runtime in
 *    a separate fresh WordPress installation, loading strictly Phase 6 production code,
 *    seeding historical state with authentic Phase 6 Checkpoint_Manager encryption.
 * 5. Authentic WordPress overwrite upgrade using the exact built Phase 7 ZIP via genuine
 *    WordPress update lifecycle (wp plugin install --force or Plugin_Upgrader::install(overwrite)).
 * 6. Phase 7 post-upgrade verification in a fresh process: single full-elementor-mcp/ directory,
 *    unchanged canonical basename, version 1.8.0, schema migration to 1.4.0, exactly 4 tables,
 *    preserved settings, preserved journal, Phase 6 checkpoint decrypted under Phase 7 crypto,
 *    preserved audit log, preserved tokens, and preserved Elementor content.
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
$active_temp_sites       = array();

// Fail-closed: premature termination MUST fail the suite.
register_shutdown_function( static function () {
	global $suite_completed_cleanly, $active_temp_sites;
	foreach ( $active_temp_sites as $site ) {
		destroy_test_wordpress_site( $site );
	}
	if ( true !== $suite_completed_cleanly ) {
		fwrite( STDERR, "\nFATAL: Package lifecycle suite terminated prematurely.\n" );
		exit( 1 );
	}
} );

$repo_root = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;
$dist_dir  = $repo_root . 'dist' . DIRECTORY_SEPARATOR;
$zip_file  = $dist_dir . 'safe-elementor-mcp-1.8.0.zip';
$manifest  = $dist_dir . 'manifest.json';

// Build release ZIP once if not present or build script required
if ( ! file_exists( $zip_file ) || ! file_exists( $manifest ) ) {
	echo "Building dist/safe-elementor-mcp-1.8.0.zip...\n";
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $repo_root . 'scripts/build-release.php' ), $b_out, $b_code );
	if ( 0 !== $b_code ) {
		fwrite( STDERR, "FATAL: build-release.php failed with exit code {$b_code}\n" );
		exit( 1 );
	}
}

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
// 1. Locate Base WordPress & Real MySQL Connection
// ---------------------------------------------------------------------

require_once __DIR__ . '/bootstrap-real-wordpress.php';
bootstrap_real_wordpress();

$base_wp_dir = rtrim( ABSPATH, '/\\' );
$worker_file = __DIR__ . DIRECTORY_SEPARATOR . 'worker-lifecycle-runner.php';

/**
 * Creates an isolated authentic real WordPress installation.
 *
 * @param string $site_name Identifier prefix.
 * @return string Absolute directory path of the isolated WordPress site.
 */
function create_test_wordpress_site( string $site_name ): string {
	global $base_wp_dir, $active_temp_sites;

	$temp_site = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $site_name . '_' . uniqid();
	mkdir( $temp_site . '/wp-content/plugins', 0777, true );
	mkdir( $temp_site . '/wp-content/themes', 0777, true );

	// Junction or symlink core directories from base WordPress
	if ( PHP_OS_FAMILY === 'Windows' ) {
		exec( 'cmd /c mklink /J ' . escapeshellarg( $temp_site . '/wp-includes' ) . ' ' . escapeshellarg( $base_wp_dir . '/wp-includes' ) );
		exec( 'cmd /c mklink /J ' . escapeshellarg( $temp_site . '/wp-admin' ) . ' ' . escapeshellarg( $base_wp_dir . '/wp-admin' ) );
		if ( is_dir( $base_wp_dir . '/wp-content/plugins/elementor' ) ) {
			exec( 'cmd /c mklink /J ' . escapeshellarg( $temp_site . '/wp-content/plugins/elementor' ) . ' ' . escapeshellarg( $base_wp_dir . '/wp-content/plugins/elementor' ) );
		}
		if ( is_dir( $base_wp_dir . '/wp-content/plugins/mcp-adapter' ) ) {
			exec( 'cmd /c mklink /J ' . escapeshellarg( $temp_site . '/wp-content/plugins/mcp-adapter' ) . ' ' . escapeshellarg( $base_wp_dir . '/wp-content/plugins/mcp-adapter' ) );
		}
	} else {
		@symlink( $base_wp_dir . '/wp-includes', $temp_site . '/wp-includes' );
		@symlink( $base_wp_dir . '/wp-admin', $temp_site . '/wp-admin' );
		if ( is_dir( $base_wp_dir . '/wp-content/plugins/elementor' ) ) {
			@symlink( $base_wp_dir . '/wp-content/plugins/elementor', $temp_site . '/wp-content/plugins/elementor' );
		}
		if ( is_dir( $base_wp_dir . '/wp-content/plugins/mcp-adapter' ) ) {
			@symlink( $base_wp_dir . '/wp-content/plugins/mcp-adapter', $temp_site . '/wp-content/plugins/mcp-adapter' );
		}
	}

	// Copy root php bootstrap files
	foreach ( glob( $base_wp_dir . '/*.php' ) as $f ) {
		if ( basename( $f ) !== 'wp-config.php' ) {
			copy( $f, $temp_site . '/' . basename( $f ) );
		}
	}

	// Write authentic wp-config.php for this real site
	$cfg = "<?php\n" .
		   "define('DB_NAME', " . var_export( DB_NAME, true ) . ");\n" .
		   "define('DB_USER', " . var_export( DB_USER, true ) . ");\n" .
		   "define('DB_PASSWORD', " . var_export( DB_PASSWORD, true ) . ");\n" .
		   "define('DB_HOST', " . var_export( DB_HOST, true ) . ");\n" .
		   "define('DB_CHARSET', 'utf8mb4');\n" .
		   "define('DB_COLLATE', 'utf8mb4_unicode_ci');\n" .
		   "\$table_prefix = 'wp_';\n" .
		   "define('WP_DEBUG', false);\n" .
		   "define('WP_INSTALLING', true);\n" .
		   "define('WP_ADMIN', true);\n" .
		   "define('WP_USE_THEMES', false);\n" .
		   "require_once __DIR__ . '/wp-settings.php';\n";
	file_put_contents( $temp_site . '/wp-config.php', $cfg );

	$active_temp_sites[] = $temp_site;
	return $temp_site;
}

/**
 * Cleanly unlinks and removes an isolated WordPress site.
 *
 * @param string $site_path Absolute directory path.
 */
function destroy_test_wordpress_site( string $site_path ): void {
	if ( ! is_dir( $site_path ) ) {
		return;
	}

	if ( PHP_OS_FAMILY === 'Windows' ) {
		@exec( 'cmd /c rmdir ' . escapeshellarg( $site_path . '/wp-includes' ) );
		@exec( 'cmd /c rmdir ' . escapeshellarg( $site_path . '/wp-admin' ) );
		@exec( 'cmd /c rmdir ' . escapeshellarg( $site_path . '/wp-content/plugins/elementor' ) );
		@exec( 'cmd /c rmdir ' . escapeshellarg( $site_path . '/wp-content/plugins/mcp-adapter' ) );
		@exec( 'cmd /c rmdir /s /q ' . escapeshellarg( $site_path ) );
	} else {
		@unlink( $site_path . '/wp-includes' );
		@unlink( $site_path . '/wp-admin' );
		@unlink( $site_path . '/wp-content/plugins/elementor' );
		@unlink( $site_path . '/wp-content/plugins/mcp-adapter' );
		@exec( 'rm -rf ' . escapeshellarg( $site_path ) );
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
// TEST 3: Authentic WordPress Clean Installation
// ---------------------------------------------------------------------

run_test( 'Test 3: Authentic WordPress clean installation extracts to full-elementor-mcp/ and activates', function () use ( $zip_file, $worker_file ) {
	$clean_site = create_test_wordpress_site( 'safe_mcp_clean_install' );

	// Check if WP-CLI is available; otherwise run worker executing public Plugin_Upgrader::install()
	$has_wp_cli = false;
	exec( 'wp --version 2>' . ( PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null' ), $wp_out, $wp_exit );
	if ( 0 === $wp_exit ) {
		$has_wp_cli = true;
	}

	if ( $has_wp_cli ) {
		$cmd = 'wp plugin install ' . escapeshellarg( $zip_file ) . ' --activate --path=' . escapeshellarg( $clean_site );
		exec( $cmd, $install_out, $install_code );
		assert_equals( 0, $install_code, 'WP-CLI plugin install --activate failed: ' . implode( "\n", $install_out ) );
	} else {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker_file ) .
			   ' --action=clean-install --wp-path=' . escapeshellarg( $clean_site ) .
			   ' --zip-file=' . escapeshellarg( $zip_file );
		exec( $cmd, $install_out, $install_code );
		assert_equals( 0, $install_code, 'Plugin_Upgrader::install failed: ' . implode( "\n", $install_out ) );
	}

	// Verify in fresh child PHP process
	$verify_cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker_file ) .
				  ' --action=verify-clean-install --wp-path=' . escapeshellarg( $clean_site );
	exec( $verify_cmd, $verify_out, $verify_code );
	assert_equals( 0, $verify_code, 'Clean install verification failed: ' . implode( "\n", $verify_out ) );

	$verify_data = json_decode( end( $verify_out ), true );
	assert_true( is_array( $verify_data ) && true === ( $verify_data['success'] ?? false ), 'Clean install verification payload invalid' );
	assert_equals( '1.8.0', $verify_data['version'] ?? '' );
	assert_equals( 4, $verify_data['safety_tables'] ?? 0 );

	destroy_test_wordpress_site( $clean_site );
} );

// ---------------------------------------------------------------------
// TEST 4: Authentic Frozen Phase 6 Runtime & Package Upgrade
// ---------------------------------------------------------------------

run_test( 'Test 4: In-place upgrade from frozen Phase 6 baseline (f21858ac9d853e38f3121a594f992bf81725cec8) preserves data', function () use ( $zip_file, $worker_file ) {
	$upgrade_site  = create_test_wordpress_site( 'safe_mcp_p6_upgrade' );
	$phase6_commit = 'f21858ac9d853e38f3121a594f992bf81725cec8';

	// 1. Export frozen Phase 6 commit directly into WP_PLUGIN_DIR/full-elementor-mcp/
	$p6_install_dir = $upgrade_site . '/wp-content/plugins/full-elementor-mcp';
	mkdir( $p6_install_dir, 0777, true );

	$p6_zip = $upgrade_site . '/p6_baseline.zip';
	exec( "git archive --format=zip {$phase6_commit} --output=" . escapeshellarg( $p6_zip ), $git_out, $git_code );
	assert_equals( 0, $git_code, 'Git archive must export Phase 6 baseline' );

	$zip = new ZipArchive();
	assert_true( true === $zip->open( $p6_zip ), 'Phase 6 zip must open' );
	$zip->extractTo( $p6_install_dir );
	$zip->close();
	unlink( $p6_zip );

	// 2. Process 1: Activate frozen Phase 6 and generate historical state using Phase 6 production code ONLY
	$seed_file = $upgrade_site . '/seed.json';
	$seed_cmd  = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker_file ) .
				 ' --action=seed-phase6 --wp-path=' . escapeshellarg( $upgrade_site ) .
				 ' --seed-file=' . escapeshellarg( $seed_file );
	exec( $seed_cmd, $seed_out, $seed_code );
	assert_equals( 0, $seed_code, 'Phase 6 state seeding failed: ' . implode( "\n", $seed_out ) );

	assert_true( file_exists( $seed_file ), 'Seed data file must exist after seeding' );
	$seed_data = json_decode( (string) file_get_contents( $seed_file ), true );
	assert_true( is_array( $seed_data ) && true === ( $seed_data['success'] ?? false ), 'Seed payload invalid from file' );
	assert_true( ! empty( $seed_data['checkpoint_uuid'] ), 'Phase 6 checkpoint_uuid must be generated' );

	// 3. Process 2: Authentic WordPress overwrite upgrade using the exact built Phase 7 ZIP
	$has_wp_cli = false;
	exec( 'wp --version 2>' . ( PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null' ), $wp_out, $wp_exit );
	if ( 0 === $wp_exit ) {
		$has_wp_cli = true;
	}

	if ( $has_wp_cli ) {
		$up_cmd = 'wp plugin install ' . escapeshellarg( $zip_file ) . ' --force --path=' . escapeshellarg( $upgrade_site );
		exec( $up_cmd, $up_out, $up_code );
		assert_equals( 0, $up_code, 'WP-CLI plugin install --force failed: ' . implode( "\n", $up_out ) );
	} else {
		$up_cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker_file ) .
				  ' --action=upgrade-package --wp-path=' . escapeshellarg( $upgrade_site ) .
				  ' --zip-file=' . escapeshellarg( $zip_file );
		exec( $up_cmd, $up_out, $up_code );
		assert_equals( 0, $up_code, 'Plugin_Upgrader overwrite upgrade failed: ' . implode( "\n", $up_out ) );
	}

	// 4. Process 3: Phase 7 post-upgrade verification in a fresh process
	$verify_up_cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker_file ) .
					 ' --action=verify-upgrade --wp-path=' . escapeshellarg( $upgrade_site ) .
					 ' --seed-file=' . escapeshellarg( $seed_file );
	exec( $verify_up_cmd, $ver_out, $ver_code );
	assert_equals( 0, $ver_code, 'Post-upgrade verification failed: ' . implode( "\n", $ver_out ) );

	$ver_data = json_decode( end( $ver_out ), true );
	assert_true( is_array( $ver_data ) && true === ( $ver_data['success'] ?? false ), 'Post-upgrade verification payload invalid' );
	assert_equals( '1.8.0', $ver_data['version'] ?? '' );
	assert_equals( 4, $ver_data['safety_tables'] ?? 0 );
	assert_true( true === ( $ver_data['decrypted'] ?? false ), 'Phase 6 checkpoint must decrypt under Phase 7 crypto' );

	destroy_test_wordpress_site( $upgrade_site );
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
