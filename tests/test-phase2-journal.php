<?php
/**
 * Standalone Test Suite for Phase 2: Mutation Strategy Registry + Write-Ahead Journal.
 *
 * Can be executed via CLI: `php tests/test-phase2-journal.php`
 *
 * Verifies:
 * 1. Mutation Strategy Registry contracts, validation, lookup, fail-closed handling, and canonical resource keys.
 * 2. Write-Ahead Journal (WAL) durable state persistence BEFORE mutation.
 * 3. Deterministic SHA-256 state hashing and canonicalization.
 * 4. Sensitive credential redaction in state and error messages.
 * 5. Strict journal state machine transitions (rejecting illegal transitions).
 * 6. Conditional atomic commit and DB failure resilience (never reporting false success).
 * 7. Created object tracking and lifecycle.
 * 8. Strategy-driven rollback for updates and creations.
 * 9. Conflict detection preventing rollback from clobbering diverged live state.
 * 10. Fencing token verification blocking stale owners from restoring state.
 * 11. Crash recovery foundation (grace period + expired lease validation, fail-closed on unsupported).
 * 12. 100% PHP 8.0+ compatibility.
 *
 * @package Full_Elementor_MCP
 */

declare(strict_types=1);

// Bootstrap minimal WordPress test harness if not running inside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public mixed $data;
		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

$GLOBALS['wp_test_options'] = array();
$GLOBALS['wp_test_current_user_id'] = 1;
$GLOBALS['wp_test_user_caps'] = array( 'edit_posts' => true, 'manage_options' => true );

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['wp_test_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, mixed $value ): bool {
		$GLOBALS['wp_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['wp_test_options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return $GLOBALS['wp_test_current_user_id'] ?? 1;
	}
}

