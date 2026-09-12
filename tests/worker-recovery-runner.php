<?php
/**
 * Fresh PHP Process Runner for Crash Recovery.
 *
 * Bootstraps the safety subsystem in a pristine PHP process to recover
 * abandoned states left by crashed worker processes using real production methods.
 *
 * Usage: php tests/worker-recovery-runner.php --grace=10
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

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

$options = getopt( '', array( 'grace:' ) );
$grace   = (int) ( $options['grace'] ?? 10 );

$reports = Full_Elementor_MCP_Journal::recover_pending( $grace );

echo json_encode( array(
	'success' => true,
	'reports' => $reports,
) ) . "\n";

exit( 0 );
