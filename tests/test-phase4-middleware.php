<?php
/**
 * Comprehensive Test Suite for Phase 4: Central Middleware & Safety Gates.
 *
 * Can be executed via CLI: `php tests/test-phase4-middleware.php`
 *
 * Verifies all 15 Phase 4 test families:
 * 1. Middleware Routing & Readonly Bypass
 * 2. Credential Scope Gates
 * 3. Server-Issued Confirmation Challenge & Atomic Single-Use CAS
 * 4. unfiltered_html Capability Gate
 * 5. Dry-Run Isolation & Prediction
 * 6. WAL Ordering (Capture Before -> Journal Pending -> Execute)
 * 7. Fencing Timing & Low-Level Persistence Sinks
 * 8. Tree Validation Before Write & Post-Mutation Rollback
 * 9. Atomic Idempotency Lifecycle (Pending -> Completed -> Conflict)
 * 10. CREATE Lifecycle & Immediate Durable Created ID Recording
 * 11. Universal High-Risk Generic Payload Gates
 * 12. Protected Resources Gates
 * 13. Failure & Exception Recovery
 * 14. Context Leak & Nested Mutation Rejection
 * 15. Reserved Argument Injection Rejection
 *
 * @package Full_Elementor_MCP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'FULL_ELEMENTOR_MCP_VERSION' ) ) {
	define( 'FULL_ELEMENTOR_MCP_VERSION', '1.8.0' );
}

if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
	define( 'ELEMENTOR_VERSION', '4.0.0' );
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
		public function get_error_data(): mixed { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

$GLOBALS['wp_test_options']     = array();
$GLOBALS['mock_post_meta']      = array();
$GLOBALS['mock_posts']          = array();
$GLOBALS['wp_test_user_id']     = 1;
$GLOBALS['wp_test_caps']        = array( 'manage_options' => true, 'edit_posts' => true, 'unfiltered_html' => true );
$GLOBALS['wp_test_app_pwd_uuid'] = null;

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
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return $GLOBALS['wp_test_user_id'];
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		return ! empty( $GLOBALS['wp_test_caps'][ $cap ] );
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( int $user_id, string $cap ): bool {
		return ! empty( $GLOBALS['wp_test_caps'][ $cap ] );
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		$data = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( mixed $value ): mixed {
		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		if ( '' === $key ) {
			return $GLOBALS['mock_post_meta'][ $post_id ] ?? array();
		}
		$val = $GLOBALS['mock_post_meta'][ $post_id ][ $key ] ?? null;
		return $single ? $val : ( null !== $val ? array( $val ) : array() );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		if ( ! isset( $GLOBALS['mock_post_meta'][ $post_id ] ) ) {
			$GLOBALS['mock_post_meta'][ $post_id ] = array();
		}
		$GLOBALS['mock_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key ): bool {
		unset( $GLOBALS['mock_post_meta'][ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ): ?object {
		return $GLOBALS['mock_posts'][ $post_id ] ?? (object) array( 'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Page ' . $post_id, 'post_name' => 'page-' . $post_id );
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int $post_id ): string|false {
		return $GLOBALS['mock_post_status'][ $post_id ] ?? 'publish';
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, mixed $value, ...$args ): mixed {
		return $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}

class MockElementorDocument {
	protected int $post_id;
	public function __construct( int $post_id ) {
		$this->post_id = $post_id;
	}
	public function get_elements_data(): array {
		$raw = get_post_meta( $this->post_id, '_elementor_data', true );
		if ( ! empty( $raw ) && is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}
	public function save( array $data ): bool {
		if ( isset( $data['elements'] ) ) {
			update_post_meta( $this->post_id, '_elementor_data', wp_json_encode( $data['elements'] ) );
		}
		if ( isset( $data['settings'] ) ) {
			update_post_meta( $this->post_id, '_elementor_page_settings', $data['settings'] );
		}
		return true;
	}
	public function get_settings(): array {
		$raw = get_post_meta( $this->post_id, '_elementor_page_settings', true );
		return is_array( $raw ) ? $raw : array();
	}
}

class MockElementorDocumentsManager {
	public function get( $post_id ) {
		return new MockElementorDocument( (int) $post_id );
	}
}

class MockElementorWidgetsManager {
	public function get_widget_types( $type = null ) { return array(); }
}

class MockElementorPlugin {
	public static $instance;
	public $widgets_manager;
	public $documents;
	public function __construct() {
		$this->widgets_manager = new MockElementorWidgetsManager();
		$this->documents       = new MockElementorDocumentsManager();
	}
}

if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
	class_alias( 'MockElementorPlugin', '\\Elementor\\Plugin' );
	\Elementor\Plugin::$instance = new MockElementorPlugin();
}

// In-Memory SQLite WPDB Mock.
class Phase4_Mock_WPDB {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public \PDO $pdo;

	public function __construct() {
		$this->pdo = new \PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		if ( method_exists( $this->pdo, 'sqliteCreateFunction' ) ) {
			call_user_func(
				array( $this->pdo, 'sqliteCreateFunction' ),
				'UTC_TIMESTAMP',
				static function () {
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

	public function query( string $query ): int|false {
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
					}
				}
				return false !== $res ? (int) $res : 0;
			}

			$query = $this->translate_query_for_sqlite( $query );
			$count = $this->pdo->exec( $query );
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return false === $count ? false : (int) $count;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function get_row( string $query, string $output = 'OBJECT' ): mixed {
		try {
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			if ( false === $stmt ) {
				return null;
			}
			$row = $stmt->fetch( \PDO::FETCH_ASSOC );
			if ( false === $row ) {
				return null;
			}
			return 'ARRAY_A' === $output ? $row : (object) $row;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function get_var( string $query ): mixed {
		try {
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			if ( false === $stmt ) {
				return null;
			}
			$val = $stmt->fetchColumn();
			return false === $val ? null : $val;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function get_results( string $query, string $output = 'OBJECT' ): array {
		try {
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			if ( false === $stmt ) {
				return array();
			}
			$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
			if ( 'ARRAY_A' === $output ) {
				return $rows;
			}
			return array_map( static fn( $r ) => (object) $r, $rows );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	private function translate_query_for_sqlite( string $query ): string {
		$query = preg_replace( '/DATE_ADD\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+SECOND\s*\)/i', "datetime($1, '+$2 seconds')", $query );
		$query = preg_replace( '/DATE_SUB\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+SECOND\s*\)/i', "datetime($1, '-$2 seconds')", $query );
		$query = preg_replace( '/GREATEST\s*\(\s*([^,]+?)\s*,\s*([^)]+?)\s*\)/i', 'max($1, $2)', $query );
		return $query;
	}
}

global $wpdb;
$wpdb = new Phase4_Mock_WPDB();
$GLOBALS['wpdb'] = $wpdb;

// Require all safety classes.
require_once __DIR__ . '/../includes/safety/class-database-installer.php';
require_once __DIR__ . '/../includes/safety/class-safety-settings.php';
require_once __DIR__ . '/../includes/safety/class-lock-manager.php';
require_once __DIR__ . '/../includes/safety/class-security-guard.php';
require_once __DIR__ . '/../includes/safety/class-elementor-features.php';
require_once __DIR__ . '/../includes/safety/class-tree-validator.php';
require_once __DIR__ . '/../includes/safety/class-security-strategies.php';
require_once __DIR__ . '/../includes/safety/class-mutation-registry.php';
require_once __DIR__ . '/../includes/safety/class-journal.php';
require_once __DIR__ . '/../includes/safety/class-mutation-context.php';
require_once __DIR__ . '/../includes/safety/class-confirmation-manager.php';
require_once __DIR__ . '/../includes/safety/class-idempotency-manager.php';
require_once __DIR__ . '/../includes/safety/class-mutation-middleware.php';
require_once __DIR__ . '/../includes/class-elementor-data.php';

function setup_phase4_test_db(): void {
	global $wpdb;
	$schemas = Full_Elementor_MCP_Database_Installer::get_schema_definitions();
	foreach ( $schemas as $ddl ) {
		$wpdb->query( $ddl );
	}
}
setup_phase4_test_db();
Full_Elementor_MCP_Mutation_Registry::init_core_strategies();

// Test runner assertion utilities.
$tests_passed = 0;
$tests_failed = 0;

function assert_true( bool $condition, string $message = 'Expected condition to be true.' ): void {
	if ( ! $condition ) {
		throw new \Exception( 'ASSERTION FAILED: ' . $message );
	}
}

function assert_false( bool $condition, string $message = 'Expected condition to be false.' ): void {
	if ( $condition ) {
		throw new \Exception( 'ASSERTION FAILED: ' . $message );
	}
}

function assert_equals( mixed $expected, mixed $actual, string $message = '' ): void {
	if ( $expected !== $actual ) {
		$exp_str = is_scalar( $expected ) ? (string) $expected : json_encode( $expected );
		$act_str = is_scalar( $actual ) ? (string) $actual : json_encode( $actual );
		throw new \Exception( "ASSERTION FAILED: Expected [{$exp_str}], got [{$act_str}]. {$message}" );
	}
}

function assert_is_wp_error( mixed $thing, string $message = 'Expected WP_Error instance.' ): void {
	if ( ! is_wp_error( $thing ) ) {
		$type = gettype( $thing );
		throw new \Exception( "ASSERTION FAILED: {$message} Got type: {$type}" );
	}
}

function assert_error_code( string $code, mixed $thing, string $message = '' ): void {
	assert_is_wp_error( $thing, $message );
	assert_equals( $code, $thing->get_error_code(), $message );
}

function run_test( string $name, callable $test ): void {
	global $tests_passed, $tests_failed, $wpdb;
	try {
		Full_Elementor_MCP_Mutation_Context::reset();
		$wpdb->query( "DELETE FROM {$wpdb->prefix}elementor_mcp_tokens" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}elementor_mcp_journal" );
		$GLOBALS['wp_test_caps'] = array(
			'manage_options'  => true,
			'edit_posts'      => true,
			'publish_posts'   => true,
			'unfiltered_html' => true,
		);
		$GLOBALS['wp_test_user_id'] = 1;
		$test();
		echo " [PASS] {$name}\n";
		$tests_passed++;
	} catch ( \Throwable $e ) {
		echo " [FAIL] {$name}\n";
		echo "        Error: {$e->getMessage()}\n";
		echo "        File:  {$e->getFile()}:{$e->getLine()}\n";
		$tests_failed++;
	} finally {
		Full_Elementor_MCP_Mutation_Context::reset();
	}
}

echo "\n=======================================================\n";
echo " Full Elementor MCP — Phase 4 Middleware Test Suite\n";
echo "=======================================================\n\n";

// Helper to register mock abilities:
function register_mock_ability( string $name, bool $readonly, callable $execute, ?callable $perm = null ): void {
	$def = array(
		'execute_callback'    => $execute,
		'permission_callback' => $perm ?? static fn() => true,
		'meta'                => array(
			'annotations' => array( 'readonly' => $readonly ),
		),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
		),
	);
	Full_Elementor_MCP_Mutation_Middleware::wrap_ability( $name, $def );
}

// -----------------------------------------------------------------------------
// 1. Middleware Routing & Readonly Bypass
// -----------------------------------------------------------------------------

run_test( 'Routing: readonly ability bypasses mutation WAL and lock', function () {
	global $wpdb;
	$called = false;
	register_mock_ability( 'full-elementor-mcp/mock-get-info', true, function ( $input ) use ( &$called ) {
		$called = true;
		return array( 'info' => 'clean' );
	} );

	$journals_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_journal" );
	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/mock-get-info', array( 'post_id' => 10 ) );

	assert_true( $called, 'Original execute callback must be called' );
	assert_equals( 'clean', $res['info'] );
	$journals_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_journal" );
	assert_equals( $journals_before, $journals_after, 'Readonly ability must not create WAL entries' );
	assert_false( Full_Elementor_MCP_Mutation_Context::has_active_context() );
} );

run_test( 'Routing: mutation ability executes through middleware and commits WAL', function () {
	global $wpdb;
	$called = false;
	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) use ( &$called ) {
		$called = true;
		return array( 'success' => true );
	} );

	$journals_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_journal" );
	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
	) );

	assert_true( $called );
	assert_true( $res['success'] );
	$journals_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_journal" );
	assert_equals( $journals_before + 1, $journals_after, 'Mutation must write durable committed journal' );

	$last_row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_equals( 'committed', $last_row['status'] );
	assert_false( Full_Elementor_MCP_Mutation_Context::has_active_context() );
} );

run_test( 'Routing: unknown mutation strategy fails closed', function () {
	register_mock_ability( 'full-elementor-mcp/unregistered-mutator', false, function ( $input ) {
		return array( 'should' => 'never run' );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/unregistered-mutator', array( 'post_id' => 10 ) );
	assert_error_code( 'mutation_strategy_missing', $res );
} );

run_test( 'Routing: globally disabled tool is rejected at execution time', function () {
	update_option( 'full_elementor_mcp_disabled_tools', array( 'full-elementor-mcp/update-element' ) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
	) );

	delete_option( 'full_elementor_mcp_disabled_tools' );
	assert_error_code( 'ability_disabled', $res );
} );

run_test( 'Routing: permission callback runs first and blocks execution on failure', function () {
	$called = false;
	register_mock_ability(
		'full-elementor-mcp/update-element',
		false,
		function ( $input ) use ( &$called ) {
			$called = true;
			return array( 'success' => true );
		},
		function ( $input ) {
			return false; // Deny permission.
		}
	);

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
	) );

	assert_false( $called, 'Execute callback must NOT be called when permission fails' );
	assert_error_code( 'permission_denied', $res );
	assert_false( Full_Elementor_MCP_Mutation_Context::has_active_context() );
} );

// -----------------------------------------------------------------------------
// 2. Credential Scope Gates
// -----------------------------------------------------------------------------

run_test( 'Scope: readonly credential scope rejects mutation ability', function () {
	update_option( Full_Elementor_MCP_Security_Guard::OPTION_SCOPES, array(
		1 => array( 'default' => array( 'mode' => 'read_only' ) ),
	) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
	) );

	delete_option( Full_Elementor_MCP_Security_Guard::OPTION_SCOPES );
	assert_error_code( 'credential_scope_readonly', $res );
} );

run_test( 'Scope: custom scope allowlist permits listed ability and rejects omitted ability', function () {
	update_option( Full_Elementor_MCP_Security_Guard::OPTION_SCOPES, array(
		1 => array(
			'default' => array(
				'mode'          => 'custom',
				'allowed_tools' => array( 'full-elementor-mcp/update-element' ),
			),
		),
	) );

	// 1. Allowed ability:
	register_mock_ability( 'full-elementor-mcp/update-element', false, fn() => array( 'success' => true ) );
	$res_ok = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
	) );
	assert_true( $res_ok['success'] );

	// 2. Omitted ability:
	register_mock_ability( 'full-elementor-mcp/delete-page', false, fn() => array( 'deleted' => true ) );
	$res_denied = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', array(
		'post_id' => 10,
	) );
	assert_error_code( 'credential_scope_denied', $res_denied );

	delete_option( Full_Elementor_MCP_Security_Guard::OPTION_SCOPES );
} );

run_test( 'Scope: empty custom scope fails closed and denies all mutations', function () {
	update_option( Full_Elementor_MCP_Security_Guard::OPTION_SCOPES, array(
		1 => array(
			'default' => array(
				'mode'          => 'custom',
				'allowed_tools' => array(), // Empty allowlist
			),
		),
	) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
	) );

	delete_option( Full_Elementor_MCP_Security_Guard::OPTION_SCOPES );
	assert_error_code( 'credential_scope_denied', $res );
} );

// -----------------------------------------------------------------------------
// 3. Server-Issued Confirmation Challenge & Atomic Single-Use CAS
// -----------------------------------------------------------------------------

run_test( 'Confirmation: safe ordinary mutation requires no confirmation', function () {
	register_mock_ability( 'full-elementor-mcp/update-element', false, fn() => array( 'success' => true ) );
	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'heading_1',
		'settings'   => array( 'title' => 'Safe Title' ),
	) );

	assert_true( $res['success'] );
} );

run_test( 'Confirmation: permanent deletion returns confirmation_required challenge', function () {
	register_mock_ability( 'full-elementor-mcp/delete-page', false, fn() => array( 'deleted' => true ) );
	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', array(
		'post_id' => 10,
		'force'   => true, // Permanent delete!
	) );

	assert_error_code( 'confirmation_required', $res );
	$data = $res->get_error_data();
	assert_true( $data['confirmation_required'] );
	assert_true( ! empty( $data['confirmation_token'] ) );
	assert_equals( 'full-elementor-mcp/delete-page', $data['ability'] );
	assert_equals( 'post:10', $data['resource_key'] );
} );

run_test( 'Confirmation: providing valid challenge token authorizes high-risk mutation', function () {
	register_mock_ability( 'full-elementor-mcp/delete-page', false, fn() => array( 'deleted' => true ) );
	$args = array(
		'post_id' => 10,
		'force'   => true,
	);

	// 1. Initial attempt receives challenge:
	$challenge_res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', $args );
	assert_error_code( 'confirmation_required', $challenge_res );
	$token = $challenge_res->get_error_data()['confirmation_token'];

	// 2. Retry with confirmation_token:
	$args['confirmation_token'] = $token;
	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', $args );
	assert_true( $res['deleted'] );
} );

run_test( 'Confirmation: modifying arguments invalidates confirmation token', function () {
	register_mock_ability( 'full-elementor-mcp/delete-page', false, fn() => array( 'deleted' => true ) );
	$args = array(
		'post_id' => 10,
		'force'   => true,
	);

	$challenge_res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', $args );
	$token         = $challenge_res->get_error_data()['confirmation_token'];

	// Caller attempts to use token for DIFFERENT post_id:
	$tampered_args = array(
		'post_id'            => 999, // Different ID!
		'force'              => true,
		'confirmation_token' => $token,
	);
	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', $tampered_args );
	assert_error_code( 'confirmation_resource_mismatch', $res );
} );

run_test( 'Confirmation: consumed token cannot be reused', function () {
	register_mock_ability( 'full-elementor-mcp/delete-page', false, fn() => array( 'deleted' => true ) );
	$args = array(
		'post_id' => 10,
		'force'   => true,
	);

	$challenge_res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', $args );
	$token         = $challenge_res->get_error_data()['confirmation_token'];

	$args['confirmation_token'] = $token;
	$res_first = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', $args );
	assert_true( $res_first['deleted'] );

	// Second attempt with same token must fail:
	$res_second = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', $args );
	assert_error_code( 'confirmation_token_used', $res_second );
} );

run_test( 'Confirmation: caller allow_critical_override boolean cannot bypass confirmation', function () {
	register_mock_ability( 'full-elementor-mcp/delete-page', false, fn() => array( 'deleted' => true ) );
	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', array(
		'post_id'                 => 10,
		'force'                   => true,
		'allow_critical_override' => true, // Spoofed legacy override!
	) );

	assert_error_code( 'confirmation_required', $res );
} );

// -----------------------------------------------------------------------------
// 4. unfiltered_html Capability Gate
// -----------------------------------------------------------------------------

run_test( 'Capabilities: unfiltered_html required for executable payload and denied when missing', function () {
	register_mock_ability( 'full-elementor-mcp/add-widget', false, fn() => array( 'success' => true ) );

	$GLOBALS['wp_test_caps']['unfiltered_html'] = false; // User lacks capability!

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/add-widget', array(
		'post_id'     => 10,
		'parent_id'   => 'cont_1',
		'widget_type' => 'html',
		'settings'    => array( 'html' => '<script>alert(1)</script>' ),
	) );

	$GLOBALS['wp_test_caps']['unfiltered_html'] = true; // Restore
	assert_error_code( 'unfiltered_html_required', $res );
} );

// -----------------------------------------------------------------------------
// 5. Dry-Run Isolation & Prediction
// -----------------------------------------------------------------------------

run_test( 'Dry-Run: predicts mutation results without calling callback or writing journal', function () {
	global $wpdb;
	$called = false;
	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) use ( &$called ) {
		$called = true;
		return array( 'success' => true );
	} );

	$journals_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_journal" );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
		'dry_run'    => true,
	) );

	assert_false( $called, 'Original callback must NEVER execute during dry-run' );
	assert_true( $res['dry_run'] );
	assert_true( $res['allowed'] );
	assert_equals( 'full-elementor-mcp/update-element', $res['ability'] );
	assert_equals( 'post:10', $res['resource_key'] );

	$journals_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_journal" );
	assert_equals( $journals_before, $journals_after, 'Dry-run must not create journal rows' );
	assert_false( Full_Elementor_MCP_Mutation_Context::has_active_context() );
} );

run_test( 'Dry-Run: string "true" is NOT treated as dry-run', function () {
	$called = false;
	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) use ( &$called ) {
		$called = true;
		return array( 'success' => true );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
		'dry_run'    => 'true', // String, NOT boolean true!
	) );

	assert_true( $called, 'Literal boolean true only; string must execute normally' );
	assert_true( $res['success'] );
} );

// -----------------------------------------------------------------------------
// 6. WAL Ordering Tests
// -----------------------------------------------------------------------------

run_test( 'WAL Ordering: durable pending journal exists before callback execution', function () {
	global $wpdb;
	$journal_id_during_exec = 0;
	$status_during_exec     = '';

	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) use ( &$journal_id_during_exec, &$status_during_exec, $wpdb ) {
		$ctx = Full_Elementor_MCP_Mutation_Context::current();
		$journal_id_during_exec = (int) ( $ctx['journal_id'] ?? 0 );
		$status_during_exec     = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}elementor_mcp_journal WHERE id = %d", $journal_id_during_exec ) );
		return array( 'success' => true );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
	) );

	assert_true( $res['success'] );
	assert_true( $journal_id_during_exec > 0, 'Durable journal row must exist during execution' );
	assert_equals( 'pending', $status_during_exec, 'Journal status must be pending while callback executes' );
} );

// -----------------------------------------------------------------------------
// 7. Fencing Timing & Low-Level Persistence Sinks
// -----------------------------------------------------------------------------

run_test( 'Fencing Timing: stale writer is blocked immediately before persistence in save_page_data', function () {
	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) {
		// Simulate mid-execution race: another client takes over lock in DB with fencing token 2:
		global $wpdb;
		$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( 'post:10' );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}elementor_mcp_tokens SET owner_id = 'worker_2', fencing_token = 2 WHERE token_key = %s",
			$lock_key
		) );

		// Now this original callback attempts to persist page data using stale fencing token 1:
		$data_layer = new Full_Elementor_MCP_Data();
		$save_res   = $data_layer->save_page_data( 10, array(
			array( 'id' => 'node_1', 'elType' => 'section', 'elements' => array() ),
		) );

		return $save_res;
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
	) );

	assert_error_code( 'stale_writer_conflict', $res, 'Low-level write guard must reject stale writer' );
} );

// -----------------------------------------------------------------------------
// 8. Tree Validation Before Write & Post-Mutation Rollback
// -----------------------------------------------------------------------------

run_test( 'Tree Validation: save_page_data rejects duplicate ID tree before writing', function () {
	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) {
		$data_layer = new Full_Elementor_MCP_Data();
		$bad_tree   = array(
			array( 'id' => 'dup_id', 'elType' => 'section', 'elements' => array() ),
			array( 'id' => 'dup_id', 'elType' => 'section', 'elements' => array() ), // Duplicate!
		);
		return $data_layer->save_page_data( 10, $bad_tree );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'New Title' ),
	) );

	assert_error_code( 'duplicate_element_id', $res );
} );

// -----------------------------------------------------------------------------
// 9. Atomic Idempotency Lifecycle
// -----------------------------------------------------------------------------

run_test( 'Idempotency: first request claims and completes; retry returns cached result without re-execution', function () {
	$execution_count = 0;
	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) use ( &$execution_count ) {
		$execution_count++;
		return array( 'success' => true, 'exec_num' => $execution_count );
	} );

	$idemp_key = 'test_key_' . bin2hex( random_bytes( 8 ) );
	$args      = array(
		'post_id'         => 10,
		'element_id'      => 'el_1',
		'settings'        => array( 'title' => 'Unique Title' ),
		'idempotency_key' => $idemp_key,
	);

	// 1. First execution:
	$res1 = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', $args );
	assert_true( $res1['success'] );
	assert_equals( 1, $execution_count );

	// 2. Retry with same key and args:
	$res2 = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', $args );
	assert_true( $res2['success'] );
	assert_equals( 1, $execution_count, 'Callback must NOT be called on completed retry' );
} );

run_test( 'Idempotency: same key with different args returns idempotency_conflict', function () {
	register_mock_ability( 'full-elementor-mcp/update-element', false, fn() => array( 'success' => true ) );

	$idemp_key = 'conflict_key_' . bin2hex( random_bytes( 8 ) );
	$res1 = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'         => 10,
		'element_id'      => 'el_1',
		'settings'        => array( 'title' => 'Title A' ),
		'idempotency_key' => $idemp_key,
	) );
	assert_true( $res1['success'] );

	// Reusing key with different title:
	$res2 = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'         => 10,
		'element_id'      => 'el_1',
		'settings'        => array( 'title' => 'Title B — Divergent!' ),
		'idempotency_key' => $idemp_key,
	) );
	assert_error_code( 'idempotency_conflict', $res2 );
} );

// -----------------------------------------------------------------------------
// 10. CREATE Lifecycle & Immediate Created ID Recording
// -----------------------------------------------------------------------------

run_test( 'CREATE: created_object_id is recorded immediately and journal committed with ID', function () {
	global $wpdb;
	register_mock_ability( 'full-elementor-mcp/create-page', false, function ( $input ) {
		return array( 'post_id' => 777, 'edit_url' => 'https://example.com/wp-admin/post.php?post=777' );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title' => 'Brand New Page',
	) );

	assert_equals( 777, $res['post_id'] );
	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal WHERE ability = 'full-elementor-mcp/create-page' ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_equals( 777, (int) $row['created_object_id'] );
	assert_equals( 'committed', $row['status'] );
} );

// -----------------------------------------------------------------------------
// 11. Universal High-Risk Generic Payload Gates
// -----------------------------------------------------------------------------

run_test( 'Generic Payload: add-widget with HTML and script tag requires confirmation and unfiltered_html', function () {
	register_mock_ability( 'full-elementor-mcp/add-widget', false, fn() => array( 'success' => true ) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/add-widget', array(
		'post_id'     => 10,
		'parent_id'   => 'cont_1',
		'widget_type' => 'html',
		'settings'    => array( 'html' => '<script>stealCookies();</script>' ),
	) );

	assert_error_code( 'confirmation_required', $res );
} );

run_test( 'Generic Payload: batch-update with one dangerous operation requires confirmation', function () {
	register_mock_ability( 'full-elementor-mcp/batch-update', false, fn() => array( 'success' => true ) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/batch-update', array(
		'post_id'    => 10,
		'operations' => array(
			array( 'element_id' => 'safe_h', 'settings' => array( 'title' => 'Safe' ) ),
			array( 'element_id' => 'evil_w', 'settings' => array( 'html' => '<iframe src="evil.com"/>' ) ),
		),
	) );

	assert_error_code( 'confirmation_required', $res );
} );

// -----------------------------------------------------------------------------
// 12. Protected Resources Gates
// -----------------------------------------------------------------------------

run_test( 'Protected Resources: editing page configured as homepage requires confirmation', function () {
	update_option( 'page_on_front', 55 );
	register_mock_ability( 'full-elementor-mcp/update-element', false, fn() => array( 'success' => true ) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 55, // Homepage!
		'element_id' => 'heading_1',
		'settings'   => array( 'title' => 'Hero Title' ),
	) );

	delete_option( 'page_on_front' );
	assert_error_code( 'confirmation_required', $res );
} );

run_test( 'Protected Resources: set-active-kit requires confirmation', function () {
	register_mock_ability( 'full-elementor-mcp/set-active-kit', false, fn() => array( 'success' => true ) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/set-active-kit', array(
		'kit_id' => 12,
	) );

	assert_error_code( 'confirmation_required', $res );
} );

// -----------------------------------------------------------------------------
// 13. Failure & Exception Recovery
// -----------------------------------------------------------------------------

run_test( 'Failure: unexpected exception marks journal failed and releases lock cleanly', function () {
	global $wpdb;
	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) {
		throw new \RuntimeException( 'Simulated explosive crash inside ability callback' );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'Kaboom' ),
	) );

	assert_error_code( 'mutation_exception', $res );
	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_equals( 'failed', $row['status'] );
	assert_false( Full_Elementor_MCP_Mutation_Context::has_active_context() );
} );

// -----------------------------------------------------------------------------
// 14. Context Leak & Nested Mutation Rejection
// -----------------------------------------------------------------------------

run_test( 'Context: nested forward mutations fail closed with nested_mutation_not_supported', function () {
	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) {
		// Attempt nested forward mutation:
		return Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', array( 'post_id' => 20 ) );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'title' => 'Outer' ),
	) );

	assert_error_code( 'nested_mutation_not_supported', $res );
	assert_false( Full_Elementor_MCP_Mutation_Context::has_active_context() );
} );

// -----------------------------------------------------------------------------
// 15. Reserved Argument Injection Rejection
// -----------------------------------------------------------------------------

run_test( 'Security: caller attempting to inject internal safety fields is rejected', function () {
	register_mock_ability( 'full-elementor-mcp/update-element', false, fn() => array( 'success' => true ) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'       => 10,
		'element_id'    => 'el_1',
		'fencing_token' => 999, // Injected reserved field!
		'settings'      => array( 'title' => 'Spoofed Fencing' ),
	) );

	assert_error_code( 'reserved_safety_argument', $res );
} );

echo "\n=======================================================\n";
echo " Test Results: {$tests_passed}/" . ( $tests_passed + $tests_failed ) . " passed.\n";
echo "=======================================================\n\n";

if ( $tests_failed > 0 ) {
	exit( 1 );
}
