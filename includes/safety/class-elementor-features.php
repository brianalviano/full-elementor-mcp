<?php
/**
 * Elementor feature and capability detection.
 *
 * Provides centralized, capability-based detection for Elementor core, Pro,
 * flexbox/grid containers, nested elements, and Editor V4 / Atomic elements
 * without relying solely on numeric version strings.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects active Elementor capabilities, experiments, and registered subsystems.
 */
class Full_Elementor_MCP_Elementor_Features {

	/**
	 * Test overrides / mock capabilities.
	 *
	 * @var array<string, bool>|null
	 */
	private static ?array $mock_features = null;

	/**
	 * Sets mock feature flags for testing.
	 *
	 * @param array<string, bool>|null $features Array of feature => bool, or null to clear.
	 */
	public static function set_mock_features( ?array $features ): void {
		self::$mock_features = $features;
	}

	/**
	 * Resets mock feature overrides.
	 */
	public static function reset_mocks(): void {
		self::$mock_features = null;
	}

	/**
	 * Checks if Elementor Core is loaded and operational.
	 *
	 * @return bool True if Elementor core is active.
	 */
	public static function has_elementor(): bool {
		if ( null !== self::$mock_features && array_key_exists( 'elementor', self::$mock_features ) ) {
			return (bool) self::$mock_features['elementor'];
		}

		if ( class_exists( '\Elementor\Plugin' ) ) {
			return true;
		}

		return function_exists( 'did_action' ) && did_action( 'elementor/loaded' ) > 0;
	}

	/**
	 * Checks if Elementor Pro is active and operational.
	 *
	 * @return bool True if Elementor Pro is active.
	 */
	public static function has_elementor_pro(): bool {
		if ( null !== self::$mock_features && array_key_exists( 'elementor_pro', self::$mock_features ) ) {
			return (bool) self::$mock_features['elementor_pro'];
		}

		if ( class_exists( '\ElementorPro\Plugin' ) ) {
			return true;
		}

		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Checks if classic widget manager is available.
	 *
	 * @return bool True if widgets manager is active.
	 */
	public static function supports_classic_widgets(): bool {
		if ( null !== self::$mock_features && array_key_exists( 'classic_widgets', self::$mock_features ) ) {
			return (bool) self::$mock_features['classic_widgets'];
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
			return true;
		}

		// Fallback: if Elementor is considered active, widgets are supported by default.
		return self::has_elementor();
	}

	/**
	 * Checks if flexbox / grid containers are supported and active.
	 *
	 * Checks Elementor's Experiments manager for the 'container' feature,
	 * rather than guessing from version strings.
	 *
	 * @return bool True if container feature is active.
	 */
	public static function supports_containers(): bool {
		if ( null !== self::$mock_features && array_key_exists( 'containers', self::$mock_features ) ) {
			return (bool) self::$mock_features['containers'];
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->experiments ) ) {
			try {
				if ( method_exists( \Elementor\Plugin::$instance->experiments, 'is_feature_active' ) ) {
					return (bool) \Elementor\Plugin::$instance->experiments->is_feature_active( 'container' );
				}
			} catch ( \Throwable $e ) {
				// Fall through to class check.
			}
		}

		// Fallback capability check: Container element class exists.
		if ( class_exists( '\Elementor\Core\DocumentTypes\Page' ) && class_exists( '\Elementor\Includes\Elements\Container' ) ) {
			return true;
		}

		// Default safe assumption: containers are standard in modern Elementor (WordPress 6.9+ stack).
		return true;
	}

	/**
	 * Checks if nested elements experiment is supported and active.
	 *
	 * @return bool True if nested elements are active.
	 */
	public static function supports_nested_elements(): bool {
		if ( null !== self::$mock_features && array_key_exists( 'nested_elements', self::$mock_features ) ) {
			return (bool) self::$mock_features['nested_elements'];
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->experiments ) ) {
			try {
				if ( method_exists( \Elementor\Plugin::$instance->experiments, 'is_feature_active' ) ) {
					return (bool) \Elementor\Plugin::$instance->experiments->is_feature_active( 'nested-elements' );
				}
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Checks if Elementor 4.0+ Atomic elements / typed props system is supported.
	 *
	 * Evaluates runtime capabilities:
	 * 1. Registered atomic widget types (e.g. 'e-div-block', 'e-flexbox').
	 * 2. Atomic element experiment or module existence.
	 * 3. Atomic props helper class presence.
	 *
	 * Does NOT rely solely on numeric version comparison.
	 *
	 * @return bool True if atomic elements are available.
	 */
	public static function supports_atomic_elements(): bool {
		if ( null !== self::$mock_features && array_key_exists( 'atomic_elements', self::$mock_features ) ) {
			return (bool) self::$mock_features['atomic_elements'];
		}

		// Check if atomic container types or modules are registered in Elementor runtime.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->elements_manager ) ) {
			try {
				if ( method_exists( \Elementor\Plugin::$instance->elements_manager, 'get_element_types' ) ) {
					$types = \Elementor\Plugin::$instance->elements_manager->get_element_types();
					if ( is_array( $types ) && ( isset( $types['e-div-block'] ) || isset( $types['e-flexbox'] ) ) ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				// Fall through.
			}
		}

		// Check experiments manager for atomic/v4 experiment if available.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->experiments ) ) {
			try {
				if ( method_exists( \Elementor\Plugin::$instance->experiments, 'is_feature_active' ) ) {
					if ( \Elementor\Plugin::$instance->experiments->is_feature_active( 'atomic_widgets' ) ||
						\Elementor\Plugin::$instance->experiments->is_feature_active( 'editor_v4' ) ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				// Fall through.
			}
		}

		// Check for atomic module namespace or class existence.
		if ( class_exists( '\Elementor\Modules\AtomicWidgets\Module' ) || class_exists( '\Elementor\Core\Editor\Editor_V4' ) ) {
			return true;
		}

		// Check if atomic props class is loaded and runtime signals atomic support.
		if ( class_exists( 'Full_Elementor_MCP_Atomic_Props' ) ) {
			// Check if runtime has active atomic widgets registered via filter or option.
			$has_atomic_opt = get_option( 'elementor_atomic_widgets_active', false );
			if ( ! empty( $has_atomic_opt ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns normalized snapshot of all active feature capabilities.
	 *
	 * @return array<string, bool>
	 */
	public static function get_active_features(): array {
		return array(
			'elementor'        => self::has_elementor(),
			'elementor_pro'    => self::has_elementor_pro(),
			'classic_widgets'  => self::supports_classic_widgets(),
			'containers'       => self::supports_containers(),
			'nested_elements'  => self::supports_nested_elements(),
			'atomic_elements'  => self::supports_atomic_elements(),
		);
	}
}
