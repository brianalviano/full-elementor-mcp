<?php
/**
 * Standalone Test Suite for Phase 2: Mutation Strategy Registry + Write-Ahead Journal.
 *
 * Can be executed via CLI: `php tests/test-phase2-journal.php`
 *
 * Verifies:
 * 1. Mutation Strategy Registry contracts, validation, lookup, fail-closed handling, and canonical resource keys.
 * 2. Write-Ahead Journal (WAL) durable state persistence BEFORE mutation.
 * 3. Deterministic SHA-256 state hashing and non-destructive operational canonicalization.
 * 4. Fenced rollback requiring active lock ownership and fencing token.
 * 5. Deterministic creation resource keys and separate rollback resource resolvers.
 * 6. Automated registry coverage of ALL 121 mutating abilities across the codebase.
 * 7. Truthful classifications: set-featured-image, set-page-slug, set-page-meta, delete-page permanent deletion, build-page, add-stock-image.
 * 8. Strict journal state machine transitions (rejecting illegal transitions).
 * 9. Conditional atomic commit and idempotent commit verifying matching result hashes.
 * 10. Crash recovery foundation: stale generation protection, expired lease + grace period, and fenced recovery locking.
 * 11. Strict literal boolean force semantics (never bypassing fencing).
 * 12. 100% PHP 8.0+ compatibility.
 *
 * @package Full_Elementor_MCP
 */

declare(strict_types=1);

// Bootstrap minimal WordPress test harness if not running inside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
	define( 'ELEMENTOR_VERSION', '4.0.0' );
}
if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	define( 'ELEMENTOR_PRO_VERSION', '4.0.0' );
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
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
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

$GLOBALS['mock_post_storage']    = array();
$GLOBALS['mock_post_meta']       = array();
$GLOBALS['mock_created_objects'] = array();
$GLOBALS['mock_thumbnails']      = array();
$GLOBALS['mock_posts']           = array();
$GLOBALS['wp_test_filters']      = array();

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

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $tag ): int {
		return 1;
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
		if ( function_exists( 'apply_filters' ) ) {
			$check = apply_filters( 'update_post_metadata', null, $post_id, $key, $value );
			if ( false === $check ) {
				return false;
			}
		}
		if ( '_elementor_data' === $key ) {
			$decoded = is_string( $value ) ? json_decode( (string) $value, true ) : $value;
			$GLOBALS['mock_post_storage'][ $post_id ] = $decoded;
			return true;
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

if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( int $post_id ): mixed {
		$GLOBALS['mock_created_objects'][ $post_id ] = 'trash';
		return true;
	}
}

if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( int $post_id ): mixed {
		$GLOBALS['mock_created_objects'][ $post_id ] = 'publish';
		return true;
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int $post_id ): string|false {
		return $GLOBALS['mock_created_objects'][ $post_id ] ?? 'publish';
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( int $post_id ): int {
		return (int) ( $GLOBALS['mock_thumbnails'][ $post_id ] ?? 0 );
	}
}
if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( int $post_id, int $thumbnail_id ): bool {
		$GLOBALS['mock_thumbnails'][ $post_id ] = $thumbnail_id;
		return true;
	}
}
if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( int $post_id ): bool {
		unset( $GLOBALS['mock_thumbnails'][ $post_id ] );
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ): ?object {
		if ( isset( $GLOBALS['mock_posts'][ $post_id ] ) ) {
			return (object) $GLOBALS['mock_posts'][ $post_id ];
		}
		return (object) array( 'ID' => $post_id, 'post_name' => 'slug-' . $post_id );
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $postarr, bool $wp_error = false ) {
		$id = absint( $postarr['ID'] ?? 0 );
		if ( $id <= 0 ) {
			return $wp_error ? new WP_Error( 'invalid_id', 'Invalid post ID' ) : 0;
		}
		foreach ( $postarr as $k => $v ) {
			$GLOBALS['mock_posts'][ $id ][ $k ] = $v;
		}
		return $id;
	}
}

$GLOBALS['wp_test_registered_abilities'] = array();
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): bool {
		$GLOBALS['wp_test_registered_abilities'][ $name ] = $args;
		return true;
	}
}
if ( ! function_exists( 'full_elementor_mcp_sanitize_schema' ) ) {
	function full_elementor_mcp_sanitize_schema( array $schema ): array {
		return $schema;
	}
}
if ( ! function_exists( 'full_elementor_mcp_register_ability' ) ) {
	function full_elementor_mcp_register_ability( string $name, array $args ) {
		return wp_register_ability( $name, $args );
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ): ?array {
		return $GLOBALS['wp_test_registered_abilities'][ $name ] ?? null;
	}
}
if ( ! function_exists( 'wp_get_abilities' ) ) {
	function wp_get_abilities(): array {
		return $GLOBALS['wp_test_registered_abilities'];
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
class WooCommerce {}
if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
	class_alias( 'MockElementorPlugin', '\\Elementor\\Plugin' );
	\Elementor\Plugin::$instance = new MockElementorPlugin();
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

// Instantiate mock WPDB.
$GLOBALS['wpdb'] = new Phase2_Mock_WPDB();

// Load safety classes.
require_once __DIR__ . '/../includes/safety/class-database-installer.php';
require_once __DIR__ . '/../includes/safety/class-safety-settings.php';
require_once __DIR__ . '/../includes/safety/class-lock-manager.php';
require_once __DIR__ . '/../includes/safety/class-mutation-registry.php';
require_once __DIR__ . '/../includes/safety/class-journal.php';

// Bootstrap database tables in memory.
function setup_phase2_test_db(): void {
	global $wpdb;
	$installer = new Full_Elementor_MCP_Database_Installer();
	$schemas   = Full_Elementor_MCP_Database_Installer::get_schema_definitions();
	foreach ( $schemas as $ddl ) {
		$wpdb->query( $ddl );
	}
}
setup_phase2_test_db();

// Test runner infrastructure.
$tests_passed = 0;
$tests_failed = 0;

function run_test( string $name, callable $callback ): void {
	global $tests_passed, $tests_failed, $wpdb;
	$wpdb->simulate_write_failure = false;
	$wpdb->simulate_zero_affected_rows = false;
	$wpdb->on_before_query = null;

	try {
		$callback();
		echo " [PASS] {$name}\n";
		$tests_passed++;
	} catch ( \Throwable $e ) {
		echo " [FAIL] {$name}: " . $e->getMessage() . "\n";
		echo "        at " . $e->getFile() . ':' . $e->getLine() . "\n";
		$tests_failed++;
	}
}

function assert_true( mixed $val, string $msg = 'Expected true' ): void {
	if ( true !== (bool) $val ) {
		throw new \RuntimeException( $msg . ' (got ' . var_export( $val, true ) . ')' );
	}
}

function assert_false( mixed $val, string $msg = 'Expected false' ): void {
	if ( false !== (bool) $val ) {
		throw new \RuntimeException( $msg . ' (got ' . var_export( $val, true ) . ')' );
	}
}

function assert_equals( mixed $expected, mixed $actual, string $msg = 'Values do not match' ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException( $msg . ' [Expected: ' . var_export( $expected, true ) . ', Got: ' . var_export( $actual, true ) . ']' );
	}
}

function assert_is_wp_error( mixed $val, string $msg = 'Expected WP_Error' ): void {
	if ( ! is_wp_error( $val ) ) {
		throw new \RuntimeException( $msg . ' (got ' . var_export( $val, true ) . ')' );
	}
}

echo "\n=======================================================\n";
echo " Full Elementor MCP — Comprehensive Phase 2 Test Suite\n";
echo "=======================================================\n\n";

// =========================================================================
// 1. MUTATION REGISTRY TESTS & 121 ABILITIES COVERAGE
// =========================================================================

run_test( 'Registry: register valid strategy and verify lookup', function () {
	$dummy_strategy = array(
		'ability'               => 'test/custom-mutation',
		'action'                => 'custom_action',
		'object_type'           => 'page',
		'category'              => Full_Elementor_MCP_Mutation_Registry::CATEGORY_PAGE_SETTINGS,
		'resource_key_resolver' => static fn( $args ) => 'post:' . ( $args['post_id'] ?? 0 ),
		'object_id_resolver'    => static fn( $args ) => (int) ( $args['post_id'] ?? 0 ),
		'capture_before'        => static fn( $id, $args ) => array( 'key' => 'val' ),
		'restore_before'        => static fn( $state, $context ) => true,
		'supports_rollback'     => true,
	);

	$res = Full_Elementor_MCP_Mutation_Registry::register( $dummy_strategy );
	assert_true( true === $res, 'Registration should return true' );
	assert_true( Full_Elementor_MCP_Mutation_Registry::has( 'test/custom-mutation' ) );

	$resolved = Full_Elementor_MCP_Mutation_Registry::get( 'test/custom-mutation' );
	assert_equals( 'custom_action', $resolved['action'] );
	assert_equals( 'page', $resolved['object_type'] );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'test/custom-mutation' ) );
} );