$GLOBALS['mock_post_storage']   = array();
$GLOBALS['mock_post_meta']      = array();
$GLOBALS['mock_created_objects'] = array();
$GLOBALS['wp_test_filters']     = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['wp_test_filters'][ $hook_name ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( string $hook_name, int|false $priority = false ): bool {
		unset( $GLOBALS['wp_test_filters'][ $hook_name ] );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, ...$args ): mixed {
		if ( ! empty( $GLOBALS['wp_test_filters'][ $hook_name ] ) ) {
			foreach ( $GLOBALS['wp_test_filters'][ $hook_name ] as $entry ) {
				$cb    = $entry['callback'];
				$value = $cb( $value, ...$args );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		if ( '_elementor_data' === $key ) {
			return isset( $GLOBALS['mock_post_storage'][ $post_id ] )
				? wp_json_encode( $GLOBALS['mock_post_storage'][ $post_id ] )
				: '';
		}
		return $GLOBALS['mock_post_meta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		if ( '_elementor_data' === $key ) {
			$decoded = is_string( $value ) ? json_decode( (string) $value, true ) : $value;
			$GLOBALS['mock_post_storage'][ $post_id ] = $decoded;
			return true;
		}
		$GLOBALS['mock_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( int $post_id ): mixed {
		$GLOBALS['mock_created_objects'][ $post_id ] = 'trashed';
		return true;
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int $post_id ): string|false {
		return 'publish';
	}
}

/**
 * High-fidelity Mock WPDB backed by in-memory SQLite with full MySQL translation.
 */
class Phase2_Mock_WPDB {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public bool $simulate_write_failure = false;
	public bool $simulate_zero_affected_rows = false;
	public ?\Closure $on_before_query = null;
	public \PDO $pdo;

	public function __construct() {
		$this->pdo = new \PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		if ( method_exists( $this->pdo, 'sqliteCreateFunction' ) ) {
			call_user_func(
				array( $this->pdo, 'sqliteCreateFunction' ),
				'UTC_TIMESTAMP',
				static function () {
					if ( isset( $GLOBALS['wp_test_mock_now'] ) ) {
						return gmdate( 'Y-m-d H:i:s', $GLOBALS['wp_test_mock_now'] );
					}
					return gmdate( 'Y-m-d H:i:s' );
				}
			);
		}
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function prepare( string $query, ...$args ): string {
		if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$val   = is_numeric( $arg ) ? (string) $arg : $this->pdo->quote( (string) $arg );
			$query = preg_replace( '/%[sdf]/', $val, $query, 1 );
		}
		return $query;
	}

	public function query( string $query ): int|bool {
		if ( $this->on_before_query ) {
			( $this->on_before_query )( $query, $this );
		}

		if ( $this->simulate_write_failure && preg_match( '/^(?:INSERT|UPDATE|REPLACE|DELETE)/i', trim( $query ) ) ) {
			return false;
		}

		if ( $this->simulate_zero_affected_rows && preg_match( '/^UPDATE/i', trim( $query ) ) ) {
			return 0;
		}

		try {
			if ( str_contains( $query, 'CREATE TABLE' ) ) {
				$table_name = '';
				if ( preg_match( '/CREATE\s+TABLE\s+([^\s(]+)/i', $query, $tm ) ) {
					$table_name = trim( $tm[1], '`' );
				}
				$indexes_to_create = array();
				if ( ! empty( $table_name ) && preg_match_all( '/\bKEY\s+([a-zA-Z0-9_]+)\s*\(([^)]+)\)/i', $query, $km, PREG_SET_ORDER ) ) {
					foreach ( $km as $key_match ) {
						$indexes_to_create[] = "CREATE INDEX IF NOT EXISTS {$table_name}_{$key_match[1]} ON {$table_name} ({$key_match[2]});";
					}
				}

				$q = preg_replace( '/id\s+bigint\([^)]+\)\s+unsigned\s+NOT\s+NULL\s+auto_increment/i', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $query );
				$q = preg_replace( '/id\s+BIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT/i', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $q );
				$q = preg_replace( '/bigint\([^)]+\)\s*(?:unsigned)?/i', 'INTEGER', $q );
				$q = preg_replace( '/int\([^)]+\)\s*(?:unsigned)?/i', 'INTEGER', $q );
				$q = preg_replace( '/tinyint\([^)]+\)\s*(?:unsigned)?/i', 'INTEGER', $q );
				$q = preg_replace( '/datetime/i', 'TEXT', $q );
				$q = preg_replace( '/longtext|text/i', 'TEXT', $q );
				$q = preg_replace( '/varchar\([^)]+\)/i', 'TEXT', $q );
				$q = preg_replace( '/on\s+update\s+CURRENT_TIMESTAMP/i', '', $q );
				if ( str_contains( $q, 'AUTOINCREMENT' ) ) {
					$q = preg_replace( '/PRIMARY\s+KEY\s*\([^)]+\),?/i', '', $q );
				}
				$q = preg_replace( '/(?<!PRIMARY\s)\bKEY\s+[a-zA-Z0-9_]+\s*\([^)]+\),?/i', '', $q );
				$q = preg_replace( '/,\s*\)/', ')', $q );
				$q = preg_replace( '/(?:\)\s*(?:DEFAULT\s+CHARACTER\s+SET|COLLATE|ENGINE)[^;]*;|\)\s*;)/i', ');', $q );

				$res = $this->pdo->exec( $q );
				foreach ( $indexes_to_create as $idx_q ) {
					try {
						$this->pdo->exec( $idx_q );
					} catch ( \Throwable $e ) {
						// Ignore index creation errors if any.
					}
				}
				return $res;
			}

			$query = $this->translate_query_for_sqlite( $query );
			$res   = $this->pdo->exec( $query );

			if ( preg_match( '/^INSERT\s+/i', trim( $query ) ) ) {
				$this->insert_id = (int) $this->pdo->lastInsertId();
			}

			return $res;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private function translate_query_for_sqlite( string $query ): string {
		$query = preg_replace( '/DATE_ADD\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+SECOND\s*\)/i', "datetime($1, '+$2 seconds')", $query );
		$query = preg_replace( '/DATE_SUB\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+SECOND\s*\)/i', "datetime($1, '-$2 seconds')", $query );
		$query = preg_replace( '/GREATEST\s*\(\s*([^,]+?)\s*,\s*([^)]+?)\s*\)/i', 'max($1, $2)', $query );
		return $query;
	}

	public function get_row( string $query, string $output = 'OBJECT' ): mixed {
		try {
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			$row   = $stmt ? $stmt->fetch( \PDO::FETCH_ASSOC ) : null;
			if ( ! $row ) {
				return null;
			}
			if ( 'ARRAY_A' === $output ) {
				return $row;
			}
			return (object) $row;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function get_var( string $query, int $x = 0, int $y = 0 ): mixed {
		try {
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			return $stmt ? $stmt->fetchColumn( $x ) : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function get_results( string $query, string $output = 'OBJECT' ): mixed {
		try {
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			$rows  = $stmt ? $stmt->fetchAll( \PDO::FETCH_ASSOC ) : array();
			if ( 'ARRAY_A' === $output ) {
				return $rows;
			}
			return array_map( static fn( $r ) => (object) $r, $rows );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public function get_col( string $query, int $x = 0 ): array {
		try {
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			return $stmt ? $stmt->fetchAll( \PDO::FETCH_COLUMN, $x ) : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}
}

// Instantiate global $wpdb.
global $wpdb;
$wpdb = new Phase2_Mock_WPDB();

// Require safety subsystem classes.
require_once __DIR__ . '/../includes/safety/class-database-installer.php';
require_once __DIR__ . '/../includes/safety/class-safety-settings.php';
require_once __DIR__ . '/../includes/safety/class-lock-manager.php';
require_once __DIR__ . '/../includes/safety/class-mutation-registry.php';
require_once __DIR__ . '/../includes/safety/class-journal.php';

// Install safety database tables in mock SQLite.
Full_Elementor_MCP_Database_Installer::install();

// Test runner state.
$tests_passed = 0;
$tests_failed = 0;

function run_test( string $description, callable $callback ): void {
	global $tests_passed, $tests_failed, $wpdb;

	// Reset any simulated DB failures.
	$wpdb->simulate_write_failure         = false;
	$wpdb->simulate_zero_affected_rows    = false;
	$wpdb->on_before_query                = null;
	$GLOBALS['wp_test_filters']           = array();
	unset( $GLOBALS['wp_test_mock_now'] );

	try {
		$callback();
		echo " \033[32m[PASS]\033[0m {$description}\n";
		$tests_passed++;
	} catch ( \Throwable $e ) {
		echo " \033[31m[FAIL]\033[0m {$description}\n";
		echo "        \033[33mError: {$e->getMessage()}\033[0m at {$e->getFile()}:{$e->getLine()}\n";
		$tests_failed++;
	}
}

function assert_true( bool $condition, string $message = 'Expected true, got false' ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
}

function assert_false( bool $condition, string $message = 'Expected false, got true' ): void {
	if ( $condition ) {
		throw new \RuntimeException( $message );
	}
}

function assert_equals( mixed $expected, mixed $actual, string $message = '' ): void {
	if ( $expected !== $actual ) {
		$exp_str = is_scalar( $expected ) ? (string) $expected : json_encode( $expected );
		$act_str = is_scalar( $actual ) ? (string) $actual : json_encode( $actual );
		throw new \RuntimeException( "{$message} [Expected: {$exp_str}, Got: {$act_str}]" );
	}
}

function assert_is_wp_error( mixed $thing, string $message = 'Expected WP_Error' ): void {
	if ( ! is_wp_error( $thing ) ) {
		throw new \RuntimeException( $message );
	}
}

echo "\n=======================================================\n";
echo " Full Elementor MCP — Phase 2 Test Suite (Journal & WAL)\n";
echo "=======================================================\n\n";

// =========================================================================
// 1. REGISTRY TESTS
// =========================================================================

run_test( 'Registry: register valid strategy and verify lookup', function () {
	$strategy = array(
		'ability'               => 'test/custom-widget',
		'action'                => 'update',
		'object_type'           => 'post',
		'category'              => Full_Elementor_MCP_Mutation_Registry::CATEGORY_ELEMENTOR_DATA,
		'resource_key_resolver' => static fn( $args ) => 'post:' . ( $args['post_id'] ?? 0 ),
		'object_id_resolver'    => static fn( $args ) => (int) ( $args['post_id'] ?? 0 ),
		'capture_before'        => static fn( $id, $args ) => array( 'elements' => array( 'widget-1' ) ),
		'restore_before'        => static fn( $id, $state, $entry ) => true,
		'supports_rollback'     => true,
	);

	$reg = Full_Elementor_MCP_Mutation_Registry::register( $strategy );
	assert_true( true === $reg, 'Strategy registration must return true' );
	assert_true( Full_Elementor_MCP_Mutation_Registry::has( 'test/custom-widget' ), 'Registry must contain strategy' );

	$fetched = Full_Elementor_MCP_Mutation_Registry::get( 'test/custom-widget' );
	assert_true( null !== $fetched, 'Fetched strategy must not be null' );
	assert_equals( 'post', $fetched['object_type'] );
	assert_true( $fetched['supports_rollback'] );
} );

run_test( 'Registry: invalid strategy contract fails validation', function () {
	// Missing 'restore_before' and 'resource_key_resolver'.
	$invalid = array(
		'ability'     => 'test/broken-widget',
		'action'      => 'update',
		'object_type' => 'post',
		'category'    => Full_Elementor_MCP_Mutation_Registry::CATEGORY_ELEMENTOR_DATA,
	);

	$res = Full_Elementor_MCP_Mutation_Registry::register( $invalid );
	assert_is_wp_error( $res, 'Invalid contract must return WP_Error' );
	assert_equals( 'invalid_strategy_descriptor', $res->get_error_code() );
} );

run_test( 'Registry: duplicate registration cleanly updates descriptor', function () {
	$strategy_v1 = array(
		'ability'               => 'test/duplicate-widget',
		'action'                => 'update',
		'object_type'           => 'post',
		'category'              => Full_Elementor_MCP_Mutation_Registry::CATEGORY_ELEMENTOR_DATA,
		'resource_key_resolver' => static fn( $args ) => 'post:' . ( $args['post_id'] ?? 0 ),
		'object_id_resolver'    => static fn( $args ) => (int) ( $args['post_id'] ?? 0 ),
		'capture_before'        => static fn( $id, $args ) => array( 'v' => 1 ),
		'restore_before'        => static fn( $id, $state, $entry ) => true,
		'supports_rollback'     => false,
	);
	Full_Elementor_MCP_Mutation_Registry::register( $strategy_v1 );
	assert_false( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'test/duplicate-widget' ) );

	$strategy_v2 = array_merge( $strategy_v1, array( 'supports_rollback' => true ) );
	Full_Elementor_MCP_Mutation_Registry::register( $strategy_v2 );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'test/duplicate-widget' ) );
} );

run_test( 'Registry: unknown strategy fails closed', function () {
	assert_false( Full_Elementor_MCP_Mutation_Registry::has( 'non_existent/ability' ) );
	assert_false( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'non_existent/ability' ) );

	$key = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( 'non_existent/ability', array( 'post_id' => 10 ) );
	assert_is_wp_error( $key );
	assert_equals( 'unknown_mutation_strategy', $key->get_error_code() );
} );

run_test( 'Registry: canonical resource keys are deterministic and formatted correctly', function () {
	$k1 = Full_Elementor_MCP_Mutation_Registry::build_resource_key( 'post', 42 );
	$k2 = Full_Elementor_MCP_Mutation_Registry::build_resource_key( 'post', '42' );
	$k3 = Full_Elementor_MCP_Mutation_Registry::build_resource_key( 'post', 43 );
	$k4 = Full_Elementor_MCP_Mutation_Registry::build_resource_key( 'template', 42 );

	assert_equals( 'post:42', $k1 );
	assert_equals( 'post:42', $k2 );
	assert_true( $k1 === $k2, 'Identical entities must produce identical resource keys' );
	assert_true( $k1 !== $k3, 'Different IDs must produce different resource keys' );
	assert_true( $k1 !== $k4, 'Different types must produce different resource keys' );
} );

run_test( 'Registry: core strategies initialize with proper rollback classifications', function () {
	// Query abilities that modify document data: rollback must be true.
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/add-widget' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/update-widget' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/delete-widget' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/create-page' ) );

	// Global / Custom Code mutations: rollback must be false (unsupported).
	assert_false( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/update-global-colors' ) );
	assert_false( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/add-custom-code' ) );
} );

// =========================================================================
// 2. JOURNAL STATE MACHINE & WAL LIFECYCLE TESTS
// =========================================================================

run_test( 'Journal: state machine permits only legal transitions', function () {
	assert_true( Full_Elementor_MCP_Journal::can_transition( 'pending', 'committed' ) );
	assert_true( Full_Elementor_MCP_Journal::can_transition( 'pending', 'rolled_back' ) );
	assert_true( Full_Elementor_MCP_Journal::can_transition( 'pending', 'failed' ) );
	assert_true( Full_Elementor_MCP_Journal::can_transition( 'committed', 'rolled_back' ) );
	assert_true( Full_Elementor_MCP_Journal::can_transition( 'failed', 'rolled_back' ) );

	// Strictly prohibited transitions.
	assert_false( Full_Elementor_MCP_Journal::can_transition( 'committed', 'pending' ) );
	assert_false( Full_Elementor_MCP_Journal::can_transition( 'rolled_back', 'committed' ) );
	assert_false( Full_Elementor_MCP_Journal::can_transition( 'failed', 'committed' ) );
	assert_false( Full_Elementor_MCP_Journal::can_transition( 'rolled_back', 'pending' ) );
} );

run_test( 'Journal: begin stores durable row before mutation with deterministic hash', function () {
	$state = array(
		'elements' => array(
			array(
				'id'       => 'c1',
				'elType'   => 'container',
				'settings' => array( 'padding' => 10 ),
			),
		),
	);

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 101,
		'fencing_token' => 1,
		'before_state'  => $state,
		'user_id'       => 5,
	) );

	assert_true( is_int( $journal_id ) && $journal_id > 0, 'begin() must return positive integer ID' );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_true( null !== $entry );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_PENDING, $entry['status'] );
	assert_equals( 'full-elementor-mcp/add-widget', $entry['ability'] );
	assert_equals( '101', (string) $entry['object_id'] );
	assert_equals( '1', (string) $entry['fencing_token'] );
	assert_equals( '5', (string) $entry['user_id'] );

	$expected_hash = Full_Elementor_MCP_Journal::hash_state( $state );
	assert_equals( $expected_hash, $entry['before_hash'] );
	assert_true( 64 === strlen( $entry['before_hash'] ) );
} );

run_test( 'Journal: canonical state hashing is order-independent for associative keys', function () {
	$state1 = array(
		'settings' => array( 'b' => 2, 'a' => 1 ),
		'title'    => 'Hello',
	);
	$state2 = array(
		'title'    => 'Hello',
		'settings' => array( 'a' => 1, 'b' => 2 ),
	);

	$h1 = Full_Elementor_MCP_Journal::hash_state( $state1 );
	$h2 = Full_Elementor_MCP_Journal::hash_state( $state2 );
	assert_equals( $h1, $h2, 'Different key ordering in associative arrays must produce identical canonical hashes' );

	// Sequential list ordering MUST remain distinct.
	$list1 = array( 'elements' => array( 'a', 'b' ) );
	$list2 = array( 'elements' => array( 'b', 'a' ) );
	assert_true( Full_Elementor_MCP_Journal::hash_state( $list1 ) !== Full_Elementor_MCP_Journal::hash_state( $list2 ), 'List element ordering must produce distinct hashes' );
} );

run_test( 'Journal: DB insert failure blocks mutation from proceeding', function () {
	global $wpdb;
	$wpdb->simulate_write_failure = true;

	$res = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 102,
		'fencing_token' => 1,
		'before_state'  => array(),
	) );

	assert_is_wp_error( $res, 'DB insert failure must return WP_Error' );
	assert_equals( 'journal_write_failed', $res->get_error_code() );
} );

run_test( 'Journal: commit persists after_hash and updates status to committed', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 103,
		'fencing_token' => 1,
		'before_state'  => array( 'elements' => array() ),
	) );

	$after_state = array( 'elements' => array( array( 'id' => 'new_w1' ) ) );
	$committed   = Full_Elementor_MCP_Journal::commit( $journal_id, $after_state, 1 );

	assert_true( true === $committed, 'commit() must return true on success' );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_COMMITTED, $entry['status'] );
	assert_equals( Full_Elementor_MCP_Journal::hash_state( $after_state ), $entry['after_hash'] );
} );

