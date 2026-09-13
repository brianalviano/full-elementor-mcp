<?php
/**
 * Security guard for credential scoping, critical asset protection, and SSRF defense.
 *
 * Provides:
 * 1. Native identity-bound credential scope verification (via rest_get_authenticated_app_password).
 * 2. Critical asset guard for core site pages (page_on_front, WooCommerce, active kit).
 * 3. Defense-in-depth SSRF URL & IP resolution validator.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles security policies, scope gates, asset guards, and URL filtering.
 */
class Full_Elementor_MCP_Security_Guard {

	/**
	 * Option name for credential scope mappings.
	 */
	public const OPTION_SCOPES = 'full_elementor_mcp_credential_scopes';

	/**
	 * Known metadata and internal hostnames.
	 */
	private const BLOCKED_HOSTNAMES = array(
		'localhost',
		'metadata.google.internal',
		'instance-data',
	);

	/**
	 * Resolves active authenticated credential UUID.
	 *
	 * Uses WordPress core rest_get_authenticated_app_password() which returns a string UUID or null.
	 *
	 * @return string|null Authenticated Application Password UUID, or null if not authenticated via App Password.
	 */
	public static function get_authenticated_credential_uuid(): ?string {
		if ( function_exists( 'rest_get_authenticated_app_password' ) ) {
			$uuid = rest_get_authenticated_app_password();
			if ( is_string( $uuid ) && '' !== trim( $uuid ) ) {
				return sanitize_text_field( trim( $uuid ) );
			}
			if ( is_array( $uuid ) && ! empty( $uuid['uuid'] ) && is_string( $uuid['uuid'] ) ) {
				// Defensive fallback in case custom filter returns an array.
				return sanitize_text_field( trim( $uuid['uuid'] ) );
			}
		}

		// Fallback check on WordPress REST global.
		if ( ! empty( $GLOBALS['wp_rest_application_password_uuid'] ) && is_string( $GLOBALS['wp_rest_application_password_uuid'] ) ) {
			return sanitize_text_field( trim( $GLOBALS['wp_rest_application_password_uuid'] ) );
		}

		return null;
	}

	/**
	 * Resolves the scope configuration bound strictly to user ID and optional credential UUID.
	 *
	 * @param int         $user_id         WordPress user ID.
	 * @param string|null $credential_uuid Optional Application Password UUID.
	 * @return array{mode: string, user_id: int, credential_uuid: ?string, allowed_tools: string[], blocked_tools: string[]}
	 */
	public static function resolve_scope_for_user_and_credential( int $user_id, ?string $credential_uuid = null ): array {
		$all_scopes  = get_option( self::OPTION_SCOPES, array() );
		$user_scopes = ( is_array( $all_scopes ) && isset( $all_scopes[ $user_id ] ) && is_array( $all_scopes[ $user_id ] ) )
			? $all_scopes[ $user_id ]
			: array();

		$scope_config = null;
		// 1. Strict match on credential UUID if provided.
		if ( ! empty( $credential_uuid ) && isset( $user_scopes[ $credential_uuid ] ) && is_array( $user_scopes[ $credential_uuid ] ) ) {
			$scope_config = $user_scopes[ $credential_uuid ];
		} elseif ( isset( $user_scopes['default'] ) && is_array( $user_scopes['default'] ) ) {
			// 2. Default user scope fallback.
			$scope_config = $user_scopes['default'];
		}

		// 3. Fallback mode if not explicitly configured:
		// Safe fail-closed policy: only administrators with 'manage_options' receive 'full' access by default.
		// All other users (authors, editors, contributors) or unauthenticated callers default to 'read_only'.
		$default_mode = 'read_only';
		if ( $user_id > 0 && function_exists( 'user_can' ) && user_can( $user_id, 'manage_options' ) ) {
			$default_mode = 'full';
		}

		/**
		 * Filters the default fallback scope mode when no explicit credential or user mapping exists.
		 *
		 * @param string      $default_mode    Default mode ('read_only' or 'full').
		 * @param int         $user_id         Target WordPress user ID.
		 * @param string|null $credential_uuid Optional Application Password UUID.
		 */
		$default_mode = apply_filters( 'full_elementor_mcp_default_scope_mode', $default_mode, $user_id, $credential_uuid );

		$mode          = sanitize_key( $scope_config['mode'] ?? $default_mode );
		$allowed_tools = isset( $scope_config['allowed_tools'] ) && is_array( $scope_config['allowed_tools'] )
			? array_values( array_map( 'sanitize_text_field', $scope_config['allowed_tools'] ) )
			: array();
		$blocked_tools = isset( $scope_config['blocked_tools'] ) && is_array( $scope_config['blocked_tools'] )
			? array_values( array_map( 'sanitize_text_field', $scope_config['blocked_tools'] ) )
			: array();

		return array(
			'mode'            => in_array( $mode, array( 'full', 'read_only', 'custom' ), true ) ? $mode : 'read_only',
			'user_id'         => $user_id,
			'credential_uuid' => $credential_uuid,
			'allowed_tools'   => $allowed_tools,
			'blocked_tools'   => $blocked_tools,
		);
	}

