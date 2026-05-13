<?php
/**
 * Widget MCP abilities for Elementor.
 *
 * Registers the universal add-widget/update-widget tools plus convenience
 * shortcut tools for common widgets (heading, text, image, button, etc.).
 * Pro widget tools register only when Elementor Pro is active.
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the widget abilities.
 *
 * @since 1.0.0
 */
class Full_Full_Elementor_MCP_Widget_Abilities {

	/**
	 * @var Full_Full_Elementor_MCP_Data
	 */
	private $data;

	/**
	 * @var Full_Full_Elementor_MCP_Element_Factory
	 */
	private $factory;

	/**
	 * @var Full_Full_Elementor_MCP_Schema_Generator
	 */
	private $schema_generator;

	/**
	 * @var Full_Full_Elementor_MCP_Settings_Validator
	 */
	private $validator;

	/**
	 * Tracked ability names.
	 *
	 * @var string[]
	 */
	private $ability_names = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Full_Full_Elementor_MCP_Data               $data             The data access layer.
	 * @param Full_Full_Elementor_MCP_Element_Factory    $factory          The element factory.
	 * @param Full_Full_Elementor_MCP_Schema_Generator   $schema_generator The schema generator.
	 * @param Full_Full_Elementor_MCP_Settings_Validator $validator        The settings validator.
	 */
	public function __construct(
		Full_Full_Elementor_MCP_Data $data,
		Full_Full_Elementor_MCP_Element_Factory $factory,
		Full_Full_Elementor_MCP_Schema_Generator $schema_generator,
		Full_Full_Elementor_MCP_Settings_Validator $validator
	) {
		$this->data             = $data;
		$this->factory          = $factory;
		$this->schema_generator = $schema_generator;
		$this->validator        = $validator;
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers all widget abilities.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		// Universal tools.
		$this->register_add_widget();
		$this->register_update_widget();

		// Core widget convenience tools.
		$this->register_add_heading();
		$this->register_add_text_editor();
		$this->register_add_image();
		$this->register_add_button();
		$this->register_add_video();
		$this->register_add_icon();
		$this->register_add_spacer();
		$this->register_add_divider();
		$this->register_add_icon_box();

		// Extended core widget convenience tools.
		$this->register_add_accordion();
		$this->register_add_alert();
		$this->register_add_counter();
		$this->register_add_google_maps();
		$this->register_add_icon_list();
		$this->register_add_image_box();
		$this->register_add_image_carousel();
		$this->register_add_progress();
		$this->register_add_social_icons();
		$this->register_add_star_rating();
		$this->register_add_tabs();
		$this->register_add_testimonial();
		$this->register_add_toggle();
		$this->register_add_html();
		$this->register_add_menu_anchor();
		$this->register_add_shortcode();
		$this->register_add_rating();
		$this->register_add_text_path();

		// Pro widget convenience tools (only if Pro is active).
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			$this->register_add_form();
			$this->register_add_posts_grid();
			$this->register_add_countdown();
			$this->register_add_price_table();
			$this->register_add_flip_box();
			$this->register_add_animated_headline();
			$this->register_add_call_to_action();
			$this->register_add_slides();
			$this->register_add_testimonial_carousel();
			$this->register_add_price_list();
			$this->register_add_gallery();
			$this->register_add_share_buttons();
			$this->register_add_table_of_contents();
			$this->register_add_blockquote();
			$this->register_add_lottie();
			$this->register_add_hotspot();
			$this->register_add_nav_menu();
			$this->register_add_loop_grid();
			$this->register_add_loop_carousel();
			$this->register_add_media_carousel();
			$this->register_add_nested_tabs();
			$this->register_add_nested_accordion();
			$this->register_add_portfolio();
			$this->register_add_author_box();
			$this->register_add_login();
			$this->register_add_code_highlight();
			$this->register_add_reviews();
			$this->register_add_off_canvas();
			$this->register_add_progress_tracker();
			$this->register_add_search();

			// WooCommerce widget convenience tools (only if WooCommerce is active).
			if ( class_exists( 'WooCommerce' ) ) {
				$this->register_add_wc_products();
				$this->register_add_wc_add_to_cart();
				$this->register_add_wc_cart();
				$this->register_add_wc_checkout();
				$this->register_add_wc_menu_cart();
			}
		}
	}

	/**
	 * Permission check for widget editing.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $input The input data.
	 * @return bool
	 */
	public function check_edit_permission( $input = null ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		return true;
	}

	// =========================================================================
	// Universal: add-widget
	// =========================================================================

	private function register_add_widget(): void {
		$this->ability_names[] = 'full-elementor-mcp/add-widget';

		full_elementor_mcp_register_ability(
			'full-elementor-mcp/add-widget',
			array(
				'label'               => __( 'Add Widget', 'full-elementor-mcp' ),
				'description'         => __( 'Adds any Elementor widget to a container. Use get-widget-schema to discover the available settings for each widget type.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_add_widget' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'parent_id'   => array(
							'type'        => 'string',
							'description' => __( 'Parent container element ID.', 'full-elementor-mcp' ),
						),
						'position'    => array(
							'type'        => 'integer',
							'description' => __( 'Insert position. -1 = append.', 'full-elementor-mcp' ),
						),
						'widget_type' => array(
							'type'        => 'string',
							'description' => __( 'The widget type name (e.g. "heading", "button", "image").', 'full-elementor-mcp' ),
						),
						'settings'    => array(
							'type'        => 'object',
							'description' => __( 'Widget-specific settings.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'parent_id', 'widget_type' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'element_id'  => array( 'type' => 'string' ),
						'widget_type' => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the add-widget ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_add_widget( $input ) {
		$post_id     = absint( $input['post_id'] ?? 0 );
		$parent_id   = sanitize_text_field( $input['parent_id'] ?? '' );
		$position    = intval( $input['position'] ?? -1 );
		$widget_type = sanitize_text_field( $input['widget_type'] ?? '' );
		$settings    = $input['settings'] ?? array();

		if ( ! $post_id || empty( $parent_id ) || empty( $widget_type ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id, parent_id, and widget_type are required.', 'full-elementor-mcp' ) );
		}

		// Validate widget type exists.
		$widget_instance = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget_type );
		if ( ! $widget_instance ) {
			return new \WP_Error(
				'invalid_widget_type',
				/* translators: %s: widget type name */
				sprintf( __( 'Widget type "%s" not found.', 'full-elementor-mcp' ), $widget_type )
			);
		}

		// Validate settings if provided.
		if ( ! empty( $settings ) ) {
			$valid = $this->validator->validate( $widget_type, $settings );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$widget = $this->factory->create_widget( $widget_type, $settings );

		$inserted = $this->data->insert_element( $page_data, $parent_id, $widget, $position );

		if ( ! $inserted ) {
			return new \WP_Error( 'parent_not_found', __( 'Parent container not found.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'element_id'  => $widget['id'],
			'widget_type' => $widget_type,
		);
	}

	// =========================================================================
	// Universal: update-widget
	// =========================================================================

	private function register_update_widget(): void {
		$this->ability_names[] = 'full-elementor-mcp/update-widget';

		full_elementor_mcp_register_ability(
			'full-elementor-mcp/update-widget',
			array(
				'label'               => __( 'Update Widget', 'full-elementor-mcp' ),
				'description'         => __( 'Updates settings on an existing widget. Settings are merged (partial update).', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_update_widget' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'element_id' => array(
							'type'        => 'string',
							'description' => __( 'The widget element ID.', 'full-elementor-mcp' ),
						),
						'settings'   => array(
							'type'        => 'object',
							'description' => __( 'Partial settings to merge.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'element_id' => array( 'type' => 'string' ),
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
	 * Executes the update-widget ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_update_widget( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );
		$settings   = $input['settings'] ?? array();

		if ( ! $post_id || empty( $element_id ) || empty( $settings ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id, element_id, and settings are required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// Find the widget to validate its type.
		$element = $this->data->find_element_by_id( $page_data, $element_id );

		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		if ( ( $element['elType'] ?? '' ) !== 'widget' ) {
			return new \WP_Error( 'not_a_widget', __( 'Target element is not a widget.', 'full-elementor-mcp' ) );
		}

		$updated = $this->data->update_element_settings( $page_data, $element_id, $settings );

		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update widget settings.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			'element_id' => $element_id,
		);
	}

	// =========================================================================
	// Convenience tool helper
	// =========================================================================

	/**
	 * Registers a convenience widget tool and adds it to ability_names.
	 *
	 * @param string $name        Ability name suffix (e.g. 'add-heading').
	 * @param string $label       Human label.
	 * @param string $description Tool description.
	 * @param array  $extra_props Extra input schema properties beyond post_id/parent_id/position.
	 * @param array  $required    Required property names (post_id and parent_id always added).
	 * @param string $widget_type The Elementor widget type name.
	 * @param array  $defaults    Default settings for this widget type.
	 */
	private function register_convenience_tool(
		string $name,
		string $label,
		string $description,
		array $extra_props,
		array $required,
		string $widget_type,
		array $defaults = array()
	): void {
		$full_name             = 'full-elementor-mcp/' . $name;
		$this->ability_names[] = $full_name;

		$base_props = array(
			'post_id'   => array(
				'type'        => 'integer',
				'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
			),
			'parent_id' => array(
				'type'        => 'string',
				'description' => __( 'Parent container element ID.', 'full-elementor-mcp' ),
			),
			'position'  => array(
				'type'        => 'integer',
				'description' => __( 'Insert position. -1 = append.', 'full-elementor-mcp' ),
			),
		);

		$all_required = array_unique( array_merge( array( 'post_id', 'parent_id' ), $required ) );

		full_elementor_mcp_register_ability(
			$full_name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => function ( $input ) use ( $widget_type, $extra_props, $defaults ) {
					return $this->execute_convenience_tool( $input, $widget_type, array_keys( $extra_props ), $defaults );
				},
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge( $base_props, $extra_props ),
					'required'   => $all_required,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'element_id' => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Shared execution for convenience tools.
	 *
	 * Extracts the widget-specific settings keys from input and delegates to add-widget logic.
	 *
	 * @param array  $input        The input parameters.
	 * @param string $widget_type  The Elementor widget type.
	 * @param array  $setting_keys Setting keys to extract from input.
	 * @param array  $defaults     Default settings.
	 * @return array|\WP_Error
	 */
	private function execute_convenience_tool( $input, string $widget_type, array $setting_keys, array $defaults ) {
		$settings = $defaults;

		// Keys that are tool params, not widget settings.
		$non_setting_keys = array( 'post_id', 'parent_id', 'position' );

		// Pass through all input keys that aren't base tool params.
		// This allows group controls (typography_*), responsive suffixes
		// (_mobile, _tablet), and common advanced controls (_margin, etc.)
		// to flow through without being explicitly listed in extra_props.
		foreach ( $input as $key => $value ) {
			if ( in_array( $key, $non_setting_keys, true ) ) {
				continue;
			}
			$settings[ $key ] = $value;
		}

		return $this->execute_add_widget(
			array(
				'post_id'     => $input['post_id'] ?? 0,
				'parent_id'   => $input['parent_id'] ?? '',
				'position'    => $input['position'] ?? -1,
				'widget_type' => $widget_type,
				'settings'    => $settings,
			)
		);
	}

	// =========================================================================
	// Core convenience tools
	// =========================================================================

	private function register_add_heading(): void {
		$this->register_convenience_tool(
			'add-heading',
			__( 'Add Heading', 'full-elementor-mcp' ),
			__( 'Adds a heading widget. Supports full typography (set typography_typography=custom first), text stroke, text shadow, blend mode, hover color. Also accepts responsive suffixes (align_tablet, align_mobile) and common advanced controls (_margin, _padding, _background_*, _border_*, etc).', 'full-elementor-mcp' ),
			array(
				'title'                       => array( 'type' => 'string', 'description' => __( 'Heading text.', 'full-elementor-mcp' ) ),
				'header_size'                 => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), 'description' => __( 'HTML tag. Default: h2.', 'full-elementor-mcp' ) ),
				'size'                        => array( 'type' => 'string', 'enum' => array( 'default', 'small', 'medium', 'large', 'xl', 'xxl' ), 'description' => __( 'Elementor size preset.', 'full-elementor-mcp' ) ),
				'align'                       => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ), 'description' => __( 'Text alignment. Responsive: align_tablet, align_mobile.', 'full-elementor-mcp' ) ),
				'title_color'                 => array( 'type' => 'string', 'description' => __( 'Heading color (hex/rgba).', 'full-elementor-mcp' ) ),
				'title_hover_color'           => array( 'type' => 'string', 'description' => __( 'Heading hover color (hex/rgba).', 'full-elementor-mcp' ) ),
				'link'                        => array( 'type' => 'object', 'description' => __( 'Link: {url, is_external, nofollow}.', 'full-elementor-mcp' ) ),
				'blend_mode'                  => array( 'type' => 'string', 'enum' => array( '', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'saturation', 'color', 'difference', 'exclusion', 'hue', 'luminosity' ), 'description' => __( 'CSS blend mode.', 'full-elementor-mcp' ) ),
				// Typography group — set typography_typography=custom to activate.
				'typography_typography'        => array( 'type' => 'string', 'description' => __( 'Set to "custom" to enable typography controls.', 'full-elementor-mcp' ) ),
				'typography_font_family'       => array( 'type' => 'string', 'description' => __( 'Font family (e.g. "Roboto", "Montserrat").', 'full-elementor-mcp' ) ),
				'typography_font_size'         => array( 'type' => 'object', 'description' => __( 'Font size: {size, unit}. Units: px, em, rem, vw.', 'full-elementor-mcp' ) ),
				'typography_font_weight'       => array( 'type' => 'string', 'enum' => array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ), 'description' => __( 'Font weight.', 'full-elementor-mcp' ) ),
				'typography_text_transform'    => array( 'type' => 'string', 'enum' => array( '', 'uppercase', 'lowercase', 'capitalize', 'none' ), 'description' => __( 'Text transform.', 'full-elementor-mcp' ) ),
				'typography_font_style'        => array( 'type' => 'string', 'enum' => array( '', 'normal', 'italic', 'oblique' ), 'description' => __( 'Font style.', 'full-elementor-mcp' ) ),
				'typography_text_decoration'   => array( 'type' => 'string', 'enum' => array( '', 'none', 'underline', 'overline', 'line-through' ), 'description' => __( 'Text decoration.', 'full-elementor-mcp' ) ),
				'typography_line_height'       => array( 'type' => 'object', 'description' => __( 'Line height: {size, unit}. Units: px, em.', 'full-elementor-mcp' ) ),
				'typography_letter_spacing'    => array( 'type' => 'object', 'description' => __( 'Letter spacing: {size, unit}. Units: px, em.', 'full-elementor-mcp' ) ),
				'typography_word_spacing'      => array( 'type' => 'object', 'description' => __( 'Word spacing: {size, unit}.', 'full-elementor-mcp' ) ),
				// Text stroke — set text_stroke_text_stroke=yes to activate.
				'text_stroke_text_stroke'      => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Enable text stroke.', 'full-elementor-mcp' ) ),
				'text_stroke_stroke_width'     => array( 'type' => 'object', 'description' => __( 'Stroke width: {size, unit}.', 'full-elementor-mcp' ) ),
				'text_stroke_stroke_color'     => array( 'type' => 'string', 'description' => __( 'Stroke color (hex/rgba).', 'full-elementor-mcp' ) ),
				// Text shadow.
				'title_text_shadow_text_shadow' => array( 'type' => 'object', 'description' => __( 'Text shadow: {horizontal, vertical, blur, color}.', 'full-elementor-mcp' ) ),
			),
			array( 'title' ),
			'heading',
			array( 'header_size' => 'h2' )
		);
	}

	private function register_add_text_editor(): void {
		$this->register_convenience_tool(
			'add-text-editor',
			__( 'Add Text Editor', 'full-elementor-mcp' ),
			__( 'Adds a rich text editor widget. Supports typography (set typography_typography=custom), drop cap, text columns, and text color. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'editor'     => array( 'type' => 'string', 'description' => __( 'HTML content.', 'full-elementor-mcp' ) ),
				'align'      => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ), 'description' => __( 'Text alignment. Responsive: align_tablet, align_mobile.', 'full-elementor-mcp' ) ),
				'text_color' => array( 'type' => 'string', 'description' => __( 'Text color (hex/rgba).', 'full-elementor-mcp' ) ),
				// Drop cap.
				'drop_cap'   => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Enable drop cap on first letter.', 'full-elementor-mcp' ) ),
				// Text columns.
				'column_gap' => array( 'type' => 'object', 'description' => __( 'Column gap: {size, unit}. Works with text_columns.', 'full-elementor-mcp' ) ),
				'text_columns' => array( 'type' => 'string', 'description' => __( 'Number of text columns (1-10).', 'full-elementor-mcp' ) ),
				// Typography group.
				'typography_typography'     => array( 'type' => 'string', 'description' => __( 'Set to "custom" to enable typography controls.', 'full-elementor-mcp' ) ),
				'typography_font_family'    => array( 'type' => 'string', 'description' => __( 'Font family.', 'full-elementor-mcp' ) ),
				'typography_font_size'      => array( 'type' => 'object', 'description' => __( 'Font size: {size, unit}.', 'full-elementor-mcp' ) ),
				'typography_font_weight'    => array( 'type' => 'string', 'enum' => array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ), 'description' => __( 'Font weight.', 'full-elementor-mcp' ) ),
				'typography_text_transform' => array( 'type' => 'string', 'enum' => array( '', 'uppercase', 'lowercase', 'capitalize', 'none' ), 'description' => __( 'Text transform.', 'full-elementor-mcp' ) ),
				'typography_line_height'    => array( 'type' => 'object', 'description' => __( 'Line height: {size, unit}.', 'full-elementor-mcp' ) ),
				'typography_letter_spacing' => array( 'type' => 'object', 'description' => __( 'Letter spacing: {size, unit}.', 'full-elementor-mcp' ) ),
			),
			array( 'editor' ),
			'text-editor'
		);
	}

	private function register_add_image(): void {
		$this->register_convenience_tool(
			'add-image',
			__( 'Add Image', 'full-elementor-mcp' ),
			__( 'Adds an image widget. Supports width, max-width, opacity, border, border-radius, box shadow, CSS filters (brightness, contrast, saturation, hue), and hover effects. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'image'          => array( 'type' => 'object', 'description' => __( 'Image object with url (required) and optional id.', 'full-elementor-mcp' ) ),
				'image_size'     => array( 'type' => 'string', 'enum' => array( 'thumbnail', 'medium', 'medium_large', 'large', 'full' ), 'description' => __( 'Image size preset.', 'full-elementor-mcp' ) ),
				'align'          => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Image alignment. Responsive: align_tablet, align_mobile.', 'full-elementor-mcp' ) ),
				'caption_source' => array( 'type' => 'string', 'enum' => array( 'none', 'attachment', 'custom' ), 'description' => __( 'Caption source.', 'full-elementor-mcp' ) ),
				'caption'        => array( 'type' => 'string', 'description' => __( 'Custom caption text.', 'full-elementor-mcp' ) ),
				'link_to'        => array( 'type' => 'string', 'enum' => array( 'none', 'file', 'custom' ), 'description' => __( 'Link behavior.', 'full-elementor-mcp' ) ),
				'link'           => array( 'type' => 'object', 'description' => __( 'Link: {url, is_external, nofollow}.', 'full-elementor-mcp' ) ),
				// Sizing.
				'width'          => array( 'type' => 'object', 'description' => __( 'Image width: {size, unit}. Units: px, %, vw.', 'full-elementor-mcp' ) ),
				'max_width'      => array( 'type' => 'object', 'description' => __( 'Max width: {size, unit}.', 'full-elementor-mcp' ) ),
				'height'         => array( 'type' => 'object', 'description' => __( 'Image height: {size, unit}.', 'full-elementor-mcp' ) ),
				'object_fit'     => array( 'type' => 'string', 'enum' => array( '', 'fill', 'cover', 'contain' ), 'description' => __( 'Object fit when height is set.', 'full-elementor-mcp' ) ),
				// Style.
				'opacity'        => array( 'type' => 'object', 'description' => __( 'Image opacity: {size, unit}. 0-1 range.', 'full-elementor-mcp' ) ),
				'hover_animation' => array( 'type' => 'string', 'description' => __( 'Hover animation (grow, shrink, pulse, push, etc).', 'full-elementor-mcp' ) ),
				'hover_opacity'  => array( 'type' => 'object', 'description' => __( 'Hover opacity: {size, unit}. 0-1 range.', 'full-elementor-mcp' ) ),
				// CSS Filters.
				'css_filters_css_filter' => array( 'type' => 'string', 'enum' => array( 'custom', '' ), 'description' => __( 'Set to "custom" to enable CSS filter controls.', 'full-elementor-mcp' ) ),
				'css_filters_blur'       => array( 'type' => 'object', 'description' => __( 'Blur: {size, unit}. px.', 'full-elementor-mcp' ) ),
				'css_filters_brightness' => array( 'type' => 'object', 'description' => __( 'Brightness: {size, unit}. 0-200%.', 'full-elementor-mcp' ) ),
				'css_filters_contrast'   => array( 'type' => 'object', 'description' => __( 'Contrast: {size, unit}. 0-200%.', 'full-elementor-mcp' ) ),
				'css_filters_saturate'   => array( 'type' => 'object', 'description' => __( 'Saturation: {size, unit}. 0-200%.', 'full-elementor-mcp' ) ),
				'css_filters_hue'        => array( 'type' => 'object', 'description' => __( 'Hue rotation: {size, unit}. 0-360deg.', 'full-elementor-mcp' ) ),
				// Border.
				'image_border_border'    => array( 'type' => 'string', 'enum' => array( '', 'solid', 'double', 'dotted', 'dashed', 'groove' ), 'description' => __( 'Border style.', 'full-elementor-mcp' ) ),
				'image_border_width'     => array( 'type' => 'object', 'description' => __( 'Border width: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				'image_border_color'     => array( 'type' => 'string', 'description' => __( 'Border color.', 'full-elementor-mcp' ) ),
				'image_border_radius'    => array( 'type' => 'object', 'description' => __( 'Border radius: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				// Box shadow.
				'image_box_shadow_box_shadow_type' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Enable box shadow.', 'full-elementor-mcp' ) ),
				'image_box_shadow_box_shadow'      => array( 'type' => 'object', 'description' => __( 'Box shadow: {horizontal, vertical, blur, spread, color}.', 'full-elementor-mcp' ) ),
			),
			array( 'image' ),
			'image'
		);
	}

	private function register_add_button(): void {
		$this->register_convenience_tool(
			'add-button',
			__( 'Add Button', 'full-elementor-mcp' ),
			__( 'Adds a button widget. Supports typography (set typography_typography=custom), border, background, hover colors, box shadow, and text shadow. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'text'          => array( 'type' => 'string', 'description' => __( 'Button text.', 'full-elementor-mcp' ) ),
				'link'          => array( 'type' => 'object', 'description' => __( 'Link: {url, is_external, nofollow}.', 'full-elementor-mcp' ) ),
				'size'          => array( 'type' => 'string', 'enum' => array( 'xs', 'sm', 'md', 'lg', 'xl' ), 'description' => __( 'Button size.', 'full-elementor-mcp' ) ),
				'button_type'   => array( 'type' => 'string', 'enum' => array( '', 'info', 'success', 'warning', 'danger' ), 'description' => __( 'Button style type.', 'full-elementor-mcp' ) ),
				'align'         => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ), 'description' => __( 'Button alignment. Responsive: align_tablet, align_mobile.', 'full-elementor-mcp' ) ),
				'selected_icon' => array( 'type' => 'object', 'description' => __( 'Icon object with value and library.', 'full-elementor-mcp' ) ),
				'icon_align'    => array( 'type' => 'string', 'enum' => array( 'row', 'row-reverse' ), 'description' => __( 'Icon position.', 'full-elementor-mcp' ) ),
				'icon_indent'   => array( 'type' => 'object', 'description' => __( 'Icon spacing: {size, unit}.', 'full-elementor-mcp' ) ),
				// Colors.
				'button_text_color'       => array( 'type' => 'string', 'description' => __( 'Text color (hex/rgba).', 'full-elementor-mcp' ) ),
				'background_color'        => array( 'type' => 'string', 'description' => __( 'Background color (hex/rgba).', 'full-elementor-mcp' ) ),
				// Hover colors.
				'hover_color'             => array( 'type' => 'string', 'description' => __( 'Hover text color.', 'full-elementor-mcp' ) ),
				'button_background_hover_color' => array( 'type' => 'string', 'description' => __( 'Hover background color.', 'full-elementor-mcp' ) ),
				'hover_animation'         => array( 'type' => 'string', 'description' => __( 'Hover animation (e.g. grow, shrink, pulse, push).', 'full-elementor-mcp' ) ),
				// Border.
				'border_border'           => array( 'type' => 'string', 'enum' => array( '', 'solid', 'double', 'dotted', 'dashed', 'groove' ), 'description' => __( 'Border style.', 'full-elementor-mcp' ) ),
				'border_width'            => array( 'type' => 'object', 'description' => __( 'Border width: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				'border_color'            => array( 'type' => 'string', 'description' => __( 'Border color.', 'full-elementor-mcp' ) ),
				'border_radius'           => array( 'type' => 'object', 'description' => __( 'Border radius: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				// Box shadow.
				'button_box_shadow_box_shadow_type' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Enable box shadow.', 'full-elementor-mcp' ) ),
				'button_box_shadow_box_shadow'      => array( 'type' => 'object', 'description' => __( 'Box shadow: {horizontal, vertical, blur, spread, color}.', 'full-elementor-mcp' ) ),
				// Typography group.
				'typography_typography'    => array( 'type' => 'string', 'description' => __( 'Set to "custom" to enable typography controls.', 'full-elementor-mcp' ) ),
				'typography_font_family'   => array( 'type' => 'string', 'description' => __( 'Font family.', 'full-elementor-mcp' ) ),
				'typography_font_size'     => array( 'type' => 'object', 'description' => __( 'Font size: {size, unit}.', 'full-elementor-mcp' ) ),
				'typography_font_weight'   => array( 'type' => 'string', 'enum' => array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ), 'description' => __( 'Font weight.', 'full-elementor-mcp' ) ),
				'typography_text_transform' => array( 'type' => 'string', 'enum' => array( '', 'uppercase', 'lowercase', 'capitalize', 'none' ), 'description' => __( 'Text transform.', 'full-elementor-mcp' ) ),
				'typography_letter_spacing' => array( 'type' => 'object', 'description' => __( 'Letter spacing: {size, unit}.', 'full-elementor-mcp' ) ),
				// Text shadow.
				'text_shadow_text_shadow'  => array( 'type' => 'object', 'description' => __( 'Text shadow: {horizontal, vertical, blur, color}.', 'full-elementor-mcp' ) ),
				// Padding.
				'button_padding'          => array( 'type' => 'object', 'description' => __( 'Button padding: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
			),
			array( 'text' ),
			'button',
			array( 'text' => 'Click here', 'size' => 'sm' )
		);
	}

	private function register_add_video(): void {
		$this->register_convenience_tool(
			'add-video',
			__( 'Add Video', 'full-elementor-mcp' ),
			__( 'Adds a video widget. Supports YouTube, Vimeo, Dailymotion, self-hosted. Options: start/end time, lazy load, privacy mode, image overlay, play icon. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'video_type'     => array( 'type' => 'string', 'enum' => array( 'youtube', 'vimeo', 'dailymotion', 'hosted' ), 'description' => __( 'Video source type.', 'full-elementor-mcp' ) ),
				'youtube_url'    => array( 'type' => 'string', 'description' => __( 'YouTube URL.', 'full-elementor-mcp' ) ),
				'vimeo_url'      => array( 'type' => 'string', 'description' => __( 'Vimeo URL.', 'full-elementor-mcp' ) ),
				'dailymotion_url' => array( 'type' => 'string', 'description' => __( 'Dailymotion URL.', 'full-elementor-mcp' ) ),
				'insert_url'     => array( 'type' => 'object', 'description' => __( 'Self-hosted video URL object: {url}.', 'full-elementor-mcp' ) ),
				'autoplay'       => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Autoplay on load.', 'full-elementor-mcp' ) ),
				'mute'           => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Mute audio.', 'full-elementor-mcp' ) ),
				'loop'           => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Loop video.', 'full-elementor-mcp' ) ),
				'controls'       => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show player controls.', 'full-elementor-mcp' ) ),
				'start'          => array( 'type' => 'integer', 'description' => __( 'Start time in seconds.', 'full-elementor-mcp' ) ),
				'end'            => array( 'type' => 'integer', 'description' => __( 'End time in seconds.', 'full-elementor-mcp' ) ),
				'yt_privacy'     => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'YouTube privacy-enhanced mode.', 'full-elementor-mcp' ) ),
				'lazy_load'      => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Lazy load the video.', 'full-elementor-mcp' ) ),
				'rel'            => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show related videos at end (YouTube).', 'full-elementor-mcp' ) ),
				'modestbranding' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Modest branding (YouTube).', 'full-elementor-mcp' ) ),
				// Image overlay.
				'show_image_overlay' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show image overlay (poster).', 'full-elementor-mcp' ) ),
				'image_overlay'      => array( 'type' => 'object', 'description' => __( 'Overlay image: {url, id}.', 'full-elementor-mcp' ) ),
				'show_play_icon'     => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show play icon on overlay.', 'full-elementor-mcp' ) ),
				// Aspect ratio.
				'aspect_ratio'       => array( 'type' => 'string', 'enum' => array( '169', '219', '43', '32', '11', '916' ), 'description' => __( 'Video aspect ratio. Values: 169=16:9, 219=21:9, 43=4:3, 32=3:2, 11=1:1, 916=9:16.', 'full-elementor-mcp' ) ),
			),
			array(),
			'video',
			array( 'video_type' => 'youtube' )
		);
	}

	private function register_add_icon(): void {
		$this->register_convenience_tool(
			'add-icon',
			__( 'Add Icon', 'full-elementor-mcp' ),
			__( 'Adds an icon widget. Supports Font Awesome and SVG icons, view modes (default/stacked/framed), hover colors, rotate, padding, border radius, and hover animation. For SVG, first use upload-svg-icon.', 'full-elementor-mcp' ),
			array(
				'selected_icon'    => array( 'type' => 'object', 'description' => __( 'Icon object. Font Awesome: { "value": "fas fa-star", "library": "fa-solid" }. SVG: { "value": { "id": 123, "url": "..." }, "library": "svg" }. Libraries: fa-solid, fa-regular, fa-brands.', 'full-elementor-mcp' ) ),
				'view'             => array( 'type' => 'string', 'enum' => array( 'default', 'stacked', 'framed' ), 'description' => __( 'Icon view mode.', 'full-elementor-mcp' ) ),
				'shape'            => array( 'type' => 'string', 'enum' => array( 'circle', 'square' ), 'description' => __( 'Icon shape (for stacked/framed).', 'full-elementor-mcp' ) ),
				'primary_color'    => array( 'type' => 'string', 'description' => __( 'Primary/icon color (hex/rgba).', 'full-elementor-mcp' ) ),
				'secondary_color'  => array( 'type' => 'string', 'description' => __( 'Secondary/background color for stacked/framed (hex/rgba).', 'full-elementor-mcp' ) ),
				'hover_primary_color'   => array( 'type' => 'string', 'description' => __( 'Hover icon color.', 'full-elementor-mcp' ) ),
				'hover_secondary_color' => array( 'type' => 'string', 'description' => __( 'Hover background color for stacked/framed.', 'full-elementor-mcp' ) ),
				'hover_animation'  => array( 'type' => 'string', 'description' => __( 'Hover animation (grow, shrink, pulse, push, etc).', 'full-elementor-mcp' ) ),
				'size'             => array( 'type' => 'object', 'description' => __( 'Icon size: {size, unit}.', 'full-elementor-mcp' ) ),
				'icon_padding'     => array( 'type' => 'object', 'description' => __( 'Icon padding: {size, unit}. For stacked/framed.', 'full-elementor-mcp' ) ),
				'rotate'           => array( 'type' => 'object', 'description' => __( 'Icon rotation: {size, unit}. Degrees.', 'full-elementor-mcp' ) ),
				'border_width'     => array( 'type' => 'object', 'description' => __( 'Border width for framed view: {size, unit}.', 'full-elementor-mcp' ) ),
				'border_radius'    => array( 'type' => 'object', 'description' => __( 'Border radius: {size, unit}.', 'full-elementor-mcp' ) ),
				'link'             => array( 'type' => 'object', 'description' => __( 'Link: {url, is_external, nofollow}.', 'full-elementor-mcp' ) ),
				'align'            => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Icon alignment. Responsive: align_tablet, align_mobile.', 'full-elementor-mcp' ) ),
			),
			array(),
			'icon',
			array( 'selected_icon' => array( 'value' => 'fas fa-star', 'library' => 'fa-solid' ) )
		);
	}

	private function register_add_spacer(): void {
		$this->register_convenience_tool(
			'add-spacer',
			__( 'Add Spacer', 'full-elementor-mcp' ),
			__( 'Adds a spacer widget for vertical spacing between elements.', 'full-elementor-mcp' ),
			array(
				'space' => array( 'type' => 'object', 'description' => __( 'Spacer height: { "size": 50, "unit": "px" }.', 'full-elementor-mcp' ) ),
			),
			array(),
			'spacer',
			array( 'space' => array( 'size' => 50, 'unit' => 'px' ) )
		);
	}

	private function register_add_divider(): void {
		$this->register_convenience_tool(
			'add-divider',
			__( 'Add Divider', 'full-elementor-mcp' ),
			__( 'Adds a horizontal divider/separator widget with style, weight, color, and width options.', 'full-elementor-mcp' ),
			array(
				'style'  => array( 'type' => 'string', 'enum' => array( 'solid', 'dashed', 'dotted', 'double' ), 'description' => __( 'Divider line style.', 'full-elementor-mcp' ) ),
				'weight' => array( 'type' => 'object', 'description' => __( 'Line weight: { "size": 1, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'color'  => array( 'type' => 'string', 'description' => __( 'Divider color (hex).', 'full-elementor-mcp' ) ),
				'width'  => array( 'type' => 'object', 'description' => __( 'Divider width: { "size": 100, "unit": "%" }.', 'full-elementor-mcp' ) ),
				'gap'    => array( 'type' => 'object', 'description' => __( 'Gap above/below: { "size": 15, "unit": "px" }.', 'full-elementor-mcp' ) ),
			),
			array(),
			'divider',
			array( 'style' => 'solid' )
		);
	}

	private function register_add_icon_box(): void {
		$this->register_convenience_tool(
			'add-icon-box',
			__( 'Add Icon Box', 'full-elementor-mcp' ),
			__( 'Adds an icon box widget. Supports icon position (top/left/right), title typography (set title_typography_typography=custom), description typography, icon spacing, hover colors, and hover animation. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'selected_icon'    => array( 'type' => 'object', 'description' => __( 'Icon object. Font Awesome: { "value": "fas fa-star", "library": "fa-solid" }. SVG: { "value": { "id": 123, "url": "..." }, "library": "svg" }.', 'full-elementor-mcp' ) ),
				'title_text'       => array( 'type' => 'string', 'description' => __( 'Box title.', 'full-elementor-mcp' ) ),
				'description_text' => array( 'type' => 'string', 'description' => __( 'Box description.', 'full-elementor-mcp' ) ),
				'view'             => array( 'type' => 'string', 'enum' => array( 'default', 'stacked', 'framed' ), 'description' => __( 'Icon view mode.', 'full-elementor-mcp' ) ),
				'shape'            => array( 'type' => 'string', 'enum' => array( 'circle', 'square' ), 'description' => __( 'Icon shape.', 'full-elementor-mcp' ) ),
				'position'         => array( 'type' => 'string', 'enum' => array( 'top', 'left', 'right' ), 'description' => __( 'Icon position relative to content.', 'full-elementor-mcp' ) ),
				'title_size'       => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), 'description' => __( 'Title HTML tag. Default: h3.', 'full-elementor-mcp' ) ),
				'link'             => array( 'type' => 'object', 'description' => __( 'Link: {url, is_external, nofollow}.', 'full-elementor-mcp' ) ),
				// Colors.
				'title_color'      => array( 'type' => 'string', 'description' => __( 'Title color (hex/rgba).', 'full-elementor-mcp' ) ),
				'description_color' => array( 'type' => 'string', 'description' => __( 'Description color (hex/rgba).', 'full-elementor-mcp' ) ),
				'primary_color'    => array( 'type' => 'string', 'description' => __( 'Icon primary color.', 'full-elementor-mcp' ) ),
				'secondary_color'  => array( 'type' => 'string', 'description' => __( 'Icon secondary/background color.', 'full-elementor-mcp' ) ),
				// Hover.
				'hover_primary_color'   => array( 'type' => 'string', 'description' => __( 'Hover icon color.', 'full-elementor-mcp' ) ),
				'hover_secondary_color' => array( 'type' => 'string', 'description' => __( 'Hover icon background color.', 'full-elementor-mcp' ) ),
				'hover_animation'       => array( 'type' => 'string', 'description' => __( 'Hover animation.', 'full-elementor-mcp' ) ),
				// Spacing.
				'icon_space'       => array( 'type' => 'object', 'description' => __( 'Space between icon and content: {size, unit}.', 'full-elementor-mcp' ) ),
				'icon_size'        => array( 'type' => 'object', 'description' => __( 'Icon size: {size, unit}.', 'full-elementor-mcp' ) ),
				'title_bottom_space' => array( 'type' => 'object', 'description' => __( 'Space below title: {size, unit}.', 'full-elementor-mcp' ) ),
				// Title typography.
				'title_typography_typography'     => array( 'type' => 'string', 'description' => __( 'Set to "custom" to enable title typography.', 'full-elementor-mcp' ) ),
				'title_typography_font_family'    => array( 'type' => 'string', 'description' => __( 'Title font family.', 'full-elementor-mcp' ) ),
				'title_typography_font_size'      => array( 'type' => 'object', 'description' => __( 'Title font size: {size, unit}.', 'full-elementor-mcp' ) ),
				'title_typography_font_weight'    => array( 'type' => 'string', 'enum' => array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ), 'description' => __( 'Title font weight.', 'full-elementor-mcp' ) ),
				// Description typography.
				'description_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" to enable description typography.', 'full-elementor-mcp' ) ),
				'description_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Description font family.', 'full-elementor-mcp' ) ),
				'description_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Description font size: {size, unit}.', 'full-elementor-mcp' ) ),
			),
			array( 'title_text' ),
			'icon-box',
			array(
				'selected_icon' => array( 'value' => 'fas fa-star', 'library' => 'fa-solid' ),
			)
		);
	}

	// =========================================================================
	// Extended core convenience tools
	// =========================================================================

	private function register_add_accordion(): void {
		$this->register_convenience_tool(
			'add-accordion',
			__( 'Add Accordion', 'full-elementor-mcp' ),
			__( 'Adds an accordion widget. Supports title/content colors, background, border, typography (set title_typography_typography=custom), spacing, icon color, and FAQ schema. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'tabs'                 => array(
					'type'        => 'array',
					'description' => __( 'Array of accordion items with tab_title and tab_content.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'tab_title'   => array( 'type' => 'string' ),
							'tab_content' => array( 'type' => 'string' ),
						),
					),
				),
				'selected_icon'        => array( 'type' => 'object', 'description' => __( 'Icon when collapsed. Default: fas fa-plus.', 'full-elementor-mcp' ) ),
				'selected_active_icon' => array( 'type' => 'object', 'description' => __( 'Icon when expanded. Default: fas fa-minus.', 'full-elementor-mcp' ) ),
				'title_html_tag'       => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div' ), 'description' => __( 'Title HTML tag. Default: div.', 'full-elementor-mcp' ) ),
				'faq_schema'           => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Enable FAQ schema markup.', 'full-elementor-mcp' ) ),
				// Style - Title.
				'title_color'          => array( 'type' => 'string', 'description' => __( 'Title text color.', 'full-elementor-mcp' ) ),
				'title_background'     => array( 'type' => 'string', 'description' => __( 'Title background color.', 'full-elementor-mcp' ) ),
				'tab_active_color'     => array( 'type' => 'string', 'description' => __( 'Active title text color.', 'full-elementor-mcp' ) ),
				'tab_active_background' => array( 'type' => 'string', 'description' => __( 'Active title background color.', 'full-elementor-mcp' ) ),
				'title_padding'        => array( 'type' => 'object', 'description' => __( 'Title padding: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				// Style - Icon.
				'icon_color'           => array( 'type' => 'string', 'description' => __( 'Icon color.', 'full-elementor-mcp' ) ),
				'icon_active_color'    => array( 'type' => 'string', 'description' => __( 'Active icon color.', 'full-elementor-mcp' ) ),
				'icon_space'           => array( 'type' => 'object', 'description' => __( 'Space between icon and title: {size, unit}.', 'full-elementor-mcp' ) ),
				// Style - Content.
				'content_color'        => array( 'type' => 'string', 'description' => __( 'Content text color.', 'full-elementor-mcp' ) ),
				'content_background_color' => array( 'type' => 'string', 'description' => __( 'Content background color.', 'full-elementor-mcp' ) ),
				'content_padding'      => array( 'type' => 'object', 'description' => __( 'Content padding: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				// Border.
				'border_width'         => array( 'type' => 'object', 'description' => __( 'Item border width: {size, unit}.', 'full-elementor-mcp' ) ),
				'border_color'         => array( 'type' => 'string', 'description' => __( 'Item border color.', 'full-elementor-mcp' ) ),
				// Title typography.
				'title_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" to enable title typography.', 'full-elementor-mcp' ) ),
				'title_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Title font family.', 'full-elementor-mcp' ) ),
				'title_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Title font size: {size, unit}.', 'full-elementor-mcp' ) ),
				'title_typography_font_weight' => array( 'type' => 'string', 'enum' => array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ), 'description' => __( 'Title font weight.', 'full-elementor-mcp' ) ),
				// Content typography.
				'content_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" to enable content typography.', 'full-elementor-mcp' ) ),
				'content_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Content font family.', 'full-elementor-mcp' ) ),
				'content_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Content font size: {size, unit}.', 'full-elementor-mcp' ) ),
			),
			array( 'tabs' ),
			'accordion',
			array( 'title_html_tag' => 'div' )
		);
	}

	private function register_add_alert(): void {
		$this->register_convenience_tool(
			'add-alert',
			__( 'Add Alert', 'full-elementor-mcp' ),
			__( 'Adds an alert/notice widget with type, title, and description.', 'full-elementor-mcp' ),
			array(
				'alert_type'        => array( 'type' => 'string', 'enum' => array( 'info', 'success', 'warning', 'danger' ), 'description' => __( 'Alert type. Default: info.', 'full-elementor-mcp' ) ),
				'alert_title'       => array( 'type' => 'string', 'description' => __( 'Alert title.', 'full-elementor-mcp' ) ),
				'alert_description' => array( 'type' => 'string', 'description' => __( 'Alert description/content.', 'full-elementor-mcp' ) ),
				'show_dismiss'      => array( 'type' => 'string', 'enum' => array( 'show', '' ), 'description' => __( 'Show dismiss button. Default: show.', 'full-elementor-mcp' ) ),
			),
			array( 'alert_title' ),
			'alert',
			array( 'alert_type' => 'info', 'show_dismiss' => 'show' )
		);
	}

	private function register_add_counter(): void {
		$this->register_convenience_tool(
			'add-counter',
			__( 'Add Counter', 'full-elementor-mcp' ),
			__( 'Adds an animated counter widget that counts up to a number.', 'full-elementor-mcp' ),
			array(
				'starting_number'    => array( 'type' => 'integer', 'description' => __( 'Start value. Default: 0.', 'full-elementor-mcp' ) ),
				'ending_number'      => array( 'type' => 'integer', 'description' => __( 'End value. Default: 100.', 'full-elementor-mcp' ) ),
				'prefix'             => array( 'type' => 'string', 'description' => __( 'Text before number (e.g. "$").', 'full-elementor-mcp' ) ),
				'suffix'             => array( 'type' => 'string', 'description' => __( 'Text after number (e.g. "%", "+").', 'full-elementor-mcp' ) ),
				'duration'           => array( 'type' => 'integer', 'description' => __( 'Animation duration in ms. Default: 2000.', 'full-elementor-mcp' ) ),
				'thousand_separator' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show thousand separators.', 'full-elementor-mcp' ) ),
				'title'              => array( 'type' => 'string', 'description' => __( 'Counter label/title.', 'full-elementor-mcp' ) ),
			),
			array( 'ending_number' ),
			'counter',
			array( 'starting_number' => 0, 'ending_number' => 100, 'duration' => 2000 )
		);
	}

	private function register_add_google_maps(): void {
		$this->register_convenience_tool(
			'add-google-maps',
			__( 'Add Google Maps', 'full-elementor-mcp' ),
			__( 'Adds an embedded Google Maps widget with address, zoom, and height.', 'full-elementor-mcp' ),
			array(
				'address' => array( 'type' => 'string', 'description' => __( 'Location address or search query.', 'full-elementor-mcp' ) ),
				'zoom'    => array( 'type' => 'object', 'description' => __( 'Zoom level: { "size": 10, "unit": "px" }. Range 1-20.', 'full-elementor-mcp' ) ),
				'height'  => array( 'type' => 'object', 'description' => __( 'Map height: { "size": 300, "unit": "px" }.', 'full-elementor-mcp' ) ),
			),
			array( 'address' ),
			'google_maps',
			array( 'zoom' => array( 'size' => 10, 'unit' => 'px' ) )
		);
	}

	private function register_add_icon_list(): void {
		$this->register_convenience_tool(
			'add-icon-list',
			__( 'Add Icon List', 'full-elementor-mcp' ),
			__( 'Adds a list widget with icons and text. Great for feature lists, checklists, and contact info.', 'full-elementor-mcp' ),
			array(
				'icon_list' => array(
					'type'        => 'array',
					'description' => __( 'Array of list items with text, selected_icon, and optional link.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'text'          => array( 'type' => 'string' ),
							'selected_icon' => array( 'type' => 'object' ),
							'link'          => array( 'type' => 'object' ),
						),
					),
				),
				'view'      => array( 'type' => 'string', 'enum' => array( 'traditional', 'inline' ), 'description' => __( 'Layout: traditional (vertical) or inline. Default: traditional.', 'full-elementor-mcp' ) ),
			),
			array( 'icon_list' ),
			'icon-list',
			array( 'view' => 'traditional' )
		);
	}

	private function register_add_image_box(): void {
		$this->register_convenience_tool(
			'add-image-box',
			__( 'Add Image Box', 'full-elementor-mcp' ),
			__( 'Adds an image box widget with image, title, and description. Great for service cards.', 'full-elementor-mcp' ),
			array(
				'image'            => array( 'type' => 'object', 'description' => __( 'Image object with url and optional id.', 'full-elementor-mcp' ) ),
				'title_text'       => array( 'type' => 'string', 'description' => __( 'Box title.', 'full-elementor-mcp' ) ),
				'description_text' => array( 'type' => 'string', 'description' => __( 'Box description.', 'full-elementor-mcp' ) ),
				'link'             => array( 'type' => 'object', 'description' => __( 'Link object with url key.', 'full-elementor-mcp' ) ),
				'title_size'       => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), 'description' => __( 'Title HTML tag. Default: h3.', 'full-elementor-mcp' ) ),
			),
			array( 'title_text' ),
			'image-box',
			array( 'title_size' => 'h3' )
		);
	}

	private function register_add_image_carousel(): void {
		$this->register_convenience_tool(
			'add-image-carousel',
			__( 'Add Image Carousel', 'full-elementor-mcp' ),
			__( 'Adds a rotating image carousel/slider widget.', 'full-elementor-mcp' ),
			array(
				'carousel'       => array(
					'type'        => 'array',
					'description' => __( 'Array of image objects with url and optional id.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'url' => array( 'type' => 'string' ),
							'id'  => array( 'type' => 'integer' ),
						),
					),
				),
				'slides_to_show' => array( 'type' => 'string', 'enum' => array( '1', '2', '3', '4', '5', '6', '7', '8', '9', '10' ), 'description' => __( 'Number of slides visible.', 'full-elementor-mcp' ) ),
				'navigation'     => array( 'type' => 'string', 'enum' => array( 'both', 'arrows', 'dots', 'none' ), 'description' => __( 'Navigation type. Default: both.', 'full-elementor-mcp' ) ),
				'autoplay'       => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Autoplay slides. Default: yes.', 'full-elementor-mcp' ) ),
				'autoplay_speed' => array( 'type' => 'integer', 'description' => __( 'Autoplay interval in ms. Default: 5000.', 'full-elementor-mcp' ) ),
				'infinite'       => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Infinite loop. Default: yes.', 'full-elementor-mcp' ) ),
			),
			array( 'carousel' ),
			'image-carousel',
			array( 'navigation' => 'both', 'autoplay' => 'yes', 'infinite' => 'yes', 'autoplay_speed' => 5000 )
		);
	}

	private function register_add_progress(): void {
		$this->register_convenience_tool(
			'add-progress',
			__( 'Add Progress Bar', 'full-elementor-mcp' ),
			__( 'Adds an animated progress bar widget with label and percentage.', 'full-elementor-mcp' ),
			array(
				'title'              => array( 'type' => 'string', 'description' => __( 'Progress bar label.', 'full-elementor-mcp' ) ),
				'progress_type'      => array( 'type' => 'string', 'enum' => array( '', 'info', 'success', 'warning', 'danger' ), 'description' => __( 'Color preset type.', 'full-elementor-mcp' ) ),
				'percent'            => array( 'type' => 'object', 'description' => __( 'Progress percentage: { "size": 50, "unit": "%" }.', 'full-elementor-mcp' ) ),
				'display_percentage' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show percentage value. Default: yes.', 'full-elementor-mcp' ) ),
				'inner_text'         => array( 'type' => 'string', 'description' => __( 'Text inside the progress bar.', 'full-elementor-mcp' ) ),
			),
			array(),
			'progress',
			array( 'percent' => array( 'size' => 50, 'unit' => '%' ), 'display_percentage' => 'yes' )
		);
	}

	private function register_add_social_icons(): void {
		$this->register_convenience_tool(
			'add-social-icons',
			__( 'Add Social Icons', 'full-elementor-mcp' ),
			__( 'Adds social media icon links. Great for headers and footers.', 'full-elementor-mcp' ),
			array(
				'social_icon_list' => array(
					'type'        => 'array',
					'description' => __( 'Array of social items with social_icon and link.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'social_icon' => array( 'type' => 'object', 'description' => __( 'Icon: { "value": "fab fa-facebook", "library": "fa-brands" }.', 'full-elementor-mcp' ) ),
							'link'        => array( 'type' => 'object', 'description' => __( 'URL object with url key.', 'full-elementor-mcp' ) ),
						),
					),
				),
				'shape'            => array( 'type' => 'string', 'enum' => array( 'rounded', 'square', 'circle' ), 'description' => __( 'Icon shape. Default: rounded.', 'full-elementor-mcp' ) ),
				'columns'          => array( 'type' => 'integer', 'description' => __( 'Grid columns. 0 = auto.', 'full-elementor-mcp' ) ),
				'align'            => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Alignment. Default: center.', 'full-elementor-mcp' ) ),
			),
			array( 'social_icon_list' ),
			'social-icons',
			array( 'shape' => 'rounded' )
		);
	}

	private function register_add_star_rating(): void {
		$this->register_convenience_tool(
			'add-star-rating',
			__( 'Add Star Rating', 'full-elementor-mcp' ),
			__( 'Adds a star rating display widget.', 'full-elementor-mcp' ),
			array(
				'rating_scale' => array( 'type' => 'string', 'enum' => array( '5', '10' ), 'description' => __( 'Rating scale. Default: 5.', 'full-elementor-mcp' ) ),
				'rating'       => array( 'type' => 'object', 'description' => __( 'Rating value: { "size": 5, "unit": "px" }. Step: 0.1.', 'full-elementor-mcp' ) ),
				'star_style'   => array( 'type' => 'string', 'enum' => array( 'star_fontawesome', 'star_unicode' ), 'description' => __( 'Star icon style.', 'full-elementor-mcp' ) ),
				'title'        => array( 'type' => 'string', 'description' => __( 'Optional rating title.', 'full-elementor-mcp' ) ),
			),
			array(),
			'star-rating',
			array( 'rating_scale' => '5', 'rating' => array( 'size' => 5, 'unit' => 'px' ) )
		);
	}

	private function register_add_tabs(): void {
		$this->register_convenience_tool(
			'add-tabs',
			__( 'Add Tabs', 'full-elementor-mcp' ),
			__( 'Adds a tabbed content widget with horizontal or vertical layout.', 'full-elementor-mcp' ),
			array(
				'tabs' => array(
					'type'        => 'array',
					'description' => __( 'Array of tab items with tab_title and tab_content.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'tab_title'   => array( 'type' => 'string' ),
							'tab_content' => array( 'type' => 'string' ),
						),
					),
				),
				'type' => array( 'type' => 'string', 'enum' => array( 'horizontal', 'vertical' ), 'description' => __( 'Tab layout direction. Default: horizontal.', 'full-elementor-mcp' ) ),
			),
			array( 'tabs' ),
			'tabs',
			array( 'type' => 'horizontal' )
		);
	}

	private function register_add_testimonial(): void {
		$this->register_convenience_tool(
			'add-testimonial',
			__( 'Add Testimonial', 'full-elementor-mcp' ),
			__( 'Adds a testimonial widget with quote, author name, job title, and image.', 'full-elementor-mcp' ),
			array(
				'testimonial_content'        => array( 'type' => 'string', 'description' => __( 'Testimonial/quote text.', 'full-elementor-mcp' ) ),
				'testimonial_image'          => array( 'type' => 'object', 'description' => __( 'Author image object with url and optional id.', 'full-elementor-mcp' ) ),
				'testimonial_name'           => array( 'type' => 'string', 'description' => __( 'Author name.', 'full-elementor-mcp' ) ),
				'testimonial_job'            => array( 'type' => 'string', 'description' => __( 'Author job title/role.', 'full-elementor-mcp' ) ),
				'testimonial_image_position' => array( 'type' => 'string', 'enum' => array( 'aside', 'top' ), 'description' => __( 'Image position. Default: aside.', 'full-elementor-mcp' ) ),
			),
			array( 'testimonial_content', 'testimonial_name' ),
			'testimonial',
			array( 'testimonial_image_position' => 'aside' )
		);
	}

	private function register_add_toggle(): void {
		$this->register_convenience_tool(
			'add-toggle',
			__( 'Add Toggle', 'full-elementor-mcp' ),
			__( 'Adds a toggle widget (multiple items can be open). Supports title/content colors, background, border, typography (set title_typography_typography=custom), spacing, and icon color. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'tabs'                 => array(
					'type'        => 'array',
					'description' => __( 'Array of toggle items with tab_title and tab_content.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'tab_title'   => array( 'type' => 'string' ),
							'tab_content' => array( 'type' => 'string' ),
						),
					),
				),
				'selected_icon'        => array( 'type' => 'object', 'description' => __( 'Icon when collapsed.', 'full-elementor-mcp' ) ),
				'selected_active_icon' => array( 'type' => 'object', 'description' => __( 'Icon when expanded.', 'full-elementor-mcp' ) ),
				'title_html_tag'       => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div' ), 'description' => __( 'Title HTML tag. Default: div.', 'full-elementor-mcp' ) ),
				// Style - Title.
				'title_color'          => array( 'type' => 'string', 'description' => __( 'Title text color.', 'full-elementor-mcp' ) ),
				'title_background'     => array( 'type' => 'string', 'description' => __( 'Title background color.', 'full-elementor-mcp' ) ),
				'tab_active_color'     => array( 'type' => 'string', 'description' => __( 'Active title text color.', 'full-elementor-mcp' ) ),
				'tab_active_background' => array( 'type' => 'string', 'description' => __( 'Active title background color.', 'full-elementor-mcp' ) ),
				'title_padding'        => array( 'type' => 'object', 'description' => __( 'Title padding: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				// Style - Icon.
				'icon_color'           => array( 'type' => 'string', 'description' => __( 'Icon color.', 'full-elementor-mcp' ) ),
				'icon_active_color'    => array( 'type' => 'string', 'description' => __( 'Active icon color.', 'full-elementor-mcp' ) ),
				'icon_space'           => array( 'type' => 'object', 'description' => __( 'Space between icon and title: {size, unit}.', 'full-elementor-mcp' ) ),
				// Style - Content.
				'content_color'        => array( 'type' => 'string', 'description' => __( 'Content text color.', 'full-elementor-mcp' ) ),
				'content_background_color' => array( 'type' => 'string', 'description' => __( 'Content background color.', 'full-elementor-mcp' ) ),
				'content_padding'      => array( 'type' => 'object', 'description' => __( 'Content padding: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				// Border.
				'border_width'         => array( 'type' => 'object', 'description' => __( 'Item border width: {size, unit}.', 'full-elementor-mcp' ) ),
				'border_color'         => array( 'type' => 'string', 'description' => __( 'Item border color.', 'full-elementor-mcp' ) ),
				// Title typography.
				'title_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" to enable title typography.', 'full-elementor-mcp' ) ),
				'title_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Title font family.', 'full-elementor-mcp' ) ),
				'title_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Title font size: {size, unit}.', 'full-elementor-mcp' ) ),
				'title_typography_font_weight' => array( 'type' => 'string', 'enum' => array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ), 'description' => __( 'Title font weight.', 'full-elementor-mcp' ) ),
				// Content typography.
				'content_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" to enable content typography.', 'full-elementor-mcp' ) ),
				'content_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Content font family.', 'full-elementor-mcp' ) ),
				'content_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Content font size: {size, unit}.', 'full-elementor-mcp' ) ),
			),
			array( 'tabs' ),
			'toggle',
			array( 'title_html_tag' => 'div' )
		);
	}

	private function register_add_html(): void {
		$this->register_convenience_tool(
			'add-html',
			__( 'Add HTML', 'full-elementor-mcp' ),
			__( 'Adds a custom HTML code widget.', 'full-elementor-mcp' ),
			array(
				'html' => array( 'type' => 'string', 'description' => __( 'Custom HTML/code content.', 'full-elementor-mcp' ) ),
			),
			array( 'html' ),
			'html'
		);
	}

	// =========================================================================
	// Pro convenience tools (only when ELEMENTOR_PRO_VERSION is defined)
	// =========================================================================

	private function register_add_form(): void {
		$this->register_convenience_tool(
			'add-form',
			__( 'Add Form (Pro)', 'full-elementor-mcp' ),
			__( 'Adds an Elementor Pro form. Supports field types (text, email, textarea, url, tel, select, radio, checkbox, number, date, time, upload, acceptance, password, html, hidden, step), submit button styling, submit actions (email, redirect, webhook, mailchimp, drip, activecampaign, getresponse, convertkit, mailerlite, slack), email settings, redirect, and success/error messages. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'form_name'     => array( 'type' => 'string', 'description' => __( 'Form name.', 'full-elementor-mcp' ) ),
				'form_fields'   => array(
					'type'        => 'array',
					'description' => __( 'Array of field definitions.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'field_type'    => array( 'type' => 'string', 'enum' => array( 'text', 'email', 'textarea', 'url', 'tel', 'select', 'radio', 'checkbox', 'number', 'date', 'time', 'upload', 'acceptance', 'password', 'html', 'hidden', 'step' ) ),
							'field_label'   => array( 'type' => 'string' ),
							'placeholder'   => array( 'type' => 'string' ),
							'required'      => array( 'type' => 'string', 'enum' => array( 'yes', '' ) ),
							'width'         => array( 'type' => 'string', 'enum' => array( '100', '80', '75', '66', '50', '33', '25', '20' ) ),
							'field_options' => array( 'type' => 'string' ),
							'field_value'   => array( 'type' => 'string' ),
							'field_html'    => array( 'type' => 'string' ),
							'allow_multiple_upload' => array( 'type' => 'string', 'enum' => array( 'yes', '' ) ),
							'file_sizes'    => array( 'type' => 'integer' ),
							'file_types'    => array( 'type' => 'string' ),
							'acceptance_text' => array( 'type' => 'string' ),
							'checked_by_default' => array( 'type' => 'string', 'enum' => array( 'yes', '' ) ),
						),
					),
				),
				// Submit button.
				'button_text'   => array( 'type' => 'string', 'description' => __( 'Submit button text.', 'full-elementor-mcp' ) ),
				'button_size'   => array( 'type' => 'string', 'enum' => array( 'xs', 'sm', 'md', 'lg', 'xl' ), 'description' => __( 'Submit button size.', 'full-elementor-mcp' ) ),
				'button_width'  => array( 'type' => 'string', 'enum' => array( '', '100' ), 'description' => __( 'Full-width button. Set to "100" for full width.', 'full-elementor-mcp' ) ),
				'button_align'  => array( 'type' => 'string', 'enum' => array( 'start', 'center', 'end', 'stretch' ), 'description' => __( 'Button alignment.', 'full-elementor-mcp' ) ),
				'selected_button_icon' => array( 'type' => 'object', 'description' => __( 'Button icon: {value, library}.', 'full-elementor-mcp' ) ),
				'button_icon_align'    => array( 'type' => 'string', 'enum' => array( 'left', 'right' ), 'description' => __( 'Button icon position.', 'full-elementor-mcp' ) ),
				// Submit actions.
				'submit_actions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Actions after submit: ["email","redirect","webhook"]. Default: ["email"].', 'full-elementor-mcp' ) ),
				// Email settings.
				'email_to'      => array( 'type' => 'string', 'description' => __( 'Email recipient.', 'full-elementor-mcp' ) ),
				'email_subject' => array( 'type' => 'string', 'description' => __( 'Email subject.', 'full-elementor-mcp' ) ),
				'email_from'    => array( 'type' => 'string', 'description' => __( 'Email from address.', 'full-elementor-mcp' ) ),
				'email_from_name' => array( 'type' => 'string', 'description' => __( 'Email from name.', 'full-elementor-mcp' ) ),
				'email_reply_to'  => array( 'type' => 'string', 'description' => __( 'Reply-to email (use field shortcode like [field id="email"]).', 'full-elementor-mcp' ) ),
				'email_content_type' => array( 'type' => 'string', 'enum' => array( 'html', 'plain' ), 'description' => __( 'Email content type. Default: html.', 'full-elementor-mcp' ) ),
				// Redirect.
				'redirect_to'   => array( 'type' => 'string', 'description' => __( 'Redirect URL after submit (requires "redirect" in submit_actions).', 'full-elementor-mcp' ) ),
				// Webhook.
				'webhooks'      => array( 'type' => 'string', 'description' => __( 'Webhook URL (requires "webhook" in submit_actions).', 'full-elementor-mcp' ) ),
				// Messages.
				'success_message' => array( 'type' => 'string', 'description' => __( 'Success message after submit.', 'full-elementor-mcp' ) ),
				'error_message'   => array( 'type' => 'string', 'description' => __( 'Error message on failure.', 'full-elementor-mcp' ) ),
				'required_field_message' => array( 'type' => 'string', 'description' => __( 'Required field validation message.', 'full-elementor-mcp' ) ),
				// Style.
				'input_size'    => array( 'type' => 'string', 'enum' => array( 'xs', 'sm', 'md', 'lg', 'xl' ), 'description' => __( 'Input field size.', 'full-elementor-mcp' ) ),
				'show_labels'   => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show field labels. Default: yes.', 'full-elementor-mcp' ) ),
				'mark_required' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show asterisk on required fields. Default: yes.', 'full-elementor-mcp' ) ),
				// Button colors.
				'button_background_color'       => array( 'type' => 'string', 'description' => __( 'Button background color.', 'full-elementor-mcp' ) ),
				'button_text_color'             => array( 'type' => 'string', 'description' => __( 'Button text color.', 'full-elementor-mcp' ) ),
				'button_hover_background_color' => array( 'type' => 'string', 'description' => __( 'Button hover background color.', 'full-elementor-mcp' ) ),
				'button_hover_color'            => array( 'type' => 'string', 'description' => __( 'Button hover text color.', 'full-elementor-mcp' ) ),
				// Button typography.
				'button_typography_typography'   => array( 'type' => 'string', 'description' => __( 'Set to "custom" to enable button typography.', 'full-elementor-mcp' ) ),
				'button_typography_font_family'  => array( 'type' => 'string', 'description' => __( 'Button font family.', 'full-elementor-mcp' ) ),
				'button_typography_font_size'    => array( 'type' => 'object', 'description' => __( 'Button font size: {size, unit}.', 'full-elementor-mcp' ) ),
				'button_typography_font_weight'  => array( 'type' => 'string', 'description' => __( 'Button font weight.', 'full-elementor-mcp' ) ),
			),
			array( 'form_name' ),
			'form',
			array( 'button_text' => 'Send', 'submit_actions' => array( 'email' ) )
		);
	}

	private function register_add_posts_grid(): void {
		$this->register_convenience_tool(
			'add-posts-grid',
			__( 'Add Posts Grid (Pro)', 'full-elementor-mcp' ),
			__( 'Adds an Elementor Pro posts grid widget to display a grid of posts.', 'full-elementor-mcp' ),
			array(
				'posts_post_type' => array( 'type' => 'string', 'enum' => array( 'post', 'page', 'any' ), 'description' => __( 'Post type to query.', 'full-elementor-mcp' ) ),
				'posts_per_page'  => array( 'type' => 'integer', 'description' => __( 'Number of posts to show.', 'full-elementor-mcp' ) ),
				'columns'         => array( 'type' => 'integer', 'description' => __( 'Number of grid columns.', 'full-elementor-mcp' ) ),
				'pagination_type' => array( 'type' => 'string', 'enum' => array( '', 'numbers', 'prev_next', 'numbers_and_prev_next', 'load_more_on_click' ), 'description' => __( 'Pagination type.', 'full-elementor-mcp' ) ),
			),
			array(),
			'posts',
			array( 'posts_post_type' => 'post', 'posts_per_page' => 6, 'columns' => 3 )
		);
	}

	private function register_add_countdown(): void {
		$this->register_convenience_tool(
			'add-countdown',
			__( 'Add Countdown (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a countdown timer. Supports due_date or evergreen mode, custom labels, expire actions (hide/redirect/message), and digit/label colors and typography. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'countdown_type'         => array( 'type' => 'string', 'enum' => array( 'due_date', 'evergreen' ), 'description' => __( 'Countdown mode.', 'full-elementor-mcp' ) ),
				'due_date'               => array( 'type' => 'string', 'description' => __( 'Due date in Y-m-d H:i format.', 'full-elementor-mcp' ) ),
				// Evergreen.
				'evergreen_counter_hours'   => array( 'type' => 'integer', 'description' => __( 'Evergreen hours.', 'full-elementor-mcp' ) ),
				'evergreen_counter_minutes' => array( 'type' => 'integer', 'description' => __( 'Evergreen minutes.', 'full-elementor-mcp' ) ),
				// Visibility.
				'show_days'              => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show days.', 'full-elementor-mcp' ) ),
				'show_hours'             => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show hours.', 'full-elementor-mcp' ) ),
				'show_minutes'           => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show minutes.', 'full-elementor-mcp' ) ),
				'show_seconds'           => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show seconds.', 'full-elementor-mcp' ) ),
				// Labels.
				'show_labels'            => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show labels. Default: yes.', 'full-elementor-mcp' ) ),
				'custom_labels'          => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Use custom label text.', 'full-elementor-mcp' ) ),
				'label_days'             => array( 'type' => 'string', 'description' => __( 'Custom days label.', 'full-elementor-mcp' ) ),
				'label_hours'            => array( 'type' => 'string', 'description' => __( 'Custom hours label.', 'full-elementor-mcp' ) ),
				'label_minutes'          => array( 'type' => 'string', 'description' => __( 'Custom minutes label.', 'full-elementor-mcp' ) ),
				'label_seconds'          => array( 'type' => 'string', 'description' => __( 'Custom seconds label.', 'full-elementor-mcp' ) ),
				// Expire actions.
				'expire_actions'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Actions on expiry: ["hide","redirect","message"].', 'full-elementor-mcp' ) ),
				'message_after_expire'   => array( 'type' => 'string', 'description' => __( 'Message to show after expire.', 'full-elementor-mcp' ) ),
				'expire_redirect_url'    => array( 'type' => 'string', 'description' => __( 'Redirect URL after expire.', 'full-elementor-mcp' ) ),
				// Style - Digits.
				'digits_color'           => array( 'type' => 'string', 'description' => __( 'Digit text color.', 'full-elementor-mcp' ) ),
				'digits_background_color' => array( 'type' => 'string', 'description' => __( 'Digit background color.', 'full-elementor-mcp' ) ),
				// Style - Labels.
				'label_color'            => array( 'type' => 'string', 'description' => __( 'Label text color.', 'full-elementor-mcp' ) ),
				// Typography.
				'digits_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" for digit typography.', 'full-elementor-mcp' ) ),
				'digits_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Digit font family.', 'full-elementor-mcp' ) ),
				'digits_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Digit font size: {size, unit}.', 'full-elementor-mcp' ) ),
				'label_typography_typography'   => array( 'type' => 'string', 'description' => __( 'Set to "custom" for label typography.', 'full-elementor-mcp' ) ),
				'label_typography_font_family'  => array( 'type' => 'string', 'description' => __( 'Label font family.', 'full-elementor-mcp' ) ),
				'label_typography_font_size'    => array( 'type' => 'object', 'description' => __( 'Label font size: {size, unit}.', 'full-elementor-mcp' ) ),
			),
			array(),
			'countdown',
			array(
				'countdown_type' => 'due_date',
				'show_days'      => 'yes',
				'show_hours'     => 'yes',
				'show_minutes'   => 'yes',
				'show_seconds'   => 'yes',
				'show_labels'    => 'yes',
			)
		);
	}

	private function register_add_price_table(): void {
		$this->register_convenience_tool(
			'add-price-table',
			__( 'Add Price Table (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a pricing table. Supports 16 currency symbols, sale pricing, ribbon, footer info, button CSS ID, feature icons, and style controls (header/pricing/features/footer/button/ribbon colors and typography). Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'heading'                => array( 'type' => 'string', 'description' => __( 'Plan name/heading.', 'full-elementor-mcp' ) ),
				'sub_heading'            => array( 'type' => 'string', 'description' => __( 'Sub-heading text.', 'full-elementor-mcp' ) ),
				'currency_symbol'        => array( 'type' => 'string', 'enum' => array( 'dollar', 'euro', 'baht', 'franc', 'krona', 'lira', 'peseta', 'peso', 'pound', 'real', 'ruble', 'rupee', 'indian_rupee', 'shekel', 'won', 'yen', 'custom' ), 'description' => __( 'Currency symbol preset.', 'full-elementor-mcp' ) ),
				'currency_symbol_custom' => array( 'type' => 'string', 'description' => __( 'Custom currency symbol (when currency_symbol=custom).', 'full-elementor-mcp' ) ),
				'price'                  => array( 'type' => 'string', 'description' => __( 'Price amount.', 'full-elementor-mcp' ) ),
				'currency_format'        => array( 'type' => 'string', 'enum' => array( '', ',', '.' ), 'description' => __( 'Price format: comma or dot separator.', 'full-elementor-mcp' ) ),
				'period'                 => array( 'type' => 'string', 'description' => __( 'Billing period (e.g. "/month").', 'full-elementor-mcp' ) ),
				// Sale.
				'sale'                   => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Enable sale pricing.', 'full-elementor-mcp' ) ),
				'original_price'         => array( 'type' => 'string', 'description' => __( 'Original price (shown crossed out when sale=yes).', 'full-elementor-mcp' ) ),
				// Features.
				'features_list'          => array(
					'type'        => 'array',
					'description' => __( 'Feature list. Each item: {item_text, selected_item_icon, item_icon_color}.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'item_text'          => array( 'type' => 'string' ),
							'selected_item_icon' => array( 'type' => 'object' ),
							'item_icon_color'    => array( 'type' => 'string' ),
						),
					),
				),
				// Button.
				'button_text'            => array( 'type' => 'string', 'description' => __( 'CTA button text.', 'full-elementor-mcp' ) ),
				'link'                   => array( 'type' => 'object', 'description' => __( 'Button link: {url, is_external, nofollow}.', 'full-elementor-mcp' ) ),
				'button_css_id'          => array( 'type' => 'string', 'description' => __( 'Button CSS ID for tracking.', 'full-elementor-mcp' ) ),
				'button_size'            => array( 'type' => 'string', 'enum' => array( 'xs', 'sm', 'md', 'lg', 'xl' ), 'description' => __( 'Button size.', 'full-elementor-mcp' ) ),
				// Footer.
				'footer_additional_info' => array( 'type' => 'string', 'description' => __( 'Footer text below button (e.g. "30-day money back").', 'full-elementor-mcp' ) ),
				// Ribbon.
				'show_ribbon'            => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show ribbon/badge.', 'full-elementor-mcp' ) ),
				'ribbon_title'           => array( 'type' => 'string', 'description' => __( 'Ribbon text (e.g. "Popular", "Best Value").', 'full-elementor-mcp' ) ),
				'ribbon_horizontal_position' => array( 'type' => 'string', 'enum' => array( 'left', 'right' ), 'description' => __( 'Ribbon position.', 'full-elementor-mcp' ) ),
				// Style - Header.
				'header_bg_color'        => array( 'type' => 'string', 'description' => __( 'Header background color.', 'full-elementor-mcp' ) ),
				'heading_color'          => array( 'type' => 'string', 'description' => __( 'Heading text color.', 'full-elementor-mcp' ) ),
				'sub_heading_color'      => array( 'type' => 'string', 'description' => __( 'Sub-heading text color.', 'full-elementor-mcp' ) ),
				// Style - Pricing.
				'pricing_element_bg_color' => array( 'type' => 'string', 'description' => __( 'Pricing area background color.', 'full-elementor-mcp' ) ),
				'price_color'            => array( 'type' => 'string', 'description' => __( 'Price text color.', 'full-elementor-mcp' ) ),
				// Style - Button.
				'button_background_color'       => array( 'type' => 'string', 'description' => __( 'Button background color.', 'full-elementor-mcp' ) ),
				'button_text_color'             => array( 'type' => 'string', 'description' => __( 'Button text color.', 'full-elementor-mcp' ) ),
				'button_hover_background_color' => array( 'type' => 'string', 'description' => __( 'Button hover background color.', 'full-elementor-mcp' ) ),
				'button_hover_color'            => array( 'type' => 'string', 'description' => __( 'Button hover text color.', 'full-elementor-mcp' ) ),
				// Style - Ribbon.
				'ribbon_bg_color'        => array( 'type' => 'string', 'description' => __( 'Ribbon background color.', 'full-elementor-mcp' ) ),
				'ribbon_text_color'      => array( 'type' => 'string', 'description' => __( 'Ribbon text color.', 'full-elementor-mcp' ) ),
			),
			array( 'heading', 'price' ),
			'price-table',
			array( 'currency_symbol' => 'dollar', 'button_text' => 'Get Started' )
		);
	}

	private function register_add_flip_box(): void {
		$this->register_convenience_tool(
			'add-flip-box',
			__( 'Add Flip Box (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a flip box with front/back sides. Supports icon/image graphics, flip effects (flip/slide/push/zoom/fade), height, front/back background colors, title/description colors and typography. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'title_text_a'       => array( 'type' => 'string', 'description' => __( 'Front side title.', 'full-elementor-mcp' ) ),
				'description_text_a' => array( 'type' => 'string', 'description' => __( 'Front side description.', 'full-elementor-mcp' ) ),
				'title_text_b'       => array( 'type' => 'string', 'description' => __( 'Back side title.', 'full-elementor-mcp' ) ),
				'description_text_b' => array( 'type' => 'string', 'description' => __( 'Back side description.', 'full-elementor-mcp' ) ),
				'graphic_element'    => array( 'type' => 'string', 'enum' => array( 'none', 'image', 'icon' ), 'description' => __( 'Front graphic type.', 'full-elementor-mcp' ) ),
				'selected_icon'      => array( 'type' => 'object', 'description' => __( 'Front icon: {value, library}.', 'full-elementor-mcp' ) ),
				'image'              => array( 'type' => 'object', 'description' => __( 'Front image: {url, id}.', 'full-elementor-mcp' ) ),
				'graphic_element_b'  => array( 'type' => 'string', 'enum' => array( 'none', 'image', 'icon' ), 'description' => __( 'Back graphic type.', 'full-elementor-mcp' ) ),
				'selected_icon_b'    => array( 'type' => 'object', 'description' => __( 'Back icon: {value, library}.', 'full-elementor-mcp' ) ),
				'button_text'        => array( 'type' => 'string', 'description' => __( 'Back button text.', 'full-elementor-mcp' ) ),
				'link'               => array( 'type' => 'object', 'description' => __( 'Link: {url, is_external, nofollow}.', 'full-elementor-mcp' ) ),
				'flip_effect'        => array( 'type' => 'string', 'enum' => array( 'flip', 'slide', 'push', 'zoom-in', 'zoom-out', 'fade' ), 'description' => __( 'Flip animation.', 'full-elementor-mcp' ) ),
				'flip_direction'     => array( 'type' => 'string', 'enum' => array( 'left', 'right', 'up', 'down' ), 'description' => __( 'Flip direction.', 'full-elementor-mcp' ) ),
				'flip_3d'            => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Enable 3D depth effect.', 'full-elementor-mcp' ) ),
				// Height.
				'height'             => array( 'type' => 'object', 'description' => __( 'Box height: {size, unit}.', 'full-elementor-mcp' ) ),
				'border_radius'      => array( 'type' => 'object', 'description' => __( 'Border radius: {size, unit}.', 'full-elementor-mcp' ) ),
				// Front style.
				'background_color_a' => array( 'type' => 'string', 'description' => __( 'Front background color.', 'full-elementor-mcp' ) ),
				'title_color_a'      => array( 'type' => 'string', 'description' => __( 'Front title color.', 'full-elementor-mcp' ) ),
				'description_color_a' => array( 'type' => 'string', 'description' => __( 'Front description color.', 'full-elementor-mcp' ) ),
				'icon_color_a'       => array( 'type' => 'string', 'description' => __( 'Front icon color.', 'full-elementor-mcp' ) ),
				// Back style.
				'background_color_b' => array( 'type' => 'string', 'description' => __( 'Back background color.', 'full-elementor-mcp' ) ),
				'title_color_b'      => array( 'type' => 'string', 'description' => __( 'Back title color.', 'full-elementor-mcp' ) ),
				'description_color_b' => array( 'type' => 'string', 'description' => __( 'Back description color.', 'full-elementor-mcp' ) ),
				// Button style.
				'button_background_color' => array( 'type' => 'string', 'description' => __( 'Back button background color.', 'full-elementor-mcp' ) ),
				'button_color'       => array( 'type' => 'string', 'description' => __( 'Back button text color.', 'full-elementor-mcp' ) ),
				'button_size'        => array( 'type' => 'string', 'enum' => array( 'xs', 'sm', 'md', 'lg', 'xl' ), 'description' => __( 'Button size.', 'full-elementor-mcp' ) ),
			),
			array( 'title_text_a' ),
			'flip-box',
			array( 'flip_effect' => 'flip', 'flip_direction' => 'left' )
		);
	}

	private function register_add_animated_headline(): void {
		$this->register_convenience_tool(
			'add-animated-headline',
			__( 'Add Animated Headline (Pro)', 'full-elementor-mcp' ),
			__( 'Adds an Elementor Pro animated headline with highlight or rotating text effects.', 'full-elementor-mcp' ),
			array(
				'headline_style'   => array( 'type' => 'string', 'enum' => array( 'highlight', 'rotate' ), 'description' => __( 'Headline animation style.', 'full-elementor-mcp' ) ),
				'animation_type'   => array( 'type' => 'string', 'enum' => array( 'typing', 'clip', 'flip', 'swirl', 'blinds', 'drop-in', 'wave', 'slide', 'slide-down' ), 'description' => __( 'Rotation animation type.', 'full-elementor-mcp' ) ),
				'marker'           => array( 'type' => 'string', 'enum' => array( 'circle', 'curly', 'underline', 'double', 'double_underline', 'underline_zigzag', 'diagonal', 'strikethrough', 'x' ), 'description' => __( 'Highlight marker style.', 'full-elementor-mcp' ) ),
				'before_text'      => array( 'type' => 'string', 'description' => __( 'Text before animated portion.', 'full-elementor-mcp' ) ),
				'highlighted_text' => array( 'type' => 'string', 'description' => __( 'Highlighted text (for highlight style).', 'full-elementor-mcp' ) ),
				'rotating_text'    => array( 'type' => 'string', 'description' => __( 'Line-separated rotating text entries.', 'full-elementor-mcp' ) ),
				'after_text'       => array( 'type' => 'string', 'description' => __( 'Text after animated portion.', 'full-elementor-mcp' ) ),
				'tag'              => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), 'description' => __( 'HTML heading tag.', 'full-elementor-mcp' ) ),
			),
			array(),
			'animated-headline',
			array( 'headline_style' => 'highlight', 'tag' => 'h3' )
		);
	}

	private function register_add_call_to_action(): void {
		$this->register_convenience_tool(
			'add-call-to-action',
			__( 'Add Call to Action (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a call-to-action widget with title, description, button, and optional graphic/ribbon.', 'full-elementor-mcp' ),
			array(
				'title'           => array( 'type' => 'string', 'description' => __( 'CTA heading text.', 'full-elementor-mcp' ) ),
				'description'     => array( 'type' => 'string', 'description' => __( 'CTA description text.', 'full-elementor-mcp' ) ),
				'button'          => array( 'type' => 'string', 'description' => __( 'Button text. Default: Click Here.', 'full-elementor-mcp' ) ),
				'link'            => array( 'type' => 'object', 'description' => __( 'Button link object with url key.', 'full-elementor-mcp' ) ),
				'graphic_element' => array( 'type' => 'string', 'enum' => array( 'none', 'image', 'icon' ), 'description' => __( 'Graphic type.', 'full-elementor-mcp' ) ),
				'graphic_image'   => array( 'type' => 'object', 'description' => __( 'Image object with url and optional id.', 'full-elementor-mcp' ) ),
				'selected_icon'   => array( 'type' => 'object', 'description' => __( 'Icon object with value and library.', 'full-elementor-mcp' ) ),
				'ribbon_title'    => array( 'type' => 'string', 'description' => __( 'Optional ribbon/badge text.', 'full-elementor-mcp' ) ),
				'title_tag'       => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), 'description' => __( 'Title HTML tag. Default: h2.', 'full-elementor-mcp' ) ),
			),
			array( 'title' ),
			'call-to-action',
			array( 'title_tag' => 'h2', 'button' => 'Click Here' )
		);
	}

	private function register_add_slides(): void {
		$this->register_convenience_tool(
			'add-slides',
			__( 'Add Slides (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a full-width slides/slider. Supports heading, description, button per slide, background image/color/overlay, Ken Burns, content animation, height, navigation, autoplay, colors, typography. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'slides'           => array(
					'type'        => 'array',
					'description' => __( 'Array of slide items.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'heading'                 => array( 'type' => 'string' ),
							'description'             => array( 'type' => 'string' ),
							'button_text'             => array( 'type' => 'string' ),
							'link'                    => array( 'type' => 'object' ),
							'background_color'        => array( 'type' => 'string' ),
							'background_image'        => array( 'type' => 'object' ),
							'background_overlay'      => array( 'type' => 'string', 'enum' => array( 'yes', '' ) ),
							'background_overlay_color' => array( 'type' => 'string' ),
							'background_ken_burns'    => array( 'type' => 'string', 'enum' => array( 'yes', '' ) ),
							'zoom_direction'          => array( 'type' => 'string', 'enum' => array( 'in', 'out' ) ),
							'content_animation'       => array( 'type' => 'string', 'description' => __( 'Content entrance animation (e.g. fadeInUp, zoomIn).', 'full-elementor-mcp' ) ),
							'custom_css_class'        => array( 'type' => 'string' ),
							'horizontal_position'     => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ),
							'vertical_position'       => array( 'type' => 'string', 'enum' => array( 'top', 'middle', 'bottom' ) ),
							'text_align'              => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ),
						),
					),
				),
				// Slider options.
				'navigation'       => array( 'type' => 'string', 'enum' => array( 'both', 'arrows', 'dots', 'none' ), 'description' => __( 'Navigation type.', 'full-elementor-mcp' ) ),
				'autoplay'         => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Autoplay. Default: yes.', 'full-elementor-mcp' ) ),
				'autoplay_speed'   => array( 'type' => 'integer', 'description' => __( 'Autoplay interval in ms. Default: 5000.', 'full-elementor-mcp' ) ),
				'pause_on_hover'   => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Pause autoplay on hover.', 'full-elementor-mcp' ) ),
				'pause_on_interaction' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Pause autoplay on interaction.', 'full-elementor-mcp' ) ),
				'infinite'         => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Infinite loop. Default: yes.', 'full-elementor-mcp' ) ),
				'transition'       => array( 'type' => 'string', 'enum' => array( 'slide', 'fade' ), 'description' => __( 'Transition effect.', 'full-elementor-mcp' ) ),
				'transition_speed' => array( 'type' => 'integer', 'description' => __( 'Transition speed in ms.', 'full-elementor-mcp' ) ),
				// Slider layout.
				'slides_height'    => array( 'type' => 'object', 'description' => __( 'Slider height: {size, unit}. Responsive.', 'full-elementor-mcp' ) ),
				'content_max_width' => array( 'type' => 'object', 'description' => __( 'Content max width percentage: {size, unit}.', 'full-elementor-mcp' ) ),
				'slides_padding'   => array( 'type' => 'object', 'description' => __( 'Content padding: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				'slides_horizontal_position' => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Default horizontal position.', 'full-elementor-mcp' ) ),
				'slides_vertical_position'   => array( 'type' => 'string', 'enum' => array( 'top', 'middle', 'bottom' ), 'description' => __( 'Default vertical position.', 'full-elementor-mcp' ) ),
				'slides_text_align' => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Default text alignment.', 'full-elementor-mcp' ) ),
				// Style - Heading.
				'heading_spacing'  => array( 'type' => 'object', 'description' => __( 'Heading bottom spacing: {size, unit}.', 'full-elementor-mcp' ) ),
				'heading_color'    => array( 'type' => 'string', 'description' => __( 'Heading text color.', 'full-elementor-mcp' ) ),
				'heading_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" for heading typography.', 'full-elementor-mcp' ) ),
				'heading_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Heading font family.', 'full-elementor-mcp' ) ),
				'heading_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Heading font size: {size, unit}.', 'full-elementor-mcp' ) ),
				'heading_typography_font_weight' => array( 'type' => 'string', 'description' => __( 'Heading font weight.', 'full-elementor-mcp' ) ),
				// Style - Description.
				'description_spacing' => array( 'type' => 'object', 'description' => __( 'Description bottom spacing: {size, unit}.', 'full-elementor-mcp' ) ),
				'description_color' => array( 'type' => 'string', 'description' => __( 'Description text color.', 'full-elementor-mcp' ) ),
				'description_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" for description typography.', 'full-elementor-mcp' ) ),
				'description_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Description font family.', 'full-elementor-mcp' ) ),
				'description_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Description font size: {size, unit}.', 'full-elementor-mcp' ) ),
				// Style - Button.
				'button_size'      => array( 'type' => 'string', 'enum' => array( 'xs', 'sm', 'md', 'lg', 'xl' ), 'description' => __( 'Button size.', 'full-elementor-mcp' ) ),
				'button_color'     => array( 'type' => 'string', 'description' => __( 'Button text color.', 'full-elementor-mcp' ) ),
				'button_background_color' => array( 'type' => 'string', 'description' => __( 'Button background color.', 'full-elementor-mcp' ) ),
				'button_border_width' => array( 'type' => 'integer', 'description' => __( 'Button border width in px.', 'full-elementor-mcp' ) ),
				'button_border_color' => array( 'type' => 'string', 'description' => __( 'Button border color.', 'full-elementor-mcp' ) ),
				'button_border_radius' => array( 'type' => 'object', 'description' => __( 'Button border radius: {size, unit}.', 'full-elementor-mcp' ) ),
				'button_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" for button typography.', 'full-elementor-mcp' ) ),
				'button_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Button font family.', 'full-elementor-mcp' ) ),
				'button_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Button font size: {size, unit}.', 'full-elementor-mcp' ) ),
				// Style - Navigation.
				'arrows_size'      => array( 'type' => 'object', 'description' => __( 'Arrow size: {size, unit}.', 'full-elementor-mcp' ) ),
				'arrows_color'     => array( 'type' => 'string', 'description' => __( 'Arrow color.', 'full-elementor-mcp' ) ),
				'dots_size'        => array( 'type' => 'object', 'description' => __( 'Dot size: {size, unit}.', 'full-elementor-mcp' ) ),
				'dots_color'       => array( 'type' => 'string', 'description' => __( 'Dot color.', 'full-elementor-mcp' ) ),
			),
			array( 'slides' ),
			'slides',
			array( 'autoplay' => 'yes', 'autoplay_speed' => 5000, 'infinite' => 'yes' )
		);
	}

	private function register_add_testimonial_carousel(): void {
		$this->register_convenience_tool(
			'add-testimonial-carousel',
			__( 'Add Testimonial Carousel (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a testimonial carousel. Supports skins (default/bubble), layouts, navigation (arrows/dots), slide spacing, background/text/border colors, image size, content gap, and name/title/content typography. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'slides'          => array(
					'type'        => 'array',
					'description' => __( 'Array of testimonial items with content, image, name, and title.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'content' => array( 'type' => 'string' ),
							'image'   => array( 'type' => 'object' ),
							'name'    => array( 'type' => 'string' ),
							'title'   => array( 'type' => 'string' ),
						),
					),
				),
				'skin'            => array( 'type' => 'string', 'enum' => array( 'default', 'bubble' ), 'description' => __( 'Skin variant. Default: default.', 'full-elementor-mcp' ) ),
				'layout'          => array( 'type' => 'string', 'enum' => array( 'image_inline', 'image_stacked', 'image_above', 'image_left', 'image_right' ), 'description' => __( 'Layout mode.', 'full-elementor-mcp' ) ),
				'alignment'       => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Content alignment.', 'full-elementor-mcp' ) ),
				'slides_per_view' => array( 'type' => 'string', 'enum' => array( '1', '2', '3', '4' ), 'description' => __( 'Slides visible at once.', 'full-elementor-mcp' ) ),
				'autoplay'        => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Autoplay. Default: yes.', 'full-elementor-mcp' ) ),
				'autoplay_speed'  => array( 'type' => 'integer', 'description' => __( 'Autoplay interval in ms.', 'full-elementor-mcp' ) ),
				// Navigation.
				'navigation'      => array( 'type' => 'string', 'enum' => array( 'both', 'arrows', 'dots', 'none' ), 'description' => __( 'Navigation type.', 'full-elementor-mcp' ) ),
				'infinite'        => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Infinite loop.', 'full-elementor-mcp' ) ),
				'speed'           => array( 'type' => 'integer', 'description' => __( 'Transition speed in ms.', 'full-elementor-mcp' ) ),
				// Slide spacing.
				'space_between'   => array( 'type' => 'object', 'description' => __( 'Space between slides: {size, unit}.', 'full-elementor-mcp' ) ),
				// Style - Slide.
				'slide_background_color' => array( 'type' => 'string', 'description' => __( 'Slide background color.', 'full-elementor-mcp' ) ),
				'slide_padding'   => array( 'type' => 'object', 'description' => __( 'Slide padding: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				'slide_border_radius' => array( 'type' => 'object', 'description' => __( 'Slide border radius: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				'slide_border_border' => array( 'type' => 'string', 'enum' => array( '', 'solid', 'double', 'dotted', 'dashed' ), 'description' => __( 'Slide border style.', 'full-elementor-mcp' ) ),
				'slide_border_width'  => array( 'type' => 'object', 'description' => __( 'Slide border width.', 'full-elementor-mcp' ) ),
				'slide_border_color'  => array( 'type' => 'string', 'description' => __( 'Slide border color.', 'full-elementor-mcp' ) ),
				// Style - Content.
				'content_color'   => array( 'type' => 'string', 'description' => __( 'Content/quote text color.', 'full-elementor-mcp' ) ),
				'name_color'      => array( 'type' => 'string', 'description' => __( 'Author name color.', 'full-elementor-mcp' ) ),
				'title_color'     => array( 'type' => 'string', 'description' => __( 'Author title/role color.', 'full-elementor-mcp' ) ),
				// Style - Image.
				'image_size'      => array( 'type' => 'object', 'description' => __( 'Author image size: {size, unit}.', 'full-elementor-mcp' ) ),
				'image_gap'       => array( 'type' => 'object', 'description' => __( 'Gap between image and text: {size, unit}.', 'full-elementor-mcp' ) ),
				'image_border_radius' => array( 'type' => 'object', 'description' => __( 'Image border radius: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				// Typography.
				'content_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" for content typography.', 'full-elementor-mcp' ) ),
				'content_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Content font family.', 'full-elementor-mcp' ) ),
				'content_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Content font size: {size, unit}.', 'full-elementor-mcp' ) ),
				'name_typography_typography'     => array( 'type' => 'string', 'description' => __( 'Set to "custom" for name typography.', 'full-elementor-mcp' ) ),
				'name_typography_font_family'    => array( 'type' => 'string', 'description' => __( 'Name font family.', 'full-elementor-mcp' ) ),
				'name_typography_font_size'      => array( 'type' => 'object', 'description' => __( 'Name font size: {size, unit}.', 'full-elementor-mcp' ) ),
				'name_typography_font_weight'    => array( 'type' => 'string', 'description' => __( 'Name font weight.', 'full-elementor-mcp' ) ),
			),
			array( 'slides' ),
			'testimonial-carousel',
			array( 'skin' => 'default', 'layout' => 'image_inline', 'slides_per_view' => '1', 'autoplay' => 'yes' )
		);
	}

	private function register_add_price_list(): void {
		$this->register_convenience_tool(
			'add-price-list',
			__( 'Add Price List (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a price list widget for menus, services, or product lists with title, price, and description.', 'full-elementor-mcp' ),
			array(
				'price_list' => array(
					'type'        => 'array',
					'description' => __( 'Array of list items with title, price, item_description, image, and link.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'title'            => array( 'type' => 'string' ),
							'price'            => array( 'type' => 'string' ),
							'item_description' => array( 'type' => 'string' ),
							'image'            => array( 'type' => 'object' ),
							'link'             => array( 'type' => 'object' ),
						),
					),
				),
				'title_tag'  => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), 'description' => __( 'Title HTML tag. Default: span.', 'full-elementor-mcp' ) ),
			),
			array( 'price_list' ),
			'price-list',
			array( 'title_tag' => 'span' )
		);
	}

	private function register_add_gallery(): void {
		$this->register_convenience_tool(
			'add-gallery',
			__( 'Add Gallery (Pro)', 'full-elementor-mcp' ),
			__( 'Adds an advanced gallery. Supports grid/justified/masonry layouts, multiple galleries with filtering, aspect ratio, overlay effects, lightbox, lazy load, image border/radius, and hover opacity/CSS filters. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'gallery'        => array(
					'type'        => 'array',
					'description' => __( 'Array of image objects with id and url.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'  => array( 'type' => 'integer' ),
							'url' => array( 'type' => 'string' ),
						),
					),
				),
				'gallery_layout' => array( 'type' => 'string', 'enum' => array( 'grid', 'justified', 'masonry' ), 'description' => __( 'Gallery layout. Default: grid.', 'full-elementor-mcp' ) ),
				'columns'        => array( 'type' => 'integer', 'description' => __( 'Number of columns. Default: 4. Responsive: columns_tablet, columns_mobile.', 'full-elementor-mcp' ) ),
				'gap'            => array( 'type' => 'object', 'description' => __( 'Gap between items: {size, unit}.', 'full-elementor-mcp' ) ),
				'link_to'        => array( 'type' => 'string', 'enum' => array( 'file', 'custom', 'none' ), 'description' => __( 'Link behavior.', 'full-elementor-mcp' ) ),
				// Multi-gallery / filtering.
				'gallery_type'   => array( 'type' => 'string', 'enum' => array( 'single', 'multiple' ), 'description' => __( 'Single or multiple galleries (with filter bar).', 'full-elementor-mcp' ) ),
				'galleries'      => array(
					'type'        => 'array',
					'description' => __( 'For gallery_type=multiple: array of {gallery_title, gallery (array of images)}.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'gallery_title' => array( 'type' => 'string' ),
							'gallery'       => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						),
					),
				),
				// Layout options.
				'aspect_ratio'   => array( 'type' => 'string', 'enum' => array( '1:1', '3:2', '4:3', '9:16', '16:9', '21:9' ), 'description' => __( 'Image aspect ratio (grid layout).', 'full-elementor-mcp' ) ),
				'ideal_row_height' => array( 'type' => 'object', 'description' => __( 'Ideal row height for justified layout: {size, unit}.', 'full-elementor-mcp' ) ),
				'order_by'       => array( 'type' => 'string', 'enum' => array( '', 'random' ), 'description' => __( 'Image order: default or random.', 'full-elementor-mcp' ) ),
				'lazyload'       => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Lazy load images.', 'full-elementor-mcp' ) ),
				// Overlay.
				'overlay_background' => array( 'type' => 'string', 'description' => __( 'Overlay background color on hover.', 'full-elementor-mcp' ) ),
				'content_hover_animation' => array( 'type' => 'string', 'description' => __( 'Overlay content hover animation.', 'full-elementor-mcp' ) ),
				// Lightbox.
				'open_lightbox'  => array( 'type' => 'string', 'enum' => array( 'default', 'yes', 'no' ), 'description' => __( 'Open in lightbox.', 'full-elementor-mcp' ) ),
				// Image style.
				'image_border_radius' => array( 'type' => 'object', 'description' => __( 'Image border radius: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				'image_border_border' => array( 'type' => 'string', 'enum' => array( '', 'solid', 'double', 'dotted', 'dashed' ), 'description' => __( 'Image border style.', 'full-elementor-mcp' ) ),
				'image_border_width'  => array( 'type' => 'object', 'description' => __( 'Image border width: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				'image_border_color'  => array( 'type' => 'string', 'description' => __( 'Image border color.', 'full-elementor-mcp' ) ),
			),
			array( 'gallery' ),
			'gallery',
			array( 'gallery_layout' => 'grid', 'columns' => 4 )
		);
	}

	private function register_add_share_buttons(): void {
		$this->register_convenience_tool(
			'add-share-buttons',
			__( 'Add Share Buttons (Pro)', 'full-elementor-mcp' ),
			__( 'Adds social share buttons for sharing the current page.', 'full-elementor-mcp' ),
			array(
				'share_buttons' => array(
					'type'        => 'array',
					'description' => __( 'Array of share buttons with button (network name) and optional text.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'button' => array( 'type' => 'string', 'description' => __( 'Network: facebook, twitter, linkedin, pinterest, reddit, etc.', 'full-elementor-mcp' ) ),
							'text'   => array( 'type' => 'string' ),
						),
					),
				),
				'view'          => array( 'type' => 'string', 'enum' => array( 'icon-text', 'icon', 'text' ), 'description' => __( 'Display mode. Default: icon-text.', 'full-elementor-mcp' ) ),
				'skin'          => array( 'type' => 'string', 'enum' => array( 'gradient', 'minimal', 'framed', 'boxed', 'flat' ), 'description' => __( 'Button skin/style.', 'full-elementor-mcp' ) ),
				'shape'         => array( 'type' => 'string', 'enum' => array( 'square', 'rounded', 'circle' ), 'description' => __( 'Button shape. Default: square.', 'full-elementor-mcp' ) ),
				'columns'       => array( 'type' => 'integer', 'description' => __( 'Number of columns.', 'full-elementor-mcp' ) ),
			),
			array( 'share_buttons' ),
			'share-buttons',
			array( 'view' => 'icon-text', 'shape' => 'square' )
		);
	}

	private function register_add_table_of_contents(): void {
		$this->register_convenience_tool(
			'add-table-of-contents',
			__( 'Add Table of Contents (Pro)', 'full-elementor-mcp' ),
			__( 'Adds an auto-generated table of contents widget based on page headings.', 'full-elementor-mcp' ),
			array(
				'title'             => array( 'type' => 'string', 'description' => __( 'TOC title. Default: Table of Contents.', 'full-elementor-mcp' ) ),
				'headings_by_tags'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Which heading tags to include (e.g. ["h2", "h3"]).', 'full-elementor-mcp' ) ),
				'marker_view'       => array( 'type' => 'string', 'enum' => array( 'numbers', 'bullets', 'none' ), 'description' => __( 'Marker style. Default: numbers.', 'full-elementor-mcp' ) ),
				'hierarchical_view' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Hierarchical display. Default: yes.', 'full-elementor-mcp' ) ),
			),
			array(),
			'table-of-contents',
			array( 'title' => 'Table of Contents', 'marker_view' => 'numbers', 'hierarchical_view' => 'yes' )
		);
	}

	private function register_add_blockquote(): void {
		$this->register_convenience_tool(
			'add-blockquote',
			__( 'Add Blockquote (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a styled blockquote widget with quote text, author, tweet button, colors, border, typography. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'blockquote_content' => array( 'type' => 'string', 'description' => __( 'Quote/blockquote text.', 'full-elementor-mcp' ) ),
				'author_name'        => array( 'type' => 'string', 'description' => __( 'Author/attribution name.', 'full-elementor-mcp' ) ),
				'blockquote_skin'    => array( 'type' => 'string', 'enum' => array( 'border', 'quotation', 'boxed', 'clean' ), 'description' => __( 'Skin variant. Default: border.', 'full-elementor-mcp' ) ),
				'alignment'          => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Text alignment.', 'full-elementor-mcp' ) ),
				// Tweet button.
				'tweet_button'       => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show tweet button.', 'full-elementor-mcp' ) ),
				'tweet_button_view'  => array( 'type' => 'string', 'enum' => array( 'icon-text', 'icon', 'text' ), 'description' => __( 'Tweet button display mode.', 'full-elementor-mcp' ) ),
				'tweet_button_skin'  => array( 'type' => 'string', 'enum' => array( 'classic', 'bubble', 'link' ), 'description' => __( 'Tweet button style.', 'full-elementor-mcp' ) ),
				'tweet_button_label' => array( 'type' => 'string', 'description' => __( 'Custom tweet button label.', 'full-elementor-mcp' ) ),
				'url_type'           => array( 'type' => 'string', 'enum' => array( 'current_page', 'custom' ), 'description' => __( 'URL to share: current page or custom.', 'full-elementor-mcp' ) ),
				'url'                => array( 'type' => 'string', 'description' => __( 'Custom URL to share (when url_type=custom).', 'full-elementor-mcp' ) ),
				'user_name'          => array( 'type' => 'string', 'description' => __( 'Twitter @username for "via" attribution.', 'full-elementor-mcp' ) ),
				// Style - Quote.
				'content_text_color' => array( 'type' => 'string', 'description' => __( 'Quote text color.', 'full-elementor-mcp' ) ),
				'content_gap'        => array( 'type' => 'object', 'description' => __( 'Gap between quote and author: {size, unit}.', 'full-elementor-mcp' ) ),
				'content_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" for quote typography.', 'full-elementor-mcp' ) ),
				'content_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Quote font family.', 'full-elementor-mcp' ) ),
				'content_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Quote font size: {size, unit}.', 'full-elementor-mcp' ) ),
				// Style - Author.
				'author_text_color'  => array( 'type' => 'string', 'description' => __( 'Author name color.', 'full-elementor-mcp' ) ),
				'author_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" for author typography.', 'full-elementor-mcp' ) ),
				'author_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Author font family.', 'full-elementor-mcp' ) ),
				'author_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Author font size: {size, unit}.', 'full-elementor-mcp' ) ),
				// Style - Border/Quotation mark.
				'border_color'       => array( 'type' => 'string', 'description' => __( 'Border color (border skin) or quotation mark color (quotation skin).', 'full-elementor-mcp' ) ),
				'border_width'       => array( 'type' => 'object', 'description' => __( 'Border width: {size, unit}.', 'full-elementor-mcp' ) ),
				'border_gap'         => array( 'type' => 'object', 'description' => __( 'Gap between border and content: {size, unit}.', 'full-elementor-mcp' ) ),
				'quote_size'         => array( 'type' => 'object', 'description' => __( 'Quotation mark size (quotation skin): {size, unit}.', 'full-elementor-mcp' ) ),
				// Style - Box (boxed skin).
				'box_color'          => array( 'type' => 'string', 'description' => __( 'Box background color (boxed skin).', 'full-elementor-mcp' ) ),
				// Tweet button style.
				'button_color'       => array( 'type' => 'string', 'description' => __( 'Tweet button text/icon color.', 'full-elementor-mcp' ) ),
				'button_text_color'  => array( 'type' => 'string', 'description' => __( 'Tweet button background color.', 'full-elementor-mcp' ) ),
			),
			array( 'blockquote_content' ),
			'blockquote',
			array( 'blockquote_skin' => 'border' )
		);
	}

	private function register_add_lottie(): void {
		$this->register_convenience_tool(
			'add-lottie',
			__( 'Add Lottie Animation (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a Lottie animation widget. Supports triggers, loop, speed, renderer, sizing, link, viewport settings, opacity, CSS filters. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'source'              => array( 'type' => 'string', 'enum' => array( 'media_file', 'external_url' ), 'description' => __( 'Source type. Default: external_url.', 'full-elementor-mcp' ) ),
				'source_external_url' => array( 'type' => 'string', 'description' => __( 'External Lottie JSON URL.', 'full-elementor-mcp' ) ),
				'source_json'         => array( 'type' => 'object', 'description' => __( 'Media library file: {url, id}.', 'full-elementor-mcp' ) ),
				// Playback.
				'trigger'             => array( 'type' => 'string', 'enum' => array( 'arriving_to_viewport', 'on_click', 'on_hover', 'bind_to_scroll', 'none' ), 'description' => __( 'Animation trigger. Default: arriving_to_viewport.', 'full-elementor-mcp' ) ),
				'loop'                => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Loop animation. Default: yes.', 'full-elementor-mcp' ) ),
				'number_of_times'     => array( 'type' => 'integer', 'description' => __( 'Loop count (0 = infinite).', 'full-elementor-mcp' ) ),
				'play_speed'          => array( 'type' => 'object', 'description' => __( 'Playback speed: {size, unit}.', 'full-elementor-mcp' ) ),
				'start_point'         => array( 'type' => 'object', 'description' => __( 'Animation start point (0-100): {size, unit}.', 'full-elementor-mcp' ) ),
				'end_point'           => array( 'type' => 'object', 'description' => __( 'Animation end point (0-100): {size, unit}.', 'full-elementor-mcp' ) ),
				'reverse_animation'   => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Play animation in reverse.', 'full-elementor-mcp' ) ),
				'renderer'            => array( 'type' => 'string', 'enum' => array( 'svg', 'canvas' ), 'description' => __( 'Render method. Default: svg.', 'full-elementor-mcp' ) ),
				'lazyload'            => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Lazy load animation.', 'full-elementor-mcp' ) ),
				// Link.
				'link_to'             => array( 'type' => 'string', 'enum' => array( 'none', 'custom' ), 'description' => __( 'Link type.', 'full-elementor-mcp' ) ),
				'custom_link'         => array( 'type' => 'object', 'description' => __( 'Link object: {url, is_external, nofollow}.', 'full-elementor-mcp' ) ),
				// Viewport trigger settings.
				'viewport_start'      => array( 'type' => 'string', 'description' => __( 'Viewport offset start (e.g. "bottom").', 'full-elementor-mcp' ) ),
				'viewport_end'        => array( 'type' => 'string', 'description' => __( 'Viewport offset end.', 'full-elementor-mcp' ) ),
				// Caption.
				'caption_source'      => array( 'type' => 'string', 'enum' => array( 'none', 'title', 'caption', 'custom' ), 'description' => __( 'Caption source.', 'full-elementor-mcp' ) ),
				'caption'             => array( 'type' => 'string', 'description' => __( 'Custom caption text.', 'full-elementor-mcp' ) ),
				// Style.
				'align'               => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Alignment.', 'full-elementor-mcp' ) ),
				'width'               => array( 'type' => 'object', 'description' => __( 'Width: {size, unit}.', 'full-elementor-mcp' ) ),
				'opacity'             => array( 'type' => 'object', 'description' => __( 'Opacity (0-1): {size, unit}.', 'full-elementor-mcp' ) ),
				'css_filters_css_filter' => array( 'type' => 'string', 'description' => __( 'Set to "custom" for CSS filters.', 'full-elementor-mcp' ) ),
				'css_filters_blur'    => array( 'type' => 'object', 'description' => __( 'Blur filter: {size, unit}.', 'full-elementor-mcp' ) ),
				'css_filters_brightness' => array( 'type' => 'object', 'description' => __( 'Brightness filter: {size, unit}.', 'full-elementor-mcp' ) ),
				'css_filters_contrast' => array( 'type' => 'object', 'description' => __( 'Contrast filter: {size, unit}.', 'full-elementor-mcp' ) ),
				'css_filters_saturate' => array( 'type' => 'object', 'description' => __( 'Saturate filter: {size, unit}.', 'full-elementor-mcp' ) ),
				'opacity_hover'       => array( 'type' => 'object', 'description' => __( 'Hover opacity: {size, unit}.', 'full-elementor-mcp' ) ),
			),
			array(),
			'lottie',
			array( 'source' => 'external_url', 'trigger' => 'arriving_to_viewport', 'loop' => 'yes', 'renderer' => 'svg' )
		);
	}

	private function register_add_hotspot(): void {
		$this->register_convenience_tool(
			'add-hotspot',
			__( 'Add Hotspot (Pro)', 'full-elementor-mcp' ),
			__( 'Adds an image hotspot widget with clickable/hoverable points. Supports tooltip settings, animations, hotspot sizing/colors, image width. Accepts responsive suffixes and advanced controls.', 'full-elementor-mcp' ),
			array(
				'image'   => array( 'type' => 'object', 'description' => __( 'Background image object with url and optional id.', 'full-elementor-mcp' ) ),
				'hotspot' => array(
					'type'        => 'array',
					'description' => __( 'Array of hotspot items.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'hotspot_label'           => array( 'type' => 'string' ),
							'hotspot_link'            => array( 'type' => 'object', 'description' => __( '{url, is_external, nofollow}.', 'full-elementor-mcp' ) ),
							'hotspot_icon'            => array( 'type' => 'object', 'description' => __( 'Icon: {value, library}.', 'full-elementor-mcp' ) ),
							'hotspot_icon_position'   => array( 'type' => 'string', 'enum' => array( 'before', 'after' ) ),
							'hotspot_horizontal'      => array( 'type' => 'string', 'enum' => array( 'left', 'right' ) ),
							'hotspot_offset_x'        => array( 'type' => 'object', 'description' => __( 'Horizontal offset %: {size, unit}.', 'full-elementor-mcp' ) ),
							'hotspot_vertical'        => array( 'type' => 'string', 'enum' => array( 'top', 'bottom' ) ),
							'hotspot_offset_y'        => array( 'type' => 'object', 'description' => __( 'Vertical offset %: {size, unit}.', 'full-elementor-mcp' ) ),
							'hotspot_tooltip_content' => array( 'type' => 'string' ),
							'hotspot_custom_size'     => array( 'type' => 'string', 'enum' => array( 'yes', '' ) ),
							'hotspot_width'           => array( 'type' => 'object' ),
							'hotspot_height'          => array( 'type' => 'object' ),
						),
					),
				),
				// Image.
				'image_size'          => array( 'type' => 'string', 'description' => __( 'Image size (e.g. full, large, medium).', 'full-elementor-mcp' ) ),
				'image_custom_dimension' => array( 'type' => 'object', 'description' => __( 'Custom image dimensions: {width, height}.', 'full-elementor-mcp' ) ),
				// Tooltip settings.
				'tooltip_trigger'     => array( 'type' => 'string', 'enum' => array( 'mouseenter', 'click', 'none' ), 'description' => __( 'Tooltip trigger event. Default: mouseenter.', 'full-elementor-mcp' ) ),
				'tooltip_position'    => array( 'type' => 'string', 'enum' => array( 'top', 'bottom', 'left', 'right' ), 'description' => __( 'Default tooltip position.', 'full-elementor-mcp' ) ),
				'tooltip_animation'   => array( 'type' => 'string', 'enum' => array( 'e--animation-fadeIn', 'e--animation-zoomIn', 'e--animation-slideInUp', 'e--animation-slideInDown', 'e--animation-slideInLeft', 'e--animation-slideInRight' ), 'description' => __( 'Tooltip entrance animation.', 'full-elementor-mcp' ) ),
				'tooltip_animation_duration' => array( 'type' => 'object', 'description' => __( 'Tooltip animation duration: {size, unit}.', 'full-elementor-mcp' ) ),
				// Hotspot animation.
				'hotspot_animation'   => array( 'type' => 'string', 'enum' => array( 'none', 'soft-beat', 'expand', 'shadow' ), 'description' => __( 'Hotspot point animation.', 'full-elementor-mcp' ) ),
				'hotspot_sequenced_animation' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Staggered animation sequence.', 'full-elementor-mcp' ) ),
				// Style - Image.
				'image_width'         => array( 'type' => 'object', 'description' => __( 'Image width: {size, unit}.', 'full-elementor-mcp' ) ),
				'image_opacity'       => array( 'type' => 'object', 'description' => __( 'Image opacity (0-1): {size, unit}.', 'full-elementor-mcp' ) ),
				'image_border_radius' => array( 'type' => 'object', 'description' => __( 'Image border radius: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				// Style - Hotspot.
				'hotspot_color'       => array( 'type' => 'string', 'description' => __( 'Hotspot label/icon color.', 'full-elementor-mcp' ) ),
				'hotspot_background_color' => array( 'type' => 'string', 'description' => __( 'Hotspot background color.', 'full-elementor-mcp' ) ),
				'hotspot_size'        => array( 'type' => 'object', 'description' => __( 'Hotspot point size: {size, unit}.', 'full-elementor-mcp' ) ),
				'hotspot_padding'     => array( 'type' => 'object', 'description' => __( 'Hotspot padding: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				'hotspot_border_radius' => array( 'type' => 'object', 'description' => __( 'Hotspot border radius: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				'hotspot_box_shadow_box_shadow_type' => array( 'type' => 'string', 'description' => __( 'Set to "yes" for hotspot box shadow.', 'full-elementor-mcp' ) ),
				'hotspot_box_shadow_box_shadow' => array( 'type' => 'object', 'description' => __( 'Hotspot box shadow: {horizontal, vertical, blur, spread, color}.', 'full-elementor-mcp' ) ),
				// Style - Tooltip.
				'tooltip_text_color'  => array( 'type' => 'string', 'description' => __( 'Tooltip text color.', 'full-elementor-mcp' ) ),
				'tooltip_background_color' => array( 'type' => 'string', 'description' => __( 'Tooltip background color.', 'full-elementor-mcp' ) ),
				'tooltip_border_radius' => array( 'type' => 'object', 'description' => __( 'Tooltip border radius: {size, unit}.', 'full-elementor-mcp' ) ),
				'tooltip_padding'     => array( 'type' => 'object', 'description' => __( 'Tooltip padding: {top, right, bottom, left, unit, isLinked}.', 'full-elementor-mcp' ) ),
				'tooltip_width'       => array( 'type' => 'object', 'description' => __( 'Tooltip width: {size, unit}.', 'full-elementor-mcp' ) ),
				'tooltip_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "custom" for tooltip typography.', 'full-elementor-mcp' ) ),
				'tooltip_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Tooltip font family.', 'full-elementor-mcp' ) ),
				'tooltip_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Tooltip font size: {size, unit}.', 'full-elementor-mcp' ) ),
			),
			array( 'image', 'hotspot' ),
			'hotspot',
			array( 'tooltip_trigger' => 'mouseenter', 'tooltip_position' => 'top' )
		);
	}

	// ── Phase 5: Missing Widget Convenience Tools ─────────────────────

	private function register_add_menu_anchor(): void {
		$this->register_convenience_tool(
			'add-menu-anchor',
			__( 'Add Menu Anchor', 'full-elementor-mcp' ),
			__( 'Adds a menu anchor for one-page navigation.', 'full-elementor-mcp' ),
			array(
				'anchor' => array( 'type' => 'string', 'description' => __( 'The anchor ID (used in menu links as #id).', 'full-elementor-mcp' ) ),
			),
			array( 'anchor' ),
			'menu-anchor',
			array()
		);
	}

	private function register_add_shortcode(): void {
		$this->register_convenience_tool(
			'add-shortcode',
			__( 'Add Shortcode', 'full-elementor-mcp' ),
			__( 'Adds a WordPress shortcode widget.', 'full-elementor-mcp' ),
			array(
				'shortcode' => array( 'type' => 'string', 'description' => __( 'The shortcode to render, e.g. [contact-form-7 id="123"].', 'full-elementor-mcp' ) ),
			),
			array( 'shortcode' ),
			'shortcode',
			array()
		);
	}

	private function register_add_rating(): void {
		$this->register_convenience_tool(
			'add-rating',
			__( 'Add Rating', 'full-elementor-mcp' ),
			__( 'Adds a star/icon rating widget.', 'full-elementor-mcp' ),
			array(
				'rating_scale'        => array( 'type' => 'object', 'description' => __( 'Rating scale: { "size": 5, "unit": "px" }. Default 5.', 'full-elementor-mcp' ) ),
				'rating_value'        => array( 'type' => 'number', 'description' => __( 'Rating value (e.g. 4.5).', 'full-elementor-mcp' ) ),
				'rating_icon'         => array( 'type' => 'object', 'description' => __( 'Icon object, e.g. { "value": "eicon-star", "library": "eicons" }.', 'full-elementor-mcp' ) ),
				'icon_alignment'      => array( 'type' => 'string', 'enum' => array( 'start', 'center', 'end' ), 'description' => __( 'Icon alignment.', 'full-elementor-mcp' ) ),
				'icon_size'           => array( 'type' => 'object', 'description' => __( 'Icon size: { "size": 24, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'icon_gap'            => array( 'type' => 'object', 'description' => __( 'Space between icons: { "size": 5, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'icon_color'          => array( 'type' => 'string', 'description' => __( 'Marked icon color (hex).', 'full-elementor-mcp' ) ),
				'icon_unmarked_color' => array( 'type' => 'string', 'description' => __( 'Unmarked icon color (hex).', 'full-elementor-mcp' ) ),
			),
			array(),
			'rating',
			array( 'rating_value' => 5 )
		);
	}

	private function register_add_text_path(): void {
		$this->register_convenience_tool(
			'add-text-path',
			__( 'Add Text Path', 'full-elementor-mcp' ),
			__( 'Adds curved/path text widget.', 'full-elementor-mcp' ),
			array(
				'text'                => array( 'type' => 'string', 'description' => __( 'The text content.', 'full-elementor-mcp' ) ),
				'path'                => array( 'type' => 'string', 'enum' => array( 'wave', 'arc', 'circle', 'line', 'oval', 'spiral', 'custom' ), 'description' => __( 'Path shape type. Default: wave.', 'full-elementor-mcp' ) ),
				'custom_path'         => array( 'type' => 'object', 'description' => __( 'Custom SVG path object (when path=custom).', 'full-elementor-mcp' ) ),
				'link'                => array( 'type' => 'object', 'description' => __( 'Link object: { "url": "...", "is_external": true }.', 'full-elementor-mcp' ) ),
				'align'               => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Text alignment.', 'full-elementor-mcp' ) ),
				'text_path_direction' => array( 'type' => 'string', 'enum' => array( '', 'rtl', 'ltr' ), 'description' => __( 'Text direction.', 'full-elementor-mcp' ) ),
				'show_path'           => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show the SVG path line.', 'full-elementor-mcp' ) ),
				'size'                => array( 'type' => 'object', 'description' => __( 'Path size: { "size": 500, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'rotation'            => array( 'type' => 'object', 'description' => __( 'Rotation: { "size": 0, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'start_point'         => array( 'type' => 'object', 'description' => __( 'Starting point (%): { "size": 0, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'text_color_normal'   => array( 'type' => 'string', 'description' => __( 'Text color (hex).', 'full-elementor-mcp' ) ),
				'text_color_hover'    => array( 'type' => 'string', 'description' => __( 'Text hover color (hex).', 'full-elementor-mcp' ) ),
				'stroke_color_normal' => array( 'type' => 'string', 'description' => __( 'Path stroke color (hex).', 'full-elementor-mcp' ) ),
				'stroke_width_normal' => array( 'type' => 'object', 'description' => __( 'Path stroke width: { "size": 1, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'text_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "yes" for custom typography.', 'full-elementor-mcp' ) ),
				'text_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Font family name.', 'full-elementor-mcp' ) ),
				'text_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Font size: { "size": 20, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'text_typography_font_weight' => array( 'type' => 'string', 'description' => __( 'Font weight (100-900, normal, bold).', 'full-elementor-mcp' ) ),
			),
			array(),
			'text-path',
			array( 'text' => 'Add Your Curvy Text Here', 'path' => 'wave' )
		);
	}

	private function register_add_nav_menu(): void {
		$this->register_convenience_tool(
			'add-nav-menu',
			__( 'Add Navigation Menu', 'full-elementor-mcp' ),
			__( 'Adds a WordPress navigation menu widget (Pro).', 'full-elementor-mcp' ),
			array(
				'menu_name'     => array( 'type' => 'string', 'description' => __( 'Menu name (as registered in WP Menus).', 'full-elementor-mcp' ) ),
				'layout'        => array( 'type' => 'string', 'enum' => array( 'horizontal', 'vertical', 'dropdown' ), 'description' => __( 'Menu layout. Default: horizontal.', 'full-elementor-mcp' ) ),
				'align_items'   => array( 'type' => 'string', 'enum' => array( 'start', 'center', 'end', 'justify' ), 'description' => __( 'Menu alignment.', 'full-elementor-mcp' ) ),
				'pointer'       => array( 'type' => 'string', 'enum' => array( 'none', 'underline', 'overline', 'double-line', 'framed', 'background', 'text' ), 'description' => __( 'Hover pointer style. Default: underline.', 'full-elementor-mcp' ) ),
				'animation_line' => array( 'type' => 'string', 'enum' => array( 'fade', 'slide', 'grow', 'drop-in', 'drop-out', 'none' ), 'description' => __( 'Line pointer animation.', 'full-elementor-mcp' ) ),
				'dropdown'      => array( 'type' => 'string', 'enum' => array( 'mobile', 'tablet', 'none' ), 'description' => __( 'Breakpoint for dropdown toggle. Default: tablet.', 'full-elementor-mcp' ) ),
				'full_width'    => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Full width dropdown.', 'full-elementor-mcp' ) ),
				'text_align'    => array( 'type' => 'string', 'enum' => array( 'aside', 'center' ), 'description' => __( 'Dropdown text alignment.', 'full-elementor-mcp' ) ),
				'toggle'        => array( 'type' => 'string', 'enum' => array( '', 'burger' ), 'description' => __( 'Toggle button type.', 'full-elementor-mcp' ) ),
				'toggle_align'  => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Toggle button alignment.', 'full-elementor-mcp' ) ),
				'color_menu_item'                => array( 'type' => 'string', 'description' => __( 'Menu text color (hex).', 'full-elementor-mcp' ) ),
				'color_menu_item_hover'          => array( 'type' => 'string', 'description' => __( 'Menu hover text color (hex).', 'full-elementor-mcp' ) ),
				'pointer_color_menu_item_hover'  => array( 'type' => 'string', 'description' => __( 'Pointer hover color (hex).', 'full-elementor-mcp' ) ),
				'color_menu_item_active'         => array( 'type' => 'string', 'description' => __( 'Active item text color (hex).', 'full-elementor-mcp' ) ),
				'pointer_color_menu_item_active' => array( 'type' => 'string', 'description' => __( 'Active item pointer color (hex).', 'full-elementor-mcp' ) ),
				'padding_horizontal_menu_item'   => array( 'type' => 'object', 'description' => __( 'Horizontal padding: { "size": 20, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'padding_vertical_menu_item'     => array( 'type' => 'object', 'description' => __( 'Vertical padding: { "size": 15, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'menu_space_between'             => array( 'type' => 'object', 'description' => __( 'Space between items: { "size": 10, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'menu_typography_typography'      => array( 'type' => 'string', 'description' => __( 'Set to "yes" for custom typography.', 'full-elementor-mcp' ) ),
				'menu_typography_font_family'     => array( 'type' => 'string', 'description' => __( 'Font family name.', 'full-elementor-mcp' ) ),
				'menu_typography_font_size'       => array( 'type' => 'object', 'description' => __( 'Font size: { "size": 16, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'menu_typography_font_weight'     => array( 'type' => 'string', 'description' => __( 'Font weight.', 'full-elementor-mcp' ) ),
			),
			array(),
			'nav-menu',
			array( 'layout' => 'horizontal', 'pointer' => 'underline' )
		);
	}

	private function register_add_loop_grid(): void {
		$this->register_convenience_tool(
			'add-loop-grid',
			__( 'Add Loop Grid', 'full-elementor-mcp' ),
			__( 'Adds a loop grid widget that displays posts/pages/CPTs using a loop template (Pro).', 'full-elementor-mcp' ),
			array(
				'_skin'                => array( 'type' => 'string', 'enum' => array( 'post', 'post_taxonomy' ), 'description' => __( 'Template type. Default: post.', 'full-elementor-mcp' ) ),
				'template_id'          => array( 'type' => 'string', 'description' => __( 'Loop template ID.', 'full-elementor-mcp' ) ),
				'columns'              => array( 'type' => 'number', 'description' => __( 'Number of columns. Default: 3.', 'full-elementor-mcp' ) ),
				'columns_tablet'       => array( 'type' => 'number', 'description' => __( 'Columns on tablet. Default: 2.', 'full-elementor-mcp' ) ),
				'columns_mobile'       => array( 'type' => 'number', 'description' => __( 'Columns on mobile. Default: 1.', 'full-elementor-mcp' ) ),
				'posts_per_page'       => array( 'type' => 'number', 'description' => __( 'Items per page. Default: 6.', 'full-elementor-mcp' ) ),
				'masonry'              => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Enable masonry layout.', 'full-elementor-mcp' ) ),
				'equal_height'         => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Equal height items.', 'full-elementor-mcp' ) ),
				'post_query_post_type' => array( 'type' => 'string', 'enum' => array( 'post', 'page', 'by_id', 'current_query', 'related' ), 'description' => __( 'Query source. Default: post.', 'full-elementor-mcp' ) ),
				'post_query_include'   => array( 'type' => 'string', 'enum' => array( 'terms', 'authors' ), 'description' => __( 'Include by terms or authors.', 'full-elementor-mcp' ) ),
				'post_query_exclude'   => array( 'type' => 'string', 'enum' => array( 'current_post', 'manual_selection', 'terms', 'authors' ), 'description' => __( 'Exclude criteria.', 'full-elementor-mcp' ) ),
				'post_query_orderby'   => array( 'type' => 'string', 'enum' => array( 'post_date', 'post_title', 'menu_order', 'modified', 'comment_count', 'rand' ), 'description' => __( 'Order by field. Default: post_date.', 'full-elementor-mcp' ) ),
				'post_query_order'     => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'description' => __( 'Sort order. Default: desc.', 'full-elementor-mcp' ) ),
				'post_query_offset'    => array( 'type' => 'number', 'description' => __( 'Query offset.', 'full-elementor-mcp' ) ),
			),
			array(),
			'loop-grid',
			array( 'columns' => 3, 'posts_per_page' => 6 )
		);
	}

	private function register_add_loop_carousel(): void {
		$this->register_convenience_tool(
			'add-loop-carousel',
			__( 'Add Loop Carousel', 'full-elementor-mcp' ),
			__( 'Adds a loop carousel widget that displays posts in a carousel using a loop template (Pro).', 'full-elementor-mcp' ),
			array(
				'_skin'                => array( 'type' => 'string', 'enum' => array( 'post', 'post_taxonomy' ), 'description' => __( 'Template type. Default: post.', 'full-elementor-mcp' ) ),
				'template_id'          => array( 'type' => 'string', 'description' => __( 'Loop template ID.', 'full-elementor-mcp' ) ),
				'posts_per_page'       => array( 'type' => 'number', 'description' => __( 'Number of slides. Default: 6.', 'full-elementor-mcp' ) ),
				'slides_to_show'       => array( 'type' => 'string', 'enum' => array( '', '1', '2', '3', '4', '5', '6', '7', '8' ), 'description' => __( 'Slides on display. Default: 3.', 'full-elementor-mcp' ) ),
				'slides_to_show_tablet' => array( 'type' => 'string', 'description' => __( 'Slides on tablet. Default: 2.', 'full-elementor-mcp' ) ),
				'slides_to_show_mobile' => array( 'type' => 'string', 'description' => __( 'Slides on mobile. Default: 1.', 'full-elementor-mcp' ) ),
				'slides_to_scroll'     => array( 'type' => 'string', 'description' => __( 'Slides to scroll per step. Default: 1.', 'full-elementor-mcp' ) ),
				'equal_height'         => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Equal height slides. Default: yes.', 'full-elementor-mcp' ) ),
				'post_query_post_type' => array( 'type' => 'string', 'enum' => array( 'post', 'page', 'by_id', 'current_query', 'related' ), 'description' => __( 'Query source. Default: post.', 'full-elementor-mcp' ) ),
				'post_query_orderby'   => array( 'type' => 'string', 'enum' => array( 'post_date', 'post_title', 'menu_order', 'modified', 'comment_count', 'rand' ), 'description' => __( 'Order by. Default: post_date.', 'full-elementor-mcp' ) ),
				'post_query_order'     => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'description' => __( 'Sort order. Default: desc.', 'full-elementor-mcp' ) ),
			),
			array(),
			'loop-carousel',
			array( 'posts_per_page' => 6, 'slides_to_show' => '3', 'equal_height' => 'yes' )
		);
	}

	private function register_add_media_carousel(): void {
		$this->register_convenience_tool(
			'add-media-carousel',
			__( 'Add Media Carousel', 'full-elementor-mcp' ),
			__( 'Adds a media carousel widget for images/video with multiple skins (Pro).', 'full-elementor-mcp' ),
			array(
				'skin'           => array( 'type' => 'string', 'enum' => array( 'carousel', 'slideshow', 'coverflow' ), 'description' => __( 'Carousel skin. Default: carousel.', 'full-elementor-mcp' ) ),
				'slides'         => array(
					'type'        => 'array',
					'description' => __( 'Array of slide items with image/video.', 'full-elementor-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'image' => array( 'type' => 'object', 'description' => __( '{ "url": "...", "id": 0 }', 'full-elementor-mcp' ) ),
							'type'  => array( 'type' => 'string', 'description' => __( 'Slide type: image or video.', 'full-elementor-mcp' ) ),
						),
					),
				),
				'effect'           => array( 'type' => 'string', 'enum' => array( 'slide', 'fade', 'cube' ), 'description' => __( 'Transition effect. Default: slide.', 'full-elementor-mcp' ) ),
				'slides_per_view'  => array( 'type' => 'string', 'description' => __( 'Slides visible at once.', 'full-elementor-mcp' ) ),
				'slides_to_scroll' => array( 'type' => 'string', 'description' => __( 'Slides to scroll per step.', 'full-elementor-mcp' ) ),
				'height'           => array( 'type' => 'object', 'description' => __( 'Carousel height: { "size": 400, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'width'            => array( 'type' => 'object', 'description' => __( 'Carousel width: { "size": 100, "unit": "%" }.', 'full-elementor-mcp' ) ),
				'show_arrows'      => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show navigation arrows. Default: yes.', 'full-elementor-mcp' ) ),
				'pagination'       => array( 'type' => 'string', 'enum' => array( '', 'bullets', 'fraction', 'progressbar' ), 'description' => __( 'Pagination type. Default: bullets.', 'full-elementor-mcp' ) ),
				'speed'            => array( 'type' => 'number', 'description' => __( 'Transition duration ms. Default: 500.', 'full-elementor-mcp' ) ),
				'autoplay'         => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Enable autoplay. Default: yes.', 'full-elementor-mcp' ) ),
				'autoplay_speed'   => array( 'type' => 'number', 'description' => __( 'Autoplay speed ms. Default: 5000.', 'full-elementor-mcp' ) ),
				'loop'             => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Infinite loop. Default: yes.', 'full-elementor-mcp' ) ),
				'pause_on_hover'   => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Pause on hover. Default: yes.', 'full-elementor-mcp' ) ),
				'overlay'          => array( 'type' => 'string', 'enum' => array( '', 'text', 'icon' ), 'description' => __( 'Overlay type on hover.', 'full-elementor-mcp' ) ),
				'caption'          => array( 'type' => 'string', 'enum' => array( 'title', 'caption', 'description' ), 'description' => __( 'Caption source. Default: title.', 'full-elementor-mcp' ) ),
				'image_size_size'  => array( 'type' => 'string', 'enum' => array( 'thumbnail', 'medium', 'medium_large', 'large', 'full', 'custom' ), 'description' => __( 'Image resolution. Default: full.', 'full-elementor-mcp' ) ),
				'image_fit'        => array( 'type' => 'string', 'enum' => array( '', 'contain', 'auto' ), 'description' => __( 'Image fit mode.', 'full-elementor-mcp' ) ),
				'centered_slides'  => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Center the active slide.', 'full-elementor-mcp' ) ),
				'slide_background_color' => array( 'type' => 'string', 'description' => __( 'Slide background color (hex).', 'full-elementor-mcp' ) ),
				'slide_border_radius'    => array( 'type' => 'object', 'description' => __( 'Slide border radius.', 'full-elementor-mcp' ) ),
				'arrows_size'      => array( 'type' => 'object', 'description' => __( 'Arrow size: { "size": 20, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'arrows_color'     => array( 'type' => 'string', 'description' => __( 'Arrow color (hex).', 'full-elementor-mcp' ) ),
				'space_between'    => array( 'type' => 'object', 'description' => __( 'Space between slides: { "size": 10, "unit": "px" }.', 'full-elementor-mcp' ) ),
			),
			array(),
			'media-carousel',
			array( 'skin' => 'carousel', 'autoplay' => 'yes', 'loop' => 'yes' )
		);
	}

	private function register_add_nested_tabs(): void {
		$this->register_convenience_tool(
			'add-nested-tabs',
			__( 'Add Nested Tabs', 'full-elementor-mcp' ),
			__( 'Adds a modern nested tabs widget where each tab content is a container (Pro). Tab content can be populated by adding child elements to the tab containers after creation.', 'full-elementor-mcp' ),
			array(
				'tabs_direction'          => array( 'type' => 'string', 'enum' => array( 'block-start', 'block-end', 'inline-end', 'inline-start' ), 'description' => __( 'Tab direction. block-start=top, block-end=bottom, inline-start=left, inline-end=right.', 'full-elementor-mcp' ) ),
				'tabs_justify_horizontal' => array( 'type' => 'string', 'enum' => array( 'start', 'center', 'end', 'stretch' ), 'description' => __( 'Horizontal tab justify.', 'full-elementor-mcp' ) ),
				'tabs_justify_vertical'   => array( 'type' => 'string', 'enum' => array( 'start', 'center', 'end', 'stretch' ), 'description' => __( 'Vertical tab justify.', 'full-elementor-mcp' ) ),
				'tabs_width'              => array( 'type' => 'object', 'description' => __( 'Tab width: { "size": 200, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'title_alignment'         => array( 'type' => 'string', 'enum' => array( 'start', 'center', 'end' ), 'description' => __( 'Title alignment within tab.', 'full-elementor-mcp' ) ),
				'horizontal_scroll'       => array( 'type' => 'string', 'enum' => array( 'disable', 'enable' ), 'description' => __( 'Enable horizontal scroll for tabs. Default: disable.', 'full-elementor-mcp' ) ),
				'breakpoint_selector'     => array( 'type' => 'string', 'enum' => array( 'none', 'mobile', 'tablet' ), 'description' => __( 'Breakpoint for accordion mode. Default: mobile.', 'full-elementor-mcp' ) ),
				'tabs_title_space_between' => array( 'type' => 'object', 'description' => __( 'Gap between tabs: { "size": 0, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'tabs_title_spacing'       => array( 'type' => 'object', 'description' => __( 'Distance from content: { "size": 0, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'tabs_title_background_color_background' => array( 'type' => 'string', 'enum' => array( 'classic', 'gradient' ), 'description' => __( 'Tab background type.', 'full-elementor-mcp' ) ),
				'tabs_title_background_color_color'      => array( 'type' => 'string', 'description' => __( 'Tab background color (hex).', 'full-elementor-mcp' ) ),
				'tabs_title_typography_typography'  => array( 'type' => 'string', 'description' => __( 'Set to "yes" for custom tab typography.', 'full-elementor-mcp' ) ),
				'tabs_title_typography_font_family' => array( 'type' => 'string', 'description' => __( 'Tab font family.', 'full-elementor-mcp' ) ),
				'tabs_title_typography_font_size'   => array( 'type' => 'object', 'description' => __( 'Tab font size: { "size": 16, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'tabs_title_typography_font_weight' => array( 'type' => 'string', 'description' => __( 'Tab font weight.', 'full-elementor-mcp' ) ),
			),
			array(),
			'nested-tabs',
			array()
		);
	}

	private function register_add_nested_accordion(): void {
		$this->register_convenience_tool(
			'add-nested-accordion',
			__( 'Add Nested Accordion', 'full-elementor-mcp' ),
			__( 'Adds a modern nested accordion widget where each item content is a container (Pro). Item content can be populated by adding child elements to the item containers after creation.', 'full-elementor-mcp' ),
			array(
				'accordion_item_title_position_horizontal' => array( 'type' => 'string', 'enum' => array( 'start', 'center', 'end', 'stretch' ), 'description' => __( 'Title position.', 'full-elementor-mcp' ) ),
				'accordion_item_title_icon_position'       => array( 'type' => 'string', 'enum' => array( 'start', 'end' ), 'description' => __( 'Icon position. Default: end.', 'full-elementor-mcp' ) ),
				'accordion_item_title_icon'                => array( 'type' => 'object', 'description' => __( 'Expand icon object.', 'full-elementor-mcp' ) ),
				'accordion_item_title_icon_active'         => array( 'type' => 'object', 'description' => __( 'Collapse icon object.', 'full-elementor-mcp' ) ),
				'title_tag'             => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), 'description' => __( 'Title HTML tag. Default: div.', 'full-elementor-mcp' ) ),
				'faq_schema'            => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Enable FAQ Schema markup.', 'full-elementor-mcp' ) ),
				'default_state'         => array( 'type' => 'string', 'enum' => array( 'expanded', 'all_collapsed' ), 'description' => __( 'Default state. Default: expanded (first item open).', 'full-elementor-mcp' ) ),
				'max_items_expended'    => array( 'type' => 'string', 'enum' => array( 'one', 'multiple' ), 'description' => __( 'Max items expanded at once. Default: one.', 'full-elementor-mcp' ) ),
				'n_accordion_animation_duration' => array( 'type' => 'object', 'description' => __( 'Animation duration: { "size": 400, "unit": "ms" }.', 'full-elementor-mcp' ) ),
				'accordion_item_title_space_between'          => array( 'type' => 'object', 'description' => __( 'Space between items: { "size": 0, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'accordion_item_title_distance_from_content'  => array( 'type' => 'object', 'description' => __( 'Distance from content: { "size": 0, "unit": "px" }.', 'full-elementor-mcp' ) ),
				'accordion_border_normal_border' => array( 'type' => 'string', 'enum' => array( '', 'none', 'solid', 'double', 'dotted', 'dashed', 'groove' ), 'description' => __( 'Border type.', 'full-elementor-mcp' ) ),
				'accordion_border_normal_color'  => array( 'type' => 'string', 'description' => __( 'Border color (hex).', 'full-elementor-mcp' ) ),
				'accordion_border_normal_width'  => array( 'type' => 'object', 'description' => __( 'Border width.', 'full-elementor-mcp' ) ),
				'accordion_background_normal_background' => array( 'type' => 'string', 'enum' => array( 'classic', 'gradient' ), 'description' => __( 'Background type.', 'full-elementor-mcp' ) ),
				'accordion_background_normal_color'      => array( 'type' => 'string', 'description' => __( 'Background color (hex).', 'full-elementor-mcp' ) ),
			),
			array(),
			'nested-accordion',
			array( 'default_state' => 'expanded', 'max_items_expended' => 'one' )
		);
	}

	private function register_add_portfolio(): void {
		$this->register_convenience_tool(
			'add-portfolio',
			__( 'Add Portfolio (Pro)', 'full-elementor-mcp' ),
			__( 'Adds an Elementor Pro portfolio widget to display a filterable grid of posts or custom post types.', 'full-elementor-mcp' ),
			array(
				'columns'             => array( 'type' => 'integer', 'description' => __( 'Number of columns. Default: 3.', 'full-elementor-mcp' ) ),
				'posts_per_page'      => array( 'type' => 'integer', 'description' => __( 'Number of posts to display. Default: 6.', 'full-elementor-mcp' ) ),
				'show_filter_bar'     => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show category filter bar. Default: no.', 'full-elementor-mcp' ) ),
				'masonry'             => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Enable masonry layout. Default: no.', 'full-elementor-mcp' ) ),
				'show_title'          => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show post title overlay. Default: yes.', 'full-elementor-mcp' ) ),
				'title_tag'           => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), 'description' => __( 'HTML tag for the title. Default: h3.', 'full-elementor-mcp' ) ),
				'thumbnail_size_size' => array( 'type' => 'string', 'enum' => array( 'thumbnail', 'medium', 'medium_large', 'large', 'full' ), 'description' => __( 'Image size. Default: medium.', 'full-elementor-mcp' ) ),
			),
			array(),
			'portfolio',
			array(
				'columns'             => 3,
				'posts_per_page'      => 6,
				'show_filter_bar'     => 'no',
				'masonry'             => 'no',
				'show_title'          => 'yes',
				'title_tag'           => 'h3',
				'thumbnail_size_size' => 'medium',
			)
		);
	}

	private function register_add_author_box(): void {
		$this->register_convenience_tool(
			'add-author-box',
			__( 'Add Author Box (Pro)', 'full-elementor-mcp' ),
			__( 'Adds an Elementor Pro author box widget displaying the post author\'s avatar, name, bio, and a link to their posts.', 'full-elementor-mcp' ),
			array(
				'show_avatar'     => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show author avatar. Default: yes.', 'full-elementor-mcp' ) ),
				'avatar_size'     => array( 'type' => 'integer', 'description' => __( 'Avatar size in pixels. Default: 96.', 'full-elementor-mcp' ) ),
				'show_name'       => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show author name. Default: yes.', 'full-elementor-mcp' ) ),
				'author_name_tag' => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), 'description' => __( 'HTML tag for the author name. Default: h3.', 'full-elementor-mcp' ) ),
				'show_biography'  => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show author biography. Default: yes.', 'full-elementor-mcp' ) ),
				'show_link'       => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show link button to author posts. Default: yes.', 'full-elementor-mcp' ) ),
				'link_text'       => array( 'type' => 'string', 'description' => __( 'Button label for the author posts link. Default: More Posts.', 'full-elementor-mcp' ) ),
				'alignment'       => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Content alignment. Default: left.', 'full-elementor-mcp' ) ),
			),
			array(),
			'author-box',
			array(
				'show_avatar'     => 'yes',
				'avatar_size'     => 96,
				'show_name'       => 'yes',
				'author_name_tag' => 'h3',
				'show_biography'  => 'yes',
				'show_link'       => 'yes',
				'link_text'       => 'More Posts',
				'alignment'       => 'left',
			)
		);
	}

	private function register_add_login(): void {
		$this->register_convenience_tool(
			'add-login',
			__( 'Add Login (Pro)', 'full-elementor-mcp' ),
			__( 'Adds an Elementor Pro login form widget with configurable fields, labels, remember me, lost password link, and post-login redirect.', 'full-elementor-mcp' ),
			array(
				'show_labels'          => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show field labels. Default: yes.', 'full-elementor-mcp' ) ),
				'show_remember_me'     => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show "Remember Me" checkbox. Default: yes.', 'full-elementor-mcp' ) ),
				'show_lost_password'   => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show "Lost Password" link. Default: yes.', 'full-elementor-mcp' ) ),
				'button_text'          => array( 'type' => 'string', 'description' => __( 'Submit button label. Default: Log In.', 'full-elementor-mcp' ) ),
				'button_size'          => array( 'type' => 'string', 'enum' => array( 'xs', 'sm', 'md', 'lg', 'xl' ), 'description' => __( 'Button size preset. Default: sm.', 'full-elementor-mcp' ) ),
				'redirect_after_login' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Enable custom redirect after login. Default: no.', 'full-elementor-mcp' ) ),
				'redirect_url'         => array( 'type' => 'string', 'description' => __( 'URL to redirect to after login (requires redirect_after_login=yes).', 'full-elementor-mcp' ) ),
				'align'                => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ), 'description' => __( 'Button alignment. Default: left.', 'full-elementor-mcp' ) ),
			),
			array(),
			'login',
			array(
				'show_labels'          => 'yes',
				'show_remember_me'     => 'yes',
				'show_lost_password'   => 'yes',
				'button_text'          => 'Log In',
				'button_size'          => 'sm',
				'redirect_after_login' => 'no',
				'redirect_url'         => '',
				'align'                => 'left',
			)
		);
	}

	private function register_add_code_highlight(): void {
		$this->register_convenience_tool(
			'add-code-highlight',
			__( 'Add Code Highlight (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a syntax-highlighted code block widget.', 'full-elementor-mcp' ),
			array(
				'code'              => array( 'type' => 'string', 'description' => __( 'The code content to display.', 'full-elementor-mcp' ) ),
				'language'          => array( 'type' => 'string', 'enum' => array( 'php', 'javascript', 'css', 'html', 'python', 'bash' ), 'description' => __( 'Syntax language. Default: php.', 'full-elementor-mcp' ) ),
				'theme'             => array( 'type' => 'string', 'enum' => array( 'default', 'dark', 'funky', 'okaidia', 'twilight', 'coy' ), 'description' => __( 'Color theme. Default: default.', 'full-elementor-mcp' ) ),
				'line_numbers'      => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show line numbers. Default: yes.', 'full-elementor-mcp' ) ),
				'copy_to_clipboard' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show copy-to-clipboard button. Default: yes.', 'full-elementor-mcp' ) ),
			),
			array(),
			'code-highlight',
			array(
				'code'              => '',
				'language'          => 'php',
				'theme'             => 'default',
				'line_numbers'      => 'yes',
				'copy_to_clipboard' => 'yes',
			)
		);
	}

	private function register_add_reviews(): void {
		$this->register_convenience_tool(
			'add-reviews',
			__( 'Add Reviews (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a reviews/testimonials carousel widget.', 'full-elementor-mcp' ),
			array(
				'slides_per_view' => array( 'type' => 'integer', 'description' => __( 'Number of slides visible at once. Default: 3.', 'full-elementor-mcp' ) ),
				'autoplay'        => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Enable autoplay. Default: no.', 'full-elementor-mcp' ) ),
				'autoplay_speed'  => array( 'type' => 'integer', 'description' => __( 'Autoplay speed in milliseconds. Default: 3000.', 'full-elementor-mcp' ) ),
				'loop'            => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Enable infinite loop. Default: yes.', 'full-elementor-mcp' ) ),
				'show_arrows'     => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show navigation arrows. Default: yes.', 'full-elementor-mcp' ) ),
				'pause_on_hover'  => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Pause autoplay on hover. Default: yes.', 'full-elementor-mcp' ) ),
			),
			array(),
			'reviews',
			array(
				'slides_per_view' => 3,
				'autoplay'        => 'no',
				'autoplay_speed'  => 3000,
				'loop'            => 'yes',
				'show_arrows'     => 'yes',
				'pause_on_hover'  => 'yes',
			)
		);
	}

	private function register_add_off_canvas(): void {
		$this->register_convenience_tool(
			'add-off-canvas',
			__( 'Add Off-Canvas (Pro)', 'full-elementor-mcp' ),
			__( 'Adds an off-canvas panel widget.', 'full-elementor-mcp' ),
			array(
				'horizontal_position' => array( 'type' => 'string', 'enum' => array( 'left', 'right' ), 'description' => __( 'Panel side. Default: left.', 'full-elementor-mcp' ) ),
				'vertical_position'   => array( 'type' => 'string', 'enum' => array( 'top', 'center', 'bottom' ), 'description' => __( 'Vertical alignment. Default: center.', 'full-elementor-mcp' ) ),
				'width'               => array( 'type' => 'integer', 'description' => __( 'Panel width in pixels. Default: 300.', 'full-elementor-mcp' ) ),
				'entrance_animation'  => array( 'type' => 'string', 'enum' => array( 'none', 'slideInLeft', 'slideInRight', 'fadeIn' ), 'description' => __( 'Open animation. Default: slideInLeft.', 'full-elementor-mcp' ) ),
				'has_overlay'         => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Show background overlay. Default: yes.', 'full-elementor-mcp' ) ),
				'prevent_scroll'      => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Lock body scroll when open. Default: yes.', 'full-elementor-mcp' ) ),
			),
			array(),
			'off-canvas',
			array(
				'horizontal_position' => 'left',
				'vertical_position'   => 'center',
				'width'               => 300,
				'entrance_animation'  => 'slideInLeft',
				'has_overlay'         => 'yes',
				'prevent_scroll'      => 'yes',
			)
		);
	}

	private function register_add_progress_tracker(): void {
		$this->register_convenience_tool(
			'add-progress-tracker',
			__( 'Add Progress Tracker (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a scroll progress tracker widget.', 'full-elementor-mcp' ),
			array(
				'type'          => array( 'type' => 'string', 'enum' => array( 'horizontal', 'circular' ), 'description' => __( 'Tracker style. Default: horizontal.', 'full-elementor-mcp' ) ),
				'relative_to'   => array( 'type' => 'string', 'enum' => array( 'page', 'element' ), 'description' => __( 'What to track. Default: page.', 'full-elementor-mcp' ) ),
				'align'         => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Alignment. Default: left.', 'full-elementor-mcp' ) ),
				'circular_size' => array( 'type' => 'integer', 'description' => __( 'Circle diameter in pixels (circular type). Default: 200.', 'full-elementor-mcp' ) ),
				'circular_width' => array( 'type' => 'integer', 'description' => __( 'Circle stroke width in pixels (circular type). Default: 8.', 'full-elementor-mcp' ) ),
			),
			array(),
			'progress-tracker',
			array(
				'type'           => 'horizontal',
				'relative_to'    => 'page',
				'align'          => 'left',
				'circular_size'  => 200,
				'circular_width' => 8,
			)
		);
	}

	private function register_add_search(): void {
		$this->register_convenience_tool(
			'add-search',
			__( 'Add Search (Pro)', 'full-elementor-mcp' ),
			__( 'Adds a search widget with live results support.', 'full-elementor-mcp' ),
			array(
				'search_input_placeholder_text' => array( 'type' => 'string', 'description' => __( 'Placeholder text for the search input. Default: Search...', 'full-elementor-mcp' ) ),
				'submit_trigger'                => array( 'type' => 'string', 'enum' => array( 'button', 'auto' ), 'description' => __( 'Search trigger method. Default: button.', 'full-elementor-mcp' ) ),
				'submit_button_text'            => array( 'type' => 'string', 'description' => __( 'Submit button label. Default: Search.', 'full-elementor-mcp' ) ),
				'live_results'                  => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => __( 'Enable live search results dropdown. Default: no.', 'full-elementor-mcp' ) ),
				'number_of_items'               => array( 'type' => 'integer', 'description' => __( 'Max results to show. Default: 5.', 'full-elementor-mcp' ) ),
				'search_query_post_type'        => array( 'type' => 'string', 'description' => __( 'Post type to search. Default: any.', 'full-elementor-mcp' ) ),
			),
			array(),
			'search',
			array(
				'search_input_placeholder_text' => 'Search...',
				'submit_trigger'                => 'button',
				'submit_button_text'            => 'Search',
				'live_results'                  => 'no',
				'number_of_items'               => 5,
				'search_query_post_type'        => 'any',

			)
		);
	}

	// ── Phase 6: WooCommerce Widget Convenience Tools ─────────────────

	private function register_add_wc_products(): void {
		$this->register_convenience_tool(
			'add-wc-products',
			__( 'Add WooCommerce Products', 'full-elementor-mcp' ),
			__( 'Adds a WooCommerce products grid widget (Pro + WooCommerce).', 'full-elementor-mcp' ),
			array(
				'columns'        => array( 'type' => 'number', 'description' => __( 'Number of columns. Default: 4.', 'full-elementor-mcp' ) ),
				'rows'           => array( 'type' => 'number', 'description' => __( 'Number of rows. Default: 1.', 'full-elementor-mcp' ) ),
				'paginate'       => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show pagination.', 'full-elementor-mcp' ) ),
				'orderby'        => array( 'type' => 'string', 'enum' => array( 'date', 'title', 'price', 'popularity', 'rating', 'rand', 'menu_order' ), 'description' => __( 'Order by. Default: date.', 'full-elementor-mcp' ) ),
				'order'          => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'description' => __( 'Sort order. Default: desc.', 'full-elementor-mcp' ) ),
				'query_post_type' => array( 'type' => 'string', 'enum' => array( 'product', 'current_query', 'by_id', 'related' ), 'description' => __( 'Query source. Default: product.', 'full-elementor-mcp' ) ),
				'show_result_count' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show result count.', 'full-elementor-mcp' ) ),
				'allow_order'    => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Allow ordering.', 'full-elementor-mcp' ) ),
			),
			array(),
			'woocommerce-products',
			array( 'columns' => 4, 'rows' => 1 )
		);
	}

	private function register_add_wc_add_to_cart(): void {
		$this->register_convenience_tool(
			'add-wc-add-to-cart',
			__( 'Add WooCommerce Add to Cart', 'full-elementor-mcp' ),
			__( 'Adds a WooCommerce add-to-cart button widget (Pro + WooCommerce).', 'full-elementor-mcp' ),
			array(
				'product_id'  => array( 'type' => 'integer', 'description' => __( 'Product ID to link to.', 'full-elementor-mcp' ) ),
				'show_quantity' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Show quantity input.', 'full-elementor-mcp' ) ),
				'quantity'    => array( 'type' => 'number', 'description' => __( 'Default quantity.', 'full-elementor-mcp' ) ),
				'view'        => array( 'type' => 'string', 'enum' => array( '', 'stacked', 'inline' ), 'description' => __( 'Layout view.', 'full-elementor-mcp' ) ),
			),
			array(),
			'wc-add-to-cart',
			array()
		);
	}

	private function register_add_wc_cart(): void {
		$this->register_convenience_tool(
			'add-wc-cart',
			__( 'Add WooCommerce Cart', 'full-elementor-mcp' ),
			__( 'Adds the WooCommerce cart page widget (Pro + WooCommerce).', 'full-elementor-mcp' ),
			array(),
			array(),
			'woocommerce-cart',
			array()
		);
	}

	private function register_add_wc_checkout(): void {
		$this->register_convenience_tool(
			'add-wc-checkout',
			__( 'Add WooCommerce Checkout', 'full-elementor-mcp' ),
			__( 'Adds the WooCommerce checkout page widget (Pro + WooCommerce).', 'full-elementor-mcp' ),
			array(),
			array(),
			'woocommerce-checkout-page',
			array()
		);
	}

	private function register_add_wc_menu_cart(): void {
		$this->register_convenience_tool(
			'add-wc-menu-cart',
			__( 'Add WooCommerce Menu Cart', 'full-elementor-mcp' ),
			__( 'Adds a mini cart icon for the menu (Pro + WooCommerce).', 'full-elementor-mcp' ),
			array(
				'icon'            => array( 'type' => 'object', 'description' => __( 'Cart icon object.', 'full-elementor-mcp' ) ),
				'items_indicator' => array( 'type' => 'string', 'enum' => array( 'none', 'bubble', 'plain' ), 'description' => __( 'Items indicator style.', 'full-elementor-mcp' ) ),
				'hide_empty_indicator' => array( 'type' => 'string', 'enum' => array( 'yes', '' ), 'description' => __( 'Hide when cart is empty.', 'full-elementor-mcp' ) ),
				'alignment'       => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ), 'description' => __( 'Alignment.', 'full-elementor-mcp' ) ),
			),
			array(),
			'woocommerce-menu-cart',
			array()
		);
	}
}
