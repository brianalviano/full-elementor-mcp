<?php
/**
 * Global settings MCP abilities for Elementor.
 *
 * Registers 2 tools for updating global colors and typography
 * in the Elementor kit (site-wide settings).
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the global settings abilities.
 *
 * @since 1.0.0
 */
class Full_Full_Elementor_MCP_Global_Abilities {

	/**
	 * @var Full_Full_Elementor_MCP_Data
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Full_Full_Elementor_MCP_Data $data The data access layer.
	 */
	public function __construct( Full_Full_Elementor_MCP_Data $data ) {
		$this->data = $data;
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'full-elementor-mcp/update-global-colors',
			'full-elementor-mcp/update-global-typography',
			'full-elementor-mcp/list-kits',
			'full-elementor-mcp/set-active-kit',
		);
	}

	/**
	 * Registers all global abilities.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		$this->register_update_global_colors();
		$this->register_update_global_typography();
		$this->register_list_kits();
		$this->register_set_active_kit();
	}

	/**
	 * Permission check for global settings (requires manage_options).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function check_manage_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// update-global-colors
	// -------------------------------------------------------------------------

	private function register_update_global_colors(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/update-global-colors',
			array(
				'label'               => __( 'Update Global Colors', 'full-elementor-mcp' ),
				'description'         => __( 'Updates the site-wide color palette in the Elementor kit. Provide an array of color objects with id, title, and color (hex).', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_update_global_colors' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'colors' => array(
							'type'        => 'array',
							'description' => __( 'Array of color definitions.', 'full-elementor-mcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'_id'   => array(
										'type'        => 'string',
										'description' => __( 'Unique color ID (e.g. "primary").', 'full-elementor-mcp' ),
									),
									'title' => array(
										'type'        => 'string',
										'description' => __( 'Human-readable title.', 'full-elementor-mcp' ),
									),
									'color' => array(
										'type'        => 'string',
										'description' => __( 'Color value in hex format (e.g. "#FF5733").', 'full-elementor-mcp' ),
									),
								),
								'required' => array( '_id', 'title', 'color' ),
							),
						),
					),
					'required'   => array( 'colors' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the update-global-colors ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_update_global_colors( $input ) {
		$colors = $input['colors'] ?? array();

		if ( empty( $colors ) || ! is_array( $colors ) ) {
			return new \WP_Error( 'missing_colors', __( 'The colors parameter is required and must be an array.', 'full-elementor-mcp' ) );
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();

		if ( ! $kit || ! $kit->get_id() ) {
			return new \WP_Error( 'kit_not_found', __( 'Active Elementor kit not found.', 'full-elementor-mcp' ) );
		}

		// Get current kit settings.
		$kit_settings = $kit->get_settings();

		// Merge colors: update existing by _id, add new ones.
		$existing_colors = $kit_settings['custom_colors'] ?? array();
		$existing_map    = array();

		foreach ( $existing_colors as $index => $existing ) {
			if ( isset( $existing['_id'] ) ) {
				$existing_map[ $existing['_id'] ] = $index;
			}
		}

		foreach ( $colors as $color ) {
			$color_id = sanitize_text_field( $color['_id'] ?? '' );
			if ( empty( $color_id ) ) {
				continue;
			}

			$color_entry = array(
				'_id'   => $color_id,
				'title' => sanitize_text_field( $color['title'] ?? '' ),
				'color' => sanitize_hex_color( $color['color'] ?? '' ),
			);

			if ( isset( $existing_map[ $color_id ] ) ) {
				$existing_colors[ $existing_map[ $color_id ] ] = $color_entry;
			} else {
				$existing_colors[] = $color_entry;
			}
		}

		$kit->update_settings( array( 'custom_colors' => $existing_colors ) );

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// update-global-typography
	// -------------------------------------------------------------------------

	private function register_update_global_typography(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/update-global-typography',
			array(
				'label'               => __( 'Update Global Typography', 'full-elementor-mcp' ),
				'description'         => __( 'Updates the site-wide typography settings in the Elementor kit.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_update_global_typography' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'typography' => array(
							'type'        => 'array',
							'description' => __( 'Array of typography definitions.', 'full-elementor-mcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'_id'                      => array(
										'type'        => 'string',
										'description' => __( 'Unique typography ID (e.g. "primary").', 'full-elementor-mcp' ),
									),
									'title'                    => array(
										'type'        => 'string',
										'description' => __( 'Human-readable title.', 'full-elementor-mcp' ),
									),
									'typography_font_family'   => array(
										'type'        => 'string',
										'description' => __( 'Font family name.', 'full-elementor-mcp' ),
									),
									'typography_font_size'     => array(
										'type'        => 'object',
										'description' => __( 'Font size with size and unit.', 'full-elementor-mcp' ),
									),
									'typography_font_weight'   => array(
										'type'        => 'string',
										'description' => __( 'Font weight (100-900, normal, bold).', 'full-elementor-mcp' ),
									),
									'typography_line_height'   => array(
										'type'        => 'object',
										'description' => __( 'Line height with size and unit.', 'full-elementor-mcp' ),
									),
									'typography_letter_spacing' => array(
										'type'        => 'object',
										'description' => __( 'Letter spacing with size and unit.', 'full-elementor-mcp' ),
									),
								),
								'required' => array( '_id', 'title' ),
							),
						),
					),
					'required'   => array( 'typography' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the update-global-typography ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_update_global_typography( $input ) {
		$typography = $input['typography'] ?? array();

		if ( empty( $typography ) || ! is_array( $typography ) ) {
			return new \WP_Error( 'missing_typography', __( 'The typography parameter is required and must be an array.', 'full-elementor-mcp' ) );
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();

		if ( ! $kit || ! $kit->get_id() ) {
			return new \WP_Error( 'kit_not_found', __( 'Active Elementor kit not found.', 'full-elementor-mcp' ) );
		}

		$kit_settings      = $kit->get_settings();
		$existing_typo     = $kit_settings['custom_typography'] ?? array();
		$existing_map      = array();

		foreach ( $existing_typo as $index => $existing ) {
			if ( isset( $existing['_id'] ) ) {
				$existing_map[ $existing['_id'] ] = $index;
			}
		}

		$allowed_keys = array(
			'_id', 'title', 'typography_typography',
			'typography_font_family', 'typography_font_size',
			'typography_font_weight', 'typography_text_transform',
			'typography_font_style', 'typography_text_decoration',
			'typography_line_height', 'typography_letter_spacing',
			'typography_word_spacing',
		);

		foreach ( $typography as $typo ) {
			$typo_id = sanitize_text_field( $typo['_id'] ?? '' );
			if ( empty( $typo_id ) ) {
				continue;
			}

			// Build a sanitized entry with only allowed keys.
			$typo_entry = array();
			foreach ( $allowed_keys as $key ) {
				if ( isset( $typo[ $key ] ) ) {
					$typo_entry[ $key ] = $typo[ $key ];
				}
			}

			// Ensure typography_typography is set to 'custom' to activate overrides.
			$typo_entry['typography_typography'] = 'custom';

			if ( isset( $existing_map[ $typo_id ] ) ) {
				$existing_typo[ $existing_map[ $typo_id ] ] = array_merge(
					$existing_typo[ $existing_map[ $typo_id ] ],
					$typo_entry
				);
			} else {
				$existing_typo[] = $typo_entry;
			}
		}

		$kit->update_settings( array( 'custom_typography' => $existing_typo ) );

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// list-kits
	// -------------------------------------------------------------------------

	private function register_list_kits(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/list-kits',
			array(
				'label'               => __( 'List Kits', 'full-elementor-mcp' ),
				'description'         => __( 'Lists all Elementor kits (saved global style sets) in the library and indicates which one is currently active. Useful before calling set-active-kit.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_list_kits' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'kits'          => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'active_kit_id' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_list_kits( $input ) {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return new \WP_Error( 'elementor_missing', __( 'Elementor is not active.', 'full-elementor-mcp' ) );
		}

		$active_id = (int) get_option( 'elementor_active_kit', 0 );

		$query = new \WP_Query(
			array(
				'post_type'      => 'elementor_library',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'meta_query'     => array(
					array(
						'key'   => '_elementor_template_type',
						'value' => 'kit',
					),
				),
			)
		);

		$kits = array();
		foreach ( $query->posts as $kit ) {
			$kits[] = array(
				'id'        => $kit->ID,
				'title'     => $kit->post_title,
				'status'    => $kit->post_status,
				'is_active' => ( $kit->ID === $active_id ),
				'edit_url'  => admin_url( 'post.php?post=' . $kit->ID . '&action=elementor' ),
			);
		}

		return array( 'kits' => $kits, 'active_kit_id' => $active_id );
	}

	// -------------------------------------------------------------------------
	// set-active-kit
	// -------------------------------------------------------------------------

	private function register_set_active_kit(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/set-active-kit',
			array(
				'label'               => __( 'Set Active Kit', 'full-elementor-mcp' ),
				'description'         => __( 'Switches the active Elementor kit (site-wide design system). Refuses to activate a non-kit post. Updates the elementor_active_kit option and clears Elementor caches so the new global colors/typography take effect immediately.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_set_active_kit' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'kit_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'kit_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'active_kit_id' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_set_active_kit( $input ) {
		$kit_id = absint( $input['kit_id'] ?? 0 );
		if ( ! $kit_id ) {
			return new \WP_Error( 'missing_kit_id', __( 'kit_id is required.', 'full-elementor-mcp' ) );
		}

		$post = get_post( $kit_id );
		if ( ! $post || 'elementor_library' !== $post->post_type ) {
			return new \WP_Error( 'not_a_kit', __( 'Post is not an Elementor library entry.', 'full-elementor-mcp' ) );
		}

		if ( 'kit' !== get_post_meta( $kit_id, '_elementor_template_type', true ) ) {
			return new \WP_Error( 'not_a_kit', __( 'Post is not an Elementor kit.', 'full-elementor-mcp' ) );
		}

		update_option( 'elementor_active_kit', $kit_id );

		// Clear Elementor's CSS cache so new globals take effect.
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return array( 'success' => true, 'active_kit_id' => $kit_id );
	}
}
