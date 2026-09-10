<?php
/**
 * Standalone Test Suite for Phase 1: Safety Foundation Layer.
 *
 * Can be executed via CLI: `php tests/test-phase1-foundation.php`
 *
 * Verifies:
 * 1. Database Installer SQL syntax and schema definitions.
 * 2. Safety Settings defaults, sanitization, and policy getters.
 * 3. Lock Manager lease locking, fencing token increments, lease renewal, stale writer detection, and idempotency.
 * 4. Security Guard SSRF URL & IP resolution blocking (loopback, RFC1918, metadata).
 * 5. Critical Asset Guard protection and break-glass policies.
 * 6. Credential Scope resolution and ability filtering.
 *
 * @package Full_Elementor_MCP
 */

declare(strict_types=1);

// Bootstrap minimal WordPress test harness if not running inside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

// Stubs for WordPress core constants.
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
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, ...$args ): mixed {
		return $value;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return $GLOBALS['wp_test_current_user_id'] ?? 1;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		return ! empty( $GLOBALS['wp_test_user_caps'][ $cap ] );
	}
}

if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
	function rest_get_authenticated_app_password(): ?string {
		return $GLOBALS['wp_test_app_password_uuid'] ?? null;
	}
}

// Mock wpdb with in-memory SQLite backend for true SQL query execution.
class Mock_WPDB {
	public string $prefix = 'wp_';
	public bool $simulate_write_failure = false;
	public bool $simulate_zero_affected_rows = false;
	public ?\Closure $on_before_query = null;
	public \PDO $pdo;