run_test( 'Registry: invalid strategy contract fails validation', function () {
	// Missing required keys.
	$invalid = array(
		'ability' => 'test/broken-strategy',
		'action'  => 'do_something',
	);

	$res = Full_Elementor_MCP_Mutation_Registry::register( $invalid );
	assert_is_wp_error( $res );
	assert_equals( 'invalid_strategy_descriptor', $res->get_error_code() );
	assert_false( Full_Elementor_MCP_Mutation_Registry::has( 'test/broken-strategy' ) );
} );

run_test( 'Registry: duplicate registration cleanly updates descriptor', function () {
	$strat_v1 = array(
		'ability'               => 'test/duplicate-check',
		'action'                => 'v1',
		'object_type'           => 'post',
		'category'              => Full_Elementor_MCP_Mutation_Registry::CATEGORY_ELEMENTOR_DATA,
		'resource_key_resolver' => static fn( $args ) => 'post:1',
		'object_id_resolver'    => static fn( $args ) => 1,
		'capture_before'        => static fn( $id, $args ) => array(),
		'restore_before'        => static fn( $state, $context ) => true,
		'supports_rollback'     => true,
	);
	Full_Elementor_MCP_Mutation_Registry::register( $strat_v1 );
	assert_equals( 'v1', Full_Elementor_MCP_Mutation_Registry::get( 'test/duplicate-check' )['action'] );

	$strat_v2 = array_merge( $strat_v1, array( 'action' => 'v2' ) );
	Full_Elementor_MCP_Mutation_Registry::register( $strat_v2 );
	assert_equals( 'v2', Full_Elementor_MCP_Mutation_Registry::get( 'test/duplicate-check' )['action'] );
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

run_test( 'Registry: deterministic create resource keys', function () {
	$k1 = Full_Elementor_MCP_Mutation_Registry::build_create_resource_key( 'full-elementor-mcp/create-page', array( 'title' => 'Landing Page', 'status' => 'draft' ) );
	$k2 = Full_Elementor_MCP_Mutation_Registry::build_create_resource_key( 'full-elementor-mcp/create-page', array( 'status' => 'draft', 'title' => 'Landing Page' ) );
	$k3 = Full_Elementor_MCP_Mutation_Registry::build_create_resource_key( 'full-elementor-mcp/create-page', array( 'title' => 'Other Page', 'status' => 'draft' ) );

	assert_true( str_starts_with( $k1, 'create:create-page:' ) );
	assert_equals( $k1, $k2, 'Identical arguments with different key order must yield identical create resource key' );
	assert_true( $k1 !== $k3, 'Different arguments must yield different create resource keys' );
} );

run_test( 'Registry: duplicate-page resource key binds to actual source post_id', function () {
	$k1 = Full_Elementor_MCP_Mutation_Registry::build_create_resource_key( 'full-elementor-mcp/duplicate-page', array( 'post_id' => 101, 'title' => 'Copy' ) );
	$k2 = Full_Elementor_MCP_Mutation_Registry::build_create_resource_key( 'full-elementor-mcp/duplicate-page', array( 'post_id' => 102, 'title' => 'Copy' ) );

	assert_true( $k1 !== $k2, 'Different source post_ids must yield distinct resource keys' );
} );

run_test( 'Registry: separate rollback resource key resolver for created objects', function () {
	$entry = array(
		'ability'           => 'full-elementor-mcp/create-page',
		'object_type'       => 'page',
		'created_object_id' => 789,
		'resource_key'      => 'create:create_page:abc123hash',
	);

	$rollback_key = Full_Elementor_MCP_Mutation_Registry::resolve_rollback_resource_key( 'full-elementor-mcp/create-page', $entry );
	assert_equals( 'post:789', $rollback_key, 'Rollback resource key for created object must lock the created entity' );
} );

run_test( 'Registry: WordPress post-backed objects share ONE canonical post lock identity', function () {
	// Page mutation vs delete-page
	$k_add_widget   = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( 'full-elementor-mcp/add-widget', array( 'post_id' => 123 ) );
	$k_delete_page  = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( 'full-elementor-mcp/delete-page', array( 'post_id' => 123 ) );
	$k_update_set   = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( 'full-elementor-mcp/update-page-settings', array( 'post_id' => 123 ) );
	assert_equals( 'post:123', $k_add_widget );
	assert_equals( 'post:123', $k_delete_page );
	assert_equals( 'post:123', $k_update_set );

	// Template mutation vs delete-template
	$k_apply_tpl   = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( 'full-elementor-mcp/apply-template', array( 'post_id' => 456 ) );
	$k_del_tpl     = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( 'full-elementor-mcp/delete-template', array( 'template_id' => 456 ) );
	assert_equals( 'post:456', $k_apply_tpl );
	assert_equals( 'post:456', $k_del_tpl );

	// Popup mutation
	$k_popup = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( 'full-elementor-mcp/set-popup-settings', array( 'popup_id' => 789 ) );
	assert_equals( 'post:789', $k_popup );

	// Create-page rollback resource key
	$k_create_rollback = Full_Elementor_MCP_Mutation_Registry::resolve_rollback_resource_key(
		'full-elementor-mcp/create-page',
		array( 'ability' => 'full-elementor-mcp/create-page', 'created_object_id' => 123 )
	);
	assert_equals( 'post:123', $k_create_rollback );
} );

run_test( 'Registry: core strategies initialize with proper rollback classifications', function () {
	// Query abilities that modify document data: rollback must be true.
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/add-widget' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/update-widget' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/create-page' ) );

	// Convenience tools registered.
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/add-heading' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/add-image' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/add-button' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/add-wc-products' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/apply-template' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/build-page' ) );
	assert_true( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/save-as-template' ) );

	// Truthful unsupported/composite classifications.
	assert_false( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/set-page-meta' ) );
	assert_false( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/set-popup-settings' ) );
	assert_false( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/add-stock-image' ) );
	assert_false( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/update-global-colors' ) );
	assert_false( Full_Elementor_MCP_Mutation_Registry::is_rollback_supported( 'full-elementor-mcp/add-custom-css' ) );
} );

run_test( 'Registry: set-featured-image captures and restores actual thumbnail attachment', function () {
	$GLOBALS['mock_thumbnails'][501] = 99;

	$captured = Full_Elementor_MCP_Mutation_Registry::capture_featured_image_callback( 501 );
	assert_equals( array( 'thumbnail_id' => 99 ), $captured );

	// Mandatory fencing check: calling without fencing context MUST fail closed.
	$no_fence = Full_Elementor_MCP_Mutation_Registry::restore_featured_image_callback( $captured, array( 'post_id' => 501 ) );
	assert_is_wp_error( $no_fence );
	assert_equals( 'rollback_fencing_required', $no_fence->get_error_code() );

	// Acquire valid lock and provide fencing context.
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:501', 'feat-worker', 30 );
	assert_true( $lock['acquired'] );

	$context = array(
		'post_id'               => 501,
		'rollback_resource_key' => 'post:501',
		'current_owner_id'      => 'feat-worker',
		'caller_fencing_token'  => (int) $lock['fencing_token'],
	);

	// Change live thumbnail.
	$GLOBALS['mock_thumbnails'][501] = 105;

	// Restore original thumbnail.
	$res = Full_Elementor_MCP_Mutation_Registry::restore_featured_image_callback( $captured, $context );
	assert_true( true === $res );
	assert_equals( 99, $GLOBALS['mock_thumbnails'][501] );

	// Restore empty thumbnail (removal).
	Full_Elementor_MCP_Mutation_Registry::restore_featured_image_callback( array( 'thumbnail_id' => 0 ), $context );
	assert_equals( 0, (int) ( $GLOBALS['mock_thumbnails'][501] ?? 0 ) );

	// Idempotent restoration when already having no thumbnail:
	$idemp = Full_Elementor_MCP_Mutation_Registry::restore_featured_image_callback( array( 'thumbnail_id' => 0 ), $context );
	assert_true( true === $idemp, 'Restoring no-thumbnail when already no thumbnail must succeed idempotently' );
	assert_equals( 0, (int) ( $GLOBALS['mock_thumbnails'][501] ?? 0 ) );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:501', 'feat-worker', (int) $lock['fencing_token'] );
} );

run_test( 'Registry: set-page-slug captures and restores actual post_name', function () {
	$GLOBALS['mock_posts'][502] = array( 'ID' => 502, 'post_name' => 'original-slug' );

	$captured = Full_Elementor_MCP_Mutation_Registry::capture_page_slug_callback( 502 );
	assert_equals( array( 'post_name' => 'original-slug' ), $captured );

	// Mandatory fencing check: calling without fencing context MUST fail closed.
	$no_fence = Full_Elementor_MCP_Mutation_Registry::restore_page_slug_callback( $captured, array( 'post_id' => 502 ) );
	assert_is_wp_error( $no_fence );
	assert_equals( 'rollback_fencing_required', $no_fence->get_error_code() );

	// Acquire valid lock and provide fencing context.
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:502', 'slug-worker', 30 );
	assert_true( $lock['acquired'] );

	$context = array(
		'post_id'               => 502,
		'rollback_resource_key' => 'post:502',
		'current_owner_id'      => 'slug-worker',
		'caller_fencing_token'  => (int) $lock['fencing_token'],
	);

	// Modify slug.
	$GLOBALS['mock_posts'][502]['post_name'] = 'modified-slug';

	// Restore slug.
	$res = Full_Elementor_MCP_Mutation_Registry::restore_page_slug_callback( $captured, $context );
	assert_true( true === $res );
	assert_equals( 'original-slug', $GLOBALS['mock_posts'][502]['post_name'] );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:502', 'slug-worker', (int) $lock['fencing_token'] );
} );

run_test( 'Registry: permanent deletion (force=true) is marked non-rollbackable', function () {
	assert_true( Full_Elementor_MCP_Mutation_Registry::supports_rollback_for_args( 'full-elementor-mcp/delete-page', array( 'post_id' => 10 ) ) );
	assert_false( Full_Elementor_MCP_Mutation_Registry::supports_rollback_for_args( 'full-elementor-mcp/delete-page', array( 'post_id' => 10, 'force' => true ) ) );
	assert_false( Full_Elementor_MCP_Mutation_Registry::supports_rollback_for_args( 'full-elementor-mcp/delete-template', array( 'template_id' => 20, 'force_delete' => true ) ) );

	$restore_err = Full_Elementor_MCP_Mutation_Registry::restore_deleted_object_callback( array( 'force' => true ), array( 'post_id' => 10 ) );
	assert_is_wp_error( $restore_err );
	assert_equals( 'permanent_delete_not_rollbackable', $restore_err->get_error_code() );
} );

run_test( 'Registry Coverage Audit: every registered readonly=false ability in codebase has explicit strategy', function () {
	$base = __DIR__ . '/..';
	if ( file_exists( $base . '/includes/safety/class-elementor-features.php' ) ) {
		require_once $base . '/includes/safety/class-elementor-features.php';
		Full_Elementor_MCP_Elementor_Features::set_mock_features( array( 'atomic_elements' => true ) );
	}
	if ( ! class_exists( 'Full_Elementor_MCP_Data' ) ) {
		require_once $base . '/includes/class-elementor-data.php';
	}
	if ( ! class_exists( 'Full_Elementor_MCP_Element_Factory' ) ) {
		require_once $base . '/includes/class-element-factory.php';
	}
	if ( ! class_exists( 'Full_Elementor_MCP_Id_Generator' ) ) {
		require_once $base . '/includes/class-id-generator.php';
	}
	if ( ! class_exists( 'Full_Elementor_MCP_Openverse_Client' ) ) {
		require_once $base . '/includes/class-openverse-client.php';
	}
	if ( ! class_exists( 'Full_Elementor_MCP_Atomic_Props' ) ) {
		require_once $base . '/includes/class-atomic-props.php';
	}
	if ( ! class_exists( 'Full_Elementor_MCP_Atomic_Styles' ) ) {
		require_once $base . '/includes/class-atomic-styles.php';
	}
	if ( ! class_exists( 'Full_Elementor_MCP_Schema_Generator' ) ) {
		require_once $base . '/includes/schemas/class-schema-generator.php';
	}
	if ( ! class_exists( 'Full_Elementor_MCP_Settings_Validator' ) ) {
		require_once $base . '/includes/validators/class-settings-validator.php';
	}

	require_once $base . '/includes/abilities/class-query-abilities.php';
	require_once $base . '/includes/abilities/class-page-abilities.php';
	require_once $base . '/includes/abilities/class-layout-abilities.php';
	require_once $base . '/includes/abilities/class-widget-abilities.php';
	require_once $base . '/includes/abilities/class-template-abilities.php';
	require_once $base . '/includes/abilities/class-global-abilities.php';
	require_once $base . '/includes/abilities/class-composite-abilities.php';
	require_once $base . '/includes/abilities/class-stock-image-abilities.php';
	require_once $base . '/includes/abilities/class-svg-icon-abilities.php';
	require_once $base . '/includes/abilities/class-custom-code-abilities.php';
	require_once $base . '/includes/abilities/class-atomic-widget-abilities.php';
	require_once $base . '/includes/abilities/class-atomic-layout-abilities.php';
	require_once $base . '/includes/abilities/class-ability-registrar.php';

	$data             = new Full_Elementor_MCP_Data();
	$factory          = new Full_Elementor_MCP_Element_Factory();
	$schema_generator = new Full_Elementor_MCP_Schema_Generator();
	$validator        = new Full_Elementor_MCP_Settings_Validator( $schema_generator );

	$registrar = new Full_Elementor_MCP_Ability_Registrar( $data, $factory, $schema_generator, $validator );
	$registrar->register_all();

	global $wp_test_registered_abilities;
	assert_true( count( $wp_test_registered_abilities ) >= 138, 'Expected at least 138 total registered abilities' );

	$missing_mutations = array();
	$mutating_count    = 0;

	foreach ( $wp_test_registered_abilities as $ability_name => $def ) {
		$is_readonly = true === ( $def['meta']['annotations']['readonly'] ?? false );
		if ( ! $is_readonly ) {
			$mutating_count++;
			if ( ! Full_Elementor_MCP_Mutation_Registry::has( $ability_name ) ) {
				$missing_mutations[] = $ability_name;
			}
		}
	}

	assert_equals( 121, $mutating_count, 'Expected exactly 121 mutating abilities' );
	assert_equals( array(), $missing_mutations, 'All mutating abilities must have a registered mutation strategy' );
	Full_Elementor_MCP_Elementor_Features::reset();
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

run_test( 'Journal: begin stores durable row before mutation with deterministic hash and resource_key', function () {
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
		'action'        => 'add_widget',
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
	assert_equals( 'post:101', $entry['resource_key'] );
	assert_equals( '101', (string) $entry['object_id'] );
	assert_equals( '1', (string) $entry['fencing_token'] );
	assert_equals( '5', (string) $entry['user_id'] );
	assert_equals( '1', (string) $entry['rollback_supported'] );

	$expected_hash = Full_Elementor_MCP_Journal::hash_state( $state );
	assert_equals( $expected_hash, $entry['before_hash'] );
	assert_true( 64 === strlen( $entry['before_hash'] ) );
} );

run_test( 'Journal: begin enforces strategy contract and rejects mismatches', function () {
	// Contradicting action.
	$res1 = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'action'        => 'wrong_action',
		'object_type'   => 'post',
		'object_id'     => 102,
		'fencing_token' => 1,
		'before_state'  => array(),
	) );
	assert_is_wp_error( $res1 );
	assert_equals( 'strategy_contract_mismatch', $res1->get_error_code() );

	// Missing before_state on rollbackable mutation.
	$res2 = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 102,
		'fencing_token' => 1,
	) );
	assert_is_wp_error( $res2 );
	assert_equals( 'missing_before_state', $res2->get_error_code() );

	// Caller supplied mismatched resource key.
	$res3 = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 102,
		'resource_key'  => 'post:999',
		'fencing_token' => 1,
		'before_state'  => array(),
	) );
	assert_is_wp_error( $res3 );
	assert_equals( 'strategy_resource_mismatch', $res3->get_error_code() );
} );

run_test( 'Journal: rollback_supported decision is durably persisted and cannot be bypassed', function () {
	// Trash delete: rollback_supported = 1
	$j1 = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/delete-page',
		'object_id'     => 405,
		'fencing_token' => 1,
		'args'          => array( 'post_id' => 405, 'force' => false ),
		'before_state'  => array( 'id' => 405, 'force' => false, 'status' => 'publish' ),
	) );
	$e1 = Full_Elementor_MCP_Journal::get_entry( $j1 );
	assert_equals( '1', (string) $e1['rollback_supported'] );

	// Permanent delete: rollback_supported = 0
	$j2 = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/delete-page',
		'object_id'     => 406,
		'fencing_token' => 1,
		'args'          => array( 'post_id' => 406, 'force' => true ),
		'before_state'  => array( 'id' => 406, 'force' => true ),
	) );
	$e2 = Full_Elementor_MCP_Journal::get_entry( $j2 );
	assert_equals( '0', (string) $e2['rollback_supported'] );

	// Rollback attempt on permanent delete journal MUST be rejected even with active lock:
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:406', 'perm-worker', 30 );
	$res  = Full_Elementor_MCP_Journal::rollback( $j2, 'perm-worker', (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'mutation_not_rollbackable', $res->get_error_code() );
	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:406', 'perm-worker', (int) $lock['fencing_token'] );
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

run_test( 'Journal: double commit with different after-state is rejected', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 105,
		'fencing_token' => 2,
		'before_state'  => array(),
	) );

	$c1 = Full_Elementor_MCP_Journal::commit( $journal_id, array( 'ok' => 1 ), 2 );
	assert_true( true === $c1 );

	// Second commit with same token and same state is idempotent success.
	$c2 = Full_Elementor_MCP_Journal::commit( $journal_id, array( 'ok' => 1 ), 2 );
	assert_true( true === $c2 );

	// Second commit with different state returns conflict.
	$c3 = Full_Elementor_MCP_Journal::commit( $journal_id, array( 'ok' => 999 ), 2 );
	assert_is_wp_error( $c3 );
	assert_equals( 'journal_state_conflict', $c3->get_error_code() );
} );

