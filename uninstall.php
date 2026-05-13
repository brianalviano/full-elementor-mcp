<?php
/**
 * Uninstall handler for Full Elementor MCP.
 *
 * Cleans up all plugin data when the plugin is uninstalled
 * through the WordPress admin.
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'full_elementor_mcp_disabled_tools' );
delete_option( 'full_elementor_mcp_openverse_api_key' );