	/**
	 * Resolves active authenticated credential identity and its bound scope.
	 *
	 * Utilizes WordPress core rest_get_authenticated_app_password() when available.
	 *
	 * @return array{mode: string, user_id: int, credential_uuid: ?string, allowed_tools: string[], blocked_tools: string[]}
	 */
	public static function resolve_current_scope(): array {
		$user_id         = get_current_user_id();
		$credential_uuid = self::get_authenticated_credential_uuid();

		$scope = self::resolve_scope_for_user_and_credential( $user_id, $credential_uuid );

		/**
		 * Filters the resolved credential scope.
		 *
		 * @param array $scope Scope details array.
		 */
		$filtered = apply_filters( 'full_elementor_mcp_current_scope', $scope );

		// Harden against malformed filter return: ensure array structure and strictly validate mode.
		if ( ! is_array( $filtered ) || empty( $filtered['mode'] ) || ! is_string( $filtered['mode'] ) ) {
			$filtered = array( 'mode' => 'read_only' );
		}

		$mode          = sanitize_key( (string) $filtered['mode'] );
		$allowed_tools = isset( $filtered['allowed_tools'] ) && is_array( $filtered['allowed_tools'] )
			? array_values( array_map( 'sanitize_text_field', $filtered['allowed_tools'] ) )
			: array();
		$blocked_tools = isset( $filtered['blocked_tools'] ) && is_array( $filtered['blocked_tools'] )
			? array_values( array_map( 'sanitize_text_field', $filtered['blocked_tools'] ) )
			: array();

		return array(
			'mode'            => in_array( $mode, array( 'full', 'read_only', 'custom' ), true ) ? $mode : 'read_only',
			'user_id'         => (int) ( $filtered['user_id'] ?? $user_id ),
			'credential_uuid' => isset( $filtered['credential_uuid'] ) && is_string( $filtered['credential_uuid'] ) ? sanitize_text_field( $filtered['credential_uuid'] ) : null,
			'allowed_tools'   => $allowed_tools,
			'blocked_tools'   => $blocked_tools,
		);
	}

