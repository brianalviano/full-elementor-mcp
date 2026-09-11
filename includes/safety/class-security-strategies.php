<?php
/**
 * Security strategies and validators for Full Elementor MCP.
 *
 * Provides centralized security strategies covering:
 * 1. SSRF defense, IPv4/IPv6 resolution, port policy, and safe redirect downloading.
 * 2. SVG XML security, XXE prevention, entity expansion defense, and tag allowlisting.
 * 3. Custom code and mutation payload classification.
 * 4. Protected site asset detection.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements security strategies for external resources, SVG content, and high-risk payloads.
 */
class Full_Elementor_MCP_Security_Strategies {

	/**
	 * Default permitted outbound ports for HTTP/HTTPS requests.
	 */
	public const ALLOWED_PORTS = array( 80, 443 );

	/**
	 * Maximum allowed HTTP redirects when downloading external assets.
	 */
	public const MAX_REDIRECTS = 5;

	/**
	 * Known metadata and internal hostnames.
	 */
	private const BLOCKED_HOSTNAMES = array(
		'localhost',
		'metadata.google.internal',
		'instance-data',
	);

	/**
	 * Mock DNS table for testing: host => array of IP strings.
	 *
	 * @var array<string, string[]>|null
	 */
	private static ?array $mock_dns = null;

	/**
	 * Sets mock DNS records for testing.
	 *
	 * @param array<string, string[]>|null $records Hostname to IP list map.
	 */
	public static function set_mock_dns( ?array $records ): void {
		self::$mock_dns = $records;
	}

	/**
	 * Resets mock DNS records.
	 */
	public static function reset_mock_dns(): void {
		self::$mock_dns = null;
	}

	// =========================================================================
	// 1. SSRF & Remote URL Security
	// =========================================================================

	/**
	 * Validates a remote URL for safe outbound requests (SSRF defense).
	 *
	 * Validates:
	 * - Only http:// and https:// schemes.
	 * - No embedded credentials (username/password).
	 * - No ambiguous IP formats (octal, hex, decimal integers).
	 * - Permitted ports only (80, 443 by default).
	 * - Hostname resolution to IPv4 and IPv6.
	 * - Blocks loopback, RFC 1918 private, link-local, cloud metadata, multicast, and reserved ranges.
	 *
	 * @param string               $url     The external URL.
	 * @param array<string, mixed> $options Optional settings (allowed_ports, timeout).
	 * @return true|\WP_Error True if valid, WP_Error if unsafe or invalid.
	 */
	public static function validate_url( string $url, array $options = array() ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return new \WP_Error( 'invalid_url', __( 'Remote URL cannot be empty.', 'full-elementor-mcp' ) );
		}

