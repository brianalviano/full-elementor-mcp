<?php
/**
 * Compatibility and Environment Diagnostic Engine for Safe Elementor MCP.
 *
 * Verifies system prerequisites, PHP/WordPress runtime requirements,
 * Elementor/Pro status, MCP adapter availability, and crypto backend health.
 *
 * @package Safe_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central compatibility checker and diagnostics provider.
 */
class Full_Elementor_MCP_Compatibility_Checker {

	public const MIN_PHP_VERSION       = '8.0';
	public const MIN_WP_VERSION        = '6.9';
	public const TESTED_WP_VER         = '7.1';
	public const MIN_ELEMENTOR_VERSION = '3.20.0';
	public const MIN_MCP_ADAPTER_VER   = '0.1.0';

	/**
	 * Checks PHP version requirement.
	 *
	 * @return array<string, mixed>
	 */
	public static function check_php(): array {
		$current   = PHP_VERSION;
		$supported = version_compare( $current, self::MIN_PHP_VERSION, '>=' );

		return array(
			'supported' => $supported,
			'current'   => $current,
			'required'  => self::MIN_PHP_VERSION,
			'message'   => $supported
				? sprintf( 'PHP %s satisfies minimum %s.', $current, self::MIN_PHP_VERSION )
				: sprintf( 'PHP %s is unsupported. Minimum required is %s.', $current, self::MIN_PHP_VERSION ),
		);
	}

	/**
	 * Checks WordPress version requirement.
	 *
	 * @return array<string, mixed>
	 */
	public static function check_wordpress(): array {
		global $wp_version;
		$current   = $wp_version ?? '0.0.0';
		$supported = version_compare( $current, self::MIN_WP_VERSION, '>=' );

		return array(
			'supported'     => $supported,
			'meets_minimum' => $supported,
			'version'       => $current,
			'current'       => $current,
			'required'      => self::MIN_WP_VERSION,
			'tested_up_to'  => self::TESTED_WP_VER,
			'message'       => $supported
				? sprintf( 'WordPress %s satisfies minimum %s.', $current, self::MIN_WP_VERSION )
				: sprintf( 'WordPress %s is unsupported. Minimum required is %s.', $current, self::MIN_WP_VERSION ),
		);
	}

	/**
	 * Checks Elementor and Elementor Pro availability and version.
	 *
	 * @return array<string, mixed>
	 */
	public static function check_elementor(): array {
		$loaded      = ( function_exists( 'did_action' ) && did_action( 'elementor/loaded' ) ) || defined( 'ELEMENTOR_VERSION' );
		$version     = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'not loaded';
		$pro_loaded  = defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\ElementorPro\Plugin' );
		$pro_version = defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : ( $pro_loaded ? 'active' : 'not loaded' );

		$supported = false;
		$message   = 'Elementor is not loaded.';

		if ( $loaded && 'not loaded' !== $version ) {
			$supported = version_compare( $version, self::MIN_ELEMENTOR_VERSION, '>=' );
			$message   = $supported
				? sprintf( 'Elementor %s satisfies minimum %s.', $version, self::MIN_ELEMENTOR_VERSION )
				: sprintf( 'Elementor %s is unsupported. Minimum required is %s.', $version, self::MIN_ELEMENTOR_VERSION );
		}

		return array(
			'loaded'        => (bool) $loaded,
			'installed'     => (bool) $loaded,
			'active'        => (bool) $loaded,
			'version'       => (string) $version,
			'supported'     => (bool) $supported,
			'meets_minimum' => (bool) $supported,
			'required'      => self::MIN_ELEMENTOR_VERSION,
			'message'       => $message,
			'pro_loaded'    => (bool) $pro_loaded,
			'pro_version'   => (string) $pro_version,
		);
	}