run_test( 'Journal: commit DB failure does not report false success', function () {
	global $wpdb;

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 104,
		'fencing_token' => 1,
		'before_state'  => array(),
	) );

	$wpdb->simulate_write_failure = true;
	$res = Full_Elementor_MCP_Journal::commit( $journal_id, array( 'done' => true ), 1 );

	assert_is_wp_error( $res, 'Commit DB failure must return WP_Error' );
	assert_equals( 'journal_write_failed', $res->get_error_code() );

	$wpdb->simulate_write_failure = false;
	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_PENDING, $entry['status'] );
} );

run_test( 'Journal: double commit is handled idempotently under same fencing token', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 105,
		'fencing_token' => 2,
		'before_state'  => array(),
	) );

	$c1 = Full_Elementor_MCP_Journal::commit( $journal_id, array( 'ok' => 1 ), 2 );
	assert_true( true === $c1 );

	// Second commit with same token is idempotent success.
	$c2 = Full_Elementor_MCP_Journal::commit( $journal_id, array( 'ok' => 1 ), 2 );
	assert_true( true === $c2 );

	// Commit with conflicting/stale token is rejected.
	$c3 = Full_Elementor_MCP_Journal::commit( $journal_id, array( 'ok' => 1 ), 99 );
	assert_is_wp_error( $c3 );
	assert_equals( 'stale_writer_conflict', $c3->get_error_code() );
} );