	/**
	 * Checks whether a specific ability is permitted under the given scope.
	 *
	 * Strict fail-closed policy:
	 * - 'read_only': only abilities with annotations['readonly'] = true
	 * - 'custom': requires non-empty allowlist containing ability, not blocked
	 * - 'full': explicitly permitted
	 * - missing / malformed / unknown mode: strictly DENIED (fail closed)
	 *
	 * @param string     $ability     The ability slug (e.g. 'full-elementor-mcp/create-page').
	 * @param array      $annotations Ability annotations array (from ability registration).
	 * @param array|null $scope       Resolved scope array, or null to auto-resolve.
	 * @return bool True if allowed.
	 */
	public static function is_ability_in_scope( string $ability, array $annotations, ?array $scope = null ): bool {
		// 1. Global disabled tools gate: applies to ALL scopes and modes.
		$disabled_tools = get_option( 'full_elementor_mcp_disabled_tools', array() );
		if ( is_array( $disabled_tools ) && in_array( $ability, $disabled_tools, true ) ) {
			return false;
		}

		if ( null === $scope ) {
			$scope = self::resolve_current_scope();
		}

		// Fail closed on missing, malformed, or non-array scope.
		if ( ! is_array( $scope ) || empty( $scope['mode'] ) || ! is_string( $scope['mode'] ) ) {
			return false;
		}

		// Strict fail-closed: readonly annotation must be literal boolean true.
		$is_readonly = isset( $annotations['readonly'] ) && true === $annotations['readonly'];

		// 2. Read-Only scope: ONLY readonly abilities are allowed.
		if ( 'read_only' === $scope['mode'] ) {
			return $is_readonly;
		}

		// 3. Custom scope: FAIL-CLOSED. Must have non-empty allowlist containing the ability, and not blocked.
		if ( 'custom' === $scope['mode'] ) {
			if ( empty( $scope['allowed_tools'] ) || ! is_array( $scope['allowed_tools'] ) ) {
				// Empty allowlist in custom scope denies all abilities (fail-closed).
				return false;
			}
			if ( ! in_array( $ability, $scope['allowed_tools'], true ) ) {
				return false;
			}
			if ( ! empty( $scope['blocked_tools'] ) && is_array( $scope['blocked_tools'] ) && in_array( $ability, $scope['blocked_tools'], true ) ) {
				return false;
			}
			return true;
		}

		// 4. Full scope: explicitly allowed (subject to global disabled gate).
		if ( 'full' === $scope['mode'] ) {
			return true;
		}

		// 5. Unknown / malformed mode: STRICTLY DENY (fail closed). Never fallback to permissive true.
		return false;
	}

	/**
	 * Returns array of all critical post/page IDs that must not be deleted or wiped without break-glass approval.
	 *
	 * @return array<int, string> Associative array of post ID => critical asset label.
	 */
	public static function get_critical_assets(): array {
		$critical = array();

		// Front page.
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id > 0 ) {
			$critical[ $front_page_id ] = __( 'Homepage (Front Page)', 'full-elementor-mcp' );
		}