	public function __construct() {
		$this->pdo = new \PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function prepare( string $query, ...$args ): string {
		if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$val = is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
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
						$indexes_to_create[] = "CREATE INDEX IF NOT EXISTS {$key_match[1]} ON {$table_name} ({$key_match[2]});";
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
			return $this->pdo->exec( $query );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function get_col( string $query, int $x = 0 ): array {
		try {
			if ( preg_match( '/SHOW COLUMNS FROM\s+([^\s;]+)/i', $query, $m ) ) {
				$table = trim( $m[1], '`' );
				$query = "SELECT name FROM pragma_table_info('{$table}')";
			}
			$stmt = $this->pdo->query( $query );
			return $stmt ? $stmt->fetchAll( \PDO::FETCH_COLUMN, $x ) : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public function get_results( string $query, string $output = 'OBJECT' ): mixed {
		try {
			if ( preg_match( '/SHOW INDEX(?:ES)? FROM\s+([^\s;]+)/i', $query, $m ) ) {
				$table = trim( $m[1], '`' );
				$query = "SELECT name AS Key_name FROM pragma_index_list('{$table}')";
			}
			$stmt = $this->pdo->query( $query );
			if ( ! $stmt ) {
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

	public function get_var( string $query ): mixed {
		try {
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/i", $query, $m ) ) {
				$query = "SELECT name FROM sqlite_master WHERE type='table' AND name = '{$m[1]}'";
			}
			$stmt = $this->pdo->query( $query );
			return $stmt ? $stmt->fetchColumn() : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function get_row( string $query, string $output = 'OBJECT' ): mixed {
		try {
			$stmt = $this->pdo->query( $query );
			$res  = $stmt ? $stmt->fetch( \PDO::FETCH_ASSOC ) : null;
			if ( ! $res ) {
				return null;
			}
			return 'ARRAY_A' === $output ? $res : (object) $res;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function insert( string $table, array $data, array $format = array() ): int|bool {
		try {
			$keys   = array_keys( $data );
			$cols   = implode( ', ', $keys );
			$places = implode( ', ', array_fill( 0, count( $keys ), '?' ) );
			$stmt   = $this->pdo->prepare( "INSERT INTO {$table} ({$cols}) VALUES ({$places})" );
			$stmt->execute( array_values( $data ) );
			return 1;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): int|bool {
		try {
			$sets = array();
			$vals = array();
			foreach ( $data as $k => $v ) {
				$sets[] = "{$k} = ?";
				$vals[] = $v;
			}
			$wheres = array();
			foreach ( $where as $k => $v ) {
				$wheres[] = "{$k} = ?";
				$vals[]   = $v;
			}
			$sql  = "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $wheres );
			$stmt = $this->pdo->prepare( $sql );
			$stmt->execute( $vals );
			return $stmt->rowCount();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function replace( string $table, array $data, array $format = array() ): int|bool {
		if ( $this->simulate_write_failure ) {
			return false;
		}

		try {
			$keys   = array_keys( $data );
			$cols   = implode( ', ', $keys );
			$places = implode( ', ', array_fill( 0, count( $keys ), '?' ) );
			$stmt   = $this->pdo->prepare( "INSERT OR REPLACE INTO {$table} ({$cols}) VALUES ({$places})" );
			$stmt->execute( array_values( $data ) );
			return 1;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}

$GLOBALS['wpdb'] = new Mock_WPDB();

// Load Phase 1 Safety files.
require_once __DIR__ . '/../includes/safety/class-database-installer.php';
require_once __DIR__ . '/../includes/safety/class-safety-settings.php';
require_once __DIR__ . '/../includes/safety/class-lock-manager.php';
require_once __DIR__ . '/../includes/safety/class-security-guard.php';

// Test runner helper.
$total_tests  = 0;
$passed_tests = 0;

function run_test( string $description, callable $fn ): void {
	global $total_tests, $passed_tests;
	$total_tests++;
	try {
		$fn();
		$passed_tests++;
		echo " [PASS] {$description}\n";
	} catch ( \Throwable $e ) {
		echo " [FAIL] {$description}\n";
		echo "        Error: {$e->getMessage()}\n";
		echo "        File:  {$e->getFile()}:{$e->getLine()}\n";
	}
}

function assert_true( bool $condition, string $msg = 'Expected condition to be true.' ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $msg );
	}
}

function assert_false( bool $condition, string $msg = 'Expected condition to be false.' ): void {
	if ( $condition ) {
		throw new \RuntimeException( $msg );
	}
}

function assert_equals( mixed $expected, mixed $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		$exp_str = is_scalar( $expected ) ? (string) $expected : json_encode( $expected );
		$act_str = is_scalar( $actual ) ? (string) $actual : json_encode( $actual );
		throw new \RuntimeException( $msg ?: "Expected [{$exp_str}], got [{$act_str}]." );
	}
}

echo "\n=======================================================\n";
echo " Full Elementor MCP — Phase 1 Test Suite\n";
echo "=======================================================\n\n";

// ---------------------------------------------------------------------
// TEST GROUP 1: Database Installer
// ---------------------------------------------------------------------
run_test( 'Database Installer: table name getters match prefix', function () {
	assert_equals( 'wp_elementor_mcp_journal', Full_Elementor_MCP_Database_Installer::get_journal_table() );
	assert_equals( 'wp_elementor_mcp_checkpoints', Full_Elementor_MCP_Database_Installer::get_checkpoints_table() );
	assert_equals( 'wp_elementor_mcp_audit_log', Full_Elementor_MCP_Database_Installer::get_audit_log_table() );
	assert_equals( 'wp_elementor_mcp_tokens', Full_Elementor_MCP_Database_Installer::get_tokens_table() );
} );

run_test( 'Database Installer: table DDL execution and version option', function () {
	$installed = Full_Elementor_MCP_Database_Installer::install();
	assert_true( $installed, 'Installer should report success.' );
	assert_equals( '1.0.0', get_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION ) );
} );

// ---------------------------------------------------------------------
// TEST GROUP 2: Safety Settings Manager
// ---------------------------------------------------------------------
run_test( 'Safety Settings: defaults are safe and correct', function () {
	Full_Elementor_MCP_Safety_Settings::clear_cache();
	assert_true( Full_Elementor_MCP_Safety_Settings::is_safe_mode_enabled() );
	assert_true( Full_Elementor_MCP_Safety_Settings::is_undo_enabled() );
	assert_false( Full_Elementor_MCP_Safety_Settings::is_permanent_delete_allowed() ); // Enforces Trash!
	assert_equals( 'agent_confirmation', Full_Elementor_MCP_Safety_Settings::get_break_glass_policy() );
	assert_equals( 30, Full_Elementor_MCP_Safety_Settings::get( 'audit_retention_days' ) );
	assert_equals( 15, Full_Elementor_MCP_Safety_Settings::get( 'max_checkpoints_count' ) );
} );

run_test( 'Safety Settings: update and policy sanitization', function () {
	Full_Elementor_MCP_Safety_Settings::update( array(
		'safe_mode'              => false,
		'break_glass_policy'     => 'admin_approval',
		'audit_retention_days'   => 45,
		'allow_permanent_delete' => true,
	) );
	assert_false( Full_Elementor_MCP_Safety_Settings::is_safe_mode_enabled() );
	assert_equals( 'admin_approval', Full_Elementor_MCP_Safety_Settings::get_break_glass_policy() );
	assert_equals( 45, Full_Elementor_MCP_Safety_Settings::get( 'audit_retention_days' ) );
	assert_true( Full_Elementor_MCP_Safety_Settings::is_permanent_delete_allowed() );

	// Test invalid policy falls back safely.
	Full_Elementor_MCP_Safety_Settings::update( array(
		'break_glass_policy' => 'invalid_hacky_policy',
	) );
	assert_equals( 'agent_confirmation', Full_Elementor_MCP_Safety_Settings::get_break_glass_policy() );

	// Reset back to defaults for clean state.
	Full_Elementor_MCP_Safety_Settings::update( Full_Elementor_MCP_Safety_Settings::get_defaults() );
	assert_true( Full_Elementor_MCP_Safety_Settings::is_safe_mode_enabled() );
} );

// ---------------------------------------------------------------------
// TEST GROUP 3: Concurrency Lock Manager & Fencing Tokens
// ---------------------------------------------------------------------
run_test( 'Lock Manager: acquire lock generates owner ID & fencing token = 1', function () {
	$res = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post_100', 'req_uuid_aaa', 30 );
	assert_false( is_wp_error( $res ) );
	assert_true( $res['acquired'] );
	assert_equals( 1, $res['fencing_token'] );
	assert_equals( 'req_uuid_aaa', $res['owner_id'] );
} );

run_test( 'Lock Manager: second client acquiring active lock is rejected', function () {
	$res = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post_100', 'req_uuid_bbb', 30 );
	assert_true( is_wp_error( $res ), 'Second client must be rejected with WP_Error.' );
	assert_equals( 'resource_locked', $res->get_error_code() );
} );

run_test( 'Lock Manager: same owner acquiring same lock renews lease without increment', function () {
	$res = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post_100', 'req_uuid_aaa', 30 );
	assert_false( is_wp_error( $res ) );
	assert_equals( 1, $res['fencing_token'] );
} );

run_test( 'Lock Manager: assert fencing token ownership succeeds for owner', function () {
	$check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( 'post_100', 'req_uuid_aaa', 1 );
	assert_true( true === $check );
} );

run_test( 'Lock Manager: assert fencing token ownership rejects stale writer token', function () {
	// Wrong fencing token (e.g. token 0 or 999).
	$check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( 'post_100', 'req_uuid_aaa', 999 );
	assert_true( is_wp_error( $check ) );
	assert_equals( 'stale_writer_conflict', $check->get_error_code() );

	// Wrong owner.
	$check2 = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( 'post_100', 'req_uuid_impostor', 1 );
	assert_true( is_wp_error( $check2 ) );
	assert_equals( 'stale_writer_conflict', $check2->get_error_code() );
} );

run_test( 'Lock Manager: release lock allows subsequent client to acquire with fencing token = 2', function () {
	$released = Full_Elementor_MCP_Lock_Manager::release_lock( 'post_100', 'req_uuid_aaa', 1 );
	assert_true( $released );

	// Next client now acquires lock: fencing token must increment to 2!
	$res2 = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post_100', 'req_uuid_bbb', 30 );
	assert_false( is_wp_error( $res2 ) );
	assert_equals( 2, $res2['fencing_token'] );
	assert_equals( 'req_uuid_bbb', $res2['owner_id'] );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post_100', 'req_uuid_bbb', 2 );
} );

run_test( 'Lock Manager: idempotency result set and get', function () {
	$idemp_key = 'test_key_' . uniqid();
	$ability   = 'full-elementor-mcp/create-page';
	$user_id   = 1;
	$cred_uuid = 'test-cred-uuid';
	$args      = array( 'title' => 'Sample Page' );
	$payload   = array( 'success' => true, 'post_id' => 42, 'message' => 'Created' );

	assert_true( Full_Elementor_MCP_Lock_Manager::set_idempotent_result( $idemp_key, $ability, $user_id, $cred_uuid, $args, $payload, 60 ) );
	$cached = Full_Elementor_MCP_Lock_Manager::get_idempotent_result( $idemp_key, $ability, $user_id, $cred_uuid, $args );
	assert_equals( $payload, $cached );

	// Unknown key returns null.
	assert_equals( null, Full_Elementor_MCP_Lock_Manager::get_idempotent_result( 'non_existent_key', $ability, $user_id, $cred_uuid, $args ) );
} );

// ---------------------------------------------------------------------
// TEST GROUP 4: Security Guard (SSRF Defense-in-Depth)
// ---------------------------------------------------------------------
run_test( 'Security Guard SSRF: blocks cloud metadata IP (169.254.169.254)', function () {
	$res = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'http://169.254.169.254/latest/meta-data/' );
	assert_true( is_wp_error( $res ) );
	assert_equals( 'ssrf_blocked_ip', $res->get_error_code() );
} );

run_test( 'Security Guard SSRF: blocks loopback IP (127.0.0.1 and ::1)', function () {
	$res1 = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'http://127.0.0.1:8080/admin' );
	assert_true( is_wp_error( $res1 ) );
	assert_equals( 'ssrf_blocked_ip', $res1->get_error_code() );

	$res2 = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'http://localhost/admin' );
	assert_true( is_wp_error( $res2 ) );
	assert_equals( 'ssrf_blocked_host', $res2->get_error_code() );
} );

run_test( 'Security Guard SSRF: blocks private RFC 1918 IPs', function () {
	$res1 = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'http://192.168.1.1/secret' );
	assert_true( is_wp_error( $res1 ) );

	$res2 = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'http://10.0.0.5/api' );
	assert_true( is_wp_error( $res2 ) );

	$res3 = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'http://172.16.0.22/metrics' );
	assert_true( is_wp_error( $res3 ) );
} );

run_test( 'Security Guard SSRF: blocks non-HTTP/HTTPS schemes', function () {
	$res1 = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'file:///etc/passwd' );
	assert_true( is_wp_error( $res1 ) );

	$res2 = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'ftp://example.com/file' );
	assert_true( is_wp_error( $res2 ) );
} );

run_test( 'Security Guard SSRF: allows legitimate public domain URLs', function () {
	$res = Full_Elementor_MCP_Security_Guard::validate_remote_url( 'https://wordpress.org/favicon.ico' );
	assert_true( true === $res );
} );

// ---------------------------------------------------------------------
// TEST GROUP 5: Critical Asset Guard & Protected Operations
// ---------------------------------------------------------------------
run_test( 'Critical Asset Guard: detects page_on_front and active kit as critical', function () {
	update_option( 'page_on_front', 10 );
	update_option( 'page_for_posts', 20 );
	update_option( 'elementor_active_kit', 99 );

	assert_true( Full_Elementor_MCP_Security_Guard::is_critical_asset( 10 ) );
	assert_true( Full_Elementor_MCP_Security_Guard::is_critical_asset( 20 ) );
	assert_true( Full_Elementor_MCP_Security_Guard::is_critical_asset( 99 ) );
	assert_false( Full_Elementor_MCP_Security_Guard::is_critical_asset( 555 ) );
} );

run_test( 'Critical Asset Guard: asserts critical asset requires explicit override', function () {
	// Calling destructive action on page 10 without override flag is blocked.
	$check1 = Full_Elementor_MCP_Security_Guard::assert_critical_asset_mutation_allowed( 10, array() );
	assert_true( is_wp_error( $check1 ) );
	assert_equals( 'critical_override_required', $check1->get_error_code() );

	// Calling with allow_critical_override: true passes if user is admin.
	$check2 = Full_Elementor_MCP_Security_Guard::assert_critical_asset_mutation_allowed( 10, array( 'allow_critical_override' => true ) );
	assert_true( true === $check2 );

	// If user is not admin, blocked regardless of flag.
	$GLOBALS['wp_test_user_caps']['manage_options'] = false;
	$check3 = Full_Elementor_MCP_Security_Guard::assert_critical_asset_mutation_allowed( 10, array( 'allow_critical_override' => true ) );
	assert_true( is_wp_error( $check3 ) );
	assert_equals( 'insufficient_critical_privilege', $check3->get_error_code() );
	$GLOBALS['wp_test_user_caps']['manage_options'] = true; // reset
} );

// ---------------------------------------------------------------------
// TEST GROUP 6: Credential Scopes & Ability Gating
// ---------------------------------------------------------------------
run_test( 'Credential Scopes: read_only mode permits only readonly abilities', function () {
	$ro_scope = array(
		'mode'            => 'read_only',
		'user_id'         => 1,
		'credential_uuid' => null,
		'allowed_tools'   => array(),
		'blocked_tools'   => array(),
	);

	// Readonly ability (e.g. list-widgets).
	$allowed = Full_Elementor_MCP_Security_Guard::is_ability_in_scope(
		'full-elementor-mcp/list-widgets',
		array( 'readonly' => true ),
		$ro_scope
	);
	assert_true( $allowed, 'Read-only ability should be permitted in read_only scope.' );

	// Mutating ability (e.g. create-page).
	$denied = Full_Elementor_MCP_Security_Guard::is_ability_in_scope(
		'full-elementor-mcp/create-page',
		array( 'readonly' => false ),
		$ro_scope
	);
	assert_false( $denied, 'Mutating ability must be blocked in read_only scope.' );
} );

run_test( 'Credential Scopes: custom mode enforces allowlist and denylist', function () {
	$custom_scope = array(
		'mode'            => 'custom',
		'user_id'         => 1,
		'credential_uuid' => null,
		'allowed_tools'   => array( 'full-elementor-mcp/add-heading', 'full-elementor-mcp/add-button' ),
		'blocked_tools'   => array( 'full-elementor-mcp/add-button' ), // specifically blocked
	);

	// In allowlist and not blocked.
	assert_true( Full_Elementor_MCP_Security_Guard::is_ability_in_scope(
		'full-elementor-mcp/add-heading',
		array( 'readonly' => false ),
		$custom_scope
	) );

	// In denylist.
	assert_false( Full_Elementor_MCP_Security_Guard::is_ability_in_scope(
		'full-elementor-mcp/add-button',
		array( 'readonly' => false ),
		$custom_scope
	) );

	// Not in allowlist.
	assert_false( Full_Elementor_MCP_Security_Guard::is_ability_in_scope(
		'full-elementor-mcp/delete-page',
		array( 'readonly' => false ),
		$custom_scope
	) );
} );

// ---------------------------------------------------------------------
// TEST GROUP 7: Corrective Patch Verification Tests
// ---------------------------------------------------------------------

// 1. Application Password UUID resolver & Scope Binding
run_test( 'Security Guard: Application Password UUID resolver returns string UUID and binds scope', function () {
	$test_uuid = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
	$GLOBALS['wp_test_app_password_uuid'] = $test_uuid;

	$resolved_uuid = Full_Elementor_MCP_Security_Guard::get_authenticated_credential_uuid();
	assert_equals( $test_uuid, $resolved_uuid );

	// Configure per-credential scope in wp_options.
	$user_id = 42;
	update_option( Full_Elementor_MCP_Security_Guard::OPTION_SCOPES, array(
		$user_id => array(
			$test_uuid => array(
				'mode'          => 'custom',
				'allowed_tools' => array( 'full-elementor-mcp/add-heading' ),
			),
			'default' => array(
				'mode'          => 'read_only',
				'allowed_tools' => array(),
			),
		),
	) );

	// Resolving with the credential UUID yields custom mode.
	$bound_scope = Full_Elementor_MCP_Security_Guard::resolve_scope_for_user_and_credential( $user_id, $test_uuid );
	assert_equals( 'custom', $bound_scope['mode'] );
	assert_equals( $test_uuid, $bound_scope['credential_uuid'] );
	assert_true( in_array( 'full-elementor-mcp/add-heading', $bound_scope['allowed_tools'], true ) );

	// Resolving without credential UUID falls back to user default (read_only).
	$fallback_scope = Full_Elementor_MCP_Security_Guard::resolve_scope_for_user_and_credential( $user_id, null );
	assert_equals( 'read_only', $fallback_scope['mode'] );
	assert_equals( null, $fallback_scope['credential_uuid'] );

	$GLOBALS['wp_test_app_password_uuid'] = null;
} );

// 2. DB Write Failure Paths
run_test( 'Lock Manager: DB write failure paths return WP_Error and never acquired=true', function () {
	global $wpdb;
	$wpdb->simulate_write_failure = true;

	$res = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post_fail_probe', 'req_fail_probe_1', 30 );
	assert_true( is_wp_error( $res ), 'acquire_lock must return WP_Error on DB write failure.' );
	assert_equals( 'lock_acquisition_failed', $res->get_error_code() );

	$idemp_saved = Full_Elementor_MCP_Lock_Manager::set_idempotent_result(
		'probe_key',
		'full-elementor-mcp/probe',
		1,
		null,
		array( 'k' => 'v' ),
		array( 'result' => 'none' )
	);
	assert_false( $idemp_saved, 'set_idempotent_result must return false on DB write failure.' );

	$wpdb->simulate_write_failure = false;
} );

// 3. Duplicate Lock Acquisition
run_test( 'Lock Manager: duplicate lock acquisition detects collision and rejects second client', function () {
	// First client atomically inserts lock.
	$res1 = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post_collision_atomic', 'owner_alpha', 30 );
	assert_false( is_wp_error( $res1 ) );
	assert_true( $res1['acquired'] );
	assert_equals( 1, $res1['fencing_token'] );

	// Second client attempting concurrent acquisition must be rejected with resource_locked.
	$res2 = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post_collision_atomic', 'owner_beta', 30 );
	assert_true( is_wp_error( $res2 ) );
	assert_equals( 'resource_locked', $res2->get_error_code() );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post_collision_atomic', 'owner_alpha', 1 );
} );

// 4. Expired-Lock Takeover Race Semantics (Compare-And-Swap)
run_test( 'Lock Manager: expired-lock takeover via CAS strictly increments fencing token', function () {
	global $wpdb;
	$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$lock_key = 'lock_post_cas_test';

	// Client A acquires lock (fencing_token = 1).
	$res_a = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post_cas_test', 'owner_a', 10 );
	assert_false( is_wp_error( $res_a ) );
	assert_equals( 1, $res_a['fencing_token'] );

	// Artificially expire the lease in DB to simulate expired lock state.
	$past_dt = gmdate( 'Y-m-d H:i:s', time() - 60 );
	$wpdb->query( "UPDATE {$table} SET expires_at = '{$past_dt}' WHERE token_key = '{$lock_key}'" );

	// Client B executes CAS takeover: should succeed and strictly increment fencing token to 2.
	$res_b = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post_cas_test', 'owner_b', 30 );
	assert_false( is_wp_error( $res_b ) );
	assert_true( $res_b['acquired'] );
	assert_equals( 2, $res_b['fencing_token'], 'Fencing token must increment to 2 on takeover.' );
	assert_equals( 'owner_b', $res_b['owner_id'] );

	// Client C attempts takeover immediately while Client B is active: must be REJECTED!
	$res_c = Full_Elementor_MCP_Lock_Manager::acquire_lock( 'post_cas_test', 'owner_c', 30 );
	assert_true( is_wp_error( $res_c ) );
	assert_equals( 'resource_locked', $res_c->get_error_code() );

	Full_Elementor_MCP_Lock_Manager::release_lock( 'post_cas_test', 'owner_b', 2 );
} );

// 5. Custom Scope Fail-Closed on Empty Allowlist
run_test( 'Security Guard: custom scope with empty allowlist fails closed (denies all)', function () {
	$empty_scope = array(
		'mode'          => 'custom',
		'user_id'       => 1,
		'allowed_tools' => array(), // Empty allowlist!
		'blocked_tools' => array(),
	);

	$allowed = Full_Elementor_MCP_Security_Guard::is_ability_in_scope(
		'full-elementor-mcp/add-heading',
		array( 'readonly' => false ),
		$empty_scope
	);
	assert_false( $allowed, 'Custom scope with empty allowlist must deny all abilities.' );

	// Verify global disabled tools gate applies to full mode as well.
	update_option( 'full_elementor_mcp_disabled_tools', array( 'full-elementor-mcp/blocked-globally' ) );
	$full_scope = array(
		'mode'          => 'full',
		'user_id'       => 1,
		'allowed_tools' => array(),
		'blocked_tools' => array(),
	);
	assert_false(
		Full_Elementor_MCP_Security_Guard::is_ability_in_scope( 'full-elementor-mcp/blocked-globally', array( 'readonly' => false ), $full_scope ),
		'Global disabled tools must block abilities even in full mode.'
	);
	delete_option( 'full_elementor_mcp_disabled_tools' );
} );

// 6. Idempotency Args Mismatch
run_test( 'Lock Manager: idempotency returns conflict when same key used with different args', function () {
	$idemp_key = 'idem_audit_' . uniqid();
	$ability   = 'full-elementor-mcp/create-page';
	$user_id   = 7;
	$cred_uuid = 'cred-uuid-xyz';

	$args_original = array( 'title' => 'Page Original', 'status' => 'publish' );
	$args_modified = array( 'title' => 'Page Tampered',   'status' => 'draft' );
	$res_original  = array( 'success' => true, 'page_id' => 999 );

	// Store original result.
	$saved = Full_Elementor_MCP_Lock_Manager::set_idempotent_result(
		$idemp_key,
		$ability,
		$user_id,
		$cred_uuid,
		$args_original,
		$res_original,
		60
	);
	assert_true( $saved );

	// Same key, same args: returns cached payload.
	$cached = Full_Elementor_MCP_Lock_Manager::get_idempotent_result(
		$idemp_key,
		$ability,
		$user_id,
		$cred_uuid,
		$args_original
	);
	assert_equals( $res_original, $cached );

	// Same key, DIFFERENT args: must return idempotency_conflict error!
	$conflict = Full_Elementor_MCP_Lock_Manager::get_idempotent_result(
		$idemp_key,
		$ability,
		$user_id,
		$cred_uuid,
		$args_modified
	);
	assert_true( is_wp_error( $conflict ), 'Mismatched args must return WP_Error.' );
	assert_equals( 'idempotency_conflict', $conflict->get_error_code() );
} );

// 7. Migration and Version Upgrade Behavior
run_test( 'Database Installer: maybe_upgrade detects older version and upgrades successfully', function () {
	// Simulate an older installed DB version.
	update_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION, '0.8.0' );

	$upgraded = Full_Elementor_MCP_Database_Installer::maybe_upgrade();
	assert_true( $upgraded );
	assert_equals(
		Full_Elementor_MCP_Database_Installer::DB_VERSION,
		get_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION )
	);
} );

// ---------------------------------------------------------------------
// TEST GROUP 7: Corrective Phase 1 #2 Regressions
// ---------------------------------------------------------------------

run_test( 'Lock Manager: idempotency empty-args verification enforces args_hash check', function () {
	$idemp_key = 'idem_empty_' . uniqid();
	$ability   = 'full-elementor-mcp/get-settings';
	$user_id   = 1;
	$cred_uuid = 'cred-test-empty';

	// Case A: Stored with non-empty args, replayed with empty args []
	$saved_a = Full_Elementor_MCP_Lock_Manager::set_idempotent_result(
		$idemp_key . '_a',
		$ability,
		$user_id,
		$cred_uuid,
		array( 'scope' => 'all' ),
		array( 'result' => 'ok' ),
		60
	);
	assert_true( $saved_a );

	$replay_empty = Full_Elementor_MCP_Lock_Manager::get_idempotent_result(
		$idemp_key . '_a',
		$ability,
		$user_id,
		$cred_uuid,
		array() // incoming empty args must NOT bypass args_hash check!
	);
	assert_true( is_wp_error( $replay_empty ), 'Empty args replay against non-empty original must return error.' );
	assert_equals( 'idempotency_conflict', $replay_empty->get_error_code() );

	// Case B: Stored with empty args [], replayed with empty args [] -> succeeds
	$saved_b = Full_Elementor_MCP_Lock_Manager::set_idempotent_result(
		$idemp_key . '_b',
		$ability,
		$user_id,
		$cred_uuid,
		array(),
		array( 'result' => 'empty_ok' ),
		60
	);
	assert_true( $saved_b );

	$replay_match = Full_Elementor_MCP_Lock_Manager::get_idempotent_result(
		$idemp_key . '_b',
		$ability,
		$user_id,
		$cred_uuid,
		array()
	);
	assert_equals( array( 'result' => 'empty_ok' ), $replay_match );

	// Case C: Stored with empty args [], replayed with non-empty args -> conflict
	$replay_tampered = Full_Elementor_MCP_Lock_Manager::get_idempotent_result(
		$idemp_key . '_b',
		$ability,
		$user_id,
		$cred_uuid,
		array( 'injected' => 123 )
	);
	assert_true( is_wp_error( $replay_tampered ) );
	assert_equals( 'idempotency_conflict', $replay_tampered->get_error_code() );
} );

run_test( 'Lock Manager: recursive canonical hashing produces identical hash for different key order', function () {
	$args1 = array(
		'z_index'  => 10,
		'settings' => array(
			'padding' => '10px',
			'margin'  => '5px',
			'colors'  => array(
				'primary'   => '#fff',
				'secondary' => '#000',
			),
		),
		'title'    => 'Hero Section',
	);

	$args2 = array(
		'title'    => 'Hero Section',
		'settings' => array(
			'margin'  => '5px',
			'colors'  => array(
				'secondary' => '#000',
				'primary'   => '#fff',
			),
			'padding' => '10px',
		),
		'z_index'  => 10,
	);

	$hash1 = Full_Elementor_MCP_Lock_Manager::hash_args( $args1 );
	$hash2 = Full_Elementor_MCP_Lock_Manager::hash_args( $args2 );

	assert_equals( $hash1, $hash2, 'Recursive key reordering must generate identical argument hash.' );
} );

run_test( 'Lock Manager: list ordering remains significant during canonicalization', function () {
	$list_a = array(
		'elements' => array( 'section_1', 'section_2', 'section_3' ),
	);
	$list_b = array(
		'elements' => array( 'section_3', 'section_2', 'section_1' ),
	);

	$hash_a = Full_Elementor_MCP_Lock_Manager::hash_args( $list_a );
	$hash_b = Full_Elementor_MCP_Lock_Manager::hash_args( $list_b );

	assert_true( $hash_a !== $hash_b, 'Indexed list element order must produce different hashes.' );
} );

run_test( 'Database Installer: maybe_upgrade fast-path executes without table verification queries', function () {
	// Set installed DB version to current.
	update_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION, Full_Elementor_MCP_Database_Installer::DB_VERSION );

	// Drop one of the tables in SQLite to prove verify_tables_exist() is NOT called on fast-path!
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . Full_Elementor_MCP_Database_Installer::get_audit_log_table() );