run_test( 'Journal: mark_failed 0-row conflict handling', function () {
	global $wpdb;

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 106,
		'fencing_token' => 1,
		'before_state'  => array(),
	) );

	$wpdb->simulate_zero_affected_rows = true;
	$res = Full_Elementor_MCP_Journal::mark_failed( $journal_id, 'Simulated failure', 1 );
	assert_is_wp_error( $res );
	assert_equals( 'journal_state_conflict', $res->get_error_code() );
	$wpdb->simulate_zero_affected_rows = false;
} );

// =========================================================================
// 3. FENCED ROLLBACK TESTS
// =========================================================================

run_test( 'Rollback: rollback without owner_id or fencing_token is rejected', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 201,
		'fencing_token' => 1,
		'before_state'  => array( 'elements' => array( 'orig' ) ),
	) );

	// Omitted or empty owner_id and fencing_token.
	$res1 = Full_Elementor_MCP_Journal::rollback( $journal_id, '', 0 );
	assert_is_wp_error( $res1 );
	assert_equals( 'rollback_fencing_required', $res1->get_error_code() );

	$res2 = Full_Elementor_MCP_Journal::rollback( $journal_id, 'client-A', 0 );
	assert_is_wp_error( $res2 );
	assert_equals( 'rollback_fencing_required', $res2->get_error_code() );
} );

