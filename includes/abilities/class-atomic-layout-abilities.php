<?php
/**
 * Atomic layout container MCP abilities for Elementor 4.0+.
 *
 * Registers tools for creating flexbox and div-block containers.
 * Only registers when Elementor >= 4.0 is active.
 *
 * @package Full_Elementor_MCP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements atomic layout abilities.
 *
 * @since 1.5.0
 */
class Full_Full_Elementor_MCP_Atomic_Layout_Abilities {

	/** @var Full_Full_Elementor_MCP_Data */
	private $data;

	/** @var Full_Full_Elementor_MCP_Element_Factory */
	private $factory;

	/** @var string[] */
	private $ability_names = array();

	/**
	 * @param Full_Full_Elementor_MCP_Data            $data    The data access layer.
	 * @param Full_Full_Elementor_MCP_Element_Factory $factory The element factory.
	 */
	public function __construct( Full_Full_Elementor_MCP_Data $data, Full_Full_Elementor_MCP_Element_Factory $factory ) {
		$this->data    = $data;
		$this->factory = $factory;
	}

	/** @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers all atomic layout abilities.
	 *
	 * Skips registration if Elementor < 4.0.
	 */
	public function register(): void {
		if ( ! Full_Full_Elementor_MCP_Atomic_Props::is_atomic_supported() ) {
			return;
		}

		$this->register_add_flexbox();
		$this->register_add_div_block();
		$this->register_detect_elementor_version();
	}

