<?php
/**
 * Test Suite for Authentic WordPress Package Lifecycle with Genuinely Database-Isolated Sites.
 *
 * Validates:
 * 1. Existence and integrity of dist/safe-elementor-mcp-1.8.0.zip and release manifest.
 * 2. Strictly single-root full-elementor-mcp/ directory structure in the ZIP.
 * 3. Authentic WordPress clean installation in a dedicated, database-isolated WordPress site:
 *    - Pre-installation assertions: plugin inactive, no plugin directory, no safety tables, no db option.
 *    - User-facing WordPress installation path (wp plugin install or public Plugin_Upgrader::install()).
 *    - Authentic activation creates schema without manual install() calls.
 *    - Runtime bootstrap: version 1.8.0, 4 tables, Abilities API init & registration, MCP Adapter init & server registration.
 * 4. Authentic frozen Phase 6 baseline (f21858ac9d853e38f3121a594f992bf81725cec8) runtime in a separate,
 *    database-isolated WordPress site, loading strictly Phase 6 code in an isolated process.
 * 5. Historical state generation using actual Phase 6 production APIs, with encrypted checkpoint created
 *    by authentic Phase 6 Checkpoint_Manager.
 * 6. Authentic WordPress overwrite upgrade using the exact built Phase 7 ZIP via genuine WordPress update
 *    lifecycle (wp plugin install --force or Plugin_Upgrader::install(overwrite)).
 * 7. Phase 7 post-upgrade verification in a fresh process: explicit activation return check, no stale active_plugins,
 *    version 1.8.0, migration to 1.4.0, exactly 4 tables, settings survive, journal survives, Phase 6 checkpoint
 *    decrypted under Phase 7 crypto, audit survives, token survives, Elementor content survives for actual post_id,
 *    Abilities API registered, and MCP Adapter server registration succeeds.
 *
 * Usage: php tests/test-wordpress-package-lifecycle.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
	$_SERVER['HTTP_HOST'] = 'localhost';
}
if ( ! isset( $_SERVER['SERVER_NAME'] ) ) {
	$_SERVER['SERVER_NAME'] = 'localhost';
}

echo "=======================================================\n";
echo " Safe Elementor MCP — WordPress Package Lifecycle\n";
echo "=======================================================\n\n";

$suite_completed_cleanly = false;
$active_temp_sites       = array();

// Fail-closed: premature termination MUST fail the suite and drop isolated databases.
register_shutdown_function( static function () {
	global $suite_completed_cleanly, $active_temp_sites;
	foreach ( $active_temp_sites as $site_info ) {
		destroy_test_wordpress_site( $site_info );
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

// Build release ZIP once if not present
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

/**
 * Extracts and decodes the last valid JSON object line from an output array.
 *
 * @param array<int, string> $lines Command output lines.
 * @return array<string, mixed>|null Decoded JSON payload or null.
 */
