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

// 1. Drop safety database tables.
require_once __DIR__ . '/includes/safety/class-database-installer.php';
Full_Elementor_MCP_Database_Installer::drop_tables();

// 2. Remove all Phase 1 plugin options.
delete_option( 'full_elementor_mcp_disabled_tools' );
delete_option( 'full_elementor_mcp_db_version' );
delete_option( 'full_elementor_mcp_safety_settings' );
delete_option( 'full_elementor_mcp_credential_scopes' );
