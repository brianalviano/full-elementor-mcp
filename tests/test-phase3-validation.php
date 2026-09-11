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
			$mock = $GLOBALS['mock_http_responses'][ $url ];
			if ( is_wp_error( $mock ) ) {
				return $mock;
			}
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) && isset( $mock['body'] ) ) {
				file_put_contents( $args['filename'], $mock['body'] );
			}
			return $mock;
		}
		// Default mock: return 200 OK with dummy body.
		$resp = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'image/png' ),
			'body'     => 'PNG_MOCK_BYTES',
		);
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $resp['body'] );
		}
		return $resp;
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

function assert_wp_error( mixed $thing, string $message = 'Expected WP_Error instance.' ): void {
	assert_is_wp_error( $thing, $message );
}

function assert_error_code( string $code, mixed $thing, string $message = '' ): void {
	assert_is_wp_error( $thing, $message );
	assert_equals( $code, $thing->get_error_code(), $message );
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

Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
	'elementor'       => true,
	'elementor_pro'   => true,
	'classic_widgets' => true,
	'containers'      => true,
	'nested_elements' => true,
	'atomic_elements' => true,
) );

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
	assert_equals( 'invalid_child_structure', $res->get_error_code() );
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

run_test( 'Tree: container structure fails when containers capability is disabled', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'classic_widgets' => true,
		'containers'      => false,
	) );

	$tree = array(
		array(
			'id'       => 'cnt_gate_test',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'unsupported_element_feature', $res->get_error_code() );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: container structure succeeds when containers capability is enabled', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'classic_widgets' => true,
		'containers'      => true,
	) );

	$tree = array(
		array(
			'id'       => 'cnt_gate_pass',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_true( true === $res );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: atomic structure fails when atomic capability is disabled', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'classic_widgets' => true,
		'atomic_elements' => false,
	) );

	$tree = array(
		array(
			'id'       => 'atomic_gate_test',
			'elType'   => 'e-div-block',
			'settings' => array(),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'unsupported_element_feature', $res->get_error_code() );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: atomic structure succeeds when atomic capability is enabled', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'classic_widgets' => true,
		'atomic_elements' => true,
	) );

	$tree = array(
		array(
			'id'       => 'atomic_gate_pass',
			'elType'   => 'e-div-block',
			'settings' => array(),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_true( true === $res );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: classic section and column structure succeeds without container feature', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'classic_widgets' => true,
		'containers'      => false,
	) );

	$tree = array(
		array(
			'id'       => 'classic_sec_1',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'classic_col_1',
					'elType'   => 'column',
					'settings' => array(),
					'elements' => array(
						array(
							'id'         => 'classic_wdg_1',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => 'Classic' ),
							'elements'   => array(),
						),
					),
				),
			),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_true( true === $res );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: mixed classic section and container document succeeds when containers enabled', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'classic_widgets' => true,
		'containers'      => true,
	) );

	$tree = array(
		array(
			'id'       => 'mix_sec_1',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'mix_col_1',
					'elType'   => 'column',
					'settings' => array(),
					'elements' => array(
						array(
							'id'         => 'mix_wdg_1',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'elements'   => array(),
						),
					),
				),
			),
		),
		array(
			'id'       => 'mix_cnt_1',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_true( true === $res );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: nested widget (nested-tabs) allows container children when nested_elements is enabled', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'containers'      => true,
		'nested_elements' => true,
	) );

	$tree = array(
		array(
			'id'         => 'tabs_widget_1',
			'elType'     => 'widget',
			'widgetType' => 'nested-tabs',
			'settings'   => array(),
			'elements'   => array(
				array(
					'id'       => 'tab_content_cnt_1',
					'elType'   => 'container',
					'settings' => array(),
					'elements' => array(),
				),
				array(
					'id'       => 'tab_content_cnt_2',
					'elType'   => 'container',
					'settings' => array(),
					'elements' => array(),
				),
			),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_true( true === $res );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: nested widget fails when nested_elements capability is disabled', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'containers'      => true,
		'nested_elements' => false,
	) );

	$tree = array(
		array(
			'id'         => 'tabs_widget_fail',
			'elType'     => 'widget',
			'widgetType' => 'nested-tabs',
			'settings'   => array(),
			'elements'   => array(
				array(
					'id'       => 'tab_child_cnt',
					'elType'   => 'container',
					'settings' => array(),
					'elements' => array(),
				),
			),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'unsupported_element_feature', $res->get_error_code() );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: ordinary classic widget (heading) with children is rejected even if nested_elements is enabled', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'containers'      => true,
		'nested_elements' => true,
	) );

	$tree = array(
		array(
			'id'         => 'heading_with_children',
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array(),
			'elements'   => array(
				array(
					'id'       => 'illegal_child',
					'elType'   => 'container',
					'elements' => array(),
				),
			),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_child_structure', $res->get_error_code() );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: nested widget containing non-container child is rejected', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array(
		'elementor'       => true,
		'containers'      => true,
		'nested_elements' => true,
	) );

	$tree = array(
		array(
			'id'         => 'nested_tabs_bad_child',
			'elType'     => 'widget',
			'widgetType' => 'nested-tabs',
			'settings'   => array(),
			'elements'   => array(
				array(
					'id'         => 'bad_direct_widget',
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

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: unsupported structural elType is rejected', function () {
	$tree = array(
		array(
			'id'       => 'unknown_elem',
			'elType'   => 'unsupported_custom_layout',
			'settings' => array(),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_element_type', $res->get_error_code() );
} );

run_test( 'Tree: top-level associative element map is rejected as invalid_child_structure', function () {
	$bad_tree = array(
		'first_block'  => array( 'id' => 'node_1', 'elType' => 'section', 'elements' => array() ),
		'second_block' => array( 'id' => 'node_2', 'elType' => 'section', 'elements' => array() ),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $bad_tree );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_child_structure', $res->get_error_code() );
} );

run_test( 'Tree: top-level sparse non-sequential numeric keys rejected as invalid_child_structure', function () {
	$bad_tree = array(
		0 => array( 'id' => 'node_1', 'elType' => 'section', 'elements' => array() ),
		2 => array( 'id' => 'node_2', 'elType' => 'section', 'elements' => array() ),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $bad_tree );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_child_structure', $res->get_error_code() );
} );

run_test( 'Tree: nested associative child elements collection is rejected as invalid_child_structure', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array( 'containers' => true ) );

	$bad_tree = array(
		array(
			'id'       => 'parent_cnt',
			'elType'   => 'container',
			'elements' => array(
				'child_a' => array( 'id' => 'c_a', 'elType' => 'widget', 'widgetType' => 'heading', 'elements' => array() ),
			),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $bad_tree );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_child_structure', $res->get_error_code() );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: valid sequential numeric ordered list passes and maintains exact ordering', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array( 'containers' => true ) );

	$valid_tree = array(
		array( 'id' => 'node_alpha', 'elType' => 'container', 'elements' => array() ),
		array( 'id' => 'node_beta', 'elType' => 'container', 'elements' => array() ),
		array( 'id' => 'node_gamma', 'elType' => 'container', 'elements' => array() ),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $valid_tree );
	assert_true( true === $res );

	// Confirm keys are strictly 0, 1, 2
	assert_equals( array( 0, 1, 2 ), array_keys( $valid_tree ) );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: stdClass object in settings is rejected as unsafe_setting_value', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array( 'containers' => true ) );

	$tree = array(
		array(
			'id'       => 'node_with_stdclass',
			'elType'   => 'container',
			'settings' => array(
				'my_obj' => (object) array( 'foo' => 'bar' ),
			),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'unsafe_setting_value', $res->get_error_code() );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: JsonSerializable object in settings is rejected as unsafe_setting_value', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array( 'containers' => true ) );

	$json_obj = new class implements \JsonSerializable {
		public function jsonSerialize(): mixed {
			return array( 'safe' => 'content' );
		}
	};

	$tree = array(
		array(
			'id'       => 'node_with_jsonserializable',
			'elType'   => 'container',
			'settings' => array(
				'serialized_obj' => $json_obj,
			),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_is_wp_error( $res );
	assert_equals( 'unsafe_setting_value', $res->get_error_code() );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
} );

run_test( 'Tree: JSON scalar types (null, bool, int, float, string, array) are accepted in settings', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array( 'containers' => true ) );

	$tree = array(
		array(
			'id'       => 'node_scalars',
			'elType'   => 'container',
			'settings' => array(
				'v_null'   => null,
				'v_bool'   => true,
				'v_int'    => 42,
				'v_float'  => 3.14159,
				'v_string' => 'hello world',
				'v_array'  => array( 'sub_key' => 100, 'sub_list' => array( 1, 2, 3 ) ),
			),
			'elements' => array(),
		),
	);

	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $tree );
	assert_true( true === $res );

	Full_Elementor_MCP_Elementor_Features::reset_mocks();
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

run_test( 'Features: runtime fail-closed when Elementor is absent', function () {
	Full_Elementor_MCP_Elementor_Features::reset_mocks();

	// In test environment without Elementor classes or mocks:
	assert_false( Full_Elementor_MCP_Elementor_Features::has_elementor() );
	assert_false( Full_Elementor_MCP_Elementor_Features::has_elementor_pro() );
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_containers() );
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_nested_elements() );
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements() );
	assert_false( Full_Elementor_MCP_Atomic_Props::is_atomic_supported() );
} );

run_test( 'Features: ELEMENTOR_VERSION constant alone CANNOT activate Atomic / Editor V4', function () {
	Full_Elementor_MCP_Elementor_Features::reset_mocks();

	// Even though ELEMENTOR_VERSION is defined as '4.0.0' in bootstrap,
	// without centralized capability evidence it MUST remain false.
	assert_false( Full_Elementor_MCP_Atomic_Props::is_atomic_supported() );
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements() );
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

run_test( 'SSRF: Security_Guard::validate_remote_url delegates to Security_Strategies (single truth)', function () {
	$res = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'http://127.0.0.1/admin' );
	assert_is_wp_error( $res );
	assert_equals( 'ssrf_blocked_ip', $res->get_error_code() );

	$res2 = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'file:///etc/passwd' );
	assert_is_wp_error( $res2 );
	assert_equals( 'invalid_protocol', $res2->get_error_code() );
} );

run_test( 'SSRF Redirect: relative redirect resolution normalizes relative, query, and scheme-relative URLs', function () {
	$base = 'https://example.com/dir/page.html';

	// Absolute path
	assert_equals( 'https://example.com/new/path.jpg', Full_Elementor_MCP_Security_Strategies::resolve_redirect_url( '/new/path.jpg', $base ) );

	// Relative path
	assert_equals( 'https://example.com/dir/photo.jpg', Full_Elementor_MCP_Security_Strategies::resolve_redirect_url( 'photo.jpg', $base ) );

	// Traversal
	assert_equals( 'https://example.com/up.jpg', Full_Elementor_MCP_Security_Strategies::resolve_redirect_url( '../up.jpg', $base ) );

	// Query only
	assert_equals( 'https://example.com/dir/page.html?ref=1', Full_Elementor_MCP_Security_Strategies::resolve_redirect_url( '?ref=1', $base ) );

	// Scheme-relative
	assert_equals( 'https://other-cdn.com/asset.png', Full_Elementor_MCP_Security_Strategies::resolve_redirect_url( '//other-cdn.com/asset.png', $base ) );
} );

run_test( 'Safe Download: Content-Length exceeding limit is rejected before download', function () {
	Full_Elementor_MCP_Security_Strategies::set_mock_dns( array( 'public-cdn.com' => array( '93.184.216.34' ) ) );

	$GLOBALS['mock_http_responses']['https://public-cdn.com/huge.png'] = array(
		'response' => array( 'code' => 200 ),
		'headers'  => array( 'content-length' => '20000000', 'content-type' => 'image/png' ),
		'body'     => 'CHUNK',
	);

	$res = Full_Elementor_MCP_Security_Strategies::safe_download_url( 'https://public-cdn.com/huge.png', 30, 5000000 );
	assert_is_wp_error( $res );
	assert_equals( 'remote_file_too_large', $res->get_error_code() );

	Full_Elementor_MCP_Security_Strategies::reset_mock_dns();
	unset( $GLOBALS['mock_http_responses']['https://public-cdn.com/huge.png'] );
} );

run_test( 'Safe Download: streamed body exceeding limit is rejected and temp file cleaned up', function () {
	Full_Elementor_MCP_Security_Strategies::set_mock_dns( array( 'public-cdn.com' => array( '93.184.216.34' ) ) );

	$GLOBALS['mock_http_responses']['https://public-cdn.com/stream-huge.png'] = array(
		'response' => array( 'code' => 200 ),
		'headers'  => array( 'content-type' => 'image/png' ),
		'body'     => str_repeat( 'X', 6000 ),
	);

	$res = Full_Elementor_MCP_Security_Strategies::safe_download_url( 'https://public-cdn.com/stream-huge.png', 30, 2000 );
	assert_is_wp_error( $res );
	assert_equals( 'remote_file_too_large', $res->get_error_code() );

	Full_Elementor_MCP_Security_Strategies::reset_mock_dns();
	unset( $GLOBALS['mock_http_responses']['https://public-cdn.com/stream-huge.png'] );
} );

run_test( 'Safe Download: valid download within size limit succeeds and creates valid temp file', function () {
	Full_Elementor_MCP_Security_Strategies::set_mock_dns( array( 'public-cdn.com' => array( '93.184.216.34' ) ) );

	$payload = 'VALID_IMAGE_DATA_12345';
	$GLOBALS['mock_http_responses']['https://public-cdn.com/valid.png'] = array(
		'response' => array( 'code' => 200 ),
		'headers'  => array( 'content-length' => (string) strlen( $payload ), 'content-type' => 'image/png' ),
		'body'     => $payload,
	);

	$tmp = Full_Elementor_MCP_Security_Strategies::safe_download_url( 'https://public-cdn.com/valid.png', 30, 50000 );
	assert_true( is_string( $tmp ) && file_exists( $tmp ) );
	assert_equals( $payload, file_get_contents( $tmp ) );
	@unlink( $tmp );

	Full_Elementor_MCP_Security_Strategies::reset_mock_dns();
	unset( $GLOBALS['mock_http_responses']['https://public-cdn.com/valid.png'] );
} );

// =========================================================================
// 6. SVG XML Security Tests
// =========================================================================

run_test( 'SVG: valid clean SVG passes validation', function () {
	$clean_svg = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40" fill="green"/></svg>';
	assert_true( true === Full_Elementor_MCP_Security_Strategies::validate_svg( $clean_svg ) );
} );

run_test( 'SVG: rejects foreignObject tag alone', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="100" height="100"><div>dangerous div</div></foreignObject></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_has_foreign_object', $res->get_error_code() );
} );

