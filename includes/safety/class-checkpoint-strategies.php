<?php
/**
 * Resource Checkpoint Strategies.
 *
 * Implements exact state capture and restoration logic for resource families:
 * - Post-backed Elementor documents (post:<ID>)
 * - Custom Code snippets (post:<ID> of elementor_snippet)
 * - Global Elementor kit state (global:elementor-kit-state)
 * - Recovery-only irreversible deletion snapshots
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates resource capture and restoration strategies.
 */
final class Full_Elementor_MCP_Checkpoint_Strategies {

	/**
	 * Restore capability constants.
	 */
	public const CAPABILITY_EXACT         = 'exact';
	public const CAPABILITY_RECOVERY_ONLY = 'recovery_only';
	public const CAPABILITY_UNSUPPORTED   = 'unsupported';

	/**
	 * Strategy type identifiers.
	 */
	public const STRATEGY_POST    = 'post';
	public const STRATEGY_SNIPPET = 'snippet';
	public const STRATEGY_GLOBAL  = 'global';

	/**
	 * Payload semantic profiles.
	 */
	public const PROFILE_LEGACY_518_V1         = 'legacy_518_v1';
	public const PROFILE_LEGACY_B16_POST_V1    = 'legacy_b16_post_v1';
	public const PROFILE_LEGACY_B16_SNIPPET_V1 = 'legacy_b16_snippet_v1';
	public const PROFILE_TRANSITIONAL_346_POST = 'transitional_346_post_v1';
	public const PROFILE_TRANSITIONAL_346_SNIP = 'transitional_346_snippet_v1';
	public const PROFILE_LEGACY_GLOBAL_V1      = 'legacy_global_v1';
	public const PROFILE_MODERN_V2             = 'modern_v2';

	/**
	 * Resolves internal semantic payload profile from authenticated checkpoint metadata and decrypted state.
	 *
	 * Envelope and payload semantics are strictly separated:
	 * - Envelope v1 is always legacy_518_v1 (untrusted restore capability).
	 * - Envelope v2 + schema v2 is modern_v2.
	 * - Envelope v2 + schema v1 inspects decrypted plaintext markers:
	 *   - post with data_exists & page_settings_exists => transitional_346_post_v1
	 *   - post without => legacy_b16_post_v1
	 *   - snippet with complete modern exact fields => transitional_346_snippet_v1
	 *   - snippet without => legacy_b16_snippet_v1
	 *   - global => legacy_global_v1
	 *
	 * @param array<string, mixed> $row   Checkpoint DB row (authenticated).
	 * @param array<string, mixed> $state Decrypted, AEAD-verified plaintext state.
	 * @return string One of self::PROFILE_* constants or WP_Error on unsupported.
	 */
	public static function resolve_payload_profile( array $row, array $state ): string|\WP_Error {
		$envelope_version = isset( $row['crypto_envelope_version'] ) ? (int) $row['crypto_envelope_version'] : 2;
		if ( 1 === $envelope_version ) {
			return self::PROFILE_LEGACY_518_V1;
		}

		if ( 2 !== $envelope_version ) {
			return new \WP_Error(
				'checkpoint_envelope_unsupported',
				sprintf(
					/* translators: %d: envelope version */
					__( 'Unsupported checkpoint crypto envelope version: %d.', 'full-elementor-mcp' ),
					$envelope_version
				),
				array( 'crypto_envelope_version' => $envelope_version )
			);
		}

		$schema_version = (int) ( $row['payload_schema_version'] ?? 1 );
		if ( 2 === $schema_version ) {
			return self::PROFILE_MODERN_V2;
		}

		if ( 1 !== $schema_version ) {
			return new \WP_Error(
				'checkpoint_schema_unsupported',
				sprintf(
					/* translators: %d: schema version */
					__( 'Unsupported checkpoint payload schema version: %d.', 'full-elementor-mcp' ),
					$schema_version
				),
				array( 'payload_schema_version' => $schema_version )
			);
		}

		// Envelope 2 + schema 1: inspect authenticated decrypted plaintext state:
		$strategy = $state['strategy'] ?? self::resolve_strategy( (string) ( $row['resource_key'] ?? '' ) );

		if ( self::STRATEGY_GLOBAL === $strategy ) {
			return self::PROFILE_LEGACY_GLOBAL_V1;
		}

		if ( self::STRATEGY_POST === $strategy ) {
			$el_meta             = $state['elementor'] ?? array();
			$has_data_exists     = is_array( $el_meta ) && array_key_exists( 'data_exists', $el_meta );
			$has_settings_exists = is_array( $el_meta ) && array_key_exists( 'page_settings_exists', $el_meta );

			if ( $has_data_exists && $has_settings_exists ) {
				return self::PROFILE_TRANSITIONAL_346_POST;
			}

			return self::PROFILE_LEGACY_B16_POST_V1;
		}

		if ( self::STRATEGY_SNIPPET === $strategy ) {
			$has_code_exists     = array_key_exists( 'code_exists', $state );
			$has_location_exists = array_key_exists( 'location_exists', $state );
			$has_priority_exists = array_key_exists( 'priority_exists', $state );
			$has_template_type   = isset( $state['template_type'] ) && is_array( $state['template_type'] );
			$has_edit_mode       = isset( $state['edit_mode'] ) && is_array( $state['edit_mode'] );
			$has_conditions      = isset( $state['conditions'] ) && is_array( $state['conditions'] );
			$has_extra_options   = isset( $state['extra_options'] ) && is_array( $state['extra_options'] );

			if ( $has_code_exists && $has_location_exists && $has_priority_exists && $has_template_type && $has_edit_mode && $has_conditions && $has_extra_options ) {
				return self::PROFILE_TRANSITIONAL_346_SNIP;
			}

			return self::PROFILE_LEGACY_B16_SNIPPET_V1;
		}

		return new \WP_Error(
			'checkpoint_schema_unsupported',
			__( 'Unrecognized strategy payload in schema version 1 checkpoint.', 'full-elementor-mcp' ),
			array( 'strategy' => $strategy )
		);
	}

