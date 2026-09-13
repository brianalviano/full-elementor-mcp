<?php
/**
 * Worker Process for Process-Level Crash and Abrupt Exit Scenarios.
 *
 * Executes REAL production safety execution paths and crashes at authentic failure points
 * via test fault injection hooks in Full_Elementor_MCP_Mutation_Middleware and Checkpoint_Manager:
 * - wal_before_write: enters real middleware, captures before-state, acquires real lock,
 *   writes real WAL journal, and crashes abruptly after WAL begin before target write.
 * - write_before_commit: executes real middleware, writes persistent state via Safe_Writes,
 *   and crashes abruptly before journal commit.
 * - create_crash: executes real middleware, creates object and binds created_object_id
 *   via Safe_Writes::insert_post(), and crashes abruptly before journal commit.
 * - restore_crash: captures real encrypted checkpoint, initiates real restore through
 *   Checkpoint_Manager::restore(), writes restore WAL, and crashes abruptly mid-restore.
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

require_once __DIR__ . '/bootstrap-real-wordpress.php';
bootstrap_real_wordpress();
bootstrap_safety_subsystem();

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

// Register execute callbacks wrapped in middleware:
Full_Elementor_MCP_Mutation_Middleware::wrap_ability( 'full-elementor-mcp/update-element', array(
	'execute_callback' => function ( array $args ) use ( $object_id ) {
		// Mutate persistent state safely
		Full_Elementor_MCP_Safe_Writes::update_post_meta( $object_id, '_elementor_data', '[{"id":"elem1","elType":"section"}]' );
		return array( 'success' => true, 'post_id' => $object_id );
	},
) );

Full_Elementor_MCP_Mutation_Middleware::wrap_ability( 'full-elementor-mcp/create-page', array(
	'execute_callback' => function ( array $args ) use ( $object_id ) {
		$created_id = Full_Elementor_MCP_Safe_Writes::insert_post( array(
			'post_title'  => $args['post_title'] ?? 'Crash Test Page',
			'post_type'   => 'page',
			'post_status' => 'draft',
		) );
		return array( 'post_id' => $created_id, 'id' => $created_id );
	},
) );

switch ( $scenario ) {

	case 'wal_before_write':
		Full_Elementor_MCP_Mutation_Middleware::set_test_fault_hook( function ( string $point, array $ctx ) {
			if ( 'after_wal_begin' === $point ) {
				fwrite( STDERR, "Worker dying abruptly in WAL before target write\n" );
				exit( 255 );
			}
		} );

		Full_Elementor_MCP_Mutation_Middleware::execute(
			'full-elementor-mcp/update-element',
			array(
				'post_id'    => $object_id,
				'element_id' => 'elem1',
				'settings'   => array( 'title' => 'Updated' ),
			)
		);
		break;

	case 'write_before_commit':
		Full_Elementor_MCP_Mutation_Middleware::set_test_fault_hook( function ( string $point, array $ctx ) {
			if ( 'before_journal_commit' === $point ) {
				fwrite( STDERR, "Worker dying abruptly after write before journal commit\n" );
				exit( 255 );
			}
		} );

		Full_Elementor_MCP_Mutation_Middleware::execute(
			'full-elementor-mcp/update-element',
			array(
				'post_id'    => $object_id,
				'element_id' => 'elem1',
				'settings'   => array( 'title' => 'Updated' ),
			)
		);
		break;

	case 'create_crash':
		Full_Elementor_MCP_Mutation_Middleware::set_test_fault_hook( function ( string $point, array $ctx ) {
			if ( 'before_journal_commit' === $point ) {
				fwrite( STDERR, "Worker dying abruptly after object creation before commit\n" );
				exit( 255 );
			}
		} );

		Full_Elementor_MCP_Mutation_Middleware::execute(
			'full-elementor-mcp/create-page',
			array(
				'post_title' => 'Crash Page ' . $object_id,
			)
		);
		break;

	case 'restore_crash':
		// First capture a valid checkpoint for the resource:
		$chk = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save(
			$resource,
			'manual',
			array(
				'label' => 'Pre-crash baseline',
			)
		);

		if ( is_wp_error( $chk ) ) {
			fwrite( STDERR, "Failed to create checkpoint: " . $chk->get_error_message() . "\n" );
			exit( 1 );
		}

		// Mutate state so live state differs from checkpoint to trigger real restore:
		update_post_meta( $object_id, '_elementor_data', '[{"id":"altered","elType":"widget"}]' );

		Full_Elementor_MCP_Checkpoint_Manager::set_test_fault_hook( function ( string $point, array $ctx ) {
			if ( 'after_restore_wal_begin' === $point ) {
				fwrite( STDERR, "Worker dying abruptly during checkpoint restore\n" );
				exit( 255 );
			}
		} );

		Full_Elementor_MCP_Checkpoint_Manager::restore( $chk['checkpoint_uuid'] );
		break;
}

exit( 0 );