	/**
	 * Checks WordPress MCP Adapter availability and API contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function check_mcp_adapter(): array {
		$loaded = class_exists( '\WP\MCP\Core\McpAdapter' ) || class_exists( 'WP_MCP_Adapter' );
		$version = 'unknown';
		if ( defined( '\WP\MCP\Core\McpAdapter::VERSION' ) ) {
			$version = constant( '\WP\MCP\Core\McpAdapter::VERSION' );
		} elseif ( defined( 'WP_MCP_VERSION' ) ) {
			$version = constant( 'WP_MCP_VERSION' );
		} elseif ( defined( 'WP_MCP_ADAPTER_VERSION' ) ) {
			$version = constant( 'WP_MCP_ADAPTER_VERSION' );
		}

		$has_api = false;
		if ( $loaded ) {
			$cls = class_exists( '\WP\MCP\Core\McpAdapter' ) ? '\WP\MCP\Core\McpAdapter' : 'WP_MCP_Adapter';
			$has_api = method_exists( $cls, 'create_server' );
		}

		$version_ok = ( 'unknown' === $version || version_compare( $version, self::MIN_MCP_ADAPTER_VER, '>=' ) );
		$supported  = $loaded && $has_api && $version_ok;

		$message = 'WordPress MCP Adapter detected and compatible.';
		if ( ! $loaded ) {
			$message = 'WordPress MCP Adapter plugin not loaded.';
		} elseif ( ! $has_api ) {
			$message = 'WordPress MCP Adapter is loaded but does not implement required create_server() API.';
		} elseif ( ! $version_ok ) {
			$message = sprintf( 'WordPress MCP Adapter %s is unsupported. Minimum required is %s.', $version, self::MIN_MCP_ADAPTER_VER );
		}

		return array(
			'loaded'        => (bool) $loaded,
			'installed'     => (bool) $loaded,
			'active'        => (bool) $loaded,
			'version'       => (string) $version,
			'supported'     => (bool) $supported,
			'meets_minimum' => (bool) $supported,
			'required'      => self::MIN_MCP_ADAPTER_VER,
			'has_api'       => (bool) $has_api,
			'message'       => $message,
		);
	}

	/**
	 * Checks WordPress Abilities API availability.
	 *
	 * @return array<string, mixed>
	 */
	public static function check_abilities_api(): array {
		$available = function_exists( 'wp_register_ability' );
		return array(
			'available' => (bool) $available,
		);
	}