run_test( 'SVG: rejects mixed-case ForeignObject variant alone', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><ForeignObject width="100" height="100"><p>test</p></ForeignObject></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_has_foreign_object', $res->get_error_code() );
} );

run_test( 'SVG: rejects embedded script tag alone', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script><circle cx="50" cy="50" r="40"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_has_script', $res->get_error_code() );
} );

run_test( 'SVG: rejects inline event handler attribute alone', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40" onload="alert(1)"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_has_event_handler', $res->get_error_code() );
} );

run_test( 'SVG: rejects javascript: href URI alone', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><circle cx="50" cy="50" r="40"/></a></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_has_javascript_uri', $res->get_error_code() );
} );

run_test( 'SVG: rejects remote HTTPS href in a tag', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><a href="https://attacker.com/evil"><circle cx="50" cy="50" r="40"/></a></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_external_resource_forbidden', $res->get_error_code() );
} );

run_test( 'SVG: rejects protocol-relative href in a tag', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><a href="//attacker.com/evil"><circle cx="50" cy="50" r="40"/></a></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_external_resource_forbidden', $res->get_error_code() );
} );

run_test( 'SVG: rejects file URI in a tag', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><a href="file:///etc/passwd"><circle cx="50" cy="50" r="40"/></a></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_external_resource_forbidden', $res->get_error_code() );
} );

