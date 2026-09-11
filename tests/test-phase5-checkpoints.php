<?php
/**
 * Comprehensive Test Suite for Phase 5: Encrypted Checkpoints & Restore Engine.
 *
 * Can be executed via CLI: `php tests/test-phase5-checkpoints.php`
 *
 * Verifies all Phase 5 requirements:
 * 1. AEAD Cryptographic Subsystem (libsodium & OpenSSL AES-256-GCM)
 * 2. Nonce Uniqueness & AAD Cryptographic Binding
 * 3. Row-Swapping & Ciphertext Tampering Protection
 * 4. Key Provider, Rotation Keyring & Historical Key Lookup
 * 5. Strict JSON Serialization, Size Bounds & State Hashing
 * 6. Post-Backed Elementor Document Strategy & Exact Restoration
 * 7. Custom Code Snippet Strategy & Executable Code Protection
 * 8. Global Elementor Kit State Strategy & Domain Restoration
 * 9. Recovery-Only Irreversible Deletion Snapshots
 * 10. Automatic Middleware Checkpoint Policy & WAL Ordering
 * 11. Dry-Run Isolation & Completed Idempotency Replay Protection
 * 12. Restore Engine Safety (Pre-Restore Checkpoints, Tree Validation, Fencing)
 * 13. Post-Restore Exact Verification & Failure Rollback
 * 14. Retention Pruning & Pinned Checkpoint Protection (DB UTC)
 * 15. Schema Migration & Exactly Four Tables Verification
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
		public function get_error_data(): mixed { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

$GLOBALS['wp_test_options']      = array();
$GLOBALS['mock_post_meta']       = array();
$GLOBALS['mock_posts']           = array();
$GLOBALS['wp_test_user_id']      = 1;
$GLOBALS['wp_test_caps']         = array( 'manage_options' => true, 'edit_posts' => true, 'unfiltered_html' => true, 'publish_pages' => true, 'edit_pages' => true );
$GLOBALS['wp_test_app_pwd_uuid']  = null;

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
		if ( ( 'edit_post' === $cap || 'edit_page' === $cap || 'publish_page' === $cap || 'publish_pages' === $cap || 'edit_pages' === $cap ) && ! empty( $GLOBALS['wp_test_caps']['edit_posts'] ) ) {
			return true;
		}
		if ( ( 'delete_post' === $cap || 'delete_page' === $cap ) && ! empty( $GLOBALS['wp_test_caps']['delete_posts'] ) ) {
			return true;
		}
		return ! empty( $GLOBALS['wp_test_caps'][ $cap ] );
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( int $user_id, string $cap ): bool {
		return current_user_can( $cap );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'mock_salt_' . $scheme . '_secret_key_material_for_hkdf_testing_12345';
	}
}
if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url(): string {
		return 'https://example.com';
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
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
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $val ): mixed {
		return is_string( $val ) ? stripslashes( $val ) : $val;
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
	function wp_strip_all_tags( string $string, bool $remove_breaks = false ): string {
		return strip_tags( $string );
	}
}

// Mock WordPress post storage:
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int|object|null $post = null ): ?object {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		return $GLOBALS['mock_posts'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		if ( '' === $key ) {
			return $GLOBALS['mock_post_meta'][ $post_id ] ?? array();
		}
		$val = $GLOBALS['mock_post_meta'][ $post_id ][ $key ] ?? null;
		if ( $single ) {
			return $val ?? '';
		}
		return null !== $val ? ( is_array( $val ) ? $val : array( $val ) ) : array();
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $val ): bool {
		$GLOBALS['mock_post_meta'][ $post_id ][ $key ] = $val;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key ): bool {
		unset( $GLOBALS['mock_post_meta'][ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false ): int|WP_Error {
		static $auto_id = 100;
		$id = $postarr['ID'] ?? ++$auto_id;
		$obj = (object) array_merge(
			array(
				'ID'             => $id,
				'post_title'     => '',
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'post_name'      => sanitize_key( $postarr['post_title'] ?? 'post-' . $id ),
				'post_content'   => '',
				'post_excerpt'   => '',
				'post_parent'    => 0,
				'menu_order'     => 0,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_password'  => '',
			),
			$postarr
		);
		$GLOBALS['mock_posts'][ $id ] = $obj;
		if ( ! empty( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ) {
			foreach ( $postarr['meta_input'] as $mk => $mv ) {
				update_post_meta( $id, $mk, $mv );
			}
		}
		if ( isset( $GLOBALS['wp_test_insert_post_hook'] ) && is_callable( $GLOBALS['wp_test_insert_post_hook'] ) ) {
			( $GLOBALS['wp_test_insert_post_hook'] )( $id, $postarr );
		}
		return $id;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array|object $postarr, bool $wp_error = false ): int|WP_Error {
		$arr = (array) $postarr;
		$id  = (int) ( $arr['ID'] ?? 0 );
		if ( ! isset( $GLOBALS['mock_posts'][ $id ] ) ) {
			return $wp_error ? new WP_Error( 'post_not_found', 'Post not found.' ) : 0;
		}
		foreach ( $arr as $k => $v ) {
			$GLOBALS['mock_posts'][ $id ]->{$k} = $v;
		}
		if ( isset( $GLOBALS['wp_test_insert_post_hook'] ) && is_callable( $GLOBALS['wp_test_insert_post_hook'] ) ) {
			( $GLOBALS['wp_test_insert_post_hook'] )( $id, $arr );
		}
		return $id;
	}
}
if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( int|object $post, int $thumb_id ): bool {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return update_post_meta( $id, '_thumbnail_id', $thumb_id );
	}
}
if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( int|object $post ): bool {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return delete_post_meta( $id, '_thumbnail_id' );
	}
}
if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( int $object_id, mixed $terms, string $taxonomy, bool $append = false ): array|WP_Error {
		$GLOBALS['mock_terms'][ $object_id ][ $taxonomy ] = (array) $terms;
		return (array) $terms;
	}
}
if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( int $object_id, string $taxonomy, array $args = array() ): array {
		return $GLOBALS['mock_terms'][ $object_id ][ $taxonomy ] ?? array();
	}
}

// Mock Elementor runtime classes:
class MockElementorDocument {
	private int $post_id;
	public function __construct( int $post_id ) { $this->post_id = $post_id; }
	public function get_elements_data(): array {
		$raw = get_post_meta( $this->post_id, '_elementor_data', true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$data = json_decode( $raw, true );
			return is_array( $data ) ? $data : array();
		}
		return is_array( $raw ) ? $raw : array();
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
	public function get( $post_id ) { return new MockElementorDocument( (int) $post_id ); }
}
class MockElementorPlugin {
	public static $instance;
	public $documents;
	public function __construct() {
		$this->documents = new MockElementorDocumentsManager();
	}
}
if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
	class_alias( 'MockElementorPlugin', '\\Elementor\\Plugin' );
	\Elementor\Plugin::$instance = new MockElementorPlugin();
}

// In-Memory SQLite WPDB Mock.
class Phase5_Mock_WPDB {
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

	public function get_charset_collate(): string { return ''; }

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

	public function get_col( string $query, int $x = 0 ): array {
		try {
			if ( preg_match( '/SHOW COLUMNS FROM\s+([^\s;]+)/i', $query, $m ) ) {
				$table = trim( $m[1], '`' );
				$query = "SELECT name FROM pragma_table_info('{$table}')";
			}
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			return $stmt ? $stmt->fetchAll( \PDO::FETCH_COLUMN, $x ) : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public function get_var( string $query ): mixed {
		try {
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/i", $query, $m ) ) {
				$query = "SELECT name FROM sqlite_master WHERE type='table' AND name = '{$m[1]}'";
			}
			$query = $this->translate_query_for_sqlite( $query );
			$stmt  = $this->pdo->query( $query );
			return $stmt ? $stmt->fetchColumn() : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function get_results( string $query, string $output = 'OBJECT' ): array {
		try {
			if ( preg_match( '/^PRAGMA\s+/i', $query ) ) {
				$stmt = $this->pdo->query( $query );
				$rows = $stmt ? $stmt->fetchAll( \PDO::FETCH_ASSOC ) : array();
				if ( 'ARRAY_A' === $output ) {
					return $rows;
				}
				return array_map( static fn( $r ) => (object) $r, $rows );
			}
			if ( preg_match( '/SHOW INDEX(?:ES)? FROM\s+([^\s;]+)/i', $query, $m ) ) {
				$table = trim( $m[1], '`' );
				$rows  = array();
				$info_stmt = $this->pdo->query( "PRAGMA table_info('{$table}')" );
				if ( $info_stmt ) {
					$cols = $info_stmt->fetchAll( \PDO::FETCH_ASSOC );
					foreach ( $cols as $col ) {
						if ( ! empty( $col['pk'] ) ) {
							$rows[] = array(
								'Key_name'    => 'PRIMARY',
								'Column_name' => $col['name'],
								'Non_unique'  => 0,
							);
						}
					}
				}
				$idx_stmt = $this->pdo->query( "PRAGMA index_list('{$table}')" );
				if ( $idx_stmt ) {
					$indices = $idx_stmt->fetchAll( \PDO::FETCH_ASSOC );
					foreach ( $indices as $idx ) {
						$idx_name  = (string) $idx['name'];
						if ( str_starts_with( $idx_name, $table . '_' ) ) {
							$idx_name = substr( $idx_name, strlen( $table ) + 1 );
						}
						$is_unique = ! empty( $idx['unique'] ) ? 0 : 1;
						$col_stmt  = $this->pdo->query( "PRAGMA index_info('{$idx['name']}')" );
						if ( $col_stmt ) {
							$idx_cols = $col_stmt->fetchAll( \PDO::FETCH_ASSOC );
							foreach ( $idx_cols as $c ) {
								$rows[] = array(
									'Key_name'    => $idx_name,
									'Column_name' => (string) $c['name'],
									'Non_unique'  => $is_unique,
								);
							}
						}
					}
				}
				if ( 'ARRAY_A' === $output ) {
					return $rows;
				}
				return array_map( static fn( $r ) => (object) $r, $rows );
			}

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
		$query = preg_replace( '/DATE_SUB\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+DAY\s*\)/i', "datetime($1, '-$2 days')", $query );
		$query = preg_replace( '/\bUTC_TIMESTAMP\(\)\s*-\s*INTERVAL\s+([0-9]+)\s+DAY\b/i', "datetime(UTC_TIMESTAMP(), '-$1 days')", $query );
		$query = preg_replace( '/GREATEST\s*\(\s*([^,]+?)\s*,\s*([^)]+?)\s*\)/i', 'max($1, $2)', $query );
		return $query;
	}
}

global $wpdb;
$wpdb = new Phase5_Mock_WPDB();
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
require_once __DIR__ . '/../includes/safety/class-checkpoint-crypto.php';
require_once __DIR__ . '/../includes/safety/class-checkpoint-strategies.php';
require_once __DIR__ . '/../includes/safety/class-checkpoint-manager.php';
require_once __DIR__ . '/../includes/safety/class-mutation-middleware.php';
require_once __DIR__ . '/../includes/safety/class-safe-writes.php';
require_once __DIR__ . '/../includes/class-elementor-data.php';
require_once __DIR__ . '/../includes/class-id-generator.php';
require_once __DIR__ . '/../includes/class-element-factory.php';
require_once __DIR__ . '/../includes/abilities/class-page-abilities.php';
require_once __DIR__ . '/../includes/abilities/class-template-abilities.php';
require_once __DIR__ . '/../includes/abilities/class-composite-abilities.php';
require_once __DIR__ . '/../includes/abilities/class-custom-code-abilities.php';
require_once __DIR__ . '/../includes/abilities/class-global-abilities.php';

function setup_phase5_test_db(): void {
	global $wpdb;
	$schemas = Full_Elementor_MCP_Database_Installer::get_schema_definitions();
	foreach ( $schemas as $ddl ) {
		$wpdb->query( $ddl );
	}
}
setup_phase5_test_db();
Full_Elementor_MCP_Mutation_Registry::init_core_strategies();

// Test runner assertion utilities.
$tests_passed = 0;
$tests_failed = 0;

function assert_true( bool $condition, string $message = 'Expected condition to be true.' ): void {
	if ( ! $condition ) {
		$trace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 );
		$caller = $trace[0];
		throw new \Exception( "ASSERTION FAILED at line {$caller['line']}: " . $message );
	}
}

function assert_false( bool $condition, string $message = 'Expected condition to be false.' ): void {
	if ( $condition ) {
		$trace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 );
		$caller = $trace[0];
		throw new \Exception( "ASSERTION FAILED at line {$caller['line']}: " . $message );
	}
}

function assert_not_wp_error( mixed $thing, string $message = '' ): void {
	if ( is_wp_error( $thing ) ) {
		$trace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 );
		$caller = $trace[0];
		$err    = $thing->get_error_code() . ': ' . $thing->get_error_message();
		if ( is_array( $thing->get_error_data() ) ) {
			$err .= ' ' . json_encode( $thing->get_error_data() );
		}
		throw new \Exception( "ASSERTION FAILED at line {$caller['line']}: Unexpected WP_Error [{$err}]. " . $message );
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
	if ( $code !== $thing->get_error_code() ) {
		$err_detail = $thing->get_error_code() . ': ' . $thing->get_error_message();
		if ( is_array( $thing->get_error_data() ) ) {
			$err_detail .= ' Data: ' . json_encode( $thing->get_error_data() );
		}
		throw new \Exception( "ASSERTION FAILED: Expected [{$code}], got [{$err_detail}]. {$message}" );
	}
}

function run_test( string $name, callable $test ): void {
	global $tests_passed, $tests_failed, $wpdb;
	try {
		Full_Elementor_MCP_Mutation_Context::reset();
		Full_Elementor_MCP_Checkpoint_Crypto::reset_keys();
		$wpdb->query( "DELETE FROM {$wpdb->prefix}elementor_mcp_tokens" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}elementor_mcp_journal" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}elementor_mcp_checkpoints" );
		$GLOBALS['wp_test_caps'] = array(
			'manage_options'  => true,
			'edit_posts'      => true,
			'publish_posts'   => true,
			'delete_posts'    => true,
			'edit_pages'      => true,
			'publish_pages'   => true,
			'delete_pages'    => true,
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
		Full_Elementor_MCP_Checkpoint_Crypto::reset_keys();
	}
}

echo "\n=======================================================\n";
echo " Full Elementor MCP — Phase 5 Checkpoints Test Suite\n";
echo "=======================================================\n\n";

// -----------------------------------------------------------------------------
// 1. AEAD Cryptography & Key Provider
// -----------------------------------------------------------------------------

run_test( 'Crypto: plaintext encrypts and decrypts with exact round-trip fidelity', function () {
	$state = array(
		'post_id'   => 42,
		'title'     => 'Original Page',
		'structure' => array( 'type' => 'container', 'settings' => array( 'bg' => '#fff' ) ),
	);

	$meta = array(
		'checkpoint_uuid'        => 'chk_uuid_001',
		'resource_key'           => 'post:42',
		'payload_schema_version' => 1,
	);

	$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );
	assert_false( is_wp_error( $enc ), 'Encryption should succeed.' );
	assert_true( ! empty( $enc['encrypted_payload'] ) );
	assert_true( ! empty( $enc['nonce'] ) );
	assert_equals( 64, strlen( $enc['state_hash'] ) );

	// Verify plaintext marker does NOT appear in raw ciphertext:
	$raw_cipher = base64_decode( $enc['encrypted_payload'], true );
	assert_false( str_contains( $raw_cipher, 'Original Page' ), 'Plaintext marker must not be visible in raw ciphertext' );

	// Decrypt using simulated row:
	$row = array_merge( $meta, $enc );
	$dec = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row );
	assert_false( is_wp_error( $dec ), 'Decryption should succeed.' );
	assert_equals( $state, $dec, 'Decrypted state must match original state exactly' );
} );

run_test( 'Crypto: same plaintext encrypted twice produces different ciphertexts and nonces', function () {
	$state = array( 'key' => 'secret_val_123' );
	$meta  = array( 'checkpoint_uuid' => 'chk_u1', 'resource_key' => 'post:1', 'payload_schema_version' => 1 );

	$enc1 = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );
	$enc2 = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );

	assert_false( is_wp_error( $enc1 ) );
	assert_false( is_wp_error( $enc2 ) );
	assert_true( $enc1['nonce'] !== $enc2['nonce'], 'Nonces must be randomized per encryption' );
	assert_true( $enc1['encrypted_payload'] !== $enc2['encrypted_payload'], 'Ciphertexts must differ due to distinct nonces' );
	assert_equals( $enc1['state_hash'], $enc2['state_hash'], 'Plaintext state hashes must remain identical' );
} );

run_test( 'Crypto: modified ciphertext is rejected with checkpoint_integrity_failed', function () {
	$state = array( 'foo' => 'bar' );
	$meta  = array( 'checkpoint_uuid' => 'chk_tamper', 'resource_key' => 'post:5', 'payload_schema_version' => 1 );

	$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );
	assert_false( is_wp_error( $enc ) );

	// Tamper single bit in ciphertext:
	$raw = base64_decode( $enc['encrypted_payload'], true );
	$raw[0] = chr( ord( $raw[0] ) ^ 0xff );
	$enc['encrypted_payload'] = base64_encode( $raw );

	$row = array_merge( $meta, $enc );
	$dec = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row );
	assert_error_code( 'checkpoint_integrity_failed', $dec, 'Tampered ciphertext must be rejected' );
} );

run_test( 'Crypto: modified nonce is rejected with checkpoint_integrity_failed', function () {
	$state = array( 'foo' => 'bar' );
	$meta  = array( 'checkpoint_uuid' => 'chk_nonce', 'resource_key' => 'post:5', 'payload_schema_version' => 1 );

	$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );
	$raw_nonce = base64_decode( $enc['nonce'], true );
	$raw_nonce[0] = chr( ord( $raw_nonce[0] ) ^ 0xff );
	$enc['nonce'] = base64_encode( $raw_nonce );

	$row = array_merge( $meta, $enc );
	$dec = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row );
	assert_error_code( 'checkpoint_integrity_failed', $dec, 'Tampered nonce must be rejected' );
} );

run_test( 'Crypto: tampered state_hash is rejected with checkpoint_integrity_failed', function () {
	$state = array( 'foo' => 'bar' );
	$meta  = array( 'checkpoint_uuid' => 'chk_hash', 'resource_key' => 'post:5', 'payload_schema_version' => 1 );

	$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );
	$enc['state_hash'] = str_repeat( '0', 64 ); // Altered expected hash

	$row = array_merge( $meta, $enc );
	$dec = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row );
	assert_error_code( 'checkpoint_integrity_failed', $dec, 'Tampered hash must be rejected' );
} );

run_test( 'Crypto: wrong key rejects decryption with checkpoint_integrity_failed', function () {
	$key1 = random_bytes( 32 );
	$key2 = random_bytes( 32 );

	Full_Elementor_MCP_Checkpoint_Crypto::register_key( 'k_test_1', 1, $key1, true );

	$state = array( 'data' => 123 );
	$meta  = array( 'checkpoint_uuid' => 'chk_k1', 'resource_key' => 'post:7', 'payload_schema_version' => 1 );

	$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );
	assert_false( is_wp_error( $enc ) );

	// Register key2 under key_id to simulate key substitution:
	Full_Elementor_MCP_Checkpoint_Crypto::register_key( 'k_test_1', 1, $key2, true );

	$row = array_merge( $meta, $enc );
	$dec = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row );
	assert_error_code( 'checkpoint_integrity_failed', $dec, 'Decryption with wrong key must fail integrity check' );
} );

run_test( 'Crypto: historical key selection allows decrypting v1 while v2 is active', function () {
	$key1 = random_bytes( 32 );
	$key2 = random_bytes( 32 );

	Full_Elementor_MCP_Checkpoint_Crypto::register_key( 'key_v1', 1, $key1, true );

	$state1 = array( 'generation' => 'one' );
	$meta1  = array( 'checkpoint_uuid' => 'chk_gen1', 'resource_key' => 'post:10', 'payload_schema_version' => 1 );
	$enc1   = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state1, $meta1 );
	assert_equals( 'key_v1', $enc1['key_id'] );

	// Key v2 becomes active:
	Full_Elementor_MCP_Checkpoint_Crypto::register_key( 'key_v2', 2, $key2, true );

	$state2 = array( 'generation' => 'two' );
	$meta2  = array( 'checkpoint_uuid' => 'chk_gen2', 'resource_key' => 'post:10', 'payload_schema_version' => 1 );
	$enc2   = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state2, $meta2 );
	assert_equals( 'key_v2', $enc2['key_id'] );

	// Old checkpoint 1 must still decrypt using key_v1:
	$row1 = array_merge( $meta1, $enc1 );
	$dec1 = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row1 );
	assert_false( is_wp_error( $dec1 ) );
	assert_equals( 'one', $dec1['generation'] );

	// Checkpoint 2 decrypts using key_v2:
	$row2 = array_merge( $meta2, $enc2 );
	$dec2 = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row2 );
	assert_false( is_wp_error( $dec2 ) );
	assert_equals( 'two', $dec2['generation'] );
} );

run_test( 'Crypto: missing historical key returns explicit checkpoint_key_unavailable', function () {
	$state = array( 'val' => 999 );
	$meta  = array( 'checkpoint_uuid' => 'chk_lost', 'resource_key' => 'post:12', 'payload_schema_version' => 1 );

	Full_Elementor_MCP_Checkpoint_Crypto::register_key( 'temp_key', 1, random_bytes( 32 ), true );
	$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );

	// Wipe keys:
	Full_Elementor_MCP_Checkpoint_Crypto::reset_keys();

	$row = array_merge( $meta, $enc );
	$dec = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row );
	assert_error_code( 'checkpoint_key_unavailable', $dec, 'Missing key must return checkpoint_key_unavailable' );
} );

run_test( 'Crypto: OpenSSL fallback operates correctly with authentication tag', function () {
	Full_Elementor_MCP_Checkpoint_Crypto::set_forced_algorithm( Full_Elementor_MCP_Checkpoint_Crypto::ALGO_AES_256_GCM );

	$state = array( 'engine' => 'openssl_gcm' );
	$meta  = array( 'checkpoint_uuid' => 'chk_ssl', 'resource_key' => 'post:99', 'payload_schema_version' => 1 );

	$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );
	assert_false( is_wp_error( $enc ) );
	assert_equals( Full_Elementor_MCP_Checkpoint_Crypto::ALGO_AES_256_GCM, $enc['encryption_algorithm'] );
	assert_true( ! empty( $enc['auth_tag'] ), 'AES-256-GCM must store authentication tag' );

	$row = array_merge( $meta, $enc );
	$dec = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $row );
	assert_false( is_wp_error( $dec ) );
	assert_equals( 'openssl_gcm', $dec['engine'] );

	Full_Elementor_MCP_Checkpoint_Crypto::set_forced_algorithm( null );
} );

// -----------------------------------------------------------------------------
// 2. Row-Swapping Protection (AAD Cryptographic Binding)
// -----------------------------------------------------------------------------

run_test( 'Row-Swapping: swapping ciphertext between checkpoints fails decryption via AAD binding', function () {
	$state_a = array( 'target' => 'resource_a' );
	$state_b = array( 'target' => 'resource_b' );

	$meta_a = array( 'checkpoint_uuid' => 'uuid_aaa', 'resource_key' => 'post:10', 'payload_schema_version' => 1 );
	$meta_b = array( 'checkpoint_uuid' => 'uuid_bbb', 'resource_key' => 'post:20', 'payload_schema_version' => 1 );

	$enc_a = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state_a, $meta_a );
	$enc_b = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state_b, $meta_b );

	// Swap: Place ciphertext A into Row B:
	$swapped_row_b = array_merge( $meta_b, array(
		'encryption_algorithm' => $enc_a['encryption_algorithm'],
		'key_id'               => $enc_a['key_id'],
		'key_version'          => $enc_a['key_version'],
		'nonce'                => $enc_a['nonce'],
		'auth_tag'             => $enc_a['auth_tag'],
		'encrypted_payload'    => $enc_a['encrypted_payload'],
		'state_hash'           => $enc_a['state_hash'],
	) );

	$dec = Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $swapped_row_b );
	assert_error_code( 'checkpoint_integrity_failed', $dec, 'Row-swapped ciphertext must fail AAD verification' );
} );

// -----------------------------------------------------------------------------
// 3. Serialization & Size Bounds
// -----------------------------------------------------------------------------

run_test( 'Serialization: unknown JSON-safe third-party settings preserved', function () {
	$state = array(
		'elementor_settings' => array(
			'custom_ext_foo' => 'bar',
			'numbers_list'   => array( 1, 2, 3 ),
			'nested_map'     => array( 'z' => 9, 'a' => 1 ),
		),
	);

	$json = Full_Elementor_MCP_Checkpoint_Crypto::serialize_state( $state );
	assert_false( is_wp_error( $json ) );
	$decoded = Full_Elementor_MCP_Checkpoint_Crypto::deserialize_state( $json );
	assert_equals( $state, $decoded );
} );

run_test( 'Serialization: PHP objects in state are rejected before encryption', function () {
	$state = array( 'obj' => new \stdClass() );
	$meta  = array( 'checkpoint_uuid' => 'chk_obj', 'resource_key' => 'post:1', 'payload_schema_version' => 1 );

	$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );
	assert_error_code( 'checkpoint_serialization_failed', $enc, 'Objects must be rejected' );
} );

run_test( 'Serialization: Closures in state are rejected before encryption', function () {
	$state = array( 'cb' => function () { return 1; } );
	$meta  = array( 'checkpoint_uuid' => 'chk_cls', 'resource_key' => 'post:1', 'payload_schema_version' => 1 );

	$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );
	assert_error_code( 'checkpoint_serialization_failed', $enc, 'Closures must be rejected' );
} );

run_test( 'Serialization: payload exceeding 16 MiB bound is rejected with checkpoint_too_large', function () {
	// Create large string > 16 MiB:
	$big_str = str_repeat( 'A', Full_Elementor_MCP_Checkpoint_Crypto::MAX_PAYLOAD_BYTES + 100 );
	$state   = array( 'big' => $big_str );
	$meta    = array( 'checkpoint_uuid' => 'chk_big', 'resource_key' => 'post:1', 'payload_schema_version' => 1 );

	$enc = Full_Elementor_MCP_Checkpoint_Crypto::encrypt( $state, $meta );
	assert_error_code( 'checkpoint_too_large', $enc, 'Oversized state must be rejected' );
} );

// -----------------------------------------------------------------------------
// 4. Post-Backed Elementor Strategy & Exact Restore
// -----------------------------------------------------------------------------

run_test( 'Strategy Post: capture, mutate, and exact restore Elementor page with sensitive password', function () {
	// 1. Create page with post password, Elementor data, and settings:
	$post_id = wp_insert_post( array(
		'post_title'    => 'Original Landing Page',
		'post_status'   => 'publish',
		'post_password' => 'secret_p@ssw0rd_123',
	) );

	$elements = array(
		array(
			'id'       => 'sec100',
			'elType'   => 'section',
			'elements' => array(),
			'settings' => array( 'layout' => 'boxed' ),
		),
	);
	$page_settings = array( 'custom_css' => 'body { color: red; }' );

	update_post_meta( $post_id, '_elementor_data', wp_json_encode( $elements ) );
	update_post_meta( $post_id, '_elementor_page_settings', $page_settings );
	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
	set_post_thumbnail( $post_id, 88 );

	// 2. Capture and persist checkpoint:
	$res_key = 'post:' . $post_id;
	$saved   = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( $res_key, 'automatic' );
	assert_false( is_wp_error( $saved ) );
	assert_equals( 'exact', $saved['restore_capability'] );

	// Verify sensitive post password is NOT in DB row plaintext columns:
	global $wpdb;
	$table = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $saved['id'] ), ARRAY_A );
	assert_false( str_contains( json_encode( $row ), 'secret_p@ssw0rd_123' ), 'Sensitive password must not be in plaintext metadata' );

	// 3. Mutate page state:
	wp_update_post( array(
		'ID'            => $post_id,
		'post_title'    => 'Mutated Page Title',
		'post_password' => 'corrupted_pwd',
	) );
	update_post_meta( $post_id, '_elementor_data', wp_json_encode( array() ) );
	update_post_meta( $post_id, '_elementor_page_settings', array( 'custom_css' => 'body { color: blue; }' ) );
	delete_post_thumbnail( $post_id );

	// 4. Restore historical checkpoint:
	$restore_res = Full_Elementor_MCP_Checkpoint_Manager::restore( $saved['id'] );
	assert_not_wp_error( $restore_res );
	assert_true( $restore_res['restored'], 'Restore must report restored = true' );

	// 5. Verify exact persistent state restored:
	$restored_post = get_post( $post_id );
	assert_equals( 'Original Landing Page', $restored_post->post_title );
	assert_equals( 'secret_p@ssw0rd_123', $restored_post->post_password );
	assert_equals( $elements, json_decode( get_post_meta( $post_id, '_elementor_data', true ), true ) );
	assert_equals( $page_settings, get_post_meta( $post_id, '_elementor_page_settings', true ) );
	assert_equals( 88, (int) get_post_meta( $post_id, '_thumbnail_id', true ) );
} );

// -----------------------------------------------------------------------------
// 5. Custom Code Snippet Strategy & Executable Code Protection
// -----------------------------------------------------------------------------

run_test( 'Strategy Snippet: captures executable snippet and restores code exactly', function () {
	$snippet_id = wp_insert_post( array(
		'post_title'  => 'Analytics Snippet',
		'post_type'   => 'elementor_snippet',
		'post_status' => 'publish',
	) );

	$raw_code = 'console.log("analytics_tracking_code_sensitive_token_abc");';
	update_post_meta( $snippet_id, '_elementor_code', $raw_code );
	update_post_meta( $snippet_id, '_elementor_location', 'head' );
	update_post_meta( $snippet_id, '_elementor_priority', 10 );

	$res_key = 'post:' . $snippet_id;
	$saved   = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( $res_key, 'automatic' );
	assert_not_wp_error( $saved );

	// Verify raw code is absent from plaintext columns:
	global $wpdb;
	$table = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $saved['id'] ), ARRAY_A );
	assert_false( str_contains( json_encode( $row ), 'analytics_tracking_code_sensitive_token_abc' ) );

	// Mutate code:
	update_post_meta( $snippet_id, '_elementor_code', 'console.log("defaced");' );
	update_post_meta( $snippet_id, '_elementor_priority', 99 );

	// Restore:
	$restore_res = Full_Elementor_MCP_Checkpoint_Manager::restore( $saved['id'] );
	assert_not_wp_error( $restore_res );

	assert_equals( $raw_code, get_post_meta( $snippet_id, '_elementor_code', true ) );
	assert_equals( 10, (int) get_post_meta( $snippet_id, '_elementor_priority', true ) );
} );

// -----------------------------------------------------------------------------
// 6. Global Elementor Kit Strategy
// -----------------------------------------------------------------------------

run_test( 'Strategy Global: captures active kit and restores kit settings and option', function () {
	$kit_id = wp_insert_post( array(
		'post_title'  => 'Default Kit',
		'post_type'   => 'elementor_library',
		'post_status' => 'publish',
	) );
	update_option( 'elementor_active_kit', $kit_id );

	$kit_settings = array(
		'custom_colors'     => array( array( '_id' => 'c1', 'color' => '#ff0000' ) ),
		'custom_typography' => array( array( '_id' => 't1', 'font_family' => 'Inter' ) ),
	);
	update_post_meta( $kit_id, '_elementor_page_settings', $kit_settings );

	$saved = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'global:elementor-kit-state', 'automatic' );
	assert_not_wp_error( $saved );

	// Mutate kit settings and active kit:
	$new_kit_id = wp_insert_post( array( 'post_title' => 'New Kit', 'post_type' => 'elementor_library' ) );
	update_option( 'elementor_active_kit', $new_kit_id );
	update_post_meta( $kit_id, '_elementor_page_settings', array( 'custom_colors' => array() ) );

	// Restore:
	$restore_res = Full_Elementor_MCP_Checkpoint_Manager::restore( $saved['id'] );
	assert_not_wp_error( $restore_res );

	assert_equals( $kit_id, (int) get_option( 'elementor_active_kit' ) );
	assert_equals( $kit_settings, get_post_meta( $kit_id, '_elementor_page_settings', true ) );
} );

// -----------------------------------------------------------------------------
// 7. Recovery-Only Checkpoint
// -----------------------------------------------------------------------------

run_test( 'Strategy Recovery-Only: irreversible deletion snapshot rejects exact restore', function () {
	$post_id = wp_insert_post( array( 'post_title' => 'Doomed Page' ) );
	$res_key = 'post:' . $post_id;

	$saved = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( $res_key, 'recovery', array( 'is_permanent_delete' => true ) );
	assert_not_wp_error( $saved );
	assert_equals( 'recovery_only', $saved['restore_capability'] );

	// Attempt exact restore:
	$restore_res = Full_Elementor_MCP_Checkpoint_Manager::restore( $saved['id'] );
	assert_error_code( 'checkpoint_restore_unsupported', $restore_res, 'Recovery-only checkpoint must reject exact restore' );
} );

// -----------------------------------------------------------------------------
// 8. Automatic Middleware Checkpoint Policy & WAL Ordering
// -----------------------------------------------------------------------------

function register_phase5_mock_ability( string $name, bool $readonly, callable $execute, ?callable $perm = null ): void {
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

register_phase5_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) {
	return array( 'success' => true );
} );

register_phase5_mock_ability( 'full-elementor-mcp/create-page', false, function ( $input ) {
	$post_id = Full_Elementor_MCP_Safe_Writes::insert_post( array(
		'post_title' => $input['title'] ?? 'Brand New Page',
		'post_type'  => 'page',
	) );
	return array(
		'post_id' => $post_id,
		'success' => true,
	);
} );

run_test( 'Middleware Policy: ordinary heading edit does not create checkpoint by default', function () {
	global $wpdb;
	$post_id = wp_insert_post( array( 'post_title' => 'Simple Page' ) );

	$chk_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_checkpoints" );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/update-element',
		array(
			'post_id'    => $post_id,
			'element_id' => 'elem1',
			'settings'   => array( 'title' => 'Updated text' ),
		)
	);

	assert_not_wp_error( $res );
	$chk_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_checkpoints" );
	assert_equals( $chk_before, $chk_after, 'Ordinary non-critical edit must not create automatic checkpoint' );
} );

run_test( 'Middleware Policy: protected homepage edit creates required checkpoint before callback', function () {
	global $wpdb;
	$post_id = wp_insert_post( array( 'post_title' => 'Site Homepage' ) );
	update_option( 'page_on_front', $post_id );
	update_option( 'show_on_front', 'page' );

	$args = array(
		'post_id'    => $post_id,
		'element_id' => 'elem1',
		'settings'   => array( 'title' => 'Protected Edit' ),
	);

	$token = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
		'full-elementor-mcp/update-element',
		$args,
		1,
		null,
		'post:' . $post_id
	)['confirmation_token'];

	$chk_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_checkpoints" );

	$exec_args                       = $args;
	$exec_args['confirmation_token'] = $token;

	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/update-element',
		$exec_args
	);

	assert_not_wp_error( $res );
	$chk_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_checkpoints" );
	assert_equals( $chk_before + 1, $chk_after, 'Protected homepage mutation must create required checkpoint' );

	delete_option( 'page_on_front' );
} );

run_test( 'Middleware Policy: CREATE page does not create empty pre-checkpoint', function () {
	global $wpdb;
	$chk_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_checkpoints" );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/create-page',
		array(
			'title' => 'Brand New Page',
		)
	);

	assert_not_wp_error( $res );
	$chk_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_checkpoints" );
	assert_equals( $chk_before, $chk_after, 'CREATE operations must not create meaningless pre-checkpoints' );
} );

run_test( 'Middleware Policy: dry-run never creates checkpoint and reports predictive capability', function () {
	global $wpdb;
	$post_id = wp_insert_post( array( 'post_title' => 'Dry Run Page' ) );

	$chk_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_checkpoints" );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => $post_id,
		'element_id' => 'elem1',
		'dry_run'    => true,
	) );

	assert_false( is_wp_error( $res ) );
	assert_true( $res['dry_run'] );
	assert_true( isset( $res['checkpoint_required'] ) );
	assert_true( isset( $res['checkpoint_supported'] ) );
	assert_true( isset( $res['checkpoint_restore_capability'] ) );

	$chk_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_checkpoints" );
	assert_equals( $chk_before, $chk_after, 'Dry-run must be strictly side-effect free' );
} );

run_test( 'Middleware Idempotency: completed replay does not create duplicate checkpoint', function () {
	global $wpdb;
	$post_id = wp_insert_post( array( 'post_title' => 'Idemp Page' ) );
	update_option( 'page_on_front', $post_id );

	$args = array(
		'post_id'         => $post_id,
		'element_id'      => 'e1',
		'idempotency_key' => 'idemp_chk_replay',
	);

	$challenge = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
		'full-elementor-mcp/update-element',
		$args,
		1,
		null,
		'post:' . $post_id
	);
	$args['confirmation_token'] = $challenge['confirmation_token'];

	// 1. Initial execution creates 1 checkpoint:
	$res1 = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', $args );
	assert_false( is_wp_error( $res1 ) );
	$count1 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_checkpoints" );
	assert_equals( 1, $count1 );

	// 2. Replay of completed execution:
	$res2 = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', $args );
	assert_false( is_wp_error( $res2 ) );
	$count2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_checkpoints" );
	assert_equals( 1, $count2, 'Replay of completed mutation must not create second checkpoint' );

	delete_option( 'page_on_front' );
} );

// -----------------------------------------------------------------------------
// 9. Restore Engine Safety & Fencing
// -----------------------------------------------------------------------------

run_test( 'Restore Engine: resource mismatch cannot restore across resources', function () {
	$post_a = wp_insert_post( array( 'post_title' => 'Page A' ) );
	$post_b = wp_insert_post( array( 'post_title' => 'Page B' ) );

	$saved_a = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:' . $post_a );
	assert_false( is_wp_error( $saved_a ) );

	// Attempt restore with explicit mismatched target:
	$res = Full_Elementor_MCP_Checkpoint_Manager::restore( $saved_a['id'], array( 'target_resource_key' => 'post:' . $post_b ) );
	assert_error_code( 'checkpoint_resource_mismatch', $res, 'Cross-resource restore must be rejected' );
} );

run_test( 'Restore Engine: current state already equals checkpoint returns no-op', function () {
	$post_id = wp_insert_post( array( 'post_title' => 'Noop Page' ) );
	$saved   = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:' . $post_id );
	assert_false( is_wp_error( $saved ) );

	// Without mutating, restore immediately:
	$res = Full_Elementor_MCP_Checkpoint_Manager::restore( $saved['id'] );
	assert_false( is_wp_error( $res ) );
	assert_true( ! empty( $res['noop'] ), 'Restore must return no-op when state is already identical' );
} );

run_test( 'Restore Engine: pre-restore checkpoint is created before persistent writes', function () {
	global $wpdb;
	$post_id = wp_insert_post( array( 'post_title' => 'State C Page' ) );
	$saved_a = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:' . $post_id, 'automatic', array( 'label' => 'Historical A' ) );
	assert_false( is_wp_error( $saved_a ) );

	// Mutate to State C:
	wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Mutated C Title' ) );

	// Restore A:
	$restore_res = Full_Elementor_MCP_Checkpoint_Manager::restore( $saved_a['id'] );
	assert_false( is_wp_error( $restore_res ) );
	assert_true( ! empty( $restore_res['pre_restore_uuid'] ) );

	// Check DB for pre_restore checkpoint:
	$table = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();
	$pre_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE checkpoint_uuid = %s", $restore_res['pre_restore_uuid'] ), ARRAY_A );
	assert_true( ! empty( $pre_row ), 'Pre-restore checkpoint must exist in database' );
	assert_equals( 'pre_restore', $pre_row['checkpoint_type'] );

	// Decrypt pre-restore checkpoint to ensure it contains State C:
	$dec_c = Full_Elementor_MCP_Checkpoint_Manager::decrypt_checkpoint( $pre_row );
	assert_equals( 'Mutated C Title', $dec_c['post_fields']['post_title'] );
} );

run_test( 'Restore Engine: stale restore writer is rejected at physical boundary', function () {
	global $wpdb;
	$post_id = wp_insert_post( array( 'post_title' => 'Stale Restore Page' ) );
	$saved   = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:' . $post_id );
	assert_false( is_wp_error( $saved ) );

	wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Changed Before Stale Restore' ) );

	// Hook into post_update to simulate another writer acquiring higher fence during restore:
	$GLOBALS['wp_test_insert_post_hook'] = function ( $p_id, $p_arr ) use ( $wpdb, $post_id ) {
		if ( (int) $p_id === (int) $post_id ) {
			$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( 'post:' . $post_id );
			$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );
		}
	};

	$res = Full_Elementor_MCP_Checkpoint_Manager::restore( $saved['id'] );
	$GLOBALS['wp_test_insert_post_hook'] = null;

	assert_is_wp_error( $res );
	assert_true( 'stale_writer_conflict' === $res->get_error_code() || 'checkpoint_restore_recovery_required' === $res->get_error_code() );
} );

// -----------------------------------------------------------------------------
// 10. Retention & Pruning (DB UTC)
// -----------------------------------------------------------------------------

run_test( 'Retention: old automatic checkpoints pruned while newest and pinned preserved', function () {
	global $wpdb;
	$table = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();

	$res_key = 'post:500';

	// Insert 5 checkpoints: 3 old, 1 pinned old, 1 fresh newest:
	for ( $i = 1; $i <= 3; $i++ ) {
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (checkpoint_uuid, created_at, resource_key, object_type, object_id, checkpoint_type, restore_capability, payload_schema_version, encryption_algorithm, key_version, key_id, nonce, auth_tag, encrypted_payload, state_hash, size_bytes, label, trigger_type, file_path, file_hash_hmac, is_pinned)
				 VALUES (%s, datetime(UTC_TIMESTAMP(), '-45 days'), %s, 'post', 500, 'automatic', 'exact', 1, 'none', 1, 'k', 'n', 't', 'p', 'h', 10, 'old', 'auto', '', '', 0)",
				'old_uuid_' . $i,
				$res_key
			)
		);
	}

	// Pinned old checkpoint (45 days old, is_pinned = 1):
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (checkpoint_uuid, created_at, resource_key, object_type, object_id, checkpoint_type, restore_capability, payload_schema_version, encryption_algorithm, key_version, key_id, nonce, auth_tag, encrypted_payload, state_hash, size_bytes, label, trigger_type, file_path, file_hash_hmac, is_pinned)
			 VALUES (%s, datetime(UTC_TIMESTAMP(), '-45 days'), %s, 'post', 500, 'automatic', 'exact', 1, 'none', 1, 'k', 'n', 't', 'p', 'h', 10, 'pinned', 'auto', '', '', 1)",
			'pinned_old_uuid',
			$res_key
		)
	);

	// Fresh newest checkpoint:
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (checkpoint_uuid, created_at, resource_key, object_type, object_id, checkpoint_type, restore_capability, payload_schema_version, encryption_algorithm, key_version, key_id, nonce, auth_tag, encrypted_payload, state_hash, size_bytes, label, trigger_type, file_path, file_hash_hmac, is_pinned)
			 VALUES (%s, UTC_TIMESTAMP(), %s, 'post', 500, 'automatic', 'exact', 1, 'none', 1, 'k', 'n', 't', 'p', 'h', 10, 'newest', 'auto', '', '', 0)",
			'newest_uuid',
			$res_key
		)
	);

	assert_equals( 5, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE resource_key = '{$res_key}'" ) );

	// Prune older than 30 days:
	$pruned = Full_Elementor_MCP_Checkpoint_Manager::prune( $res_key, 30, 20 );
	assert_equals( 3, $pruned, 'Exactly 3 unpinned old checkpoints should be pruned' );

	$remaining = $wpdb->get_col( "SELECT checkpoint_uuid FROM {$table} WHERE resource_key = '{$res_key}'" );
	assert_true( in_array( 'pinned_old_uuid', $remaining, true ), 'Pinned old checkpoint must be preserved' );
	assert_true( in_array( 'newest_uuid', $remaining, true ), 'Newest checkpoint must be preserved' );
} );

// -----------------------------------------------------------------------------
// 11. Schema & Exactly Four Tables Verification
// -----------------------------------------------------------------------------

run_test( 'Schema: exactly four safety tables remain after Phase 5 extensions', function () {
	global $wpdb;
	$schemas = Full_Elementor_MCP_Database_Installer::get_schema_definitions();
	assert_equals( 4, count( $schemas ), 'There must be exactly four safety table definitions' );

	$expected = Full_Elementor_MCP_Database_Installer::get_expected_schema();
	assert_equals( 4, count( $expected ), 'There must be exactly four expected schema table specs' );

	assert_true( Full_Elementor_MCP_Database_Installer::verify_schema(), 'Full schema verification must pass' );
	assert_equals( '1.1.0', Full_Elementor_MCP_Database_Installer::DB_VERSION );
} );

echo "\n=======================================================\n";
echo " Test Results: {$tests_passed}/" . ( $tests_passed + $tests_failed ) . " passed.\n";
echo "=======================================================\n\n";

if ( $tests_failed > 0 ) {
	exit( 1 );
}