	// maybe_upgrade() should return true immediately via fast-path without checking tables.
	$fast_res = Full_Elementor_MCP_Database_Installer::maybe_upgrade();
	assert_true( $fast_res, 'Fast path must return true immediately when DB_VERSION is up to date.' );

	// Reinstall table for subsequent tests.
	Full_Elementor_MCP_Database_Installer::install();
	assert_true( Full_Elementor_MCP_Database_Installer::verify_tables_exist() );
} );

run_test( 'Database Installer: migration fails and aborts DB version update if required column or index is missing', function () {
	global $wpdb;

	// Reset option DB version.
	update_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION, '0.5.0' );

	// Create a damaged journal table missing the 'fencing_token' column.
	$journal_table = Full_Elementor_MCP_Database_Installer::get_journal_table();
	$wpdb->query( "DROP TABLE IF EXISTS {$journal_table}" );
	$wpdb->query( "CREATE TABLE {$journal_table} ( id INTEGER PRIMARY KEY, ability TEXT, status TEXT )" );

	// Run schema verification directly.
	$schema_ok = Full_Elementor_MCP_Database_Installer::verify_schema();
	assert_false( $schema_ok, 'verify_schema must return false when required columns are missing.' );

	// Run upgrade: must fail and NOT save DB_VERSION.
	$upgraded = Full_Elementor_MCP_Database_Installer::upgrade( '0.5.0' );
	assert_false( $upgraded, 'Upgrade must return false when schema verification fails.' );
	assert_equals( '0.5.0', get_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION ) );

	// Clean up and reinstall complete tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$journal_table}" );
	Full_Elementor_MCP_Database_Installer::install();
	assert_true( Full_Elementor_MCP_Database_Installer::verify_schema() );
	assert_equals( Full_Elementor_MCP_Database_Installer::DB_VERSION, get_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION ) );
} );

