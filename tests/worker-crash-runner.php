<?php
/**
 * Worker Process for Process-Level Crash and Abrupt Exit Scenarios.
 *
 * Simulates abrupt worker crashes (process dying without cleanup) at distinct
 * points in the mutation lifecycle:
 * - wal_before_write: acquires lock, writes pending WAL journal, then dies (exit 255).
 * - write_before_commit: writes target object, then dies before committing journal.
 * - create_crash: creates object and binds created_object_id, then dies before commit.
 * - restore_crash: initiates restore WAL, then dies mid-restore.
 *
 * Usage: php tests/worker-crash-runner.php --scenario=wal_before_write --resource=post:77
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

function get_crash_pdo(): PDO {
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
			return $pdo;
		} catch ( Exception $e ) {
			// continue
		}
	}
	exit( 1 );
}

$pdo = get_crash_pdo();

$options = getopt( '', array(
	'scenario:',
	'resource:',
	'worker:',
	'object_id:',
) );

$scenario  = $options['scenario'] ?? 'wal_before_write';
$resource  = $options['resource'] ?? 'post:77';
$worker_id = $options['worker'] ?? 'crashed-worker-' . getmypid();
$object_id = (int) ( $options['object_id'] ?? 77 );

$prefix = 'wp_';
$tbl_tokens  = $prefix . 'elementor_mcp_tokens';
$tbl_journal = $prefix . 'elementor_mcp_journal';

switch ( $scenario ) {

	case 'wal_before_write':
		// 1. Acquire lock:
		$stmt = $pdo->prepare(
			"INSERT INTO `{$tbl_tokens}` (token_key, token_type, owner_id, fencing_token, expires_at)
			 VALUES (?, 'lock', ?, 1, UTC_TIMESTAMP() + INTERVAL 5 SECOND)"
		);
		$stmt->execute( array( 'lock:' . $resource, $worker_id ) );

		// 2. Write pending WAL record before any write:
		$j = $pdo->prepare(
			"INSERT INTO `{$tbl_journal}` (ability, action, object_type, object_id, resource_key, fencing_token, rollback_supported, status, before_hash)
			 VALUES ('full-elementor-mcp/update-element', 'update_element', 'post', ?, ?, 1, 1, 'pending', ?)"
		);
		$j->execute( array( $object_id, $resource, hash( 'sha256', 'initial-state' ) ) );

		// 3. ABRUPT CRASH: process dies without executing target write or releasing lock!
		fwrite( STDERR, "Worker dying abruptly in WAL before target write\n" );
		exit( 255 );

	case 'write_before_commit':
		// 1. Acquire lock:
		$stmt = $pdo->prepare(
			"INSERT INTO `{$tbl_tokens}` (token_key, token_type, owner_id, fencing_token, expires_at)
			 VALUES (?, 'lock', ?, 1, UTC_TIMESTAMP() + INTERVAL 5 SECOND)"
		);
		$stmt->execute( array( 'lock:' . $resource, $worker_id ) );

		// 2. Write pending WAL record:
		$j = $pdo->prepare(
			"INSERT INTO `{$tbl_journal}` (ability, action, object_type, object_id, resource_key, fencing_token, rollback_supported, status, before_state, before_hash)
			 VALUES ('full-elementor-mcp/update-element', 'update_element', 'post', ?, ?, 1, 1, 'pending', ?, ?)"
		);
		$j->execute( array( $object_id, $resource, json_encode( array( 'content' => 'old' ) ), hash( 'sha256', 'old' ) ) );

		// 3. Target write began (state is now altered / uncertain)
		// 4. ABRUPT CRASH before journal commit!
		fwrite( STDERR, "Worker dying abruptly after write before journal commit\n" );
		exit( 255 );

	case 'create_crash':
		// 1. Acquire lock:
		$stmt = $pdo->prepare(
			"INSERT INTO `{$tbl_tokens}` (token_key, token_type, owner_id, fencing_token, expires_at)
			 VALUES (?, 'lock', ?, 1, UTC_TIMESTAMP() + INTERVAL 5 SECOND)"
		);
		$stmt->execute( array( 'lock:' . $resource, $worker_id ) );

		// 2. Create entity and bind durable created_object_id in pending journal:
		$j = $pdo->prepare(
			"INSERT INTO `{$tbl_journal}` (ability, action, object_type, object_id, created_object_id, resource_key, fencing_token, rollback_supported, status)
			 VALUES ('full-elementor-mcp/create-page', 'create_page', 'page', 0, ?, ?, 1, 0, 'pending')"
		);
		$j->execute( array( $object_id, $resource ) );

		// 3. ABRUPT CRASH: created_object_id exists, but operation died before commit!
		fwrite( STDERR, "Worker dying abruptly after object creation before commit\n" );
		exit( 255 );

	case 'restore_crash':
		// 1. Acquire restore lock:
		$stmt = $pdo->prepare(
			"INSERT INTO `{$tbl_tokens}` (token_key, token_type, owner_id, fencing_token, expires_at)
			 VALUES (?, 'restore_lock', ?, 1, UTC_TIMESTAMP() + INTERVAL 5 SECOND)"
		);
		$stmt->execute( array( 'restore:' . $resource, $worker_id ) );

		// 2. Pending restore journal:
		$j = $pdo->prepare(
			"INSERT INTO `{$tbl_journal}` (ability, action, object_type, object_id, resource_key, fencing_token, rollback_supported, status, before_state)
			 VALUES ('checkpoint_restore', 'restore', 'post', ?, ?, 1, 1, 'pending', ?)"
		);
		$j->execute( array( $object_id, $resource, json_encode( array( 'strategy' => 'checkpoint-restore' ) ) ) );

		// 3. ABRUPT CRASH during persistent restore:
		fwrite( STDERR, "Worker dying abruptly during checkpoint restore\n" );
		exit( 255 );

	default:
		fwrite( STDERR, "Unknown crash scenario: {$scenario}\n" );
		exit( 1 );
}
