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
		if ( 'recovery' === $checkpoint_type || ! empty( $context['is_permanent_delete'] ) ) {
			return self::CAPABILITY_RECOVERY_ONLY;
		}

		$strategy = self::resolve_strategy( $resource_key );
		if ( in_array( $strategy, array( self::STRATEGY_POST, self::STRATEGY_SNIPPET, self::STRATEGY_GLOBAL ), true ) ) {
			return self::CAPABILITY_EXACT;
		}

		return self::CAPABILITY_UNSUPPORTED;
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
	 * Restores persistent resource state from a validated checkpoint state array.
	 *
	 * All physical writes MUST be routed through guarded persistent write sinks
	 * with active mutation fencing asserting current fencing token.
	 *
	 * @param string               $resource_key  Canonical resource key.
	 * @param array<string, mixed> $state         Decrypted, validated state dictionary.
	 * @param int                  $fencing_token Active fencing token.
	 * @param string               $owner_id      Active lock owner ID.
	 * @return true|\WP_Error True on successful restoration or WP_Error on failure.
	 */
	public static function restore( string $resource_key, array $state, int $fencing_token, string $owner_id ) {
		$strategy = self::resolve_strategy( $resource_key );

		if ( self::STRATEGY_GLOBAL === $strategy ) {
			return self::restore_global_kit_state( $state, $fencing_token, $owner_id );
		}

		if ( self::STRATEGY_SNIPPET === $strategy ) {
			$post_id = (int) substr( $resource_key, 5 );
			return self::restore_snippet_state( $post_id, $state, $fencing_token, $owner_id );
		}

		if ( self::STRATEGY_POST === $strategy ) {
			$post_id = (int) substr( $resource_key, 5 );
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

		// 2. Elementor data & settings:
		$raw_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $raw_data ) && '' !== trim( $raw_data ) ) {
			$elements = json_decode( $raw_data, true );
			if ( ! is_array( $elements ) ) {
				$elements = array();
			}
		} elseif ( is_array( $raw_data ) ) {
			$elements = $raw_data;
		} else {
			$elements = array();
		}

		$raw_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		$page_settings = is_array( $raw_settings ) ? $raw_settings : array();

		$elementor_meta = array(
			'data'           => $elements,
			'page_settings'  => $page_settings,
			'edit_mode'      => (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
			'template_type'  => (string) get_post_meta( $post_id, '_elementor_template_type', true ),
			'version'        => (string) get_post_meta( $post_id, '_elementor_version', true ),
			'pro_version'    => (string) get_post_meta( $post_id, '_elementor_pro_version', true ),
			'conditions'     => get_post_meta( $post_id, '_elementor_conditions', true ),
			'popup_display'  => get_post_meta( $post_id, '_elementor_popup_display', true ),
			'page_template'  => (string) get_post_meta( $post_id, '_wp_page_template', true ),
		);

		// 3. Featured Image attachment:
		$thumb_id = (int) get_post_meta( $post_id, '_thumbnail_id', true );

		// 4. Relevant terms (taxonomies):
		$terms_map = array();
		if ( function_exists( 'wp_get_object_terms' ) ) {
			$taxonomies = array( 'elementor_library_type', 'category', 'post_tag' );
			foreach ( $taxonomies as $tax ) {
				$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'slugs' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$terms_map[ $tax ] = array_values( (array) $terms );
				}
			}
		}

		return array(
			'strategy'        => self::STRATEGY_POST,
			'post_id'         => $post_id,
			'post_fields'     => $post_fields,
			'elementor'       => $elementor_meta,
			'featured_image'  => $thumb_id,
			'terms'           => $terms_map,
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

		// 2. Restore Elementor elements tree via guarded data layer:
		$data_layer = new Full_Elementor_MCP_Data();
		$elements   = $state['elementor']['data'] ?? array();

		$data_res = $data_layer->save_page_data( $post_id, $elements );
		if ( is_wp_error( $data_res ) ) {
			return $data_res;
		}

		// 3. Restore Elementor page settings:
		$page_settings = $state['elementor']['page_settings'] ?? array();
		$settings_res  = $data_layer->save_page_settings( $post_id, $page_settings );
		if ( is_wp_error( $settings_res ) ) {
			return $settings_res;
		}

		// 4. Restore Elementor flags and metadata:
		$el_meta = $state['elementor'] ?? array();
		if ( isset( $el_meta['edit_mode'] ) && '' !== $el_meta['edit_mode'] ) {
			$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_edit_mode', $el_meta['edit_mode'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		if ( isset( $el_meta['template_type'] ) && '' !== $el_meta['template_type'] ) {
			$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_template_type', $el_meta['template_type'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		if ( isset( $el_meta['page_template'] ) && '' !== $el_meta['page_template'] ) {
			$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_wp_page_template', $el_meta['page_template'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		if ( isset( $el_meta['conditions'] ) ) {
			$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_conditions', $el_meta['conditions'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		if ( isset( $el_meta['popup_display'] ) ) {
			$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_popup_display', $el_meta['popup_display'] );
			if ( is_wp_error( $res ) ) {
				return $res;
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
		if ( ! empty( $state['terms'] ) && is_array( $state['terms'] ) ) {
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

		return array(
			'strategy'        => self::STRATEGY_SNIPPET,
			'post_id'         => $post_id,
			'post_title'      => (string) ( $post->post_title ?? '' ),
			'post_status'     => (string) ( $post->post_status ?? 'publish' ),
			'code'            => (string) get_post_meta( $post_id, '_elementor_code', true ),
			'location'        => (string) get_post_meta( $post_id, '_elementor_location', true ),
			'priority'        => (int) get_post_meta( $post_id, '_elementor_priority', true ),
			'conditions'      => get_post_meta( $post_id, '_elementor_conditions', true ),
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

		$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_code', (string) ( $state['code'] ?? '' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_location', (string) ( $state['location'] ?? '' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_priority', max( 1, (int) ( $state['priority'] ?? 1 ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( isset( $state['conditions'] ) ) {
			$res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_conditions', $state['conditions'] );
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
			'strategy'       => self::STRATEGY_GLOBAL,
			'active_kit_id'  => $active_kit_id,
			'kit_post'       => $kit_post,
			'kit_settings'   => $kit_settings,
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

		if ( $target_kit_id > 0 && $target_kit_id !== $current_kit_id ) {
			$opt_res = Full_Elementor_MCP_Safe_Writes::update_option( 'elementor_active_kit', $target_kit_id );
			if ( is_wp_error( $opt_res ) ) {
				return $opt_res;
			}
		}

		if ( $target_kit_id > 0 && ! empty( $state['kit_settings'] ) ) {
			$guard = Full_Elementor_MCP_Safe_Writes::assert_write_boundary( 'global:elementor-kit-state' );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			update_post_meta( $target_kit_id, '_elementor_page_settings', (array) $state['kit_settings'] );
			Full_Elementor_MCP_Mutation_Context::increment_write_count();
		}

		// Flush kit CSS and cache if helper exists:
		if ( function_exists( 'delete_option' ) ) {
			delete_option( '_elementor_active_kit_cache' );
		}

		return true;
	}
}
