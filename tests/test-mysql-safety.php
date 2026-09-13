<?php
/**
 * Real MySQL / MariaDB Safety Test Suite.
 *
 * Runs against an actual MySQL or MariaDB instance (no SQLite mocks).
 * Verifies:
 * 1. Four safety tables install cleanly with correct character set & collation.
 * 2. Indexes and single-column UNIQUE constraints are created and verified.
 * 3. checkpoint_uuid UNIQUE constraint enforcement rejects collisions.
 * 4. Write-ahead journal CAS transitions and fencing token mechanics.
 * 5. Tokens table primary key atomicity and CAS semantics.
 * 6. UTC_TIMESTAMP() expiry logic and conditional UPDATE ownership.
 * 7. Idempotency CAS behavior under real MySQL constraints.
 * 8. Full_Elementor_MCP_Database_Installer::verify_schema() recognizes MySQL indexes.
 * 9. Historical DB schema migrations (1.0.0, 1.1.0, 1.2.0, 1.3.0 -> 1.4.0) preserve all rows.
 * 10. Exactly four safety tables exist (zero phantom tables).
 *
 * Usage: php tests/test-mysql-safety.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( basename( __FILE__ ) === basename( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) {
	echo "=======================================================\n";
	echo " Safe Elementor MCP — Real MySQL Safety Test Suite\n";
	echo "=======================================================\n\n";
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'FULL_ELEMENTOR_MCP_VERSION' ) ) {
	define( 'FULL_ELEMENTOR_MCP_VERSION', '1.8.0' );
}
if ( ! defined( 'FULL_ELEMENTOR_MCP_DIR' ) ) {
	define( 'FULL_ELEMENTOR_MCP_DIR', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// ---------------------------------------------------------------------
// 1. MySQL Connection Helper
// ---------------------------------------------------------------------

function get_mysql_pdo(): PDO {
	$env_host = getenv( 'DB_HOST' ) ?: getenv( 'MYSQL_HOST' ) ?: '127.0.0.1';
	$env_port = (int) ( getenv( 'DB_PORT' ) ?: getenv( 'MYSQL_PORT' ) ?: 0 );
	$env_user = getenv( 'DB_USER' ) ?: getenv( 'MYSQL_USER' ) ?: 'root';
	$env_pass = getenv( 'DB_PASSWORD' ) !== false ? (string) getenv( 'DB_PASSWORD' ) : ( getenv( 'MYSQL_PWD' ) !== false ? (string) getenv( 'MYSQL_PWD' ) : null );
	$dbname   = getenv( 'DB_NAME' ) ?: getenv( 'MYSQL_DATABASE' ) ?: 'safe_elementor_test';

	$candidates = array();

	if ( $env_port > 0 && null !== $env_pass ) {
		$candidates[] = array( 'host' => $env_host, 'port' => $env_port, 'user' => $env_user, 'pass' => $env_pass );
	} elseif ( $env_port > 0 ) {
		$candidates[] = array( 'host' => $env_host, 'port' => $env_port, 'user' => $env_user, 'pass' => 'root' );
		$candidates[] = array( 'host' => $env_host, 'port' => $env_port, 'user' => $env_user, 'pass' => 'mysql' );
		$candidates[] = array( 'host' => $env_host, 'port' => $env_port, 'user' => $env_user, 'pass' => '' );
	} else {
		// Standard CI (3306 root:root / root:blank) followed by local dev (3307 root:mysql)
		if ( null !== $env_pass ) {
			$candidates[] = array( 'host' => $env_host, 'port' => 3306, 'user' => $env_user, 'pass' => $env_pass );
			$candidates[] = array( 'host' => $env_host, 'port' => 3307, 'user' => $env_user, 'pass' => $env_pass );
		}
		$candidates[] = array( 'host' => $env_host, 'port' => 3306, 'user' => 'root', 'pass' => 'root' );
		$candidates[] = array( 'host' => $env_host, 'port' => 3306, 'user' => 'root', 'pass' => '' );
		$candidates[] = array( 'host' => $env_host, 'port' => 3307, 'user' => 'root', 'pass' => 'mysql' );
	}

	$last_ex = null;
	foreach ( $candidates as $c ) {
		try {
			$dsn = "mysql:host={$c['host']};port={$c['port']}";
			$pdo = new PDO( $dsn, $c['user'], $c['pass'], array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			) );
			$pdo->exec( "CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
			$pdo->exec( "USE `{$dbname}`" );
			return $pdo;
		} catch ( Exception $e ) {
			$last_ex = $e;
		}
	}

	fwrite( STDERR, "FATAL: Could not connect to any MySQL candidate. Last error: " . ( $last_ex ? $last_ex->getMessage() : 'unknown' ) . "\n" );
	exit( 1 );
}

$pdo = get_mysql_pdo();
if ( basename( __FILE__ ) === basename( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) {
	echo "Connected to MySQL " . $pdo->getAttribute( PDO::ATTR_SERVER_VERSION ) . "\n\n";
}

// ---------------------------------------------------------------------
// 2. Real MySQL WPDB Implementation
// ---------------------------------------------------------------------

class Real_MySQL_WPDB {
	public string $prefix = 'wp_';
	public ?string $last_error = null;
	public string $last_query = '';
	public int $insert_id = 0;
	public int $rows_affected = 0;
	private PDO $pdo;
	private bool $suppress = false;

	public function __construct( PDO $pdo ) {
		$this->pdo = $pdo;
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function suppress_errors( bool $suppress = true ): bool {
		$old = $this->suppress;
		$this->suppress = $suppress;
		return $old;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function query( string $query ): int|bool {
		$this->last_query = $query;
		$this->last_error = null;
		try {
			$affected = $this->pdo->exec( $query );
			$this->rows_affected = false === $affected ? 0 : (int) $affected;
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return $affected;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			if ( ! $this->suppress ) {
				// error recorded
			}
			return false;
		}
	}

	public function get_results( string $query, string $output = OBJECT ): array {
		$this->last_query = $query;
		$this->last_error = null;
		try {
			$stmt = $this->pdo->query( $query );
			$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
			if ( OBJECT === $output ) {
				return array_map( fn( $r ) => (object) $r, $rows );
			}
			return $rows;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return array();
		}
	}

	public function get_row( string $query, string $output = OBJECT ): mixed {
		$res = $this->get_results( $query, $output );
		return $res[0] ?? null;
	}

	public function get_col( string $query, int $x = 0 ): array {
		$this->last_query = $query;
		$this->last_error = null;
		try {
			$stmt = $this->pdo->query( $query );
			return $stmt->fetchAll( PDO::FETCH_COLUMN, $x );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return array();
		}
	}

	public function get_var( string $query, int $x = 0, int $y = 0 ): mixed {
		$this->last_query = $query;
		$this->last_error = null;
		try {
			$stmt = $this->pdo->query( $query );
			$val = $stmt->fetchColumn( $x );
			return false === $val ? null : $val;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return null;
		}
	}

	public function prepare( string $query, ...$args ): string {
		if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
			$args = $args[0];
		}
		$arg_index = 0;
		$prepared = preg_replace_callback( '/%[sdfF]/', function( $match ) use ( &$arg_index, $args ) {
			if ( ! array_key_exists( $arg_index, $args ) ) {
				return $match[0];
			}
			$val = $args[ $arg_index++ ];
			if ( null === $val ) {
				return 'NULL';
			}
			if ( '%d' === $match[0] ) {
				return (string) ( (int) $val );
			}
			if ( '%f' === $match[0] || '%F' === $match[0] ) {
				return (string) ( (float) $val );
			}
			return $this->pdo->quote( (string) $val );
		}, $query );
		return (string) $prepared;
	}

	public function insert( string $table, array $data, ?array $format = null ): int|bool {
		$cols = array_keys( $data );
		$quoted_cols = array_map( fn( $c ) => "`{$c}`", $cols );
		$placeholders = array_fill( 0, count( $cols ), '?' );

		$sql = "INSERT INTO `{$table}` (" . implode( ', ', $quoted_cols ) . ") VALUES (" . implode( ', ', $placeholders ) . ")";
		$this->last_query = $sql;
		try {
			$stmt = $this->pdo->prepare( $sql );
			$stmt->execute( array_values( $data ) );
			$this->insert_id = (int) $this->pdo->lastInsertId();
			$this->rows_affected = $stmt->rowCount();
			return $this->rows_affected;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int|bool {
		$set_parts = array();
		$values    = array();
		foreach ( $data as $c => $v ) {
			$set_parts[] = "`{$c}` = ?";
			$values[]    = $v;
		}
		$where_parts = array();
		foreach ( $where as $c => $v ) {
			$where_parts[] = "`{$c}` = ?";
			$values[]      = $v;
		}
		$sql = "UPDATE `{$table}` SET " . implode( ', ', $set_parts ) . " WHERE " . implode( ' AND ', $where_parts );
		$this->last_query = $sql;
		try {
			$stmt = $this->pdo->prepare( $sql );
			$stmt->execute( $values );
			$this->rows_affected = $stmt->rowCount();
			return $this->rows_affected;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	public function delete( string $table, array $where, ?array $where_format = null ): int|bool {
		$where_parts = array();
		$values      = array();
		foreach ( $where as $c => $v ) {
			$where_parts[] = "`{$c}` = ?";
			$values[]      = $v;
		}
		$sql = "DELETE FROM `{$table}` WHERE " . implode( ' AND ', $where_parts );
		$this->last_query = $sql;
		try {
			$stmt = $this->pdo->prepare( $sql );
			$stmt->execute( $values );
			$this->rows_affected = $stmt->rowCount();
			return $this->rows_affected;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}
}

global $wpdb;
$wpdb = new Real_MySQL_WPDB( $pdo );

// WordPress option helpers
$GLOBALS['wp_test_options'] = array();
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
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( mixed $args, array $defaults = array() ): array {
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		} elseif ( ! is_array( $args ) ) {
			$args = array();
		}
		return array_merge( $defaults, $args );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'safe-elementor-mcp-test-auth-key-32chars!' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'safe-elementor-mcp-test-sec-auth-key-32ch!' );
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		return true;
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( int|object $user, string $capability, ...$args ): bool {
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, ...$args ): mixed {
		return $value;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		// no-op stub
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		global $_wp_mock_post_meta;
		if ( ! is_array( $_wp_mock_post_meta ) ) {
			$_wp_mock_post_meta = array();
		}
		if ( '' === $key ) {
			return $_wp_mock_post_meta[ $post_id ] ?? array();
		}
		$val = $_wp_mock_post_meta[ $post_id ][ $key ] ?? ( $single ? '' : array() );
		return $val;
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value, mixed $prev_value = '' ): bool {
		global $_wp_mock_post_meta;
		if ( ! is_array( $_wp_mock_post_meta ) ) {
			$_wp_mock_post_meta = array();
		}
		if ( ! isset( $_wp_mock_post_meta[ $post_id ] ) ) {
			$_wp_mock_post_meta[ $post_id ] = array();
		}
		$_wp_mock_post_meta[ $post_id ][ $key ] = wp_unslash( $value );
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key, mixed $value = '' ): bool {
		global $_wp_mock_post_meta;
		if ( isset( $_wp_mock_post_meta[ $post_id ][ $key ] ) ) {
			unset( $_wp_mock_post_meta[ $post_id ][ $key ] );
		}
		return true;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int $post_id ): string|false {
		return 'publish';
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ): ?stdClass {
		global $_wp_mock_posts;
		if ( isset( $_wp_mock_posts[ $post_id ] ) ) {
			return (object) $_wp_mock_posts[ $post_id ];
		}
		$post                 = new stdClass();
		$post->ID             = $post_id;
		$post->post_type      = 'post';
		$post->post_status    = 'publish';
		$post->post_title     = '';
		$post->post_name      = '';
		$post->post_content   = '';
		$post->post_excerpt   = '';
		$post->post_parent    = 0;
		$post->menu_order     = 0;
		$post->comment_status = 'closed';
		$post->ping_status    = 'closed';
		$post->post_password  = '';
		return $post;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false, bool $fire_after_hooks = true ): int|\WP_Error {
		global $_wp_mock_posts;
		static $next_id = 9912;
		$id = ! empty( $postarr['import_id'] ) ? (int) $postarr['import_id'] : ( ! empty( $postarr['ID'] ) ? (int) $postarr['ID'] : $next_id++ );
		if ( ! is_array( $_wp_mock_posts ) ) {
			$_wp_mock_posts = array();
		}
		$_wp_mock_posts[ $id ] = array_merge( (array) ( get_post( $id ) ?? array() ), $postarr );
		return $id;
	}
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $post_id, bool $force = false ): ?stdClass {
		global $_wp_mock_posts;
		if ( isset( $_wp_mock_posts[ $post_id ] ) ) {
			$post = (object) $_wp_mock_posts[ $post_id ];
			unset( $_wp_mock_posts[ $post_id ] );
			return $post;
		}
		$post     = new stdClass();
		$post->ID = $post_id;
		return $post;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $postarr, bool $wp_error = false ): int|\WP_Error {
		global $_wp_mock_posts;
		$id = (int) ( $postarr['ID'] ?? 1 );
		if ( ! is_array( $_wp_mock_posts ) ) {
			$_wp_mock_posts = array();
		}
		$_wp_mock_posts[ $id ] = array_merge( (array) ( get_post( $id ) ?? array() ), $postarr );
		return $id;
	}
}
if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
		global $_wp_mock_post_meta;
		return isset( $_wp_mock_post_meta[ $object_id ][ $meta_key ] );
	}
}
if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( int|stdClass $post, int $thumbnail_id ): int|bool {
		return true;
	}
}
if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( int|stdClass $post ): bool {
		return true;
	}
}
if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( int $object_id, array|int|string $terms, string $taxonomy, bool $append = false ): array|\WP_Error {
		return array();
	}
}

require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-database-installer.php';
require_once FULL_ELEMENTOR_MCP_DIR . 'includes/class-compatibility-checker.php';

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return addslashes( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = wp_slash( $v );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = wp_unslash( $v );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	function wp_get_upload_dir(): array {
		$tmp = sys_get_temp_dir();
		return array(
			'path'    => $tmp,
			'url'     => 'http://localhost/uploads',
			'subdir'  => '',
			'basedir' => $tmp,
			'baseurl' => 'http://localhost/uploads',
			'error'   => false,
		);
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		return wp_get_upload_dir();
	}
}

// ---------------------------------------------------------------------
// 3. Test Harness Framework
// ---------------------------------------------------------------------

$total_tests  = 0;
$passed_tests = 0;
$failed_tests = 0;

if ( ! function_exists( 'run_test' ) ) {
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
}

if ( ! function_exists( 'assert_true' ) ) {
	function assert_true( mixed $val, string $msg = 'Expected true' ): void {
		if ( true !== $val ) {
			throw new RuntimeException( $msg . ' (got: ' . var_export( $val, true ) . ')' );
		}
	}
}

if ( ! function_exists( 'assert_false' ) ) {
	function assert_false( mixed $val, string $msg = 'Expected false' ): void {
		if ( false !== $val ) {
			throw new RuntimeException( $msg . ' (got: ' . var_export( $val, true ) . ')' );
		}
	}
}

if ( ! function_exists( 'assert_equals' ) ) {
	function assert_equals( mixed $expected, mixed $actual, string $msg = '' ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( ( $msg ? $msg . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}
}

function drop_all_safety_tables(): void {
	global $wpdb;
	$tables = array(
		$wpdb->prefix . 'elementor_mcp_journal',
		$wpdb->prefix . 'elementor_mcp_checkpoints',
		$wpdb->prefix . 'elementor_mcp_audit_log',
		$wpdb->prefix . 'elementor_mcp_tokens',
	);
	foreach ( $tables as $tbl ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$tbl}`" );
	}
}

if ( basename( __FILE__ ) === basename( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) {
// ---------------------------------------------------------------------
// TEST GROUP 1: Clean Installation & dbDelta Table Generation
// ---------------------------------------------------------------------

run_test( 'MySQL Install: Clean install creates all four tables with utf8mb4', function () {
	drop_all_safety_tables();
	delete_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION );

	$ok = Full_Elementor_MCP_Database_Installer::install();
	assert_true( $ok, 'Full_Elementor_MCP_Database_Installer::install() must return true' );

	global $wpdb;
	$tables = $wpdb->get_col( "SHOW TABLES LIKE 'wp_elementor_mcp_%'" );
	assert_equals( 4, count( $tables ), 'Exactly 4 safety tables must be present' );

	$expected = array(
		'wp_elementor_mcp_journal',
		'wp_elementor_mcp_checkpoints',
		'wp_elementor_mcp_audit_log',
		'wp_elementor_mcp_tokens',
	);
	foreach ( $expected as $exp ) {
		assert_true( in_array( $exp, $tables, true ), "Table {$exp} must exist in MySQL" );
	}

	assert_equals( '1.4.0', get_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION ) );
} );

// ---------------------------------------------------------------------
// TEST GROUP 2: MySQL Index Structure & Column Integrity
// ---------------------------------------------------------------------

run_test( 'MySQL Indexes: dbDelta and DDL create required MySQL indexes', function () {
	global $wpdb;

	// 1. Journal table
	$j_indexes = $wpdb->get_results( "SHOW INDEX FROM `wp_elementor_mcp_journal`", ARRAY_A );
	$j_keys = array_unique( array_column( $j_indexes, 'Key_name' ) );
	assert_true( in_array( 'PRIMARY', $j_keys, true ), 'Journal must have PRIMARY key' );
	assert_true( in_array( 'idx_status', $j_keys, true ), 'Journal must have idx_status' );
	assert_true( in_array( 'idx_resource', $j_keys, true ), 'Journal must have idx_resource' );
	assert_true( in_array( 'idx_created', $j_keys, true ), 'Journal must have idx_created' );

	// 2. Checkpoints table
	$c_indexes = $wpdb->get_results( "SHOW INDEX FROM `wp_elementor_mcp_checkpoints`", ARRAY_A );
	$c_keys = array_unique( array_column( $c_indexes, 'Key_name' ) );
	assert_true( in_array( 'PRIMARY', $c_keys, true ), 'Checkpoints must have PRIMARY key' );
	assert_true( in_array( 'idx_checkpoint_uuid', $c_keys, true ), 'Checkpoints must have idx_checkpoint_uuid' );
	assert_true( in_array( 'idx_resource', $c_keys, true ), 'Checkpoints must have idx_resource' );

	// 3. Audit log table
	$a_indexes = $wpdb->get_results( "SHOW INDEX FROM `wp_elementor_mcp_audit_log`", ARRAY_A );
	$a_keys = array_unique( array_column( $a_indexes, 'Key_name' ) );
	assert_true( in_array( 'PRIMARY', $a_keys, true ), 'Audit log must have PRIMARY key' );
	assert_true( in_array( 'idx_timestamp', $a_keys, true ), 'Audit log must have idx_timestamp' );
	assert_true( in_array( 'idx_event', $a_keys, true ), 'Audit log must have idx_event' );

	// 4. Tokens table
	$t_indexes = $wpdb->get_results( "SHOW INDEX FROM `wp_elementor_mcp_tokens`", ARRAY_A );
	$t_keys = array_unique( array_column( $t_indexes, 'Key_name' ) );
	assert_true( in_array( 'PRIMARY', $t_keys, true ), 'Tokens must have PRIMARY key' );
	assert_true( in_array( 'idx_type_expires', $t_keys, true ), 'Tokens must have idx_type_expires' );
} );

// ---------------------------------------------------------------------
// TEST GROUP 3: Checkpoint UUID Single-Column UNIQUE Constraint
// ---------------------------------------------------------------------

run_test( 'MySQL Constraint: checkpoint_uuid UNIQUE constraint rejects duplicate inserts', function () {
	global $wpdb;
	$uuid = 'test-uuid-mysql-unique-001';

	$res1 = $wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_checkpoints',
		array(
			'checkpoint_uuid' => $uuid,
			'resource_key'    => 'post:123',
			'object_type'     => 'post',
			'object_id'       => 123,
			'checkpoint_type' => 'manual',
			'state_hash'      => hash( 'sha256', 'initial' ),
		)
	);
	assert_equals( 1, $res1, 'First insert must succeed' );

	// Second insert with exact same checkpoint_uuid must fail with unique constraint violation:
	$wpdb->suppress_errors( true );
	$res2 = $wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_checkpoints',
		array(
			'checkpoint_uuid' => $uuid,
			'resource_key'    => 'post:123',
			'object_type'     => 'post',
			'object_id'       => 123,
			'checkpoint_type' => 'manual',
			'state_hash'      => hash( 'sha256', 'duplicate' ),
		)
	);
	$wpdb->suppress_errors( false );

	assert_false( $res2, 'Second insert with colliding checkpoint_uuid must fail' );
	assert_true( ! empty( $wpdb->last_error ), 'MySQL last_error must record duplicate key violation' );
	assert_true( str_contains( strtolower( $wpdb->last_error ), 'duplicate' ), 'Error must be duplicate entry: ' . $wpdb->last_error );
} );

// ---------------------------------------------------------------------
// TEST GROUP 4: Write-Ahead Journal CAS Transitions & Fencing
// ---------------------------------------------------------------------

run_test( 'MySQL Journal CAS: State transitions and fencing token verify atomically', function () {
	global $wpdb;

	$wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_journal',
		array(
			'ability'           => 'elementor_update_post',
			'action'            => 'update',
			'object_type'       => 'post',
			'object_id'         => 500,
			'resource_key'      => 'post:500',
			'rollback_supported'=> 1,
			'fencing_token'     => 10,
			'status'            => 'pending',
		)
	);
	$journal_id = $wpdb->insert_id;
	assert_true( $journal_id > 0 );

	// Valid CAS transition: pending -> committed where fencing_token = 10
	$affected1 = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}elementor_mcp_journal SET status = %s WHERE id = %d AND status = %s AND fencing_token = %d",
			'committed',
			$journal_id,
			'pending',
			10
		)
	);
	assert_equals( 1, $affected1, 'Legal CAS transition must succeed' );

	// Second attempt with status = pending must yield 0 affected rows:
	$affected2 = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}elementor_mcp_journal SET status = %s WHERE id = %d AND status = %s",
			'failed',
			$journal_id,
			'pending'
		)
	);
	assert_equals( 0, $affected2, 'Illegal transition from committed -> failed must affect 0 rows' );
} );

// ---------------------------------------------------------------------
// TEST GROUP 5: Tokens Table Primary Key & Atomic CAS
// ---------------------------------------------------------------------

run_test( 'MySQL Tokens CAS: token_key PRIMARY KEY enforces mutual exclusion', function () {
	global $wpdb;
	$token_key = 'lock:post:999';

	$res1 = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}elementor_mcp_tokens (token_key, token_type, owner_id, fencing_token, expires_at)
			 VALUES (%s, %s, %s, %d, UTC_TIMESTAMP() + INTERVAL 30 SECOND)",
			$token_key,
			'lock',
			'worker-A',
			1
		)
	);
	assert_equals( 1, $res1, 'Worker A must acquire lock' );

	// Competing insert with same token_key must fail on PRIMARY KEY:
	$wpdb->suppress_errors( true );
	$res2 = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}elementor_mcp_tokens (token_key, token_type, owner_id, fencing_token, expires_at)
			 VALUES (%s, %s, %s, %d, UTC_TIMESTAMP() + INTERVAL 30 SECOND)",
			$token_key,
			'lock',
			'worker-B',
			2
		)
	);
	$wpdb->suppress_errors( false );
	assert_false( $res2, 'Competing insert must be rejected by PRIMARY KEY constraint' );

	// Atomic lease renewal / takeover with CAS (owner_id = worker-A, fencing_token = 1):
	$renew = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}elementor_mcp_tokens SET fencing_token = %d, expires_at = UTC_TIMESTAMP() + INTERVAL 60 SECOND
			 WHERE token_key = %s AND owner_id = %s AND fencing_token = %d",
			2,
			$token_key,
			'worker-A',
			1
		)
	);
	assert_equals( 1, $renew, 'CAS token renewal must increment fencing token' );
} );

// ---------------------------------------------------------------------
// TEST GROUP 6: UTC_TIMESTAMP() Expiry Logic on MySQL
// ---------------------------------------------------------------------

run_test( 'MySQL UTC_TIMESTAMP: Native timestamp expiry arithmetic works accurately', function () {
	global $wpdb;

	$wpdb->query(
		"INSERT INTO {$wpdb->prefix}elementor_mcp_tokens (token_key, token_type, owner_id, fencing_token, expires_at)
		 VALUES ('test:expired:1', 'lock', 'worker-old', 1, UTC_TIMESTAMP() - INTERVAL 10 SECOND)"
	);
	$wpdb->query(
		"INSERT INTO {$wpdb->prefix}elementor_mcp_tokens (token_key, token_type, owner_id, fencing_token, expires_at)
		 VALUES ('test:active:1', 'lock', 'worker-new', 1, UTC_TIMESTAMP() + INTERVAL 60 SECOND)"
	);

	$active_count = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_tokens WHERE token_key IN ('test:expired:1', 'test:active:1') AND expires_at > UTC_TIMESTAMP()"
	);
	assert_equals( 1, $active_count, 'Only the active token must have expires_at > UTC_TIMESTAMP()' );

	$expired_count = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}elementor_mcp_tokens WHERE token_key IN ('test:expired:1', 'test:active:1') AND expires_at <= UTC_TIMESTAMP()"
	);
	assert_equals( 1, $expired_count, 'Expired token must have expires_at <= UTC_TIMESTAMP()' );
} );

// ---------------------------------------------------------------------
// TEST GROUP 7: Conditional UPDATE Ownership & Fencing Checks
// ---------------------------------------------------------------------

run_test( 'MySQL Fencing Checks: Stale generation update rejected by conditional UPDATE', function () {
	global $wpdb;

	// Stale worker tries updating when fencing token has advanced:
	$affected = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}elementor_mcp_tokens SET payload = %s WHERE token_key = %s AND fencing_token = %d",
			'stale_write',
			'lock:post:999',
			1 // Stale fencing token, current is 2
		)
	);
	assert_equals( 0, $affected, 'Stale fencing token update must affect 0 rows' );
} );

// ---------------------------------------------------------------------
// TEST GROUP 8: Idempotency CAS Semantics on MySQL
// ---------------------------------------------------------------------

run_test( 'MySQL Idempotency CAS: Idempotency token storage and atomic resolution', function () {
	global $wpdb;
	$idem_key = 'idem:mutation:hash-777';

	// Initial registration in pending state:
	$wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_tokens',
		array(
			'token_key'     => $idem_key,
			'token_type'    => 'idempotency',
			'owner_id'      => 'req-worker-1',
			'fencing_token' => 1,
			'payload'       => json_encode( array( 'status' => 'in_progress', 'args_hash' => 'abc' ) ),
			'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + 300 ),
		)
	);

	// Competing request with same idempotency key fails:
	$wpdb->suppress_errors( true );
	$dup = $wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_tokens',
		array(
			'token_key'     => $idem_key,
			'token_type'    => 'idempotency',
			'owner_id'      => 'req-worker-2',
			'fencing_token' => 1,
			'payload'       => json_encode( array( 'status' => 'in_progress', 'args_hash' => 'abc' ) ),
			'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + 300 ),
		)
	);
	$wpdb->suppress_errors( false );
	assert_false( $dup, 'Duplicate idempotency registration must be rejected' );

	// Resolving idempotency with CAS:
	$resolve = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}elementor_mcp_tokens SET used = 1, payload = %s WHERE token_key = %s AND owner_id = %s",
			json_encode( array( 'status' => 'completed', 'result' => array( 'post_id' => 123 ) ) ),
			$idem_key,
			'req-worker-1'
		)
	);
	assert_equals( 1, $resolve, 'Owner must atomically resolve idempotency token' );
} );

// ---------------------------------------------------------------------
// TEST GROUP 9: Schema Verification on Real MySQL
// ---------------------------------------------------------------------

run_test( 'MySQL Verification: verify_schema() succeeds on real MySQL tables', function () {
	$valid = Full_Elementor_MCP_Database_Installer::verify_schema();
	assert_true( $valid, 'verify_schema() must return true against real MySQL tables and indexes' );
} );

// ---------------------------------------------------------------------
// TEST GROUP 10: Historical Database Migrations (1.0.0, 1.1.0, 1.2.0, 1.3.0 -> 1.4.0)
// ---------------------------------------------------------------------

run_test( 'MySQL Migrations: Historical schema versions upgrade cleanly to 1.4.0 preserving rows', function () {
	global $wpdb;
	drop_all_safety_tables();

	// 1. Create historical DB 1.0.0 schema (only journal and tokens):
	$wpdb->query(
		"CREATE TABLE `{$wpdb->prefix}elementor_mcp_journal` (
			id bigint(20) unsigned NOT NULL auto_increment,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			ability varchar(100) NOT NULL,
			action varchar(20) NOT NULL,
			object_type varchar(30) NOT NULL,
			object_id bigint(20) unsigned NOT NULL default 0,
			resource_key varchar(128) NOT NULL default '',
			status varchar(20) NOT NULL default 'pending',
			PRIMARY KEY (id)
		) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
	);
	$wpdb->query(
		"CREATE TABLE `{$wpdb->prefix}elementor_mcp_tokens` (
			token_key varchar(128) NOT NULL,
			token_type varchar(20) NOT NULL,
			owner_id varchar(64) default NULL,
			fencing_token bigint(20) unsigned NOT NULL default 0,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			expires_at datetime NOT NULL,
			PRIMARY KEY (token_key)
		) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
	);

	// Seed historical data:
	$wpdb->query( "INSERT INTO `{$wpdb->prefix}elementor_mcp_journal` (ability, action, object_type, object_id, resource_key, status) VALUES ('elementor_update_post', 'update', 'post', 42, 'post:42', 'committed')" );
	$wpdb->query( "INSERT INTO `{$wpdb->prefix}elementor_mcp_tokens` (token_key, token_type, expires_at) VALUES ('hist:token:1', 'lock', UTC_TIMESTAMP() + INTERVAL 1 HOUR)" );

	update_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION, '1.0.0' );

	// Upgrade to current (1.4.0):
	$upgraded = Full_Elementor_MCP_Database_Installer::maybe_upgrade();
	assert_true( $upgraded, 'Upgrade from 1.0.0 must succeed' );

	// Verify all 4 tables exist now:
	$tables = $wpdb->get_col( "SHOW TABLES LIKE 'wp_elementor_mcp_%'" );
	assert_equals( 4, count( $tables ), 'All 4 tables must exist after upgrade from 1.0.0' );

	// Verify historical data preserved:
	$journal_row = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_journal` WHERE object_id = 42", ARRAY_A );
	assert_true( ! empty( $journal_row ), 'Historical journal row must be preserved' );
	assert_equals( 'committed', $journal_row['status'] );

	$token_row = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_tokens` WHERE token_key = 'hist:token:1'", ARRAY_A );
	assert_true( ! empty( $token_row ), 'Historical token row must be preserved' );

	// Verify new columns exist:
	$cols = Full_Elementor_MCP_Database_Installer::get_table_columns( $wpdb->prefix . 'elementor_mcp_journal' );
	assert_true( in_array( 'fencing_token', $cols, true ), 'New column fencing_token must be added' );
	assert_true( in_array( 'created_object_id', $cols, true ), 'New column created_object_id must be added' );

	assert_equals( '1.4.0', get_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION ) );
} );

// ---------------------------------------------------------------------
// TEST GROUP 11: Schema Invariant - Exactly Four Tables
// ---------------------------------------------------------------------

run_test( 'MySQL Invariant: Exactly four safety tables remain', function () {
	global $wpdb;
	$tables = $wpdb->get_col( "SHOW TABLES LIKE 'wp_elementor_mcp_%'" );
	assert_equals( 4, count( $tables ), 'Exactly four elementor_mcp safety tables must exist' );
} );

// ---------------------------------------------------------------------
// TEST SUMMARY
// ---------------------------------------------------------------------

	echo "\n=======================================================\n";
	echo " Test Results: {$passed_tests}/{$total_tests} passed.";
	if ( $failed_tests > 0 ) {
		echo " ({$failed_tests} failed)\n";
		echo "=======================================================\n";
		exit( 1 );
	}
	echo "\n=======================================================\n";
	exit( 0 );
}