run_test( 'SVG: rejects image tag with remote href', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://attacker.com/leak.png"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_external_resource_forbidden', $res->get_error_code() );
} );

run_test( 'SVG: rejects use tag with remote href', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><use href="https://attacker.com/sprites.svg#icon"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_external_resource_forbidden', $res->get_error_code() );
} );

run_test( 'SVG: rejects CSS url() referencing remote resource', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>rect { fill: url("https://attacker.com/bg.png"); }</style><rect width="10" height="10"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_external_resource_forbidden', $res->get_error_code() );
} );

run_test( 'SVG: rejects CSS @import referencing remote stylesheet', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>@import "https://attacker.com/style.css";</style></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_external_resource_forbidden', $res->get_error_code() );
} );

run_test( 'SVG: rejects DOCTYPE declaration alone', function () {
	$svg = '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_equals( 'svg_doctype_forbidden', $res->get_error_code() );
} );

run_test( 'SVG: rejects ENTITY declaration alone', function () {
	$svg = '<?xml version="1.0"?><!ENTITY xxe SYSTEM "file:///etc/passwd"><svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_is_wp_error( $res );
	assert_true( in_array( $res->get_error_code(), array( 'svg_doctype_forbidden', 'svg_xxe_detected' ), true ) );
} );

run_test( 'SVG: accepts safe local fragment reference in use tag', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><circle id="dot" r="5"/></defs><use href="#dot"/></svg>';
	assert_true( true === Full_Elementor_MCP_Security_Strategies::validate_svg( $svg ) );
} );

