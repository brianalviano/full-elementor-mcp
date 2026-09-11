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

$GLOBALS['wp_test_options']     = array();
$GLOBALS['mock_post_meta']      = array();
$GLOBALS['mock_posts']          = array();
$GLOBALS['wp_test_user_id']     = 1;
$GLOBALS['wp_test_caps']        = array( 'manage_options' => true, 'edit_posts' => true, 'unfiltered_html' => true, 'publish_pages' => true, 'edit_pages' => true );
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
		if ( ( 'edit_post' === $cap || 'edit_page' === $cap || 'publish_page' === $cap ) && ! empty( $GLOBALS['wp_test_caps']['edit_posts'] ) ) {
			return true;
		}
		if ( ( 'delete_post' === $cap || 'delete_page' === $cap ) && ! empty( $GLOBALS['wp_test_caps']['delete_posts'] ) ) {
			return true;
		}
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
		$val = $GLOBALS['mock_post_meta'][ $post_id ][ $key ] ?? '';
		return $single ? $val : ( '' !== $val ? array( $val ) : array() );
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
		if ( isset( $GLOBALS['wp_test_get_post_hook'] ) && is_callable( $GLOBALS['wp_test_get_post_hook'] ) ) {
			( $GLOBALS['wp_test_get_post_hook'] )( $post_id );
		}
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
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false, bool $fire_after_hooks = true ): int|WP_Error {
		static $next_id = 2000;
		$id = ! empty( $postarr['ID'] ) ? (int) $postarr['ID'] : ++$next_id;
		$GLOBALS['mock_posts'][ $id ] = (object) array_merge(
			array( 'ID' => $id, 'post_title' => '', 'post_name' => '', 'post_type' => 'post', 'post_status' => 'draft' ),
			$postarr,
			array( 'ID' => $id )
		);
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
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int|object|null $post = null ): string|false {
		if ( is_object( $post ) ) {
			return $post->post_type ?? false;
		}
		$post_id = (int) $post;
		return $GLOBALS['mock_posts'][ $post_id ]->post_type ?? false;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int|object $post = 0 ): string|false {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return 'https://example.com/?p=' . $id;
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( int|object|null $post = null ): int|false {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		$thumb = get_post_meta( $id, '_thumbnail_id', true );
		return ! empty( $thumb ) ? (int) $thumb : false;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array|object $postarr, bool $wp_error = false, bool $fire_after_hooks = true ): int|WP_Error {
		$arr = (array) $postarr;
		$id  = (int) ( $arr['ID'] ?? 0 );
		if ( $id <= 0 ) {
			return $wp_error ? new WP_Error( 'invalid_id', 'Invalid ID' ) : 0;
		}
		$existing = isset( $GLOBALS['mock_posts'][ $id ] ) ? (array) $GLOBALS['mock_posts'][ $id ] : array();
		$GLOBALS['mock_posts'][ $id ] = (object) array_merge( $existing, $arr );
		return $id;
	}
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $postid, bool $force_delete = false ): mixed {
		if ( ! isset( $GLOBALS['mock_posts'][ $postid ] ) ) {
			return false;
		}
		$post = $GLOBALS['mock_posts'][ $postid ];
		unset( $GLOBALS['mock_posts'][ $postid ] );
		return $post;
	}
}
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( int $postid ): mixed {
		if ( ! isset( $GLOBALS['mock_posts'][ $postid ] ) ) {
			return false;
		}
		$GLOBALS['mock_posts'][ $postid ]->post_status = 'trash';
		return $GLOBALS['mock_posts'][ $postid ];
	}
}
if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( int $postid ): mixed {
		if ( ! isset( $GLOBALS['mock_posts'][ $postid ] ) ) {
			return false;
		}
		$GLOBALS['mock_posts'][ $postid ]->post_status = 'publish';
		return $GLOBALS['mock_posts'][ $postid ];
	}
}
if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( int|object $post, int $thumbnail_id ): bool {
		$post_id = is_object( $post ) ? $post->ID : (int) $post;
		update_post_meta( $post_id, '_thumbnail_id', $thumbnail_id );
		return true;
	}
}
if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( int|object $post ): bool {
		$post_id = is_object( $post ) ? $post->ID : (int) $post;
		delete_post_meta( $post_id, '_thumbnail_id' );
		return true;
	}
}
if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( int $object_id, mixed $terms, string $taxonomy, bool $append = false ): array|WP_Error {
		if ( isset( $GLOBALS['wp_test_set_terms_hook'] ) && is_callable( $GLOBALS['wp_test_set_terms_hook'] ) ) {
			( $GLOBALS['wp_test_set_terms_hook'] )( $object_id, $terms, $taxonomy );
		}
		return is_array( $terms ) ? $terms : array( (int) $terms );
	}
}
if ( ! function_exists( 'media_handle_sideload' ) ) {
	function media_handle_sideload( array $file_array, int $post_id = 0, ?string $desc = null, array $post_data = array() ): int|WP_Error {
		$res = wp_insert_post( array(
			'post_type'      => 'attachment',
			'post_mime_type' => $file_array['type'] ?? 'image/jpeg',
			'post_title'     => $desc ?? 'Sideloaded',
		) );
		if ( isset( $GLOBALS['wp_test_sideload_hook'] ) && is_callable( $GLOBALS['wp_test_sideload_hook'] ) ) {
			( $GLOBALS['wp_test_sideload_hook'] )( $res, $file_array );
		}
		return $res;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '', ?string $scheme = 'admin' ): string {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return preg_replace( '/[^a-zA-Z0-9_\.-]/', '', $name ) ?? '';
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $post_id = 0 ): string|false {
		return 'https://example.com/wp-content/uploads/' . $post_id . '.jpg';
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): bool {
		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
		return true;
	}
}
if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( string $filename, ?array $mimes = null ): array {
		return array( 'ext' => 'jpg', 'type' => 'image/jpeg' );
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int|object $post = 0 ): string {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return $GLOBALS['mock_posts'][ $id ]->post_title ?? ( 'Post ' . $id );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $data ): string {
		return $data;
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
		if ( isset( $GLOBALS['wp_test_document_save_hook'] ) && is_callable( $GLOBALS['wp_test_document_save_hook'] ) ) {
			( $GLOBALS['wp_test_document_save_hook'] )( $this->post_id, $data );
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
require_once __DIR__ . '/../includes/safety/class-safe-writes.php';
require_once __DIR__ . '/../includes/class-elementor-data.php';
require_once __DIR__ . '/../includes/class-id-generator.php';
require_once __DIR__ . '/../includes/class-element-factory.php';
require_once __DIR__ . '/../includes/abilities/class-page-abilities.php';
require_once __DIR__ . '/../includes/abilities/class-template-abilities.php';
require_once __DIR__ . '/../includes/abilities/class-composite-abilities.php';
require_once __DIR__ . '/../includes/abilities/class-custom-code-abilities.php';
require_once __DIR__ . '/../includes/class-openverse-client.php';
require_once __DIR__ . '/../includes/abilities/class-stock-image-abilities.php';
require_once __DIR__ . '/../includes/abilities/class-svg-icon-abilities.php';
require_once __DIR__ . '/../includes/abilities/class-global-abilities.php';

if ( ! function_exists( 'full_elementor_mcp_register_ability' ) ) {
	function full_elementor_mcp_register_ability( string $name, array $args ) {
		return Full_Elementor_MCP_Mutation_Middleware::wrap_ability( $name, $args );
	}
}

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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}elementor_mcp_tokens" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}elementor_mcp_journal" );
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
		$post_id = Full_Elementor_MCP_Safe_Writes::insert_post( array(
			'post_title' => $input['title'] ?? 'Brand New Page',
			'post_type'  => 'page',
		) );
		return array( 'post_id' => $post_id, 'edit_url' => 'https://example.com/wp-admin/post.php?post=' . $post_id );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title' => 'Brand New Page',
	) );

	assert_false( is_wp_error( $res ) );
	assert_true( ( $res['post_id'] ?? 0 ) > 0 );
	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal WHERE ability = 'full-elementor-mcp/create-page' ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_equals( (int) $res['post_id'], (int) $row['created_object_id'] );
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

// -----------------------------------------------------------------------------
// 16. Plugin-Owned Safe Write Sinks & Physical Fencing Boundaries
// -----------------------------------------------------------------------------