run_test( 'Journal: created_object_id tracking updates row immediately', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'action'        => 'create',
		'object_type'   => 'page',
		'object_id'     => 0,
		'fencing_token' => 1,
		'before_state'  => null,
	) );

	$recorded = Full_Elementor_MCP_Journal::record_created_object_id( $journal_id, 888, 1 );
	assert_true( true === $recorded, 'Recording created object ID must return true' );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( '888', (string) $entry['created_object_id'] );
} );

run_test( 'Journal: mark_failed sanitizes error messages and preserves limits', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 106,
		'fencing_token' => 1,
		'before_state'  => array(),
	) );

	$sensitive_msg = 'Database crashed! Bearer secret_token_12345 password="super_secret_password" ' . str_repeat( 'X', 600 );
	Full_Elementor_MCP_Journal::mark_failed( $journal_id, $sensitive_msg, 1 );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_FAILED, $entry['status'] );
	assert_false( str_contains( $entry['error_message'], 'secret_token_12345' ) );
	assert_false( str_contains( $entry['error_message'], 'super_secret_password' ) );
	assert_true( strlen( $entry['error_message'] ) <= Full_Elementor_MCP_Journal::MAX_ERROR_MESSAGE_LENGTH );
} );

// =========================================================================
// 3. ROLLBACK TESTS
// =========================================================================