run_test( 'SVG: accepts safe local gradient reference in fill', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="grad1"/></defs><rect fill="url(#grad1)" width="10" height="10"/></svg>';
	assert_true( true === Full_Elementor_MCP_Security_Strategies::validate_svg( $svg ) );
} );

run_test( 'SVG: accepts safe embedded raster data image', function () {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="/></svg>';
	assert_true( true === Full_Elementor_MCP_Security_Strategies::validate_svg( $svg ) );
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

run_test( 'Security Profile: add-custom-js is executable, high-risk, and requires unfiltered_html', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/add-custom-js', array( 'js' => 'alert(1);' ) );
	assert_true( $prof['executable_content'] );
	assert_true( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
	assert_equals( 'custom_code', $prof['security_category'] );
} );

run_test( 'Security Profile: add-code-snippet and update-code-snippet are executable and high-risk', function () {
	$prof_add = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/add-code-snippet', array( 'code' => 'phpinfo();' ) );
	assert_true( $prof_add['executable_content'] );
	assert_true( $prof_add['high_risk'] );

	$prof_up = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/update-code-snippet', array( 'snippet_id' => 5, 'code' => 'echo 1;' ) );
	assert_true( $prof_up['executable_content'] );
	assert_true( $prof_up['high_risk'] );
} );

run_test( 'Security Profile: add-custom-css requires unfiltered_html but is NOT executable', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/add-custom-css', array( 'css' => 'body { color: red; }' ) );
	assert_false( $prof['executable_content'] );
	assert_false( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
	assert_equals( 'custom_css', $prof['security_category'] );
} );

run_test( 'Security Profile: add-html dynamic classification detects executable script tags and handlers', function () {
	$prof_safe = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/add-html', array( 'html' => '<p>Safe Text</p>' ) );
	assert_false( $prof_safe['executable_content'] );

	$prof_script = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/add-html', array( 'html' => '<script>alert(1);</script>' ) );
	assert_true( $prof_script['executable_content'] );
	assert_true( $prof_script['high_risk'] );

	$prof_handler = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/add-html', array( 'html' => '<img src="x" onerror="alert(1)"/>' ) );
	assert_true( $prof_handler['executable_content'] );
	assert_true( $prof_handler['high_risk'] );
} );

run_test( 'Security Profile: remote network abilities have external_network_access = true', function () {
	$net_abilities = array(
		'full-elementor-mcp/add-stock-image',
		'full-elementor-mcp/sideload-image',
		'full-elementor-mcp/upload-svg-icon',
		'full-elementor-mcp/search-images',
		'full-elementor-mcp/get-image-details',
	);

	foreach ( $net_abilities as $ab ) {
		$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile( $ab, array() );
		assert_true( $prof['external_network_access'], "Ability {$ab} must have external_network_access = true" );
	}
} );

run_test( 'Security Profile: irreversible permanent deletion identified when force = true', function () {
	$prof_trash = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/delete-page', array( 'post_id' => 10, 'force' => false ) );
	assert_false( $prof_trash['irreversible'] );

	$prof_force = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/delete-page', array( 'post_id' => 10, 'force' => true ) );
	assert_true( $prof_force['irreversible'] );
	assert_true( $prof_force['high_risk'] );
} );

run_test( 'Security Profile: unknown ability fails closed with unknown category and high_risk', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/unregistered-secret-ability', array() );
	assert_equals( 'unknown', $prof['security_category'] );
	assert_true( $prof['high_risk'] );
} );

run_test( 'Security Profile: Mutation_Registry delegates to authoritative Security_Strategies profile', function () {
	$reg_prof = Full_Elementor_MCP_Mutation_Registry::get_security_profile( 'full-elementor-mcp/add-custom-js', array() );
	$sec_prof = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/add-custom-js', array() );
	assert_equals( $sec_prof, $reg_prof );
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

// =========================================================================
// 5. Phase 3 Corrective Pass Regression Tests
// =========================================================================

// --- Group A: SVG Security (Fail closed & Presentation attributes) ---

run_test( 'SVG: fails closed with svg_parser_unavailable when secure XML parser is unavailable', function () {
	Full_Elementor_MCP_Security_Strategies::set_mock_dom_available( false );
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( '<svg><rect width="10" height="10"/></svg>' );
	Full_Elementor_MCP_Security_Strategies::set_mock_dom_available( null );

	assert_wp_error( $res );
	assert_error_code( 'svg_parser_unavailable', $res );
} );

run_test( 'SVG: rejects fill attribute containing remote url()', function () {
	$svg = '<svg><rect fill="url(https://evil.example/pattern.svg#x)" width="10" height="10"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_wp_error( $res );
	assert_error_code( 'svg_external_resource_forbidden', $res );
} );

run_test( 'SVG: rejects filter attribute containing remote url()', function () {
	$svg = '<svg><rect filter="url(https://evil.example/filter.svg#x)" width="10" height="10"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_wp_error( $res );
	assert_error_code( 'svg_external_resource_forbidden', $res );
} );

run_test( 'SVG: rejects mask attribute containing protocol-relative url()', function () {
	$svg = '<svg><rect mask="url(//evil.example/mask.svg)" width="10" height="10"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_wp_error( $res );
	assert_error_code( 'svg_external_resource_forbidden', $res );
} );

run_test( 'SVG: rejects clip-path attribute containing file url()', function () {
	$svg = '<svg><rect clip-path="url(file:///etc/passwd)" width="10" height="10"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_wp_error( $res );
	assert_error_code( 'svg_external_resource_forbidden', $res );
} );

run_test( 'SVG: rejects marker-start attribute containing relative url()', function () {
	$svg = '<svg><path marker-start="url(evil.svg#arrow)" d="M0,0 L10,10"/></svg>';
	$res = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg );
	assert_wp_error( $res );
	assert_error_code( 'svg_external_resource_forbidden', $res );
} );

run_test( 'SVG: accepts local fragment and raster data URI in presentation attributes', function () {
	$svg_local = '<svg><defs><linearGradient id="grad1"/></defs><rect fill="url(#grad1)" width="10" height="10"/></svg>';
	$res_local = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg_local );
	assert_true( $res_local );

	$svg_data = '<svg><rect fill="url(data:image/png;base64,iVBORw0KGgo=)" width="10" height="10"/></svg>';
	$res_data = Full_Elementor_MCP_Security_Strategies::validate_svg( $svg_data );
	assert_true( $res_data );
} );

// --- Group B: Universal Mutation & Subtree Security Classification ---

run_test( 'Security Profile: add-widget with HTML and script tag is classified as executable and high-risk', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/add-widget',
		array(
			'widget_type' => 'html',
			'settings'    => array( 'html' => '<script>alert("pwned")</script>' ),
		)
	);
	assert_true( $prof['executable_content'] );
	assert_true( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: add-widget with safe HTML requires unfiltered_html but is not executable', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/add-widget',
		array(
			'widget_type' => 'html',
			'settings'    => array( 'html' => '<p>Hello World</p>' ),
		)
	);
	assert_false( $prof['executable_content'] );
	assert_false( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: update-widget HTML payload identifies target widget from tree', function () {
	$post_id    = 555;
	$element_id = 'html_widget_target';
	$tree       = array(
		array(
			'id'         => $element_id,
			'elType'     => 'widget',
			'widgetType' => 'html',
			'settings'   => array( 'html' => '<p>Original</p>' ),
		),
	);
	update_post_meta( $post_id, '_elementor_data', json_encode( $tree ) );

	$prof_exec = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/update-widget',
		array(
			'post_id'    => $post_id,
			'element_id' => $element_id,
			'settings'   => array( 'html' => '<script>stealCookies()</script>' ),
		)
	);
	assert_true( $prof_exec['executable_content'] );
	assert_true( $prof_exec['high_risk'] );
	assert_true( $prof_exec['requires_unfiltered_html'] );

	$prof_safe = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/update-widget',
		array(
			'post_id'    => $post_id,
			'element_id' => $element_id,
			'settings'   => array( 'html' => '<p>Updated Safe Text</p>' ),
		)
	);
	assert_false( $prof_safe['executable_content'] );
	assert_false( $prof_safe['high_risk'] );
	assert_true( $prof_safe['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: update-element fails conservatively when target cannot be resolved but payload contains script', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/update-element',
		array(
			'post_id'    => 99999, // Unresolvable post
			'element_id' => 'nonexistent',
			'settings'   => array( 'code' => '<script>dangerous()</script>' ),
		)
	);
	assert_true( $prof['executable_content'] );
	assert_true( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: batch-update with safe operations only is not executable or high-risk', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/batch-update',
		array(
			'post_id'    => 10,
			'operations' => array(
				array(
					'element_id' => 'safe_heading_1',
					'settings'   => array( 'title' => 'Heading One' ),
				),
				array(
					'element_id' => 'safe_button_1',
					'settings'   => array( 'text' => 'Click Me', 'size' => 'md' ),
				),
			),
		)
	);
	assert_false( $prof['executable_content'] );
	assert_false( $prof['high_risk'] );
	assert_false( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: batch-update with one executable operation marks whole mutation high-risk', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/batch-update',
		array(
			'post_id'    => 10,
			'operations' => array(
				array(
					'element_id' => 'safe_heading',
					'settings'   => array( 'title' => 'Plain Heading' ),
				),
				array(
					'element_id' => 'dangerous_html',
					'settings'   => array( 'html' => '<iframe src="javascript:evil()"/>' ),
				),
			),
		)
	);
	assert_true( $prof['executable_content'] );
	assert_true( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: batch-update with safe raw HTML requires unfiltered_html without executable risk', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/batch-update',
		array(
			'post_id'    => 10,
			'operations' => array(
				array(
					'element_id' => 'safe_heading',
					'settings'   => array( 'title' => 'Plain Heading' ),
				),
				array(
					'element_id' => 'safe_html_widget',
					'settings'   => array( 'html' => '<div class="alert">Safe Static Notice</div>' ),
				),
			),
		)
	);
	assert_false( $prof['executable_content'] );
	assert_false( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: replace-element with safe ordinary subtree is not high-risk or unfiltered_html', function () {
	$subtree_plain = array(
		'id'       => 'parent_cont_plain',
		'elType'   => 'container',
		'elements' => array(
			array(
				'id'         => 'child_heading',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'Harmless Title' ),
			),
			array(
				'id'         => 'child_button',
				'elType'     => 'widget',
				'widgetType' => 'button',
				'settings'   => array( 'text' => 'Click Here' ),
			),
		),
	);

	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/replace-element',
		array(
			'post_id'     => 10,
			'element_id'  => 'target_el',
			'replacement' => $subtree_plain,
		)
	);
	assert_false( $prof['executable_content'] );
	assert_false( $prof['high_risk'] );
	assert_false( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: replace-element with safe HTML widget subtree requires unfiltered_html without executable risk', function () {
	$subtree_safe = array(
		'id'       => 'parent_cont_2',
		'elType'   => 'container',
		'elements' => array(
			array(
				'id'         => 'child_html_2',
				'elType'     => 'widget',
				'widgetType' => 'html',
				'settings'   => array( 'html' => '<div>Clean and harmless</div>' ),
			),
		),
	);

	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/replace-element',
		array(
			'post_id'     => 10,
			'element_id'  => 'target_el',
			'replacement' => $subtree_safe,
		)
	);
	assert_false( $prof['executable_content'] );
	assert_false( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: replace-element with HTML widget subtree containing script is executable and high-risk', function () {
	$subtree_exec = array(
		'id'       => 'parent_cont',
		'elType'   => 'container',
		'elements' => array(
			array(
				'id'         => 'child_html',
				'elType'     => 'widget',
				'widgetType' => 'html',
				'settings'   => array( 'html' => '<img src=x onerror=alert(1)>' ),
			),
		),
	);

	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/replace-element',
		array(
			'post_id'     => 10,
			'element_id'  => 'target_el',
			'replacement' => $subtree_exec,
		)
	);
	assert_true( $prof['executable_content'] );
	assert_true( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: replace-element with deeply nested executable HTML descendant propagates to entire profile', function () {
	$subtree_deep = array(
		'id'       => 'root_section',
		'elType'   => 'section',
		'elements' => array(
			array(
				'id'       => 'col_1',
				'elType'   => 'column',
				'elements' => array(
					array(
						'id'       => 'inner_cont',
						'elType'   => 'container',
						'elements' => array(
							array(
								'id'         => 'deep_html_widget',
								'elType'     => 'widget',
								'widgetType' => 'html',
								'settings'   => array( 'html' => '<script>window.pwned=true;</script>' ),
							),
						),
					),
				),
			),
		),
	);

	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/replace-element',
		array(
			'post_id'     => 10,
			'element_id'  => 'target_el',
			'replacement' => $subtree_deep,
		)
	);
	assert_true( $prof['executable_content'] );
	assert_true( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Profile: replace-element with custom_css descendant requires unfiltered_html but not executable', function () {
	$subtree_css = array(
		'id'       => 'parent_cont_css',
		'elType'   => 'container',
		'elements' => array(
			array(
				'id'         => 'child_widget_css',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array(
					'title'      => 'Styled Heading',
					'custom_css' => '.styled-heading { color: red; }',
				),
			),
		),
	);

	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/replace-element',
		array(
			'post_id'     => 10,
			'element_id'  => 'target_el',
			'replacement' => $subtree_css,
		)
	);
	assert_false( $prof['executable_content'] );
	assert_false( $prof['high_risk'] );
	assert_true( $prof['requires_unfiltered_html'] );
} );