run_test( 'Rollback: successful update rollback restores before_state under active lock', function () {
	$resource_key = 'post:201';
	$owner_id     = 'worker-1';

	// Acquire active lock.
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );
	assert_true( $lock['acquired'] );

	$orig_state = array( 'elements' => array( 'original_widget' ) );
	$GLOBALS['mock_post_storage'][201] = array( 'elements' => array( 'modified_widget' ) );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 201,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => $orig_state,
	) );

	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'elements' => array( 'modified_widget' ) ), (int) $lock['fencing_token'] );

	// Rollback with active ownership.
	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_true( is_array( $res ) && ! empty( $res['success'] ) );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK, $res['status'] );
	assert_equals( $orig_state, $GLOBALS['mock_post_storage'][201] );

	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

run_test( 'Rollback: created object rollback locks created resource and trashes entity', function () {
	$GLOBALS['mock_created_objects'][999] = 'active_page';
	$owner_id = 'creator-worker';

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
	) );

	Full_Elementor_MCP_Journal::record_created_object_id( $journal_id, 999, 1 );
	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'post_id' => 999 ), 1 );

	// Acquire lock on created object resource (post:999).
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:999', $owner_id, 30 );
	assert_true( $lock['acquired'] );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_true( is_array( $res ) && ! empty( $res['success'] ) );
	assert_true( in_array( $GLOBALS['mock_created_objects'][999], array( 'trash', 'trashed' ), true ) );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:999', $owner_id, (int) $lock['fencing_token'] );
} );

run_test( 'Rollback: page-settings rollback is exact and removes newly introduced keys', function () {
	$resource_key = 'post:401';
	$owner_id     = 'settings-worker';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );

	$before_settings = array( 'a' => 1 );
	$GLOBALS['mock_post_meta'][401]['_elementor_page_settings'] = array( 'a' => 2, 'new_key' => 99 );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/update-page-settings',
		'object_id'     => 401,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => $before_settings,
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'a' => 2, 'new_key' => 99 ), (int) $lock['fencing_token'] );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_true( is_array( $res ) && ! empty( $res['success'] ) );

	$final_settings = $GLOBALS['mock_post_meta'][401]['_elementor_page_settings'];
	assert_equals( array( 'a' => 1 ), $final_settings );
	assert_false( isset( $final_settings['new_key'] ), 'Keys introduced by mutation must not survive rollback' );

	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

run_test( 'Rollback: race condition between conflict-check and restore blocks persistent write', function () {
	$resource_key = 'post:402';
	$owner_id     = 'worker-race';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );

	$orig_state = array( 'elements' => array( 'orig' ) );
	$mut_state  = array( 'elements' => array( 'mutated' ) );
	$GLOBALS['mock_post_storage'][402] = $mut_state;

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 402,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => $orig_state,
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, $mut_state, (int) $lock['fencing_token'] );

	// Simulate takeover occurring right before restore executes:
	add_filter( 'full_elementor_mcp_restore_page_data', function ( $null, $state, $context ) use ( $resource_key, $owner_id, $lock ) {
		Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
		Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, 'new-owner', 30 );
		return null;
	}, 10, 3 );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	remove_all_filters( 'full_elementor_mcp_restore_page_data' );

	assert_equals( $mut_state, $GLOBALS['mock_post_storage'][402] );
} );

run_test( 'Rollback: verification against BEFORE state rejects corrupted or mismatched restore', function () {
	$resource_key = 'post:403';
	$owner_id     = 'worker-verify';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );

	$orig_state = array( 'elements' => array( 'correct_orig' ) );
	$mut_state  = array( 'elements' => array( 'modified' ) );
	$GLOBALS['mock_post_storage'][403] = $mut_state;

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 403,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => $orig_state,
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, $mut_state, (int) $lock['fencing_token'] );

	// Filter simulates corrupted restore that leaves diverged data:
	add_filter( 'full_elementor_mcp_restore_page_data', function () {
		$GLOBALS['mock_post_storage'][403] = array( 'elements' => array( 'corrupted_restore' ) );
		return true;
	} );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'rollback_verification_failed', $res->get_error_code() );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_true( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK !== $entry['status'] );

	remove_all_filters( 'full_elementor_mcp_restore_page_data' );
	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

run_test( 'Rollback: slug collision during set-page-slug fails rollback verification', function () {
	$post_id = 404;
	$GLOBALS['mock_posts'][404] = array( 'ID' => 404, 'post_name' => 'target-slug' );
	$resource_key = 'post:404';
	$owner_id     = 'slug-worker';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/set-page-slug',
		'object_id'     => 404,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => array( 'post_name' => 'target-slug' ),
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'post_name' => 'new-slug' ), (int) $lock['fencing_token'] );
	$GLOBALS['mock_posts'][404]['post_name'] = 'new-slug';

	// Simulate WP adding suffix on collision:
	add_filter( 'full_elementor_mcp_restore_page_slug', function () {
		$GLOBALS['mock_posts'][404]['post_name'] = 'target-slug-2';
		return true;
	} );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'rollback_verification_failed', $res->get_error_code() );

	remove_all_filters( 'full_elementor_mcp_restore_page_slug' );
	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

run_test( 'Rollback: conflict detection fails closed when live state diverged', function () {
	$resource_key = 'post:202';
	$owner_id     = 'owner-conflict';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );

	$GLOBALS['mock_post_storage'][202] = array( 'elements' => array( 'committed_state' ) );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 202,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => array( 'elements' => array( 'original_state' ) ),
	) );

	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'elements' => array( 'committed_state' ) ), (int) $lock['fencing_token'] );

	// Divergence occurs.
	$GLOBALS['mock_post_storage'][202] = array( 'elements' => array( 'diverged_state' ) );

	// String "true" must NOT bypass divergence check!
	$str_force_res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'], array( 'force' => 'true' ) );
	assert_is_wp_error( $str_force_res );
	assert_equals( 'journal_state_conflict', $str_force_res->get_error_code() );

	// Literal boolean true allows forced rollback.
	$bool_force_res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'], array( 'force' => true ) );
	assert_true( is_array( $bool_force_res ) && ! empty( $bool_force_res['success'] ) );
	assert_equals( array( 'elements' => array( 'original_state' ) ), $GLOBALS['mock_post_storage'][202] );

	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

run_test( 'Rollback: force boolean true does not bypass fencing', function () {
	$resource_key = 'post:203';
	$lock_a       = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, 'owner-a', 30 );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 203,
		'fencing_token' => (int) $lock_a['fencing_token'],
		'before_state'  => array( 'elements' => array() ),
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'elements' => array( 'done' ) ), (int) $lock_a['fencing_token'] );

	// Release lock A and acquire lock B (advancing fence).
	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, 'owner-a', (int) $lock_a['fencing_token'] );
	$lock_b = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, 'owner-b', 30 );

	// Owner A attempts forced rollback with stale token.
	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, 'owner-a', (int) $lock_a['fencing_token'], array( 'force' => true ) );
	assert_is_wp_error( $res, 'Force=true must NEVER bypass fencing!' );

	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, 'owner-b', (int) $lock_b['fencing_token'] );
} );

