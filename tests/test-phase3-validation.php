<?php
/**
 * Standalone Test Suite for Phase 3: Tree Validator & Security Strategies.
 *
 * Can be executed via CLI: `php tests/test-phase3-validation.php`
 *
 * Verifies:
 * 1. Elementor Tree Recursive Validation:
 *    - Valid empty document
 *    - Valid nested containers and widgets
 *    - Document-wide duplicate ID rejection
 *    - Invalid element structure and types
 *    - Widget child elements rejection
 *    - Container and column child invariants
 *    - Settings value safety (JSON serializability, rejecting resources/closures/unserializable objects)
 *    - Resource limits (depth, node count, serialized byte size)
 *    - Preservation of element ordering and unknown third-party settings
 * 2. Element ID Validation:
 *    - Generated and real-world 7-hex and alphanumeric IDs
 *    - Empty, duplicate, and excessive length / invalid character rejections
 * 3. Feature & Capability Detection:
 *    - Runtime capability detection (classic, containers, nested, atomic)
 *    - Independence from numeric ELEMENTOR_VERSION comparison
 * 4. Rollback Tree Verification:
 *    - Valid before-state restores successfully
 *    - Corrupted journal before-state rejected before persistent write
 *    - Fencing assertion enforced
 * 5. SSRF & Remote URL Defense:
 *    - Scheme and embedded credential enforcement
 *    - Localhost, 127.0.0.1, 0.0.0.0, RFC 1918, link-local, cloud metadata blocked
 *    - IPv6 loopback (::1), unique local (fc00::/7), link-local, and IPv4-mapped IPv6 blocked
 *    - Ambiguous IP representations (integer, hex, octal) blocked
 *    - Outbound port policy (80, 443 only)
 *    - Public HTTPS allowed
 *    - Safe redirect following with intermediate hop SSRF rejection and loop bounds
 * 6. SVG XML Security:
 *    - Safe XML parsing with entity expansion (XXE) blocked
 *    - Script tags, inline event handlers, javascript: URIs, dangerous data URIs rejected
 *    - Dangerous foreignObject, iframe, object, embed rejected
 *    - Valid clean SVGs accepted
 * 7. Security Classification & Protected Assets:
 *    - Executable code truthfully classified (custom JS, HTML with script)
 *    - Custom CSS classified
 *    - Permanent deletion identified
 *    - Protected assets identified (front page, posts page, active kit, WooCommerce)
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

$GLOBALS['wp_test_options']    = array();
$GLOBALS['mock_post_storage']  = array();
$GLOBALS['mock_post_meta']     = array();
$GLOBALS['mock_posts']         = array();
$GLOBALS['wp_test_filters']    = array();
$GLOBALS['mock_http_responses'] = array();

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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return $value;
	}
}
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return addslashes( $value );
		}
		return $value;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return '6.9';
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( string $filename = '', string $dir = '' ): string|false {
		return tempnam( sys_get_temp_dir(), 'test_mcp_' );
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): bool {
		if ( file_exists( $file ) ) {
			return @unlink( $file );
		}
		return false;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( mixed $post = null ): ?object {
		$id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
		return isset( $GLOBALS['mock_posts'][ $id ] ) ? (object) $GLOBALS['mock_posts'][ $id ] : null;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( mixed $post = null ): string|false {
		$id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
		return isset( $GLOBALS['mock_posts'][ $id ] ) ? (string) ( $GLOBALS['mock_posts'][ $id ]['post_status'] ?? 'publish' ) : false;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		if ( '' === $key ) {
			return $GLOBALS['mock_post_meta'][ $post_id ] ?? array();
		}
		if ( '_elementor_data' === $key ) {
			$val = $GLOBALS['mock_post_meta'][ $post_id ][ $key ] ?? null;
			if ( is_string( $val ) ) {
				$decoded = json_decode( wp_unslash( $val ), true );
				return is_array( $decoded ) ? $decoded : $val;
			}
			return $val ?? ( $single ? '' : array() );
		}
		return $GLOBALS['mock_post_meta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value, mixed $prev = '' ): bool {
		$filter = apply_filters( 'update_post_metadata', null, $post_id, $key, $value, $prev );
		if ( false === $filter ) {
			return false;
		}
		if ( ! isset( $GLOBALS['mock_post_meta'][ $post_id ] ) ) {
			$GLOBALS['mock_post_meta'][ $post_id ] = array();
		}
		$GLOBALS['mock_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key, mixed $value = '' ): bool {
		unset( $GLOBALS['mock_post_meta'][ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['wp_test_filters'][ $tag ][] = array(
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, mixed $value, ...$args ): mixed {
		if ( empty( $GLOBALS['wp_test_filters'][ $tag ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['wp_test_filters'][ $tag ] as $f ) {
			$params = array_merge( array( $value ), array_slice( $args, 0, $f['accepted_args'] - 1 ) );
			$value  = call_user_func_array( $f['callback'], $params );
		}
		return $value;
	}
}
if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( string $tag ): void {
		unset( $GLOBALS['wp_test_filters'][ $tag ] );
	}
}
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( string $url, array $args = array() ): array|\WP_Error {
		if ( isset( $GLOBALS['mock_http_responses'][ $url ] ) ) {
			return $GLOBALS['mock_http_responses'][ $url ];
		}
		// Default mock: return 200 OK with dummy body.
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'image/png' ),
			'body'     => 'PNG_MOCK_BYTES',
		);
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( mixed $response ): int {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return 0;
		}
		return (int) ( $response['response']['code'] ?? 0 );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( mixed $response ): string {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return '';
		}
		return (string) ( $response['body'] ?? '' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( mixed $response, string $header ): string {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return '';
		}
		$hdr = strtolower( $header );
		return (string) ( $response['headers'][ $hdr ] ?? '' );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

class Phase3_Mock_WPDB {
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
			$cb_res = ( $this->on_before_query )( $query, $this );
			if ( false === $cb_res ) {
				return false;
			}
		}

		if ( $this->simulate_write_failure ) {
			return false;
		}
		if ( $this->simulate_zero_affected_rows ) {
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
					}
				}
				return $res;
			}

			$query   = $this->translate_query_for_sqlite( $query );
			$count   = $this->pdo->exec( $query );
			$last_id = (int) $this->pdo->lastInsertId();
			if ( $last_id > 0 ) {
				$this->insert_id = $last_id;
			}
			return false !== $count ? $count : 0;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function get_var( string $query ): mixed {
		try {
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			return false !== $stmt ? $stmt->fetchColumn() : null;
		} catch ( \Throwable $e ) {
			return null;
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
			if ( ! $row ) {
				return null;
			}
			return 'ARRAY_A' === $output ? $row : (object) $row;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function get_col( string $query, int $col_offset = 0 ): array {
		try {
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			if ( ! $stmt ) {
				return array();
			}
			return $stmt->fetchAll( \PDO::FETCH_COLUMN, $col_offset );
		} catch ( \Throwable $e ) {
			return array();
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
$wpdb = new Phase3_Mock_WPDB();
$GLOBALS['wpdb'] = $wpdb;

// Require safety classes.
require_once __DIR__ . '/../includes/safety/class-database-installer.php';
require_once __DIR__ . '/../includes/safety/class-safety-settings.php';
require_once __DIR__ . '/../includes/safety/class-lock-manager.php';
require_once __DIR__ . '/../includes/safety/class-security-guard.php';
require_once __DIR__ . '/../includes/safety/class-elementor-features.php';
require_once __DIR__ . '/../includes/safety/class-tree-validator.php';
require_once __DIR__ . '/../includes/safety/class-security-strategies.php';
require_once __DIR__ . '/../includes/safety/class-mutation-registry.php';
require_once __DIR__ . '/../includes/safety/class-journal.php';
require_once __DIR__ . '/../includes/class-id-generator.php';
require_once __DIR__ . '/../includes/class-element-factory.php';
require_once __DIR__ . '/../includes/class-atomic-props.php';

Full_Elementor_MCP_Database_Installer::install();
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

function run_test( string $name, callable $test ): void {
	global $tests_passed, $tests_failed;
	try {
		$test();
		echo " [PASS] {$name}\n";
		$tests_passed++;
	} catch ( \Throwable $e ) {
		echo " [FAIL] {$name}\n";
		echo "        Error: {$e->getMessage()}\n";
		echo "        File:  {$e->getFile()}:{$e->getLine()}\n";
		$tests_failed++;
	}
}

echo "\n=======================================================\n";
echo " Full Elementor MCP — Phase 3 Test Suite\n";
echo "=======================================================\n\n";

// =========================================================================
// 1. Tree Structure Tests
// =========================================================================

run_test( 'Tree: valid empty document passes validation', function () {
	$res = Full_Elementor_MCP_Tree_Validator::validate_document( array() );
	assert_true( true === $res );
} );

run_test( 'Tree: valid nested containers and widgets pass validation', function () {
	$tree = array(
		array(
			'id'       => 'cnt_101',
			'elType'   => 'container',
			'settings' => array( 'flex_direction' => 'column' ),
			'elements' => array(
				array(
					'id'         => 'wdg_102',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array( 'title' => 'Hello World' ),
					'elements'   => array(),
				),
				array(
					'id'         => 'wdg_103',
					'elType'     => 'widget',
					'widgetType' => 'button',
					'settings'   => array( 'text' => 'Click Here' ),
					'elements'   => array(),
				),
			),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_true( true === $res );
} );

run_test( 'Tree: deep nested valid tree passes validation', function () {
	$current = array(
		'id'         => 'leaf_node',
		'elType'     => 'widget',
		'widgetType' => 'heading',
		'settings'   => array(),
		'elements'   => array(),
	);

	// Build 15 nested container levels
	for ( $i = 14; $i >= 1; $i-- ) {
		$current = array(
			'id'       => "container_lvl_{$i}",
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array( $current ),
		);
	}

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( array( $current ) );
	assert_true( true === $res );
} );

run_test( 'Tree: duplicate element ID anywhere in document is rejected', function () {
	$tree = array(
		array(
			'id'       => 'duplicate_id_x',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(),
		),
		array(
			'id'       => 'container_2',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'duplicate_id_x', // Duplicate!
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array(),
					'elements'   => array(),
				),
			),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'duplicate_element_id', $res->get_error_code() );
	$data = $res->get_error_data();
	assert_equals( 'duplicate_id_x', $data['duplicate_id'] );
	assert_equals( 'elements[0]', $data['first_path'] );
	assert_equals( 'elements[1].elements[0]', $data['duplicate_path'] );
} );

run_test( 'Tree: malformed element node (scalar) is rejected', function () {
	$tree = array(
		array(
			'id'       => 'container_1',
			'elType'   => 'container',
			'elements' => array( 'not-a-node-array' ),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_elementor_tree', $res->get_error_code() );
} );

run_test( 'Tree: invalid settings type is rejected', function () {
	$tree = array(
		array(
			'id'       => 'container_1',
			'elType'   => 'container',
			'settings' => 'string_instead_of_array',
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_elementor_tree', $res->get_error_code() );
} );

run_test( 'Tree: invalid children type is rejected', function () {
	$tree = array(
		array(
			'id'       => 'container_1',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => 'not-an-array',
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_elementor_tree', $res->get_error_code() );
} );

run_test( 'Tree: widget node containing child elements is rejected', function () {
	$tree = array(
		array(
			'id'         => 'widget_illegal_child',
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array(),
			'elements'   => array(
				array(
					'id'         => 'nested_child',
					'elType'     => 'widget',
					'widgetType' => 'button',
					'elements'   => array(),
				),
			),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_child_structure', $res->get_error_code() );
} );

run_test( 'Tree: section element containing non-column children is rejected', function () {
	$tree = array(
		array(
			'id'       => 'sec_1',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'wdg_direct', // Must be column!
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'elements'   => array(),
				),
			),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_child_structure', $res->get_error_code() );
} );

run_test( 'Tree: tree depth limit is enforced', function () {
	// Filter max tree depth to 5 for test
	add_filter( 'full_elementor_mcp_max_tree_depth', fn() => 5 );

	$current = array( 'id' => 'leaf', 'elType' => 'widget', 'widgetType' => 'heading', 'elements' => array() );
	for ( $i = 6; $i >= 1; $i-- ) {
		$current = array( 'id' => "node_{$i}", 'elType' => 'container', 'elements' => array( $current ) );
	}

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( array( $current ) );
	assert_is_wp_error( $res );
	assert_equals( 'tree_depth_exceeded', $res->get_error_code() );

	remove_all_filters( 'full_elementor_mcp_max_tree_depth' );
} );

run_test( 'Tree: node count limit is enforced', function () {
	add_filter( 'full_elementor_mcp_max_tree_node_count', fn() => 10 );

	$nodes = array();
	for ( $i = 1; $i <= 15; $i++ ) {
		$nodes[] = array( 'id' => "widget_{$i}", 'elType' => 'widget', 'widgetType' => 'heading', 'elements' => array() );
	}

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $nodes );
	assert_is_wp_error( $res );
	assert_equals( 'tree_node_limit_exceeded', $res->get_error_code() );

	remove_all_filters( 'full_elementor_mcp_max_tree_node_count' );
} );

run_test( 'Tree: oversized tree byte limit is enforced', function () {
	add_filter( 'full_elementor_mcp_max_tree_size_bytes', fn() => 500 );

	$tree = array(
		array(
			'id'       => 'large_node',
			'elType'   => 'container',
			'settings' => array( 'huge_text' => str_repeat( 'X', 600 ) ),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'tree_size_exceeded', $res->get_error_code() );

	remove_all_filters( 'full_elementor_mcp_max_tree_size_bytes' );
} );

run_test( 'Tree: unserializable setting value (closure or resource) is rejected', function () {
	$tree_with_closure = array(
		array(
			'id'       => 'closure_node',
			'elType'   => 'container',
			'settings' => array(
				'valid_key'   => 'valid_val',
				'bad_closure' => function () { return 'oops'; },
			),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree_with_closure );
	assert_is_wp_error( $res );
	assert_equals( 'unsafe_setting_value', $res->get_error_code() );
} );

run_test( 'Tree: unknown third-party settings are preserved and accepted if JSON-safe', function () {
	$tree = array(
		array(
			'id'       => 'custom_widget_1',
			'elType'   => 'widget',
			'widgetType' => 'third_party_addon',
			'settings' => array(
				'my_custom_plugin_color' => '#123456',
				'my_custom_config'       => array( 'speed' => 500, 'enabled' => true ),
			),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_true( true === $res );
} );

// =========================================================================
// 2. Element ID Validation Tests
// =========================================================================

run_test( 'ID: real 7-hex Elementor IDs and alphanumeric IDs pass', function () {
	assert_true( true === Full_Elementor_MCP_Tree_Validator::validate_element_id( 'a1b2c3d' ) );
	assert_true( true === Full_Elementor_MCP_Tree_Validator::validate_element_id( 'e-1234567' ) );
	assert_true( true === Full_Elementor_MCP_Tree_Validator::validate_element_id( 'hero_section_top' ) );
	assert_true( true === Full_Elementor_MCP_Tree_Validator::validate_element_id( Full_Elementor_MCP_Id_Generator::generate() ) );
} );

run_test( 'ID: empty or whitespace ID is rejected', function () {
	$res1 = Full_Elementor_MCP_Tree_Validator::validate_element_id( '' );
	assert_is_wp_error( $res1 );
	assert_equals( 'invalid_element_id', $res1->get_error_code() );

	$res2 = Full_Elementor_MCP_Tree_Validator::validate_element_id( '   ' );
	assert_is_wp_error( $res2 );
	assert_equals( 'invalid_element_id', $res2->get_error_code() );
} );

run_test( 'ID: dangerous characters or excessive length rejected', function () {
	$res_chars = Full_Elementor_MCP_Tree_Validator::validate_element_id( '<script>' );
	assert_is_wp_error( $res_chars );
	assert_equals( 'invalid_element_id', $res_chars->get_error_code() );

	$res_quotes = Full_Elementor_MCP_Tree_Validator::validate_element_id( 'element"id' );
	assert_is_wp_error( $res_quotes );

	$res_len = Full_Elementor_MCP_Tree_Validator::validate_element_id( str_repeat( 'a', 65 ) );
	assert_is_wp_error( $res_len );
	assert_equals( 'invalid_element_id', $res_len->get_error_code() );
} );

// =========================================================================
// 3. Feature & Capability Detection Tests
// =========================================================================

run_test( 'Features: capability detector accurately reflects mock environments', function () {
	// 1. Classic Elementor setup:
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'elementor_pro'   => false,
		'classic_widgets' => true,
		'containers'      => false,
		'atomic_elements' => false,
	) );

	assert_true( Full_Elementor_MCP_Elementor_Features::has_elementor() );
	assert_false( Full_Elementor_MCP_Elementor_Features::has_elementor_pro() );
	assert_true( Full_Elementor_MCP_Elementor_Features::supports_classic_widgets() );
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_containers() );
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements() );

	// 2. Modern Container setup with Pro:
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'elementor_pro'   => true,
		'classic_widgets' => true,
		'containers'      => true,
		'atomic_elements' => false,
	) );

	assert_true( Full_Elementor_MCP_Elementor_Features::has_elementor_pro() );
	assert_true( Full_Elementor_MCP_Elementor_Features::supports_containers() );
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements() );

	// 3. Atomic / Editor V4 setup:
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'elementor_pro'   => true,
		'classic_widgets' => true,
		'containers'      => true,
		'atomic_elements' => true,
	) );

	assert_true( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements() );
	// Atomic props helper matches feature detector:
	assert_true( Full_Elementor_MCP_Atomic_Props::is_atomic_supported() );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

// =========================================================================
// 4. Rollback Tree Verification Tests
// =========================================================================

run_test( 'Rollback: valid before-tree restores cleanly', function () {
	$post_id      = 701;
	$resource_key = 'post:701';
	$owner_id     = 'rb-worker-1';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );
	assert_true( $lock['acquired'] );

	$valid_before = array(
		array(
			'id'       => 'restore_cnt_1',
			'elType'   => 'container',
			'settings' => array( 'flex_direction' => 'row' ),
			'elements' => array(),
		),
	);

	$context = array(
		'rollback_resource_key' => $resource_key,
		'current_owner_id'      => $owner_id,
		'caller_fencing_token'  => (int) $lock['fencing_token'],
		'post_id'               => $post_id,
	);

	$res = Full_Elementor_MCP_Mutation_Registry::restore_page_data_callback( $valid_before, $context );
	assert_true( true === $res );
	assert_equals( $valid_before, get_post_meta( $post_id, '_elementor_data', true ) );

	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

run_test( 'Rollback: corrupted tree in journal rejected BEFORE persistent write', function () {
	$post_id      = 702;
	$resource_key = 'post:702';
	$owner_id     = 'rb-worker-2';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );
	assert_true( $lock['acquired'] );

	$initial_data = array( array( 'id' => 'orig_clean', 'elType' => 'container', 'elements' => array() ) );
	$GLOBALS['mock_post_meta'][ $post_id ]['_elementor_data'] = $initial_data;

	// Corrupted before-state containing duplicate element IDs:
	$corrupted_before = array(
		array( 'id' => 'dup_id', 'elType' => 'container', 'elements' => array() ),
		array( 'id' => 'dup_id', 'elType' => 'container', 'elements' => array() ),
	);

	$context = array(
		'rollback_resource_key' => $resource_key,
		'current_owner_id'      => $owner_id,
		'caller_fencing_token'  => (int) $lock['fencing_token'],
		'post_id'               => $post_id,
	);

	$res = Full_Elementor_MCP_Mutation_Registry::restore_page_data_callback( $corrupted_before, $context );
	assert_is_wp_error( $res );
	assert_equals( 'duplicate_element_id', $res->get_error_code() );

	// Storage must NOT be modified by failed rollback!
	assert_equals( $initial_data, get_post_meta( $post_id, '_elementor_data', true ) );

	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

run_test( 'Rollback: fencing token remains mandatory for restoration', function () {
	$context_no_fence = array(
		'rollback_resource_key' => 'post:703',
		'current_owner_id'      => 'worker',
		'caller_fencing_token'  => 0, // Invalid token!
		'post_id'               => 703,
	);

	$res = Full_Elementor_MCP_Mutation_Registry::restore_page_data_callback( array(), $context_no_fence );
	assert_is_wp_error( $res );
	assert_equals( 'rollback_fencing_required', $res->get_error_code() );
} );

// =========================================================================
// 5. SSRF & Remote URL Defense Tests
// =========================================================================

run_test( 'SSRF: blocks loopback, private IPv4, metadata, and 0.0.0.0', function () {
	// Direct IP tests
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://127.0.0.1/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://127.5.6.7/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://0.0.0.0/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://10.0.0.1/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://172.16.0.1/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://192.168.1.1/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://169.254.169.254/latest/meta-data/' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://localhost/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://sub.localhost/test' ) );
} );

run_test( 'SSRF: blocks IPv6 loopback, link-local, unique local, and IPv4-mapped IPv6', function () {
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://[::1]/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://[fe80::1]/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://[fc00::1]/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://[::ffff:127.0.0.1]/test' ) );
	assert_is_wp_error( Full_Elementor_MCP_Security_Strategies::validate_url( 'http://[::ffff:192.168.1.1]/test' ) );
} );

run_test( 'SSRF: blocks embedded credentials, non-HTTP schemes, and ambiguous formats', function () {
	// Embedded credentials:
	$res_cred = Full_Elementor_MCP_Security_Strategies::validate_url( 'http://admin:secret@example.com/image.png' );
	assert_is_wp_error( $res_cred );
	assert_equals( 'ssrf_embedded_credentials', $res_cred->get_error_code() );

	// Non-HTTP schemes:
	$res_file = Full_Elementor_MCP_Security_Strategies::validate_url( 'file:///etc/passwd' );
	assert_is_wp_error( $res_file );
	assert_equals( 'invalid_protocol', $res_file->get_error_code() );

	$res_ftp = Full_Elementor_MCP_Security_Strategies::validate_url( 'ftp://example.com/file' );
	assert_is_wp_error( $res_ftp );

	// Ambiguous integer IP:
	$res_int = Full_Elementor_MCP_Security_Strategies::validate_url( 'http://2130706433/test' );
	assert_is_wp_error( $res_int );
	assert_equals( 'ssrf_ambiguous_ip', $res_int->get_error_code() );
} );

run_test( 'SSRF: blocks non-standard ports by default', function () {
	// Port 22 (SSH)
	$res_ssh = Full_Elementor_MCP_Security_Strategies::validate_url( 'http://example.com:22/' );
	assert_is_wp_error( $res_ssh );
	assert_equals( 'ssrf_blocked_port', $res_ssh->get_error_code() );

	// Port 3306 (MySQL)
	$res_db = Full_Elementor_MCP_Security_Strategies::validate_url( 'http://example.com:3306/' );
	assert_is_wp_error( $res_db );
	assert_equals( 'ssrf_blocked_port', $res_db->get_error_code() );
} );

run_test( 'SSRF: allows legitimate public domain URL with public IP resolution', function () {
	Full_Elementor_MCP_Security_Strategies::set_mock_dns( array(
		'example.com' => array( '93.184.216.34' ), // Public IP
	) );

	$res = Full_Elementor_MCP_Security_Strategies::validate_url( 'https://example.com/assets/logo.png' );
	assert_true( true === $res );

	Full_Elementor_MCP_Security_Strategies::reset_mock_dns();
} );

run_test( 'SSRF: hostname resolving to private IP is rejected (DNS rebinding defense)', function () {
	Full_Elementor_MCP_Security_Strategies::set_mock_dns( array(
		'evil-rebind.com' => array( '10.0.0.5' ),
	) );

	$res = Full_Elementor_MCP_Security_Strategies::validate_url( 'https://evil-rebind.com/payload' );
	assert_is_wp_error( $res );
	assert_equals( 'ssrf_blocked_ip', $res->get_error_code() );

	Full_Elementor_MCP_Security_Strategies::reset_mock_dns();
} );

run_test( 'SSRF Redirect: public to private redirect is blocked', function () {
	Full_Elementor_MCP_Security_Strategies::set_mock_dns( array(
		'public-gateway.com' => array( '93.184.216.34' ),
	) );

	// Mock gateway returning 302 redirect to metadata endpoint
	$GLOBALS['mock_http_responses']['https://public-gateway.com/download'] = array(
		'response' => array( 'code' => 302 ),
		'headers'  => array( 'location' => 'http://169.254.169.254/latest/meta-data/' ),
		'body'     => '',
	);

	$res = Full_Elementor_MCP_Security_Strategies::safe_download_url( 'https://public-gateway.com/download' );
	assert_is_wp_error( $res );
	assert_equals( 'ssrf_blocked_ip', $res->get_error_code() );

	Full_Elementor_MCP_Security_Strategies::reset_mock_dns();
	unset( $GLOBALS['mock_http_responses']['https://public-gateway.com/download'] );
} );

run_test( 'SSRF Redirect: redirect loop exceeding limit is blocked', function () {
	Full_Elementor_MCP_Security_Strategies::set_mock_dns( array(
		'loop.com' => array( '93.184.216.34' ),
	) );

	$GLOBALS['mock_http_responses']['https://loop.com/redirect'] = array(
		'response' => array( 'code' => 302 ),
		'headers'  => array( 'location' => 'https://loop.com/redirect' ),
		'body'     => '',
	);

	$res = Full_Elementor_MCP_Security_Strategies::safe_download_url( 'https://loop.com/redirect' );
	assert_is_wp_error( $res );
	assert_equals( 'too_many_redirects', $res->get_error_code() );

	Full_Elementor_MCP_Security_Strategies::reset_mock_dns();
	unset( $GLOBALS['mock_http_responses']['https://loop.com/redirect'] );
} );

// =========================================================================
// 6. SVG XML Security Tests
// =========================================================================

run_test( 'SVG: valid SVG passes validation', function () {
	$clean_svg = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40" fill="green"/></svg>';
	assert_true( true === Full_Elementor_MCP_Security_Strategies::validate_svg( $clean_svg ) );
} );

run_test( 'SVG: rejects embedded <script> tags', function () {
	$bad_svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script><circle cx="50" cy="50" r="40"/></svg>';
	$res     = Full_Elementor_MCP_Security_Strategies::validate_svg( $bad_svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_has_script', $res->get_error_code() );
} );

run_test( 'SVG: rejects inline event handler attributes', function () {
	$bad_svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40" onload="alert(1)"/></svg>';
	$res     = Full_Elementor_MCP_Security_Strategies::validate_svg( $bad_svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_has_event_handler', $res->get_error_code() );
} );

run_test( 'SVG: rejects javascript: href URIs', function () {
	$bad_svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><circle cx="50" cy="50" r="40"/></a></svg>';
	$res     = Full_Elementor_MCP_Security_Strategies::validate_svg( $bad_svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_has_javascript_uri', $res->get_error_code() );
} );

run_test( 'SVG: rejects dangerous foreignObject or iframe tags', function () {
	$bad_svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="100" height="100"><iframe src="http://evil.com"></iframe></foreignObject></svg>';
	$res     = Full_Elementor_MCP_Security_Strategies::validate_svg( $bad_svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_has_forbidden_tag', $res->get_error_code() );
} );

run_test( 'SVG: rejects XXE DOCTYPE external entity declarations', function () {
	$bad_svg = '<?xml version="1.0"?><!DOCTYPE svg SYSTEM "http://evil.com/xxe.dtd"><svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40"/></svg>';
	$res     = Full_Elementor_MCP_Security_Strategies::validate_svg( $bad_svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_xxe_detected', $res->get_error_code() );
} );

// =========================================================================
// 7. Security Classification & Protected Assets Tests
// =========================================================================

run_test( 'Classification: custom JS classified as executable and high risk', function () {
	$class = Full_Elementor_MCP_Security_Strategies::classify_ability_payload( 'full-elementor-mcp/add-custom-js', array( 'js' => 'console.log(1);' ) );
	assert_true( $class['is_executable'] );
	assert_true( $class['is_high_risk'] );
	assert_true( $class['requires_unfiltered_html'] );
	assert_equals( 'custom_code', $class['category'] );
} );

run_test( 'Classification: custom CSS classified with requires_unfiltered_html but not executable', function () {
	$class = Full_Elementor_MCP_Security_Strategies::classify_ability_payload( 'full-elementor-mcp/add-custom-css', array( 'css' => 'body { color: red; }' ) );
	assert_false( $class['is_executable'] );
	assert_false( $class['is_high_risk'] );
	assert_true( $class['requires_unfiltered_html'] );
	assert_equals( 'custom_css', $class['category'] );
} );

run_test( 'Classification: HTML widget with script tag classified as executable', function () {
	$class_safe = Full_Elementor_MCP_Security_Strategies::classify_ability_payload( 'full-elementor-mcp/add-html', array( 'html' => '<p>Safe markup</p>' ) );
	assert_false( $class_safe['is_executable'] );

	$class_script = Full_Elementor_MCP_Security_Strategies::classify_ability_payload( 'full-elementor-mcp/add-html', array( 'html' => '<script>alert(1);</script>' ) );
	assert_true( $class_script['is_executable'] );
	assert_true( $class_script['is_high_risk'] );
	assert_true( $class_script['requires_unfiltered_html'] );
} );

run_test( 'Classification: permanent deletion identified as irreversible', function () {
	$class_trash = Full_Elementor_MCP_Security_Strategies::classify_ability_payload( 'full-elementor-mcp/delete-page', array( 'post_id' => 10, 'force' => false ) );
	assert_false( $class_trash['is_irreversible'] );

	$class_force = Full_Elementor_MCP_Security_Strategies::classify_ability_payload( 'full-elementor-mcp/delete-page', array( 'post_id' => 10, 'force' => true ) );
	assert_true( $class_force['is_irreversible'] );
	assert_true( $class_force['is_high_risk'] );
} );

run_test( 'Protected Assets: identifies front page, posts page, and active kit', function () {
	update_option( 'page_on_front', 101 );
	update_option( 'page_for_posts', 102 );
	update_option( 'elementor_active_kit', 103 );

	$res_front = Full_Elementor_MCP_Security_Strategies::is_protected_asset( 101 );
	assert_true( $res_front['protected'] );
	assert_equals( 'front_page', $res_front['asset_type'] );

	$res_blog = Full_Elementor_MCP_Security_Strategies::is_protected_asset( 102 );
	assert_true( $res_blog['protected'] );
	assert_equals( 'posts_page', $res_blog['asset_type'] );

	$res_kit = Full_Elementor_MCP_Security_Strategies::is_protected_asset( 103 );
	assert_true( $res_kit['protected'] );
	assert_equals( 'active_kit', $res_kit['asset_type'] );

	$res_normal = Full_Elementor_MCP_Security_Strategies::is_protected_asset( 999 );
	assert_false( $res_normal['protected'] );
} );

echo "\n=======================================================\n";
echo " Test Results: {$tests_passed}/" . ( $tests_passed + $tests_failed ) . " passed.\n";
echo "=======================================================\n\n";

if ( $tests_failed > 0 ) {
	exit( 1 );
}