run_test( 'Safe_Writes: write without active context is rejected with mutation_context_missing', function () {
	Full_Elementor_MCP_Mutation_Context::reset();

	$err1 = Full_Elementor_MCP_Safe_Writes::insert_post( array( 'post_title' => 'Unfenced' ) );
	assert_error_code( 'mutation_context_missing', $err1 );

	$err2 = Full_Elementor_MCP_Safe_Writes::update_post( array( 'ID' => 10, 'post_title' => 'Unfenced' ) );
	assert_error_code( 'mutation_context_missing', $err2 );

	$err3 = Full_Elementor_MCP_Safe_Writes::delete_post( 10, true );
	assert_error_code( 'mutation_context_missing', $err3 );

	$err4 = Full_Elementor_MCP_Safe_Writes::trash_post( 10 );
	assert_error_code( 'mutation_context_missing', $err4 );

	$err5 = Full_Elementor_MCP_Safe_Writes::untrash_post( 10 );
	assert_error_code( 'mutation_context_missing', $err5 );

	$err6 = Full_Elementor_MCP_Safe_Writes::update_post_meta( 10, '_some_key', 'val' );
	assert_error_code( 'mutation_context_missing', $err6 );

	$err7 = Full_Elementor_MCP_Safe_Writes::delete_post_meta( 10, '_some_key' );
	assert_error_code( 'mutation_context_missing', $err7 );

	$err8 = Full_Elementor_MCP_Safe_Writes::set_post_thumbnail( 10, 5 );
	assert_error_code( 'mutation_context_missing', $err8 );

	$err9 = Full_Elementor_MCP_Safe_Writes::delete_post_thumbnail( 10 );
	assert_error_code( 'mutation_context_missing', $err9 );

	$err10 = Full_Elementor_MCP_Safe_Writes::set_object_terms( 10, array( 1 ), 'category' );
	assert_error_code( 'mutation_context_missing', $err10 );

	$err11 = Full_Elementor_MCP_Safe_Writes::update_option( 'test_opt', 'val' );
	assert_error_code( 'mutation_context_missing', $err11 );

	$err12 = Full_Elementor_MCP_Safe_Writes::delete_option( 'test_opt' );
	assert_error_code( 'mutation_context_missing', $err12 );

	$err13 = Full_Elementor_MCP_Safe_Writes::media_handle_sideload( array( 'tmp_name' => 'x' ), 10 );
	assert_error_code( 'mutation_context_missing', $err13 );

	$err14 = Full_Elementor_MCP_Safe_Writes::assert_write_boundary( 'post:10' );
	assert_error_code( 'mutation_context_missing', $err14 );
} );

run_test( 'Safe_Writes: readonly ability attempting write through Safe_Writes fails closed', function () {
	$called = false;
	register_mock_ability( 'full-elementor-mcp/mock-readonly-writer', true, function ( $input ) use ( &$called ) {
		$called = true;
		return Full_Elementor_MCP_Safe_Writes::update_post( array( 'ID' => 10, 'post_title' => 'Sneaky Title' ) );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/mock-readonly-writer', array( 'post_id' => 10 ) );
	assert_true( $called );
	assert_error_code( 'readonly_context_write_blocked', $res );
	assert_false( Full_Elementor_MCP_Mutation_Context::has_active_context() );
} );

run_test( 'Safe_Writes: readonly ability attempting update_option through Safe_Writes fails closed', function () {
	register_mock_ability( 'full-elementor-mcp/mock-readonly-option-writer', true, function ( $input ) {
		return Full_Elementor_MCP_Safe_Writes::update_option( 'forbidden_opt', 'sneak' );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/mock-readonly-option-writer', array( 'post_id' => 10 ) );
	assert_error_code( 'readonly_context_write_blocked', $res );
} );

run_test( 'Safe_Writes: stale writer with lower fencing token is rejected at physical boundary', function () {
	global $wpdb;
	$lock1 = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:10', 'worker-1' );
	assert_true( ! is_wp_error( $lock1 ) );
	$fence1 = $lock1['fencing_token'];

	$ctx_token = Full_Elementor_MCP_Mutation_Context::enter( array(
		'ability'       => 'full-elementor-mcp/update-element',
		'resource_key'  => 'post:10',
		'object_id'     => 10,
		'owner_id'      => 'worker-1',
		'fencing_token' => $fence1,
	) );

	// Force-expire worker-1 lock in DB so worker-2 can acquire it and advance fencing token:
	$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( 'post:10' );
	$wpdb->query( "UPDATE {$table} SET expires_at = datetime('now', '-10 seconds') WHERE token_key = '{$lock_key}'" );

	$lock2 = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:10', 'worker-2' );
	assert_true( ! is_wp_error( $lock2 ) );
	assert_true( $lock2['fencing_token'] > $fence1 );

	// Worker 1 tries to write with stale fencing token:
	$write_res = Full_Elementor_MCP_Safe_Writes::update_post( array( 'ID' => 10, 'post_title' => 'Stale Write' ) );

	Full_Elementor_MCP_Mutation_Context::leave( $ctx_token );
	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:10', 'worker-2', $lock2['fencing_token'] );

	assert_error_code( 'stale_writer_conflict', $write_res, 'Safe_Writes must block stale worker at physical boundary' );
} );

run_test( 'Safe_Writes: write targeting mismatched resource key fails closed', function () {
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:10', 'worker-target-mismatch' );
	$ctx_token = Full_Elementor_MCP_Mutation_Context::enter( array(
		'ability'       => 'full-elementor-mcp/update-element',
		'resource_key'  => 'post:10',
		'object_id'     => 10,
		'owner_id'      => 'worker-target-mismatch',
		'fencing_token' => $lock['fencing_token'],
	) );

	// Attempting write targeting post:99 while lock is on post:10:
	$err = Full_Elementor_MCP_Safe_Writes::update_post( array( 'ID' => 99, 'post_title' => 'Mismatch' ) );

	Full_Elementor_MCP_Mutation_Context::leave( $ctx_token );
	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:10', 'worker-target-mismatch', $lock['fencing_token'] );

	assert_error_code( 'write_resource_mismatch', $err );
} );

run_test( 'Safe_Writes: non-create operation calling insert_post fails closed with write_prohibited_in_context', function () {
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:10', 'worker-non-create' );
	$ctx_token = Full_Elementor_MCP_Mutation_Context::enter( array(
		'ability'       => 'full-elementor-mcp/update-element',
		'resource_key'  => 'post:10',
		'object_id'     => 10,
		'owner_id'      => 'worker-non-create',
		'fencing_token' => $lock['fencing_token'],
	) );

	$err = Full_Elementor_MCP_Safe_Writes::insert_post( array( 'post_title' => 'Illegal Child Post' ) );

	Full_Elementor_MCP_Mutation_Context::leave( $ctx_token );
	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:10', 'worker-non-create', $lock['fencing_token'] );

	assert_error_code( 'invalid_create_context', $err );
} );

run_test( 'Safe_Writes: post writes during CREATE before durable insert_post fail closed', function () {
	$ctx_token = Full_Elementor_MCP_Mutation_Context::enter( array(
		'ability'           => 'full-elementor-mcp/create-page',
		'resource_key'      => 'create:create-page:test_hash',
		'object_id'         => 0,
		'owner_id'          => 'worker-create-premature',
		'fencing_token'     => 1,
		'created_object_id' => null,
	) );

	$err = Full_Elementor_MCP_Safe_Writes::update_post_meta( 2050, '_elementor_data', '[]' );

	Full_Elementor_MCP_Mutation_Context::leave( $ctx_token );

	assert_error_code( 'created_object_not_yet_bound', $err );
} );

// -----------------------------------------------------------------------------
// 17. Readonly / Mutation Registration Consistency
// -----------------------------------------------------------------------------

run_test( 'Consistency: readonly annotation with registered mutation strategy fails closed', function () {
	register_mock_ability( 'full-elementor-mcp/update-element', true, fn() => array( 'success' => true ) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array( 'post_id' => 10 ) );
	assert_error_code( 'readonly_mutation_conflict', $res );
} );

run_test( 'Consistency: mutating annotation without registered mutation strategy fails closed', function () {
	register_mock_ability( 'full-elementor-mcp/unknown-mutator-ability', false, fn() => array( 'success' => true ) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/unknown-mutator-ability', array( 'post_id' => 10 ) );
	assert_error_code( 'mutation_strategy_missing', $res );
} );

// -----------------------------------------------------------------------------
// 18. Confirmation Deferred CAS & Argument Preserving Hash
// -----------------------------------------------------------------------------

run_test( 'Confirmation: token is NOT consumed if lock acquisition fails', function () {
	global $wpdb;
	register_mock_ability( 'full-elementor-mcp/delete-page', false, fn() => array( 'deleted' => true ) );

	// Pre-lock post 10 by external owner:
	$ext_lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:10', 'external-owner' );

	// Issue challenge:
	$challenge = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
		'full-elementor-mcp/delete-page',
		array( 'post_id' => 10, 'force' => true ),
		1,
		null,
		'post:10'
	);
	assert_false( is_wp_error( $challenge ) );
	$token = $challenge['confirmation_token'];

	// Attempt execution while lock is held by external-owner:
	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', array(
		'post_id'            => 10,
		'force'              => true,
		'confirmation_token' => $token,
	) );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:10', 'external-owner', $ext_lock['fencing_token'] );

	assert_error_code( 'resource_locked', $res );

	// Token MUST still be unconsumed (used = 0) so user can retry!
	$token_key = 'conf:' . hash( 'sha256', $token );
	$token_row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}elementor_mcp_tokens WHERE token_type = 'confirmation' AND token_key = %s",
		$token_key
	), ARRAY_A );
	assert_equals( 0, (int) $token_row['used'], 'Confirmation token must not be consumed when lock acquisition fails' );
} );