run_test( 'Rollback: successful update rollback restores before_state and marks rolled_back', function () {
	// Set up mock post storage.
	$GLOBALS['mock_post_storage'][201] = array( 'elements' => array( 'modified_widget' ) );

	$orig_state = array( 'elements' => array( 'original_widget' ) );
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 201,
		'fencing_token' => 1,
		'before_state'  => $orig_state,
	) );

	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'elements' => array( 'modified_widget' ) ), 1 );

	// Perform rollback.
	$res = Full_Elementor_MCP_Journal::rollback( $journal_id );
	assert_true( is_array( $res ) && ! empty( $res['success'] ), 'Rollback must return success array' );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK, $res['status'] );

	// Verify live storage was restored.
	assert_equals( $orig_state, $GLOBALS['mock_post_storage'][201] );

	// Verify journal status updated in DB.
	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK, $entry['status'] );
} );

run_test( 'Rollback: created object rollback trashes/deletes created entity', function () {
	$GLOBALS['mock_created_objects'][999] = 'active_page';

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'action'        => 'create',
		'object_type'   => 'page',
		'object_id'     => 0,
		'fencing_token' => 1,
		'before_state'  => null,
	) );

	Full_Elementor_MCP_Journal::record_created_object_id( $journal_id, 999, 1 );
	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'post_id' => 999 ), 1 );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id );
	assert_true( is_array( $res ) && ! empty( $res['success'] ) );

	// Mock trash callback deletes or marks object trashed.
	assert_equals( 'trashed', $GLOBALS['mock_created_objects'][999] ?? 'missing' );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK, $entry['status'] );
} );

