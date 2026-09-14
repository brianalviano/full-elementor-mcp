<?php
/**
 * Worker subprocess for multi-process concurrency testing.
 *
 * Usage:
 * php tests/worker-lock-runner.php --db=<path> --worker=<id> --resource=<key> --hold-ms=<int> [--crash]
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

$opts = getopt( '', array( 'db:', 'worker:', 'resource:', 'hold-ms:', 'crash' ) );
$db_path      = $opts['db'] ?? '';
$worker_id    = $opts['worker'] ?? 'worker-' . getmypid();
$resource     = $opts['resource'] ?? 'post:100';
$hold_ms      = isset( $opts['hold-ms'] ) ? (int) $opts['hold-ms'] : 100;
$should_crash = isset( $opts['crash'] );

if ( empty( $db_path ) || ! file_exists( $db_path ) ) {
	echo json_encode( array( 'error' => 'Database file not found: ' . $db_path ) );
	exit( 1 );
}

// Minimal environment setup
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

// Disk-backed SQLite WPDB mock
class WorkerDiskWpdb {
	public string $prefix = 'wp_';
	public ?\PDO $pdo = null;

	public function __construct( string $path ) {
		$this->pdo = new \PDO( 'sqlite:' . $path );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'PRAGMA journal_mode = WAL;' );
		$this->pdo->exec( 'PRAGMA busy_timeout = 5000;' );

		$this->pdo->sqliteCreateFunction( 'UTC_TIMESTAMP', function () {
			return gmdate( 'Y-m-d H:i:s' );
		} );
		$this->pdo->sqliteCreateFunction( 'DATE_SUB', function ( $date, $interval ) {
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
			if ( is_int( $val ) || is_float( $val ) ) {
				return (string) $val;
			}
			return $this->pdo->quote( (string) $val );
		}, $query );
	}

	public function query( string $sql ): mixed {
		try {
			$sql = $this->translate_sql( $sql );
			$res = $this->pdo->exec( $sql );
			return false !== $res ? $res : false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private function translate_sql( string $sql ): string {
		$sql = preg_replace( '/DATE_ADD\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+SECOND\s*\)/i', "datetime($1, '+$2 seconds')", $sql );
		$sql = preg_replace( '/DATE_SUB\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([0-9+-]+)\s+SECOND\s*\)/i', "datetime($1, '-$2 seconds')", $sql );
		$sql = preg_replace( '/GREATEST\s*\(\s*([^,]+?)\s*,\s*([^)]+?)\s*\)/i', 'max($1, $2)', $sql );
		return $sql;
	}

	public function get_row( string $query, string $output = OBJECT ): mixed {
		$query = $this->translate_sql( $query );
		$stmt  = $this->pdo->query( $query );
		$row   = $stmt ? $stmt->fetch( \PDO::FETCH_ASSOC ) : null;
		if ( ! $row ) {
			return null;
		}
		return OBJECT === $output ? (object) $row : $row;
	}

	public function get_var( string $query ): mixed {
		$query = $this->translate_sql( $query );
		$stmt  = $this->pdo->query( $query );
		$val   = $stmt ? $stmt->fetchColumn() : false;
		return false === $val ? null : $val;
	}
}

global $wpdb;
$wpdb = new WorkerDiskWpdb( $db_path );

require_once dirname( __DIR__ ) . '/includes/safety/class-database-installer.php';
require_once dirname( __DIR__ ) . '/includes/safety/class-safety-settings.php';
require_once dirname( __DIR__ ) . '/includes/safety/class-lock-manager.php';

// Try acquiring lock with owner = $worker_id, ttl = 10s
$res = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource, $worker_id, 10 );

if ( is_wp_error( $res ) ) {
	echo json_encode( array(
		'worker'        => $worker_id,
		'acquired'      => false,
		'fencing_token' => 0,
		'error'         => $res->get_error_code(),
		'message'       => $res->get_error_message(),
	) );
	exit( 0 );
}

if ( empty( $res['acquired'] ) ) {
	echo json_encode( array(
		'worker'        => $worker_id,
		'acquired'      => false,
		'fencing_token' => 0,
		'error'         => 'lock_held',
	) );
	exit( 0 );
}

$fencing_token = (int) $res['fencing_token'];

// If simulating crash, exit immediately with exit code 99 while holding lock
if ( $should_crash ) {
	echo json_encode( array(
		'worker'        => $worker_id,
		'acquired'      => true,
		'fencing_token' => $fencing_token,
		'crashed'       => true,
	) );
	exit( 99 );
}

// Hold lock for specified ms
if ( $hold_ms > 0 ) {
	usleep( $hold_ms * 1000 );
}

// Release lock
Full_Elementor_MCP_Lock_Manager::release_lock( $resource, $worker_id, $fencing_token );

echo json_encode( array(
	'worker'        => $worker_id,
	'acquired'      => true,
	'fencing_token' => $fencing_token,
	'released'      => true,
) );
exit( 0 );
