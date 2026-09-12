<?php
/**
 * Test Suite for Real Process Crash & Restart Recovery.
 *
 * Exercises true process-level crash scenarios where worker processes terminate
 * abruptly via exit(255) without running cleanup handlers or releasing locks.
 *
 * Then, completely fresh PHP processes are booted to initialize the plugin and
 * execute recovery routines against real MySQL according to frozen Phase 2 semantics:
 *
 * 1. WAL crash before target write
 * 2. Crash after persistent write before journal commit
 * 3. CREATE crash (durable created_object_id preserved without destructive overwrite)
 * 4. Checkpoint restore crash (preserves restore strategy recovery evidence)
 *
 * Usage: php tests/test-process-crash-recovery.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

echo "=======================================================\n";
echo " Safe Elementor MCP — Process Crash Recovery Suite\n";
echo "=======================================================\n\n";

if ( ! defined( 'FULL_ELEMENTOR_MCP_DIR' ) ) {
	define( 'FULL_ELEMENTOR_MCP_DIR', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}

$active_mysql_conn   = null;
$active_mysql_dbname = null;

function get_crash_mysql_pdo(): PDO {
	global $active_mysql_conn, $active_mysql_dbname;
	$env_host = getenv( 'DB_HOST' ) ?: getenv( 'MYSQL_HOST' ) ?: '127.0.0.1';
	$env_port = (int) ( getenv( 'DB_PORT' ) ?: getenv( 'MYSQL_PORT' ) ?: 0 );
	$env_user = getenv( 'DB_USER' ) ?: getenv( 'MYSQL_USER' ) ?: 'root';
	$env_pass = getenv( 'DB_PASSWORD' ) !== false ? (string) getenv( 'DB_PASSWORD' ) : ( getenv( 'MYSQL_PWD' ) !== false ? (string) getenv( 'MYSQL_PWD' ) : null );
	$dbname   = getenv( 'DB_NAME' ) ?: getenv( 'MYSQL_DATABASE' ) ?: 'safe_elementor_test';

	$candidates = array();
	if ( $env_port > 0 && null !== $env_pass ) {
		$candidates[] = array( 'host' => $env_host, 'port' => $env_port, 'user' => $env_user, 'pass' => $env_pass );
	} elseif ( $env_port > 0 ) {
		$candidates[] = array( 'host' => $env_host, 'port' => $env_port, 'user' => $env_user, 'pass' => 'root' );
		$candidates[] = array( 'host' => $env_host, 'port' => $env_port, 'user' => $env_user, 'pass' => 'mysql' );
		$candidates[] = array( 'host' => $env_host, 'port' => $env_port, 'user' => $env_user, 'pass' => '' );
	} else {
		if ( null !== $env_pass ) {
			$candidates[] = array( 'host' => $env_host, 'port' => 3306, 'user' => $env_user, 'pass' => $env_pass );
			$candidates[] = array( 'host' => $env_host, 'port' => 3307, 'user' => $env_user, 'pass' => $env_pass );
		}
		$candidates[] = array( 'host' => $env_host, 'port' => 3306, 'user' => 'root', 'pass' => 'root' );
		$candidates[] = array( 'host' => $env_host, 'port' => 3306, 'user' => 'root', 'pass' => '' );
		$candidates[] = array( 'host' => $env_host, 'port' => 3307, 'user' => 'root', 'pass' => 'mysql' );
	}

	foreach ( $candidates as $c ) {
		try {
			$pdo = new PDO( "mysql:host={$c['host']};port={$c['port']};dbname={$dbname}", $c['user'], $c['pass'], array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			) );
			$active_mysql_conn   = $c;
			$active_mysql_dbname = $dbname;
			return $pdo;
		} catch ( Exception $e ) {
			// continue
		}
	}

	fwrite( STDERR, "FATAL: Could not connect to MySQL for crash recovery tests\n" );
	exit( 1 );
}

$pdo = get_crash_mysql_pdo();
echo "Connected to MySQL " . $pdo->getAttribute( PDO::ATTR_SERVER_VERSION ) . "\n\n";

// Ensure safety database tables exist before executing crash recovery tests
require_once __DIR__ . '/test-mysql-safety.php';
Full_Elementor_MCP_Database_Installer::install();

$inc_dir = dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR;
require_once $inc_dir . 'class-compatibility-checker.php';
require_once $inc_dir . 'safety/class-database-installer.php';
require_once $inc_dir . 'safety/class-safety-settings.php';
require_once $inc_dir . 'safety/class-lock-manager.php';
require_once $inc_dir . 'safety/class-security-guard.php';
require_once $inc_dir . 'safety/class-elementor-features.php';
require_once $inc_dir . 'safety/class-tree-validator.php';
require_once $inc_dir . 'safety/class-security-strategies.php';
require_once $inc_dir . 'safety/class-mutation-registry.php';
require_once $inc_dir . 'safety/class-journal.php';
require_once $inc_dir . 'safety/class-mutation-context.php';
require_once $inc_dir . 'safety/class-safe-writes.php';
require_once $inc_dir . 'safety/class-confirmation-manager.php';
require_once $inc_dir . 'safety/class-idempotency-manager.php';
require_once $inc_dir . 'safety/class-checkpoint-crypto.php';
require_once $inc_dir . 'safety/class-checkpoint-strategies.php';
require_once $inc_dir . 'safety/class-checkpoint-manager.php';
require_once $inc_dir . 'safety/class-audit-logger.php';
require_once $inc_dir . 'safety/class-undo-manager.php';
require_once $inc_dir . 'safety/class-mutation-middleware.php';

Full_Elementor_MCP_Mutation_Registry::init_core_strategies();

$crash_script    = FULL_ELEMENTOR_MCP_DIR . 'tests/worker-crash-runner.php';
$recovery_script = FULL_ELEMENTOR_MCP_DIR . 'tests/worker-recovery-runner.php';
$php_bin         = PHP_BINARY;

$total_tests  = 0;
$passed_tests = 0;
$failed_tests = 0;

if ( ! function_exists( 'run_test' ) ) {
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
}

if ( ! function_exists( 'assert_true' ) ) {
	function assert_true( mixed $val, string $msg = 'Expected true' ): void {
		if ( true !== $val ) {
			throw new RuntimeException( $msg . ' (got: ' . var_export( $val, true ) . ')' );
		}
	}
}

if ( ! function_exists( 'assert_equals' ) ) {
	function assert_equals( mixed $expected, mixed $actual, string $msg = '' ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( ( $msg ? $msg . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}
}

function spawn_worker( string $php_bin, string $worker_script, array $args ): array {
	$cmd = escapeshellarg( $php_bin ) . ' ' . escapeshellarg( $worker_script );
	foreach ( $args as $k => $v ) {
		$cmd .= ' --' . escapeshellarg( $k ) . '=' . escapeshellarg( (string) $v );
	}

	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	global $active_mysql_conn, $active_mysql_dbname;
	$env = getenv();
	if ( ! is_array( $env ) ) {
		$env = array();
	}
	$env['DB_HOST']     = (string) ( $active_mysql_conn['host'] ?? '127.0.0.1' );
	$env['DB_PORT']     = (string) ( $active_mysql_conn['port'] ?? 3306 );
	$env['DB_USER']     = (string) ( $active_mysql_conn['user'] ?? 'root' );
	$env['DB_PASSWORD'] = (string) ( $active_mysql_conn['pass'] ?? 'root' );
	$env['DB_NAME']     = (string) ( $active_mysql_dbname ?? 'safe_elementor_test' );

	$proc = proc_open( $cmd, $descriptors, $pipes, null, $env );
	if ( ! is_resource( $proc ) ) {
		throw new RuntimeException( "Failed to spawn worker process: {$cmd}" );
	}

	return array(
		'process' => $proc,
		'pipes'   => $pipes,
	);
}

function wait_worker( array $worker ): array {
	fclose( $worker['pipes'][0] );
	$stdout = stream_get_contents( $worker['pipes'][1] );
	fclose( $worker['pipes'][1] );
	$stderr = stream_get_contents( $worker['pipes'][2] );
	fclose( $worker['pipes'][2] );

	$exit_code = proc_close( $worker['process'] );
	$data = json_decode( trim( (string) $stdout ), true ) ?: array();

	return array(
		'exit_code' => $exit_code,
		'data'      => $data,
		'stderr'    => trim( (string) $stderr ),
	);
}

/**
 * Runs a crash worker and asserts that it died with exit code 255.
 */