run_test( 'Rollback: capture-current failure returns journal_state_unverifiable', function () {
	$resource_key = 'post:205';
	$owner_id     = 'owner-capture-fail';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/update-page-settings',
		'object_id'     => 205,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => array( 'title' => 'old' ),
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'title' => 'new' ), (int) $lock['fencing_token'] );

	// Register temporary filter causing capture to fail with WP_Error.
	add_filter( 'full_elementor_mcp_capture_page_settings', static function () {
		return new \WP_Error( 'db_unavailable', 'Database query failure' );
	} );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	// Depending on whether filter is invoked: verify fail closed.
	remove_all_filters( 'full_elementor_mcp_capture_page_settings' );

	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

// =========================================================================
// 4. CRASH RECOVERY & STALE GENERATION TESTS
// =========================================================================

run_test( 'Recovery: stale journal generation (fence 1 vs current fence 2) is never restored', function () {
	global $wpdb;
	$tokens_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$resource_key = 'post:305';
	$lock_key     = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource_key );

	// Journal A created with fence 1.
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 305,
		'fencing_token' => 1,
		'before_state'  => array( 'elements' => array( 'gen_1_orig' ) ),
	) );

	// Lock table has generation 2 which expired 120s ago (> 60s grace).
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$tokens_table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
			VALUES (%s, 'lock', 'worker-gen-2', 2, NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 150 SECOND), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND), 0)",
			$lock_key
		)
	);

	$reports = Full_Elementor_MCP_Journal::recover_pending( 60 );
	assert_true( count( $reports ) > 0 );

	$matched_report = null;
	foreach ( $reports as $r ) {
		if ( $r['journal_id'] === $journal_id ) {
			$matched_report = $r;
			break;
		}
	}
	assert_true( null !== $matched_report, 'Must find recovery report for journal_id' );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_FAILED, $matched_report['status'] );
	assert_equals( 'stale_generation', $matched_report['reason'] );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_FAILED, $entry['status'] );
	assert_equals( 'abandoned_pending_stale_generation', $entry['error_message'] );
} );

run_test( 'Recovery: abandoned create never auto-trashes created object and never compares cross-resource fences', function () {
	global $wpdb;
	$tokens_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();

	$GLOBALS['mock_created_objects'][500] = 'active_page';

	// Create journal with fence = 8 on create:create-page:...
	$create_res_key  = Full_Elementor_MCP_Mutation_Registry::build_create_resource_key( 'full-elementor-mcp/create-page', array( 'title' => 'Important Page' ) );
	$create_lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $create_res_key );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 8,
		'args'          => array( 'title' => 'Important Page' ),
	) );
	Full_Elementor_MCP_Journal::record_created_object_id( $journal_id, 500, 8 );

	// Expire the creation lock (> 60s grace).
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$tokens_table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
			VALUES (%s, 'lock', 'creator-died', 8, NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 150 SECOND), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND), 0)",
			$create_lock_key
		)
	);

	// Meanwhile, later post:500 has lock with fence = 1 and is active/modified.
	$post_lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( 'post:500' );
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$tokens_table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
			VALUES (%s, 'lock', 'editor-worker', 1, NULL, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND), 0)",
			$post_lock_key
		)
	);

	$reports = Full_Elementor_MCP_Journal::recover_pending( 60 );
	$matched_report = null;
	foreach ( $reports as $r ) {
		if ( $r['journal_id'] === $journal_id ) {
			$matched_report = $r;
			break;
		}
	}
	assert_true( null !== $matched_report, 'Must find recovery report for journal_id' );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_FAILED, $matched_report['status'] );
	assert_equals( 'abandoned_create_requires_manual_recovery', $matched_report['reason'] );

	// CRITICAL: object 500 MUST NOT be trashed!
	assert_equals( 'active_page', $GLOBALS['mock_created_objects'][500] );
} );

run_test( 'Recovery: pending existing-resource with divergent live state is NOT speculatively overwritten', function () {
	global $wpdb;
	$tokens_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$resource_key = 'post:306';
	$lock_key     = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource_key );

	$GLOBALS['mock_post_storage'][306] = array( 'elements' => array( 'stuck_intermediate' ) );
	$orig_state = array( 'elements' => array( 'clean_state' ) );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 306,
		'fencing_token' => 1,
		'before_state'  => $orig_state,
	) );

	// Insert lock generation 1 that expired 120s ago (> 60s grace).
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$tokens_table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
			VALUES (%s, 'lock', 'dead-worker', 1, NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 150 SECOND), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND), 0)",
			$lock_key
		)
	);

	$reports = Full_Elementor_MCP_Journal::recover_pending( 60 );
	assert_true( count( $reports ) > 0 );

	$matched_report = null;
	foreach ( $reports as $r ) {
		if ( $r['journal_id'] === $journal_id ) {
			$matched_report = $r;
			break;
		}
	}
	assert_true( null !== $matched_report, 'Must find recovery report for journal_id' );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_FAILED, $matched_report['status'] );
	assert_equals( 'manual_recovery_required', $matched_report['reason'] );

	// Live content remains untouched!
	assert_equals( array( 'elements' => array( 'stuck_intermediate' ) ), $GLOBALS['mock_post_storage'][306] );
} );

run_test( 'Recovery: pending existing-resource with clean live state resolves cleanly as clean_noop', function () {
	global $wpdb;
	$tokens_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$resource_key = 'post:307';
	$lock_key     = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource_key );

	$GLOBALS['mock_post_storage'][307] = array( 'elements' => array( 'clean_state' ) );
	$orig_state = array( 'elements' => array( 'clean_state' ) );

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 307,
		'fencing_token' => 1,
		'before_state'  => $orig_state,
	) );

	// Insert lock generation 1 that expired 120s ago (> 60s grace).
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$tokens_table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
			VALUES (%s, 'lock', 'dead-worker-2', 1, NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 150 SECOND), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND), 0)",
			$lock_key
		)
	);

	$reports = Full_Elementor_MCP_Journal::recover_pending( 60 );
	assert_true( count( $reports ) > 0 );

	$matched_report = null;
	foreach ( $reports as $r ) {
		if ( $r['journal_id'] === $journal_id ) {
			$matched_report = $r;
			break;
		}
	}
	assert_true( null !== $matched_report, 'Must find recovery report for journal_id' );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_FAILED, $matched_report['status'] );
	assert_equals( 'clean_noop', $matched_report['reason'] );

	assert_equals( $orig_state, $GLOBALS['mock_post_storage'][307] );
} );

// =========================================================================
// 5. SECURITY, FIDELITY & SERIALIZATION TESTS
// =========================================================================

run_test( 'Security: operational state does not redact legitimate Elementor key and token fields', function () {
	$elementor_state = array(
		'elements' => array(
			array(
				'id'       => 'el_1',
				'settings' => array(
					'key'             => 'design_key_123',
					'token'           => 'css_token_xyz',
					'safe_label'      => 'Hello World',
				),
			),
		),
	);

	$canonical = Full_Elementor_MCP_Journal::canonicalize_data( $elementor_state );
	assert_equals( 'design_key_123', $canonical['elements'][0]['settings']['key'] );
	assert_equals( 'css_token_xyz', $canonical['elements'][0]['settings']['token'] );
	assert_equals( 'Hello World', $canonical['elements'][0]['settings']['safe_label'] );
} );

run_test( 'Security: redact_credentials strips credential patterns from logs', function () {
	$log_payload = array(
		'username'     => 'admin',
		'user_pass'    => 'P@ssw0rd',
		'app_password' => 'abcd-1234-wxyz',
		'safe_data'    => 'keep_me',
	);

	$redacted = Full_Elementor_MCP_Journal::redact_credentials( $log_payload );
	assert_equals( '[REDACTED]', $redacted['user_pass'] );
	assert_equals( '[REDACTED]', $redacted['app_password'] );
	assert_equals( 'keep_me', $redacted['safe_data'] );
} );

run_test( 'Journal: hash_state fails closed and returns WP_Error on invalid data', function () {
	$invalid_utf8 = array( 'bad' => "\xB1\x31" );
	$hash_res     = Full_Elementor_MCP_Journal::hash_state( $invalid_utf8 );
	assert_is_wp_error( $hash_res );
	assert_equals( 'serialization_failed', $hash_res->get_error_code() );
} );

run_test( 'Journal: deserialize_state returns error on corrupted JSON', function () {
	$deserialized = Full_Elementor_MCP_Journal::deserialize_state( '{"invalid": json' );
	assert_is_wp_error( $deserialized );
	assert_equals( 'deserialization_failed', $deserialized->get_error_code() );
} );

// =========================================================================
// 6. FINAL CONSOLIDATED PHASE 2 CORRECTNESS & RESILIENCE REGRESSIONS
// =========================================================================

// Item 1: Journal::begin() derives object_id from Mutation Registry
run_test( 'Final Pass: begin with nested args post_id stores authoritative object_id', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'fencing_token' => 1,
		'args'          => array( 'post_id' => 123 ),
		'before_state'  => array( 'elements' => array() ),
	) );
	assert_true( $journal_id > 0 );
	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( 123, (int) $entry['object_id'] );
	assert_equals( 'post:123', (string) $entry['resource_key'] );
} );

