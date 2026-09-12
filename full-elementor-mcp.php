<?php
/**
 * Plugin Name:       Full Elementor MCP
 * Plugin URI:        https://github.com/Zainulabidin90/full-elementor-mcp
 * Description:       A WordPress MCP server that exposes the full surface of Elementor and Elementor Pro (131+ tools) to AI agents over the Model Context Protocol.
 * Version:           1.7.1
 * Requires at least: 6.9
 * Tested up to:      6.9
 * Requires PHP:      8.0
 * Author:            Zain Ul Abidin
 * Author URI:        https://www.progressiverobot.com
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       full-elementor-mcp
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'FULL_ELEMENTOR_MCP_VERSION', '1.7.1' );
define( 'FULL_ELEMENTOR_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FULL_ELEMENTOR_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'FULL_ELEMENTOR_MCP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Recursively removes empty strings from enum arrays in a JSON Schema.
 *
 * Some MCP clients (e.g. Gemini/Antigravity) reject empty string values
 * inside enum arrays. This sanitizer strips them from any schema structure,
 * including nested properties, items, and allOf/oneOf/anyOf.
 *
 * Also ensures empty `properties` objects serialize as JSON `{}` not `[]`.
 *
 * @since 1.4.3
 *
 * @param array $schema A JSON Schema array.
 * @return array The sanitized schema.
 */
function full_elementor_mcp_sanitize_schema( array $schema ): array {
	// Strip empty strings from enum arrays.
	if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
		$schema['enum'] = array_values(
			array_filter(
				$schema['enum'],
				function ( $value ) {
					return '' !== $value;
				}
			)
		);
		if ( empty( $schema['enum'] ) ) {
			unset( $schema['enum'] );
		}
	}

	// Recurse into properties.
	if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
		if ( empty( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		} else {
			foreach ( $schema['properties'] as $key => $prop ) {
				if ( is_array( $prop ) ) {
					$schema['properties'][ $key ] = full_elementor_mcp_sanitize_schema( $prop );
				}
			}
		}
	}

	// Recurse into items.
	if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
		$schema['items'] = full_elementor_mcp_sanitize_schema( $schema['items'] );
	}

	// Recurse into allOf, oneOf, anyOf.
	foreach ( array( 'allOf', 'oneOf', 'anyOf' ) as $keyword ) {
		if ( isset( $schema[ $keyword ] ) && is_array( $schema[ $keyword ] ) ) {
			foreach ( $schema[ $keyword ] as $i => $sub ) {
				if ( is_array( $sub ) ) {
					$schema[ $keyword ][ $i ] = full_elementor_mcp_sanitize_schema( $sub );
				}
			}
		}
	}

	return $schema;
}

/**
 * Wrapper around wp_register_ability that sanitizes schemas for cross-client compatibility.
 *
 * @since 1.4.3
 *
 * @param string $name    The ability name.
 * @param array  $args    The ability arguments.
 * @return mixed The result of wp_register_ability().
 */
function full_elementor_mcp_register_ability( string $name, array $args ) {
	if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
		$args['input_schema'] = full_elementor_mcp_sanitize_schema( $args['input_schema'] );
	}
	if ( isset( $args['output_schema'] ) && is_array( $args['output_schema'] ) ) {
		$args['output_schema'] = full_elementor_mcp_sanitize_schema( $args['output_schema'] );
	}
	// Phase 4: Route ability execution through central safety middleware:
	if ( ! class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) ) {
		return new \WP_Error(
			'safety_middleware_missing',
			__( 'Central safety middleware unavailable. Ability registration rejected.', 'full-elementor-mcp' )
		);
	}
	$args = Full_Elementor_MCP_Mutation_Middleware::wrap_ability( $name, $args );
	return wp_register_ability( $name, $args );
}

/**
 * Checks that all required dependencies are available.
 *
 * @since 1.0.0
 *
 * @return bool True if all dependencies are met.
 */
function full_elementor_mcp_check_dependencies(): bool {
	$missing = array();

	// Elementor must be active.
	if ( ! did_action( 'elementor/loaded' ) ) {
		$missing[] = 'Elementor';
	}

	// MCP Adapter must be active.
	if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		$missing[] = 'WordPress MCP Adapter';
	}

	// WordPress Abilities API must be available.
	if ( ! function_exists( 'wp_register_ability' ) ) {
		$missing[] = 'WordPress Abilities API (requires WordPress 6.9+)';
	}

	if ( ! empty( $missing ) ) {
		add_action( 'admin_notices', function () use ( $missing ) {
			$list = implode( ', ', $missing );
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				sprintf(
					/* translators: %s: comma-separated list of missing dependencies */
					esc_html__( 'Full Elementor MCP requires the following to be installed and active: %s', 'full-elementor-mcp' ),
					'<strong>' . esc_html( $list ) . '</strong>'
				)
			);
		} );

		return false;
	}

	return true;
}