run_test( 'Confirmation: canonical hash preserves nested mutation arguments while stripping top-level controls', function () {
	$args1 = array(
		'post_id'            => 10,
		'confirmation_token' => 'tok_abc',
		'dry_run'            => true,
		'idempotency_key'    => 'key_xyz',
		'settings'           => array( 'title' => 'Same', 'dry_run' => 'nested_value_1' ),
	);
	$args2 = array(
		'post_id'            => 10,
		'confirmation_token' => 'tok_DIFFERENT',
		'dry_run'            => false,
		'idempotency_key'    => 'key_DIFFERENT',
		'settings'           => array( 'title' => 'Same', 'dry_run' => 'nested_value_1' ),
	);
	$args3 = array(
		'post_id'            => 10,
		'confirmation_token' => 'tok_abc',
		'settings'           => array( 'title' => 'Same', 'dry_run' => 'nested_value_2_CHANGED' ),
	);

	$hash1 = Full_Elementor_MCP_Confirmation_Manager::canonical_args_hash( $args1 );
	$hash2 = Full_Elementor_MCP_Confirmation_Manager::canonical_args_hash( $args2 );
	$hash3 = Full_Elementor_MCP_Confirmation_Manager::canonical_args_hash( $args3 );

	assert_equals( $hash1, $hash2, 'Top-level confirmation_token, dry_run, idempotency_key must be stripped from hash' );
	assert_true( $hash1 !== $hash3, 'Nested settings changes MUST produce a different hash' );
} );

run_test( 'Confirmation: exact credential UUID equality enforced including null', function () {
	// 1. Token issued for 'cred-A':
	$c1 = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
		'full-elementor-mcp/delete-page',
		array( 'post_id' => 10, 'force' => true ),
		1,
		'cred-A',
		'post:10'
	);
	$tok1 = $c1['confirmation_token'];

	// Validate with 'cred-B' -> fail:
	$err_b = Full_Elementor_MCP_Confirmation_Manager::validate( $tok1, 'full-elementor-mcp/delete-page', array( 'post_id' => 10, 'force' => true ), 1, 'cred-B', 'post:10' );
	assert_error_code( 'confirmation_credential_mismatch', $err_b );

	// Validate with null -> fail:
	$err_null = Full_Elementor_MCP_Confirmation_Manager::validate( $tok1, 'full-elementor-mcp/delete-page', array( 'post_id' => 10, 'force' => true ), 1, null, 'post:10' );
	assert_error_code( 'confirmation_credential_mismatch', $err_null );

	// 2. Token issued for null:
	$c2 = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
		'full-elementor-mcp/delete-page',
		array( 'post_id' => 10, 'force' => true ),
		1,
		null,
		'post:10'
	);
	$tok2 = $c2['confirmation_token'];

	// Validate with 'cred-A' -> fail:
	$err_a2 = Full_Elementor_MCP_Confirmation_Manager::validate( $tok2, 'full-elementor-mcp/delete-page', array( 'post_id' => 10, 'force' => true ), 1, 'cred-A', 'post:10' );
	assert_error_code( 'confirmation_credential_mismatch', $err_a2 );

	// Validate with null -> success:
	$ok = Full_Elementor_MCP_Confirmation_Manager::validate( $tok2, 'full-elementor-mcp/delete-page', array( 'post_id' => 10, 'force' => true ), 1, null, 'post:10' );
	assert_true( ! is_wp_error( $ok ) );
} );

// -----------------------------------------------------------------------------
// 19. Idempotency States (failed_safe vs recovery_required) & Stale Worker Defense
// -----------------------------------------------------------------------------

run_test( 'Idempotency: failure before writes marks state failed_safe; failure after writes marks recovery_required', function () {
	global $wpdb;

	// Case A: throws before any write started:
	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) {
		throw new \RuntimeException( 'Crash before writes' );
	} );

	$key_a = 'idemp_fail_before_' . bin2hex( random_bytes( 6 ) );
	$res_a = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'         => 10,
		'element_id'      => 'el_1',
		'settings'        => array( 'title' => 'A' ),
		'idempotency_key' => $key_a,
	) );
	assert_error_code( 'mutation_exception', $res_a );

	$token_key_a = Full_Elementor_MCP_Lock_Manager::get_idempotency_token_key( $key_a, 'full-elementor-mcp/update-element', 1, null );
	$row_a = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}elementor_mcp_tokens WHERE token_key = %s",
		$token_key_a
	), ARRAY_A );
	assert_true( empty( $row_a ), 'Failure before writes must clean up idempotency claim for safe immediate retry' );

	// Case B: writes started on non-rollbackable mutation, THEN throws:
	register_mock_ability( 'full-elementor-mcp/set-page-meta', false, function ( $input ) {
		Full_Elementor_MCP_Safe_Writes::update_post_meta( 10, '_some_meta', 'persisted' );
		throw new \RuntimeException( 'Crash AFTER non-rollbackable write occurred' );
	} );

	$key_b = 'idemp_fail_after_' . bin2hex( random_bytes( 6 ) );
	$res_b = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/set-page-meta', array(
		'post_id'         => 10,
		'meta_key'        => '_some_meta',
		'meta_value'      => 'persisted',
		'idempotency_key' => $key_b,
	) );
	assert_error_code( 'mutation_exception', $res_b );

	$token_key_b = Full_Elementor_MCP_Lock_Manager::get_idempotency_token_key( $key_b, 'full-elementor-mcp/set-page-meta', 1, null );
	$row_b = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}elementor_mcp_tokens WHERE token_key = %s",
		$token_key_b
	), ARRAY_A );
	$payload_b = json_decode( (string) $row_b['payload'], true );
	assert_equals( 'recovery_required', $payload_b['status'], 'Failure after non-rollbackable write started must be recovery_required' );

	// Retrying on recovery_required returns idempotency_recovery_required error:
	$res_retry = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/set-page-meta', array(
		'post_id'         => 10,
		'meta_key'        => '_some_meta',
		'meta_value'      => 'persisted',
		'idempotency_key' => $key_b,
	) );
	assert_error_code( 'idempotency_recovery_required', $res_retry );
} );

run_test( 'Idempotency: completed high-risk replay returns cached result without requiring confirmation challenge', function () {
	register_mock_ability( 'full-elementor-mcp/delete-page', false, fn() => array( 'deleted' => true ) );

	$idemp_key = 'idemp_perm_del_' . bin2hex( random_bytes( 6 ) );
	$args = array(
		'post_id'         => 10,
		'force'           => true,
		'idempotency_key' => $idemp_key,
	);

	// 1. Initial attempt receives confirmation challenge:
	$c_res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', $args );
	assert_error_code( 'confirmation_required', $c_res );
	$token = $c_res->get_error_data()['confirmation_token'];

	// 2. Supply confirmation token -> executes and completes:
	$args['confirmation_token'] = $token;
	$res_exec = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', $args );
	assert_true( $res_exec['deleted'] );

	// 3. Third call with same idempotency key and NO confirmation token:
	unset( $args['confirmation_token'] );
	$res_replay = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', $args );
	assert_true( $res_replay['deleted'], 'Completed replay must return cached result without prompting confirmation' );
} );

run_test( 'Idempotency: stale worker cannot complete another owner claim', function () {
	$key = 'stale_worker_' . bin2hex( random_bytes( 6 ) );
	$claim = Full_Elementor_MCP_Idempotency_Manager::claim( $key, 'full-elementor-mcp/update-element', 1, null, array( 'post_id' => 10 ), 'worker-A' );
	assert_equals( 'claimed', $claim['status'] );

	// Worker B attempts to complete Worker A claim:
	$res = Full_Elementor_MCP_Idempotency_Manager::complete( $claim['token_key'], 'worker-B', array( 'done' => true ) );
	assert_false( $res, 'Worker B cannot complete Worker A claim' );
} );