function extract_last_json( array $lines ): ?array {
	for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
		$trimmed = trim( (string) $lines[ $i ] );
		if ( str_starts_with( $trimmed, '{' ) && str_ends_with( $trimmed, '}' ) ) {
			$decoded = json_decode( $trimmed, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
	}
	return null;
}

// ---------------------------------------------------------------------
// 1. Locate Base WordPress & Real MySQL Connection
// ---------------------------------------------------------------------

require_once __DIR__ . '/bootstrap-real-wordpress.php';
bootstrap_real_wordpress();

$base_wp_dir = rtrim( ABSPATH, '/\\' );
$worker_file = __DIR__ . DIRECTORY_SEPARATOR . 'worker-lifecycle-runner.php';

$db_port = defined( 'DB_PORT' ) ? DB_PORT : 3307;

/**
 * Creates an isolated authentic real WordPress installation backed by its own unique MySQL database.
 *
 * @param string $site_name Identifier prefix.
 * @return array{site_path: string, db_name: string} Information about the isolated WordPress site.
 */
function create_test_wordpress_site( string $site_name ): array {
	global $base_wp_dir, $worker_file, $db_port, $active_temp_sites;

	$db_name   = 'safelc_' . substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 10 );
	$temp_site = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $site_name . '_' . uniqid();
	mkdir( $temp_site . '/wp-content/plugins', 0777, true );
	mkdir( $temp_site . '/wp-content/themes', 0777, true );

	$effective_host = DB_HOST;
	if ( ! str_contains( (string) $effective_host, ':' ) && $db_port ) {
		$effective_host .= ':' . $db_port;
	}

	$pdo_host = DB_HOST;
	$pdo_port = $db_port;
	if ( str_contains( (string) $pdo_host, ':' ) ) {
		list( $h, $p ) = explode( ':', (string) $pdo_host, 2 );
		$pdo_host = $h;
		$pdo_port = (int) $p;
	}

	// 1. Create a genuinely unique, isolated MySQL database for this lifecycle site
	$pdo = new PDO(
		"mysql:host={$pdo_host};port={$pdo_port}",
		DB_USER,
		DB_PASSWORD,
		array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
	);
	$pdo->exec( "CREATE DATABASE `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );

	// 2. Junction or symlink core directories from base WordPress
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

	// 3. Copy root php bootstrap files
	foreach ( glob( $base_wp_dir . '/*.php' ) as $f ) {
		if ( basename( $f ) !== 'wp-config.php' ) {
			copy( $f, $temp_site . '/' . basename( $f ) );
		}
	}

	// 4. Write authentic wp-config.php bound strictly to the isolated database
	$cfg = "<?php\n" .
		   "if ( ! isset( \$_SERVER['HTTP_HOST'] ) ) { \$_SERVER['HTTP_HOST'] = 'localhost'; }\n" .
		   "if ( ! isset( \$_SERVER['SERVER_NAME'] ) ) { \$_SERVER['SERVER_NAME'] = 'localhost'; }\n" .
		   "define('DB_NAME', " . var_export( $db_name, true ) . ");\n" .
		   "define('DB_USER', " . var_export( DB_USER, true ) . ");\n" .
		   "define('DB_PASSWORD', " . var_export( DB_PASSWORD, true ) . ");\n" .
		   "define('DB_HOST', " . var_export( $effective_host, true ) . ");\n" .
		   "define('DB_CHARSET', 'utf8mb4');\n" .
		   "define('DB_COLLATE', 'utf8mb4_unicode_ci');\n" .
		   "\$table_prefix = 'wp_';\n" .
		   "define('WP_DEBUG', false);\n" .
		   "define('WP_ADMIN', true);\n" .
		   "define('WP_USE_THEMES', false);\n" .
		   "require_once __DIR__ . '/wp-settings.php';\n";
	file_put_contents( $temp_site . '/wp-config.php', $cfg );

	// 5. Initialize fresh WordPress core schema and activate Elementor + MCP Adapter
	$init_cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker_file ) .
				' --action=init-site --wp-path=' . escapeshellarg( $temp_site );
	exec( $init_cmd, $init_out, $init_code );
	if ( 0 !== $init_code ) {
		fwrite( STDERR, "FATAL: Failed to initialize isolated WordPress site schema: " . implode( "\n", $init_out ) . "\n" );
		exit( 1 );
	}

	$site_info           = array(
		'site_path' => $temp_site,
		'db_name'   => $db_name,
	);
	$active_temp_sites[] = $site_info;
	return $site_info;
}

/**
 * Cleanly unlinks junctions/symlinks, removes directory, and drops the isolated database.
 *
 * @param array{site_path: string, db_name: string} $site_info Site details.
 */