run_test( 'Security Contract Guard: verified against production ability input schemas', function () {
	// 1. add-widget contract: post_id, parent_id, widget_type, settings
	$add_widget_args = array(
		'post_id'     => 10,
		'parent_id'   => 'cont_1',
		'widget_type' => 'html',
		'settings'    => array( 'html' => '<script>alert(1)</script>' ),
	);
	$prof_add = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/add-widget', $add_widget_args );
	assert_true( $prof_add['executable_content'], 'add-widget contract mismatch' );

	// 2. update-widget contract: post_id, element_id, settings
	$update_widget_args = array(
		'post_id'    => 10,
		'element_id' => 'w_1',
		'settings'   => array( 'custom_css' => 'body { background: #000; }' ),
	);
	$prof_upd = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/update-widget', $update_widget_args );
	assert_true( $prof_upd['requires_unfiltered_html'], 'update-widget contract mismatch' );

	// 3. update-element contract: post_id, element_id, settings
	$update_element_args = array(
		'post_id'    => 10,
		'element_id' => 'el_1',
		'settings'   => array( 'code' => '<script>console.log(1)</script>' ),
	);
	$prof_el = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/update-element', $update_element_args );
	assert_true( $prof_el['executable_content'], 'update-element contract mismatch' );

	// 4. batch-update contract: post_id, operations -> array of [element_id, settings]
	$batch_args = array(
		'post_id'    => 10,
		'operations' => array(
			array( 'element_id' => 'e1', 'settings' => array( 'html' => '<embed src="malware.swf"/>' ) ),
		),
	);
	$prof_batch = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/batch-update', $batch_args );
	assert_true( $prof_batch['executable_content'], 'batch-update contract mismatch' );

	// 5. replace-element contract: post_id, element_id, replacement -> full node
	$replace_args = array(
		'post_id'     => 10,
		'element_id'  => 'e1',
		'replacement' => array(
			'elType'   => 'widget',
			'settings' => array( 'html' => '<iframe src="evil.com"/>' ),
		),
	);
	$prof_replace = Full_Elementor_MCP_Security_Strategies::get_security_profile( 'full-elementor-mcp/replace-element', $replace_args );
	assert_true( $prof_replace['executable_content'], 'replace-element contract mismatch' );
} );