// -----------------------------------------------------------------------------
// 20. Post-Mutation Fail-Closed Tree Check & Rollback Trigger
// -----------------------------------------------------------------------------

run_test( 'Post-Mutation: malformed tree persisted during mutation triggers rollback and returns post_mutation_validation_failed', function () {
	// Set up valid initial page:
	$initial_tree = array(
		array(
			'id'       => 'sec111',
			'elType'   => 'section',
			'elements' => array(
				array(
					'id'         => 'col111',
					'elType'     => 'column',
					'elements'   => array(),
					'isInner'    => false,
					'settings'   => array(),
				),
			),
			'isInner'  => false,
			'settings' => array(),
		),
	);
	update_post_meta( 10, '_elementor_data', wp_json_encode( $initial_tree ) );

	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) {
		// Callback persists a malformed tree (missing elType and id):
		$corrupted_tree = array( array( 'bad_node' => true ) );
		Full_Elementor_MCP_Safe_Writes::update_post_meta( 10, '_elementor_data', wp_json_encode( $corrupted_tree ) );
		return array( 'success' => true );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'sec111',
		'settings'   => array( 'title' => 'Corrupting' ),
	) );

	assert_error_code( 'post_mutation_validation_failed', $res );

	// Verify rollback restored the original valid tree:
	$restored = json_decode( get_post_meta( 10, '_elementor_data', true ), true );
	assert_equals( 'sec111', $restored[0]['id'], 'Rollback must have restored initial valid tree' );
} );

// -----------------------------------------------------------------------------
// 21. Strict Boolean `force` Semantics
// -----------------------------------------------------------------------------

run_test( 'Destructive: delete-page with force => "true" (string) is NOT irreversible permanent deletion', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/delete-page',
		array( 'post_id' => 10, 'force' => 'true' )
	);
	assert_false( $prof['irreversible'] );
	assert_true( 'permanent_deletion' !== $prof['security_category'] );
} );

run_test( 'Destructive: delete-page with force => true (boolean) IS irreversible permanent deletion', function () {
	$prof = Full_Elementor_MCP_Security_Strategies::get_security_profile(
		'full-elementor-mcp/delete-page',
		array( 'post_id' => 10, 'force' => true )
	);
	assert_true( $prof['irreversible'] );
	assert_equals( 'permanent_deletion', $prof['security_category'] );
} );

// -----------------------------------------------------------------------------
// 22. Missing Safety Dependencies Fail Closed
// -----------------------------------------------------------------------------

run_test( 'Dependencies: all 11 required safety classes are present and enforced', function () {
	assert_true( class_exists( 'Full_Elementor_MCP_Safe_Writes' ) );
	assert_true( class_exists( 'Full_Elementor_MCP_Database_Installer' ) );
	assert_true( class_exists( 'Full_Elementor_MCP_Lock_Manager' ) );
	assert_true( class_exists( 'Full_Elementor_MCP_Journal' ) );
	assert_true( class_exists( 'Full_Elementor_MCP_Mutation_Registry' ) );
	assert_true( class_exists( 'Full_Elementor_MCP_Security_Strategies' ) );
	assert_true( class_exists( 'Full_Elementor_MCP_Tree_Validator' ) );
	assert_true( class_exists( 'Full_Elementor_MCP_Confirmation_Manager' ) );
	assert_true( class_exists( 'Full_Elementor_MCP_Idempotency_Manager' ) );
	assert_true( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) );
	assert_true( class_exists( 'Full_Elementor_MCP_Security_Guard' ) );
} );

// -----------------------------------------------------------------------------
// 23. Final Phase 4 Correctness Gate & Regression Tests
// -----------------------------------------------------------------------------

run_test( 'Import Template: real execute_import_template operates on existing post under post:<id> and rollback restores exact tree', function () {
	global $wpdb;
	$post_id = 123;
	$tree_a = array(
		array(
			'id'       => 'sec_a1',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'col_a1',
					'elType'     => 'column',
					'settings'   => array(),
					'elements'   => array(
						array(
							'id'         => 'wid_a1',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => 'Original Heading' ),
							'elements'   => array(),
						),
					),
				),
			),
		),
	);
	update_post_meta( $post_id, '_elementor_data', wp_json_encode( $tree_a ) );
	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

	$page_abilities = new Full_Elementor_MCP_Page_Abilities( new Full_Elementor_MCP_Data(), new Full_Elementor_MCP_Element_Factory() );
	$page_abilities->register();

	$template_to_import = array(
		array(
			'id'       => 'imp_sec_1',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'imp_col_1',
					'elType'     => 'column',
					'settings'   => array(),
					'elements'   => array(
						array(
							'id'         => 'imp_wid_1',
							'elType'     => 'widget',
							'widgetType' => 'text-editor',
							'settings'   => array( 'editor' => '<p>Imported Content</p>' ),
							'elements'   => array(),
						),
					),
				),
			),
		),
	);

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/import-template', array(
		'post_id'       => $post_id,
		'template_json' => $template_to_import,
	) );

	assert_false( is_wp_error( $res ), 'import-template should execute successfully: ' . ( is_wp_error( $res ) ? $res->get_error_message() : '' ) );
	assert_true( ! empty( $res['success'] ), 'Success flag must be true' );
	assert_equals( 3, $res['elements_count'], 'Imported elements count should be 3 (section + column + widget)' );

	// Verify journal state:
	$journal_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_equals( 1, count( $journal_rows ), 'Journal entry must exist' );
	$j_row = $journal_rows[0];
	assert_equals( 'post:123', $j_row['resource_key'], 'Journal canonical resource must be post:123' );
	assert_equals( 123, (int) $j_row['object_id'], 'Journal object ID must be 123' );
	assert_equals( 'committed', $j_row['status'], 'Journal status must be committed' );

	// Journal BEFORE must contain Tree A:
	$before_decoded = json_decode( $j_row['before_state'], true );
	assert_equals( 'sec_a1', $before_decoded[0]['id'], 'Journal BEFORE must be tree A' );

	// Persisted tree on post 123 must now have 2 sections:
	$persisted = json_decode( get_post_meta( $post_id, '_elementor_data', true ), true );
	assert_equals( 2, count( $persisted ), 'Persisted tree must have 2 sections' );

	// Now verify rollback restores exact Tree A:
	$owner_id = 'rollback-worker-1';
	$lock     = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:123', $owner_id, 60 );
	assert_true( ! empty( $lock['fencing_token'] ), 'Must acquire lock for rollback' );
	$rb_res = Full_Elementor_MCP_Journal::rollback( (int) $j_row['id'], $owner_id, (int) $lock['fencing_token'] );
	assert_false( is_wp_error( $rb_res ), 'Rollback must succeed: ' . ( is_wp_error( $rb_res ) ? $rb_res->get_error_message() : '' ) );

	$restored = json_decode( get_post_meta( $post_id, '_elementor_data', true ), true );
	assert_equals( 1, count( $restored ), 'Restored tree must have 1 section' );
	assert_equals( 'sec_a1', $restored[0]['id'], 'Restored tree must have original section id' );
	assert_equals( 'wid_a1', $restored[0]['elements'][0]['elements'][0]['id'], 'Restored widget must match exact original tree A' );
	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:123', $owner_id, (int) $lock['fencing_token'] );
} );