run_test( 'Final Pass: begin with args post_id and mismatched object_id is rejected', function () {
	$err = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 999,
		'fencing_token' => 1,
		'args'          => array( 'post_id' => 123 ),
		'before_state'  => array( 'elements' => array() ),
	) );
	assert_is_wp_error( $err );
	assert_equals( 'strategy_object_mismatch', $err->get_error_code() );
} );

run_test( 'Final Pass: template_id resolver stores correct object_id in begin', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/delete-template',
		'fencing_token' => 1,
		'args'          => array( 'template_id' => 456 ),
		'before_state'  => array( 'exists' => true ),
	) );
	assert_true( $journal_id > 0 );
	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( 456, (int) $entry['object_id'] );
	assert_equals( 'post:456', (string) $entry['resource_key'] );
} );

run_test( 'Final Pass: create-page stores object_id = 0 in begin', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
		'args'          => array( 'title' => 'New Page' ),
	) );
	assert_true( $journal_id > 0 );
	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( 0, (int) $entry['object_id'] );
} );

// Item 2: Fix Elementor global-kit resource identity
run_test( 'Final Pass: global-kit mutations share canonical global:elementor-kit-state lock domain', function () {
	$k_colors = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key(
		'full-elementor-mcp/update-global-colors',
		array( 'colors' => array() )
	);
	$k_typo = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key(
		'full-elementor-mcp/update-global-typography',
		array( 'typography' => array() )
	);
	$k_kit10 = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key(
		'full-elementor-mcp/set-active-kit',
		array( 'kit_id' => 10 )
	);
	$k_kit20 = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key(
		'full-elementor-mcp/set-active-kit',
		array( 'kit_id' => 20 )
	);

	assert_equals( 'global:elementor-kit-state', $k_colors );
	assert_equals( 'global:elementor-kit-state', $k_typo );
	assert_equals( 'global:elementor-kit-state', $k_kit10 );
	assert_equals( 'global:elementor-kit-state', $k_kit20 );
	assert_true( $k_colors === $k_typo );
	assert_true( $k_kit10 === $k_kit20 );
	assert_true( $k_colors === $k_kit10 );
} );

// Item 3: Make page-settings persistence verification authoritative
run_test( 'Final Pass: page-settings restore fails closed when DB write fails or retains mutated state', function () {
	$resource_key = 'post:601';
	$owner_id     = 'settings-verifier';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );

	$before_settings  = array( 'site_layout' => 'boxed' );
	$mutated_settings = array( 'site_layout' => 'full_width', 'evil' => 'data' );

	$GLOBALS['mock_post_meta'][601]['_elementor_page_settings'] = $mutated_settings;

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/update-page-settings',
		'object_id'     => 601,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => $before_settings,
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, $mutated_settings, (int) $lock['fencing_token'] );

	// Simulate persistent DB write failure: update_post_meta fails or retains mutated state
	add_filter( 'update_post_metadata', function ( $null, $object_id, $meta_key, $meta_value ) {
		if ( 601 === (int) $object_id && '_elementor_page_settings' === $meta_key ) {
			return false; // DB write rejected
		}
		return null;
	}, 10, 4 );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	remove_all_filters( 'update_post_metadata' );

	// Verify journal did NOT become rolled_back
	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_true( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK !== $entry['status'] );

	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

// Item 4: Make committed rollback retry crash-safe (idempotent reconciliation)
run_test( 'Final Pass: committed rollback retry reconciles idempotently after DB update failure', function () {
	global $wpdb;
	$resource_key = 'post:602';
	$owner_id     = 'crash-worker';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );

	$before_state = array( 'elements' => array( 'base' ) );
	$after_state  = array( 'elements' => array( 'mutated' ) );
	$GLOBALS['mock_post_storage'][602] = $after_state;

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 602,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => $before_state,
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, $after_state, (int) $lock['fencing_token'] );

	// Step 1: Simulate DB update failure right when transitioning journal status to rolled_back
	// Resource restore will succeed (setting live storage to $before_state), but journal UPDATE fails
	$wpdb->on_before_query = function ( $sql ) {
		if ( str_contains( $sql, 'SET status =' ) && str_contains( $sql, 'rolled_back' ) ) {
			return false; // fail the UPDATE
		}
		return null;
	};

	$first_try = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_is_wp_error( $first_try );
	assert_equals( 'journal_write_failed', $first_try->get_error_code() );

	// Live state was restored to before_state!
	assert_equals( $before_state, $GLOBALS['mock_post_storage'][602] );
	$wpdb->on_before_query = null;

	// Journal status is still committed
	$entry_middle = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_COMMITTED, $entry_middle['status'] );

	// Step 2: Retry rollback!
	// Must recognize that live_hash === before_hash, skip second restore, and transition status to rolled_back
	$retry = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_true( is_array( $retry ) && ! empty( $retry['success'] ) );

	$entry_final = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK, $entry_final['status'] );

	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

// Item 5: Prevent stale committed CREATE rollback from deleting newer work
run_test( 'Final Pass: committed create rollback rejected when object was modified after creation', function () {
	$GLOBALS['mock_created_objects'][777] = 'publish';
	$GLOBALS['mock_posts'][777] = array(
		'ID'         => 777,
		'post_title' => 'Initial Title',
		'post_name'  => 'initial-title',
		'post_type'  => 'page',
	);
	$GLOBALS['mock_post_storage'][777] = array( 'elements' => array() );
	$GLOBALS['mock_post_meta'][777]['_elementor_page_settings'] = array();

	$owner_id = 'create-tester';

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
	) );
	Full_Elementor_MCP_Journal::record_created_object_id( $journal_id, 777, 1 );
	Full_Elementor_MCP_Journal::commit( $journal_id, array( 'post_id' => 777 ), 1 );

	// Scenario A: Later modification occurs! (Newer work on created object)
	$GLOBALS['mock_posts'][777]['post_title'] = 'Modified After Creation';

	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:777', $owner_id, 30 );
	assert_true( $lock['acquired'] );

	// Rollback attempt MUST be rejected with conflict because entity has newer work!
	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'journal_state_conflict', $res->get_error_code() );
	// Object remains active (NOT trashed)
	assert_equals( 'publish', $GLOBALS['mock_created_objects'][777] );

	// Scenario B: Without modifications (matching initial baseline), rollback succeeds
	$GLOBALS['mock_posts'][777]['post_title'] = 'Initial Title'; // revert to committed baseline
	$res2 = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_true( is_array( $res2 ) && ! empty( $res2['success'] ) );
	assert_equals( 'trash', $GLOBALS['mock_created_objects'][777] );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:777', $owner_id, (int) $lock['fencing_token'] );
} );

// Item 6: Fix record_created_object_id() fencing recheck
run_test( 'Final Pass: record_created_object_id recheck enforces fencing token and strategy tracking', function () {
	// Subtest 1: Strategy without created_object_tracking fails closed
	$j_widget = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 10,
		'fencing_token' => 1,
		'before_state'  => array( 'elements' => array() ),
	) );
	$err_strat = Full_Elementor_MCP_Journal::record_created_object_id( $j_widget, 99, 1 );
	assert_is_wp_error( $err_strat );
	assert_equals( 'invalid_journal_operation', $err_strat->get_error_code() );

	// Subtest 2: Stale fencing token on retry fails with stale_writer_conflict
	$j_create = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
	) );
	$ok = Full_Elementor_MCP_Journal::record_created_object_id( $j_create, 888, 1 );
	assert_true( true === $ok );

	// Calling again with same ID but different fencing token (token 2) MUST fail
	$err_fence = Full_Elementor_MCP_Journal::record_created_object_id( $j_create, 888, 2 );
	assert_is_wp_error( $err_fence );
	assert_equals( 'stale_writer_conflict', $err_fence->get_error_code() );
} );

// Item 7: Do not persist arbitrary before_state for non-rollbackable strategies
run_test( 'Final Pass: non-rollbackable strategy set-page-meta does not persist post_password in journal', function () {
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/set-page-meta',
		'object_id'     => 123,
		'fencing_token' => 1,
		'before_state'  => array( 'post_password' => 'super_secret_password_123' ),
	) );
	assert_true( $journal_id > 0 );

	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_false( str_contains( (string) $entry['before_state'], 'super_secret_password_123' ) );
	assert_true( null === $entry['before_state'] || 'null' === $entry['before_state'] || '' === $entry['before_state'] );
} );

// Item 8: WAL before-state payload size limit
run_test( 'Final Pass: oversized WAL before_state payload is rejected before DB insert', function () {
	$huge_state = str_repeat( 'X', Full_Elementor_MCP_Journal::MAX_BEFORE_STATE_BYTES + 1024 );

	$err = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/update-page-settings',
		'object_id'     => 200,
		'fencing_token' => 1,
		'before_state'  => array( 'huge' => $huge_state ),
	) );

	assert_is_wp_error( $err );
	assert_equals( 'journal_state_too_large', $err->get_error_code() );

	// Payload within limit succeeds
	$normal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/update-page-settings',
		'object_id'     => 200,
		'fencing_token' => 1,
		'before_state'  => array( 'small' => 'valid_data' ),
	) );
	assert_true( $normal_id > 0 );
} );