function destroy_test_wordpress_site( array $site_info ): void {
	global $db_port, $active_temp_sites;

	$site_path = $site_info['site_path'] ?? '';
	$db_name   = $site_info['db_name'] ?? '';

	// Remove from $active_temp_sites
	foreach ( $active_temp_sites as $idx => $s ) {
		if ( ( $s['db_name'] ?? '' ) === $db_name ) {
			unset( $active_temp_sites[ $idx ] );
		}
	}

	// Drop isolated MySQL database
	if ( $db_name ) {
		try {
			$pdo_host = DB_HOST;
			$pdo_port = $db_port;
			if ( str_contains( (string) $pdo_host, ':' ) ) {
				list( $h, $p ) = explode( ':', (string) $pdo_host, 2 );
				$pdo_host = $h;
				$pdo_port = (int) $p;
			}
			$pdo = new PDO(
				"mysql:host={$pdo_host};port={$pdo_port}",
				DB_USER,
				DB_PASSWORD,
				array( PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT )
			);
			$pdo->exec( "DROP DATABASE IF EXISTS `{$db_name}`" );
		} catch ( Exception $e ) {
			// ignore on cleanup
		}
	}

	// Remove filesystem files
	if ( $site_path && is_dir( $site_path ) ) {
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
// TEST 3: Authentic WordPress Clean Installation (Database-Isolated)
// ---------------------------------------------------------------------

run_test( 'Test 3: Authentic WordPress clean installation in database-isolated site activates and boots', function () use ( $zip_file, $worker_file ) {
	$clean_site_info = create_test_wordpress_site( 'safe_mcp_clean_install' );
	try {
		$clean_site = $clean_site_info['site_path'];

		// 1. Explicitly assert pre-installation clean state:
		$pre_cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker_file ) .
				   ' --action=assert-pre-install --wp-path=' . escapeshellarg( $clean_site );
		exec( $pre_cmd, $pre_out, $pre_code );
		assert_equals( 0, $pre_code, 'Pre-install clean state assertion failed: ' . implode( "\n", $pre_out ) );

		// 2. Install exact built ZIP through user-facing WordPress installation path:
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

		// 3. Verify in fresh child PHP process (schema created by authentic activation, zero manual install):
		$verify_cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker_file ) .
					  ' --action=verify-clean-install --wp-path=' . escapeshellarg( $clean_site );
		exec( $verify_cmd, $verify_out, $verify_code );
		assert_equals( 0, $verify_code, 'Clean install verification failed: ' . implode( "\n", $verify_out ) );

		$verify_data = extract_last_json( $verify_out );
		assert_true( is_array( $verify_data ) && true === ( $verify_data['success'] ?? false ), 'Clean install verification payload invalid: ' . implode( "\n", $verify_out ) );
		assert_equals( '1.8.0', $verify_data['version'] ?? '' );
		assert_equals( 4, $verify_data['safety_tables'] ?? 0 );
		assert_true( true === ( $verify_data['abilities_registered'] ?? false ), 'Abilities API must be registered' );
		assert_true( true === ( $verify_data['mcp_server_registered'] ?? false ), 'MCP Adapter server registration must succeed' );
	} finally {
		destroy_test_wordpress_site( $clean_site_info );
	}
} );

// ---------------------------------------------------------------------
// TEST 4: Authentic Frozen Phase 6 Runtime & Package Upgrade (Database-Isolated)
// ---------------------------------------------------------------------

run_test( 'Test 4: In-place upgrade from frozen Phase 6 baseline (f21858ac9d853e38f3121a594f992bf81725cec8) in isolated database', function () use ( $zip_file, $worker_file ) {
	$upgrade_site_info = create_test_wordpress_site( 'safe_mcp_p6_upgrade' );
	try {
		$upgrade_site  = $upgrade_site_info['site_path'];
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
		$seed_file = $upgrade_site . DIRECTORY_SEPARATOR . 'seed.json';
		$seed_cmd  = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker_file ) .
					 ' --action=seed-phase6 --wp-path=' . escapeshellarg( $upgrade_site ) .
					 ' --seed-file=' . escapeshellarg( $seed_file );
		exec( $seed_cmd, $seed_out, $seed_code );
		assert_equals( 0, $seed_code, 'Phase 6 state seeding failed: ' . implode( "\n", $seed_out ) );

		$seed_data = null;
		if ( file_exists( $seed_file ) ) {
			$seed_data = json_decode( (string) file_get_contents( $seed_file ), true );
		}
		if ( ! is_array( $seed_data ) ) {
			$seed_data = extract_last_json( $seed_out );
			if ( is_array( $seed_data ) ) {
				file_put_contents( $seed_file, json_encode( $seed_data ) );
			}
		}

		assert_true( is_array( $seed_data ) && true === ( $seed_data['success'] ?? false ), 'Seed payload invalid: ' . implode( "\n", $seed_out ) );
		assert_true( ! empty( $seed_data['checkpoint_uuid'] ), 'Phase 6 checkpoint_uuid must be generated' );
		assert_true( ( $seed_data['post_id'] ?? 0 ) > 0, 'Phase 6 post_id must be generated' );

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

		$ver_data = extract_last_json( $ver_out );
		assert_true( is_array( $ver_data ) && true === ( $ver_data['success'] ?? false ), 'Post-upgrade verification payload invalid: ' . implode( "\n", $ver_out ) );
		assert_equals( '1.8.0', $ver_data['version'] ?? '' );
		assert_equals( 4, $ver_data['safety_tables'] ?? 0 );
		assert_true( true === ( $ver_data['decrypted'] ?? false ), 'Phase 6 checkpoint must decrypt under Phase 7 crypto' );
		assert_true( true === ( $ver_data['abilities_registered'] ?? false ), 'Abilities API must be registered after upgrade' );
		assert_true( true === ( $ver_data['mcp_server_registered'] ?? false ), 'MCP Adapter server registration must succeed after upgrade' );
	} finally {
		destroy_test_wordpress_site( $upgrade_site_info );
	}
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