run_test( 'Idempotency CAS: only one worker succeeds in stale takeover and callback runs once', function () {
	global $wpdb;
	$token_key = 'idem_cas_stale_' . wp_generate_uuid4();
	$owner_a   = 'worker_A';
	$claim_a   = Full_Elementor_MCP_Idempotency_Manager::claim( $token_key, 'full-elementor-mcp/update-element', 1, null, array(), $owner_a );
	assert_equals( 'claimed', $claim_a['status'] );

	// Expire the lease and set payload status to failed_safe:
	$past    = gmdate( 'Y-m-d H:i:s', time() - 3600 );
	$table   = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$row     = $wpdb->get_row( $wpdb->prepare( "SELECT payload FROM {$table} WHERE token_key = %s", $claim_a['token_key'] ), ARRAY_A );
	$payload = json_decode( (string) $row['payload'], true );
	$payload['status'] = 'failed_safe';
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$table} SET payload = %s, expires_at = %s WHERE token_key = %s",
		wp_json_encode( $payload ),
		$past,
		$claim_a['token_key']
	) );

	// Worker B attempts takeover and CAS succeeds:
	$owner_b = 'worker_B';
	$claim_b = Full_Elementor_MCP_Idempotency_Manager::claim( $token_key, 'full-elementor-mcp/update-element', 1, null, array(), $owner_b );
	assert_false( is_wp_error( $claim_b ), 'Worker B should succeed in CAS takeover' );
	assert_equals( 'claimed', $claim_b['status'], 'Worker B should succeed in CAS takeover' );

	// Worker C attempts takeover:
	$owner_c = 'worker_C';
	$claim_c = Full_Elementor_MCP_Idempotency_Manager::claim( $token_key, 'full-elementor-mcp/update-element', 1, null, array(), $owner_c );
	assert_true( is_wp_error( $claim_c ), 'Worker C MUST NOT receive claimed' );
	assert_equals( 'idempotency_in_progress', $claim_c->get_error_code(), 'Worker C must receive in_progress after losing CAS' );
} );

run_test( 'Idempotency: attach_journal_id has strict write-once owner-bound CAS semantics', function () {
	$token_key = 'idem_attach_' . wp_generate_uuid4();
	$owner_id  = 'owner_1';
	$claim     = Full_Elementor_MCP_Idempotency_Manager::claim( $token_key, 'full-elementor-mcp/update-element', 1, null, array(), $owner_id );
	assert_equals( 'claimed', $claim['status'] );
	$db_key = $claim['token_key'];

	// 0 -> 17 succeeds
	$res1 = Full_Elementor_MCP_Idempotency_Manager::attach_journal_id( $db_key, $owner_id, 17 );
	assert_true( true === $res1, 'Initial journal attachment 0 -> 17 must succeed' );

	// 17 -> 17 succeeds idempotently
	$res2 = Full_Elementor_MCP_Idempotency_Manager::attach_journal_id( $db_key, $owner_id, 17 );
	assert_true( true === $res2, 'Same journal attachment 17 -> 17 must succeed idempotently' );

	// 17 -> 18 fails
	$res3 = Full_Elementor_MCP_Idempotency_Manager::attach_journal_id( $db_key, $owner_id, 18 );
	assert_error_code( 'idempotency_journal_conflict', $res3, 'Attachment 17 -> 18 must be rejected as conflict' );

	// wrong owner fails
	$res4 = Full_Elementor_MCP_Idempotency_Manager::attach_journal_id( $db_key, 'wrong_owner', 17 );
	assert_error_code( 'idempotency_owner_mismatch', $res4, 'Wrong owner must be rejected' );
} );

run_test( 'Idempotency: journal attachment failure prevents mutation callback execution', function () {
	$token_key = 'idem_attach_fail_' . wp_generate_uuid4();
	$owner_id  = 'owner_prebound';
	$claim     = Full_Elementor_MCP_Idempotency_Manager::claim( $token_key, 'full-elementor-mcp/update-element', 1, null, array(), $owner_id );
	Full_Elementor_MCP_Idempotency_Manager::attach_journal_id( $claim['token_key'], $owner_id, 999 );

	$callback_executed = 0;
	register_mock_ability( 'full-elementor-mcp/update-element', false, function () use ( &$callback_executed ) {
		$callback_executed++;
		return array( 'success' => true );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'          => 10,
		'element_id'       => 'sec1',
		'settings'         => array(),
		'idempotency_key'  => $token_key,
	) );

	assert_true( is_wp_error( $res ), 'Middleware must return error on journal attach failure' );
	assert_equals( 0, $callback_executed, 'Callback must never execute if journal attachment fails' );
} );

run_test( 'Media CREATE: journal binding failure becomes recovery_required and prevents duplicate on retry', function () {
	global $wpdb;
	$token_key        = 'idem_media_fail_' . wp_generate_uuid4();
	$created_attempts = 0;

	if ( ! function_exists( 'media_handle_sideload' ) ) {
		function media_handle_sideload( $file, $post_id, $desc = null, $data = array() ) {
			return 777;
		}
	}

	register_mock_ability( 'full-elementor-mcp/sideload-image', false, function ( $input ) use ( &$created_attempts, $wpdb ) {
		$created_attempts++;
		$ctx = Full_Elementor_MCP_Mutation_Context::current();
		// Delete the journal entry so record_created_object_id fails with journal_not_found:
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}elementor_mcp_journal WHERE id = %d", (int) $ctx['journal_id'] ) );

		$file   = array( 'name' => 'test.jpg', 'tmp_name' => '/tmp/test.jpg' );
		$att_id = Full_Elementor_MCP_Safe_Writes::media_handle_sideload( $file, 0 );
		if ( is_wp_error( $att_id ) ) {
			return $att_id;
		}
		return array( 'attachment_id' => $att_id );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/sideload-image', array(
		'url'             => 'https://example.com/test.jpg',
		'idempotency_key' => $token_key,
	) );

	assert_error_code( 'created_media_journal_binding_failed', $res, 'Must fail with created_media_journal_binding_failed' );
	assert_equals( 1, $created_attempts, 'First attempt ran' );

	// Verify idempotency record is marked recovery_required:
	$idem_res = Full_Elementor_MCP_Idempotency_Manager::claim( $token_key, 'full-elementor-mcp/sideload-image', 1, null, array( 'url' => 'https://example.com/test.jpg' ), 'retry_worker' );
	assert_true( is_wp_error( $idem_res ), 'Idempotency claim must return WP_Error for recovery_required' );
	assert_equals( 'idempotency_recovery_required', $idem_res->get_error_code(), 'Idempotency must be recovery_required' );

	// Execute again with same idempotency key:
	$retry_res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/sideload-image', array(
		'url'             => 'https://example.com/test.jpg',
		'idempotency_key' => $token_key,
	) );

	assert_error_code( 'idempotency_recovery_required', $retry_res, 'Retry must be blocked by idempotency' );
	assert_equals( 1, $created_attempts, 'Second attachment MUST NOT have been created' );
} );

run_test( 'CREATE verification: durable created ID vs callback result mismatch is rejected', function () {
	register_mock_ability( 'full-elementor-mcp/create-page', false, function ( $input ) {
		$post_id = Full_Elementor_MCP_Safe_Writes::insert_post( array(
			'post_title' => 'Created Page',
			'post_type'  => 'page',
		) );
		return array(
			'post_id' => $post_id + 1,
			'success' => true,
		);
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title' => 'Mismatch Test',
	) );

	assert_error_code( 'created_object_result_mismatch', $res, 'Must reject created ID mismatch: ' . ( is_wp_error( $res ) ? $res->get_error_message() . ' Data: ' . json_encode( $res->get_error_data() ) : '' ) );
} );

run_test( 'CREATE verification: explicit per-strategy create result fields reject arbitrary id fallback', function () {
	$id1 = Full_Elementor_MCP_Mutation_Registry::resolve_created_object_id( 'full-elementor-mcp/create-page', array( 'id' => 123 ) );
	assert_equals( 0, $id1, 'Generic id must not resolve for create-page' );
	$id1_ok = Full_Elementor_MCP_Mutation_Registry::resolve_created_object_id( 'full-elementor-mcp/create-page', array( 'post_id' => 123 ) );
	assert_equals( 123, $id1_ok, 'post_id must resolve for create-page' );

	$id2 = Full_Elementor_MCP_Mutation_Registry::resolve_created_object_id( 'full-elementor-mcp/save-as-template', array( 'id' => 456 ) );
	assert_equals( 0, $id2, 'Generic id must not resolve for save-as-template' );
	$id2_ok = Full_Elementor_MCP_Mutation_Registry::resolve_created_object_id( 'full-elementor-mcp/save-as-template', array( 'template_id' => 456 ) );
	assert_equals( 456, $id2_ok, 'template_id must resolve for save-as-template' );

	$id3 = Full_Elementor_MCP_Mutation_Registry::resolve_created_object_id( 'full-elementor-mcp/add-code-snippet', array( 'id' => 789 ) );
	assert_equals( 0, $id3, 'Generic id must not resolve for add-code-snippet' );
	$id3_ok = Full_Elementor_MCP_Mutation_Registry::resolve_created_object_id( 'full-elementor-mcp/add-code-snippet', array( 'snippet_id' => 789 ) );
	assert_equals( 789, $id3_ok, 'snippet_id must resolve for add-code-snippet' );

	$id4 = Full_Elementor_MCP_Mutation_Registry::resolve_created_object_id( 'full-elementor-mcp/sideload-image', array( 'id' => 999 ) );
	assert_equals( 0, $id4, 'Generic id must not resolve for sideload-image' );
	$id4_ok = Full_Elementor_MCP_Mutation_Registry::resolve_created_object_id( 'full-elementor-mcp/sideload-image', array( 'attachment_id' => 999 ) );
	assert_equals( 999, $id4_ok, 'attachment_id must resolve for sideload-image' );
} );