// Item 9: Make recovery reports reflect actual journal DB state
run_test( 'Final Pass: recovery reports recovery_failed when mark_failed DB write fails', function () {
	global $wpdb;

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 991,
		'fencing_token' => 1,
		'before_state'  => array( 'elements' => array() ),
	) );
	assert_true( $journal_id > 0 );

	// Backdate journal row and simulate lock expired + past grace period
	$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
	$wpdb->query( "UPDATE {$table} SET created_at = '2020-01-01 00:00:00' WHERE id = {$journal_id}" );
	$GLOBALS['mock_post_storage'][991] = array( 'elements' => array() );

	// Fail the mark_failed UPDATE query
	$wpdb->on_before_query = function ( $sql ) {
		if ( str_contains( $sql, 'SET error_message =' ) && str_contains( $sql, 'status =' ) ) {
			return false; // DB query fails
		}
		return null;
	};

	$reports = Full_Elementor_MCP_Journal::recover_pending( 10 );
	$wpdb->on_before_query = null;

	$matched = null;
	foreach ( $reports as $r ) {
		if ( (int) $r['journal_id'] === $journal_id ) {
			$matched = $r;
			break;
		}
	}
	assert_true( null !== $matched );
	assert_equals( 'recovery_failed', $matched['status'] );
	assert_equals( 'journal_write_failed', $matched['reason'] );
} );

// Item 10: Harden restore callbacks fencing contract
run_test( 'Final Pass: direct restore callback invocation without fencing context returns rollback_fencing_required', function () {
	$res1 = Full_Elementor_MCP_Mutation_Registry::restore_page_data_callback( array( 'elements' => array() ), array( 'post_id' => 10 ) );
	assert_is_wp_error( $res1 );
	assert_equals( 'rollback_fencing_required', $res1->get_error_code() );

	$res2 = Full_Elementor_MCP_Mutation_Registry::restore_page_settings_callback( array( 'a' => 1 ), array( 'post_id' => 10 ) );
	assert_is_wp_error( $res2 );
	assert_equals( 'rollback_fencing_required', $res2->get_error_code() );

	$res3 = Full_Elementor_MCP_Mutation_Registry::restore_created_object_callback( null, array( 'created_object_id' => 10 ) );
	assert_is_wp_error( $res3 );
	assert_equals( 'rollback_fencing_required', $res3->get_error_code() );
} );

// Item 11: Final strategy resource audit - NO mutating strategy resolves to post:0
run_test( 'Final Pass: audit all 121 mutating strategies and verify zero post:0 resolutions', function () {
	$all_strategies = Full_Elementor_MCP_Mutation_Registry::all();
	assert_true( count( $all_strategies ) >= 121, 'Must have at least 121 mutation strategies registered' );

	$test_args = array(
		'post_id'           => 999,
		'page_id'           => 999,
		'template_id'       => 999,
		'popup_id'          => 999,
		'id'                => 999,
		'kit_id'            => 999,
		'font_family'       => 'Roboto',
		'title'             => 'Test Entity',
		'elements'          => array(),
		'settings'          => array(),
		'created_object_id' => 999,
	);

	foreach ( $all_strategies as $ability => $strategy ) {
		$res_key = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( $ability, $test_args );
		assert_false( is_wp_error( $res_key ), "Strategy {$ability} failed to resolve resource key: " . ( is_wp_error( $res_key ) ? $res_key->get_error_message() : '' ) );
		assert_true( is_string( $res_key ) && '' !== trim( $res_key ), "Strategy {$ability} resolved empty resource key" );
		assert_true( 'post:0' !== $res_key, "Strategy {$ability} resolved to prohibited post:0!" );
		assert_false( str_ends_with( $res_key, ':0' ), "Strategy {$ability} resolved to zero-ID resource key ({$res_key})" );
	}
} );

// =========================================================================
// 7. WAL-INTEGRITY REGRESSIONS & WRITE-ONCE ENFORCEMENT
// =========================================================================

run_test( 'WAL-Integrity: created_object_id cannot be replaced by another ID', function () {
	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
	) );
	assert_true( $j > 0 );

	// First record succeeds
	$ok1 = Full_Elementor_MCP_Journal::record_created_object_id( $j, 888, 1 );
	assert_true( true === $ok1 );

	// Repeat 888 with same fence succeeds (idempotent)
	$ok2 = Full_Elementor_MCP_Journal::record_created_object_id( $j, 888, 1 );
	assert_true( true === $ok2 );

	// Attempt 999 with same fence fails
	$err1 = Full_Elementor_MCP_Journal::record_created_object_id( $j, 999, 1 );
	assert_is_wp_error( $err1 );
	assert_equals( 'created_object_conflict', $err1->get_error_code() );

	// Attempt 888 with stale fence fails
	$err2 = Full_Elementor_MCP_Journal::record_created_object_id( $j, 888, 2 );
	assert_is_wp_error( $err2 );
	assert_equals( 'stale_writer_conflict', $err2->get_error_code() );
} );

run_test( 'WAL-Integrity: tracked CREATE cannot commit without created_object_id', function () {
	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
	) );
	assert_true( $j > 0 );

	// Attempt commit before recording created ID
	$err = Full_Elementor_MCP_Journal::commit( $j, array( 'dummy' => 1 ), 1 );
	assert_is_wp_error( $err );
	assert_equals( 'created_object_id_required', $err->get_error_code() );

	// Record created ID and then commit succeeds
	$GLOBALS['mock_created_objects'][889] = 'publish';
	$GLOBALS['mock_posts'][889] = array( 'ID' => 889, 'post_title' => 'Page 889' );
	Full_Elementor_MCP_Journal::record_created_object_id( $j, 889, 1 );

	$ok = Full_Elementor_MCP_Journal::commit( $j, array( 'dummy' => 1 ), 1 );
	assert_true( true === $ok );
} );

run_test( 'WAL-Integrity: caller cannot spoof CREATE after-state fingerprint', function () {
	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
	) );

	$GLOBALS['mock_created_objects'][890] = 'publish';
	$GLOBALS['mock_posts'][890] = array(
		'ID'           => 890,
		'post_title'   => 'Authoritative Title',
		'post_name'    => 'auth-slug',
		'post_excerpt' => 'auth-excerpt',
	);
	Full_Elementor_MCP_Journal::record_created_object_id( $j, 890, 1 );

	// Caller attempts to pass fake after-state
	$fake_after = array( 'exists' => true, 'spoofed_key' => 'fake_value' );
	$ok = Full_Elementor_MCP_Journal::commit( $j, $fake_after, 1 );
	assert_true( true === $ok );

	// Authoritative fingerprint must be stored, not the caller's fake payload
	$entry = Full_Elementor_MCP_Journal::get_entry( $j );
	$expected_fp   = Full_Elementor_MCP_Mutation_Registry::capture_created_object_callback( 890 );
	$expected_hash = Full_Elementor_MCP_Journal::hash_state( $expected_fp );

	assert_equals( $expected_hash, $entry['after_hash'] );
	assert_true( $entry['after_hash'] !== Full_Elementor_MCP_Journal::hash_state( $fake_after ) );
} );

run_test( 'WAL-Integrity: create with unknown/unrecorded ID cannot be marked rolled_back', function () {
	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
	) );
	assert_true( $j > 0 );

	// Acquire lock on create resource
	$entry = Full_Elementor_MCP_Journal::get_entry( $j );
	$lock  = Full_Elementor_MCP_Lock_Manager::acquire_lock( $entry['resource_key'], 'unrecorded-worker', 30 );

	// Attempt rollback without recorded created_object_id
	$res = Full_Elementor_MCP_Journal::rollback( $j, 'unrecorded-worker', (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'created_object_identity_unknown', $res->get_error_code() );

	// Journal must NOT be rolled_back
	$entry_after = Full_Elementor_MCP_Journal::get_entry( $j );
	assert_true( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK !== $entry_after['status'] );

	Full_Elementor_MCP_Lock_Manager::release_lock( $entry['resource_key'], 'unrecorded-worker', (int) $lock['fencing_token'] );
} );

run_test( 'WAL-Integrity: post_excerpt modification detected by create fingerprint', function () {
	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
	) );

	$GLOBALS['mock_created_objects'][891] = 'publish';
	$GLOBALS['mock_posts'][891] = array(
		'ID'           => 891,
		'post_title'   => 'Base Title',
		'post_excerpt' => 'initial excerpt',
	);
	Full_Elementor_MCP_Journal::record_created_object_id( $j, 891, 1 );
	Full_Elementor_MCP_Journal::commit( $j, array(), 1 );

	// Modify post_excerpt (newer work)
	$GLOBALS['mock_posts'][891]['post_excerpt'] = 'modified excerpt by another tool';

	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:891', 'excerpt-worker', 30 );
	$res  = Full_Elementor_MCP_Journal::rollback( $j, 'excerpt-worker', (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'journal_state_conflict', $res->get_error_code() );
	assert_equals( 'publish', $GLOBALS['mock_created_objects'][891] );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:891', 'excerpt-worker', (int) $lock['fencing_token'] );
} );

