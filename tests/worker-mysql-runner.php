<?php
/**
 * Worker Process for Real MySQL Multi-Process Concurrency Testing.
 *
 * Runs as an independent OS process with its own independent MySQL connection.
 *
 * Actions:
 * - lock: Contend for a resource lock with leasing and fencing token.
 * - idempotency: Register or claim an idempotency key with args hash.
 * - confirmation: Consume a one-time confirmation token atomically.
 * - journal_cas: Attempt terminal status transition on a journal row.
 * - checkpoint_insert: Insert a checkpoint with a given UUID.
 * - undo: Attempt atomic undo execution on a journal row.
 * - restore: Attempt resource lock and checkpoint restore.
 * - safe_write: Attempt conditional write with a specific fencing token.
 *
 * Output is emitted as a single JSON object to STDOUT.
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

function get_worker_pdo(): PDO {
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
			// try next
		}
	}

	fwrite( STDERR, "Worker failed to connect to MySQL\n" );
	exit( 2 );
}

$pdo = get_worker_pdo();

// Parse CLI options
$options = getopt( '', array(
	'action:',
	'worker:',
	'resource:',
	'key:',
	'args_hash:',
	'token:',
	'journal_id:',
	'status:',
	'uuid:',
	'fencing_token:',
	'hold_ms:',
	'delay_ms:',
) );

$action   = $options['action'] ?? '';
$worker   = $options['worker'] ?? 'worker-' . getmypid();
$delay_ms = (int) ( $options['delay_ms'] ?? 0 );
if ( $delay_ms > 0 ) {
	usleep( $delay_ms * 1000 );
}

$hold_ms = (int) ( $options['hold_ms'] ?? 0 );

$prefix = 'wp_';
$tbl_tokens      = $prefix . 'elementor_mcp_tokens';
$tbl_journal     = $prefix . 'elementor_mcp_journal';
$tbl_checkpoints = $prefix . 'elementor_mcp_checkpoints';

$result = array(
	'worker'  => $worker,
	'action'  => $action,
	'success' => false,
);

try {
	switch ( $action ) {

		case 'lock':
			$resource = $options['resource'] ?? 'post:1';
			$token_key = 'lock:' . $resource;

			// Atomic attempt to acquire via INSERT:
			$stmt = $pdo->prepare(
				"INSERT INTO `{$tbl_tokens}` (token_key, token_type, owner_id, fencing_token, expires_at)
				 VALUES (?, 'lock', ?, 1, UTC_TIMESTAMP() + INTERVAL 10 SECOND)"
			);
			try {
				$stmt->execute( array( $token_key, $worker ) );
				$result['success'] = true;
				$result['status']  = 'acquired';
				$result['fencing_token'] = 1;
			} catch ( PDOException $e ) {
				// Lock exists; check if lease has expired or attempt atomic takeover:
				$check = $pdo->prepare( "SELECT owner_id, fencing_token, expires_at <= UTC_TIMESTAMP() as is_expired FROM `{$tbl_tokens}` WHERE token_key = ?" );
				$check->execute( array( $token_key ) );
				$row = $check->fetch();

				if ( $row && $row['is_expired'] ) {
					// Expired: attempt atomic CAS takeover
					$new_token = (int) $row['fencing_token'] + 1;
					$takeover = $pdo->prepare(
						"UPDATE `{$tbl_tokens}` SET owner_id = ?, fencing_token = ?, expires_at = UTC_TIMESTAMP() + INTERVAL 10 SECOND
						 WHERE token_key = ? AND fencing_token = ?"
					);
					$takeover->execute( array( $worker, $new_token, $token_key, $row['fencing_token'] ) );
					if ( $takeover->rowCount() > 0 ) {
						$result['success'] = true;
						$result['status']  = 'recovered';
						$result['fencing_token'] = $new_token;
					} else {
						$result['status'] = 'contended';
						$result['error']  = 'CAS takeover collision';
					}
				} else {
					$result['status'] = 'busy';
					$result['current_owner'] = $row['owner_id'] ?? 'unknown';
					$result['fencing_token'] = (int) ( $row['fencing_token'] ?? 0 );
				}
			}

			if ( $result['success'] && $hold_ms > 0 ) {
				usleep( $hold_ms * 1000 );
			}
			break;

		case 'idempotency':
			$key       = $options['key'] ?? 'idem-test';
			$args_hash = $options['args_hash'] ?? hash( 'sha256', 'default-args' );
			$token_key = 'idempotency:' . $key;

			$payload = json_encode( array( 'status' => 'in_progress', 'args_hash' => $args_hash ) );
			$stmt = $pdo->prepare(
				"INSERT INTO `{$tbl_tokens}` (token_key, token_type, owner_id, fencing_token, payload, expires_at)
				 VALUES (?, 'idempotency', ?, 1, ?, UTC_TIMESTAMP() + INTERVAL 60 SECOND)"
			);
			try {
				$stmt->execute( array( $token_key, $worker, $payload ) );
				$result['success'] = true;
				$result['status']  = 'executed';
			} catch ( PDOException $e ) {
				// Duplicate idempotency attempt: read existing payload
				$sel = $pdo->prepare( "SELECT payload FROM `{$tbl_tokens}` WHERE token_key = ?" );
				$sel->execute( array( $token_key ) );
				$row = $sel->fetch();
				$existing = json_decode( (string) ( $row['payload'] ?? '' ), true );

				if ( is_array( $existing ) && ( $existing['args_hash'] ?? '' ) === $args_hash ) {
					$result['success'] = false;
					$result['status']  = 'replayed';
				} else {
					$result['success'] = false;
					$result['status']  = 'conflict';
					$result['error']   = 'Idempotency key reused with conflicting arguments';
				}
			}
			break;

		case 'confirmation':
			$token_key = 'confirmation:' . ( $options['token'] ?? 'test-conf' );

			// Single-use atomic consume: UPDATE ... WHERE used = 0
			$stmt = $pdo->prepare(
				"UPDATE `{$tbl_tokens}` SET used = 1, owner_id = ?
				 WHERE token_key = ? AND used = 0 AND expires_at > UTC_TIMESTAMP()"
			);
			$stmt->execute( array( $worker, $token_key ) );
			if ( $stmt->rowCount() > 0 ) {
				$result['success'] = true;
				$result['status']  = 'consumed';
			} else {
				$result['success'] = false;
				$result['status']  = 'already_consumed_or_expired';
			}
			break;

		case 'journal_cas':
			$journal_id    = (int) ( $options['journal_id'] ?? 0 );
			$target_status = $options['status'] ?? 'committed';

			$stmt = $pdo->prepare(
				"UPDATE `{$tbl_journal}` SET status = ? WHERE id = ? AND status = 'pending'"
			);
			$stmt->execute( array( $target_status, $journal_id ) );
			if ( $stmt->rowCount() > 0 ) {
				$result['success'] = true;
				$result['status']  = $target_status;
			} else {
				$result['success'] = false;
				$result['status']  = 'cas_failed';
			}
			break;

		case 'checkpoint_insert':
			$uuid     = $options['uuid'] ?? 'chk-' . microtime( true );
			$resource = $options['resource'] ?? 'post:1';

			$stmt = $pdo->prepare(
				"INSERT INTO `{$tbl_checkpoints}` (checkpoint_uuid, resource_key, object_type, object_id, state_hash)
				 VALUES (?, ?, 'post', 1, 'test-hash')"
			);
			try {
				$stmt->execute( array( $uuid, $resource ) );
				$result['success'] = true;
				$result['status']  = 'inserted';
			} catch ( PDOException $e ) {
				$result['success'] = false;
				$result['status']  = 'duplicate_uuid_rejected';
				$result['error']   = $e->getMessage();
			}
			break;

		case 'undo':
			$journal_id = (int) ( $options['journal_id'] ?? 0 );

			// Check and atomically claim undo via CAS:
			// status: committed -> undo_in_progress
			$stmt = $pdo->prepare(
				"UPDATE `{$tbl_journal}` SET status = 'undo_in_progress', error_message = ?
				 WHERE id = ? AND status = 'committed'"
			);
			$stmt->execute( array( 'claimed_by:' . $worker, $journal_id ) );
			if ( $stmt->rowCount() > 0 ) {
				if ( $hold_ms > 0 ) {
					usleep( $hold_ms * 1000 );
				}
				// Finish undo
				$fin = $pdo->prepare( "UPDATE `{$tbl_journal}` SET status = 'undone' WHERE id = ? AND status = 'undo_in_progress'" );
				$fin->execute( array( $journal_id ) );
				$result['success'] = true;
				$result['status']  = 'undone';
			} else {
				$result['success'] = false;
				$result['status']  = 'conflict_already_undone_or_in_progress';
			}
			break;

		case 'restore':
			$resource = $options['resource'] ?? 'post:1';
			$token_key = 'restore:' . $resource;

			// Restore lock acquire:
			$stmt = $pdo->prepare(
				"INSERT INTO `{$tbl_tokens}` (token_key, token_type, owner_id, fencing_token, expires_at)
				 VALUES (?, 'restore_lock', ?, 1, UTC_TIMESTAMP() + INTERVAL 15 SECOND)"
			);
			try {
				$stmt->execute( array( $token_key, $worker ) );
				if ( $hold_ms > 0 ) {
					usleep( $hold_ms * 1000 );
				}
				// Clean release:
				$pdo->prepare( "DELETE FROM `{$tbl_tokens}` WHERE token_key = ? AND owner_id = ?" )->execute( array( $token_key, $worker ) );
				$result['success'] = true;
				$result['status']  = 'restored';
			} catch ( PDOException $e ) {
				$result['success'] = false;
				$result['status']  = 'restore_locked_by_other';
			}
			break;

		case 'safe_write':
			$resource = $options['resource'] ?? 'post:1';
			$expected_token = (int) ( $options['fencing_token'] ?? 1 );
			$token_key = 'lock:' . $resource;

			// Verify current lock generation:
			$check = $pdo->prepare( "SELECT fencing_token FROM `{$tbl_tokens}` WHERE token_key = ?" );
			$check->execute( array( $token_key ) );
			$current_token = (int) $check->fetchColumn();

			if ( $current_token === $expected_token ) {
				$result['success'] = true;
				$result['status']  = 'write_committed';
			} else {
				$result['success'] = false;
				$result['status']  = 'fencing_token_mismatch';
				$result['error']   = "Stale token: expected {$expected_token}, found {$current_token}";
			}
			break;

		default:
			$result['error'] = 'Unknown action: ' . $action;
			break;
	}
} catch ( Throwable $t ) {
	$result['error'] = $t->getMessage();
}

echo json_encode( $result ) . "\n";
exit( $result['success'] ? 0 : 1 );