/**
 * Initializes the plugin.
 *
 * Hooked to `plugins_loaded` at priority 20 to ensure Elementor and
 * other dependencies are loaded first.
 *
 * @since 1.0.0
 */
function full_elementor_mcp_init(): void {
	if ( ! full_elementor_mcp_check_dependencies() ) {
		return;
	}

	// Load class files.
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/class-id-generator.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/class-elementor-data.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/class-element-factory.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/schemas/class-control-mapper.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/schemas/class-schema-generator.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/validators/class-element-validator.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/validators/class-settings-validator.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-query-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-page-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-layout-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-widget-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-template-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-global-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-composite-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/class-openverse-client.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-stock-image-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-svg-icon-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-custom-code-abilities.php';
	// Atomic elements support (Elementor 4.0+).
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/class-atomic-props.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/class-atomic-styles.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-atomic-widget-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-atomic-layout-abilities.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-safety-abilities.php';

	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/abilities/class-ability-registrar.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/class-plugin.php';

	// Admin.
	if ( is_admin() ) {
		require_once FULL_ELEMENTOR_MCP_DIR . 'includes/admin/class-admin.php';
		require_once FULL_ELEMENTOR_MCP_DIR . 'includes/admin/class-safety-admin.php';
	}

	// Safety subsystem foundation (Phases 1-6).
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-database-installer.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-safety-settings.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-lock-manager.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-security-guard.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-elementor-features.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-tree-validator.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-security-strategies.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-mutation-registry.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-journal.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-mutation-context.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-safe-writes.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-confirmation-manager.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-idempotency-manager.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-checkpoint-crypto.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-checkpoint-strategies.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-checkpoint-manager.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-audit-logger.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-undo-manager.php';
	require_once FULL_ELEMENTOR_MCP_DIR . 'includes/safety/class-mutation-middleware.php';

	// Initialize mutation strategies:
	Full_Elementor_MCP_Mutation_Registry::init_core_strategies();
	if ( class_exists( 'Full_Elementor_MCP_Checkpoint_Manager' ) ) {
		Full_Elementor_MCP_Checkpoint_Manager::ensure_restore_strategy_registered();
	}

	// Execute runtime schema upgrade check to support in-place updates.
	// Fail-closed policy: block MCP initialization if safety database cannot be verified.
	$db_ready = Full_Elementor_MCP_Database_Installer::maybe_upgrade();
	if ( ! $db_ready ) {
		add_action( 'admin_notices', function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Full Elementor MCP: Safety database tables failed verification or are incompatible with this version. MCP server initialization has been blocked to protect site integrity.', 'full-elementor-mcp' )
			);
		} );
		return;
	}

	// Runtime conservative recovery hooks (throttled):
	$maybe_recover = static function () {
		if ( ! class_exists( 'Full_Elementor_MCP_Journal' ) ) {
			return;
		}
		$last_recovery = (int) get_option( 'full_elementor_mcp_last_recovery_time', 0 );
		if ( ( time() - $last_recovery ) > 60 ) {
			update_option( 'full_elementor_mcp_last_recovery_time', time() );
			Full_Elementor_MCP_Journal::recover_pending();
		}
	};
	add_action( 'admin_init', $maybe_recover );
	add_action( 'mcp_adapter_init', $maybe_recover );

	// Best-effort shutdown recovery handler for active mutation context:
	register_shutdown_function( static function () {
		if ( class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) ) {
			Full_Elementor_MCP_Mutation_Middleware::handle_shutdown();
		}
	} );

	// Boot the plugin.
	Full_Elementor_MCP_Plugin::instance();
}
add_action( 'plugins_loaded', 'full_elementor_mcp_init', 20 );

/**
 * Plugin activation hook to install safety database tables.
 */
register_activation_hook( __FILE__, function () {
	require_once plugin_dir_path( __FILE__ ) . 'includes/safety/class-database-installer.php';
	Full_Elementor_MCP_Database_Installer::install();
} );