	/**
	 * Resolves strategy family for a given canonical resource key.
	 *
	 * @param string $resource_key Canonical resource key.
	 * @return string|null Strategy identifier or null if unknown/unsupported.
	 */
	public static function resolve_strategy( string $resource_key ): ?string {
		if ( 'global:elementor-kit-state' === $resource_key ) {
			return self::STRATEGY_GLOBAL;
		}

		if ( str_starts_with( $resource_key, 'post:' ) ) {
			$post_id = (int) substr( $resource_key, 5 );
			if ( $post_id <= 0 ) {
				return null;
			}

			if ( function_exists( 'get_post' ) ) {
				$post = get_post( $post_id );
				if ( $post && 'elementor_snippet' === ( $post->post_type ?? '' ) ) {
					return self::STRATEGY_SNIPPET;
				}
			}

			return self::STRATEGY_POST;
		}

		return null;
	}

	/**
	 * Returns declared restore capability for a resource and context.
	 *
	 * @param string      $resource_key    Canonical resource key.
	 * @param string|null $checkpoint_type Checkpoint trigger type (e.g. 'recovery', 'automatic').
	 * @param array       $context         Additional context (e.g. ability, is_destructive).
	 * @return string CAPABILITY_EXACT, CAPABILITY_RECOVERY_ONLY, or CAPABILITY_UNSUPPORTED.
	 */
	public static function get_restore_capability( string $resource_key, ?string $checkpoint_type = null, array $context = array() ): string {
		if ( 'recovery' === $checkpoint_type || 'recovery_only' === $checkpoint_type || ! empty( $context['is_permanent_delete'] ) || ( $context['restore_capability'] ?? '' ) === self::CAPABILITY_RECOVERY_ONLY ) {
			return self::CAPABILITY_RECOVERY_ONLY;
		}

		$strategy = self::resolve_strategy( $resource_key );
		if ( in_array( $strategy, array( self::STRATEGY_POST, self::STRATEGY_SNIPPET, self::STRATEGY_GLOBAL ), true ) ) {
			return self::CAPABILITY_EXACT;
		}

		return self::CAPABILITY_UNSUPPORTED;
	}

	/**
	 * Validates state array against the resolved strategy schema and payload profile.
	 *
	 * @param string               $resource_key                 Target canonical resource key.
	 * @param array<string, mixed> $state                        Captured state to validate.
	 * @param int|string           $payload_profile_or_version   Semantic payload profile or schema version.
	 * @return true|\WP_Error
	 */
	public static function validate_state_schema( string $resource_key, array $state, int|string $payload_profile_or_version = 2 ) {
		$strategy = self::resolve_strategy( $resource_key );
		if ( empty( $strategy ) ) {
			return new \WP_Error(
				'checkpoint_strategy_missing',
				sprintf(
					/* translators: %s: resource key */
					__( 'No checkpoint strategy available for resource "%s".', 'full-elementor-mcp' ),
					$resource_key
				),
				array( 'resource_key' => $resource_key )
			);
		}

		if ( empty( $state['strategy'] ) ) {
			return new \WP_Error(
				'checkpoint_state_schema_invalid',
				__( 'Checkpoint payload missing strategy identifier.', 'full-elementor-mcp' )
			);
		}

		if ( $state['strategy'] !== $strategy ) {
			return new \WP_Error(
				'checkpoint_resource_mismatch',
				sprintf(
					/* translators: 1: expected strategy, 2: actual strategy */
					__( 'Checkpoint strategy mismatch: expected "%1$s" but payload strategy was "%2$s".', 'full-elementor-mcp' ),
					$strategy,
					$state['strategy']
				),
				array(
					'expected' => $strategy,
					'actual'   => $state['strategy'],
				)
			);
		}

		$is_modern = ( self::PROFILE_MODERN_V2 === $payload_profile_or_version
			|| self::PROFILE_TRANSITIONAL_346_POST === $payload_profile_or_version
			|| self::PROFILE_TRANSITIONAL_346_SNIP === $payload_profile_or_version
			|| 2 === $payload_profile_or_version );

		if ( self::STRATEGY_POST === $strategy ) {
			$expected_post_id = (int) substr( $resource_key, 5 );
			if ( (int) ( $state['post_id'] ?? 0 ) !== $expected_post_id ) {
				return new \WP_Error(
					'checkpoint_resource_mismatch',
					sprintf(
						/* translators: 1: payload post ID, 2: expected post ID */
						__( 'Checkpoint payload post_id %1$d does not match resource_key post ID %2$d.', 'full-elementor-mcp' ),
						(int) ( $state['post_id'] ?? 0 ),
						$expected_post_id
					)
				);
			}

			if ( ! isset( $state['post_fields'] ) || ! is_array( $state['post_fields'] ) ) {
				return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Post checkpoint missing post_fields array.', 'full-elementor-mcp' ) );
			}

			if ( ! isset( $state['elementor'] ) || ! is_array( $state['elementor'] ) ) {
				return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Post checkpoint missing elementor structure.', 'full-elementor-mcp' ) );
			}

			if ( ! isset( $state['elementor']['data'] ) || ! is_array( $state['elementor']['data'] ) ) {
				return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Post checkpoint missing elementor.data list.', 'full-elementor-mcp' ) );
			}

			if ( $is_modern ) {
				if ( ! array_key_exists( 'data_exists', $state['elementor'] ) || ! array_key_exists( 'page_settings_exists', $state['elementor'] ) ) {
					return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Modern/transitional post checkpoint missing data_exists or page_settings_exists flag.', 'full-elementor-mcp' ) );
				}
			}

			if ( ! array_key_exists( 'featured_image', $state ) ) {
				return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Post checkpoint missing featured_image field.', 'full-elementor-mcp' ) );
			}

			if ( ! isset( $state['terms'] ) || ! is_array( $state['terms'] ) ) {
				return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Post checkpoint missing terms map.', 'full-elementor-mcp' ) );
			}
		} elseif ( self::STRATEGY_SNIPPET === $strategy ) {
			$expected_post_id = (int) substr( $resource_key, 5 );
			if ( (int) ( $state['post_id'] ?? 0 ) !== $expected_post_id ) {
				return new \WP_Error(
					'checkpoint_resource_mismatch',
					sprintf(
						/* translators: 1: payload post ID, 2: expected post ID */
						__( 'Snippet payload post_id %1$d does not match resource_key post ID %2$d.', 'full-elementor-mcp' ),
						(int) ( $state['post_id'] ?? 0 ),
						$expected_post_id
					)
				);
			}

			if ( ! isset( $state['post_title'] ) || ! isset( $state['code'] ) || ! isset( $state['location'] ) || ! isset( $state['priority'] ) ) {
				return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Snippet checkpoint missing required snippet fields.', 'full-elementor-mcp' ) );
			}

			if ( $is_modern ) {
				if ( ! isset( $state['template_type'] ) || ! is_array( $state['template_type'] ) ) {
					return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Snippet checkpoint missing template_type structure.', 'full-elementor-mcp' ) );
				}

				if ( ! isset( $state['edit_mode'] ) || ! is_array( $state['edit_mode'] ) ) {
					return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Snippet checkpoint missing edit_mode structure.', 'full-elementor-mcp' ) );
				}

				if ( ! array_key_exists( 'code_exists', $state ) || ! array_key_exists( 'location_exists', $state ) || ! array_key_exists( 'priority_exists', $state ) ) {
					return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Snippet checkpoint missing code_exists, location_exists, or priority_exists flag.', 'full-elementor-mcp' ) );
				}

				if ( ! isset( $state['conditions'] ) || ! is_array( $state['conditions'] ) ) {
					return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Snippet checkpoint missing conditions structure.', 'full-elementor-mcp' ) );
				}

				if ( ! isset( $state['extra_options'] ) || ! is_array( $state['extra_options'] ) ) {
					return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Snippet checkpoint missing extra_options structure.', 'full-elementor-mcp' ) );
				}
			}
		} elseif ( self::STRATEGY_GLOBAL === $strategy ) {
			if ( ! array_key_exists( 'active_kit_id', $state ) ) {
				return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Global checkpoint missing active_kit_id field.', 'full-elementor-mcp' ) );
			}

			if ( ! isset( $state['kit_settings'] ) || ! is_array( $state['kit_settings'] ) ) {
				return new \WP_Error( 'checkpoint_state_schema_invalid', __( 'Global checkpoint missing kit_settings array.', 'full-elementor-mcp' ) );
			}
		}

		return true;
	}

