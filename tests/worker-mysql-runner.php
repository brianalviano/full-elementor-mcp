<?php
/**
 * Worker Process for Real MySQL Multi-Process Concurrency Testing.
 *
 * Runs as an independent OS process with its own independent MySQL connection.
 * Bootstraps the real Safe Elementor MCP safety runtime and invokes production APIs:
 * - Full_Elementor_MCP_Lock_Manager
 * - Full_Elementor_MCP_Idempotency_Manager
 * - Full_Elementor_MCP_Confirmation_Manager
 * - Full_Elementor_MCP_Journal
 * - Full_Elementor_MCP_Checkpoint_Manager
 * - Full_Elementor_MCP_Undo_Manager
 * - Full_Elementor_MCP_Safe_Writes
 *
 * Output is emitted as a single JSON object to STDOUT.
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

// Ensure safety test harness and real MySQL $wpdb wrapper are initialized
require_once __DIR__ . '/test-mysql-safety.php';

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

$result = array(
	'worker'  => $worker,
	'action'  => $action,
	'success' => false,
);

try {
	switch ( $action ) {

		case 'lock':
			$resource = $options['resource'] ?? 'post:1';
			$res      = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource, $worker, 10 );

			if ( ! is_wp_error( $res ) && ! empty( $res['acquired'] ) ) {
				$result['success']       = true;
				$result['status']        = 'acquired';
				$result['fencing_token'] = (int) $res['fencing_token'];

				if ( $hold_ms > 0 ) {
					usleep( $hold_ms * 1000 );
				}
			} else {
				$result['success'] = false;
				$result['status']  = 'busy';
				global $wpdb;
				$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource );
				$tokens_t = Full_Elementor_MCP_Database_Installer::get_tokens_table();
				$lock_row = $wpdb->get_row(
					$wpdb->prepare( "SELECT owner_id, fencing_token FROM {$tokens_t} WHERE token_key = %s", $lock_key ),
					ARRAY_A
				);
				$result['current_owner'] = ! empty( $lock_row['owner_id'] ) ? $lock_row['owner_id'] : 'unknown';
				$result['fencing_token'] = ! empty( $lock_row['fencing_token'] ) ? (int) $lock_row['fencing_token'] : 0;
			}
			break;

		case 'idempotency':
			$key       = $options['key'] ?? 'idem-test';
			$args_hash = $options['args_hash'] ?? '';
			$args      = ! empty( $args_hash ) ? array( 'hash' => $args_hash ) : array( 'default' => 'args' );
			$ability   = 'full-elementor-mcp/update-element';

			$claim = Full_Elementor_MCP_Idempotency_Manager::claim(
				$key,
				$ability,
				1,
				null,
				$args,
				$worker
			);

			// In concurrent duplicate races, if another worker is in progress, poll briefly for completion:
			$start_wait = microtime( true );
			while ( is_wp_error( $claim ) && 'idempotency_in_progress' === $claim->get_error_code() && ( microtime( true ) - $start_wait ) < 2.0 ) {
				usleep( 30000 ); // 30ms
				$claim = Full_Elementor_MCP_Idempotency_Manager::claim(
					$key,
					$ability,
					1,
					null,
					$args,
					$worker
				);
			}

			if ( is_wp_error( $claim ) ) {
				$result['success'] = false;
				$result['status']  = 'conflict';
				$result['error']   = $claim->get_error_code();
			} elseif ( 'completed' === ( $claim['status'] ?? '' ) ) {
				$result['success'] = false;
				$result['status']  = 'replayed';
				$result['data']    = $claim['result'] ?? array();
			} elseif ( 'claimed' === ( $claim['status'] ?? '' ) ) {
				if ( $hold_ms > 0 ) {
					usleep( $hold_ms * 1000 );
				}
				$res_payload = array( 'status' => 'success', 'worker' => $worker );
				Full_Elementor_MCP_Idempotency_Manager::complete(
					$claim['token_key'],
					$worker,
					$res_payload
				);
				$result['success'] = true;
				$result['status']  = 'executed';
				$result['data']    = $res_payload;
			} else {
				$result['success'] = false;
				$result['status']  = 'busy';
			}
			break;

		case 'confirmation':
			$token        = $options['token'] ?? 'test-conf';
			$action_name  = 'full-elementor-mcp/delete-page';
			$resource_key = 'post:42';
			$args         = array( 'post_id' => 42, 'force' => true );

			$consume_res = Full_Elementor_MCP_Confirmation_Manager::consume(
				$token,
				$action_name,
				$args,
				1,
				null,
				$resource_key
			);

			if ( is_wp_error( $consume_res ) ) {
				$result['success'] = false;
				$result['status']  = 'already_consumed_or_expired';
				$result['error']   = $consume_res->get_error_code();
			} else {
				$result['success'] = true;
				$result['status']  = 'consumed';
			}
			break;

		case 'journal_cas':
			$journal_id    = (int) ( $options['journal_id'] ?? 0 );
			$target_status = $options['status'] ?? 'committed';
			$fencing_token = (int) ( $options['fencing_token'] ?? 1 );

			if ( 'committed' === $target_status ) {
				$cas_res = Full_Elementor_MCP_Journal::commit(
					$journal_id,
					array(),
					$fencing_token
				);
			} else {
				$cas_res = Full_Elementor_MCP_Journal::mark_failed(
					$journal_id,
					'failed_by_' . $worker,
					$fencing_token
				);
			}

			if ( is_wp_error( $cas_res ) ) {
				$result['success'] = false;
				$result['status']  = 'cas_failed';
				$result['error']   = $cas_res->get_error_code();
			} else {
				$result['success'] = true;
				$result['status']  = $target_status;
			}
			break;

		case 'checkpoint_insert':
			$uuid     = $options['uuid'] ?? ( 'chk-' . bin2hex( random_bytes( 4 ) ) );
			$resource = $options['resource'] ?? 'global:elementor-kit-state';

			$chk_res = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save(
				$resource,
				'manual',
				array(
					'checkpoint_uuid' => $uuid,
					'label'           => 'Collision test ' . $worker,
					'state'           => array( 'strategy' => 'global', 'active_kit_id' => 1, 'kit_settings' => array() ),
					'state_schema'    => 'checkpoint_strategy_v2',
				)
			);

			if ( is_wp_error( $chk_res ) ) {
				$result['success'] = false;
				$result['status']  = 'duplicate_uuid_rejected';
				$result['error']   = $chk_res->get_error_code();
			} else {
				$result['success'] = true;
				$result['status']  = 'inserted';
				$result['uuid']    = $chk_res['checkpoint_uuid'] ?? $uuid;
			}
			break;

		case 'undo':
			$journal_id = (int) ( $options['journal_id'] ?? 0 );

			$undo_res = Full_Elementor_MCP_Undo_Manager::undo_change(
				$journal_id,
				array( 'user_id' => 1 )
			);

			if ( is_wp_error( $undo_res ) ) {
				$result['success'] = false;
				$result['status']  = 'conflict_already_undone_or_in_progress';
				$result['error']   = $undo_res->get_error_code();
			} else {
				$result['success'] = true;
				$result['status']  = 'undone';
				$result['data']    = $undo_res;
			}
			break;

		case 'restore':
			$resource = $options['resource'] ?? 'post:1';

			// Production restore engine acquires canonical restore lock
			$res = Full_Elementor_MCP_Lock_Manager::acquire_lock(
				'restore:' . $resource,
				$worker,
				15
			);

			if ( ! is_wp_error( $res ) && ! empty( $res['acquired'] ) ) {
				if ( $hold_ms > 0 ) {
					usleep( $hold_ms * 1000 );
				}
				Full_Elementor_MCP_Lock_Manager::release_lock(
					'restore:' . $resource,
					$worker,
					(int) $res['fencing_token']
				);
				$result['success'] = true;
				$result['status']  = 'restored';
			} else {
				$result['success'] = false;
				$result['status']  = 'restore_locked_by_other';
				$result['error']   = is_wp_error( $res ) ? $res->get_error_code() : 'locked';
			}
			break;

		case 'safe_write':
			$resource       = $options['resource'] ?? 'post:1';
			$expected_token = (int) ( $options['fencing_token'] ?? 1 );

			// Enter mutation context with stale fencing token
			$ctx = Full_Elementor_MCP_Mutation_Context::enter( array(
				'ability'       => 'full-elementor-mcp/update-element',
				'request_uuid'  => wp_generate_uuid4(),
				'user_id'       => 1,
				'resource_key'  => $resource,
				'object_id'     => 1,
				'owner_id'      => $worker,
				'fencing_token' => $expected_token,
				'journal_id'    => 1,
				'is_dry_run'    => false,
				'is_rollback'   => false,
				'is_readonly'   => false,
				'is_create'     => false,
			) );

			$write_res = Full_Elementor_MCP_Safe_Writes::assert_write_boundary( $resource );
			Full_Elementor_MCP_Mutation_Context::leave( $ctx );

			if ( is_wp_error( $write_res ) ) {
				$result['success'] = false;
				$result['status']  = 'fencing_token_mismatch';
				$result['error']   = $write_res->get_error_code();
			} else {
				$result['success'] = true;
				$result['status']  = 'write_committed';
			}
			break;

		default:
			$result['error'] = 'Unknown action: ' . $action;
			break;
	}
} catch ( Throwable $t ) {
	$result['error'] = $t->getMessage() . ' (' . $t->getFile() . ':' . $t->getLine() . ')';
}

echo json_encode( $result ) . "\n";
exit( $result['success'] ? 0 : 1 );
