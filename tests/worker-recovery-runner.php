<?php
/**
 * Fresh PHP Process Runner for Crash Recovery.
 *
 * Bootstraps the safety subsystem in a pristine PHP process to recover
 * abandoned states left by crashed worker processes.
 *
 * Usage: php tests/worker-recovery-runner.php --grace=10
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
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
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $x ): int {
		return abs( (int) $x );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $t, string $d = 'default' ): string {
		return $t;
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
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
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $tag ): int {
		return 1;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $val, ...$args ): mixed {
		return $val;
	}
}
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( mixed $val ): mixed {
		return $val;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $val ): mixed {
		return $val;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		return $single ? '' : array();
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int|WP_Post|null $post = null ): ?object {
		return (object) array( 'ID' => absint( $post ), 'post_status' => 'publish' );
	}
}

require_once FULL_ELEMENTOR_MCP_DIR . 'tests/test-mysql-safety.php';

$pdo = get_mysql_pdo();
global $wpdb;
$wpdb = new Real_MySQL_WPDB( $pdo );

require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-database-installer.php';
require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-lock-manager.php';
require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-mutation-registry.php';
require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-journal.php';

$options = getopt( '', array( 'grace:' ) );
$grace   = (int) ( $options['grace'] ?? 10 );

$reports = Full_Elementor_MCP_Journal::recover_pending( $grace );

echo json_encode( array(
	'success' => true,
	'reports' => $reports,
) ) . "\n";

exit( 0 );