	/**
	 * Captures comprehensive persistent state for a canonical resource.
	 *
	 * @param string $resource_key Canonical resource key.
	 * @param array  $options      Optional capture hints or strategy overrides.
	 * @return array<string, mixed>|\WP_Error Captured state dictionary or WP_Error.
	 */
	public static function capture( string $resource_key, array $options = array() ): array|\WP_Error {
		$strategy = self::resolve_strategy( $resource_key );

		if ( self::STRATEGY_GLOBAL === $strategy ) {
			return self::capture_global_kit_state();
		}

		if ( self::STRATEGY_SNIPPET === $strategy ) {
			$post_id = (int) substr( $resource_key, 5 );
			return self::capture_snippet_state( $post_id );
		}

		if ( self::STRATEGY_POST === $strategy ) {
			$post_id = (int) substr( $resource_key, 5 );
			return self::capture_post_state( $post_id );
		}

		return new \WP_Error(
			'checkpoint_strategy_missing',
			sprintf(
				/* translators: %s: resource key */
				__( 'No checkpoint capture strategy available for resource "%s".', 'full-elementor-mcp' ),
				$resource_key
			),
			array( 'resource_key' => $resource_key )
		);
	}

	/**
	 * Captures current resource state projected into the historical Schema v1 domain.
	 *
	 * Used for deterministic state hash comparison against historical checkpoints.
	 *
	 * @param string $resource_key Canonical resource key.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function capture_as_schema_v1( string $resource_key ): array|\WP_Error {
		$strategy = self::resolve_strategy( $resource_key );

		if ( self::STRATEGY_GLOBAL === $strategy ) {
			return self::capture_global_kit_state();
		}

		if ( self::STRATEGY_SNIPPET === $strategy ) {
			$post_id = (int) substr( $resource_key, 5 );
			return self::capture_snippet_state_v1( $post_id );
		}

		if ( self::STRATEGY_POST === $strategy ) {
			$post_id = (int) substr( $resource_key, 5 );
			return self::capture_post_state_v1( $post_id );
		}

		return new \WP_Error(
			'checkpoint_strategy_missing',
			sprintf(
				/* translators: %s: resource key */
				__( 'No schema v1 projection available for resource "%s".', 'full-elementor-mcp' ),
				$resource_key
			),
			array( 'resource_key' => $resource_key )
		);
	}

	/**
	 * Captures post state projected into transitional 346 format.
	 *
	 * Matches the exact plaintext shape generated by commit 346b08a.
	 *
	 * @param string $resource_key Canonical resource key.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function capture_as_transitional_346_post_v1( string $resource_key ): array|\WP_Error {
		return self::capture( $resource_key );
	}

	/**
	 * Captures snippet state projected into transitional 346 format.
	 *
	 * Matches the exact plaintext shape generated by commit 346b08a.
	 *
	 * @param string $resource_key Canonical resource key.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function capture_as_transitional_346_snippet_v1( string $resource_key ): array|\WP_Error {
		return self::capture( $resource_key );
	}

	/**
	 * Restores persistent resource state from a validated checkpoint state array.
	 *
	 * All physical writes MUST be routed through guarded persistent write sinks
	 * with active mutation fencing asserting current fencing token.
	 *
	 * @param string               $resource_key                 Canonical resource key.
	 * @param array<string, mixed> $state                        Decrypted, validated state dictionary.
	 * @param int                  $fencing_token                Active fencing token.
	 * @param string               $owner_id                     Active lock owner ID.
	 * @param int|string           $payload_profile_or_version   Payload profile or schema version (1 or 2).
	 * @return true|\WP_Error True on successful restoration or WP_Error on failure.
	 */
	public static function restore( string $resource_key, array $state, int $fencing_token, string $owner_id, int|string $payload_profile_or_version = 2 ) {
		$strategy = self::resolve_strategy( $resource_key );

		if ( self::STRATEGY_GLOBAL === $strategy ) {
			return self::restore_global_kit_state( $state, $fencing_token, $owner_id );
		}

		if ( self::STRATEGY_SNIPPET === $strategy ) {
			if ( self::PROFILE_LEGACY_B16_SNIPPET_V1 === $payload_profile_or_version || self::PROFILE_LEGACY_518_V1 === $payload_profile_or_version || ( 1 === $payload_profile_or_version && ! array_key_exists( 'code_exists', $state ) ) ) {
				return new \WP_Error(
					'checkpoint_legacy_snippet_not_exact',
					__( 'Historical snippet checkpoint (schema v1) lacks required exact fields and cannot be restored. Classified as recovery-only.', 'full-elementor-mcp' )
				);
			}
			$post_id = (int) substr( $resource_key, 5 );
			return self::restore_snippet_state( $post_id, $state, $fencing_token, $owner_id );
		}

		if ( self::STRATEGY_POST === $strategy ) {
			$post_id = (int) substr( $resource_key, 5 );
			if ( self::PROFILE_LEGACY_B16_POST_V1 === $payload_profile_or_version || self::PROFILE_LEGACY_518_V1 === $payload_profile_or_version || ( 1 === $payload_profile_or_version && ! isset( $state['elementor']['data_exists'] ) ) ) {
				return self::restore_post_state_v1( $post_id, $state, $fencing_token, $owner_id );
			}
			return self::restore_post_state( $post_id, $state, $fencing_token, $owner_id );
		}

		return new \WP_Error(
			'checkpoint_restore_unsupported',
			sprintf(
				/* translators: %s: resource key */
				__( 'No restore strategy available for resource "%s".', 'full-elementor-mcp' ),
				$resource_key
			),
			array( 'resource_key' => $resource_key )
		);
	}

	// -------------------------------------------------------------------------
	// Post-Backed Elementor Strategy
	// -------------------------------------------------------------------------

	/**
	 * Captures persistent state for an Elementor post or page.
	 *
	 * @param int $post_id Target post ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function capture_post_state( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'checkpoint_capture_failed', __( 'Target post not found.', 'full-elementor-mcp' ), array( 'post_id' => $post_id ) );
		}

		// 1. Post core fields:
		$post_fields = array(
			'ID'             => (int) $post->ID,
			'post_type'      => (string) ( $post->post_type ?? 'page' ),
			'post_status'    => (string) ( $post->post_status ?? 'publish' ),
			'post_title'     => (string) ( $post->post_title ?? '' ),
			'post_name'      => (string) ( $post->post_name ?? '' ),
			'post_content'   => (string) ( $post->post_content ?? '' ),
			'post_excerpt'   => (string) ( $post->post_excerpt ?? '' ),
			'post_parent'    => (int) ( $post->post_parent ?? 0 ),
			'menu_order'     => (int) ( $post->menu_order ?? 0 ),
			'comment_status' => (string) ( $post->comment_status ?? 'closed' ),
			'ping_status'    => (string) ( $post->ping_status ?? 'closed' ),
			'post_password'  => (string) ( $post->post_password ?? '' ),
		);

		// 2. Elementor data & settings with strict existence semantics (fails closed on malformed storage):
		$data_exists = function_exists( 'metadata_exists' )
			? metadata_exists( 'post', $post_id, '_elementor_data' )
			: ( '' !== get_post_meta( $post_id, '_elementor_data', true ) );

		if ( ! $data_exists ) {
			$elements = array();
		} else {
			$raw_data = get_post_meta( $post_id, '_elementor_data', true );
			if ( is_string( $raw_data ) && '' !== trim( $raw_data ) ) {
				try {
					$elements = json_decode( $raw_data, true, 512, JSON_THROW_ON_ERROR );
				} catch ( \Throwable $e ) {
					return new \WP_Error(
						'checkpoint_capture_failed',
						sprintf(
							/* translators: %s: error message */
							__( 'Malformed JSON in _elementor_data storage: %s', 'full-elementor-mcp' ),
							$e->getMessage()
						),
						array( 'post_id' => $post_id )
					);
				}
			} elseif ( is_array( $raw_data ) ) {
				$elements = $raw_data;
			} elseif ( empty( $raw_data ) ) {
				$elements = array();
			} else {
				return new \WP_Error(
					'checkpoint_capture_failed',
					__( 'Invalid type for _elementor_data storage.', 'full-elementor-mcp' ),
					array( 'post_id' => $post_id )
				);
			}

			if ( ! is_array( $elements ) ) {
				return new \WP_Error(
					'checkpoint_capture_failed',
					__( '_elementor_data JSON must decode to an array list.', 'full-elementor-mcp' ),
					array( 'post_id' => $post_id )
				);
			}

			if ( ! empty( $elements ) && array_keys( $elements ) !== range( 0, count( $elements ) - 1 ) ) {
				return new \WP_Error(
					'checkpoint_capture_failed',
					__( '_elementor_data elements root must be a list of elements, not an associative object.', 'full-elementor-mcp' ),
					array( 'post_id' => $post_id )
				);
			}
		}

		$settings_exists = function_exists( 'metadata_exists' )
			? metadata_exists( 'post', $post_id, '_elementor_page_settings' )
			: false;

		if ( ! $settings_exists ) {
			$page_settings = array();
		} else {
			$raw_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
			if ( is_array( $raw_settings ) ) {
				$page_settings = $raw_settings;
			} elseif ( '' === $raw_settings ) {
				$page_settings = array();
			} else {
				return new \WP_Error(
					'checkpoint_capture_failed',
					__( 'Malformed unexpected persistent type for _elementor_page_settings.', 'full-elementor-mcp' ),
					array( 'post_id' => $post_id )
				);
			}
		}

		// 3. Relevant Elementor meta keys with explicit existence semantics:
		$tracked_meta_keys = array(
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_version',
			'_elementor_pro_version',
			'_elementor_conditions',
			'_elementor_popup_display',
			'_wp_page_template',
		);

		$meta_entries = array();
		foreach ( $tracked_meta_keys as $m_key ) {
			$exists = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, $m_key ) : false;
			$val    = $exists ? get_post_meta( $post_id, $m_key, true ) : null;
			$meta_entries[ $m_key ] = array(
				'exists' => (bool) $exists,
				'value'  => $val,
			);
		}

		$elementor_meta = array(
			'data_exists'          => (bool) $data_exists,
			'data'                 => $elements,
			'page_settings_exists' => (bool) $settings_exists,
			'page_settings'        => $page_settings,
			'meta'                 => $meta_entries,
		);

		// 4. Featured Image attachment:
		$thumb_id = (int) get_post_meta( $post_id, '_thumbnail_id', true );

		// 5. Relevant terms (taxonomies) with explicit empty list tracking:
		$terms_map = array();
		if ( function_exists( 'wp_get_object_terms' ) ) {
			$taxonomies = array( 'elementor_library_type', 'elementor_library_category', 'category', 'post_tag' );
			foreach ( $taxonomies as $tax ) {
				if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $tax ) ) {
					continue;
				}
				$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'slugs' ) );
				$terms_map[ $tax ] = ( ! is_wp_error( $terms ) && is_array( $terms ) ) ? array_values( (array) $terms ) : array();
			}
		}

		return array(
			'strategy'       => self::STRATEGY_POST,
			'post_id'        => $post_id,
			'post_fields'    => $post_fields,
			'elementor'      => $elementor_meta,
			'featured_image' => $thumb_id,
			'terms'          => $terms_map,
		);
	}

	/**
	 * Captures post state projected into Schema v1 (commit b16 format).
	 *
	 * Lacks modern data_exists and page_settings_exists flags.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function capture_post_state_v1( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'checkpoint_capture_failed', __( 'Target post not found.', 'full-elementor-mcp' ), array( 'post_id' => $post_id ) );
		}

		$post_fields = array(
			'ID'             => (int) $post->ID,
			'post_type'      => (string) ( $post->post_type ?? 'page' ),
			'post_status'    => (string) ( $post->post_status ?? 'publish' ),
			'post_title'     => (string) ( $post->post_title ?? '' ),
			'post_name'      => (string) ( $post->post_name ?? '' ),
			'post_content'   => (string) ( $post->post_content ?? '' ),
			'post_excerpt'   => (string) ( $post->post_excerpt ?? '' ),
			'post_parent'    => (int) ( $post->post_parent ?? 0 ),
			'menu_order'     => (int) ( $post->menu_order ?? 0 ),
			'comment_status' => (string) ( $post->comment_status ?? 'closed' ),
			'ping_status'    => (string) ( $post->ping_status ?? 'closed' ),
			'post_password'  => (string) ( $post->post_password ?? '' ),
		);

		$raw_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $raw_data ) && '' !== trim( $raw_data ) ) {
			try {
				$elements = json_decode( $raw_data, true, 512, JSON_THROW_ON_ERROR );
			} catch ( \Throwable $e ) {
				return new \WP_Error(
					'checkpoint_capture_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Malformed JSON in _elementor_data storage: %s', 'full-elementor-mcp' ),
						$e->getMessage()
					),
					array( 'post_id' => $post_id )
				);
			}
			if ( ! is_array( $elements ) ) {
				return new \WP_Error(
					'checkpoint_capture_failed',
					__( '_elementor_data JSON must decode to an array list.', 'full-elementor-mcp' ),
					array( 'post_id' => $post_id )
				);
			}
			if ( ! empty( $elements ) && array_keys( $elements ) !== range( 0, count( $elements ) - 1 ) ) {
				return new \WP_Error(
					'checkpoint_capture_failed',
					__( '_elementor_data elements root must be a list of elements, not an associative object.', 'full-elementor-mcp' ),
					array( 'post_id' => $post_id )
				);
			}
		} elseif ( is_array( $raw_data ) ) {
			if ( ! empty( $raw_data ) && array_keys( $raw_data ) !== range( 0, count( $raw_data ) - 1 ) ) {
				return new \WP_Error(
					'checkpoint_capture_failed',
					__( '_elementor_data elements root must be a list of elements, not an associative object.', 'full-elementor-mcp' ),
					array( 'post_id' => $post_id )
				);
			}
			$elements = $raw_data;
		} elseif ( empty( $raw_data ) ) {
			$elements = array();
		} else {
			return new \WP_Error(
				'checkpoint_capture_failed',
				__( 'Invalid type for _elementor_data storage.', 'full-elementor-mcp' ),
				array( 'post_id' => $post_id )
			);
		}

		$raw_settings  = get_post_meta( $post_id, '_elementor_page_settings', true );
		$page_settings = is_array( $raw_settings ) ? $raw_settings : array();

		$tracked_meta_keys = array(
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_version',
			'_elementor_pro_version',
			'_elementor_conditions',
			'_elementor_popup_display',
			'_wp_page_template',
		);

		$meta_entries = array();
		foreach ( $tracked_meta_keys as $m_key ) {
			$exists = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, $m_key ) : false;
			$val    = $exists ? get_post_meta( $post_id, $m_key, true ) : null;
			$meta_entries[ $m_key ] = array(
				'exists' => (bool) $exists,
				'value'  => $val,
			);
		}

		$thumb_id = (int) get_post_meta( $post_id, '_thumbnail_id', true );

		$terms_map = array();
		if ( function_exists( 'wp_get_object_terms' ) ) {
			$taxonomies = array( 'elementor_library_type', 'elementor_library_category', 'category', 'post_tag' );
			foreach ( $taxonomies as $tax ) {
				if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $tax ) ) {
					continue;
				}
				$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'slugs' ) );
				$terms_map[ $tax ] = ( ! is_wp_error( $terms ) && is_array( $terms ) ) ? array_values( (array) $terms ) : array();
			}
		}

		return array(
			'strategy'       => self::STRATEGY_POST,
			'post_id'        => $post_id,
			'post_fields'    => $post_fields,
			'elementor'      => array(
				'data'          => is_array( $elements ) ? $elements : array(),
				'page_settings' => $page_settings,
				'meta'          => $meta_entries,
			),
			'featured_image' => $thumb_id,
			'terms'          => $terms_map,
		);
	}

	/**
	 * Restores persistent state for an Elementor post or page.
	 *
	 * @param int                  $post_id       Target post ID.
	 * @param array<string, mixed> $state         Captured state map.
	 * @param int                  $fencing_token Active fencing token.
	 * @param string               $owner_id      Active lock owner ID.
	 * @return true|\WP_Error
	 */
	private static function restore_post_state( int $post_id, array $state, int $fencing_token, string $owner_id ) {
		// 1. Restore WordPress post fields:
		$post_fields = $state['post_fields'] ?? array();
		$post_fields['ID'] = $post_id;

		$upd_res = Full_Elementor_MCP_Safe_Writes::update_post( $post_fields, true );
		if ( is_wp_error( $upd_res ) ) {
			return $upd_res;
		}

		// 2. Restore Elementor elements tree via guarded data layer or delete meta if absent:
		$data_layer  = new Full_Elementor_MCP_Data();
		$data_exists = ! empty( $state['elementor']['data_exists'] );
		$elements    = $state['elementor']['data'] ?? array();

		if ( ! $data_exists ) {
			$del_data = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_data' );
			if ( is_wp_error( $del_data ) ) {
				return $del_data;
			}
		} else {
			$data_res = $data_layer->save_page_data( $post_id, $elements );
			if ( is_wp_error( $data_res ) ) {
				return $data_res;
			}
		}

		// 3. Restore Elementor page settings or delete meta if absent:
		$settings_exists = ! empty( $state['elementor']['page_settings_exists'] );
		$page_settings   = $state['elementor']['page_settings'] ?? array();

		if ( ! $settings_exists ) {
			$del_settings = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_page_settings' );
			if ( is_wp_error( $del_settings ) ) {
				return $del_settings;
			}
		} else {
			$settings_res = $data_layer->save_page_settings( $post_id, $page_settings );
			if ( is_wp_error( $settings_res ) ) {
				return $settings_res;
			}
		}

		// 4. Restore tracked Elementor metadata preserving exact existence semantics:
		if ( isset( $state['elementor']['meta'] ) && is_array( $state['elementor']['meta'] ) ) {
			foreach ( $state['elementor']['meta'] as $m_key => $entry ) {
				if ( ! empty( $entry['exists'] ) ) {
					$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, $m_key, $entry['value'] );
				} else {
					$res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, $m_key );
				}
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
		}

		// 5. Restore featured image:
		$thumb_id = (int) ( $state['featured_image'] ?? 0 );
		if ( $thumb_id > 0 ) {
			$thumb_res = Full_Elementor_MCP_Safe_Writes::set_post_thumbnail( $post_id, $thumb_id );
			if ( is_wp_error( $thumb_res ) ) {
				return $thumb_res;
			}
		} else {
			$thumb_res = Full_Elementor_MCP_Safe_Writes::delete_post_thumbnail( $post_id );
			if ( is_wp_error( $thumb_res ) ) {
				return $thumb_res;
			}
		}

		// 6. Restore terms (taxonomies) symmetrically:
		if ( isset( $state['terms'] ) && is_array( $state['terms'] ) ) {
			foreach ( $state['terms'] as $tax => $slugs ) {
				$term_res = Full_Elementor_MCP_Safe_Writes::set_object_terms( $post_id, (array) $slugs, $tax );
				if ( is_wp_error( $term_res ) ) {
					return $term_res;
				}
			}
		}

		return true;
	}

	/**
	 * Restores post state using explicit Schema v1 semantics.
	 *
	 * Does not interpret missing modern existence flags as absence or meta deletion.
	 *
	 * @param int                  $post_id       Target post ID.
	 * @param array<string, mixed> $state         Captured state map.
	 * @param int                  $fencing_token Active fencing token.
	 * @param string               $owner_id      Active lock owner ID.
	 * @return true|\WP_Error
	 */
	private static function restore_post_state_v1( int $post_id, array $state, int $fencing_token, string $owner_id ) {
		// 1. Restore WordPress post fields:
		$post_fields = $state['post_fields'] ?? array();
		$post_fields['ID'] = $post_id;

		$upd_res = Full_Elementor_MCP_Safe_Writes::update_post( $post_fields, true );
		if ( is_wp_error( $upd_res ) ) {
			return $upd_res;
		}

		// 2. Restore Elementor elements tree directly (v1 semantics: data present means restore):
		$data_layer = new Full_Elementor_MCP_Data();
		if ( isset( $state['elementor']['data'] ) && is_array( $state['elementor']['data'] ) ) {
			$data_res = $data_layer->save_page_data( $post_id, $state['elementor']['data'] );
			if ( is_wp_error( $data_res ) ) {
				return $data_res;
			}
		}

		// 3. Restore Elementor page settings directly:
		if ( isset( $state['elementor']['page_settings'] ) && is_array( $state['elementor']['page_settings'] ) ) {
			$settings_res = $data_layer->save_page_settings( $post_id, $state['elementor']['page_settings'] );
			if ( is_wp_error( $settings_res ) ) {
				return $settings_res;
			}
		}

		// 4. Restore tracked Elementor metadata (supports both Format B 'meta' map and Format A flat keys):
		if ( isset( $state['elementor']['meta'] ) && is_array( $state['elementor']['meta'] ) ) {
			foreach ( $state['elementor']['meta'] as $m_key => $entry ) {
				if ( ! empty( $entry['exists'] ) ) {
					$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, $m_key, $entry['value'] );
				} else {
					$res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, $m_key );
				}
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
		} else {
			// Format A flat keys fallback:
			$el_meta   = $state['elementor'] ?? array();
			$flat_keys = array(
				'edit_mode'     => '_elementor_edit_mode',
				'template_type' => '_elementor_template_type',
				'page_template' => '_wp_page_template',
				'conditions'    => '_elementor_conditions',
				'popup_display' => '_elementor_popup_display',
			);
			foreach ( $flat_keys as $key => $m_key ) {
				if ( isset( $el_meta[ $key ] ) && '' !== $el_meta[ $key ] ) {
					$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, $m_key, $el_meta[ $key ] );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
				}
			}
		}

		// 5. Restore featured image:
		$thumb_id = (int) ( $state['featured_image'] ?? 0 );
		if ( $thumb_id > 0 ) {
			$thumb_res = Full_Elementor_MCP_Safe_Writes::set_post_thumbnail( $post_id, $thumb_id );
			if ( is_wp_error( $thumb_res ) ) {
				return $thumb_res;
			}
		} else {
			$thumb_res = Full_Elementor_MCP_Safe_Writes::delete_post_thumbnail( $post_id );
			if ( is_wp_error( $thumb_res ) ) {
				return $thumb_res;
			}
		}

		// 6. Restore terms:
		if ( isset( $state['terms'] ) && is_array( $state['terms'] ) ) {
			foreach ( $state['terms'] as $tax => $slugs ) {
				$term_res = Full_Elementor_MCP_Safe_Writes::set_object_terms( $post_id, (array) $slugs, $tax );
				if ( is_wp_error( $term_res ) ) {
					return $term_res;
				}
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Custom Code Snippet Strategy
	// -------------------------------------------------------------------------

	/**
	 * Captures persistent state for an Elementor code snippet.
	 *
	 * @param int $post_id Snippet post ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function capture_snippet_state( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'checkpoint_capture_failed', __( 'Snippet post not found.', 'full-elementor-mcp' ), array( 'post_id' => $post_id ) );
		}

		$has_code     = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, '_elementor_code' ) : false;
		$has_location = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, '_elementor_location' ) : false;
		$has_priority = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, '_elementor_priority' ) : false;
		$has_ttype    = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, '_elementor_template_type' ) : false;
		$has_emode    = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, '_elementor_edit_mode' ) : false;
		$has_conds    = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, '_elementor_conditions' ) : false;
		$has_extra    = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, '_elementor_extra_options' ) : false;

		$raw_priority = $has_priority ? get_post_meta( $post_id, '_elementor_priority', true ) : null;
		$priority_int = ( null !== $raw_priority && '' !== $raw_priority ) ? (int) $raw_priority : 0;

		return array(
			'strategy'        => self::STRATEGY_SNIPPET,
			'post_id'         => $post_id,
			'post_title'      => (string) ( $post->post_title ?? '' ),
			'post_status'     => (string) ( $post->post_status ?? 'publish' ),
			'code'            => (string) ( $has_code ? get_post_meta( $post_id, '_elementor_code', true ) : '' ),
			'code_exists'     => (bool) $has_code,
			'location'        => (string) ( $has_location ? get_post_meta( $post_id, '_elementor_location', true ) : '' ),
			'location_exists' => (bool) $has_location,
			'priority'        => $priority_int,
			'priority_exists' => (bool) $has_priority,
			'template_type'   => array(
				'exists' => (bool) $has_ttype,
				'value'  => $has_ttype ? get_post_meta( $post_id, '_elementor_template_type', true ) : null,
			),
			'edit_mode'       => array(
				'exists' => (bool) $has_emode,
				'value'  => $has_emode ? get_post_meta( $post_id, '_elementor_edit_mode', true ) : null,
			),
			'conditions'      => array(
				'exists' => (bool) $has_conds,
				'value'  => $has_conds ? get_post_meta( $post_id, '_elementor_conditions', true ) : null,
			),
			'extra_options'   => array(
				'exists' => (bool) $has_extra,
				'value'  => $has_extra ? get_post_meta( $post_id, '_elementor_extra_options', true ) : null,
			),
		);
	}

	/**
	 * Captures snippet state projected into Schema v1 (commit b16 format).
	 *
	 * @param int $post_id Snippet post ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function capture_snippet_state_v1( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'checkpoint_capture_failed', __( 'Snippet post not found.', 'full-elementor-mcp' ), array( 'post_id' => $post_id ) );
		}

		$conditions_exists = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, '_elementor_conditions' ) : false;
		$extra_exists      = function_exists( 'metadata_exists' ) ? metadata_exists( 'post', $post_id, '_elementor_extra_options' ) : false;

		return array(
			'strategy'      => self::STRATEGY_SNIPPET,
			'post_id'       => $post_id,
			'post_title'    => (string) ( $post->post_title ?? '' ),
			'post_status'   => (string) ( $post->post_status ?? 'publish' ),
			'code'          => (string) get_post_meta( $post_id, '_elementor_code', true ),
			'location'      => (string) get_post_meta( $post_id, '_elementor_location', true ),
			'priority'      => (int) get_post_meta( $post_id, '_elementor_priority', true ),
			'conditions'    => array(
				'exists' => (bool) $conditions_exists,
				'value'  => $conditions_exists ? get_post_meta( $post_id, '_elementor_conditions', true ) : null,
			),
			'extra_options' => array(
				'exists' => (bool) $extra_exists,
				'value'  => $extra_exists ? get_post_meta( $post_id, '_elementor_extra_options', true ) : null,
			),
		);
	}

	/**
	 * Restores persistent state for a custom code snippet.
	 *
	 * @param int                  $post_id       Snippet post ID.
	 * @param array<string, mixed> $state         Captured state map.
	 * @param int                  $fencing_token Active fencing token.
	 * @param string               $owner_id      Active lock owner ID.
	 * @return true|\WP_Error
	 */
	private static function restore_snippet_state( int $post_id, array $state, int $fencing_token, string $owner_id ) {
		$upd_res = Full_Elementor_MCP_Safe_Writes::update_post( array(
			'ID'          => $post_id,
			'post_title'  => (string) ( $state['post_title'] ?? '' ),
			'post_status' => (string) ( $state['post_status'] ?? 'publish' ),
		), true );

		if ( is_wp_error( $upd_res ) ) {
			return $upd_res;
		}

		// Code:
		if ( array_key_exists( 'code_exists', $state ) && empty( $state['code_exists'] ) ) {
			$res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_code' );
		} else {
			$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_code', (string) ( $state['code'] ?? '' ) );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// Location:
		if ( array_key_exists( 'location_exists', $state ) && empty( $state['location_exists'] ) ) {
			$res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_location' );
		} else {
			$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_location', (string) ( $state['location'] ?? '' ) );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// Priority: symmetric without max(1, ...) transform:
		if ( array_key_exists( 'priority_exists', $state ) && empty( $state['priority_exists'] ) ) {
			$res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_priority' );
		} else {
			$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_priority', (int) ( $state['priority'] ?? 0 ) );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// Template type with existence semantics:
		if ( isset( $state['template_type'] ) && is_array( $state['template_type'] ) ) {
			if ( ! empty( $state['template_type']['exists'] ) ) {
				$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_template_type', $state['template_type']['value'] );
			} else {
				$res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_template_type' );
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		// Edit mode with existence semantics:
		if ( isset( $state['edit_mode'] ) && is_array( $state['edit_mode'] ) ) {
			if ( ! empty( $state['edit_mode']['exists'] ) ) {
				$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_edit_mode', $state['edit_mode']['value'] );
			} else {
				$res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_edit_mode' );
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		// Conditions with existence semantics:
		if ( isset( $state['conditions'] ) && is_array( $state['conditions'] ) ) {
			if ( ! empty( $state['conditions']['exists'] ) ) {
				$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_conditions', $state['conditions']['value'] );
			} else {
				$res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_conditions' );
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		// Extra options (ensure_jquery, etc.) with existence semantics:
		if ( isset( $state['extra_options'] ) && is_array( $state['extra_options'] ) ) {
			if ( ! empty( $state['extra_options']['exists'] ) ) {
				$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_extra_options', $state['extra_options']['value'] );
			} else {
				$res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_extra_options' );
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Global Elementor Kit Strategy
	// -------------------------------------------------------------------------

	/**
	 * Captures persistent state for the active Elementor global kit.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function capture_global_kit_state(): array|\WP_Error {
		$active_kit_id = (int) get_option( 'elementor_active_kit', 0 );
		$kit_settings  = array();
		$kit_post      = null;

		if ( $active_kit_id > 0 ) {
			$raw_settings = get_post_meta( $active_kit_id, '_elementor_page_settings', true );
			$kit_settings = is_array( $raw_settings ) ? $raw_settings : array();
			$post         = get_post( $active_kit_id );
			if ( $post ) {
				$kit_post = array(
					'post_title'  => (string) $post->post_title,
					'post_status' => (string) $post->post_status,
				);
			}
		}

		return array(
			'strategy'      => self::STRATEGY_GLOBAL,
			'active_kit_id' => $active_kit_id,
			'kit_post'      => $kit_post,
			'kit_settings'  => $kit_settings,
		);
	}

	/**
	 * Restores persistent state for the active Elementor global kit.
	 *
	 * @param array<string, mixed> $state         Captured state map.
	 * @param int                  $fencing_token Active fencing token.
	 * @param string               $owner_id      Active lock owner ID.
	 * @return true|\WP_Error
	 */
	private static function restore_global_kit_state( array $state, int $fencing_token, string $owner_id ) {
		$target_kit_id  = (int) ( $state['active_kit_id'] ?? 0 );
		$current_kit_id = (int) get_option( 'elementor_active_kit', 0 );

		// Always restore active kit option, even when target is 0:
		if ( $target_kit_id !== $current_kit_id ) {
			$opt_res = Full_Elementor_MCP_Safe_Writes::update_option( 'elementor_active_kit', $target_kit_id );
			if ( is_wp_error( $opt_res ) ) {
				return $opt_res;
			}
		}

		if ( $target_kit_id > 0 ) {
			$guard = Full_Elementor_MCP_Safe_Writes::assert_write_boundary( 'global:elementor-kit-state' );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			// Write settings even when empty array:
			$kit_settings = isset( $state['kit_settings'] ) && is_array( $state['kit_settings'] ) ? $state['kit_settings'] : array();
			update_post_meta( $target_kit_id, '_elementor_page_settings', $kit_settings );
			Full_Elementor_MCP_Mutation_Context::increment_write_count();
		}

		// Flush kit CSS and cache if helper exists:
		if ( function_exists( 'delete_option' ) ) {
			delete_option( '_elementor_active_kit_cache' );
		}

		return true;
	}
}