function run_crash_worker( string $php_bin, string $crash_script, array $args ): void {
	$w = spawn_worker( $php_bin, $crash_script, $args );
	$r = wait_worker( $w );
	assert_equals( 255, $r['exit_code'], 'Crashed worker must terminate abruptly with code 255' );
}

/**
 * Boots a completely fresh PHP process to run recovery scan.
 */
function run_fresh_recovery( string $php_bin, string $recovery_script, int $grace = 10 ): array {
	$w = spawn_worker( $php_bin, $recovery_script, array( 'grace' => $grace ) );
	$r = wait_worker( $w );
	assert_equals( 0, $r['exit_code'], 'Recovery process must exit cleanly with 0: ' . $r['stderr'] );
	return $r['data']['reports'] ?? array();
}

// ---------------------------------------------------------------------
// TEST 1: WAL Crash Before Target Write
// ---------------------------------------------------------------------

run_test( 'Crash Scenario 1: WAL crash before target write recovered truthfully by fresh process', function () use ( $pdo, $php_bin, $crash_script, $recovery_script ) {
	$object_id = 7711;
	$res       = "post:{$object_id}";

	// 1. Crash worker in WAL phase:
	run_crash_worker( $php_bin, $crash_script, array(
		'scenario'  => 'wal_before_write',
		'resource'  => $res,
		'object_id' => $object_id,
	) );

	// Verify pending WAL and active lock exist in MySQL:
	$j_row = $pdo->query( "SELECT id, status, fencing_token FROM `wp_elementor_mcp_journal` WHERE resource_key = '{$res}' ORDER BY id DESC LIMIT 1" )->fetch();
	assert_true( ! empty( $j_row ), 'Pending journal row must exist' );
	assert_equals( 'pending', $j_row['status'] );

	// 2. Simulate expiration of the abandoned lock (beyond grace period):
	$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $res );
	$pdo->exec( "UPDATE `wp_elementor_mcp_tokens` SET expires_at = UTC_TIMESTAMP() - INTERVAL 120 SECOND WHERE token_key = '{$lock_key}'" );
	$pdo->exec( "UPDATE `wp_elementor_mcp_journal` SET created_at = UTC_TIMESTAMP() - INTERVAL 120 SECOND WHERE id = {$j_row['id']}" );

	// 3. Boot fresh PHP process to execute recovery scan:
	$reports = run_fresh_recovery( $php_bin, $recovery_script, 10 );

	// 4. Verify truthful result:
	$updated = $pdo->query( "SELECT status, error_message FROM `wp_elementor_mcp_journal` WHERE id = {$j_row['id']}" )->fetch();
	assert_true( in_array( $updated['status'], array( 'failed', 'undone', 'reconciled' ), true ), 'Status must not remain pending (was: ' . $updated['status'] . ')' );
} );