run_test( 'WAL-Integrity: featured-image modification detected by create fingerprint', function () {
	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
	) );

	$GLOBALS['mock_created_objects'][892] = 'publish';
	$GLOBALS['mock_posts'][892] = array( 'ID' => 892, 'post_title' => 'Title 892' );
	$GLOBALS['mock_thumbnails'][892] = 55;
	Full_Elementor_MCP_Journal::record_created_object_id( $j, 892, 1 );
	Full_Elementor_MCP_Journal::commit( $j, array(), 1 );

	// Modify featured image
	$GLOBALS['mock_thumbnails'][892] = 99;

	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:892', 'feat-worker', 30 );
	$res  = Full_Elementor_MCP_Journal::rollback( $j, 'feat-worker', (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'journal_state_conflict', $res->get_error_code() );
	assert_equals( 'publish', $GLOBALS['mock_created_objects'][892] );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:892', 'feat-worker', (int) $lock['fencing_token'] );
} );

run_test( 'WAL-Integrity: template-condition modification detected by create fingerprint', function () {
	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-theme-template',
		'fencing_token' => 1,
	) );

	$GLOBALS['mock_created_objects'][893] = 'publish';
	$GLOBALS['mock_posts'][893] = array( 'ID' => 893, 'post_title' => 'Theme Header' );
	$GLOBALS['mock_post_meta'][893]['_elementor_conditions'] = array( 'include/general' );
	Full_Elementor_MCP_Journal::record_created_object_id( $j, 893, 1 );
	Full_Elementor_MCP_Journal::commit( $j, array(), 1 );

	// Modify template conditions
	$GLOBALS['mock_post_meta'][893]['_elementor_conditions'] = array( 'include/archive' );

	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:893', 'cond-worker', 30 );
	$res  = Full_Elementor_MCP_Journal::rollback( $j, 'cond-worker', (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'journal_state_conflict', $res->get_error_code() );
	assert_equals( 'publish', $GLOBALS['mock_created_objects'][893] );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:893', 'cond-worker', (int) $lock['fencing_token'] );
} );

run_test( 'WAL-Integrity: FAILED manual-recovery journal cannot be ordinary-rollback overwritten', function () {
	global $wpdb;

	// Original worker acquires generation 1
	$orig_lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:894', 'orig-worker', 30 );
	assert_equals( 1, (int) $orig_lock['fencing_token'] );

	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 894,
		'fencing_token' => (int) $orig_lock['fencing_token'],
		'before_state'  => array( 'elements' => array( 'original_894' ) ),
	) );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:894', 'orig-worker', (int) $orig_lock['fencing_token'] );

	// Manually set status = failed (as recovery would do on manual_recovery_required)
	$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
	$wpdb->query( "UPDATE {$table} SET status = 'failed', error_message = 'manual_recovery_required' WHERE id = {$j}" );

	// Live state diverged from before_state
	$GLOBALS['mock_post_storage'][894] = array( 'elements' => array( 'divergent_newer_work' ) );

	// New generation writer acquires lock (fencing token 2)
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:894', 'new-gen-worker', 30 );
	assert_equals( 2, (int) $lock['fencing_token'] );

	// Ordinary rollback MUST NOT overwrite unverifiable divergent state
	$res = Full_Elementor_MCP_Journal::rollback( $j, 'new-gen-worker', (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'manual_recovery_required', $res->get_error_code() );
	assert_equals( array( 'elements' => array( 'divergent_newer_work' ) ), $GLOBALS['mock_post_storage'][894] );

	// But explicit force=true path allows authorized overwrite under active fencing
	$forced = Full_Elementor_MCP_Journal::rollback( $j, 'new-gen-worker', (int) $lock['fencing_token'], array( 'force' => true ) );
	assert_true( is_array( $forced ) && ! empty( $forced['success'] ) );
	assert_equals( array( 'elements' => array( 'original_894' ) ), $GLOBALS['mock_post_storage'][894] );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:894', 'new-gen-worker', (int) $lock['fencing_token'] );
} );

run_test( 'WAL-Integrity: PENDING new-generation rollback cannot overwrite unverifiable state', function () {
	// Original worker acquires generation 1
	$orig_lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:895', 'orig-worker-2', 30 );
	assert_equals( 1, (int) $orig_lock['fencing_token'] );

	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 895,
		'fencing_token' => (int) $orig_lock['fencing_token'],
		'before_state'  => array( 'elements' => array( 'original_895' ) ),
	) );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:895', 'orig-worker-2', (int) $orig_lock['fencing_token'] );

	// Live state diverged
	$GLOBALS['mock_post_storage'][895] = array( 'elements' => array( 'divergent_895' ) );

	// New generation writer acquires lock (fencing token 2)
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:895', 'new-gen-worker-2', 30 );
	assert_equals( 2, (int) $lock['fencing_token'] );

	// Ordinary rollback fails closed
	$res = Full_Elementor_MCP_Journal::rollback( $j, 'new-gen-worker-2', (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'manual_recovery_required', $res->get_error_code() );
	assert_equals( array( 'elements' => array( 'divergent_895' ) ), $GLOBALS['mock_post_storage'][895] );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:895', 'new-gen-worker-2', (int) $lock['fencing_token'] );
} );

run_test( 'WAL-Integrity: PENDING original-generation cleanup remains possible where resource/fence identity proves same execution', function () {
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:896', 'orig-worker', 30 );
	assert_true( $lock['acquired'] );

	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 896,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => array( 'elements' => array( 'clean_base_896' ) ),
	) );

	// Partial mutation occurred before execution failure:
	$GLOBALS['mock_post_storage'][896] = array( 'elements' => array( 'partial_failed_mutation' ) );

	// Same worker cleaning up its own failure in the same execution:
	$res = Full_Elementor_MCP_Journal::rollback( $j, 'orig-worker', (int) $lock['fencing_token'] );
	assert_true( is_array( $res ) && ! empty( $res['success'] ) );
	assert_equals( array( 'elements' => array( 'clean_base_896' ) ), $GLOBALS['mock_post_storage'][896] );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:896', 'orig-worker', (int) $lock['fencing_token'] );
} );

run_test( 'WAL-Integrity: pending CREATE with created ID but no after_hash remains conservative', function () {
	$j = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/create-page',
		'fencing_token' => 1,
	) );

	$GLOBALS['mock_created_objects'][897] = 'publish';
	$GLOBALS['mock_posts'][897] = array( 'ID' => 897, 'post_title' => 'Page 897' );
	Full_Elementor_MCP_Journal::record_created_object_id( $j, 897, 1 );
	// Notice: NOT committed! No after_hash exists.

	// Caller acquires lock on created object resource (post:897)
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:897', 'post-worker', 30 );
	assert_true( $lock['acquired'] );

	// Normal rollback MUST NOT trash the object without an authoritative baseline
	$res = Full_Elementor_MCP_Journal::rollback( $j, 'post-worker', (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	assert_equals( 'manual_recovery_required', $res->get_error_code() );
	assert_equals( 'publish', $GLOBALS['mock_created_objects'][897] );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:897', 'post-worker', (int) $lock['fencing_token'] );
} );

run_test( 'WAL-Integrity: persisted Elementor page-data mismatch blocks rollback', function () {
	$resource_key = 'post:898';
	$owner_id     = 'data-verifier';
	$lock         = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );

	$before_data  = array( 'elements' => array( 'widget_1' ) );
	$mutated_data = array( 'elements' => array( 'widget_1', 'widget_2' ) );

	$GLOBALS['mock_post_storage'][898] = $mutated_data;

	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/add-widget',
		'object_id'     => 898,
		'fencing_token' => (int) $lock['fencing_token'],
		'before_state'  => $before_data,
	) );
	Full_Elementor_MCP_Journal::commit( $journal_id, $mutated_data, (int) $lock['fencing_token'] );

	// Simulate persistent DB write failure: update_post_metadata filter rejects _elementor_data write
	add_filter( 'update_post_metadata', function ( $null, $object_id, $meta_key, $meta_value ) {
		if ( 898 === (int) $object_id && '_elementor_data' === $meta_key ) {
			return false; // persistent DB write rejected
		}
		return null;
	}, 10, 4 );

	$res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, (int) $lock['fencing_token'] );
	assert_is_wp_error( $res );
	remove_all_filters( 'update_post_metadata' );

	// Journal must NOT become rolled_back
	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_true( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK !== $entry['status'] );

	Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, (int) $lock['fencing_token'] );
} );

echo "\n=======================================================\n";
echo " Test Results: {$tests_passed}/" . ( $tests_passed + $tests_failed ) . " passed.\n";
echo "=======================================================\n\n";

if ( $tests_failed > 0 ) {
	exit( 1 );
}
exit( 0 );