	/**
	 * @param array $input Input parameters.
	 * @return true|\WP_Error
	 */
	public function check_edit_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to edit posts.', 'full-elementor-mcp' ) );
		}

		$post_id = $input['post_id'] ?? 0;
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'full-elementor-mcp' ) );
		}

		return true;
	}

	// =========================================================================
	// Flexbox
	// =========================================================================

	private function register_add_flexbox(): void {
		$name                  = 'full-elementor-mcp/add-flexbox';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Add Flexbox', 'full-elementor-mcp' ),
				'description'         => __( 'Adds an Elementor 4.0 flexbox container. Layout properties (direction, justify, align, gap) are applied as local styles automatically. Use this instead of add-container for Elementor 4.0+ sites.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_add_flexbox' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'         => array( 'type' => 'integer', 'description' => __( 'The post/page ID.', 'full-elementor-mcp' ) ),
						'parent_id'       => array( 'type' => 'string', 'description' => __( 'Parent element ID. Empty for top-level.', 'full-elementor-mcp' ) ),
						'position'        => array( 'type' => 'integer', 'description' => __( 'Insert position. -1 = append.', 'full-elementor-mcp' ) ),
						'tag'             => array( 'type' => 'string', 'enum' => array( 'div', 'header', 'section', 'article', 'aside', 'footer' ), 'description' => __( 'HTML tag. Default: div.', 'full-elementor-mcp' ) ),
						'direction'       => array( 'type' => 'string', 'enum' => array( 'row', 'column', 'row-reverse', 'column-reverse' ), 'description' => __( 'Flex direction. Default: column.', 'full-elementor-mcp' ) ),
						'justify'         => array( 'type' => 'string', 'enum' => array( 'flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly' ), 'description' => __( 'Justify content.', 'full-elementor-mcp' ) ),
						'align'           => array( 'type' => 'string', 'enum' => array( 'flex-start', 'center', 'flex-end', 'stretch', 'baseline' ), 'description' => __( 'Align items.', 'full-elementor-mcp' ) ),
						'gap'             => array( 'type' => 'number', 'description' => __( 'Gap between children (px by default).', 'full-elementor-mcp' ) ),
						'gap_unit'        => array( 'type' => 'string', 'enum' => array( 'px', 'em', 'rem', '%', 'vw' ), 'description' => __( 'Gap unit. Default: px.', 'full-elementor-mcp' ) ),
						'wrap'            => array( 'type' => 'string', 'enum' => array( 'nowrap', 'wrap', 'wrap-reverse' ), 'description' => __( 'Flex wrap.', 'full-elementor-mcp' ) ),
						'css_id'          => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'full-elementor-mcp' ) ),
						'padding'         => array( 'type' => 'number', 'description' => __( 'Padding on all sides (px by default).', 'full-elementor-mcp' ) ),
						'background_color' => array( 'type' => 'string', 'description' => __( 'Background color (hex/rgba).', 'full-elementor-mcp' ) ),
						'min_height'      => array( 'type' => 'number', 'description' => __( 'Minimum height (px by default).', 'full-elementor-mcp' ) ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'element_id' => array( 'type' => 'string' ),
						'post_id'    => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_add_flexbox( $input ) {
		$post_id   = absint( $input['post_id'] ?? 0 );
		$parent_id = sanitize_text_field( $input['parent_id'] ?? '' );
		$position  = (int) ( $input['position'] ?? -1 );

		$settings = array();

		if ( ! empty( $input['tag'] ) ) {
			$settings['tag'] = Full_Full_Elementor_MCP_Atomic_Props::string( sanitize_text_field( $input['tag'] ) );
		}
		if ( ! empty( $input['css_id'] ) ) {
			$settings['_cssid'] = Full_Full_Elementor_MCP_Atomic_Props::string( sanitize_text_field( $input['css_id'] ) );
		}

		// Style props extracted from input.
		$style_params = array();
		$style_keys   = array( 'direction', 'flex_direction', 'justify', 'justify_content', 'align', 'align_items', 'wrap', 'flex_wrap', 'gap', 'gap_unit', 'row_gap', 'column_gap', 'padding', 'padding_unit', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'margin_top', 'margin_bottom', 'background_color', 'color', 'min_height', 'width', 'border_radius' );

		foreach ( $style_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$style_params[ $key ] = $input[ $key ];
			}
		}

		$element = $this->factory->create_flexbox( $settings, array(), $style_params );

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		if ( ! empty( $parent_id ) ) {
			// `insert_element` mutates `$page_data` by reference and returns bool.
			$inserted = $this->data->insert_element( $page_data, $parent_id, $element, $position );
			if ( ! $inserted ) {
				return new \WP_Error( 'parent_not_found', __( 'Parent container not found.', 'full-elementor-mcp' ) );
			}
		} else {
			// Top-level element.
			if ( -1 === $position || $position >= count( $page_data ) ) {
				$page_data[] = $element;
			} else {
				array_splice( $page_data, max( 0, $position ), 0, array( $element ) );
			}
		}

		$save = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $save ) ) {
			return $save;
		}

		return array(
			'element_id' => $element['id'],
			'post_id'    => $post_id,
		);
	}

	// =========================================================================
	// Div Block
	// =========================================================================

	private function register_add_div_block(): void {
		$name                  = 'full-elementor-mcp/add-div-block';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Add Div Block', 'full-elementor-mcp' ),
				'description'         => __( 'Adds an Elementor 4.0 div-block container (block flow layout). Use for non-flex containers.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_add_div_block' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer', 'description' => __( 'The post/page ID.', 'full-elementor-mcp' ) ),
						'parent_id'        => array( 'type' => 'string', 'description' => __( 'Parent element ID. Empty for top-level.', 'full-elementor-mcp' ) ),
						'position'         => array( 'type' => 'integer', 'description' => __( 'Insert position. -1 = append.', 'full-elementor-mcp' ) ),
						'tag'              => array( 'type' => 'string', 'enum' => array( 'div', 'header', 'section', 'article', 'aside', 'footer' ), 'description' => __( 'HTML tag. Default: div.', 'full-elementor-mcp' ) ),
						'css_id'           => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'full-elementor-mcp' ) ),
						'padding'          => array( 'type' => 'number', 'description' => __( 'Padding on all sides (px by default).', 'full-elementor-mcp' ) ),
						'background_color' => array( 'type' => 'string', 'description' => __( 'Background color (hex/rgba).', 'full-elementor-mcp' ) ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'element_id' => array( 'type' => 'string' ),
						'post_id'    => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_add_div_block( $input ) {
		$post_id   = absint( $input['post_id'] ?? 0 );
		$parent_id = sanitize_text_field( $input['parent_id'] ?? '' );
		$position  = (int) ( $input['position'] ?? -1 );

		$settings = array();

		if ( ! empty( $input['tag'] ) ) {
			$settings['tag'] = Full_Full_Elementor_MCP_Atomic_Props::string( sanitize_text_field( $input['tag'] ) );
		}
		if ( ! empty( $input['css_id'] ) ) {
			$settings['_cssid'] = Full_Full_Elementor_MCP_Atomic_Props::string( sanitize_text_field( $input['css_id'] ) );
		}

		$style_params = array();
		$style_keys   = array( 'padding', 'padding_unit', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'margin_top', 'margin_bottom', 'background_color', 'color', 'min_height', 'width', 'border_radius' );

		foreach ( $style_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$style_params[ $key ] = $input[ $key ];
			}
		}

		$element = $this->factory->create_div_block( $settings, array(), $style_params );

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		if ( ! empty( $parent_id ) ) {
			// `insert_element` mutates `$page_data` by reference and returns bool.
			$inserted = $this->data->insert_element( $page_data, $parent_id, $element, $position );
			if ( ! $inserted ) {
				return new \WP_Error( 'parent_not_found', __( 'Parent container not found.', 'full-elementor-mcp' ) );
			}
		} else {
			if ( -1 === $position || $position >= count( $page_data ) ) {
				$page_data[] = $element;
			} else {
				array_splice( $page_data, max( 0, $position ), 0, array( $element ) );
			}
		}

		$save = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $save ) ) {
			return $save;
		}

		return array(
			'element_id' => $element['id'],
			'post_id'    => $post_id,
		);
	}

	// =========================================================================
	// Detect version (always registers, even on < 4.0)
	// =========================================================================

	private function register_detect_elementor_version(): void {
		$name                  = 'full-elementor-mcp/detect-elementor-version';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Detect Elementor Version', 'full-elementor-mcp' ),
				'description'         => __( 'Returns the Elementor version and whether atomic elements (v4.0+) are supported. Call this first to decide whether to use legacy tools (add-heading, add-container) or atomic tools (add-atomic-heading, add-flexbox).', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => function () {
					$core_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'unknown';
					$pro_version  = defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null;

					return array(
						'elementor_version'     => $core_version,
						'elementor_pro_version' => $pro_version,
						'supports_atomic'       => Full_Full_Elementor_MCP_Atomic_Props::is_atomic_supported(),
						'recommended_mode'      => Full_Full_Elementor_MCP_Atomic_Props::is_atomic_supported() ? 'atomic' : 'legacy',
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' ) ? true : new \WP_Error( 'forbidden', __( 'Insufficient permissions.', 'full-elementor-mcp' ) );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'elementor_version'     => array( 'type' => 'string' ),
						'elementor_pro_version' => array( 'type' => 'string' ),
						'supports_atomic'       => array( 'type' => 'boolean' ),
						'recommended_mode'      => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}
}