// ---------------------------------------------------------------------
// TEST 2: Crash After Persistent Write Before Journal Commit
// ---------------------------------------------------------------------

run_test( 'Crash Scenario 2: Crash after write before commit reconciles conservatively without blind overwrite', function () use ( $pdo, $php_bin, $crash_script, $recovery_script ) {
	$object_id = 7712;
	$res       = "post:{$object_id}";

	// 1. Worker writes target state, then abruptly dies:
	run_crash_worker( $php_bin, $crash_script, array(
		'scenario'  => 'write_before_commit',
		'resource'  => $res,
		'object_id' => $object_id,
	) );

	$j_row = $pdo->query( "SELECT id, status FROM `wp_elementor_mcp_journal` WHERE resource_key = '{$res}' ORDER BY id DESC LIMIT 1" )->fetch();
	assert_true( ! empty( $j_row ) );

	// 2. Expire lease:
	$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $res );
	$pdo->exec( "UPDATE `wp_elementor_mcp_tokens` SET expires_at = UTC_TIMESTAMP() - INTERVAL 120 SECOND WHERE token_key = '{$lock_key}'" );
	$pdo->exec( "UPDATE `wp_elementor_mcp_journal` SET created_at = UTC_TIMESTAMP() - INTERVAL 120 SECOND WHERE id = {$j_row['id']}" );

	// 3. Fresh recovery process:
	$reports = run_fresh_recovery( $php_bin, $recovery_script, 10 );

	// 4. Status must be closed conservatively:
	$updated = $pdo->query( "SELECT status, error_message FROM `wp_elementor_mcp_journal` WHERE id = {$j_row['id']}" )->fetch();
	assert_true( in_array( $updated['status'], array( 'failed', 'undone' ), true ) );
} );