run_test( 'Security Profile: generic custom_css payload requires unfiltered_html but is not executable', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/update-widget',
		array(
			'post_id'    => 10,
			'element_id' => 'el_css',
			'settings'   => array( 'custom_css' => 'selector { font-size: 16px; border: 1px solid #000; }' ),
		)
	);
	assert_false( $prof['executable_content'] );
	assert_true( $prof['requires_unfiltered_html'] );
} );

// --- Group C: Protected Resources (popup_id, snippet_id, template_id, kit) ---

run_test( 'Protected Resources: recognizes protected popup ID and snippet ID via Mutation Registry', function () {
	update_option( 'page_on_front', 201 );

	// Protected popup:
	$prof_popup = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/set-popup-settings',
		array( 'popup_id' => 201 )
	);
	assert_true( $prof_popup['protected_resource_possible'] );
	assert_true( $prof_popup['high_risk'] );

	// Protected snippet:
	$prof_snippet = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/delete-code-snippet',
		array( 'snippet_id' => 201 )
	);
	assert_true( $prof_snippet['protected_resource_possible'] );
	assert_true( $prof_snippet['high_risk'] );

	// Protected template:
	$prof_template = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/delete-template',
		array( 'template_id' => 201 )
	);
	assert_true( $prof_template['protected_resource_possible'] );
	assert_true( $prof_template['high_risk'] );
} );