run_test( 'Lock Manager: lease renewal never shortens active lease duration', function () {
	global $wpdb;

	$res_key = 'lease_ttl_test_' . uniqid();
	$owner   = 'owner_lease_tester';

	// Acquire lock with initial TTL of 120 seconds.
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( $res_key, $owner, 120 );
	assert_true( $lock['acquired'] );
	$initial_ts = (int) $lock['expires_at'];

	// Attempt to renew lease with smaller extra_seconds = 10.
	// Heartbeat semantics MUST extend from max(current_expiry, now) + extra_seconds, never shortening!
	$renewed = Full_Elementor_MCP_Lock_Manager::renew_lease( $res_key, $owner, $lock['fencing_token'], 10 );
	assert_true( $renewed );

	$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$lock_key = 'lock_' . sanitize_key( $res_key );
	$row      = $wpdb->get_row(
		$wpdb->prepare( "SELECT expires_at FROM {$table} WHERE token_key = %s", $lock_key ),
		ARRAY_A
	);

	$renewed_ts = strtotime( $row['expires_at'] . ' UTC' );
	assert_true(
		$renewed_ts >= $initial_ts,
		"Renewed lease expiry ({$row['expires_at']}) must be >= initial expiry (" . gmdate( 'Y-m-d H:i:s', $initial_ts ) . ")."
	);

	// Cleanup.
	Full_Elementor_MCP_Lock_Manager::release_lock( $res_key, $owner, $lock['fencing_token'] );
} );