		// Disallow control characters or whitespace in URL.
		if ( preg_match( '/[\x00-\x1F\x7F\s]/', $url ) ) {
			return new \WP_Error( 'invalid_url', __( 'URL contains invalid control characters or whitespace.', 'full-elementor-mcp' ) );
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) ) {
			return new \WP_Error( 'invalid_url', __( 'Malformed URL structure: scheme is required.', 'full-elementor-mcp' ) );
		}

		// Scheme validation: strictly http or https.
		$scheme = strtolower( (string) $parsed['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error(
				'invalid_protocol',
				sprintf(
					/* translators: %s: scheme */
					__( 'Unsupported URL scheme "%s". Only HTTP and HTTPS are permitted.', 'full-elementor-mcp' ),
					esc_html( $scheme )
				)
			);
		}

		if ( empty( $parsed['host'] ) ) {
			return new \WP_Error( 'invalid_url', __( 'Malformed URL structure: host is required.', 'full-elementor-mcp' ) );
		}

		// Reject embedded credentials in URL (e.g. http://user:pass@host).
		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return new \WP_Error(
				'ssrf_embedded_credentials',
				__( 'URLs containing embedded credentials (user:pass@host) are forbidden.', 'full-elementor-mcp' )
			);
		}

		// Port validation.
		$allowed_ports = isset( $options['allowed_ports'] ) && is_array( $options['allowed_ports'] )
			? array_map( 'intval', $options['allowed_ports'] )
			: self::ALLOWED_PORTS;

		$port = isset( $parsed['port'] ) ? (int) $parsed['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( ! in_array( $port, $allowed_ports, true ) ) {
			return new \WP_Error(
				'ssrf_blocked_port',
				sprintf(
					/* translators: %d: port */
					__( 'Outbound request to port %d is disallowed by safety policy.', 'full-elementor-mcp' ),
					$port
				),
				array( 'port' => $port, 'allowed_ports' => $allowed_ports )
			);
		}

		$raw_host = (string) $parsed['host'];
		// Normalize host: strip brackets for IPv6 literal (e.g. [::1] -> ::1).
		$host = trim( $raw_host, '[]' );
		$host_lower = strtolower( $host );

		// Check blocked hostnames.
		foreach ( self::BLOCKED_HOSTNAMES as $blocked ) {
			if ( $host_lower === $blocked || str_ends_with( $host_lower, '.' . $blocked ) ) {
				return new \WP_Error(
					'ssrf_blocked_host',
					sprintf(
						/* translators: %s: host */
						__( 'Access to local or cloud metadata hostname "%s" is forbidden.', 'full-elementor-mcp' ),
						esc_html( $host )
					)
				);
			}
		}

		// Reject ambiguous IP representations:
		// 1. Pure integer IP (e.g. http://2130706433/).
		if ( ctype_digit( $host ) ) {
			return new \WP_Error(
				'ssrf_ambiguous_ip',
				__( 'Integer IP addresses are disallowed for security.', 'full-elementor-mcp' )
			);
		}

		// 2. Hexadecimal or octal IP segments (e.g. 0x7f.0.0.1 or 0177.0.0.1).
		if ( preg_match( '/^0x[0-9a-fA-F]+$/', $host ) || preg_match( '/(^|\.)0[0-9]+/', $host ) ) {
			return new \WP_Error(
				'ssrf_ambiguous_ip',
				__( 'Hexadecimal or octal IP representations are disallowed for security.', 'full-elementor-mcp' )
			);
		}

		// Resolve host to IP addresses.
		$ips = self::resolve_host_ips( $host );
		if ( empty( $ips ) ) {
			return new \WP_Error(
				'dns_resolution_failed',
				sprintf(
					/* translators: %s: host */
					__( 'Could not resolve IP address for hostname "%s".', 'full-elementor-mcp' ),
					esc_html( $host )
				)
			);
		}

		// Verify that ALL resolved IPs are safe public addresses.
		foreach ( $ips as $ip ) {
			if ( self::is_forbidden_ip( $ip ) ) {
				return new \WP_Error(
					'ssrf_blocked_ip',
					sprintf(
						/* translators: 1: host, 2: ip */
						__( 'Hostname "%1$s" resolves to a forbidden private, loopback, or cloud-metadata IP address (%2$s).', 'full-elementor-mcp' ),
						esc_html( $host ),
						esc_html( $ip )
					),
					array( 'host' => $host, 'blocked_ip' => $ip )
				);
			}
		}

		return true;
	}

	/**
	 * Resolves a hostname to all associated IPv4 and IPv6 addresses.
	 *
	 * @param string $host The hostname or IP literal.
	 * @return string[] Array of resolved IP strings.
	 */
	public static function resolve_host_ips( string $host ): array {
		// 1. IP literal check (IPv4 or IPv6).
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		// 2. Check test mock.
		if ( null !== self::$mock_dns ) {
			return self::$mock_dns[ strtolower( $host ) ] ?? array();
		}

		$resolved = array();

		// 3. Resolve IPv4.
		$v4 = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			foreach ( $v4 as $ip ) {
				if ( is_string( $ip ) && '' !== $ip ) {
					$resolved[] = $ip;
				}
			}
		}

		// 4. Resolve IPv6 via dns_get_record if available.
		if ( function_exists( 'dns_get_record' ) ) {
			try {
				$records = @dns_get_record( $host, DNS_AAAA );
				if ( is_array( $records ) ) {
					foreach ( $records as $rec ) {
						if ( isset( $rec['ipv6'] ) && is_string( $rec['ipv6'] ) ) {
							$resolved[] = $rec['ipv6'];
						}
					}
				}
			} catch ( \Throwable $e ) {
				// Fall through.
			}
		}

		return array_values( array_unique( $resolved ) );
	}

	/**
	 * Checks if an IP address (IPv4 or IPv6) is in a forbidden range.
	 *
	 * Blocks:
	 * - Loopback (127.0.0.0/8, ::1)
	 * - Private (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, fc00::/7)
	 * - Link-local (169.254.0.0/16, fe80::/10)
	 * - Cloud metadata (169.254.169.254)
	 * - Zero / unspecified (0.0.0.0/8, ::)
	 * - Multicast & reserved (224.0.0.0/4, 240.0.0.0/4, ff00::/8, 255.255.255.255)
	 * - IPv4-mapped IPv6 (::ffff:127.0.0.1 etc.)
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool True if the IP is forbidden.
	 */
	public static function is_forbidden_ip( string $ip ): bool {
		$ip = trim( $ip );
		if ( '' === $ip ) {
			return true;
		}

		// Validate basic IP syntax.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		// Use PHP's built-in private & reserved range filters.
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ) {
			return true;
		}

		// Handle IPv4-mapped IPv6 addresses (e.g. ::ffff:127.0.0.1 or ::ffff:7f00:1).
		if ( str_starts_with( strtolower( $ip ), '::ffff:' ) ) {
			$sub = substr( $ip, 7 );
			if ( filter_var( $sub, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return self::is_forbidden_ip( $sub );
			}
			return true;
		}

		// Explicit IPv4 checks:
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			// Cloud metadata.
			if ( '169.254.169.254' === $ip ) {
				return true;
			}
			// Loopback (127.0.0.0/8).
			if ( str_starts_with( $ip, '127.' ) ) {
				return true;
			}
			// Link-local (169.254.0.0/16).
			if ( str_starts_with( $ip, '169.254.' ) ) {
				return true;
			}
			// Zero network (0.0.0.0/8) or broadcast (255.255.255.255).
			if ( str_starts_with( $ip, '0.' ) || '255.255.255.255' === $ip ) {
				return true;
			}
		}

		// Explicit IPv6 checks:
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$norm = strtolower( $ip );
			// Loopback / unspecified.
			if ( '::1' === $norm || '::' === $norm || '0000:0000:0000:0000:0000:0000:0000:0001' === $norm ) {
				return true;
			}
			// Link-local (fe80::/10).
			if ( str_starts_with( $norm, 'fe8' ) || str_starts_with( $norm, 'fe9' ) ||
				str_starts_with( $norm, 'fea' ) || str_starts_with( $norm, 'feb' ) ) {
				return true;
			}
			// Unique local / private (fc00::/7).
			if ( str_starts_with( $norm, 'fc' ) || str_starts_with( $norm, 'fd' ) ) {
				return true;
			}
			// Multicast (ff00::/8).
			if ( str_starts_with( $norm, 'ff' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Safely downloads an external resource to a temporary file, defending against redirect SSRF.
	 *
	 * Every redirect hop is re-validated against the SSRF policy before following.
	 *
	 * @param string $url     The URL to download.
	 * @param int    $timeout Request timeout in seconds.
	 * @return string|\WP_Error Path to downloaded temporary file, or WP_Error on failure.
	 */
	public static function safe_download_url( string $url, int $timeout = 30 ) {
		$current_url    = $url;
		$redirect_count = 0;

		while ( $redirect_count <= self::MAX_REDIRECTS ) {
			// Validate current target URL before connecting.
			$val = self::validate_url( $current_url );
			if ( is_wp_error( $val ) ) {
				return $val;
			}

			// Perform safe request without auto-following redirects.
			$response = wp_safe_remote_get(
				$current_url,
				array(
					'timeout'     => $timeout,
					'redirection' => 0, // Manual redirect validation.
					'user-agent'  => 'Full-Elementor-MCP/' . FULL_ELEMENTOR_MCP_VERSION . '; ' . get_bloginfo( 'name' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status = wp_remote_retrieve_response_code( $response );

			// Check for redirect responses (301, 302, 303, 307, 308).
			if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( empty( $location ) || ! is_string( $location ) ) {
					return new \WP_Error( 'ssrf_redirect_missing_location', __( 'Redirect response did not include a Location header.', 'full-elementor-mcp' ) );
				}

				// Resolve relative redirect locations if necessary.
				$parsed_loc = wp_parse_url( $location );
				if ( empty( $parsed_loc['host'] ) ) {
					$parsed_orig = wp_parse_url( $current_url );
					$base        = ( $parsed_orig['scheme'] ?? 'https' ) . '://' . ( $parsed_orig['host'] ?? '' );
					$location    = $base . ( str_starts_with( $location, '/' ) ? '' : '/' ) . $location;
				}

				$current_url = $location;
				$redirect_count++;

				if ( $redirect_count > self::MAX_REDIRECTS ) {
					return new \WP_Error( 'too_many_redirects', __( 'Too many redirects encountered while downloading resource.', 'full-elementor-mcp' ) );
				}

				continue;
			}

			if ( $status < 200 || $status >= 300 ) {
				return new \WP_Error(
					'http_download_failed',
					sprintf(
						/* translators: %d: HTTP code */
						__( 'Remote server returned HTTP %d.', 'full-elementor-mcp' ),
						$status
					),
					array( 'status_code' => $status )
				);
			}

			$body = wp_remote_retrieve_body( $response );
			if ( '' === $body ) {
				return new \WP_Error( 'empty_response', __( 'Downloaded file is empty.', 'full-elementor-mcp' ) );
			}

			// Write body to temporary file.
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$tmp = wp_tempnam( 'mcp_dl_' );
			if ( ! $tmp ) {
				return new \WP_Error( 'temp_file_failed', __( 'Could not create temporary file for download.', 'full-elementor-mcp' ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $tmp, $body );

			return $tmp;
		}

		return new \WP_Error( 'too_many_redirects', __( 'Too many redirects encountered while downloading resource.', 'full-elementor-mcp' ) );
	}

	// =========================================================================
	// 2. SVG XML Parser Security
	// =========================================================================

	/**
	 * Validates an SVG markup string for XML entity expansion (XXE), scripts, and dangerous attributes.
	 *
	 * Safe XML parsing:
	 * - Uses DOMDocument with LIBXML_NONET to block external entity resolution.
	 * - Rejects <script> tags.
	 * - Rejects inline event handler attributes (on*="...").
	 * - Rejects javascript: URLs in href/xlink:href/src.
	 * - Rejects dangerous <foreignObject> constructs.
	 * - Rejects <!DOCTYPE with external entity definitions.
	 *
	 * @param string $svg_content Raw SVG markup.
	 * @return true|\WP_Error True if safe, WP_Error if malicious or invalid.
	 */
	public static function validate_svg( string $svg_content ) {
		$svg = trim( $svg_content );
		if ( '' === $svg ) {
			return new \WP_Error( 'empty_svg', __( 'SVG content cannot be empty.', 'full-elementor-mcp' ) );
		}

		// Must contain <svg tag.
		if ( false === stripos( $svg, '<svg' ) ) {
			return new \WP_Error( 'not_svg', __( 'Content does not contain an <svg> element.', 'full-elementor-mcp' ) );
		}

		// 1. Fast regex checks for obvious attacks before XML parsing.
		if ( preg_match( '/<script\b/i', $svg ) ) {
			return new \WP_Error( 'svg_has_script', __( 'SVG contains forbidden <script> elements.', 'full-elementor-mcp' ) );
		}

		// Event handlers (e.g. onload, onerror, onclick).
		if ( preg_match( '/\s+on[a-zA-Z0-9_\-]+\s*=/i', $svg ) ) {
			return new \WP_Error( 'svg_has_event_handler', __( 'SVG contains forbidden inline event handler attributes.', 'full-elementor-mcp' ) );
		}

		// javascript: or vbscript: URIs.
		if ( preg_match( '/(javascript|vbscript)\s*:/i', $svg ) ) {
			return new \WP_Error( 'svg_has_javascript_uri', __( 'SVG contains forbidden javascript: or vbscript: URIs.', 'full-elementor-mcp' ) );
		}

		// Dangerous data URIs (allow safe image/png, image/jpeg, image/webp raster embeds only).
		if ( preg_match( '/data\s*:\s*(?!image\/(?:png|jpeg|jpg|webp|gif))/i', $svg ) ) {
			return new \WP_Error( 'svg_has_dangerous_data_uri', __( 'SVG contains forbidden non-image data: URIs.', 'full-elementor-mcp' ) );
		}

		// Embedded interactive / executable elements.
		if ( preg_match( '/<(?:iframe|object|embed|applet|meta|link|form)\b/i', $svg ) ) {
			return new \WP_Error( 'svg_has_forbidden_tag', __( 'SVG contains forbidden embedded document or object tags.', 'full-elementor-mcp' ) );
		}

		// XXE defense: check for external entity declarations in DOCTYPE.
		if ( preg_match( '/<!DOCTYPE\b[^>]*\b(?:SYSTEM|PUBLIC|ENTITY)\b/i', $svg ) ) {
			return new \WP_Error( 'svg_xxe_detected', __( 'SVG contains external DOCTYPE or entity declarations (XXE defense).', 'full-elementor-mcp' ) );
		}

		// 2. Strict XML Parser validation using DOMDocument with libxml security flags.
		if ( class_exists( '\DOMDocument' ) ) {
			$prev_libxml = libxml_use_internal_errors( true );
			$dom         = new \DOMDocument();

			// Flags: LIBXML_NONET (disable network access), LIBXML_NOENT (substitute entities safely).
			$options = LIBXML_NONET;
			if ( defined( 'LIBXML_NOBLANKS' ) ) {
				$options |= LIBXML_NOBLANKS;
			}

			$loaded = $dom->loadXML( $svg, $options );
			$errors = libxml_get_errors();
			libxml_clear_errors();
			libxml_use_internal_errors( $prev_libxml );

			if ( ! $loaded ) {
				$err_msg = ! empty( $errors ) ? $errors[0]->message : __( 'Malformed XML syntax.', 'full-elementor-mcp' );
				return new \WP_Error( 'invalid_svg_xml', sprintf( __( 'SVG XML parsing failed: %s', 'full-elementor-mcp' ), trim( $err_msg ) ) );
			}

			// Inspect DOM tree for forbidden nodes.
			$forbidden_tags = array( 'script', 'foreignobject', 'iframe', 'object', 'embed', 'applet', 'meta', 'link', 'form' );
			foreach ( $forbidden_tags as $tag ) {
				$nodes = $dom->getElementsByTagName( $tag );
				if ( $nodes->length > 0 ) {
					return new \WP_Error(
						'svg_has_forbidden_tag',
						sprintf(
							/* translators: %s: tag */
							__( 'SVG contains forbidden DOM element "<%s>".', 'full-elementor-mcp' ),
							$tag
						)
					);
				}
			}

			// Inspect all attributes across all elements for event handlers or javascript: hrefs.
			$all_elements = $dom->getElementsByTagName( '*' );
			foreach ( $all_elements as $elem ) {
				if ( ! $elem->hasAttributes() ) {
					continue;
				}
				foreach ( $elem->attributes as $attr ) {
					$attr_name = strtolower( $attr->nodeName );
					$attr_val  = strtolower( trim( $attr->nodeValue ) );

					if ( str_starts_with( $attr_name, 'on' ) ) {
						return new \WP_Error( 'svg_has_event_handler', sprintf( __( 'SVG contains event attribute "%s".', 'full-elementor-mcp' ), $attr_name ) );
					}

					if ( ( 'href' === $attr_name || 'xlink:href' === $attr_name || 'src' === $attr_name ) &&
						( str_starts_with( $attr_val, 'javascript:' ) || str_starts_with( $attr_val, 'vbscript:' ) ) ) {
						return new \WP_Error( 'svg_has_javascript_uri', __( 'SVG attribute contains executable javascript: URI.', 'full-elementor-mcp' ) );
					}
				}
			}
		}

		return true;
	}

	// =========================================================================
	// 3. Custom Code & Mutation Classification
	// =========================================================================

	/**
	 * Classifies an ability and its payload for security risks, executable code, and confirmation gating.
	 *
	 * Does not falsely sanitize code; produces honest security classification metadata for Phase 4.
	 *
	 * @param string               $ability Ability name (e.g. 'full-elementor-mcp/add-custom-js').
	 * @param array<string, mixed> $args    Input arguments.
	 * @return array{
	 *     ability: string,
	 *     is_executable: bool,
	 *     is_high_risk: bool,
	 *     requires_unfiltered_html: bool,
	 *     is_irreversible: bool,
	 *     has_external_network: bool,
	 *     category: string,
	 *     reasons: string[]
	 * }
	 */
	public static function classify_ability_payload( string $ability, array $args = array() ): array {
		$reasons                  = array();
		$is_executable            = false;
		$is_high_risk             = false;
		$requires_unfiltered_html = false;
		$is_irreversible          = false;
		$has_external_network     = false;
		$category                 = 'standard';

		// Custom JavaScript & code snippets:
		if ( in_array( $ability, array(
			'full-elementor-mcp/add-custom-js',
			'full-elementor-mcp/add-code-snippet',
			'full-elementor-mcp/update-code-snippet',
		), true ) ) {
			$is_executable            = true;
			$is_high_risk             = true;
			$requires_unfiltered_html = true;
			$category                 = 'custom_code';
			$reasons[]                = 'Payload injects arbitrary executable JavaScript or PHP/HTML snippets.';
		}

		// Custom CSS:
		if ( 'full-elementor-mcp/add-custom-css' === $ability ) {
			$is_executable            = false;
			$is_high_risk             = false;
			$requires_unfiltered_html = true;
			$category                 = 'custom_css';
			$reasons[]                = 'Payload injects page or element level stylesheet rules.';
		}

		// HTML widget content:
		if ( 'full-elementor-mcp/add-html' === $ability ) {
			$html = (string) ( $args['html'] ?? '' );
			if ( preg_match( '/<script\b/i', $html ) || preg_match( '/\bon[a-zA-Z]+\s*=/i', $html ) ) {
				$is_executable            = true;
				$is_high_risk             = true;
				$requires_unfiltered_html = true;
				$reasons[]                = 'HTML payload contains executable script tags or inline event handlers.';
			}
		}

		// Irreversible destructive deletions:
		if ( in_array( $ability, array(
			'full-elementor-mcp/delete-page',
			'full-elementor-mcp/delete-template',
			'full-elementor-mcp/delete-code-snippet',
		), true ) ) {
			$force = ! empty( $args['force'] ) || ! empty( $args['force_delete'] );
			if ( $force ) {
				$is_irreversible = true;
				$is_high_risk    = true;
				$category        = 'permanent_deletion';
				$reasons[]       = 'Operation permanently deletes entity from database (bypass trash).';
			}
		}

		// External network access:
		if ( in_array( $ability, array(
			'full-elementor-mcp/sideload-image',
			'full-elementor-mcp/upload-svg-icon',
			'full-elementor-mcp/search-images',
			'full-elementor-mcp/get-image-details',
		), true ) ) {
			$has_external_network = true;
			$reasons[]            = 'Ability performs outbound HTTP network requests.';
		}

		return array(
			'ability'                  => $ability,
			'is_executable'            => $is_executable,
			'is_high_risk'             => $is_high_risk,
			'requires_unfiltered_html' => $requires_unfiltered_html,
			'is_irreversible'          => $is_irreversible,
			'has_external_network'     => $has_external_network,
			'category'                 => $category,
			'reasons'                  => $reasons,
		);
	}

	// =========================================================================
	// 4. Protected Asset Detection
	// =========================================================================

	/**
	 * Detects whether a post ID is a critical protected site resource.
	 *
	 * Detects:
	 * - Front page (page_on_front)
	 * - Blog page (page_for_posts)
	 * - Active Elementor Kit (elementor_active_kit)
	 * - WooCommerce core pages (shop, cart, checkout, myaccount)
	 * - Configured custom protected page IDs
	 *
	 * @param int $post_id Post ID to inspect.
	 * @return array{protected: bool, reason: ?string, asset_type: ?string}
	 */
	public static function is_protected_asset( int $post_id ): array {
		if ( $post_id < 1 ) {
			return array(
				'protected'  => false,
				'reason'     => null,
				'asset_type' => null,
			);
		}

		// Front page.
		$front_id = (int) get_option( 'page_on_front', 0 );
		if ( $post_id === $front_id ) {
			return array(
				'protected'  => true,
				'reason'     => __( 'Homepage (Front Page)', 'full-elementor-mcp' ),
				'asset_type' => 'front_page',
			);
		}

		// Blog / posts page.
		$posts_id = (int) get_option( 'page_for_posts', 0 );
		if ( $post_id === $posts_id ) {
			return array(
				'protected'  => true,
				'reason'     => __( 'Blog / Posts Index Page', 'full-elementor-mcp' ),
				'asset_type' => 'posts_page',
			);
		}

		// Active Elementor Kit.
		$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		if ( $post_id === $kit_id ) {
			return array(
				'protected'  => true,
				'reason'     => __( 'Elementor Active Site Settings Kit', 'full-elementor-mcp' ),
				'asset_type' => 'active_kit',
			);
		}

		// WooCommerce pages (if WooCommerce is active).
		if ( function_exists( 'wc_get_page_id' ) ) {
			$wc_map = array(
				'shop'      => __( 'WooCommerce Shop Page', 'full-elementor-mcp' ),
				'cart'      => __( 'WooCommerce Cart Page', 'full-elementor-mcp' ),
				'checkout'  => __( 'WooCommerce Checkout Page', 'full-elementor-mcp' ),
				'myaccount' => __( 'WooCommerce My Account Page', 'full-elementor-mcp' ),
			);
			foreach ( $wc_map as $endpoint => $label ) {
				$wc_pid = (int) wc_get_page_id( $endpoint );
				if ( $post_id === $wc_pid ) {
					return array(
						'protected'  => true,
						'reason'     => $label,
						'asset_type' => 'woocommerce_' . $endpoint,
					);
				}
			}
		}

		// Custom configured protected page IDs.
		if ( class_exists( 'Full_Elementor_MCP_Safety_Settings' ) ) {
			$custom_ids = Full_Elementor_MCP_Safety_Settings::get( 'custom_protected_page_ids', array() );
			if ( is_array( $custom_ids ) && in_array( $post_id, array_map( 'intval', $custom_ids ), true ) ) {
				return array(
					'protected'  => true,
					'reason'     => sprintf( __( 'Custom Protected Page (ID %d)', 'full-elementor-mcp' ), $post_id ),
					'asset_type' => 'custom_protected',
				);
			}
		}

		return array(
			'protected'  => false,
			'reason'     => null,
			'asset_type' => null,
		);
	}
}