run_test( 'Protected Resources: global kit mutations affect active kit and site-wide state', function () {
	update_option( 'elementor_active_kit', 301 );

	// update-global-colors mutates active kit even without kit_id:
	$prof_colors = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/update-global-colors',
		array()
	);
	assert_true( $prof_colors['protected_resource_possible'] );
	assert_true( $prof_colors['high_risk'] );

	// update-global-typography mutates active kit:
	$prof_typo = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/update-global-typography',
		array()
	);
	assert_true( $prof_typo['protected_resource_possible'] );
	assert_true( $prof_typo['high_risk'] );

	// set-active-kit switches design kit site-wide:
	$prof_set = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/set-active-kit',
		array( 'kit_id' => 302 )
	);
	assert_true( $prof_set['protected_resource_possible'] );
	assert_true( $prof_set['high_risk'] );
} );

// --- Group D: Irreversible Permanent Deletion Strict Booleans ---

run_test( 'Security Profile: irreversible deletion strictly enforces boolean true for force', function () {
	// Literal boolean true triggers irreversible:
	$prof_true = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/delete-page',
		array( 'post_id' => 10, 'force' => true )
	);
	assert_true( $prof_true['irreversible'] );
	assert_equals( 'permanent_deletion', $prof_true['security_category'] );

	// String "true" does NOT trigger irreversible:
	$prof_str_true = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/delete-page',
		array( 'post_id' => 10, 'force' => 'true' )
	);
	assert_false( $prof_str_true['irreversible'] );

	// String "false" does NOT trigger irreversible:
	$prof_str_false = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/delete-page',
		array( 'post_id' => 10, 'force' => 'false' )
	);
	assert_false( $prof_str_false['irreversible'] );

	// String "1" does NOT trigger irreversible:
	$prof_str_one = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/delete-page',
		array( 'post_id' => 10, 'force' => '1' )
	);
	assert_false( $prof_str_one['irreversible'] );
} );

// --- Group E: Tree Value and Shape Validations ---

run_test( 'Tree: arbitrary PHP object outside settings is rejected before serialization', function () {
	$doc = array(
		array(
			'id'         => 'node_obj_top',
			'elType'     => 'section',
			'elements'   => array(),
			'custom_obj' => new \stdClass(),
		),
	);
	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $doc );
	assert_wp_error( $res );
	assert_error_code( 'unsafe_node_value', $res );
} );

run_test( 'Tree: PHP resource outside settings is rejected before serialization', function () {
	$fp  = tmpfile();
	$doc = array(
		array(
			'id'         => 'node_res_top',
			'elType'     => 'section',
			'elements'   => array(),
			'custom_res' => $fp,
		),
	);
	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $doc );
	fclose( $fp );
	assert_wp_error( $res );
	assert_error_code( 'unsafe_node_value', $res );
} );

run_test( 'Tree: invalid atomic styles shape is rejected', function () {
	$doc = array(
		array(
			'id'       => 'node_bad_styles',
			'elType'   => 'section',
			'elements' => array(),
			'styles'   => 'corrupt_string_not_array',
		),
	);
	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $doc );
	assert_wp_error( $res );
	assert_error_code( 'invalid_atomic_structure', $res );
} );

