<?php
/**
 * Real Multi-Process MySQL Concurrency Test Suite.
 *
 * Spawns independent OS child processes, each connecting independently to
 * real MySQL (no shared memory or mock $wpdb instances).
 *
 * Verifies all 9 mandatory concurrency race scenarios:
 * 1. Resource Locking Contention
 * 2. Idempotency Duplicate Race
 * 3. Idempotency Conflicting Args Race
 * 4. Confirmation Double-Consume Race
 * 5. Journal Incompatible Terminal CAS Race
 * 6. Checkpoint UUID Collision Race
 * 7. Concurrent Journal Undo Race
 * 8. Concurrent Resource Restore Race
 * 9. Stale Writer Fencing Token Rejection
 *
 * Usage: php tests/test-mysql-concurrency.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

echo "=======================================================\n";
echo " Safe Elementor MCP — Real MySQL Concurrency Suite\n";
echo "=======================================================\n\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'FULL_ELEMENTOR_MCP_DIR' ) ) {
	define( 'FULL_ELEMENTOR_MCP_DIR', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}

$active_mysql_conn   = null;
$active_mysql_dbname = null;

function get_concurrency_mysql_pdo(): PDO {
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

	fwrite( STDERR, "FATAL: Could not connect to MySQL for concurrency tests\n" );
	exit( 1 );
}

$pdo = get_concurrency_mysql_pdo();
echo "Executing multi-process races against MySQL " . $pdo->getAttribute( PDO::ATTR_SERVER_VERSION ) . "...\n\n";

// Ensure safety database tables exist before executing concurrency races
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

$worker_script = FULL_ELEMENTOR_MCP_DIR . 'tests/worker-mysql-runner.php';
$php_bin       = PHP_BINARY;

// Test framework
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

if ( ! function_exists( 'assert_false' ) ) {
	function assert_false( mixed $val, string $msg = 'Expected false' ): void {
		if ( false !== $val ) {
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

/**
 * Spawns an asynchronous worker process returning process resource and pipes.
 *
 * @param array<string, mixed> $args
 * @return array{process: resource, pipes: array<int, resource>}
 */
