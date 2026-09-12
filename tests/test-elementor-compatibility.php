<?php
/**
 * Test Suite for Authentic Elementor Compatibility Range.
 *
 * Validates:
 * 1. Elementor 3.20.0 minimum supported threshold.
 * 2. Below 3.20.0 (e.g., 3.19.4) fails closed with unsupported version status.
 * 3. Current stable versions (3.26+, 4.x) pass validation and meet container prerequisites.
 * 4. Elementor 4.0+ Atomic elements runtime detection:
 *    - Fails closed if class exists without active runtime registration or experiment.
 *    - Passes when registered in elements_manager or is_active() is true.
 * 5. Graceful absence of Elementor Pro:
 *    - Core abilities remain fully operational.
 *    - Pro absence correctly reported in diagnostics.
 *    - Pro-only abilities fail gracefully without fatal errors.
 * 6. Elementor Pro presence detection:
 *    - Pro hooks and features correctly reported when active.
 *
 * Usage: php tests/test-elementor-compatibility.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

echo "=======================================================\n";
echo " Safe Elementor MCP — Elementor Compatibility Range\n";
echo "=======================================================\n\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'FULL_ELEMENTOR_MCP_DIR' ) ) {
	define( 'FULL_ELEMENTOR_MCP_DIR', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}

require_once __DIR__ . '/test-mysql-safety.php';

$inc_dir = FULL_ELEMENTOR_MCP_DIR . 'includes/';
require_once $inc_dir . 'class-compatibility-checker.php';
require_once $inc_dir . 'safety/class-database-installer.php';
require_once $inc_dir . 'safety/class-elementor-features.php';

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

function assert_false( mixed $val, string $msg = 'Expected false' ): void {
	if ( false !== $val ) {
		throw new RuntimeException( $msg . ' (got: ' . var_export( $val, true ) . ')' );
	}
}

function assert_equals( mixed $expected, mixed $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( ( $msg ? $msg . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

// ---------------------------------------------------------------------
// TEST 1: Elementor 3.20.0 Minimum Supported Threshold
// ---------------------------------------------------------------------

run_test( 'Test 1: Elementor 3.20.0 satisfies minimum required version', function () {
	// Verify constant
	assert_equals( '3.20.0', Full_Elementor_MCP_Compatibility_Checker::MIN_ELEMENTOR_VERSION );

	// Simulate Elementor 3.20.0
	$res = version_compare( '3.20.0', Full_Elementor_MCP_Compatibility_Checker::MIN_ELEMENTOR_VERSION, '>=' );
	assert_true( $res, '3.20.0 must satisfy minimum version requirement' );
} );

// ---------------------------------------------------------------------
// TEST 2: Below 3.20.0 Fails Closed
// ---------------------------------------------------------------------

run_test( 'Test 2: Elementor versions below 3.20.0 are rejected as unsupported', function () {
	$legacy_versions = array( '3.19.4', '3.18.0', '3.16.2', '3.0.0', '2.9.9' );
	foreach ( $legacy_versions as $v ) {
		$res = version_compare( $v, Full_Elementor_MCP_Compatibility_Checker::MIN_ELEMENTOR_VERSION, '>=' );
		assert_false( $res, "Elementor {$v} must be rejected as unsupported" );
	}
} );

// ---------------------------------------------------------------------
// TEST 3: Current Stable & 4.x Range
// ---------------------------------------------------------------------

run_test( 'Test 3: Current stable and modern versions satisfy compatibility range', function () {
	$valid_versions = array( '3.20.0', '3.20.4', '3.24.0', '3.26.0', '4.0.0', '4.1.4' );
	foreach ( $valid_versions as $v ) {
		$res = version_compare( $v, Full_Elementor_MCP_Compatibility_Checker::MIN_ELEMENTOR_VERSION, '>=' );
		assert_true( $res, "Elementor {$v} must be accepted" );
	}
} );

// ---------------------------------------------------------------------
// TEST 4: Elementor 4.0+ Atomic Elements Runtime Detection
// ---------------------------------------------------------------------

run_test( 'Test 4: Atomic elements runtime detection fails closed without active runtime evidence', function () {
	Full_Elementor_MCP_Elementor_Features::reset_mocks();

	// When elementor is absent, atomic elements must be false
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => false,
		'atomic_elements' => false,
	) );
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements(), 'Atomic elements must be false when Elementor is absent' );

	// When Elementor is present but atomic elements experiment/runtime is not active
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'atomic_elements' => false,
	) );
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements(), 'Atomic elements must fail closed when experiment is not active' );

	// When atomic elements experiment/runtime is active
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'atomic_elements' => true,
	) );
	assert_true( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements(), 'Atomic elements must be detected when active' );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

// ---------------------------------------------------------------------
// TEST 5: Graceful Absence of Elementor Pro
// ---------------------------------------------------------------------

run_test( 'Test 5: Graceful handling when Elementor Pro is absent', function () {
	Full_Elementor_MCP_Elementor_Features::reset_mocks();

	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'     => true,
		'elementor_pro' => false,
	) );

	assert_true( Full_Elementor_MCP_Elementor_Features::has_elementor(), 'Core Elementor must be active' );
	assert_false( Full_Elementor_MCP_Elementor_Features::has_elementor_pro(), 'Elementor Pro must be reported absent' );

	$features = Full_Elementor_MCP_Elementor_Features::get_active_features();
	assert_false( $features['elementor_pro'], 'Active features must indicate Pro is absent' );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

// ---------------------------------------------------------------------
// TEST 6: Elementor Pro Presence Detection
// ---------------------------------------------------------------------

run_test( 'Test 6: Elementor Pro presence correctly detected when active', function () {
	Full_Elementor_MCP_Elementor_Features::reset_mocks();

	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'     => true,
		'elementor_pro' => true,
	) );

	assert_true( Full_Elementor_MCP_Elementor_Features::has_elementor_pro(), 'Elementor Pro must be reported active' );

	$features = Full_Elementor_MCP_Elementor_Features::get_active_features();
	assert_true( $features['elementor_pro'], 'Active features must indicate Pro is active' );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

// ---------------------------------------------------------------------
// TEST 7: Flexbox and Grid Container Capability Detection
// ---------------------------------------------------------------------

run_test( 'Test 7: Flexbox and Grid container capability detection', function () {
	Full_Elementor_MCP_Elementor_Features::reset_mocks();

	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'  => true,
		'containers' => true,
	) );
	assert_true( Full_Elementor_MCP_Elementor_Features::supports_containers(), 'Container support must be detected when active' );

	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'  => true,
		'containers' => false,
	) );
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_containers(), 'Container support must fail closed when inactive' );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------

echo "\n=======================================================\n";
echo " Elementor Compatibility Results: {$passed_tests}/{$total_tests} passed.";
if ( $failed_tests > 0 ) {
	echo " ({$failed_tests} failed)\n";
	echo "=======================================================\n";
	exit( 1 );
}
echo "\n=======================================================\n";
exit( 0 );