		// Posts page (Blog).
		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id > 0 ) {
			$critical[ $posts_page_id ] = __( 'Blog / Posts Index Page', 'full-elementor-mcp' );
		}

		// WooCommerce core pages.
		if ( function_exists( 'wc_get_page_id' ) ) {
			$wc_pages = array(
				'shop'      => __( 'WooCommerce Shop Page', 'full-elementor-mcp' ),
				'cart'      => __( 'WooCommerce Cart Page', 'full-elementor-mcp' ),
				'checkout'  => __( 'WooCommerce Checkout Page', 'full-elementor-mcp' ),
				'myaccount' => __( 'WooCommerce My Account Page', 'full-elementor-mcp' ),
			);
			foreach ( $wc_pages as $slug => $label ) {
				$pid = (int) wc_get_page_id( $slug );
				if ( $pid > 0 ) {
					$critical[ $pid ] = $label;
				}
			}
		}

		// Elementor Active Kit.
		$active_kit_id = (int) get_option( 'elementor_active_kit' );
		if ( $active_kit_id > 0 ) {
			$critical[ $active_kit_id ] = __( 'Elementor Active Site Settings Kit', 'full-elementor-mcp' );
		}

		// Custom configured protected page IDs.
		$custom_ids = Full_Elementor_MCP_Safety_Settings::get( 'custom_protected_page_ids', array() );
		if ( is_array( $custom_ids ) ) {
			foreach ( $custom_ids as $cid ) {
				$cid = (int) $cid;
				if ( $cid > 0 && ! isset( $critical[ $cid ] ) ) {
					$critical[ $cid ] = sprintf(
						/* translators: %d: page ID */
						__( 'Custom Protected Page (ID %d)', 'full-elementor-mcp' ),
						$cid
					);
				}
			}
		}

		return $critical;
	}

	/**
	 * Checks whether a post ID is considered a critical site asset.
	 *
	 * @param int $post_id Post ID to inspect.
	 * @return bool True if critical asset.
	 */
	public static function is_critical_asset( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$critical = self::get_critical_assets();
		return isset( $critical[ $post_id ] );
	}

	/**
	 * Gets the human-readable label for a critical asset.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Label or null if not critical.
	 */
	public static function get_critical_asset_label( int $post_id ): ?string {
		$critical = self::get_critical_assets();
		return $critical[ $post_id ] ?? null;
	}

	/**
	 * Asserts whether a destructive mutation on a target post ID is permitted by policy.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $input   Input parameters array.
	 * @return true|\WP_Error True if permitted, WP_Error if blocked.
	 */
	public static function assert_critical_asset_mutation_allowed( int $post_id, array $input ) {
		if ( ! self::is_critical_asset( $post_id ) ) {
			return true;
		}

		$label  = self::get_critical_asset_label( $post_id );
		$policy = Full_Elementor_MCP_Safety_Settings::get_break_glass_policy();

		if ( Full_Elementor_MCP_Safety_Settings::POLICY_DISABLED === $policy ) {
			return new \WP_Error(
				'critical_asset_protected',
				sprintf(
					/* translators: %s: asset label */
					__( 'Action prohibited: "%s" is a critical site asset and break-glass operations are disabled on this site.', 'full-elementor-mcp' ),
					esc_html( $label ?? (string) $post_id )
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'insufficient_critical_privilege',
				sprintf(
					/* translators: %s: asset label */
					__( 'Modifying or deleting critical site asset "%s" requires administrator (manage_options) privileges.', 'full-elementor-mcp' ),
					esc_html( $label ?? (string) $post_id )
				)
			);
		}

		if ( ! isset( $input['allow_critical_override'] ) || true !== $input['allow_critical_override'] ) {
			return new \WP_Error(
				'critical_override_required',
				sprintf(
					/* translators: %s: asset label */
					__( 'Safety Guard: "%s" is a critical site asset. To proceed with this destructive action, you must explicitly set allow_critical_override: true.', 'full-elementor-mcp' ),
					esc_html( $label ?? (string) $post_id )
				),
				array(
					'post_id'     => $post_id,
					'asset_label' => $label,
				)
			);
		}

		return true;
	}

	/**
	 * Defense-in-depth URL and IP validation for remote downloads.
	 *
	 * Delegates directly to the authoritative Security Strategies layer.
	 *
	 * @param string $url External URL to validate.
	 * @return true|\WP_Error True if valid, WP_Error if unsafe.
	 */
	public static function validate_remote_url( string $url ) {
		if ( ! class_exists( 'Full_Elementor_MCP_Security_Strategies' ) ) {
			$file = __DIR__ . '/class-security-strategies.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( class_exists( 'Full_Elementor_MCP_Security_Strategies' ) ) {
			return Full_Elementor_MCP_Security_Strategies::validate_url( $url );
		}

		return new \WP_Error(
			'security_infrastructure_unavailable',
			__( 'Security strategies layer is unavailable.', 'full-elementor-mcp' )
		);
	}

	/**
	 * Checks if an IP address falls in loopback, private, link-local, or cloud metadata ranges.
	 *
	 * Delegates to authoritative Security Strategies validator.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool True if forbidden.
	 */
	public static function is_forbidden_ip( string $ip ): bool {
		if ( ! class_exists( 'Full_Elementor_MCP_Security_Strategies' ) ) {
			$file = __DIR__ . '/class-security-strategies.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( class_exists( 'Full_Elementor_MCP_Security_Strategies' ) ) {
			return Full_Elementor_MCP_Security_Strategies::is_forbidden_ip( $ip );
		}

		// Fail closed: if security strategies cannot load, treat IP as forbidden.
		return true;
	}
}