function spawn_worker( string $php_bin, string $worker_script, array $args ): array {
	$cmd = escapeshellarg( $php_bin ) . ' ' . escapeshellarg( $worker_script );
	foreach ( $args as $k => $v ) {
		$cmd .= ' --' . escapeshellarg( $k ) . '=' . escapeshellarg( (string) $v );
	}

	$descriptors = array(
		0 => array( 'pipe', 'r' ), // stdin
		1 => array( 'pipe', 'w' ), // stdout
		2 => array( 'pipe', 'w' ), // stderr
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

/**
 * Waits for a worker to finish and parses JSON output.
 *
 * @param array{process: resource, pipes: array<int, resource>} $worker
 * @return array{exit_code: int, data: array<string, mixed>, stderr: string}
 */
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

// ---------------------------------------------------------------------
// TEST 1: Resource Locking Contention
// ---------------------------------------------------------------------

run_test( 'Race 1: Resource locking contention allows exactly one current generation owner', function () use ( $pdo, $php_bin, $worker_script ) {
	$resource = 'race:post:' . bin2hex( random_bytes( 4 ) );

	$w1 = spawn_worker( $php_bin, $worker_script, array(
		'action'   => 'lock',
		'worker'   => 'worker-1',
		'resource' => $resource,
		'hold_ms'  => 150,
	) );
	$w2 = spawn_worker( $php_bin, $worker_script, array(
		'action'   => 'lock',
		'worker'   => 'worker-2',
		'resource' => $resource,
		'hold_ms'  => 150,
	) );

	$r1 = wait_worker( $w1 );
	$r2 = wait_worker( $w2 );

	$success_count = ( ( $r1['data']['success'] ?? false ) ? 1 : 0 ) + ( ( $r2['data']['success'] ?? false ) ? 1 : 0 );
	assert_equals( 1, $success_count, 'Exactly one worker must acquire lock generation' );

	$winner = ( $r1['data']['success'] ?? false ) ? $r1['data'] : $r2['data'];
	$loser  = ( $r1['data']['success'] ?? false ) ? $r2['data'] : $r1['data'];

	assert_equals( 'acquired', $winner['status'] );
	assert_equals( 1, $winner['fencing_token'] );
	assert_equals( 'busy', $loser['status'] );
	assert_equals( $winner['worker'], $loser['current_owner'] );
} );

// ---------------------------------------------------------------------
// TEST 2: Idempotency Duplicate Race
// ---------------------------------------------------------------------

run_test( 'Race 2: Idempotency duplicate race executes exactly once, second receives replay', function () use ( $pdo, $php_bin, $worker_script ) {
	$key       = 'idem-race-' . bin2hex( random_bytes( 4 ) );
	$args_hash = hash( 'sha256', 'mutation-payload-123' );

	$w1 = spawn_worker( $php_bin, $worker_script, array(
		'action'    => 'idempotency',
		'worker'    => 'worker-A',
		'key'       => $key,
		'args_hash' => $args_hash,
	) );
	$w2 = spawn_worker( $php_bin, $worker_script, array(
		'action'    => 'idempotency',
		'worker'    => 'worker-B',
		'key'       => $key,
		'args_hash' => $args_hash,
	) );

	$r1 = wait_worker( $w1 );
	$r2 = wait_worker( $w2 );

	$executed_count = ( 'executed' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                  ( 'executed' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );
	$replayed_count = ( 'replayed' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                  ( 'replayed' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );

	assert_equals( 1, $executed_count, 'Exactly one worker must execute mutation' );
	assert_equals( 1, $replayed_count, 'Second worker must receive replayed state' );
} );

// ---------------------------------------------------------------------
// TEST 3: Idempotency Conflicting Args Race
// ---------------------------------------------------------------------

run_test( 'Race 3: Idempotency with conflicting args rejects second execution as conflict', function () use ( $pdo, $php_bin, $worker_script ) {
	$key = 'idem-conflict-' . bin2hex( random_bytes( 4 ) );

	$w1 = spawn_worker( $php_bin, $worker_script, array(
		'action'    => 'idempotency',
		'worker'    => 'worker-A',
		'key'       => $key,
		'args_hash' => hash( 'sha256', 'args-version-1' ),
	) );
	$w2 = spawn_worker( $php_bin, $worker_script, array(
		'action'    => 'idempotency',
		'worker'    => 'worker-B',
		'key'       => $key,
		'args_hash' => hash( 'sha256', 'args-version-2' ),
	) );

	$r1 = wait_worker( $w1 );
	$r2 = wait_worker( $w2 );

	$executed_count = ( 'executed' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                  ( 'executed' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );
	$conflict_count = ( 'conflict' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                  ( 'conflict' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );

	assert_equals( 1, $executed_count, 'Exactly one request executes' );
	assert_equals( 1, $conflict_count, 'Competing request with different args must receive conflict error' );
} );

// ---------------------------------------------------------------------
// TEST 4: Confirmation Double-Consume Race
// ---------------------------------------------------------------------

run_test( 'Race 4: Confirmation double-consume race succeeds for exactly one worker', function () use ( $pdo, $php_bin, $worker_script ) {
	$conf = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
		'full-elementor-mcp/delete-page',
		array( 'post_id' => 42, 'force' => true ),
		1,
		null,
		'post:42',
		array( 'destructive_action' )
	);
	$token_id = $conf['confirmation_token'];

	$w1 = spawn_worker( $php_bin, $worker_script, array(
		'action' => 'confirmation',
		'worker' => 'worker-1',
		'token'  => $token_id,
	) );
	$w2 = spawn_worker( $php_bin, $worker_script, array(
		'action' => 'confirmation',
		'worker' => 'worker-2',
		'token'  => $token_id,
	) );

	$r1 = wait_worker( $w1 );
	$r2 = wait_worker( $w2 );

	$consumed_count = ( 'consumed' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                  ( 'consumed' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );

	assert_equals( 1, $consumed_count, 'Confirmation token must be consumed exactly once' );
} );

// ---------------------------------------------------------------------
// TEST 5: Journal Terminal CAS Transition Race
// ---------------------------------------------------------------------

run_test( 'Race 5: Journal CAS allows only one legal terminal transition', function () use ( $pdo, $php_bin, $worker_script ) {
	$stmt = $pdo->prepare(
		"INSERT INTO `wp_elementor_mcp_journal` (ability, action, object_type, object_id, resource_key, status, fencing_token)
		 VALUES ('full-elementor-mcp/update-element', 'update_element', 'post', 88, 'post:88', 'pending', 1)"
	);
	$stmt->execute();
	$journal_id = (int) $pdo->lastInsertId();

	$w1 = spawn_worker( $php_bin, $worker_script, array(
		'action'     => 'journal_cas',
		'worker'     => 'worker-commit',
		'journal_id' => $journal_id,
		'status'     => 'committed',
	) );
	$w2 = spawn_worker( $php_bin, $worker_script, array(
		'action'     => 'journal_cas',
		'worker'     => 'worker-fail',
		'journal_id' => $journal_id,
		'status'     => 'failed',
	) );

	$r1 = wait_worker( $w1 );
	$r2 = wait_worker( $w2 );

	$success_count = ( ( $r1['data']['success'] ?? false ) ? 1 : 0 ) + ( ( $r2['data']['success'] ?? false ) ? 1 : 0 );
	assert_equals( 1, $success_count, 'Only one terminal CAS transition can win' );

	$chk = $pdo->prepare( "SELECT status FROM `wp_elementor_mcp_journal` WHERE id = ?" );
	$chk->execute( array( $journal_id ) );
	$final_status = $chk->fetchColumn();

	$winner_status = ( $r1['data']['success'] ?? false ) ? 'committed' : 'failed';
	assert_equals( $winner_status, $final_status, 'Database status must reflect single winning CAS transition' );
} );

// ---------------------------------------------------------------------
// TEST 6: Checkpoint UUID Collision Race
// ---------------------------------------------------------------------

run_test( 'Race 6: Checkpoint UUID uniqueness race rejects collision without creating ambiguous rows', function () use ( $pdo, $php_bin, $worker_script ) {
	$uuid = 'colliding-uuid-' . bin2hex( random_bytes( 4 ) );

	$w1 = spawn_worker( $php_bin, $worker_script, array(
		'action'   => 'checkpoint_insert',
		'worker'   => 'worker-chk-1',
		'uuid'     => $uuid,
		'resource' => 'global:elementor-kit-state',
	) );
	$w2 = spawn_worker( $php_bin, $worker_script, array(
		'action'   => 'checkpoint_insert',
		'worker'   => 'worker-chk-2',
		'uuid'     => $uuid,
		'resource' => 'global:elementor-kit-state',
	) );

	$r1 = wait_worker( $w1 );
	$r2 = wait_worker( $w2 );

	$inserted_count = ( 'inserted' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                  ( 'inserted' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );
	$rejected_count = ( 'duplicate_uuid_rejected' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                  ( 'duplicate_uuid_rejected' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );

	assert_equals( 1, $inserted_count, 'Exactly one checkpoint row can be inserted with given UUID' );
	assert_equals( 1, $rejected_count, 'Second insertion must be rejected by UNIQUE constraint' );

	$cnt = (int) $pdo->query( "SELECT COUNT(*) FROM `wp_elementor_mcp_checkpoints` WHERE checkpoint_uuid = '{$uuid}'" )->fetchColumn();
	assert_equals( 1, $cnt, 'Exactly one row must exist in MySQL' );
} );

// ---------------------------------------------------------------------
// TEST 7: Concurrent Journal Undo Race
// ---------------------------------------------------------------------

run_test( 'Race 7: Concurrent undo race allows exactly one worker to perform persistent rollback', function () use ( $pdo, $php_bin, $worker_script ) {
	$empty_hash = Full_Elementor_MCP_Journal::hash_state( array() );
	$stmt = $pdo->prepare(
		"INSERT INTO `wp_elementor_mcp_journal` (ability, action, object_type, object_id, resource_key, status, rollback_supported, before_hash, after_hash, fencing_token)
		 VALUES ('full-elementor-mcp/update-element', 'update_element', 'post', 99, 'post:99', 'committed', 1, ?, ?, 1)"
	);
	$stmt->execute( array( $empty_hash, $empty_hash ) );
	$journal_id = (int) $pdo->lastInsertId();

	$w1 = spawn_worker( $php_bin, $worker_script, array(
		'action'     => 'undo',
		'worker'     => 'worker-undo-1',
		'journal_id' => $journal_id,
		'hold_ms'    => 100,
	) );
	$w2 = spawn_worker( $php_bin, $worker_script, array(
		'action'     => 'undo',
		'worker'     => 'worker-undo-2',
		'journal_id' => $journal_id,
		'hold_ms'    => 100,
	) );

	$r1 = wait_worker( $w1 );
	$r2 = wait_worker( $w2 );

	$undone_count = ( 'undone' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                ( 'undone' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );
	$conflict_count = ( 'conflict_already_undone_or_in_progress' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                  ( 'conflict_already_undone_or_in_progress' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );

	assert_equals( 1, $undone_count, 'Exactly one worker can claim and execute undo' );
	assert_equals( 1, $conflict_count, 'Second worker must receive conflict state' );
} );

// ---------------------------------------------------------------------
// TEST 8: Concurrent Resource Restore Race
// ---------------------------------------------------------------------

run_test( 'Race 8: Concurrent restore serialize and reject overlapping restores via lock', function () use ( $pdo, $php_bin, $worker_script ) {
	$resource = 'restore:res:' . bin2hex( random_bytes( 4 ) );

	$w1 = spawn_worker( $php_bin, $worker_script, array(
		'action'   => 'restore',
		'worker'   => 'worker-restore-1',
		'resource' => $resource,
		'hold_ms'  => 150,
	) );
	$w2 = spawn_worker( $php_bin, $worker_script, array(
		'action'   => 'restore',
		'worker'   => 'worker-restore-2',
		'resource' => $resource,
		'hold_ms'  => 150,
	) );

	$r1 = wait_worker( $w1 );
	$r2 = wait_worker( $w2 );

	$restored_count = ( 'restored' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                  ( 'restored' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );
	$locked_count   = ( 'restore_locked_by_other' === ( $r1['data']['status'] ?? '' ) ? 1 : 0 ) +
	                  ( 'restore_locked_by_other' === ( $r2['data']['status'] ?? '' ) ? 1 : 0 );

	assert_equals( 1, $restored_count, 'Exactly one restore acquires canonical restore lock' );
	assert_equals( 1, $locked_count, 'Concurrent second restore is safely blocked/rejected' );
} );

// ---------------------------------------------------------------------
// TEST 9: Stale Writer Fencing Token Rejection
// ---------------------------------------------------------------------

run_test( 'Race 9: Stale writer loses lease to Worker B; Worker A persistent Safe Write fails', function () use ( $pdo, $php_bin, $worker_script ) {
	$resource = 'res:stale:' . bin2hex( random_bytes( 4 ) );

	// 1. Worker A acquires initial lease (generation 1)
	$wA = spawn_worker( $php_bin, $worker_script, array(
		'action'   => 'lock',
		'worker'   => 'worker-A',
		'resource' => $resource,
	) );
	$rA = wait_worker( $wA );
	assert_true( $rA['data']['success'] ?? false, 'Worker A acquires lease generation 1' );
	assert_equals( 1, $rA['data']['fencing_token'] );

	// 2. Worker A's lease expires: expire it manually in MySQL:
	$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource );
	$pdo->exec( "UPDATE `wp_elementor_mcp_tokens` SET expires_at = UTC_TIMESTAMP() - INTERVAL 5 SECOND WHERE token_key = '{$lock_key}'" );

	// 3. Worker B recovers the expired lease with CAS, advancing generation to 2:
	$wB = spawn_worker( $php_bin, $worker_script, array(
		'action'   => 'lock',
		'worker'   => 'worker-B',
		'resource' => $resource,
	) );
	$rB = wait_worker( $wB );
	assert_true( $rB['data']['success'] ?? false, 'Worker B recovers lease' );
	assert_equals( 2, $rB['data']['fencing_token'], 'Generation strictly increments to 2' );

	// 4. Stale Worker A wakes up and attempts a persistent safe write using its old generation (1):
	$wA_write = spawn_worker( $php_bin, $worker_script, array(
		'action'        => 'safe_write',
		'worker'        => 'worker-A',
		'resource'      => $resource,
		'fencing_token' => 1,
	) );
	$rA_write = wait_worker( $wA_write );

	assert_false( $rA_write['data']['success'] ?? true, 'Stale Worker A write must be rejected' );
	assert_equals( 'fencing_token_mismatch', $rA_write['data']['status'] ?? '' );
} );

// ---------------------------------------------------------------------
// TEST SUMMARY
// ---------------------------------------------------------------------

if ( basename( __FILE__ ) === basename( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) {
	echo "\n=======================================================\n";
	echo " Concurrency Results: {$passed_tests}/{$total_tests} passed.";
	if ( $failed_tests > 0 ) {
		echo " ({$failed_tests} failed)\n";
		echo "=======================================================\n";
		exit( 1 );
	}
	echo "\n=======================================================\n";
	exit( 0 );
}