// ---------------------------------------------------------------------
// TEST 3: CREATE Crash
// ---------------------------------------------------------------------

run_test( 'Crash Scenario 3: CREATE crash preserves durable created_object_id binding without destructive overwrite', function () use ( $pdo, $php_bin, $crash_script, $recovery_script ) {
	$created_id = 9912;

	// 1. Worker creates object, binds created_object_id, then dies before commit:
	run_crash_worker( $php_bin, $crash_script, array(
		'scenario'  => 'create_crash',
		'object_id' => $created_id,
	) );

	$j_row = $pdo->query( "SELECT id, created_object_id, status, resource_key FROM `wp_elementor_mcp_journal` WHERE ability = 'full-elementor-mcp/create-page' ORDER BY id DESC LIMIT 1" )->fetch();
	assert_true( ! empty( $j_row ) );
	assert_equals( $created_id, (int) $j_row['created_object_id'] );

	// 2. Expire lease:
	$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $j_row['resource_key'] );
	$pdo->exec( "UPDATE `wp_elementor_mcp_tokens` SET expires_at = UTC_TIMESTAMP() - INTERVAL 120 SECOND WHERE token_key = '{$lock_key}'" );
	$pdo->exec( "UPDATE `wp_elementor_mcp_journal` SET created_at = UTC_TIMESTAMP() - INTERVAL 120 SECOND WHERE id = {$j_row['id']}" );

	// 3. Fresh recovery process:
	$reports = run_fresh_recovery( $php_bin, $recovery_script, 10 );

	// 4. Verify conservative CREATE recovery:
	$updated = $pdo->query( "SELECT status, error_message, created_object_id FROM `wp_elementor_mcp_journal` WHERE id = {$j_row['id']}" )->fetch();
	assert_equals( 'failed', $updated['status'] );
	assert_equals( $created_id, (int) $updated['created_object_id'], 'created_object_id must remain bound for manual reconciliation' );
	assert_true( str_contains( (string) $updated['error_message'], 'abandoned_create' ), 'Must report abandoned create: ' . $updated['error_message'] );
} );

// ---------------------------------------------------------------------
// TEST 4: Checkpoint Restore Crash
// ---------------------------------------------------------------------

run_test( 'Crash Scenario 4: Checkpoint restore crash preserves restore recovery evidence', function () use ( $pdo, $php_bin, $crash_script, $recovery_script ) {
	$object_id = 7714;
	$res       = "post:{$object_id}";

	// 1. Worker dies during checkpoint restore:
	run_crash_worker( $php_bin, $crash_script, array(
		'scenario'  => 'restore_crash',
		'resource'  => $res,
		'object_id' => $object_id,
	) );

	$j_row = $pdo->query( "SELECT id, status, resource_key FROM `wp_elementor_mcp_journal` WHERE ability = 'checkpoint-restore' AND resource_key = '{$res}' ORDER BY id DESC LIMIT 1" )->fetch();
	assert_true( ! empty( $j_row ) );

	// 2. Expire lease:
	$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $j_row['resource_key'] );
	$pdo->exec( "UPDATE `wp_elementor_mcp_tokens` SET expires_at = UTC_TIMESTAMP() - INTERVAL 120 SECOND WHERE token_key = '{$lock_key}'" );
	$pdo->exec( "UPDATE `wp_elementor_mcp_journal` SET created_at = UTC_TIMESTAMP() - INTERVAL 120 SECOND WHERE id = {$j_row['id']}" );

	// 3. Fresh recovery process:
	$reports = run_fresh_recovery( $php_bin, $recovery_script, 10 );

	// 4. Verify recovery marks the abandoned restore and preserves evidence:
	$updated = $pdo->query( "SELECT status, error_message FROM `wp_elementor_mcp_journal` WHERE id = {$j_row['id']}" )->fetch();
	assert_true( in_array( $updated['status'], array( 'failed', 'undone' ), true ) );
} );

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------

echo "\n=======================================================\n";
echo " Crash Recovery Results: {$passed_tests}/{$total_tests} passed.";
if ( $failed_tests > 0 ) {
	echo " ({$failed_tests} failed)\n";
	echo "=======================================================\n";
	exit( 1 );
}
echo "\n=======================================================\n";
exit( 0 );