run_test( 'Rollback: unsupported strategy fails closed without mutating state', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/update-global-colors',
		'action'        => 'update',
		'object_type'   => 'kit',
		'object_id'     => 500,
		'fencing_token' => 1,
		'before_state'  => array( 'colors' => array( 'red' ) ),
	) );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id );
	assert_is_wp_error( $res, 'Rollback on unsupported strategy must fail closed' );
	assert_equals( 'mutation_not_rollbackable', $res->get_error_code() );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_PENDING, $entry['status'] );
} );

run_test( 'Rollback: conflict detection blocks rollback when live state has diverged', function () {
	$GLOBALS['mock_post_storage'][202] = array( 'elements' => array( 'committed_state' ) );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 202,
		'fencing_token' => 1,
		'before_state'  => array( 'elements' => array( 'original_state' ) ),
	) );

	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'elements' => array( 'committed_state' ) ), 1 );

	// External modification occurs: live state diverges from committed after_hash.
	$GLOBALS['mock_post_storage'][202] = array( 'elements' => array( 'diverged_user_modification' ) );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id );
	assert_is_wp_error( $res, 'Rollback must detect state conflict' );
	assert_equals( 'journal_state_conflict', $res->get_error_code() );

	// Storage must NOT be clobbered.
	assert_equals( array( 'elements' => array( 'diverged_user_modification' ) ), $GLOBALS['mock_post_storage'][202] );

	// Forced rollback bypasses conflict check if explicitly requested.
	$forced_res = Full_Elementor_MCP_Journal::rollback( $journal_id, null, 0, array( 'force' => true ) );
	assert_true( is_array( $forced_res ) && ! empty( $forced_res['success'] ) );
	assert_equals( array( 'elements' => array( 'original_state' ) ), $GLOBALS['mock_post_storage'][202] );
} );

run_test( 'Rollback: stale fencing token blocks resource restoration', function () {
	$resource_key = 'post:203';
	$owner_a      = 'client-A';
	$owner_b      = 'client-B';

	// Client A acquires lock (fencing token = 1).
	$lock_a = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_a, 5 );
	assert_true( is_array( $lock_a ) && $lock_a['acquired'] );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 203,
		'fencing_token' => 1,
		'before_state'  => array( 'elements' => array( 'orig' ) ),
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'elements' => array( 'mod' ) ), 1 );

	// Release lock and allow Client B to acquire newer generation (fencing token = 2).
	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_a, 1 );
	$lock_b = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_b, 45 );
	assert_equals( 2, $lock_b['fencing_token'] );

	// Client A attempts rollback providing stale fencing token = 1.
	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_a, 1 );
	assert_is_wp_error( $res, 'Stale writer must be rejected from rollback' );
	assert_true( in_array( $res->get_error_code(), array( 'stale_writer_conflict', 'lock_owner_mismatch' ), true ) );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_COMMITTED, $entry['status'] );
} );