run_test( 'Tree: invalid interactions shape is rejected', function () {
	$doc = array(
		array(
			'id'           => 'node_bad_interact',
			'elType'       => 'section',
			'elements'     => array(),
			'interactions' => 12345,
		),
	);
	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $doc );
	assert_wp_error( $res );
	assert_error_code( 'invalid_atomic_structure', $res );
} );

run_test( 'Tree: legitimate atomic element node with valid structures passes', function () {
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array( 'atomic_elements' => true ) );

	$doc = array(
		array(
			'id'              => 'valid_atomic_node',
			'elType'          => 'e-div-block',
			'elements'        => array(),
			'styles'          => array( 'desktop' => array( 'display' => 'flex' ) ),
			'interactions'    => array(),
			'editor_settings' => array( 'title' => 'Div Block' ),
			'version'         => '4.0.0',
		),
	);
	$res = Full_Elementor_MCP_Tree_Validator::validate_document( $doc );
	Full_Elementor_MCP_Elementor_Features::reset_mocks();

	assert_true( $res );
} );

// --- Group F: Runtime Capability Detection without Mocks ---

run_test( 'Features: runtime capability detection against real Elementor mock classes', function () {
	Full_Elementor_MCP_Elementor_Features::reset_mocks();

	// Define runtime mock classes conditionally:
	if ( ! class_exists( 'Elementor\Modules\AtomicWidgets\Module' ) ) {
		eval('
			namespace Elementor\Modules\AtomicWidgets;
			class Module {
				const EXPERIMENT_NAME = "e_atomic_elements";
				public static bool $active_flag = false;
				public static function is_active(): bool {
					return self::$active_flag;
				}
			}
		');
	}

	if ( ! class_exists( 'Elementor\Plugin' ) ) {
		eval('
			namespace Elementor;
			class MockExperiments {
				public array $features = [];
				public function is_feature_active( string $name ): bool {
					return ! empty( $this->features[ $name ] );
				}
			}
			class MockElementsManager {
				public array $element_types = [];
				public function get_element_types(): array {
					return $this->element_types;
				}
			}
			class MockWidgetsManager {
				public array $widget_types = [];
				public function get_widget_types( string $name = "" ): mixed {
					if ( "" !== $name ) {
						return $this->widget_types[ $name ] ?? null;
					}
					return $this->widget_types;
				}
			}
			class Plugin {
				public static ?Plugin $instance = null;
				public MockExperiments $experiments;
				public MockElementsManager $elements_manager;
				public MockWidgetsManager $widgets_manager;

				public function __construct() {
					$this->experiments = new MockExperiments();
					$this->elements_manager = new MockElementsManager();
					$this->widgets_manager = new MockWidgetsManager();
				}
			}
		');
	}

	if ( ! class_exists( 'Elementor\Modules\NestedElements\Base\Widget_Nested_Base' ) ) {
		eval('
			namespace Elementor\Modules\NestedElements\Base;
			class Widget_Nested_Base {}
		');
	}

	// Instantiate Plugin singleton:
	\Elementor\Plugin::$instance = new \Elementor\Plugin();

	// 1. Module class available + is_active() === false -> false:
	\Elementor\Modules\AtomicWidgets\Module::$active_flag = false;
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements() );

	// 2. Module class available + is_active() === true -> true:
	\Elementor\Modules\AtomicWidgets\Module::$active_flag = true;
	assert_true( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements() );

	// Reset module is_active back to false:
	\Elementor\Modules\AtomicWidgets\Module::$active_flag = false;

	// 3. Registered atomic element types in elements_manager -> true:
	\Elementor\Plugin::$instance->elements_manager->element_types = array( 'e-div-block' => new \stdClass() );
	assert_true( Full_Elementor_MCP_Elementor_Features::supports_atomic_elements() );
	\Elementor\Plugin::$instance->elements_manager->element_types = array();

	// 4. Containers: experiment inactive -> false; active -> true; registered -> true:
	\Elementor\Plugin::$instance->experiments->features['container'] = false;
	assert_false( Full_Elementor_MCP_Elementor_Features::supports_containers() );

	\Elementor\Plugin::$instance->experiments->features['container'] = true;
	assert_true( Full_Elementor_MCP_Elementor_Features::supports_containers() );
	\Elementor\Plugin::$instance->experiments->features['container'] = false;

	\Elementor\Plugin::$instance->elements_manager->element_types = array( 'container' => new \stdClass() );
	assert_true( Full_Elementor_MCP_Elementor_Features::supports_containers() );
	\Elementor\Plugin::$instance->elements_manager->element_types = array();

	// 5. Nested elements: experiment active -> true; widget instance -> true:
	\Elementor\Plugin::$instance->experiments->features['nested-elements'] = true;
	assert_true( Full_Elementor_MCP_Elementor_Features::supports_nested_elements() );
	\Elementor\Plugin::$instance->experiments->features['nested-elements'] = false;

	$nested_instance = new \Elementor\Modules\NestedElements\Base\Widget_Nested_Base();
	\Elementor\Plugin::$instance->widgets_manager->widget_types = array( 'custom-nested' => $nested_instance );
	assert_true( Full_Elementor_MCP_Elementor_Features::supports_nested_elements() );
	\Elementor\Plugin::$instance->widgets_manager->widget_types = array();

	// Clear Plugin instance:
	\Elementor\Plugin::$instance = null;
} );

echo "\n=======================================================\n";
echo " Test Results: {$tests_passed}/" . ( $tests_passed + $tests_failed ) . " passed.\n";
echo "=======================================================\n\n";

if ( $tests_failed > 0 ) {
	exit( 1 );
}