run_test( 'Lock Manager: stale fencing token cannot release newer generation lock', function () {
	global $wpdb;

	$res_key = 'lock_fencing_release_' . uniqid();
	$owner   = 'owner_fencing_tester';

	// Acquire Generation 1 lock.
	$lock1 = Full_Elementor_MCP_Lock_Manager::acquire_lock( $res_key, $owner, 60 );
	assert_true( $lock1['acquired'] );
	assert_equals( 1, $lock1['fencing_token'] );

	// Simulate lock advancement to Generation 2 (e.g. after takeover or lease generation bump).
	$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$lock_key = 'lock_' . sanitize_key( $res_key );
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET fencing_token = 2 WHERE token_key = %s",
			$lock_key
		)
	);

	// Stale Generation 1 attempts to release lock: MUST fail!
	$released_stale = Full_Elementor_MCP_Lock_Manager::release_lock( $res_key, $owner, 1 );
	assert_false( $released_stale, 'Release with stale fencing token=1 must return false.' );

	// Verify lock in database is STILL active (used = 0, fencing_token = 2).
	$current_row = $wpdb->get_row(
		$wpdb->prepare( "SELECT fencing_token, used FROM {$table} WHERE token_key = %s", $lock_key ),
		ARRAY_A
	);
	assert_equals( 2, (int) $current_row['fencing_token'] );
	assert_equals( 0, (int) $current_row['used'], 'Lock must remain active after failed release.' );

	// Valid Generation 2 releases lock: MUST succeed!
	$released_valid = Full_Elementor_MCP_Lock_Manager::release_lock( $res_key, $owner, 2 );
	assert_true( $released_valid, 'Release with matching fencing token=2 must succeed.' );

	$released_row = $wpdb->get_row(
		$wpdb->prepare( "SELECT used FROM {$table} WHERE token_key = %s", $lock_key ),
		ARRAY_A
	);
	assert_equals( 1, (int) $released_row['used'], 'Lock must now be marked used/released.' );
} );