run_test( 'Rollback: restore failure does not falsely mark rolled_back', function () {
	add_filter( 'full_elementor_mcp_restore_page_data', static function () {
		return new \WP_Error( 'mock_restore_failure', 'Simulated restore error' );
	} );

	$GLOBALS['mock_post_storage'][204] = array( 'elements' => array( 'mod' ) );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 204,
		'fencing_token' => 1,
		'before_state'  => array(),
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'elements' => array( 'mod' ) ), 1 );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id );
	assert_is_wp_error( $res );
	assert_equals( 'rollback_failed', $res->get_error_code() );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_COMMITTED, $entry['status'] );
	assert_true( ! empty( $entry['error_message'] ) );

	unset( $GLOBALS['mock_fail_restore'] );
} );

// =========================================================================
// 4. CRASH RECOVERY FOUNDATION TESTS
// =========================================================================

run_test( 'Recovery: fresh pending row with active lock is ignored', function () {
	$resource_key = 'post:301';
	$owner_id     = 'worker-1';
	Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 45 );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 301,
		'fencing_token' => 1,
		'before_state'  => array(),
	) );

	// Run recovery with default 60s grace period.
	$reports = Full_Elementor_MCP_Journal::recover_pending( 60 );
	assert_equals( 0, count( $reports ), 'Active in-flight operation must be completely ignored by recovery' );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_PENDING, $entry['status'] );
} );

run_test( 'Recovery: expired lease within 60s grace period is ignored', function () {
	global $wpdb;
	$table        = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$resource_key = 'post:302';
	$lock_key     = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource_key );

	// Insert lock that expired 30 seconds ago (< 60s grace).
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
			VALUES (%s, 'lock', 'stale-worker', 1, NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 SECOND), 0)",
			$lock_key
		)
	);

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 302,
		'fencing_token' => 1,
		'before_state'  => array(),
	) );

	$reports = Full_Elementor_MCP_Journal::recover_pending( 60 );
	assert_equals( 0, count( $reports ), 'Expired lease within grace period must not be recovered yet' );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_PENDING, $entry['status'] );
} );

run_test( 'Recovery: expired lease past grace period is recognized as abandoned and safely rolled back', function () {
	global $wpdb;
	$table        = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$resource_key = 'post:303';
	$lock_key     = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource_key );

	// Insert lock that expired 120 seconds ago (> 60s grace).
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
			VALUES (%s, 'lock', 'dead-worker', 1, NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 150 SECOND), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND), 0)",
			$lock_key
		)
	);

	$orig_state = array( 'elements' => array( 'clean_state' ) );
	$GLOBALS['mock_post_storage'][303] = array( 'elements' => array( 'half_baked_state' ) );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 303,
		'fencing_token' => 1,
		'before_state'  => $orig_state,
	) );

	$reports = Full_Elementor_MCP_Journal::recover_pending( 60 );
	assert_true( count( $reports ) > 0, 'Abandoned operation must be recovered' );
	assert_equals( $journal_id, $reports[0]['journal_id'] );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK, $reports[0]['status'] );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK, $entry['status'] );
} );

run_test( 'Recovery: unsupported abandoned strategy fails closed and is marked failed', function () {
	global $wpdb;
	$table        = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$resource_key = 'kit:999';
	$lock_key     = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource_key );

	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
			VALUES (%s, 'lock', 'dead-worker', 1, NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 150 SECOND), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND), 0)",
			$lock_key
		)
	);

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/update-global-colors',
		'action'        => 'update',
		'object_type'   => 'kit',
		'object_id'     => 999,
		'fencing_token' => 1,
		'before_state'  => array(),
	) );

	$reports = Full_Elementor_MCP_Journal::recover_pending( 60 );
	assert_true( count( $reports ) > 0 );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_FAILED, $reports[0]['status'] );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_FAILED, $entry['status'] );
} );

// =========================================================================
// 5. SECURITY & REDACTION TESTS
// =========================================================================