	/**
	 * Checks checkpoint crypto backend availability.
	 *
	 * @return array<string, mixed>
	 */
	public static function check_crypto(): array {
		$has_sodium  = extension_loaded( 'sodium' ) && function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' );
		$has_openssl = extension_loaded( 'openssl' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
		$backend     = $has_sodium ? 'sodium_xchacha20poly1305' : ( $has_openssl ? 'openssl_aes256gcm' : 'none' );
		$key_defined = defined( 'FULL_ELEMENTOR_MCP_CHECKPOINT_KEY' ) && ! empty( constant( 'FULL_ELEMENTOR_MCP_CHECKPOINT_KEY' ) );

		return array(
			'supported'   => $has_sodium || $has_openssl,
			'backend'     => $backend,
			'has_sodium'  => $has_sodium,
			'has_openssl' => $has_openssl,
			'key_defined' => $key_defined,
		);
	}

	/**
	 * Evaluates hard activation prerequisites.
	 *
	 * Returns WP_Error if hard activation requirements fail, or true if compatible.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_activation_prerequisites() {
		$php = self::check_php();
		if ( ! $php['supported'] ) {
			return new \WP_Error(
				'unsupported_php_version',
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'Safe Elementor MCP requires PHP %1$s or higher. Your server is running PHP %2$s. Plugin activation aborted.', 'full-elementor-mcp' ),
					self::MIN_PHP_VERSION,
					$php['current']
				)
			);
		}

		$wp = self::check_wordpress();
		if ( ! $wp['supported'] ) {
			return new \WP_Error(
				'unsupported_wordpress_version',
				sprintf(
					/* translators: 1: required WordPress version, 2: current WordPress version */
					__( 'Safe Elementor MCP requires WordPress %1$s or higher. Your site is running WordPress %2$s. Plugin activation aborted.', 'full-elementor-mcp' ),
					self::MIN_WP_VERSION,
					$wp['current']
				)
			);
		}

		$elem = self::check_elementor();
		if ( ! $elem['loaded'] || ! $elem['supported'] ) {
			return new \WP_Error(
				'missing_dependency',
				sprintf(
					/* translators: 1: required Elementor version, 2: current version */
					__( 'Safe Elementor MCP requires Elementor %1$s or higher to be installed and active. Current: %2$s. Plugin activation aborted.', 'full-elementor-mcp' ),
					self::MIN_ELEMENTOR_VERSION,
					$elem['version']
				)
			);
		}

		return true;
	}

	/**
	 * Returns true if Elementor is loaded and meets the minimum version requirement.
	 *
	 * @return bool
	 */
	public static function is_elementor_active(): bool {
		$elem = self::check_elementor();
		return $elem['loaded'] && $elem['supported'];
	}

	/**
	 * Returns true if Elementor Pro is active.
	 *
	 * @return bool
	 */
	public static function is_elementor_pro_active(): bool {
		$elem = self::check_elementor();
		return (bool) $elem['pro_loaded'];
	}

	/**
	 * Returns true if WordPress MCP Adapter is active.
	 *
	 * @return bool
	 */
	public static function is_mcp_adapter_active(): bool {
		$mcp = self::check_mcp_adapter();
		return $mcp['loaded'] && $mcp['supported'];
	}

	/**
	 * Returns true if WordPress MCP Adapter is supported and meets minimum requirements.
	 *
	 * @return bool
	 */
	public static function is_mcp_adapter_supported(): bool {
		$mcp = self::check_mcp_adapter();
		return ! empty( $mcp['supported'] );
	}

	/**
	 * Returns the WordPress MCP Adapter version.
	 *
	 * @return string
	 */
	public static function get_mcp_adapter_version(): string {
		$mcp = self::check_mcp_adapter();
		return (string) $mcp['version'];
	}

	/**
	 * Returns list of missing human-readable dependency labels for admin notices.
	 *
	 * @return string[]
	 */
	public static function get_missing_dependencies(): array {
		$missing = array();

		$elem = self::check_elementor();
		if ( ! $elem['loaded'] ) {
			$missing[] = sprintf( 'Elementor (>= %s)', self::MIN_ELEMENTOR_VERSION );
		} elseif ( ! $elem['supported'] ) {
			$missing[] = sprintf( 'Elementor (>= %s) (current version %s is unsupported)', self::MIN_ELEMENTOR_VERSION, $elem['version'] );
		}

		$mcp = self::check_mcp_adapter();
		if ( ! $mcp['loaded'] ) {
			$missing[] = 'WordPress MCP Adapter plugin';
		} elseif ( ! $mcp['has_api'] ) {
			$missing[] = 'WordPress MCP Adapter (missing required create_server API)';
		} elseif ( ! $mcp['supported'] ) {
			$missing[] = sprintf( 'WordPress MCP Adapter (>= %s, current version %s is unsupported)', self::MIN_MCP_ADAPTER_VER, $mcp['version'] );
		}

		$abi = self::check_abilities_api();
		if ( ! $abi['available'] ) {
			$missing[] = 'WordPress Abilities API (WordPress 6.9+ or Abilities API feature plugin)';
		}

		$crypto = self::check_crypto();
		if ( ! $crypto['supported'] ) {
			$missing[] = 'PHP sodium extension or openssl extension with AES-256-GCM (required for encrypted checkpoints)';
		}

		return $missing;
	}

	/**
	 * Returns comprehensive system diagnostics summary.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_system_diagnostics(): array {
		$php       = self::check_php();
		$wp        = self::check_wordpress();
		$elem      = self::check_elementor();
		$mcp       = self::check_mcp_adapter();
		$abi       = self::check_abilities_api();
		$crypto    = self::check_crypto();
		$missing   = self::get_missing_dependencies();

		$status = 'healthy';
		if ( ! $php['supported'] || ! $wp['supported'] || ! $crypto['supported'] ) {
			$status = 'incompatible';
		} elseif ( ! empty( $missing ) || ( $elem['loaded'] && ! $elem['supported'] ) ) {
			$status = 'degraded';
		}

		return array(
			'product'      => 'Safe Elementor MCP',
			'version'      => defined( 'FULL_ELEMENTOR_MCP_VERSION' ) ? constant( 'FULL_ELEMENTOR_MCP_VERSION' ) : '1.8.0',
			'status'       => $status,
			'php_compatible'   => $php['supported'],
			'wp_compatible'    => $wp['supported'],
			'crypto_available' => $crypto['supported'],
			'php'          => $php,
			'wordpress'    => $wp,
			'elementor'    => $elem,
			'mcp_adapter'  => $mcp,
			'abilities_api'=> $abi,
			'crypto'       => $crypto,
			'missing'      => $missing,
			'multisite'    => function_exists( 'is_multisite' ) && is_multisite(),
		);
	}

	/**
	 * Alias for get_system_diagnostics().
	 *
	 * @return array<string, mixed>
	 */
	public static function check_system(): array {
		return self::get_system_diagnostics();
	}
}