run_test( 'Lock Manager: same owner acquire_lock re-entry is monotonic and never shortens expiry', function () {
	global $wpdb;

	$res_key = 'reentry_monotonic_' . uniqid();
	$owner   = 'owner_reentry_tester';

	// 1. Acquire lock with long TTL = 120 seconds.
	$lock1 = Full_Elementor_MCP_Lock_Manager::acquire_lock( $res_key, $owner, 120 );
	assert_true( $lock1['acquired'] );
	$fencing1 = $lock1['fencing_token'];

	// 2. Renew lease by extending +30 seconds.
	$renewed = Full_Elementor_MCP_Lock_Manager::renew_lease( $res_key, $owner, $fencing1, 30 );
	assert_true( $renewed );

	$table       = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$lock_key    = 'lock_' . sanitize_key( $res_key );
	$row_renewed = $wpdb->get_row(
		$wpdb->prepare( "SELECT expires_at FROM {$table} WHERE token_key = %s", $lock_key ),
		ARRAY_A
	);
	$renewed_ts = strtotime( $row_renewed['expires_at'] . ' UTC' );

	// 3. Same owner calls acquire_lock() again with a much shorter TTL (e.g. 10 seconds).
	$lock2 = Full_Elementor_MCP_Lock_Manager::acquire_lock( $res_key, $owner, 10 );
	assert_true( $lock2['acquired'] );
	assert_equals( $fencing1, $lock2['fencing_token'], 'Re-entry must preserve fencing token.' );

	$row_reentry = $wpdb->get_row(
		$wpdb->prepare( "SELECT expires_at FROM {$table} WHERE token_key = %s", $lock_key ),
		ARRAY_A
	);
	$final_ts = strtotime( $row_reentry['expires_at'] . ' UTC' );

	// 4. Final expiry must remain >= renewal expiry.
	assert_true(
		$final_ts >= $renewed_ts,
		"Same-owner re-entry expiry ({$final_ts}) must be >= previous renewal expiry ({$renewed_ts})."
	);
	assert_true(
		(int) $lock2['expires_at'] >= $renewed_ts,
		"Returned re-entry expires_at timestamp must be >= previous renewal expiry."
	);

	// Cleanup.
	Full_Elementor_MCP_Lock_Manager::release_lock( $res_key, $owner, $lock2['fencing_token'] );
} );

