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

require_once __DIR__ . '/bootstrap-real-wordpress.php';
bootstrap_real_wordpress();
bootstrap_safety_subsystem();

$options = getopt( '', array( 'grace:' ) );
$grace   = (int) ( $options['grace'] ?? 10 );

$reports = Full_Elementor_MCP_Journal::recover_pending( $grace );

echo json_encode( array(
	'success' => true,
	'reports' => $reports,
) ) . "\n";

exit( 0 );