run_test( 'Tree verification: malformed persistent JSON {bad json triggers failure and rollback', function () {
	$initial_tree = array(
		array(
			'id'       => 'sec_good_1',
			'elType'   => 'section',
			'elements' => array(),
		),
	);
	update_post_meta( 10, '_elementor_data', wp_json_encode( $initial_tree ) );

	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) {
		Full_Elementor_MCP_Safe_Writes::update_post_meta( 10, '_elementor_data', '{bad json' );
		return array( 'success' => true );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'sec_good_1',
		'settings'   => array(),
	) );

	assert_error_code( 'post_mutation_state_unverifiable', $res, 'Must fail closed on malformed JSON' );

	$restored = json_decode( get_post_meta( 10, '_elementor_data', true ), true );
	assert_equals( 'sec_good_1', $restored[0]['id'], 'Rollback must have restored initial tree' );
} );

run_test( 'Tree verification: scalar persistent JSON triggers failure and rollback', function () {
	$initial_tree = array(
		array(
			'id'       => 'sec_scalar_1',
			'elType'   => 'section',
			'elements' => array(),
		),
	);
	update_post_meta( 10, '_elementor_data', wp_json_encode( $initial_tree ) );

	register_mock_ability( 'full-elementor-mcp/update-element', false, function ( $input ) {
		Full_Elementor_MCP_Safe_Writes::update_post_meta( 10, '_elementor_data', '"a scalar string value"' );
		return array( 'success' => true );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-element', array(
		'post_id'    => 10,
		'element_id' => 'sec_scalar_1',
		'settings'   => array(),
	) );

	assert_error_code( 'post_mutation_state_unverifiable', $res, 'Must fail closed on scalar JSON' );

	$restored = json_decode( get_post_meta( 10, '_elementor_data', true ), true );
	assert_equals( 'sec_scalar_1', $restored[0]['id'], 'Rollback must have restored initial tree' );
} );

run_test( 'Shutdown: unexpected shutdown preserves pending journal and later recover_pending sees it', function () {
	global $wpdb;
	$journal_id = Full_Elementor_MCP_Journal::begin( array(
		'ability'       => 'full-elementor-mcp/update-element',
		'fencing_token' => 1,
		'resource_key'  => 'post:10',
		'object_id'     => 10,
		'before_state'  => array(),
		'owner_id'      => 'owner-shut-1',
		'user_id'       => 1,
	) );
	assert_true( $journal_id > 0 );

	$token_key = 'idem_shutdown_' . wp_generate_uuid4();
	$claim     = Full_Elementor_MCP_Idempotency_Manager::claim( $token_key, 'full-elementor-mcp/update-element', 1, null, array(), 'owner-shut-1' );
	Full_Elementor_MCP_Idempotency_Manager::attach_journal_id( $claim['token_key'], 'owner-shut-1', $journal_id );

	$ctx_token = Full_Elementor_MCP_Mutation_Context::enter( array(
		'resource_key'    => 'post:10',
		'owner_id'        => 'owner-shut-1',
		'fencing_token'   => 1,
		'journal_id'      => $journal_id,
		'ability'         => 'full-elementor-mcp/update-element',
		'idempotency_key' => $token_key,
		'user_id'         => 1,
	) );
	Full_Elementor_MCP_Mutation_Context::mark_write_started( true );

	Full_Elementor_MCP_Mutation_Middleware::handle_shutdown();

	// Invariant: Journal MUST REMAIN 'pending' so next-request recovery sees it:
	$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
	assert_equals( 'pending', $entry['status'], 'Journal must remain pending after uncertain shutdown' );

	// Invariant: Idempotency is recovery_required:
	$idem = Full_Elementor_MCP_Idempotency_Manager::claim( $token_key, 'full-elementor-mcp/update-element', 1, null, array(), 'new_worker' );
	assert_true( is_wp_error( $idem ), 'Idempotency claim must return WP_Error for recovery_required' );
	assert_equals( 'idempotency_recovery_required', $idem->get_error_code() );

	// Age the journal entry beyond lease TTL + grace so recover_pending identifies it as abandoned:
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}elementor_mcp_journal SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 300 SECOND) WHERE id = %d",
		$journal_id
	) );

	// Next-request recover_pending(10) sees the pending journal:
	$recovered = Full_Elementor_MCP_Journal::recover_pending( 10 );
	assert_true( count( $recovered ) >= 1, 'recover_pending must see and process the pending journal' );
} );

run_test( 'Confirmation token: context initialization failure does not consume confirmation token', function () {
	register_mock_ability( 'full-elementor-mcp/delete-page-content', false, fn() => array( 'success' => true ) );

	$challenge = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
		'full-elementor-mcp/delete-page-content',
		array( 'post_id' => 10 ),
		1,
		null,
		'post:10'
	);
	assert_false( is_wp_error( $challenge ) );
	$token = $challenge['confirmation_token'];

	$dummy_token = Full_Elementor_MCP_Mutation_Context::enter( array(
		'resource_key'  => 'post:999',
		'owner_id'      => 'nested-worker',
		'fencing_token' => 1,
		'journal_id'    => 1,
	) );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page-content', array(
		'post_id'            => 10,
		'confirmation_token' => $token,
	) );

	assert_error_code( 'nested_mutation_not_supported', $res );

	Full_Elementor_MCP_Mutation_Context::leave( $dummy_token );

	$valid = Full_Elementor_MCP_Confirmation_Manager::validate( $token, 'full-elementor-mcp/delete-page-content', array( 'post_id' => 10 ), 1, null, 'post:10' );
	assert_true( true === $valid, 'Confirmation token must remain unused and valid' );
} );

run_test( 'Confirmation token: stale final fence preflight does not consume confirmation token', function () {
	global $wpdb;
	$test_ability = 'full-elementor-mcp/delete-page-content';
	register_mock_ability( $test_ability, false, fn() => array( 'success' => true ) );

	$challenge = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
		$test_ability,
		array( 'post_id' => 10 ),
		1,
		null,
		'post:10'
	);
	assert_false( is_wp_error( $challenge ) );
	$token = $challenge['confirmation_token'];

	$orig_strategy     = Full_Elementor_MCP_Mutation_Registry::get( $test_ability );
	$modified_strategy = $orig_strategy;
	$modified_strategy['capture_before'] = function ( $post_id, $input ) {
		global $wpdb;
		$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( 'post:' . $post_id );
		$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );
		return array();
	};
	Full_Elementor_MCP_Mutation_Registry::register( $modified_strategy );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( $test_ability, array(
		'post_id'            => 10,
		'confirmation_token' => $token,
	) );

	Full_Elementor_MCP_Mutation_Registry::register( $orig_strategy );

	assert_error_code( 'stale_writer_conflict', $res, 'Preflight must fail with stale_writer_conflict' );

	$valid = Full_Elementor_MCP_Confirmation_Manager::validate( $token, $test_ability, array( 'post_id' => 10 ), 1, null, 'post:10' );
	assert_true( true === $valid, 'Confirmation token must remain unused after stale fencing preflight failure' );
} );

run_test( 'Dry-run: delete-page reports accurate argument-level rollback_supported', function () {
	register_mock_ability( 'full-elementor-mcp/delete-page', false, fn() => array( 'deleted' => true ) );

	$res_perm = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', array(
		'post_id' => 10,
		'force'   => true,
		'dry_run' => true,
	) );
	assert_false( is_wp_error( $res_perm ) );
	assert_false( $res_perm['rollback_supported'], 'Permanent delete dry-run must report rollback_supported = false' );

	$res_trash = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/delete-page', array(
		'post_id' => 10,
		'force'   => false,
		'dry_run' => true,
	) );
	assert_false( is_wp_error( $res_trash ) );
	assert_true( $res_trash['rollback_supported'], 'Trashing dry-run must report rollback_supported = true' );
} );