run_test( 'Lock Manager: simulated DB failure on same-owner re-entry returns error and never acquired=true', function () {
	global $wpdb;

	$res_key = 'db_fail_reentry_' . uniqid();
	$owner   = 'owner_db_fail_reentry';

	// 1. Initial acquisition succeeds.
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( $res_key, $owner, 60 );
	assert_true( $lock['acquired'] );

	// 2. Enable simulated write failure on DB.
	$wpdb->simulate_write_failure = true;

	// 3. Same-owner re-entry attempts UPDATE: must FAIL with lock_acquisition_failed!
	$reentry = Full_Elementor_MCP_Lock_Manager::acquire_lock( $res_key, $owner, 30 );
	assert_true( is_wp_error( $reentry ), 'DB failure on re-entry must return WP_Error.' );
	assert_equals( 'lock_acquisition_failed', $reentry->get_error_code() );

	// Reset DB simulation.
	$wpdb->simulate_write_failure = false;

	// Cleanup.
	Full_Elementor_MCP_Lock_Manager::release_lock( $res_key, $owner, $lock['fencing_token'] );
} );

run_test( 'Lock Manager: interim race between read and update on re-entry returns conflict error', function () {
	global $wpdb;

	$res_key = 'race_reentry_' . uniqid();
	$owner   = 'owner_race_reentry';

	// 1. Initial acquisition (fencing token = 1).
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( $res_key, $owner, 60 );
	assert_true( $lock['acquired'] );
	assert_equals( 1, $lock['fencing_token'] );

	// 2. Set hook: right before the UPDATE query executes, concurrently bump fencing token in DB!
	$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
	$lock_key = 'lock_' . sanitize_key( $res_key );

	$wpdb->on_before_query = function ( $q, $db ) use ( $table, $lock_key ) {
		if ( str_contains( $q, 'UPDATE' ) && str_contains( $q, $lock_key ) ) {
			// Concurrently change fencing token in DB to simulate another transaction takeover!
			$db->pdo->exec( "UPDATE {$table} SET fencing_token = 999 WHERE token_key = '{$lock_key}'" );
			$db->on_before_query = null; // fire once
		}
	};

	// 3. Same owner tries re-entry:
	// Initial SELECT reads fencing token 1.
	// Hook bumps fencing token to 999 right before UPDATE.
	// UPDATE matches 0 rows!
	// Recheck detects fencing token changed from 1 to 999!
	$reentry = Full_Elementor_MCP_Lock_Manager::acquire_lock( $res_key, $owner, 30 );
	$wpdb->on_before_query = null;

	assert_true( is_wp_error( $reentry ), 'Interim fencing token race must return WP_Error.' );
	assert_equals( 'stale_writer_conflict', $reentry->get_error_code() );

	// Cleanup.
	Full_Elementor_MCP_Lock_Manager::release_lock( $res_key, $owner, 999 );
} );

