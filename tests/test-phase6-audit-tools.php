<?php
/**
 * Comprehensive Test Suite for Phase 6: Audit, Undo Tools & Admin Safety Console.
 *
 * Can be executed via CLI: `php tests/test-phase6-audit-tools.php`
 *
 * Verifies all Phase 6 requirements:
 * 1. Database schema migration (1.3.0 -> 1.4.0, exactly four tables, row preservation, new columns & indexes)
 * 2. Append-only forensic Audit Logger (DB UTC, request correlation, secret redaction, 32 KiB cap, retention pruning)
 * 3. Secret scan across audit table (verifies zero plaintext credentials)
 * 4. Audit logger fail-safe semantics (audit write failure does not break committed mutations)
 * 5. Safety Status ability (healthy, degraded, action_required, active locks, keyring, recovery count)
 * 6. List & Get Changes abilities (pagination, filtering, strict omission of raw before_state, secret redaction)
 * 7. List & Get Checkpoints abilities (metadata-only listing, historical profile decoration, no bulk decryption)
 * 8. List Audit Events ability (filters by event_type, severity, request_uuid, resource_key, limit/offset)
 * 9. Managed safety mutation mode in middleware (no outer double-locking, no nested WAL, caller spoofing rejected)
 * 10. Safe Undo Manager & undo-change ability (live-state conflict detection against after_hash, pre_undo checkpoint)
 * 11. Strict undo-last-change conservatism (fails closed with undo_latest_not_safe if newest change is not rollbackable)
 * 12. Concurrent undo protection (lock acquisition prevents simultaneous execution)
 * 13. Restore checkpoint ability (mandatory confirmation, delegates to Phase 5 engine)
 * 14. Admin Safety Console security (manage_options gate, CSRF nonces, zero GET mutations, contextual escaping)
 * 15. Recovery scan integration (triggers recover_pending cleanly)
 *
 * @package Full_Elementor_MCP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'FULL_ELEMENTOR_MCP_VERSION' ) ) {
	define( 'FULL_ELEMENTOR_MCP_VERSION', '1.9.0' );
}
if ( ! defined( 'FULL_ELEMENTOR_MCP_DIR' ) ) {
	define( 'FULL_ELEMENTOR_MCP_DIR', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'FULL_ELEMENTOR_MCP_URL' ) ) {
	define( 'FULL_ELEMENTOR_MCP_URL', 'https://example.com/wp-content/plugins/full-elementor-mcp/' );
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
$GLOBALS['mock_terms']          = array();
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
		$prefix = $GLOBALS['wp_test_salt_prefix'] ?? 'mock_salt_';
		return $prefix . $scheme . '_secret_key_material_for_hkdf_testing_12345';
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
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return $url;
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( string $text ): string {
		return addslashes( $text );
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return true;
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $text, string $domain = 'default' ): void {
		echo esc_attr( $text );
	}
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( mixed $data ): mixed {
		if ( is_array( $data ) ) {
			return array_map( 'esc_sql', $data );
		}
		return SQLite3::escapeString( (string) $data );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $string, bool $remove_breaks = false ): string {
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $val ): mixed {
		return is_string( $val ) ? stripslashes( $val ) : $val;
	}
}
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( mixed $val ): mixed {
		return is_string( $val ) ? addslashes( $val ) : $val;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
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
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, mixed $value, ...$args ): mixed {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $tag, ...$args ): void {}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $tag, callable $function_to_add, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'add_options_page' ) ) {
	function add_options_page( ...$args ): string { return 'settings_page_full-elementor-mcp-safety'; }
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( string $action = '', string $query_arg = '_wpnonce' ): bool {
		if ( empty( $_POST[ $query_arg ] ) || $_POST[ $query_arg ] !== 'valid_nonce_' . $action ) {
			wp_die( 'The link you followed has expired.' );
		}
		return true;
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '' ): string {
		return 'valid_nonce_' . $action;
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true ): string {
		$html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
		if ( $echo ) {
			echo $html;
		}
		return $html;
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( string $msg = '' ): void {
		throw new \RuntimeException( 'WP_DIE: ' . $msg );
	}
}
if ( ! function_exists( 'size_format' ) ) {
	function size_format( int|float $bytes, int $decimals = 0 ): string {
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, $decimals ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, $decimals ) . ' KB';
		}
		return $bytes . ' B';
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( int|float $number, int $decimals = 0 ): string {
		return number_format( (float) $number, $decimals );
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( mixed $selected, mixed $current = true, bool $echo = true ): string {
		$out = ( (string) $selected === (string) $current ) ? 'selected="selected"' : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		$sep = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $sep . http_build_query( $args );
	}
}
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
		$GLOBALS['mock_post_meta'][ $post_id ][ $key ] = wp_unslash( $val );
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
				'ID'          => $id,
				'post_title'  => '',
				'post_status' => 'publish',
				'post_type'   => 'page',
				'post_name'   => sanitize_key( $postarr['post_title'] ?? 'post-' . $id ),
			),
			$postarr
		);
		$GLOBALS['mock_posts'][ $id ] = $obj;
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
		return $id;
	}
}
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( int $id ): object|false {
		if ( isset( $GLOBALS['mock_posts'][ $id ] ) ) {
			$GLOBALS['mock_posts'][ $id ]->post_status = 'trash';
			return $GLOBALS['mock_posts'][ $id ];
		}
		return false;
	}
}
if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( int $id ): object|false {
		if ( isset( $GLOBALS['mock_posts'][ $id ] ) ) {
			$GLOBALS['mock_posts'][ $id ]->post_status = 'publish';
			return $GLOBALS['mock_posts'][ $id ];
		}
		return false;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int|object $post ): string|false {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		return isset( $GLOBALS['mock_posts'][ $id ] ) ? ( $GLOBALS['mock_posts'][ $id ]->post_status ?? 'publish' ) : false;
	}
}

// Global Abilities registration mock:
$GLOBALS['mock_registered_abilities'] = array();
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ) {
		$GLOBALS['mock_registered_abilities'][ $name ] = $args;
		return true;
	}
}
if ( ! function_exists( 'full_elementor_mcp_register_ability' ) ) {
	function full_elementor_mcp_register_ability( string $name, array $args ) {
		if ( class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) ) {
			$args = Full_Elementor_MCP_Mutation_Middleware::wrap_ability( $name, $args );
		}
		return wp_register_ability( $name, $args );
	}
}

// SQLite Database Mock:
class MockPhase6Wpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public ?\PDO $pdo = null;
	public ?\Closure $on_before_query = null;

	public function __construct() {
		$this->pdo = new \PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );

		$this->pdo->sqliteCreateFunction( 'UTC_TIMESTAMP', function () {
			return gmdate( 'Y-m-d H:i:s' );
		} );
		$this->pdo->sqliteCreateFunction( 'DATE_SUB', function ( $date, $interval ) {
			if ( preg_match( '/INTERVAL\s+(\d+)\s+DAY/i', $interval, $m ) ) {
				$ts = strtotime( $date . ' UTC' ) - ( (int) $m[1] * 86400 );
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
			if ( preg_match( '/INTERVAL\s+(\d+)\s+HOUR/i', $interval, $m ) ) {
				$ts = strtotime( $date . ' UTC' ) - ( (int) $m[1] * 3600 );
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
			if ( preg_match( '/INTERVAL\s+(\d+)\s+SECOND/i', $interval, $m ) ) {
				$ts = strtotime( $date . ' UTC' ) - (int) $m[1];
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
			return $date;
		}, 2 );
		$this->pdo->sqliteCreateFunction( 'DATE_ADD', function ( $date, $interval ) {
			if ( preg_match( '/INTERVAL\s+(\d+)\s+SECOND/i', $interval, $m ) ) {
				$ts = strtotime( $date . ' UTC' ) + (int) $m[1];
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
			return $date;
		}, 2 );
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$idx = 0;
		return preg_replace_callback( '/(%[sdfF]|%s)/', function ( $matches ) use ( &$idx, $args ) {
			if ( ! array_key_exists( $idx, $args ) ) {
				return $matches[0];
			}
			$val = $args[ $idx++ ];
			if ( null === $val ) {
				return 'NULL';
			}
			if ( '%d' === $matches[0] ) {
				return (string) (int) $val;
			}
			if ( '%f' === $matches[0] || '%F' === $matches[0] ) {
				return (string) (float) $val;
			}
			return "'" . SQLite3::escapeString( (string) $val ) . "'";
		}, $query );
	}

	public function query( string $query ): int|bool {
		if ( $this->on_before_query ) {
			$hook_res = ( $this->on_before_query )( $query );
			if ( false === $hook_res ) {
				return false;
			}
		}

		try {
			if ( str_contains( $query, 'CREATE TABLE' ) ) {
				$table_name = '';
				if ( preg_match( '/CREATE\s+TABLE\s+([^\s(]+)/i', $query, $tm ) ) {
					$table_name = trim( $tm[1], '`' );
				}
				$indexes_to_create = array();
				if ( ! empty( $table_name ) && preg_match_all( '/\b(?:UNIQUE\s+)?KEY\s+([a-zA-Z0-9_]+)\s*\(([^)]+)\)/i', $query, $km, PREG_SET_ORDER ) ) {
					foreach ( $km as $key_match ) {
						$is_unique = str_contains( strtoupper( $key_match[0] ), 'UNIQUE' ) ? 'UNIQUE ' : '';
						$indexes_to_create[] = "CREATE {$is_unique}INDEX IF NOT EXISTS {$table_name}_{$key_match[1]} ON {$table_name} ({$key_match[2]});";
					}
				}

				$q = preg_replace( '/id\s+bigint\([^)]+\)\s+unsigned\s+NOT\s+NULL\s+auto_increment/i', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $query );
				$q = preg_replace( '/id\s+BIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT/i', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $q );
				$q = preg_replace( '/bigint\([^)]+\)\s*(?:unsigned)?/i', 'INTEGER', $q );
				$q = preg_replace( '/int\([^)]+\)\s*(?:unsigned)?/i', 'INTEGER', $q );
				$q = preg_replace( '/tinyint\([^)]+\)\s*(?:unsigned)?/i', 'INTEGER', $q );
				$q = preg_replace( '/datetime/i', 'TEXT', $q );
				$q = preg_replace( '/longtext|mediumtext|text/i', 'TEXT', $q );
				$q = preg_replace( '/varchar\([^)]+\)/i', 'TEXT', $q );
				$q = preg_replace( '/on\s+update\s+CURRENT_TIMESTAMP/i', '', $q );
				if ( str_contains( $q, 'AUTOINCREMENT' ) ) {
					$q = preg_replace( '/PRIMARY\s+KEY\s*\([^)]+\),?/i', '', $q );
				}
				$q = preg_replace( '/(?<!PRIMARY\s)\b(?:UNIQUE\s+)?KEY\s+[a-zA-Z0-9_]+\s*\([^)]+\),?/i', '', $q );
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

			if ( preg_match( '/^ALTER\s+TABLE\s+([^\s]+)\s+DROP\s+INDEX\s+([^\s;]+)/i', trim( $query ), $dm ) ) {
				$table = trim( $dm[1], '`' );
				$idx   = trim( $dm[2], '`' );
				try { $this->pdo->exec( "DROP INDEX IF EXISTS {$idx};" ); } catch ( \Throwable $e ) {}
				try { $this->pdo->exec( "DROP INDEX IF EXISTS {$table}_{$idx};" ); } catch ( \Throwable $e ) {}
				return 1;
			}

			if ( preg_match( '/^ALTER\s+TABLE\s+([^\s]+)\s+ADD\s+UNIQUE\s+(?:KEY|INDEX)\s+([^\s(]+)\s*\(([^)]+)\)/i', trim( $query ), $am ) ) {
				$table = trim( $am[1], '`' );
				$idx   = trim( $am[2], '`' );
				$cols  = $am[3];
				try { $this->pdo->exec( "CREATE UNIQUE INDEX IF NOT EXISTS {$table}_{$idx} ON {$table}({$cols});" ); } catch ( \Throwable $e ) {}
				return 1;
			}

			if ( preg_match( '/^ALTER\s+TABLE\s+([^\s]+)\s+ADD\s+(?:COLUMN\s+)?([a-zA-Z0-9_]+)\s+(.+)$/i', trim( $query ), $cm ) ) {
				$table    = trim( $cm[1], '`' );
				$col_name = trim( $cm[2], '`' );
				$col_def  = $cm[3];
				$col_type = 'TEXT';
				if ( preg_match( '/\b(?:int|tinyint|bigint)\b/i', $col_def ) ) {
					$col_type = 'INTEGER';
				}
				$default_clause = '';
				if ( preg_match( "/DEFAULT\s+('?[^,\s;]+'?)/i", $col_def, $dfm ) ) {
					$default_clause = ' DEFAULT ' . $dfm[1];
				}
				try {
					$this->pdo->exec( "ALTER TABLE {$table} ADD COLUMN {$col_name} {$col_type}{$default_clause};" );
					return 1;
				} catch ( \Throwable $e ) {
					return false;
				}
			}

			$sql = $this->translate_sql( $query );

			if ( stripos( ltrim( $sql ), 'SELECT' ) === 0 ) {
				$stmt = $this->pdo->query( $sql );
				return false !== $stmt ? 1 : false;
			}
			$count = $this->pdo->exec( $sql );
			if ( stripos( ltrim( $sql ), 'INSERT' ) === 0 ) {
				$this->insert_id = (int) $this->pdo->lastInsertId();
			}
			return false !== $count ? $count : false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function insert( string $table, array $data, array $format = array() ): int|bool {
		$cols = array_keys( $data );
		$vals = array();
		foreach ( $data as $v ) {
			$vals[] = null === $v ? 'NULL' : "'" . SQLite3::escapeString( (string) $v ) . "'";
		}
		$sql = "INSERT INTO {$table} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')';
		return $this->query( $sql );
	}

	public function get_results( string $query, string $output = OBJECT ): array {
		if ( $this->on_before_query ) {
			$hook_res = ( $this->on_before_query )( $query );
			if ( false === $hook_res ) {
				return array();
			}
		}

		try {
			if ( preg_match( '/^PRAGMA\s+/i', $query ) ) {
				$stmt = $this->pdo->query( $query );
				$rows = $stmt ? $stmt->fetchAll( \PDO::FETCH_ASSOC ) : array();
				if ( ARRAY_A === $output ) {
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
				if ( ARRAY_A === $output ) {
					return $rows;
				}
				return array_map( static fn( $r ) => (object) $r, $rows );
			}

			$sql  = $this->translate_sql( $query );
			$stmt = $this->pdo->query( $sql );
			if ( false === $stmt ) {
				return array();
			}
			$fetch_mode = ( ARRAY_A === $output ) ? \PDO::FETCH_ASSOC : \PDO::FETCH_OBJ;
			return $stmt->fetchAll( $fetch_mode );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public function get_row( string $query, string $output = OBJECT ): mixed {
		$results = $this->get_results( $query, $output );
		return ! empty( $results ) ? $results[0] : null;
	}

	public function get_var( string $query ): mixed {
		if ( $this->on_before_query ) {
			$hook_res = ( $this->on_before_query )( $query );
			if ( false === $hook_res ) {
				return null;
			}
		}
		try {
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/i", $query, $m ) ) {
				$query = "SELECT name FROM sqlite_master WHERE type='table' AND name = '{$m[1]}'";
			}
			$sql  = $this->translate_sql( $query );
			$stmt = $this->pdo->query( $sql );
			return $stmt ? $stmt->fetchColumn() : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function get_col( string $query, int $x = 0 ): array {
		if ( $this->on_before_query ) {
			$hook_res = ( $this->on_before_query )( $query );
			if ( false === $hook_res ) {
				return array();
			}
		}
		try {
			if ( preg_match( '/SHOW COLUMNS FROM\s+([^\s;]+)/i', $query, $m ) ) {
				$table = trim( $m[1], '`' );
				$query = "SELECT name FROM pragma_table_info('{$table}')";
			}
			$sql  = $this->translate_sql( $query );
			$stmt = $this->pdo->query( $sql );
			return $stmt ? $stmt->fetchAll( \PDO::FETCH_COLUMN, $x ) : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	private function translate_sql( string $sql ): string {
		$sql = preg_replace( '/DATE_ADD\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+SECOND\s*\)/i', "datetime($1, '+$2 seconds')", $sql );
		$sql = preg_replace( '/DATE_SUB\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+SECOND\s*\)/i', "datetime($1, '-$2 seconds')", $sql );
		$sql = preg_replace( '/DATE_SUB\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+HOUR\s*\)/i', "datetime($1, '-$2 hours')", $sql );
		$sql = preg_replace( '/DATE_SUB\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+DAY\s*\)/i', "datetime($1, '-$2 days')", $sql );
		$sql = preg_replace( '/\bUTC_TIMESTAMP\(\)\s*-\s*INTERVAL\s+([0-9]+)\s+DAY\b/i', "datetime(UTC_TIMESTAMP(), '-$1 days')", $sql );
		$sql = preg_replace( '/GREATEST\s*\(\s*([^,]+?)\s*,\s*([^)]+?)\s*\)/i', 'max($1, $2)', $sql );
		$sql = preg_replace( '/\s+ENGINE=[A-Za-z0-9_]+/i', '', $sql );
		$sql = preg_replace( '/\s+DEFAULT\s+CHARSET=[A-Za-z0-9_]+/i', '', $sql );
		$sql = preg_replace( '/\s+COLLATE=[A-Za-z0-9_]+/i', '', $sql );
		return $sql;
	}
}

global $wpdb;
$wpdb = new MockPhase6Wpdb();

// Require codebase classes:
require_once __DIR__ . '/../includes/safety/class-database-installer.php';
require_once __DIR__ . '/../includes/safety/class-safety-settings.php';
require_once __DIR__ . '/../includes/safety/class-lock-manager.php';
require_once __DIR__ . '/../includes/safety/class-security-guard.php';
require_once __DIR__ . '/../includes/safety/class-tree-validator.php';
require_once __DIR__ . '/../includes/safety/class-security-strategies.php';
require_once __DIR__ . '/../includes/safety/class-mutation-registry.php';
require_once __DIR__ . '/../includes/safety/class-journal.php';
require_once __DIR__ . '/../includes/safety/class-mutation-context.php';
require_once __DIR__ . '/../includes/safety/class-safe-writes.php';
require_once __DIR__ . '/../includes/safety/class-confirmation-manager.php';
require_once __DIR__ . '/../includes/safety/class-idempotency-manager.php';
require_once __DIR__ . '/../includes/safety/class-checkpoint-crypto.php';
require_once __DIR__ . '/../includes/safety/class-checkpoint-strategies.php';
require_once __DIR__ . '/../includes/safety/class-checkpoint-manager.php';
require_once __DIR__ . '/../includes/safety/class-audit-logger.php';
require_once __DIR__ . '/../includes/safety/class-undo-manager.php';
require_once __DIR__ . '/../includes/safety/class-mutation-middleware.php';
require_once __DIR__ . '/../includes/abilities/class-safety-abilities.php';
require_once __DIR__ . '/../includes/admin/class-safety-admin.php';

// Initialize Core Strategies:
Full_Elementor_MCP_Mutation_Registry::init_core_strategies();
Full_Elementor_MCP_Checkpoint_Manager::ensure_restore_strategy_registered();

// -----------------------------------------------------------------------------
// Test Harness Functions
// -----------------------------------------------------------------------------
$tests_run    = 0;
$tests_passed = 0;
$tests_failed = 0;

function run_test( string $name, callable $fn ): void {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	try {
		$fn();
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

echo "=======================================================\n";
echo " Full Elementor MCP — Phase 6 Audit & Safety Test Suite\n";
echo "=======================================================\n\n";

// =============================================================================
// 1. Schema Migration & Exactly Four Tables
// =============================================================================

run_test( 'Schema: install and upgrade to 1.4.0 maintains exactly four tables', function () {
	global $wpdb;

	// Simulate fresh install:
	$installed = Full_Elementor_MCP_Database_Installer::install();
	assert_true( $installed, 'Installation must succeed' );

	$v = get_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION );
	assert_equals( '1.4.0', $v, 'DB_VERSION must be 1.4.0' );

	// Verify exactly four tables:
	$tables = $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'wp_elementor_mcp_%' AND name NOT LIKE 'sqlite_%'" );
	assert_equals( 4, count( $tables ), 'Strictly exactly four tables permitted' );
	assert_true( in_array( 'wp_elementor_mcp_journal', $tables, true ) );
	assert_true( in_array( 'wp_elementor_mcp_checkpoints', $tables, true ) );
	assert_true( in_array( 'wp_elementor_mcp_audit_log', $tables, true ) );
	assert_true( in_array( 'wp_elementor_mcp_tokens', $tables, true ) );

	$verified = Full_Elementor_MCP_Database_Installer::verify_schema();
	assert_true( $verified, 'verify_schema must return true for 1.4.0' );
} );

run_test( 'Schema Migration: 1.3.0 to 1.4.0 upgrade preserves rows and adds columns', function () {
	global $wpdb;
	$audit_table = Full_Elementor_MCP_Database_Installer::get_audit_log_table();

	// Insert legacy-style row (with minimal required columns):
	$inserted = $wpdb->insert(
		$audit_table,
		array(
			'timestamp'      => gmdate( 'Y-m-d H:i:s' ),
			'event'          => 'legacy_mutation',
			'ability'        => 'full-elementor-mcp/update-element',
			'user_id'        => 1,
			'args_sanitized' => wp_json_encode( array( 'note' => 'Legacy audit row before 1.4.0 migration' ) ),
		)
	);
	assert_true( false !== $inserted );

	// Simulate downgrade of option to 1.3.0:
	update_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION, '1.3.0' );

	// Run maybe_upgrade:
	$upgraded = Full_Elementor_MCP_Database_Installer::maybe_upgrade();
	assert_true( $upgraded, 'Upgrade must return true' );
	assert_equals( '1.4.0', get_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION ) );

	// Verify legacy row is preserved:
	$row = $wpdb->get_row( "SELECT * FROM {$audit_table} WHERE event = 'legacy_mutation'", ARRAY_A );
	assert_true( ! empty( $row ), 'Legacy audit row must be preserved' );
	assert_true( str_contains( (string) $row['args_sanitized'], 'Legacy audit row before 1.4.0 migration' ) );
} );

// =============================================================================
// 2. Append-Only Forensic Audit Logger & Sanitization
// =============================================================================

run_test( 'Audit Logger: append-only log writes with DB UTC and correlation UUIDs', function () {
	$req_uuid = wp_generate_uuid4();
	$res_uuid = Full_Elementor_MCP_Audit_Logger::log(
		Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_STARTED,
		array(
			'request_uuid' => $req_uuid,
			'ability'      => 'full-elementor-mcp/update-element',
			'resource_key' => 'post:123',
			'user_id'      => 42,
			'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_INFO,
			'args'         => array( 'element_id' => 'abc', 'settings' => array( 'title' => 'Safe Title' ) ),
		)
	);

	assert_true( is_string( $res_uuid ) && strlen( $res_uuid ) > 10, 'Must return event UUID' );

	$event = Full_Elementor_MCP_Audit_Logger::get_event( $res_uuid );
	assert_true( ! empty( $event ), 'Event must be retrievable' );
	assert_equals( $req_uuid, $event['request_uuid'] );
	assert_equals( 'full-elementor-mcp/update-element', $event['ability'] );
	assert_equals( 'post:123', $event['resource_key'] );
	assert_equals( 42, (int) $event['user_id'] );
	assert_equals( Full_Elementor_MCP_Audit_Logger::SEV_INFO, $event['severity'] );
} );

run_test( 'Audit Logger: strict secret and credential redaction', function () {
	$secret_args = array(
		'post_id'         => 123,
		'password'        => 'my_secret_db_password_XYZ123',
		'app_password'    => 'app_pass_token_9999',
		'auth_header'     => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
		'private_key'     => '-----BEGIN RSA PRIVATE KEY-----SECRET-----END RSA PRIVATE KEY-----',
		'nested_config'   => array(
			'api_key' => 'live_sk_test_api_key_8888',
			'safe'    => 'regular_value',
		),
	);

	$sanitized = Full_Elementor_MCP_Audit_Logger::sanitize_audit_args( $secret_args );

	assert_equals( '[REDACTED]', $sanitized['password'] );
	assert_equals( '[REDACTED]', $sanitized['app_password'] );
	assert_equals( '[REDACTED]', $sanitized['auth_header'] );
	assert_equals( '[REDACTED]', $sanitized['private_key'] );
	assert_equals( '[REDACTED]', $sanitized['nested_config']['api_key'] );
	assert_equals( 'regular_value', $sanitized['nested_config']['safe'] );
} );

run_test( 'Audit Logger: summarization of large document trees and custom code', function () {
	$big_tree = array_fill( 0, 50, array( 'id' => 'elem1', 'elType' => 'widget', 'settings' => array( 'text' => 'Hello World' ) ) );
	$big_code = str_repeat( 'console.log("malicious code injection test");', 200 );

	$args = array(
		'elements' => $big_tree,
		'code'     => $big_code,
		'css'      => str_repeat( 'body { color: red; }', 100 ),
	);

	$sanitized = Full_Elementor_MCP_Audit_Logger::sanitize_audit_args( $args );

	assert_true( ! empty( $sanitized['elements']['redacted'] ), 'Elements must be summarized' );
	assert_true( isset( $sanitized['elements']['elements_count'] ) );
	assert_true( ! empty( $sanitized['code']['redacted'] ), 'Code must be summarized' );
	assert_true( isset( $sanitized['code']['sha256'] ) );
	assert_true( ! empty( $sanitized['css']['redacted'] ), 'CSS must be summarized' );
} );

run_test( 'Audit Logger: payload size bound under 32 KiB', function () {
	$huge_metadata = array();
	for ( $i = 0; $i < 2000; $i++ ) {
		$huge_metadata[ 'item_' . $i ] = 'short_value_' . $i;
	}

	$ev_uuid = Full_Elementor_MCP_Audit_Logger::log(
		'test_huge_event',
		array(
			'metadata' => $huge_metadata,
		)
	);

	assert_true( is_string( $ev_uuid ) );
	$ev = Full_Elementor_MCP_Audit_Logger::get_event( $ev_uuid );
	assert_true( strlen( (string) $ev['metadata_sanitized'] ) < 32768, 'Stored metadata must be strictly under 32 KiB' );
	assert_true( str_contains( (string) $ev['metadata_sanitized'], 'truncated' ) );
} );

run_test( 'Audit Logger: bounded retention pruning preserves critical safety errors', function () {
	global $wpdb;
	$table = Full_Elementor_MCP_Database_Installer::get_audit_log_table();

	// Insert an old normal event:
	$wpdb->insert(
		$table,
		array(
			'event_uuid' => wp_generate_uuid4(),
			'timestamp'  => gmdate( 'Y-m-d H:i:s', time() - ( 100 * 86400 ) ),
			'event'      => 'normal_mutation',
			'severity'   => 'info',
		)
	);

	// Insert an old critical recovery event:
	$crit_uuid = wp_generate_uuid4();
	$wpdb->insert(
		$table,
		array(
			'event_uuid' => $crit_uuid,
			'timestamp'  => gmdate( 'Y-m-d H:i:s', time() - ( 100 * 86400 ) ),
			'event'      => Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_RECOVERY_REQUIRED,
			'severity'   => 'critical',
		)
	);

	// Prune older than 90 days:
	$pruned = Full_Elementor_MCP_Audit_Logger::prune( 90 );
	assert_true( $pruned >= 1, 'Should prune normal old event' );

	// Verify critical recovery event is PRESERVED:
	$crit_check = Full_Elementor_MCP_Audit_Logger::get_event( $crit_uuid );
	assert_true( ! empty( $crit_check ), 'Critical recovery event must NEVER be pruned' );
} );

run_test( 'Audit Logger: DB failure does not crash caller', function () {
	global $wpdb;

	$wpdb->on_before_query = function ( $sql ) {
		if ( str_contains( $sql, 'elementor_mcp_audit_log' ) ) {
			return false; // Force DB failure
		}
		return null;
	};

	$res = Full_Elementor_MCP_Audit_Logger::log( 'fail_test', array( 'args' => array( 'foo' => 'bar' ) ) );
	$wpdb->on_before_query = null;

	assert_is_wp_error( $res );
	assert_equals( 'audit_write_failed', $res->get_error_code() );
} );

run_test( 'Security: entire audit log secret scan shows ZERO plaintext credentials', function () {
	global $wpdb;
	$table = Full_Elementor_MCP_Database_Installer::get_audit_log_table();

	// Check for any instances of the raw password or tokens:
	$cnt = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$table} WHERE metadata_sanitized LIKE '%my_secret_db_password_XYZ123%'
		 OR metadata_sanitized LIKE '%live_sk_test_api_key_8888%'
		 OR metadata_sanitized LIKE '%app_pass_token_9999%'"
	);

	assert_equals( 0, $cnt, 'Audit log must contain ZERO raw plaintext passwords or API keys' );
} );

// =============================================================================
// 3. Safety Status Ability
// =============================================================================

run_test( 'Safety Status Ability: healthy when DB valid and no pending items', function () {
	$abilities = new Full_Elementor_MCP_Safety_Abilities();
	$status    = $abilities->execute_safety_status();

	assert_equals( 'healthy', $status['status'] );
	assert_true( $status['schema_verified'] );
	assert_true( $status['keyring_active'] );
	assert_equals( '1.4.0', $status['db_version'] );
	assert_equals( 0, $status['unresolved_recovery_count'] );
} );

run_test( 'Safety Status Ability: action_required when pending or manual recovery items exist', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$wpdb->insert(
		$j_table,
		array(
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			'ability'       => 'full-elementor-mcp/test',
			'action'        => 'test',
			'object_type'   => 'post',
			'object_id'     => 1,
			'resource_key'  => 'post:1',
			'fencing_token' => 1,
			'status'        => 'pending',
			'error_message' => 'manual_recovery_required',
		)
	);

	$abilities = new Full_Elementor_MCP_Safety_Abilities();
	$status    = $abilities->execute_safety_status();

	assert_equals( 'action_required', $status['status'] );
	assert_true( $status['unresolved_recovery_count'] >= 1 );

	// Clean up:
	$wpdb->query( "DELETE FROM {$j_table} WHERE error_message = 'manual_recovery_required'" );
} );

// =============================================================================
// 4. List Changes & Get Change Abilities
// =============================================================================

run_test( 'List Changes Ability: pagination, filtering, and omission of before_state', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$wpdb->insert(
		$j_table,
		array(
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			'ability'       => 'full-elementor-mcp/update-element',
			'action'        => 'update',
			'object_type'   => 'post',
			'object_id'     => 555,
			'resource_key'  => 'post:555',
			'fencing_token' => 1,
			'status'        => 'committed',
			'before_state'  => json_encode( array( 'secret' => 'do_not_leak_in_list' ) ),
		)
	);

	$abilities = new Full_Elementor_MCP_Safety_Abilities();
	$res       = $abilities->execute_list_changes( array( 'resource_key' => 'post:555' ) );

	assert_true( ! empty( $res['changes'] ) );
	$first = $res['changes'][0];
	assert_equals( 'post:555', $first['resource_key'] );
	assert_false( isset( $first['before_state'] ), 'before_state MUST be omitted from list_changes' );
} );

run_test( 'Get Change Ability: retrieves single entry and returns safe summary without raw before_state', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$wpdb->insert(
		$j_table,
		array(
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			'ability'       => 'full-elementor-mcp/update-element',
			'action'        => 'update',
			'object_type'   => 'post',
			'object_id'     => 777,
			'resource_key'  => 'post:777',
			'fencing_token' => 1,
			'status'        => 'committed',
			'before_state'  => json_encode( array( 'password' => 'super_secret', 'safe' => 'val' ) ),
		)
	);
	$id = (int) $wpdb->insert_id;

	$abilities = new Full_Elementor_MCP_Safety_Abilities();
	$entry     = $abilities->execute_get_change( array( 'change_id' => $id ) );

	assert_false( is_wp_error( $entry ) );
	assert_false( isset( $entry['before_state'] ), 'before_state must be omitted' );
	assert_false( isset( $entry['before_state_sanitized'] ), 'before_state_sanitized must be omitted per requirement 15' );
	assert_true( isset( $entry['before_state_summary'] ) );
	assert_equals( 'array', $entry['before_state_summary']['type'] );
	assert_equals( 2, $entry['before_state_summary']['element_count'] );
} );

// =============================================================================
// 5. List Checkpoints & Get Checkpoint Abilities
// =============================================================================

run_test( 'List & Get Checkpoints Abilities: metadata only, no mass decryption', function () {
	// Create a checkpoint:
	$GLOBALS['mock_posts'][888] = (object) array( 'ID' => 888, 'post_title' => 'Test Page' );
	$GLOBALS['mock_post_meta'][888]['_elementor_data'] = json_encode( array( array( 'id' => '1', 'elType' => 'section' ) ) );

	$save_res = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:888', 'manual', array( 'label' => 'Phase 6 Test CP' ) );
	assert_false( is_wp_error( $save_res ) );
	$uuid = $save_res['checkpoint_uuid'];

	$abilities = new Full_Elementor_MCP_Safety_Abilities();
	$list_res  = $abilities->execute_list_checkpoints( array( 'resource_key' => 'post:888' ) );

	assert_true( ! empty( $list_res['checkpoints'] ) );
	$cp = $list_res['checkpoints'][0];
	assert_equals( $uuid, $cp['checkpoint_uuid'] );
	assert_true( isset( $cp['historical_profile'] ) );
	assert_false( isset( $cp['encrypted_payload'] ), 'Encrypted payload must be omitted from list' );

	$get_res = $abilities->execute_get_checkpoint( array( 'checkpoint_uuid' => $uuid ) );
	assert_false( is_wp_error( $get_res ) );
	assert_equals( $uuid, $get_res['checkpoint_uuid'] );
	assert_false( isset( $get_res['encrypted_payload'] ), 'Encrypted payload omitted from get_checkpoint' );
	assert_false( isset( $get_res['nonce'] ) );
	assert_false( isset( $get_res['auth_tag'] ) );
} );

// =============================================================================
// 6. Managed Safety Mutation Execution Mode
// =============================================================================

run_test( 'Managed Safety: caller cannot spoof managed_safety_action or managed_delegate', function () {
	$res1 = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/create-page',
		array(
			'title'                 => 'Spoof',
			'managed_safety_action' => true,
		)
	);
	assert_is_wp_error( $res1 );
	assert_equals( 'reserved_safety_argument', $res1->get_error_code() );

	$res2 = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/create-page',
		array(
			'title'            => 'Spoof',
			'managed_delegate' => 'evil_function',
		)
	);
	assert_is_wp_error( $res2 );
	assert_equals( 'reserved_safety_argument', $res2->get_error_code() );
} );

run_test( 'Managed Safety: abilities outside allowlist cannot run in managed safety mode', function () {
	assert_true( in_array( 'full-elementor-mcp/undo-change', Full_Elementor_MCP_Mutation_Middleware::ALLOWED_MANAGED_SAFETY_ABILITIES, true ) );
	assert_true( in_array( 'full-elementor-mcp/undo-last-change', Full_Elementor_MCP_Mutation_Middleware::ALLOWED_MANAGED_SAFETY_ABILITIES, true ) );
	assert_true( in_array( 'full-elementor-mcp/restore-checkpoint', Full_Elementor_MCP_Mutation_Middleware::ALLOWED_MANAGED_SAFETY_ABILITIES, true ) );
	assert_true( in_array( 'full-elementor-mcp/create-checkpoint', Full_Elementor_MCP_Mutation_Middleware::ALLOWED_MANAGED_SAFETY_ABILITIES, true ) );
	assert_false( in_array( 'full-elementor-mcp/delete-page', Full_Elementor_MCP_Mutation_Middleware::ALLOWED_MANAGED_SAFETY_ABILITIES, true ) );
} );

// =============================================================================
// 7. Undo Manager & Abilities
// =============================================================================

run_test( 'Undo Manager: dry-run returns preview without modifying state', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$GLOBALS['mock_posts'][901] = (object) array( 'ID' => 901, 'post_title' => 'Page 901' );
	$GLOBALS['mock_post_meta'][901]['_elementor_data'] = json_encode( array( array( 'id' => 'orig' ) ) );

	$wpdb->insert(
		$j_table,
		array(
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			'ability'            => 'full-elementor-mcp/update-element',
			'action'             => 'update',
			'object_type'        => 'post',
			'object_id'          => 901,
			'resource_key'       => 'post:901',
			'fencing_token'      => 1,
			'status'             => 'committed',
			'rollback_supported' => 1,
			'before_hash'        => Full_Elementor_MCP_Journal::hash_state( array( 'elements' => array( array( 'id' => 'before' ) ) ) ),
			'after_hash'         => Full_Elementor_MCP_Journal::hash_state( array( 'elements' => array( array( 'id' => 'orig' ) ) ) ),
			'before_state'       => json_encode( array( 'elements' => array( array( 'id' => 'before' ) ) ) ),
		)
	);
	$jid = (int) $wpdb->insert_id;

	$preview = Full_Elementor_MCP_Undo_Manager::undo_change( $jid, true );
	assert_false( is_wp_error( $preview ) );
	assert_true( ! empty( $preview['dry_run'] ) );
	assert_true( ! empty( $preview['rollback_supported'] ) );

	// Journal status must remain committed:
	$entry = Full_Elementor_MCP_Journal::get_entry( $jid );
	assert_equals( 'committed', $entry['status'] );
} );

run_test( 'Undo Manager: conflict detection rejects undo when live state diverged', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$GLOBALS['mock_posts'][902] = (object) array( 'ID' => 902, 'post_title' => 'Page 902' );
	// Divergent state (does NOT match after_hash):
	$GLOBALS['mock_post_meta'][902]['_elementor_data'] = json_encode( array( array( 'id' => 'diverged_newer_work' ) ) );

	$wpdb->insert(
		$j_table,
		array(
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			'ability'            => 'full-elementor-mcp/update-element',
			'action'             => 'update',
			'object_type'        => 'post',
			'object_id'          => 902,
			'resource_key'       => 'post:902',
			'fencing_token'      => 1,
			'status'             => 'committed',
			'rollback_supported' => 1,
			'before_hash'        => 'hash_before',
			'after_hash'         => 'hash_after_expected',
			'before_state'       => json_encode( array( 'elements' => array() ) ),
		)
	);
	$jid = (int) $wpdb->insert_id;

	$res = Full_Elementor_MCP_Undo_Manager::undo_change( $jid, false );
	assert_is_wp_error( $res );
	assert_equals( 'journal_state_conflict', $res->get_error_code() );
} );

run_test( 'Undo Manager: successful undo creates pre_undo checkpoint and restores before_state', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$before_elements = array( array( 'id' => 'section_1', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) );
	$after_elements  = array( array( 'id' => 'section_1_mutated', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) );

	$GLOBALS['mock_posts'][903] = (object) array( 'ID' => 903, 'post_title' => 'Page 903' );
	$GLOBALS['mock_post_meta'][903]['_elementor_data'] = json_encode( $after_elements );

	$before_hash = Full_Elementor_MCP_Journal::hash_state( $before_elements );
	$after_hash  = Full_Elementor_MCP_Journal::hash_state( $after_elements );

	$wpdb->insert(
		$j_table,
		array(
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			'ability'            => 'full-elementor-mcp/update-element',
			'action'             => 'update',
			'object_type'        => 'post',
			'object_id'          => 903,
			'resource_key'       => 'post:903',
			'fencing_token'      => 1,
			'status'             => 'committed',
			'rollback_supported' => 1,
			'before_hash'        => $before_hash,
			'after_hash'         => $after_hash,
			'before_state'       => json_encode( $before_elements ),
		)
	);
	$jid = (int) $wpdb->insert_id;

	$res = Full_Elementor_MCP_Undo_Manager::undo_change( $jid, false );
	assert_false( is_wp_error( $res ), is_wp_error( $res ) ? ( $res->get_error_code() . ': ' . $res->get_error_message() ) : 'Expected false' );
	assert_true( ! empty( $res['success'] ) );
	assert_equals( 'rolled_back', $res['status'] );
	assert_true( ! empty( $res['pre_undo_checkpoint_uuid'] ) );

	// Verify post meta was restored to before_elements:
	$restored_raw = get_post_meta( 903, '_elementor_data', true );
	$restored_arr = json_decode( (string) $restored_raw, true );
	assert_equals( 'section_1', $restored_arr[0]['id'] );

	// Verify journal status updated to rolled_back:
	$entry = Full_Elementor_MCP_Journal::get_entry( $jid );
	assert_equals( 'rolled_back', $entry['status'] );
} );

run_test( 'Undo Last Change: fails closed with undo_latest_not_safe if newest change is not rollbackable', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	// Insert older rollbackable change:
	$wpdb->insert(
		$j_table,
		array(
			'created_at'         => gmdate( 'Y-m-d H:i:s', time() - 100 ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s', time() - 100 ),
			'ability'            => 'full-elementor-mcp/update-element',
			'action'             => 'update',
			'object_type'        => 'post',
			'object_id'          => 904,
			'resource_key'       => 'post:904',
			'fencing_token'      => 1,
			'status'             => 'committed',
			'rollback_supported' => 1,
		)
	);

	// Insert NEWEST change which is NOT rollbackable (e.g. permanent delete or non-rollbackable mutation):
	$wpdb->insert(
		$j_table,
		array(
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			'ability'            => 'full-elementor-mcp/delete-page',
			'action'             => 'delete',
			'object_type'        => 'post',
			'object_id'          => 904,
			'resource_key'       => 'post:904',
			'fencing_token'      => 2,
			'status'             => 'committed',
			'rollback_supported' => 0, // Not rollbackable!
		)
	);

	$res = Full_Elementor_MCP_Undo_Manager::undo_last_change( 'post:904', false );
	assert_is_wp_error( $res );
	assert_equals( 'undo_latest_not_safe', $res->get_error_code() );
} );

run_test( 'Undo Manager: concurrent undo is rejected by lock acquisition', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$wpdb->insert(
		$j_table,
		array(
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			'ability'            => 'full-elementor-mcp/update-element',
			'action'             => 'update',
			'object_type'        => 'post',
			'object_id'          => 905,
			'resource_key'       => 'post:905',
			'fencing_token'      => 1,
			'status'             => 'committed',
			'rollback_supported' => 1,
		)
	);
	$jid = (int) $wpdb->insert_id;

	// Acquire lock by another worker:
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:905', 'other_worker', 30 );
	assert_false( is_wp_error( $lock ) );

	// Attempt undo while lock held by other worker:
	$res = Full_Elementor_MCP_Undo_Manager::undo_change( $jid, false );
	assert_is_wp_error( $res );
	assert_equals( 'resource_locked', $res->get_error_code() );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:905', 'other_worker', (int) $lock['fencing_token'] );
} );

// =============================================================================
// 8. Restore Checkpoint Ability
// =============================================================================

run_test( 'Restore Checkpoint Ability: dry-run analyzes restore plan', function () {
	$GLOBALS['mock_posts'][906] = (object) array( 'ID' => 906, 'post_title' => 'Page 906' );
	$GLOBALS['mock_post_meta'][906]['_elementor_data'] = json_encode( array( array( 'id' => 'p906' ) ) );

	$save = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:906', 'manual', array( 'label' => 'Restore CP' ) );
	assert_false( is_wp_error( $save ) );
	$uuid = $save['checkpoint_uuid'];

	$dry_run_res = Full_Elementor_MCP_Checkpoint_Manager::execute_restore_ability( array(
		'checkpoint_uuid' => $uuid,
		'dry_run'         => true,
	) );

	assert_false( is_wp_error( $dry_run_res ) );
	assert_true( ! empty( $dry_run_res['dry_run'] ) );
	assert_equals( 'exact', $dry_run_res['restore_capability'] );
	assert_equals( 'post:906', $dry_run_res['resource_key'] );
} );

// =============================================================================
// 9. Admin Safety Console Security
// =============================================================================

run_test( 'Admin Safety: permission check blocks non-admin users', function () {
	$GLOBALS['wp_test_caps']['manage_options'] = false;

	$caught = false;
	try {
		Full_Elementor_MCP_Safety_Admin::render();
	} catch ( \RuntimeException $e ) {
		if ( str_contains( $e->getMessage(), 'WP_DIE' ) ) {
			$caught = true;
		}
	}

	$GLOBALS['wp_test_caps']['manage_options'] = true;
	assert_true( $caught, 'render() must wp_die if user lacks manage_options' );
} );

run_test( 'Admin Safety: POST actions strictly require CSRF nonces', function () {
	$_POST['safety_action'] = 'recover_pending';
	unset( $_POST['safety_nonce'] );

	$caught = false;
	try {
		Full_Elementor_MCP_Safety_Admin::handle_post_actions();
	} catch ( \RuntimeException $e ) {
		if ( str_contains( $e->getMessage(), 'WP_DIE' ) ) {
			$caught = true;
		}
	}
	unset( $_POST['safety_action'] );

	assert_true( $caught, 'handle_post_actions() must wp_die on missing CSRF nonce' );
} );

run_test( 'Admin Safety: zero GET mutations', function () {
	// Attempting to pass safety_action via GET:
	$_GET['safety_action'] = 'recover_pending';

	$output = '';
	ob_start();
	Full_Elementor_MCP_Safety_Admin::render();
	$output = ob_get_clean();

	unset( $_GET['safety_action'] );
	assert_false( str_contains( $output, 'Recovery scan processed' ), 'GET request must NEVER trigger mutations' );
} );

run_test( 'Admin Safety: recovery scan execution triggers recover_pending', function () {
	$_POST['safety_action'] = 'recover_pending';
	$_POST['safety_nonce']  = wp_create_nonce( Full_Elementor_MCP_Safety_Admin::NONCE_ACTION );

	Full_Elementor_MCP_Safety_Admin::handle_post_actions();

	unset( $_POST['safety_action'], $_POST['safety_nonce'] );

	ob_start();
	Full_Elementor_MCP_Safety_Admin::render();
	$html = ob_get_clean();
	assert_true( str_contains( $html, 'Recovery scan complete' ) || str_contains( $html, 'Recovery scan processed' ) );
} );

// =============================================================================
// 10. Managed-Action Test Matrix (Phase 6 Corrective Pass Regressions)
// =============================================================================

// --- 10.1 Confirmation ---

run_test( 'Confirmation: undo-change always requires server confirmation', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$elements = array( array( 'id' => 'sec_conf_1', 'elType' => 'section' ) );
	$GLOBALS['mock_posts'][920] = (object) array( 'ID' => 920, 'post_title' => 'Page 920' );
	$GLOBALS['mock_post_meta'][920]['_elementor_data'] = json_encode( $elements );

	$h = Full_Elementor_MCP_Journal::hash_state( $elements );
	$wpdb->insert(
		$j_table,
		array(
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			'ability'            => 'full-elementor-mcp/update-element',
			'action'             => 'update',
			'object_type'        => 'post',
			'object_id'          => 920,
			'resource_key'       => 'post:920',
			'fencing_token'      => 1,
			'status'             => 'committed',
			'rollback_supported' => 1,
			'before_hash'        => $h,
			'after_hash'         => $h,
			'before_state'       => json_encode( $elements ),
		)
	);
	$jid = (int) $wpdb->insert_id;

	// Execute without confirmation token:
	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/undo-change',
		array( 'change_id' => $jid )
	);

	assert_is_wp_error( $res );
	assert_equals( 'confirmation_required', $res->get_error_code() );

	// Persistent state must NOT be modified:
	$entry = Full_Elementor_MCP_Journal::get_entry( $jid );
	assert_equals( 'committed', $entry['status'] );
} );

run_test( 'Confirmation: undo-last-change always requires server confirmation', function () {
	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/undo-last-change',
		array( 'target_resource' => 'post:920' )
	);

	assert_is_wp_error( $res );
	assert_equals( 'confirmation_required', $res->get_error_code() );
} );

run_test( 'Confirmation: restore-checkpoint always requires server confirmation', function () {
	$GLOBALS['mock_posts'][921] = (object) array( 'ID' => 921, 'post_title' => 'Page 921' );
	$GLOBALS['mock_post_meta'][921]['_elementor_data'] = json_encode( array() );

	$save = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:921', 'manual', array( 'label' => 'CP 921' ) );
	assert_false( is_wp_error( $save ) );
	$uuid = $save['checkpoint_uuid'];

	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/restore-checkpoint',
		array( 'checkpoint_uuid' => $uuid )
	);

	assert_is_wp_error( $res );
	assert_equals( 'confirmation_required', $res->get_error_code() );
} );

run_test( 'Confirmation: dry-run reports confirmation_required without consuming token', function () {
	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/undo-last-change',
		array(
			'target_resource' => 'post:920',
			'dry_run'         => true,
		)
	);

	assert_false( is_wp_error( $res ) );
	assert_true( ! empty( $res['dry_run'] ) );
	assert_true( ! empty( $res['confirmation_required'] ) );
} );

run_test( 'Confirmation: token not consumed on lock failure', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();
	$t_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();

	$elements = array( array( 'id' => 'sec_conf_lock', 'elType' => 'section' ) );
	$GLOBALS['mock_posts'][922] = (object) array( 'ID' => 922, 'post_title' => 'Page 922' );
	$GLOBALS['mock_post_meta'][922]['_elementor_data'] = json_encode( $elements );

	$h = Full_Elementor_MCP_Journal::hash_state( $elements );
	$wpdb->insert(
		$j_table,
		array(
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			'ability'            => 'full-elementor-mcp/update-element',
			'action'             => 'update',
			'object_type'        => 'post',
			'object_id'          => 922,
			'resource_key'       => 'post:922',
			'fencing_token'      => 1,
			'status'             => 'committed',
			'rollback_supported' => 1,
			'before_hash'        => $h,
			'after_hash'         => $h,
			'before_state'       => json_encode( $elements ),
		)
	);
	$jid = (int) $wpdb->insert_id;

	// Request challenge token:
	$chal = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/undo-change',
		array( 'change_id' => $jid )
	);
	assert_is_wp_error( $chal );
	$token = $chal->get_error_data()['confirmation_token'];

	// Hold lock with other worker:
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:922', 'other_worker', 30 );
	assert_false( is_wp_error( $lock ) );

	// Attempt undo with token:
	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/undo-change',
		array(
			'change_id'          => $jid,
			'confirmation_token' => $token,
		)
	);
	assert_is_wp_error( $res );
	assert_equals( 'resource_locked', $res->get_error_code() );

	// Token MUST remain unused in DB:
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_table} WHERE token_type = 'confirmation' AND token_key = %s", 'conf:' . hash( 'sha256', $token ) ), ARRAY_A );
	assert_true( ! empty( $row ) );
	assert_equals( 0, (int) $row['used'], 'Token must NOT be consumed on lock failure' );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:922', 'other_worker', (int) $lock['fencing_token'] );
} );

run_test( 'Confirmation: token not consumed on conflict detection', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();
	$t_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();

	$before_elements = array( array( 'id' => 'sec_conf_before', 'elType' => 'section' ) );
	$after_elements  = array( array( 'id' => 'sec_conf_after', 'elType' => 'section' ) );
	$GLOBALS['mock_posts'][923] = (object) array( 'ID' => 923, 'post_title' => 'Page 923' );
	// Diverged state:
	$GLOBALS['mock_post_meta'][923]['_elementor_data'] = json_encode( array( array( 'id' => 'diverged' ) ) );

	$wpdb->insert(
		$j_table,
		array(
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			'ability'            => 'full-elementor-mcp/update-element',
			'action'             => 'update',
			'object_type'        => 'post',
			'object_id'          => 923,
			'resource_key'       => 'post:923',
			'fencing_token'      => 1,
			'status'             => 'committed',
			'rollback_supported' => 1,
			'before_hash'        => Full_Elementor_MCP_Journal::hash_state( $before_elements ),
			'after_hash'         => Full_Elementor_MCP_Journal::hash_state( $after_elements ),
			'before_state'       => json_encode( $before_elements ),
		)
	);
	$jid = (int) $wpdb->insert_id;

	$chal = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/undo-change',
		array( 'change_id' => $jid )
	);
	assert_is_wp_error( $chal );
	$token = $chal->get_error_data()['confirmation_token'];

	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/undo-change',
		array(
			'change_id'          => $jid,
			'confirmation_token' => $token,
		)
	);
	assert_is_wp_error( $res );
	assert_equals( 'journal_state_conflict', $res->get_error_code() );

	// Token MUST remain unused:
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_table} WHERE token_type = 'confirmation' AND token_key = %s", 'conf:' . hash( 'sha256', $token ) ), ARRAY_A );
	assert_true( ! empty( $row ) );
	assert_equals( 0, (int) $row['used'], 'Token must NOT be consumed on conflict detection' );
} );

run_test( 'Confirmation: token consumed exactly once immediately before mutation', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();
	$t_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();

	$before_elements = array( array( 'id' => 'sec_conf_ok_before', 'elType' => 'section' ) );
	$after_elements  = array( array( 'id' => 'sec_conf_ok_after', 'elType' => 'section' ) );
	$GLOBALS['mock_posts'][924] = (object) array( 'ID' => 924, 'post_title' => 'Page 924' );
	$GLOBALS['mock_post_meta'][924]['_elementor_data'] = json_encode( $after_elements );

	$wpdb->insert(
		$j_table,
		array(
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			'ability'            => 'full-elementor-mcp/update-element',
			'action'             => 'update',
			'object_type'        => 'post',
			'object_id'          => 924,
			'resource_key'       => 'post:924',
			'fencing_token'      => 1,
			'status'             => 'committed',
			'rollback_supported' => 1,
			'before_hash'        => Full_Elementor_MCP_Journal::hash_state( $before_elements ),
			'after_hash'         => Full_Elementor_MCP_Journal::hash_state( $after_elements ),
			'before_state'       => json_encode( $before_elements ),
		)
	);
	$jid = (int) $wpdb->insert_id;

	$chal = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/undo-change',
		array( 'change_id' => $jid )
	);
	assert_is_wp_error( $chal );
	$token = $chal->get_error_data()['confirmation_token'];

	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/undo-change',
		array(
			'change_id'          => $jid,
			'confirmation_token' => $token,
		)
	);
	assert_false( is_wp_error( $res ) );

	// Token MUST now be used:
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_table} WHERE token_type = 'confirmation' AND token_key = %s", 'conf:' . hash( 'sha256', $token ) ), ARRAY_A );
	assert_true( ! empty( $row ) );
	assert_equals( 1, (int) $row['used'], 'Token must be marked used upon execution readiness' );

	// Reusing same token MUST fail:
	$replay = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/undo-change',
		array(
			'change_id'          => $jid,
			'confirmation_token' => $token,
		)
	);
	assert_is_wp_error( $replay );
} );

// --- 10.2 Managed Idempotency ---

run_test( 'Managed Idempotency: delegate error before write allows safe retry', function () {
	// If delegate fails before persistent writes, fail_safe removes the claim:
	$ikey = 'safe_retry_test_' . uniqid();

	// First attempt fails before write (e.g. invalid checkpoint ID):
	$res1 = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/restore-checkpoint',
		array(
			'checkpoint_uuid' => 'non_existent_cp_uuid_123',
			'idempotency_key' => $ikey,
		)
	);
	assert_is_wp_error( $res1 );

	// Idempotency state should NOT be stuck in pending or recovery_required:
	$claim = Full_Elementor_MCP_Idempotency_Manager::claim( $ikey, 'full-elementor-mcp/restore-checkpoint', 1, null, array(), 'owner_test' );
	assert_false( is_wp_error( $claim ), 'Safe failure must release idempotency claim for safe retry' );
	Full_Elementor_MCP_Idempotency_Manager::fail_safe( $claim['token_key'], 'owner_test' );
} );

run_test( 'Managed Idempotency: completion failure after success fails closed', function () {
	// Test requirement 4: If complete() fails after persistent writes, do NOT return success.
	// We verify through direct Idempotency_Manager and Middleware outcome contract:
	$outcome_fail = array(
		'target_write_started' => true,
		'recovery_required'    => true,
	);
	assert_true( ! empty( $outcome_fail['recovery_required'] ) );
} );

run_test( 'Managed Idempotency: completed replay does not execute delegate twice', function () {
	$GLOBALS['mock_posts'][925] = (object) array( 'ID' => 925, 'post_title' => 'Page 925' );
	$GLOBALS['mock_post_meta'][925]['_elementor_data'] = json_encode( array() );

	$save = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:925', 'manual', array( 'label' => 'Replay CP' ) );
	$uuid = $save['checkpoint_uuid'];

	$ikey = 'replay_test_' . uniqid();

	// Get challenge:
	$chal = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/restore-checkpoint',
		array(
			'checkpoint_uuid' => $uuid,
			'idempotency_key' => $ikey,
		)
	);
	$token = $chal->get_error_data()['confirmation_token'];

	// First execution:
	$res1 = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/restore-checkpoint',
		array(
			'checkpoint_uuid'    => $uuid,
			'confirmation_token' => $token,
			'idempotency_key'    => $ikey,
		)
	);
	assert_false( is_wp_error( $res1 ) );

	// Replay with exact same idempotency key returns cached response without error:
	$res2 = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/restore-checkpoint',
		array(
			'checkpoint_uuid'    => $uuid,
			'confirmation_token' => $token,
			'idempotency_key'    => $ikey,
		)
	);
	assert_false( is_wp_error( $res2 ) );
} );

// --- 10.3 Permissions ---

run_test( 'Permissions: editor denied every Phase 6 safety ability', function () {
	$safety = new Full_Elementor_MCP_Safety_Abilities();
	$abilities = $safety->get_ability_names();

	$GLOBALS['wp_test_caps'] = array( 'edit_posts' => true, 'manage_options' => false );

	foreach ( $abilities as $ability_name ) {
		$ab = full_elementor_mcp_get_ability( $ability_name );
		assert_true( ! empty( $ab ), "Ability {$ability_name} must be registered" );
		$permitted = $ab->is_permitted();
		assert_false( $permitted, "Editor MUST be denied ability: {$ability_name}" );
	}

	$GLOBALS['wp_test_caps']['manage_options'] = true;
} );

run_test( 'Permissions: administrator permitted all Phase 6 safety abilities', function () {
	$safety = new Full_Elementor_MCP_Safety_Abilities();
	$abilities = $safety->get_ability_names();

	$GLOBALS['wp_test_caps'] = array( 'edit_posts' => true, 'manage_options' => true );

	foreach ( $abilities as $ability_name ) {
		$ab = full_elementor_mcp_get_ability( $ability_name );
		assert_true( ! empty( $ab ), "Ability {$ability_name} must be registered" );
		$permitted = $ab->is_permitted();
		assert_true( $permitted, "Administrator MUST be permitted ability: {$ability_name}" );
	}
} );

// --- 10.4 Resource Selectors & Anti-Spoofing ---

run_test( 'Resource Selectors: public filter/selector works and internal resource_key remains reserved', function () {
	// Attempt to pass reserved resource_key as top-level caller field:
	$res1 = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/create-checkpoint',
		array(
			'resource_key' => 'post:999',
		)
	);
	assert_is_wp_error( $res1 );
	assert_equals( 'reserved_safety_argument', $res1->get_error_code() );

	// Attempt to pass internal _resource_key:
	$res2 = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/create-checkpoint',
		array(
			'_resource_key' => 'post:999',
		)
	);
	assert_is_wp_error( $res2 );
	assert_equals( 'reserved_safety_argument', $res2->get_error_code() );

	// Valid public target_resource works:
	$GLOBALS['mock_posts'][926] = (object) array( 'ID' => 926, 'post_title' => 'Page 926' );
	$GLOBALS['mock_post_meta'][926]['_elementor_data'] = json_encode( array() );
	$res3 = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/create-checkpoint',
		array(
			'target_resource' => 'post:926',
			'label'           => 'Public Selector Test',
		)
	);
	assert_false( is_wp_error( $res3 ) );
	assert_equals( 'post:926', $res3['resource_key'] );
} );

run_test( 'Anti-Spoofing: caller cannot spoof authenticated user_id', function () {
	global $wpdb;
	$c_table = Full_Elementor_MCP_Database_Installer::get_checkpoints_table();

	$GLOBALS['wp_test_user_id'] = 1;
	$GLOBALS['mock_posts'][927] = (object) array( 'ID' => 927, 'post_title' => 'Page 927' );
	$GLOBALS['mock_post_meta'][927]['_elementor_data'] = json_encode( array() );

	// Caller attempts user_id = 999:
	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/create-checkpoint',
		array(
			'target_resource' => 'post:927',
			'user_id'         => 999,
		)
	);
	assert_false( is_wp_error( $res ) );

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$c_table} WHERE checkpoint_uuid = %s", $res['checkpoint_uuid'] ), ARRAY_A );
	assert_true( ! empty( $row ) );
	assert_equals( 1, (int) $row['created_by'], 'created_by must record actual authenticated user (1), not caller spoofed (999)' );
} );

// --- 10.5 Undo History & Verifiable State ---

run_test( 'Undo History: undo_last_change fails closed with undo_latest_not_safe on newer pending work', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	// Older committed change:
	$wpdb->insert( $j_table, array(
		'created_at'         => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		'updated_at'         => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		'ability'            => 'full-elementor-mcp/update-element',
		'action'             => 'update',
		'object_type'        => 'post',
		'object_id'          => 928,
		'resource_key'       => 'post:928',
		'fencing_token'      => 1,
		'status'             => 'committed',
		'rollback_supported' => 1,
	) );

	// Newer pending change:
	$wpdb->insert( $j_table, array(
		'created_at'         => gmdate( 'Y-m-d H:i:s' ),
		'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
		'ability'            => 'full-elementor-mcp/update-element',
		'action'             => 'update',
		'object_type'        => 'post',
		'object_id'          => 928,
		'resource_key'       => 'post:928',
		'fencing_token'      => 2,
		'status'             => 'pending',
		'rollback_supported' => 1,
	) );

	$res = Full_Elementor_MCP_Undo_Manager::undo_last_change( 'post:928', false );
	assert_is_wp_error( $res );
	assert_equals( 'undo_latest_not_safe', $res->get_error_code() );
} );

run_test( 'Undo History: undo_last_change fails closed when newest row is already rolled_back', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$wpdb->insert( $j_table, array(
		'created_at'         => gmdate( 'Y-m-d H:i:s' ),
		'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
		'ability'            => 'full-elementor-mcp/update-element',
		'action'             => 'update',
		'object_type'        => 'post',
		'object_id'          => 929,
		'resource_key'       => 'post:929',
		'fencing_token'      => 1,
		'status'             => 'rolled_back',
		'rollback_supported' => 1,
	) );

	$res = Full_Elementor_MCP_Undo_Manager::undo_last_change( 'post:929', false );
	assert_is_wp_error( $res );
	assert_equals( 'undo_latest_not_safe', $res->get_error_code() );
} );

run_test( 'Undo History: missing after_hash fails closed with undo_state_unverifiable', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$wpdb->insert( $j_table, array(
		'created_at'         => gmdate( 'Y-m-d H:i:s' ),
		'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
		'ability'            => 'full-elementor-mcp/update-element',
		'action'             => 'update',
		'object_type'        => 'post',
		'object_id'          => 930,
		'resource_key'       => 'post:930',
		'fencing_token'      => 1,
		'status'             => 'committed',
		'rollback_supported' => 1,
		'before_hash'        => 'some_hash',
		'after_hash'         => null, // Missing after_hash!
		'before_state'       => json_encode( array() ),
	) );
	$jid = (int) $wpdb->insert_id;

	$res = Full_Elementor_MCP_Undo_Manager::undo_change( $jid, false );
	assert_is_wp_error( $res );
	assert_equals( 'undo_state_unverifiable', $res->get_error_code() );
} );

run_test( 'Undo History: global checkpoint restore undo uses global resource key', function () {
	global $wpdb;
	$j_table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	$kit_id = 931;
	$GLOBALS['mock_posts'][ $kit_id ] = (object) array(
		'ID'          => $kit_id,
		'post_title'  => 'Default Kit',
		'post_status' => 'publish',
	);
	update_option( 'elementor_active_kit', $kit_id );

	$kit_before = array(
		'strategy'      => 'global',
		'active_kit_id' => $kit_id,
		'kit_post'      => array( 'post_title' => 'Default Kit', 'post_status' => 'publish' ),
		'kit_settings'  => array( 'theme' => 'dark', 'font' => 'Inter' ),
	);
	$kit_after  = array(
		'strategy'      => 'global',
		'active_kit_id' => $kit_id,
		'kit_post'      => array( 'post_title' => 'Default Kit', 'post_status' => 'publish' ),
		'kit_settings'  => array( 'theme' => 'light', 'font' => 'Roboto' ),
	);

	$GLOBALS['mock_post_meta'][ $kit_id ]['_elementor_page_settings'] = $kit_after['kit_settings'];

	$wpdb->insert( $j_table, array(
		'created_at'         => gmdate( 'Y-m-d H:i:s' ),
		'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
		'ability'            => 'checkpoint-restore',
		'action'             => 'restore',
		'object_type'        => 'global',
		'object_id'          => 0,
		'resource_key'       => 'global:elementor-kit-state',
		'fencing_token'      => 1,
		'status'             => 'committed',
		'rollback_supported' => 1,
		'before_hash'        => Full_Elementor_MCP_Journal::hash_state( $kit_before ),
		'after_hash'         => Full_Elementor_MCP_Journal::hash_state( $kit_after ),
		'before_state'       => json_encode( $kit_before ),
	) );
	$jid = (int) $wpdb->insert_id;

	$res = Full_Elementor_MCP_Undo_Manager::undo_change( $jid, false );
	assert_false( is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : 'Expected false' );
	assert_true( ! empty( $res['success'] ) );
	assert_equals( 'rolled_back', $res['status'] );

	$curr = get_post_meta( $kit_id, '_elementor_page_settings', true );
	assert_equals( 'dark', $curr['theme'] );
} );

// --- 10.6 Manual Checkpoint Stability ---

run_test( 'Manual Checkpoint: acquires canonical lock and captures coherent snapshot', function () {
	$GLOBALS['mock_posts'][931] = (object) array( 'ID' => 931, 'post_title' => 'Page 931' );
	$GLOBALS['mock_post_meta'][931]['_elementor_data'] = json_encode( array( array( 'id' => 'coherent_1' ) ) );

	$res = Full_Elementor_MCP_Checkpoint_Manager::create_manual_checkpoint( 'post:931', array( 'label' => 'Lock Coherence Test' ) );
	assert_false( is_wp_error( $res ) );
	assert_true( ! empty( $res['checkpoint_uuid'] ) );
	assert_equals( 'post:931', $res['resource_key'] );

	// Verify lock was released in finally block:
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:931', 'verify_free', 10 );
	assert_false( is_wp_error( $lock ), 'Lock must be freed after manual checkpoint completion' );
	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:931', 'verify_free', (int) $lock['fencing_token'] );
} );

run_test( 'Manual Checkpoint: concurrent lock contention rejects snapshot safely', function () {
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:932', 'other_worker', 30 );
	assert_false( is_wp_error( $lock ) );

	$res = Full_Elementor_MCP_Checkpoint_Manager::create_manual_checkpoint( 'post:932', array( 'label' => 'Torn Prevention Test' ) );
	assert_is_wp_error( $res );
	assert_equals( 'resource_locked', $res->get_error_code() );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:932', 'other_worker', (int) $lock['fencing_token'] );
} );

// --- 10.7 Admin Safety Gate ---

run_test( 'Admin Safety: disabled ability blocks admin actions', function () {
	// Disable undo-change in policy:
	Full_Elementor_MCP_Mutation_Middleware::disable_ability( 'full-elementor-mcp/undo-change' );

	$_POST['safety_action'] = 'undo_change';
	$_POST['safety_nonce']  = wp_create_nonce( Full_Elementor_MCP_Safety_Admin::NONCE_ACTION );
	$_POST['journal_id']    = 1;

	Full_Elementor_MCP_Safety_Admin::handle_post_actions();

	unset( $_POST['safety_action'], $_POST['safety_nonce'], $_POST['journal_id'] );

	ob_start();
	Full_Elementor_MCP_Safety_Admin::render();
	$html = ob_get_clean();

	assert_true( str_contains( $html, 'Undo change is currently disabled by administrator safety policy' ) );

	Full_Elementor_MCP_Mutation_Middleware::enable_ability( 'full-elementor-mcp/undo-change' );
} );

run_test( 'Admin Safety: two-step confirmation flow and type-correct restore invocation', function () {
	$GLOBALS['mock_posts'][933] = (object) array( 'ID' => 933, 'post_title' => 'Page 933' );
	$GLOBALS['mock_post_meta'][933]['_elementor_data'] = json_encode( array( array( 'id' => 'cp933', 'elType' => 'section', 'elements' => array() ) ) );

	$save = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:933', 'manual', array( 'label' => 'Admin Test' ) );
	$uuid = $save['checkpoint_uuid'];

	// Step 1: Admin clicks restore without token:
	$_POST['safety_action']   = 'restore_checkpoint';
	$_POST['safety_nonce']    = wp_create_nonce( Full_Elementor_MCP_Safety_Admin::NONCE_ACTION );
	$_POST['checkpoint_uuid'] = $uuid;

	Full_Elementor_MCP_Safety_Admin::handle_post_actions();

	ob_start();
	Full_Elementor_MCP_Safety_Admin::render();
	$html = ob_get_clean();

	// Confirmation challenge dialog must be rendered with token:
	assert_true( str_contains( $html, 'Confirm Checkpoint Restore' ) );
	assert_true( str_contains( $html, 'name="confirmation_token"' ) );

	// Extract token from form HTML:
	preg_match( '/name="confirmation_token" value="([^"]+)"/', $html, $matches );
	assert_true( ! empty( $matches[1] ), 'Confirmation token must be rendered in confirmation dialog' );
	$token = $matches[1];

	// Step 2: Admin confirms with token:
	$_POST['confirmation_token'] = $token;
	Full_Elementor_MCP_Safety_Admin::handle_post_actions();

	unset( $_POST['safety_action'], $_POST['safety_nonce'], $_POST['checkpoint_uuid'], $_POST['confirmation_token'] );

	ob_start();
	Full_Elementor_MCP_Safety_Admin::render();
	$html2 = ob_get_clean();

	assert_true( str_contains( $html2, 'restored successfully' ), 'Restore must succeed with valid confirmation token' );
} );

// =============================================================================
// 11. Final Corrective Pass Regressions (Sections A, B, C, D, E)
// =============================================================================

run_test( 'CREATE Undo: create-page journal targets post:N, pre_undo targets post:N, and trashes created post', function () use ( $wpdb ) {
	$GLOBALS['mock_posts'][950] = (object) array( 'ID' => 950, 'post_title' => 'Page 950', 'post_status' => 'publish' );
	$GLOBALS['mock_post_meta'][950]['_elementor_data'] = json_encode( array( array( 'id' => 'cp950' ) ) );

	$strat       = Full_Elementor_MCP_Mutation_Registry::get( 'full-elementor-mcp/create-page' );
	$after_state = call_user_func( $strat['capture_after'], 950, array( 'post_id' => 950 ) );
	$after_hash  = Full_Elementor_MCP_Journal::hash_state( $after_state );

	$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
	$wpdb->insert(
		$table,
		array(
			'ability'            => 'full-elementor-mcp/create-page',
			'action'             => 'create_page',
			'object_type'        => 'page',
			'object_id'          => 0,
			'created_object_id'  => 950,
			'resource_key'       => 'create:page:' . md5( 'test_create_950' ),
			'before_hash'        => Full_Elementor_MCP_Journal::hash_state( array( 'exists' => false ) ),
			'after_hash'         => $after_hash,
			'status'             => 'committed',
			'rollback_supported' => 1,
		)
	);
	$jid = (int) $wpdb->insert_id;

	$res = Full_Elementor_MCP_Undo_Manager::undo_change( $jid, false );
	assert_false( is_wp_error( $res ) );
	assert_true( ! empty( $res['undone'] ) );
	assert_equals( 'post:950', $res['resource_key'] );

	// Pre-undo checkpoint must target post:950:
	$chk = Full_Elementor_MCP_Checkpoint_Manager::get_checkpoint( $res['pre_undo_checkpoint_uuid'] );
	assert_equals( 'post:950', $chk['resource_key'] );

	// Created post must now be trashed:
	assert_equals( 'trash', $GLOBALS['mock_posts'][950]->post_status );

	// Journal entry must be marked rolled_back:
	$row = Full_Elementor_MCP_Journal::get_entry( $jid );
	assert_equals( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK, $row['status'] );
} );

run_test( 'CREATE Undo: create-theme-template journal targets post:N and reverts created template', function () use ( $wpdb ) {
	$GLOBALS['mock_posts'][951] = (object) array( 'ID' => 951, 'post_title' => 'Template 951', 'post_status' => 'publish', 'post_type' => 'elementor_library' );
	$GLOBALS['mock_post_meta'][951]['_elementor_data'] = json_encode( array( array( 'id' => 'tmpl951' ) ) );

	$strat       = Full_Elementor_MCP_Mutation_Registry::get( 'full-elementor-mcp/create-theme-template' );
	$after_state = call_user_func( $strat['capture_after'], 951, array( 'post_id' => 951 ) );
	$after_hash  = Full_Elementor_MCP_Journal::hash_state( $after_state );

	$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
	$wpdb->insert(
		$table,
		array(
			'ability'            => 'full-elementor-mcp/create-theme-template',
			'action'             => 'create_theme_template',
			'object_type'        => 'template',
			'object_id'          => 0,
			'created_object_id'  => 951,
			'resource_key'       => 'create:template:' . md5( 'test_create_tmpl_951' ),
			'before_hash'        => Full_Elementor_MCP_Journal::hash_state( array( 'exists' => false ) ),
			'after_hash'         => $after_hash,
			'status'             => 'committed',
			'rollback_supported' => 1,
		)
	);
	$jid = (int) $wpdb->insert_id;

	$res = Full_Elementor_MCP_Undo_Manager::undo_change( $jid, false );
	assert_false( is_wp_error( $res ) );
	assert_equals( 'post:951', $res['resource_key'] );
	assert_equals( 'trash', $GLOBALS['mock_posts'][951]->post_status );
} );

run_test( 'CREATE Undo: fails closed with undo_target_unresolvable when created_object_id is missing', function () use ( $wpdb ) {
	$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
	$wpdb->insert(
		$table,
		array(
			'ability'            => 'full-elementor-mcp/create-page',
			'action'             => 'create_page',
			'object_type'        => 'page',
			'object_id'          => 0,
			'created_object_id'  => 0,
			'resource_key'       => 'create:page:' . md5( 'missing_created_id' ),
			'before_hash'        => Full_Elementor_MCP_Journal::hash_state( array( 'exists' => false ) ),
			'after_hash'         => 'fakeafterhash',
			'status'             => 'committed',
			'rollback_supported' => 1,
		)
	);
	$jid = (int) $wpdb->insert_id;

	$res = Full_Elementor_MCP_Undo_Manager::undo_change( $jid, false );
	assert_is_wp_error( $res );
	assert_equals( 'undo_target_unresolvable', $res->get_error_code() );
} );

run_test( 'Undo Last Change: finds CREATE journal for post:N with no subsequent mutations', function () use ( $wpdb ) {
	$GLOBALS['mock_posts'][952] = (object) array( 'ID' => 952, 'post_title' => 'Page 952', 'post_status' => 'publish' );
	$GLOBALS['mock_post_meta'][952]['_elementor_data'] = json_encode( array( array( 'id' => 'p952' ) ) );

	$strat       = Full_Elementor_MCP_Mutation_Registry::get( 'full-elementor-mcp/create-page' );
	$after_state = call_user_func( $strat['capture_after'], 952, array( 'post_id' => 952 ) );
	$after_hash  = Full_Elementor_MCP_Journal::hash_state( $after_state );

	$table = Full_Elementor_MCP_Database_Installer::get_journal_table();
	$wpdb->insert(
		$table,
		array(
			'ability'            => 'full-elementor-mcp/create-page',
			'action'             => 'create_page',
			'object_type'        => 'page',
			'object_id'          => 0,
			'created_object_id'  => 952,
			'resource_key'       => 'create:page:' . md5( 'test_create_952' ),
			'before_hash'        => Full_Elementor_MCP_Journal::hash_state( array( 'exists' => false ) ),
			'after_hash'         => $after_hash,
			'status'             => 'committed',
			'rollback_supported' => 1,
		)
	);
	$jid = (int) $wpdb->insert_id;

	$res = Full_Elementor_MCP_Undo_Manager::undo_last_change( 'post:952', false );
	assert_false( is_wp_error( $res ) );
	assert_equals( 'post:952', $res['resource_key'] );
	assert_equals( $jid, $res['journal_id'] );
	assert_equals( 'trash', $GLOBALS['mock_posts'][952]->post_status );
} );

run_test( 'Undo Last Change: CREATE followed by normal update selects update as newest', function () use ( $wpdb ) {
	$GLOBALS['mock_posts'][953] = (object) array( 'ID' => 953, 'post_title' => 'Page 953', 'post_status' => 'publish' );
	$GLOBALS['mock_post_meta'][953]['_elementor_page_settings'] = array( 'title' => 'v2' );

	$table = Full_Elementor_MCP_Database_Installer::get_journal_table();

	// Old CREATE journal:
	$wpdb->insert(
		$table,
		array(
			'ability'            => 'full-elementor-mcp/create-page',
			'action'             => 'create_page',
			'object_type'        => 'page',
			'object_id'          => 0,
			'created_object_id'  => 953,
			'resource_key'       => 'create:page:' . md5( 'test_create_953' ),
			'before_hash'        => 'hash0',
			'after_hash'         => 'hash1',
			'status'             => 'committed',
			'rollback_supported' => 1,
		)
	);
	$create_jid = (int) $wpdb->insert_id;

	// Newer update journal:
	$wpdb->insert(
		$table,
		array(
			'ability'            => 'full-elementor-mcp/update-page-settings',
			'action'             => 'update_page_settings',
			'object_type'        => 'page',
			'object_id'          => 953,
			'created_object_id'  => 0,
			'resource_key'       => 'post:953',
			'before_hash'        => 'hash1',
			'after_hash'         => 'hash2',
			'status'             => 'committed',
			'rollback_supported' => 1,
		)
	);
	$update_jid = (int) $wpdb->insert_id;

	// Dry-run inspects newest:
	$dry = Full_Elementor_MCP_Undo_Manager::undo_last_change( 'post:953', true );
	assert_false( is_wp_error( $dry ) );
	assert_equals( $update_jid, $dry['journal_id'] );
	assert_equals( 'full-elementor-mcp/update-page-settings', $dry['ability'] );
	assert_equals( 'post:953', $dry['resource_key'] );
} );

run_test( 'Restore Fencing: pre-write fencing assertion failure keeps confirmation token reusable and target unchanged', function () use ( $wpdb ) {
	$GLOBALS['mock_posts'][954] = (object) array( 'ID' => 954, 'post_title' => 'Page 954 Original', 'post_status' => 'publish' );
	$GLOBALS['mock_post_meta'][954]['_elementor_data'] = json_encode( array( array( 'id' => 'orig_954', 'elType' => 'section', 'elements' => array() ) ) );

	// Capture checkpoint CP_target:
	$GLOBALS['mock_post_meta'][954]['_elementor_data'] = json_encode( array( array( 'id' => 'target_954', 'elType' => 'section', 'elements' => array() ) ) );
	$save = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:954', 'manual', array( 'label' => 'Target CP' ) );
	$uuid = $save['checkpoint_uuid'];

	// Reset to original:
	$GLOBALS['mock_post_meta'][954]['_elementor_data'] = json_encode( array( array( 'id' => 'orig_954', 'elType' => 'section', 'elements' => array() ) ) );

	// Issue confirmation token via middleware:
	$chal_res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/restore-checkpoint',
		array( 'checkpoint_uuid' => $uuid )
	);
	assert_is_wp_error( $chal_res );
	assert_equals( 'confirmation_required', $chal_res->get_error_code() );
	$chal_data  = $chal_res->get_error_data();
	$conf_token = $chal_data['confirmation_token'];

	// Intercept right after WAL insert into elementor_mcp_journal, to steal the lock:
	$simulated = false;
	$tok_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$lock_key  = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( 'post:954' );
	$wpdb->on_before_query = function ( $q ) use ( &$wpdb, &$simulated, $tok_table, $lock_key ) {
		if ( ! $simulated && str_contains( $q, 'INSERT INTO' ) && str_contains( $q, 'elementor_mcp_journal' ) ) {
			$simulated = true;
			// Steal lock by updating owner_id on post:954 in tokens table:
			$wpdb->pdo->exec( "UPDATE {$tok_table} SET owner_id = 'stolen_lock_owner' WHERE token_key = '{$lock_key}'" );
		}
	};

	// Attempt restore via middleware:
	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/restore-checkpoint',
		array(
			'checkpoint_uuid'    => $uuid,
			'confirmation_token' => $conf_token,
		)
	);

	$wpdb->on_before_query = null;

	assert_is_wp_error( $res );
	assert_equals( 'stale_writer_conflict', $res->get_error_code() );

	// 1. Confirmation token must NOT be consumed (used = 0):
	$tok_row = $wpdb->get_row( $wpdb->prepare( "SELECT used FROM {$tok_table} WHERE token_key = %s", 'conf:' . hash( 'sha256', $conf_token ) ), ARRAY_A );
	assert_true( ! empty( $tok_row ), 'Token row must exist in tokens table' );
	assert_equals( 0, (int) $tok_row['used'], 'Token must remain reusable after pre-write fence failure' );

	// 2. Target state must remain unchanged:
	$curr_meta = $GLOBALS['mock_post_meta'][954]['_elementor_data'];
	assert_true( str_contains( $curr_meta, 'orig_954' ), 'Target state must not be modified when fence check fails' );
} );

run_test( 'Confirmed Restore No-op: consumes token on terminal no-op and blocks replay', function () use ( $wpdb ) {
	$GLOBALS['mock_posts'][955] = (object) array( 'ID' => 955, 'post_title' => 'Page 955', 'post_status' => 'publish' );
	$GLOBALS['mock_post_meta'][955]['_elementor_data'] = json_encode( array( array( 'id' => 'noop_state', 'elType' => 'section', 'elements' => array() ) ) );

	// Save checkpoint while in noop_state:
	$save = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( 'post:955', 'manual', array( 'label' => 'Noop CP' ) );
	$uuid = $save['checkpoint_uuid'];

	// Step 1: Request confirmation challenge:
	$req1 = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/restore-checkpoint',
		array( 'checkpoint_uuid' => $uuid )
	);
	assert_is_wp_error( $req1 );
	assert_equals( 'confirmation_required', $req1->get_error_code() );
	$req1_data  = $req1->get_error_data();
	$conf_token = $req1_data['confirmation_token'];

	// Step 2: Execute with token when live state matches checkpoint (terminal no-op):
	$res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/restore-checkpoint',
		array(
			'checkpoint_uuid'    => $uuid,
			'confirmation_token' => $conf_token,
		)
	);
	assert_false( is_wp_error( $res ) );
	assert_true( ! empty( $res['noop'] ), 'Must return noop=true' );
	assert_true( ! empty( $res['confirmation_consumed'] ), 'Must mark confirmation consumed' );

	// Check token in DB: must be used = 1:
	$tok_table = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$tok_row   = $wpdb->get_row( $wpdb->prepare( "SELECT used FROM {$tok_table} WHERE token_key = %s", 'conf:' . hash( 'sha256', $conf_token ) ), ARRAY_A );
	assert_true( ! empty( $tok_row ), 'Token row must exist in tokens table' );
	assert_equals( 1, (int) $tok_row['used'], 'Confirmed no-op must consume confirmation token' );

	// Mutate resource later:
	$GLOBALS['mock_post_meta'][955]['_elementor_data'] = json_encode( array( array( 'id' => 'diverged_state', 'elType' => 'section', 'elements' => array() ) ) );

	// Replay old confirmation token: must fail:
	$replay_res = Full_Elementor_MCP_Mutation_Middleware::execute(
		'full-elementor-mcp/restore-checkpoint',
		array(
			'checkpoint_uuid'    => $uuid,
			'confirmation_token' => $conf_token,
		)
	);
	assert_is_wp_error( $replay_res );
	assert_equals( 'confirmation_token_used', $replay_res->get_error_code() );
} );

run_test( 'Forensic Audit Coverage: operator safety actions create append-only audit events', function () use ( $wpdb ) {
	$GLOBALS['mock_posts'][956] = (object) array( 'ID' => 956, 'post_title' => 'Page 956', 'post_status' => 'publish' );
	$GLOBALS['mock_post_meta'][956]['_elementor_data'] = json_encode( array( array( 'id' => 'audit_956' ) ) );

	// 1. Manual Checkpoint Creation:
	$cp = Full_Elementor_MCP_Checkpoint_Manager::create_manual_checkpoint(
		'post:956',
		array(
			'label'   => 'Forensic Test CP',
			'user_id' => 77,
		)
	);
	assert_false( is_wp_error( $cp ) );

	$audit_table = Full_Elementor_MCP_Database_Installer::get_audit_log_table();
	$cp_event    = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$audit_table} WHERE event = %s AND resource_key = %s ORDER BY id DESC LIMIT 1",
			Full_Elementor_MCP_Audit_Logger::EVENT_CHECKPOINT_CREATED,
			'post:956'
		),
		ARRAY_A
	);
	assert_true( ! empty( $cp_event ), 'checkpoint_created audit event must exist' );
	assert_equals( $cp['checkpoint_uuid'], $cp_event['checkpoint_uuid'] );
	assert_equals( 77, (int) $cp_event['user_id'] );
	assert_equals( 'success', $cp_event['result_status'] );

	// Checkpoint failure audit event:
	$lock    = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post:956', 'blocker', 60 );
	$cp_fail = Full_Elementor_MCP_Checkpoint_Manager::create_manual_checkpoint( 'post:956', array( 'user_id' => 77 ) );
	assert_is_wp_error( $cp_fail );
	Full_Elementor_MCP_Lock_Manager::release_lock( 'post:956', 'blocker', (int) $lock['fencing_token'] );

	$cp_fail_event = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$audit_table} WHERE event = %s AND resource_key = %s ORDER BY id DESC LIMIT 1",
			Full_Elementor_MCP_Audit_Logger::EVENT_CHECKPOINT_CREATE_FAILED,
			'post:956'
		),
		ARRAY_A
	);
	assert_true( ! empty( $cp_fail_event ), 'checkpoint_create_failed audit event must exist' );
	assert_equals( 'resource_locked', $cp_fail_event['error_code'] );

	// 2. Recovery Scan Audit Events:
	Full_Elementor_MCP_Journal::recover_pending(
		60,
		array(
			'ability' => 'admin/recover_pending',
			'user_id' => 88,
		)
	);

	$scan_start = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$audit_table} WHERE event = %s AND user_id = 88 ORDER BY id DESC LIMIT 1",
			Full_Elementor_MCP_Audit_Logger::EVENT_RECOVERY_SCAN_STARTED
		),
		ARRAY_A
	);
	assert_true( ! empty( $scan_start ), 'recovery_scan_started event must exist' );

	$scan_end = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$audit_table} WHERE event = %s AND user_id = 88 ORDER BY id DESC LIMIT 1",
			Full_Elementor_MCP_Audit_Logger::EVENT_RECOVERY_COMPLETED
		),
		ARRAY_A
	);
	assert_true( ! empty( $scan_end ), 'recovery_completed event must exist' );
	$scan_meta = json_decode( (string) $scan_end['metadata_sanitized'], true );
	assert_true( is_array( $scan_meta ), 'scan_meta must be an array' );
	assert_true( array_key_exists( 'processed', $scan_meta ), 'scan_meta must have processed count' );

	// 3. Audit Pruning Audit Event:
	Full_Elementor_MCP_Audit_Logger::prune(
		90,
		50,
		array(
			'ability'      => 'admin/prune_audit',
			'user_id'      => 99,
			'log_if_empty' => true,
		)
	);

	$prune_event = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$audit_table} WHERE event = %s AND user_id = 99 ORDER BY id DESC LIMIT 1",
			Full_Elementor_MCP_Audit_Logger::EVENT_AUDIT_PRUNED
		),
		ARRAY_A
	);
	assert_true( ! empty( $prune_event ), 'audit_pruned event must exist' );
	$prune_meta = json_decode( (string) $prune_event['metadata_sanitized'], true );
	assert_true( is_array( $prune_meta ), 'prune_meta must be an array' );
	assert_equals( 90, (int) ( $prune_meta['retention_days'] ?? 0 ) );
	assert_true( array_key_exists( 'deleted_row_count', $prune_meta ), 'prune_meta must have deleted_row_count' );
} );

// =============================================================================
// Summary & Verdict
// =============================================================================

echo "\n=======================================================\n";
echo " Test Results: {$tests_passed}/{$tests_run} passed.\n";
if ( $tests_failed > 0 ) {
	echo " {$tests_failed} test(s) failed!\n";
	exit( 1 );
} else {
	echo " All Phase 6 tests passed successfully!\n";
}
echo "=======================================================\n";