// Helper to ensure core ability instances are available
function get_test_abilities(): array {
	static $instances = null;
	if ( null === $instances ) {
		$data      = new Full_Elementor_MCP_Data();
		$factory   = new Full_Elementor_MCP_Element_Factory();
		$instances = array(
			'page'        => new Full_Elementor_MCP_Page_Abilities( $data, $factory ),
			'template'    => new Full_Elementor_MCP_Template_Abilities( $data, $factory ),
			'composite'   => new Full_Elementor_MCP_Composite_Abilities( $data, $factory ),
			'custom_code' => new Full_Elementor_MCP_Custom_Code_Abilities( $data, $factory ),
			'stock_image' => new Full_Elementor_MCP_Stock_Image_Abilities( $data, $factory ),
			'svg_icon'    => new Full_Elementor_MCP_Svg_Icon_Abilities( $data, $factory ),
			'global'      => new Full_Elementor_MCP_Global_Abilities( $data, $factory ),
		);
		foreach ( $instances as $inst ) {
			$inst->register();
		}
	}
	return $instances;
}

// -----------------------------------------------------------------------------
// 16. CREATE Identity Fail-Closed Invariants
// -----------------------------------------------------------------------------

run_test( 'CREATE identity: bound 123 + callback 123 results in success', function () {
	global $wpdb;
	register_mock_ability( 'full-elementor-mcp/create-page', false, function ( $input ) {
		$post_id = Full_Elementor_MCP_Safe_Writes::insert_post( array(
			'post_title' => 'Bound 123 Test',
			'post_type'  => 'page',
		) );
		return array( 'post_id' => $post_id, 'success' => true );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title' => 'Bound 123 Test',
	) );

	assert_false( is_wp_error( $res ) );
	assert_true( ( $res['post_id'] ?? 0 ) > 0 );
	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal WHERE ability = 'full-elementor-mcp/create-page' ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_equals( (int) $res['post_id'], (int) $row['created_object_id'] );
	assert_equals( 'committed', $row['status'] );
} );

run_test( 'CREATE identity: bound 123 + callback 124 results in created_object_result_mismatch', function () {
	register_mock_ability( 'full-elementor-mcp/create-page', false, function ( $input ) {
		$post_id = Full_Elementor_MCP_Safe_Writes::insert_post( array(
			'post_title' => 'Mismatch Test',
			'post_type'  => 'page',
		) );
		return array( 'post_id' => $post_id + 1, 'success' => true );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title' => 'Mismatch Test',
	) );

	assert_error_code( 'created_object_result_mismatch', $res );
} );

run_test( 'CREATE identity: bound 123 + callback missing ID results in created_object_result_missing', function () {
	register_mock_ability( 'full-elementor-mcp/create-page', false, function ( $input ) {
		$post_id = Full_Elementor_MCP_Safe_Writes::insert_post( array(
			'post_title' => 'Missing Callback ID Test',
			'post_type'  => 'page',
		) );
		return array( 'success' => true ); // Missing 'post_id'
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title' => 'Missing ID Test',
	) );

	assert_error_code( 'created_object_result_missing', $res );
} );

run_test( 'CREATE identity: callback 123 + no durable bound results in created_object_identity_not_durable', function () {
	register_mock_ability( 'full-elementor-mcp/create-page', false, function ( $input ) {
		// Does NOT call Safe_Writes::insert_post()
		return array( 'post_id' => 123, 'success' => true );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title' => 'Not Durable Test',
	) );

	assert_error_code( 'created_object_identity_not_durable', $res );
} );

run_test( 'CREATE identity: no bound + no callback ID results in created_object_id_unresolved', function () {
	register_mock_ability( 'full-elementor-mcp/create-page', false, function ( $input ) {
		// Neither binds nor returns ID
		return array( 'success' => true );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title' => 'Unresolved Test',
	) );

	assert_error_code( 'created_object_id_unresolved', $res );
} );

run_test( 'CREATE identity: middleware NEVER calls record_created_object_id after callback returns', function () {
	global $wpdb;
	$calls_during_callback = 0;

	register_mock_ability( 'full-elementor-mcp/create-page', false, function ( $input ) use ( &$calls_during_callback ) {
		$post_id = Full_Elementor_MCP_Safe_Writes::insert_post( array(
			'post_title' => 'No Late Binding Test',
			'post_type'  => 'page',
		) );
		$calls_during_callback++;
		return array( 'post_id' => $post_id );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title' => 'No Late Binding Test',
	) );

	assert_false( is_wp_error( $res ) );
	assert_equals( 1, $calls_during_callback, 'Physical create must bind during callback execution' );

	// Now verify that if callback returns an ID without durable binding, middleware rejects and NEVER attempts late recording
	register_mock_ability( 'full-elementor-mcp/create-page', false, function ( $input ) {
		return array( 'post_id' => 999 );
	} );

	$res_fail = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title' => 'Attempt Late Binding Test',
	) );

	assert_error_code( 'created_object_identity_not_durable', $res_fail );
	$latest_entry = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_true( empty( $latest_entry['created_object_id'] ), 'Middleware must NOT have written created_object_id 999 into journal' );
} );

// -----------------------------------------------------------------------------
// 17. Fencing Loss Propagation
// -----------------------------------------------------------------------------

run_test( 'Fencing loss: create-page loses fence before optional template meta write does not report success', function () {
	global $wpdb;
	get_test_abilities();

	$GLOBALS['wp_test_insert_post_hook'] = function ( $id, $postarr ) use ( $wpdb ) {
		$ctx = Full_Elementor_MCP_Mutation_Context::current();
		if ( $ctx ) {
			$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $ctx['resource_key'] );
			$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );
		}
	};

	$idemp_key = 'idem_create_page_fence_loss';
	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-page', array(
		'title'           => 'Fence Lost Page',
		'template'        => 'elementor_canvas',
		'idempotency_key' => $idemp_key,
	) );

	$GLOBALS['wp_test_insert_post_hook'] = null;

	assert_true( is_wp_error( $res ), 'Ability must fail when fencing is lost before template meta write' );
	assert_equals( 'stale_writer_conflict', $res->get_error_code() );

	// Assert journal and idempotency remain truthful
	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal WHERE ability = 'full-elementor-mcp/create-page' ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_true( 'committed' !== $row['status'], 'Journal status must not be committed' );
	$claim = Full_Elementor_MCP_Idempotency_Manager::claim( $idemp_key, 'full-elementor-mcp/create-page', 1, null, array( 'title' => 'Fence Lost Page', 'template' => 'elementor_canvas' ), 'worker_2' );
	assert_true( is_wp_error( $claim ) && 'idempotency_recovery_required' === $claim->get_error_code(), 'Idempotency must be recovery_required' );
} );

run_test( 'Fencing loss: build-page loses fence before page-settings write does not report success', function () {
	global $wpdb;
	Full_Elementor_MCP_Elementor_Features::set_mock_features( array( 'elementor' => true, 'containers' => true ) );
	get_test_abilities();

	$GLOBALS['wp_test_document_save_hook'] = function ( $post_id, $data ) use ( $wpdb ) {
		if ( isset( $data['elements'] ) ) {
			$ctx = Full_Elementor_MCP_Mutation_Context::current();
			if ( $ctx ) {
				$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $ctx['resource_key'] );
				$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );
			}
		}
	};

	$idemp_key = 'idem_build_page_fence_loss';
	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/build-page', array(
		'title'           => 'Fence Lost Build Page',
		'structure'       => array(
			array(
				'type' => 'container',
			),
		),
		'page_settings'   => array(
			'custom_css' => 'body { background: #000; }',
		),
		'idempotency_key' => $idemp_key,
	) );

	$GLOBALS['wp_test_document_save_hook'] = null;
	Full_Elementor_MCP_Elementor_Features::reset_mocks();

	assert_error_code( 'stale_writer_conflict', $res, 'build-page must fail when fence is lost before page-settings write' );

	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal WHERE ability = 'full-elementor-mcp/build-page' ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_true( 'committed' !== $row['status'], 'Journal status must not be committed' );
} );

run_test( 'Fencing loss: create-theme-template loses fence before initialization save does not report success', function () {
	global $wpdb;
	get_test_abilities();

	$GLOBALS['wp_test_set_terms_hook'] = function ( $object_id, $terms, $taxonomy ) use ( $wpdb ) {
		$ctx = Full_Elementor_MCP_Mutation_Context::current();
		if ( $ctx ) {
			$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $ctx['resource_key'] );
			$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );
		}
	};

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-theme-template', array(
		'title'         => 'Theme Template Header',
		'template_type' => 'header',
	) );

	$GLOBALS['wp_test_set_terms_hook'] = null;

	assert_error_code( 'stale_writer_conflict', $res, 'create-theme-template must fail when fence is lost before initialization save' );

	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal WHERE ability = 'full-elementor-mcp/create-theme-template' ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_true( 'committed' !== $row['status'], 'Journal status must not be committed' );
} );