run_test( 'Lock Manager: legitimate 0 affected rows on re-entry succeeds after state recheck', function () {
	global $wpdb;

	$res_key = 'zero_rows_reentry_' . uniqid();
	$owner   = 'owner_zero_rows';

	// 1. Initial acquisition (fencing token = 1).
	$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( $res_key, $owner, 60 );
	assert_true( $lock['acquired'] );

	// 2. Simulate 0 affected rows on UPDATE (e.g. MySQL identical values).
	$wpdb->simulate_zero_affected_rows = true;

	// 3. Re-entry attempts UPDATE -> 0 affected rows -> triggers recheck.
	// In DB, owner_id is still owner_zero_rows, fencing_token is still 1, used is 0, expiry is active.
	// Recheck verifies all state valid -> returns acquired=true!
	$reentry = Full_Elementor_MCP_Lock_Manager::acquire_lock( $res_key, $owner, 30 );
	$wpdb->simulate_zero_affected_rows = false;

	assert_false( is_wp_error( $reentry ), 'Valid state with 0 affected rows must succeed.' );
	assert_true( $reentry['acquired'] );
	assert_equals( 1, $reentry['fencing_token'] );

	// Cleanup.
	Full_Elementor_MCP_Lock_Manager::release_lock( $res_key, $owner, $lock['fencing_token'] );
} );

// ---------------------------------------------------------------------
// Test Summary
// ---------------------------------------------------------------------
echo "\n=======================================================\n";
echo " Test Results: {$passed_tests}/{$total_tests} passed.\n";
echo "=======================================================\n\n";

if ( $passed_tests !== $total_tests ) {
	exit( 1 );
}
exit( 0 );

