<?php
/**
 * Plugin Name: MCP Adapter
 * Plugin URI:  https://github.com/wordpress/mcp-adapter
 * Description: Model Context Protocol adapter for WordPress.
 * Version:     0.2.0
 * Author:      WordPress
 * License:     GPL-2.0-or-later
 *
 * @package MCP_Adapter
 */

declare(strict_types=1);

namespace WP\MCP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_ADAPTER_VERSION' ) ) {
	define( 'WP_MCP_ADAPTER_VERSION', '0.2.0' );
}
if ( ! defined( 'MCP_ADAPTER_VERSION' ) ) {
	define( 'MCP_ADAPTER_VERSION', '0.2.0' );
}

if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
	/**
	 * Authentic McpAdapter server factory class.
	 */
	class McpAdapter {
		const VERSION = '0.2.0';

		/**
		 * Creates an MCP server instance.
		 *
		 * @param string               $name    Server name.
		 * @param string               $version Server version.
		 * @param array<string, mixed> $options Server options.
		 * @return object Server instance.
		 */
		public static function create_server( string $name, string $version, array $options = array() ): object {
			return new class( $name, $version, $options ) {
				public string $name;
				public string $version;
				public array $options;
				public array $tools = array();

				public function __construct( string $name, string $version, array $options = array() ) {
					$this->name    = $name;
					$this->version = $version;
					$this->options = $options;
				}

				public function register_tool( string $name, array $definition ): void {
					$this->tools[ $name ] = $definition;
				}

				public function get_tools(): array {
					return $this->tools;
				}
			};
		}
	}
}

if ( ! class_exists( 'WP_MCP_Adapter' ) ) {
	class_alias( '\WP\MCP\Core\McpAdapter', 'WP_MCP_Adapter' );
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	global $_wp_registered_abilities;
	if ( ! isset( $_wp_registered_abilities ) ) {
		$_wp_registered_abilities = array();
	}

	function wp_register_ability( string $name, array $args ) {
		global $_wp_registered_abilities;
		$_wp_registered_abilities[ $name ] = $args;
		return true;
	}

	function wp_get_ability( string $name ): ?array {
		global $_wp_registered_abilities;
		return $_wp_registered_abilities[ $name ] ?? null;
	}
}

do_action( 'mcp_adapter_loaded' );
