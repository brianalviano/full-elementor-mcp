<?php
/**
 * Test Suite for Phase 7: Public Release Hardening, Packaging & Concurrency.
 *
 * Can be executed via CLI: `php tests/test-phase7-release-concurrency.php`
 *
 * Verifies:
 * 1. Multi-process concurrency and mutual exclusion across separate OS processes.
 * 2. Worker crash recovery and CAS fencing token monotonicity.
 * 3. Compatibility Checker diagnostic gates and prerequisite evaluation.
 * 4. Safe uninstall policy (data retention by default, full purge on opt-in).
 * 5. Schema invariants (clean install vs upgrade, exactly four tables, downgrade protection).
 * 6. Deterministic packaging smoke test and archive integrity verification.
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'FULL_ELEMENTOR_MCP_VERSION' ) ) {
	$main_file = dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
	if ( file_exists( $main_file ) && preg_match( "/define\(\s*'FULL_ELEMENTOR_MCP_VERSION',\s*'([^']+)'\s*\);/", (string) file_get_contents( $main_file ), $m_ver ) ) {
		define( 'FULL_ELEMENTOR_MCP_VERSION', $m_ver[1] );
	} else {
		define( 'FULL_ELEMENTOR_MCP_VERSION', '1.8.1' );
	}
}
if ( ! defined( 'FULL_ELEMENTOR_MCP_DIR' ) ) {
	define( 'FULL_ELEMENTOR_MCP_DIR', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

// Complete SQLite WPDB Mock
class Phase7Wpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public ?\PDO $pdo = null;
	public ?\Closure $on_before_query = null;

	public function __construct( ?string $file = null ) {
		$dsn = $file ? 'sqlite:' . $file : 'sqlite::memory:';
		$this->pdo = new \PDO( $dsn );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );

		if ( $file ) {
			$this->pdo->exec( 'PRAGMA journal_mode = WAL;' );
			$this->pdo->exec( 'PRAGMA busy_timeout = 5000;' );
		}

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
$wpdb = new Phase7Wpdb();

require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-database-installer.php';
require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-safety-settings.php';
require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-lock-manager.php';
require_once FULL_ELEMENTOR_MCP_DIR . 'includes/class-compatibility-checker.php';

// Test runner infrastructure
$total_tests  = 0;
$passed_tests = 0;
$failed_tests = 0;

function run_test( string $title, callable $cb ): void {
	global $total_tests, $passed_tests, $failed_tests;
	$total_tests++;
	try {
		$cb();
		echo " [PASS] {$title}\n";
		$passed_tests++;
	} catch ( \Throwable $e ) {
		echo " [FAIL] {$title}\n";
		echo '        Error: ' . $e->getMessage() . "\n";
		echo '        File: ' . $e->getFile() . ':' . $e->getLine() . "\n";
		$failed_tests++;
	}
}

function assert_true( mixed $val, string $msg = 'Expected true' ): void {
	if ( true !== $val ) {
		throw new \Exception( $msg . ' (got ' . var_export( $val, true ) . ')' );
	}
}

function assert_false( mixed $val, string $msg = 'Expected false' ): void {
	if ( false !== $val ) {
		throw new \Exception( $msg . ' (got ' . var_export( $val, true ) . ')' );
	}
}

function assert_equals( mixed $expected, mixed $actual, string $msg = 'Values not equal' ): void {
	if ( $expected !== $actual ) {
		throw new \Exception( $msg . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
	}
}

echo "=======================================================\n";
echo " Safe Elementor MCP — Phase 7 Release & Concurrency Tests\n";
echo "=======================================================\n\n";

// Helper function to spawn worker process
function spawn_worker( string $db_file, string $worker_id, string $resource, int $hold_ms, bool $crash = false ): array {
	$php_bin = PHP_BINARY;
	$runner  = FULL_ELEMENTOR_MCP_DIR . 'tests/worker-lock-runner.php';

	$cmd = escapeshellarg( $php_bin ) . ' ' . escapeshellarg( $runner )
		. ' --db=' . escapeshellarg( $db_file )
		. ' --worker=' . escapeshellarg( $worker_id )
		. ' --resource=' . escapeshellarg( $resource )
		. ' --hold-ms=' . $hold_ms;

	if ( $crash ) {
		$cmd .= ' --crash';
	}

	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$proc = proc_open( $cmd, $descriptors, $pipes );
	if ( ! is_resource( $proc ) ) {
		return array( 'proc' => null, 'error' => 'proc_open failed' );
	}

	return array(
		'proc'  => $proc,
		'pipes' => $pipes,
	);
}

function read_worker_result( array $worker ): array {
	$pipes = $worker['pipes'];
	$proc  = $worker['proc'];

	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );

	$status = proc_close( $proc );
	$json   = json_decode( $stdout, true );

	return array(
		'exit_code' => $status,
		'stdout'    => $stdout,
		'stderr'    => $stderr,
		'data'      => is_array( $json ) ? $json : array(),
	);
}

// ---------------------------------------------------------------------
// TEST GROUP 1: Separate-Process Multi-Connection Concurrency
// ---------------------------------------------------------------------

run_test( 'Multi-Process Concurrency: Two competing OS processes enforce mutual exclusion', function () {
	$temp_db = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emcp_concurrency_' . uniqid() . '.sqlite';
	$disk_wpdb = new Phase7Wpdb( $temp_db );

	$disk_wpdb->query(
		"CREATE TABLE IF NOT EXISTS wp_elementor_mcp_tokens (
			token_key varchar(128) NOT NULL,
			token_type varchar(20) NOT NULL,
			owner_id varchar(64) default NULL,
			fencing_token bigint(20) unsigned NOT NULL default 0,
			payload longtext default NULL,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			expires_at datetime NOT NULL,
			used tinyint(1) NOT NULL default 0,
			PRIMARY KEY (token_key)
		)"
	);

	// Worker 1 acquires lock and holds for 400ms
	$w1 = spawn_worker( $temp_db, 'worker_1', 'post:777', 400 );
	usleep( 80000 ); // 80ms pause to ensure W1 claims lock first

	// Worker 2 attempts to acquire same lock key while W1 is holding it
	$w2 = spawn_worker( $temp_db, 'worker_2', 'post:777', 50 );

	$res1 = read_worker_result( $w1 );
	$res2 = read_worker_result( $w2 );

	@unlink( $temp_db );
	@unlink( $temp_db . '-wal' );
	@unlink( $temp_db . '-shm' );

	assert_true( ! empty( $res1['data']['acquired'] ), 'Worker 1 must have acquired the lock: ' . $res1['stdout'] . ' ' . $res1['stderr'] );
	assert_equals( 1, (int) $res1['data']['fencing_token'], 'Worker 1 initial fencing token must be 1' );
	assert_true( ! empty( $res1['data']['released'] ), 'Worker 1 must have released lock after holding' );

	assert_false( ! empty( $res2['data']['acquired'] ), 'Worker 2 must have been rejected while lock was held' );
	assert_equals( 'resource_locked', $res2['data']['error'] ?? '', 'Worker 2 error must be resource_locked: ' . $res2['stdout'] . ' ' . $res2['stderr'] );
} );

run_test( 'Multi-Process Crash Recovery: Crashed worker lease expires and is recovered via CAS with token increment', function () {
	$temp_db = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emcp_crash_' . uniqid() . '.sqlite';
	$disk_wpdb = new Phase7Wpdb( $temp_db );

	$disk_wpdb->query(
		"CREATE TABLE IF NOT EXISTS wp_elementor_mcp_tokens (
			token_key varchar(128) NOT NULL,
			token_type varchar(20) NOT NULL,
			owner_id varchar(64) default NULL,
			fencing_token bigint(20) unsigned NOT NULL default 0,
			payload longtext default NULL,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			expires_at datetime NOT NULL,
			used tinyint(1) NOT NULL default 0,
			PRIMARY KEY (token_key)
		)"
	);

	// Worker 1 acquires lock on post:888 and abruptly crashes
	$w1 = spawn_worker( $temp_db, 'crasher_1', 'post:888', 0, true );
	$res1 = read_worker_result( $w1 );

	assert_true( ! empty( $res1['data']['crashed'] ), 'Worker 1 must indicate simulated crash: ' . $res1['stdout'] . ' ' . $res1['stderr'] );
	assert_equals( 99, $res1['exit_code'], 'Worker 1 must exit with crash code 99' );

	// Expire the lock in the shared SQLite table to simulate time passing past lease expiry
	$disk_wpdb->query(
		"UPDATE wp_elementor_mcp_tokens
		 SET expires_at = '2000-01-01 00:00:00'
		 WHERE token_type = 'lock'"
	);

	// Worker 2 attempts to acquire the expired lock
	$w2 = spawn_worker( $temp_db, 'recovery_worker_2', 'post:888', 50 );
	$res2 = read_worker_result( $w2 );

	@unlink( $temp_db );
	@unlink( $temp_db . '-wal' );
	@unlink( $temp_db . '-shm' );

	assert_true( ! empty( $res2['data']['acquired'] ), 'Worker 2 must successfully recover expired lock via CAS: ' . $res2['stdout'] . ' ' . $res2['stderr'] );
	assert_equals( 2, (int) $res2['data']['fencing_token'], 'Fencing token must strictly increment to 2 on CAS recovery' );
	assert_true( ! empty( $res2['data']['released'] ), 'Worker 2 must release the lock cleanly' );
} );

// ---------------------------------------------------------------------
// TEST GROUP 2: In-Process CAS Monotonicity & Fencing Monotonicity
// ---------------------------------------------------------------------

run_test( 'CAS Monotonicity: Repeated lease recovery strictly increments fencing tokens monotonically', function () {
	Full_Elementor_MCP_Database_Installer::install();

	$resource = 'post:999';

	// Client A acquires lock
	$res_a = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource, 'client_A', 10 );
	assert_true( $res_a['acquired'] );
	assert_equals( 1, (int) $res_a['fencing_token'] );

	// Expire lease
	global $wpdb;
	$token_key = Full_Elementor_MCP_Lock_Manager::get_lock_token_key( $resource );
	$wpdb->query( "UPDATE wp_elementor_mcp_tokens SET expires_at = '2000-01-01 00:00:00' WHERE token_key = '{$token_key}'" );

	// Client B acquires expired lock
	$res_b = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource, 'client_B', 10 );
	assert_true( $res_b['acquired'] );
	assert_equals( 2, (int) $res_b['fencing_token'] );

	// Expire lease again
	$wpdb->query( "UPDATE wp_elementor_mcp_tokens SET expires_at = '2000-01-01 00:00:00' WHERE token_key = '{$token_key}'" );

	// Client C acquires expired lock
	$res_c = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource, 'client_C', 10 );
	assert_true( $res_c['acquired'] );
	assert_equals( 3, (int) $res_c['fencing_token'] );

	// Expire lease again
	$wpdb->query( "UPDATE wp_elementor_mcp_tokens SET expires_at = '2000-01-01 00:00:00' WHERE token_key = '{$token_key}'" );

	// Client D acquires expired lock
	$res_d = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource, 'client_D', 10 );
	assert_true( $res_d['acquired'] );
	assert_equals( 4, (int) $res_d['fencing_token'] );
} );

// ---------------------------------------------------------------------
// TEST GROUP 3: Compatibility Checker Diagnostics
// ---------------------------------------------------------------------

run_test( 'Compatibility Checker: System check reports healthy when requirements are met', function () {
	$status = Full_Elementor_MCP_Compatibility_Checker::check_system();

	assert_true( is_array( $status ) );
	assert_true( isset( $status['php_compatible'] ) );
	assert_true( $status['php_compatible'], 'PHP version must be compatible' );
	assert_true( isset( $status['crypto_available'] ) );
	assert_true( $status['crypto_available'], 'Crypto engine must be available' );
} );

run_test( 'Compatibility Checker: PHP version check rejects PHP < 8.0', function () {
	assert_true( version_compare( '8.0.0', '8.0.0', '>=' ) );
	assert_true( version_compare( '8.4.2', '8.0.0', '>=' ) );
	assert_false( version_compare( '7.4.33', '8.0.0', '>=' ) );
	assert_false( version_compare( '5.6.40', '8.0.0', '>=' ) );
} );

run_test( 'Compatibility Checker: Missing dependencies are accurately listed', function () {
	$missing = Full_Elementor_MCP_Compatibility_Checker::get_missing_dependencies();
	assert_true( is_array( $missing ) );
	assert_true( in_array( 'WordPress MCP Adapter plugin', $missing, true ) );
} );

run_test( 'Compatibility Checker: Activation prerequisites check handles environment gracefully', function () {
	global $wp_version;
	$orig_ver   = $wp_version ?? null;
	$wp_version = '6.9';

	$result = Full_Elementor_MCP_Compatibility_Checker::check_activation_prerequisites();
	assert_true( true === $result, 'Prerequisites should pass with PHP >= 8.0 and WP 6.9' );

	$wp_version = '6.8.3';
	$result2    = Full_Elementor_MCP_Compatibility_Checker::check_activation_prerequisites();
	assert_true( is_wp_error( $result2 ) );
	assert_equals( 'unsupported_wordpress_version', $result2->get_error_code() );

	$wp_version = $orig_ver;
} );

// ---------------------------------------------------------------------
// TEST GROUP 4: Safe Uninstall Policy
// ---------------------------------------------------------------------

run_test( 'Safe Uninstall Policy: Default retains tables and forensic data', function () {
	Full_Elementor_MCP_Database_Installer::install();

	update_option( 'full_elementor_mcp_disabled_tools', array( 'toolA' ) );
	update_option( 'full_elementor_mcp_safety_settings', array( 'safe_mode' => true ) );
	update_option( 'full_elementor_mcp_delete_data_on_uninstall', false );

	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', true );
	}

	require FULL_ELEMENTOR_MCP_DIR . 'uninstall.php';

	assert_equals( array( 'toolA' ), get_option( 'full_elementor_mcp_disabled_tools' ) );
	assert_equals( array( 'safe_mode' => true ), get_option( 'full_elementor_mcp_safety_settings' ) );

	global $wpdb;
	$res = $wpdb->get_results( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'wp_elementor_mcp_%'" );
	assert_true( count( $res ) >= 4, 'Tables must be preserved when delete_data_on_uninstall is false' );
} );

run_test( 'Safe Uninstall Policy: Explicit opt-in purges all tables and options', function () {
	update_option( 'full_elementor_mcp_delete_data_on_uninstall', true );

	require FULL_ELEMENTOR_MCP_DIR . 'uninstall.php';

	assert_false( get_option( 'full_elementor_mcp_disabled_tools', false ) );
	assert_false( get_option( 'full_elementor_mcp_safety_settings', false ) );
	assert_false( get_option( 'full_elementor_mcp_delete_data_on_uninstall', false ) );

	global $wpdb;
	$res = $wpdb->get_results( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'wp_elementor_mcp_%'" );
	assert_equals( 0, count( $res ), 'All tables must be dropped when delete_data_on_uninstall is true' );

	Full_Elementor_MCP_Database_Installer::install();
} );

// ---------------------------------------------------------------------
// TEST GROUP 5: Schema Invariants & Upgrade Safety
// ---------------------------------------------------------------------

run_test( 'Schema Invariant: Exactly four safety tables exist and match expected schemas', function () {
	Full_Elementor_MCP_Database_Installer::install();

	$expected_tables = array(
		'wp_elementor_mcp_journal',
		'wp_elementor_mcp_checkpoints',
		'wp_elementor_mcp_audit_log',
		'wp_elementor_mcp_tokens',
	);

	global $wpdb;
	$res = $wpdb->get_results( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'wp_elementor_mcp_%'", ARRAY_A );
	$found_names = array_column( $res, 'name' );

	foreach ( $expected_tables as $expected ) {
		assert_true( in_array( $expected, $found_names, true ), "Table {$expected} must exist" );
	}

	assert_equals( 4, count( $found_names ), 'Exactly four elementor_mcp safety tables must exist (no phantoms)' );
} );

run_test( 'Schema Invariant: Upgrade from 1.3.0 brings version to 1.4.0 cleanly', function () {
	global $wpdb;
	update_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION, '1.3.0' );

	$upgraded = Full_Elementor_MCP_Database_Installer::maybe_upgrade();
	assert_true( $upgraded );
	assert_equals( '1.4.0', get_option( Full_Elementor_MCP_Database_Installer::OPTION_DB_VERSION ) );
} );

// ---------------------------------------------------------------------
// TEST GROUP 6: Deterministic Packaging Smoke Test
// ---------------------------------------------------------------------

run_test( 'Release Packaging: scripts/build-release.php produces valid single-root archive and manifest', function () {
	$php_bin = PHP_BINARY;
	$script  = FULL_ELEMENTOR_MCP_DIR . 'scripts/build-release.php';

	exec( escapeshellarg( $php_bin ) . ' ' . escapeshellarg( $script ), $output, $exit_code );

	assert_equals( 0, $exit_code, 'scripts/build-release.php must exit with 0: ' . implode( "\n", $output ) );

	$dist_dir = FULL_ELEMENTOR_MCP_DIR . 'dist';
	$expected_ver = FULL_ELEMENTOR_MCP_VERSION;
	$zip_path = $dist_dir . DIRECTORY_SEPARATOR . "safe-elementor-mcp-{$expected_ver}.zip";
	$manifest_path = $dist_dir . DIRECTORY_SEPARATOR . 'manifest.json';

	assert_true( file_exists( $zip_path ), "Release ZIP file must exist: {$zip_path}" );
	assert_true( file_exists( $manifest_path ), 'Release manifest.json must exist' );
	assert_true( ! file_exists( $dist_dir . DIRECTORY_SEPARATOR . 'safe-elementor-mcp.zip' ), 'Unversioned alias safe-elementor-mcp.zip must NOT exist' );

	$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
	assert_true( is_array( $manifest ), 'Manifest must be valid JSON' );
	assert_equals( 'Safe Elementor MCP', $manifest['product_name'] );
	assert_equals( $expected_ver, $manifest['version'] );

	$actual_zip_hash = hash_file( 'sha256', $zip_path );
	assert_equals( $actual_zip_hash, $manifest['zip_sha256'], 'Manifest SHA-256 must match physical ZIP file' );

	$zip = new ZipArchive();
	assert_true( true === $zip->open( $zip_path, ZipArchive::RDONLY ) );

	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->statIndex( $i )['name'];
		assert_true( str_starts_with( $name, 'full-elementor-mcp/' ), "Entry {$name} must start with full-elementor-mcp/" );
		assert_false( str_contains( $name, '.git/' ), "Entry {$name} must not contain .git/" );
		assert_false( str_contains( $name, 'tests/' ), "Entry {$name} must not contain tests/" );
		assert_false( str_contains( $name, 'scripts/' ), "Entry {$name} must not contain scripts/" );
	}
	$zip->close();
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