run_test( 'Security: sensitive keys are redacted from stored state', function () {
	$input_state = array(
		'widget_name' => 'login_box',
		'credentials' => array(
			'user_pass'       => 'P@ssw0rd123!',
			'api_key'         => 'sk_live_9988776655',
			'safe_config_val' => 'visible_value',
		),
		'auth_token'  => 'jwt_bearer_token_xyz',
	);

	$canonical = Full_Elementor_MCP_Journal::canonicalize_data( $input_state );
	assert_equals( '[REDACTED]', $canonical['credentials']['user_pass'] );
	assert_equals( '[REDACTED]', $canonical['credentials']['api_key'] );
	assert_equals( '[REDACTED]', $canonical['auth_token'] );
	assert_equals( 'visible_value', $canonical['credentials']['safe_config_val'] );
} );

run_test( 'Security: malformed JSON state deserializes safely', function () {
	$res1 = Full_Elementor_MCP_Journal::deserialize_state( null );
	assert_equals( array(), $res1 );

	$res2 = Full_Elementor_MCP_Journal::deserialize_state( '' );
	assert_equals( array(), $res2 );

	$res3 = Full_Elementor_MCP_Journal::deserialize_state( '{broken json' );
	assert_equals( null, $res3 );
} );

run_test( 'Registry: readonly ability cannot accidentally register as mutation', function () {
	$readonly_strategy = array(
		'ability'               => 'test/readonly-info',
		'action'                => 'read',
		'object_type'           => 'post',
		'category'              => Full_Elementor_MCP_Mutation_Registry::CATEGORY_ELEMENTOR_DATA,
		'resource_key_resolver' => static fn( $args ) => 'post:1',
		'object_id_resolver'    => static fn( $args ) => 1,
		'capture_before'        => static fn( $id, $args ) => array(),
		'restore_before'        => static fn( $id, $state ) => true,
		'supports_rollback'     => false,
		'is_readonly'           => true,
	);

	$res = Full_Elementor_MCP_Mutation_Registry::register( $readonly_strategy );
	assert_is_wp_error( $res );
	assert_equals( 'readonly_mutation_conflict', $res->get_error_code() );
} );

run_test( 'Journal: record_created_object_id DB failure returns WP_Error', function () {
	global $wpdb;

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'action'        => 'create',
		'object_type'   => 'page',
		'object_id'     => 0,
		'fencing_token' => 1,
		'before_state'  => null,
	) );

	$wpdb->simulate_write_failure = true;
	$res = Full_Elementor_MCP_Journal::record_created_object_id( $journal_id, 777, 1 );
	assert_is_wp_error( $res );
	assert_equals( 'journal_write_failed', $res->get_error_code() );
} );

run_test( 'Recovery: completed and rolled-back rows are never recovered', function () {
	global $wpdb;
	$table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	// Insert committed entry from 200 seconds ago.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (created_at, updated_at, ability, action, object_type, object_id, fencing_token, status)
			VALUES (DATE_SUB(UTC_TIMESTAMP(), INTERVAL 200 SECOND), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 SECOND), 'full-elementor-mcp/add-widget', 'add', 'post', 888, 1, %s)",
			Full_Elementor_MCP_Journal::STATUS_COMMITTED
		)
	);

	// Insert rolled_back entry from 200 seconds ago.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (created_at, updated_at, ability, action, object_type, object_id, fencing_token, status)
			VALUES (DATE_SUB(UTC_TIMESTAMP(), INTERVAL 200 SECOND), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 SECOND), 'full-elementor-mcp/add-widget', 'add', 'post', 889, 1, %s)",
			Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK
		)
	);

	$reports = Full_Elementor_MCP_Journal::recover_pending( 60 );
	foreach ( $reports as $rep ) {
		assert_true( 888 !== ( $rep['object_id'] ?? 0 ), 'Committed row must never be processed by recovery' );
		assert_true( 889 !== ( $rep['object_id'] ?? 0 ), 'Rolled back row must never be processed by recovery' );
	}
} );

run_test( 'Journal: invalid non-UTF8 serialization returns error and blocks begin', function () {
	// Malformed invalid UTF-8 byte string.
	$invalid_utf8_state = array(
		'bad_data' => "\xB1\x31",
	);

	$res = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'add',
		'object_type'   => 'post',
		'object_id'     => 444,
		'fencing_token' => 1,
		'before_state'  => $invalid_utf8_state,
	) );

	assert_is_wp_error( $res );
	assert_equals( 'serialization_failed', $res->get_error_code() );
} );

echo "\n=======================================================\n";
echo " Test Results: {$tests_passed}/" . ( $tests_passed + $tests_failed ) . " passed.\n";
echo "=======================================================\n\n";

if ( $tests_failed > 0 ) {
	exit( 1 );
}
exit( 0 );