run_test( 'Fencing loss: create-popup loses fence before initialization save does not report success', function () {
	global $wpdb;
	get_test_abilities();

	$GLOBALS['wp_test_set_terms_hook'] = function ( $object_id, $terms, $taxonomy ) use ( $wpdb ) {
		$ctx = Full_Elementor_MCP_Mutation_Context::current();
		if ( $ctx ) {
			$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $ctx['resource_key'] );
			$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );
		}
	};

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/create-popup', array(
		'title' => 'Lost Popup',
	) );

	$GLOBALS['wp_test_set_terms_hook'] = null;

	assert_error_code( 'stale_writer_conflict', $res, 'create-popup must fail when fence is lost before initialization save' );

	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal WHERE ability = 'full-elementor-mcp/create-popup' ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_true( 'committed' !== $row['status'], 'Journal status must not be committed' );
} );

run_test( 'Fencing loss: add-code-snippet loses fence during required metadata writes does not report success', function () {
	global $wpdb;
	get_test_abilities();

	$snippet_args = array(
		'title'    => 'Custom Snippet',
		'code'     => 'console.log("test");',
		'location' => 'head',
	);

	$res_key   = Full_Elementor_MCP_Mutation_Registry::build_create_resource_key( 'full-elementor-mcp/add-code-snippet', $snippet_args );
	$challenge = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
		'full-elementor-mcp/add-code-snippet',
		$snippet_args,
		1,
		null,
		$res_key
	);
	$token = $challenge['confirmation_token'];

	$GLOBALS['wp_test_insert_post_hook'] = function ( $id, $postarr ) use ( $wpdb ) {
		$ctx = Full_Elementor_MCP_Mutation_Context::current();
		if ( $ctx ) {
			$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $ctx['resource_key'] );
			$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );
		}
	};

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/add-code-snippet', array_merge(
		$snippet_args,
		array( 'confirmation_token' => $token )
	) );

	$GLOBALS['wp_test_insert_post_hook'] = null;

	assert_error_code( 'stale_writer_conflict', $res, 'add-code-snippet must fail when fence is lost during metadata writes' );

	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal WHERE ability = 'full-elementor-mcp/add-code-snippet' ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_true( 'committed' !== $row['status'], 'Journal status must not be committed' );
} );

run_test( 'Fencing loss: update-code-snippet loses fence during metadata update does not report success', function () {
	global $wpdb;
	get_test_abilities();

	$snippet_id = wp_insert_post( array(
		'post_title'  => 'Snippet To Update',
		'post_type'   => 'elementor_snippet',
		'post_status' => 'publish',
	) );

	$update_args = array(
		'snippet_id' => $snippet_id,
		'code'       => 'console.log("updated code");',
	);

	$challenge = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
		'full-elementor-mcp/update-code-snippet',
		$update_args,
		1,
		null,
		'post:' . $snippet_id
	);
	$token = $challenge['confirmation_token'];

	$GLOBALS['wp_test_get_post_hook'] = function ( $post_id ) use ( $wpdb, $snippet_id ) {
		if ( (int) $post_id === (int) $snippet_id ) {
			$ctx = Full_Elementor_MCP_Mutation_Context::current();
			if ( $ctx ) {
				$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $ctx['resource_key'] );
				$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );
			}
		}
	};

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/update-code-snippet', array_merge(
		$update_args,
		array( 'confirmation_token' => $token )
	) );

	$GLOBALS['wp_test_get_post_hook'] = null;

	assert_error_code( 'stale_writer_conflict', $res, 'update-code-snippet must fail when fence is lost during metadata update' );

	$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}elementor_mcp_journal WHERE ability = 'full-elementor-mcp/update-code-snippet' ORDER BY id DESC LIMIT 1", ARRAY_A );
	assert_true( 'committed' !== $row['status'], 'Journal status must not be committed' );
} );

run_test( 'Fencing loss: sideload-image loses fence after attachment creation but before title/alt metadata enters recovery behavior', function () {
	global $wpdb;
	$token_key = 'idem_sideload_fence_loss_' . bin2hex( random_bytes( 4 ) );

	register_mock_ability( 'full-elementor-mcp/sideload-image', false, function ( $input ) use ( $wpdb ) {
		$file   = array( 'name' => 'photo.jpg', 'tmp_name' => '/tmp/photo.jpg' );
		$att_id = Full_Elementor_MCP_Safe_Writes::media_handle_sideload( $file, 0 );
		if ( is_wp_error( $att_id ) ) {
			return $att_id;
		}

		// Simulate losing lease/fence right after physical attachment creation:
		$ctx      = Full_Elementor_MCP_Mutation_Context::current();
		$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $ctx['resource_key'] );
		$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );

		// Secondary write must fail with stale_writer_conflict:
		$upd = Full_Elementor_MCP_Safe_Writes::update_post( array(
			'ID'         => $att_id,
			'post_title' => 'Custom Title',
		) );
		if ( is_wp_error( $upd ) ) {
			return $upd;
		}

		return array( 'attachment_id' => $att_id );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/sideload-image', array(
		'url'             => 'https://example.com/photo.jpg',
		'title'           => 'Custom Title',
		'idempotency_key' => $token_key,
	) );

	assert_error_code( 'stale_writer_conflict', $res, 'Must fail when fence is lost before metadata write' );

	// Verify idempotency record is marked recovery_required:
	$claim = Full_Elementor_MCP_Idempotency_Manager::claim( $token_key, 'full-elementor-mcp/sideload-image', 1, null, array( 'url' => 'https://example.com/photo.jpg', 'title' => 'Custom Title' ), 'worker_retry' );
	assert_true( is_wp_error( $claim ) && 'idempotency_recovery_required' === $claim->get_error_code(), 'Idempotency must be recovery_required' );
} );

run_test( 'Fencing loss: upload-svg-icon loses fence before title update does not report success', function () {
	global $wpdb;
	register_mock_ability( 'full-elementor-mcp/upload-svg-icon', false, function ( $input ) use ( $wpdb ) {
		$file   = array( 'name' => 'icon.svg', 'tmp_name' => '/tmp/icon.svg' );
		$att_id = Full_Elementor_MCP_Safe_Writes::media_handle_sideload( $file, 0 );
		if ( is_wp_error( $att_id ) ) {
			return $att_id;
		}

		// Simulate losing fence before title update:
		$ctx      = Full_Elementor_MCP_Mutation_Context::current();
		$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $ctx['resource_key'] );
		$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );

		$title_res = Full_Elementor_MCP_Safe_Writes::update_post( array(
			'ID'         => $att_id,
			'post_title' => 'Brand Icon',
		) );
		if ( is_wp_error( $title_res ) ) {
			return $title_res;
		}

		return array( 'attachment_id' => $att_id, 'id' => $att_id );
	} );

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/upload-svg-icon', array(
		'svg'   => '<svg></svg>',
		'title' => 'Brand Icon',
	) );

	assert_error_code( 'stale_writer_conflict', $res, 'upload-svg-icon must fail when fence is lost before title update' );
} );

run_test( 'Fencing loss: set-featured-image Safe_Write returns stale_writer_conflict', function () {
	global $wpdb;
	get_test_abilities();

	$post_id = wp_insert_post( array(
		'post_title'  => 'Page for Thumbnail',
		'post_type'   => 'page',
		'post_status' => 'publish',
	) );
	$att_id = wp_insert_post( array(
		'post_title'  => 'Thumbnail Image',
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
	) );

	$GLOBALS['wp_test_get_post_hook'] = function ( $p_id ) use ( $wpdb, $post_id ) {
		if ( (int) $p_id === (int) $post_id ) {
			$ctx = Full_Elementor_MCP_Mutation_Context::current();
			if ( $ctx ) {
				$lock_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $ctx['resource_key'] );
				$wpdb->query( "UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = fencing_token + 50 WHERE token_key = '{$lock_key}'" );
			}
		}
	};

	$res = Full_Elementor_MCP_Mutation_Middleware::execute( 'full-elementor-mcp/set-featured-image', array(
		'post_id'       => $post_id,
		'attachment_id' => $att_id,
	) );

	$GLOBALS['wp_test_get_post_hook'] = null;

	assert_error_code( 'stale_writer_conflict', $res, 'Caller must receive stale_writer_conflict error' );
} );

echo "\n=======================================================\n";
echo " Test Results: {$tests_passed}/" . ( $tests_passed + $tests_failed ) . " passed.\n";
echo "=======================================================\n\n";

if ( $tests_failed > 0 ) {
	exit( 1 );
}
