<?php
/**
 * Template MCP abilities for Elementor.
 *
 * Registers 2 tools for saving and applying Elementor templates.
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the template abilities.
 *
 * @since 1.0.0
 */
class Full_Elementor_MCP_Template_Abilities {

	/**
	 * @var Full_Elementor_MCP_Data
	 */
	private $data;

	/**
	 * @var Full_Elementor_MCP_Element_Factory
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Full_Elementor_MCP_Data            $data    The data access layer.
	 * @param Full_Elementor_MCP_Element_Factory $factory The element factory.
	 */
	public function __construct( Full_Elementor_MCP_Data $data, Full_Elementor_MCP_Element_Factory $factory ) {
		$this->data    = $data;
		$this->factory = $factory;
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		$names = array(
			'full-elementor-mcp/save-as-template',
			'full-elementor-mcp/apply-template',
			'full-elementor-mcp/delete-template',
			'full-elementor-mcp/list-templates',
		);

		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			$names[] = 'full-elementor-mcp/create-theme-template';
			$names[] = 'full-elementor-mcp/set-template-conditions';
			$names[] = 'full-elementor-mcp/unset-template-conditions';
			$names[] = 'full-elementor-mcp/list-theme-templates';
			$names[] = 'full-elementor-mcp/list-dynamic-tags';
			$names[] = 'full-elementor-mcp/set-dynamic-tag';
			$names[] = 'full-elementor-mcp/create-popup';
			$names[] = 'full-elementor-mcp/set-popup-settings';
		}

		return $names;
	}

	/**
	 * Registers all template abilities.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		$this->register_save_as_template();
		$this->register_apply_template();
		$this->register_delete_template();
		$this->register_list_templates();

		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			$this->register_create_theme_template();
			$this->register_set_template_conditions();
			$this->register_unset_template_conditions();
			$this->register_list_theme_templates();
			$this->register_list_dynamic_tags();
			$this->register_set_dynamic_tag();
			$this->register_create_popup();
			$this->register_set_popup_settings();
		}
	}

	/**
	 * Permission check for template operations.
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

	// -------------------------------------------------------------------------
	// save-as-template
	// -------------------------------------------------------------------------

	private function register_save_as_template(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/save-as-template',
			array(
				'label'               => __( 'Save As Template', 'full-elementor-mcp' ),
				'description'         => __( 'Saves a page or a specific element as a reusable Elementor template.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_save_as_template' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array(
							'type'        => 'integer',
							'description' => __( 'The source post/page ID.', 'full-elementor-mcp' ),
						),
						'element_id'    => array(
							'type'        => 'string',
							'description' => __( 'Specific element ID to save. Omit to save the entire page.', 'full-elementor-mcp' ),
						),
						'title'         => array(
							'type'        => 'string',
							'description' => __( 'Template title.', 'full-elementor-mcp' ),
						),
						'template_type' => array(
							'type'        => 'string',
							'enum'        => array( 'page', 'section', 'container' ),
							'description' => __( 'Template type. Default: page.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'title' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'template_id' => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
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
	 * Executes the save-as-template ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_save_as_template( $input ) {
		$post_id       = absint( $input['post_id'] ?? 0 );
		$element_id    = sanitize_text_field( $input['element_id'] ?? '' );
		$title         = sanitize_text_field( $input['title'] ?? '' );
		$template_type = sanitize_key( $input['template_type'] ?? 'page' );

		if ( ! $post_id || empty( $title ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and title are required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// Get the elements to save.
		if ( ! empty( $element_id ) ) {
			$element = $this->data->find_element_by_id( $page_data, $element_id );
			if ( null === $element ) {
				return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
			}
			$elements_data = array( $element );
		} else {
			$elements_data = $page_data;
		}

		// Create the template post in Elementor's library CPT.
		$template_id = Full_Elementor_MCP_Safe_Writes::insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'elementor_library',
				'meta_input'  => array(
					'_elementor_edit_mode'     => 'builder',
					'_elementor_template_type' => $template_type,
				),
			),
			true
		);

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Set the template type taxonomy.
		$term_res = Full_Elementor_MCP_Safe_Writes::set_object_terms( $template_id, $template_type, 'elementor_library_type' );
		if ( is_wp_error( $term_res ) ) {
			return $term_res;
		}

		// Save the element data to the template.
		$save_result = $this->data->save_page_data( $template_id, $elements_data );

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		return array(
			'template_id' => $template_id,
			'title'       => $title,
		);
	}

	// -------------------------------------------------------------------------
	// apply-template
	// -------------------------------------------------------------------------

	private function register_apply_template(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/apply-template',
			array(
				'label'               => __( 'Apply Template', 'full-elementor-mcp' ),
				'description'         => __( 'Applies a saved Elementor template to a page at a given position, inserting its elements with fresh IDs.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_apply_template' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array(
							'type'        => 'integer',
							'description' => __( 'The target post/page ID.', 'full-elementor-mcp' ),
						),
						'template_id' => array(
							'type'        => 'integer',
							'description' => __( 'The template post ID to apply.', 'full-elementor-mcp' ),
						),
						'parent_id'   => array(
							'type'        => 'string',
							'description' => __( 'Parent container ID. Empty for top-level.', 'full-elementor-mcp' ),
						),
						'position'    => array(
							'type'        => 'integer',
							'description' => __( 'Insert position. -1 = append.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'template_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'elements_added' => array( 'type' => 'integer' ),
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
	 * Executes the apply-template ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_apply_template( $input ) {
		$post_id     = absint( $input['post_id'] ?? 0 );
		$template_id = absint( $input['template_id'] ?? 0 );
		$parent_id   = sanitize_text_field( $input['parent_id'] ?? '' );
		$position    = intval( $input['position'] ?? -1 );

		if ( ! $post_id || ! $template_id ) {
			return new \WP_Error( 'missing_params', __( 'post_id and template_id are required.', 'full-elementor-mcp' ) );
		}

		// Get the template elements.
		$template_data = $this->data->get_page_data( $template_id );

		if ( is_wp_error( $template_data ) ) {
			return $template_data;
		}

		if ( empty( $template_data ) ) {
			return new \WP_Error( 'empty_template', __( 'Template has no elements.', 'full-elementor-mcp' ) );
		}

		// Get the target page data.
		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// Reserve existing IDs to prevent collisions, reassign new IDs,
		// and normalize legacy container flex shorthand keys.
		Full_Elementor_MCP_Id_Generator::reserve( $this->data->collect_ids( $page_data ) );
		$template_data = $this->data->reassign_ids( $template_data );
		$template_data = $this->data->normalize_tree_containers( $template_data );
		$count         = $this->data->count_elements( $template_data );

		// Insert template elements.
		if ( ! empty( $parent_id ) ) {
			// Insert each template element into the parent.
			foreach ( $template_data as $i => $element ) {
				$pos      = ( $position >= 0 ) ? $position + $i : -1;
				$inserted = $this->data->insert_element( $page_data, $parent_id, $element, $pos );

				if ( ! $inserted ) {
					return new \WP_Error(
						'parent_not_found',
						sprintf(
							/* translators: %s: parent element ID */
							__( 'Parent element "%s" not found.', 'full-elementor-mcp' ),
							$parent_id
						)
					);
				}
			}
		} else {
			// Top-level insertion.
			if ( $position < 0 || $position >= count( $page_data ) ) {
				$page_data = array_merge( $page_data, $template_data );
			} else {
				array_splice( $page_data, $position, 0, $template_data );
			}
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'        => true,
			'elements_added' => $count,
		);
	}

	// ── Theme Builder Template Tools ─────────────────────────────────

	private function register_create_theme_template(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/create-theme-template',
			array(
				'label'               => __( 'Create Theme Template', 'full-elementor-mcp' ),
				'description'         => __( 'Creates a new Elementor Pro theme builder template (header, footer, single, archive, 404, etc.).', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_create_theme_template' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'         => array(
							'type'        => 'string',
							'description' => __( 'Template title.', 'full-elementor-mcp' ),
						),
						'template_type' => array(
							'type'        => 'string',
							'enum'        => array( 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'search-results', 'error-404', 'loop-item' ),
							'description' => __( 'Theme template type.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'title', 'template_type' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'title'    => array( 'type' => 'string' ),
						'edit_url' => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_create_theme_template( $input ) {
		$title         = sanitize_text_field( $input['title'] ?? '' );
		$template_type = sanitize_key( $input['template_type'] ?? '' );

		if ( empty( $title ) || empty( $template_type ) ) {
			return new \WP_Error( 'missing_params', __( 'title and template_type are required.', 'full-elementor-mcp' ) );
		}

		// Create the template post.
		$post_id = Full_Elementor_MCP_Safe_Writes::insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'elementor_library',
				'meta_input'  => array(
					'_elementor_edit_mode'     => 'builder',
					'_elementor_template_type' => $template_type,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$term_res = Full_Elementor_MCP_Safe_Writes::set_object_terms( $post_id, $template_type, 'elementor_library_type' );
		if ( is_wp_error( $term_res ) ) {
			return $term_res;
		}

		// Initialize with empty Elementor data.
		$save_res = $this->data->save_page_data( $post_id, array() );
		if ( is_wp_error( $save_res ) ) {
			return $save_res;
		}

		return array(
			'post_id'  => $post_id,
			'title'    => $title,
			'edit_url' => admin_url( "post.php?post={$post_id}&action=elementor" ),
		);
	}

	private function register_set_template_conditions(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/set-template-conditions',
			array(
				'label'               => __( 'Set Template Conditions', 'full-elementor-mcp' ),
				'description'         => __( 'Sets display conditions for a theme builder template (e.g., Entire Site, specific pages, post types).', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_set_template_conditions' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The template post ID.', 'full-elementor-mcp' ),
						),
						'conditions' => array(
							'type'        => 'array',
							'description' => __( 'Array of condition rules. Each is an array like ["include", "general"] for Entire Site, or ["include", "singular", "post"] for all posts.', 'full-elementor-mcp' ),
							'items'       => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
					'required'   => array( 'post_id', 'conditions' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_set_template_conditions( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$conditions = $input['conditions'] ?? array();

		if ( ! $post_id || empty( $conditions ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and conditions are required.', 'full-elementor-mcp' ) );
		}

		// Elementor Pro stores conditions in the meta key '_elementor_conditions'.
		$formatted = array();
		foreach ( $conditions as $condition ) {
			if ( is_array( $condition ) ) {
				$formatted[] = implode( '/', $condition );
			} elseif ( is_string( $condition ) ) {
				$formatted[] = $condition;
			}
		}

		$meta_res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_conditions', $formatted );
		if ( is_wp_error( $meta_res ) ) {
			return $meta_res;
		}

		// Bust Elementor Pro's modern Theme Builder conditions cache. The old
		// `elementor_pro_theme_builder_conditions` option key was removed in
		// Pro 3.11+; current Pro stores the cache in a transient and rebuilds
		// it on the next page render only when this transient is missing.
		delete_transient( 'elementor_theme_builder_conditions_cache' );
		delete_option( 'elementor_pro_theme_builder_conditions' );

		return array( 'success' => true );
	}

	// ── Dynamic Tags ─────────────────────────────────────────────────

	private function register_list_dynamic_tags(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/list-dynamic-tags',
			array(
				'label'               => __( 'List Dynamic Tags', 'full-elementor-mcp' ),
				'description'         => __( 'Lists all available Elementor Pro dynamic tags with their names, groups, and categories.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_list_dynamic_tags' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'group' => array(
							'type'        => 'string',
							'description' => __( 'Filter by tag group (e.g., "post", "site", "author", "media", "action", "woocommerce"). Omit for all.', 'full-elementor-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'tags'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'count' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_list_dynamic_tags( $input ) {
		$filter_group = sanitize_text_field( $input['group'] ?? '' );

		$dynamic_tags_manager = \Elementor\Plugin::instance()->dynamic_tags;
		if ( ! $dynamic_tags_manager ) {
			return new \WP_Error( 'no_dynamic_tags', __( 'Dynamic tags manager not available.', 'full-elementor-mcp' ) );
		}

		$tags_info = $dynamic_tags_manager->get_tags();
		$tags      = array();

		foreach ( $tags_info as $tag_name => $tag_info ) {
			if ( ! is_array( $tag_info ) || empty( $tag_info['instance'] ) ) {
				continue;
			}

			$tag_instance = $tag_info['instance'];
			$group        = method_exists( $tag_instance, 'get_group' ) ? $tag_instance->get_group() : '';

			if ( ! empty( $filter_group ) && $group !== $filter_group ) {
				continue;
			}

			$tags[] = array(
				'name'       => $tag_name,
				'title'      => method_exists( $tag_instance, 'get_title' ) ? $tag_instance->get_title() : $tag_name,
				'group'      => $group,
				'categories' => method_exists( $tag_instance, 'get_categories' ) ? $tag_instance->get_categories() : array(),
			);
		}

		return array(
			'tags'  => $tags,
			'count' => count( $tags ),
		);
	}

	private function register_set_dynamic_tag(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/set-dynamic-tag',
			array(
				'label'               => __( 'Set Dynamic Tag', 'full-elementor-mcp' ),
				'description'         => __( 'Sets a dynamic tag on a specific setting of an element. This makes the setting value dynamic (e.g., title becomes post title).', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_set_dynamic_tag' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array(
							'type'        => 'integer',
							'description' => __( 'The page/post ID.', 'full-elementor-mcp' ),
						),
						'element_id'  => array(
							'type'        => 'string',
							'description' => __( 'The element ID to modify.', 'full-elementor-mcp' ),
						),
						'setting_key' => array(
							'type'        => 'string',
							'description' => __( 'The setting key to make dynamic (e.g., "title", "url", "image").', 'full-elementor-mcp' ),
						),
						'tag_name'    => array(
							'type'        => 'string',
							'description' => __( 'The dynamic tag name (e.g., "post-title", "site-title", "post-featured-image").', 'full-elementor-mcp' ),
						),
						'tag_settings' => array(
							'type'        => 'object',
							'description' => __( 'Optional settings for the dynamic tag.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'setting_key', 'tag_name' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_set_dynamic_tag( $input ) {
		$post_id      = absint( $input['post_id'] ?? 0 );
		$element_id   = sanitize_text_field( $input['element_id'] ?? '' );
		$setting_key  = sanitize_text_field( $input['setting_key'] ?? '' );
		$tag_name     = sanitize_text_field( $input['tag_name'] ?? '' );
		$tag_settings = $input['tag_settings'] ?? array();

		if ( ! $post_id || empty( $element_id ) || empty( $setting_key ) || empty( $tag_name ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id, element_id, setting_key, and tag_name are required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// Find the element to read its current __dynamic__ settings.
		$element = $this->data->find_element_by_id( $page_data, $element_id );
		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		// Build the dynamic tag value in Elementor's canonical format. Modern
		// Elementor (3.10+) stores tag settings as a base64-encoded JSON string
		// inside the `settings` shortcode attribute; previous versions used a
		// urlencoded JSON. Try the official Manager helper first so we match
		// whatever the active Elementor version expects.
		$tag_id = wp_generate_password( 7, false, false );

		if ( class_exists( '\\Elementor\\Plugin' )
			&& isset( \Elementor\Plugin::$instance->dynamic_tags )
			&& method_exists( \Elementor\Plugin::$instance->dynamic_tags, 'tag_data_to_tag_text' )
		) {
			$encoded = \Elementor\Plugin::$instance->dynamic_tags->tag_data_to_tag_text(
				$tag_id,
				$tag_name,
				is_array( $tag_settings ) ? $tag_settings : array()
			);
		} else {
			$settings_json = wp_json_encode( is_array( $tag_settings ) ? $tag_settings : array() );
			$encoded       = '[elementor-tag id="' . $tag_id . '" name="' . $tag_name . '" settings="' . rawurlencode( $settings_json ) . '"]';
		}

		// Merge with existing __dynamic__ settings.
		$dynamic                 = $element['settings']['__dynamic__'] ?? array();
		$dynamic[ $setting_key ] = $encoded;

		// Use update_element_settings to write back (operates by reference on $page_data).
		$updated = $this->data->update_element_settings( $page_data, $element_id, array( '__dynamic__' => $dynamic ) );
		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update element settings.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	// ── Popup Builder ────────────────────────────────────────────────

	private function register_create_popup(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/create-popup',
			array(
				'label'               => __( 'Create Popup', 'full-elementor-mcp' ),
				'description'         => __( 'Creates a new Elementor Pro popup template.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_create_popup' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title' => array(
							'type'        => 'string',
							'description' => __( 'Popup title.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'title' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'title'    => array( 'type' => 'string' ),
						'edit_url' => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_create_popup( $input ) {
		$title = sanitize_text_field( $input['title'] ?? '' );

		if ( empty( $title ) ) {
			return new \WP_Error( 'missing_params', __( 'title is required.', 'full-elementor-mcp' ) );
		}

		$post_id = Full_Elementor_MCP_Safe_Writes::insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'elementor_library',
				'meta_input'  => array(
					'_elementor_edit_mode'     => 'builder',
					'_elementor_template_type' => 'popup',
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$term_res = Full_Elementor_MCP_Safe_Writes::set_object_terms( $post_id, 'popup', 'elementor_library_type' );
		if ( is_wp_error( $term_res ) ) {
			return $term_res;
		}
		$save_res = $this->data->save_page_data( $post_id, array() );
		if ( is_wp_error( $save_res ) ) {
			return $save_res;
		}

		return array(
			'post_id'  => $post_id,
			'title'    => $title,
			'edit_url' => admin_url( "post.php?post={$post_id}&action=elementor" ),
		);
	}

	private function register_set_popup_settings(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/set-popup-settings',
			array(
				'label'               => __( 'Set Popup Settings', 'full-elementor-mcp' ),
				'description'         => __( 'Configures popup triggers, timing, and display conditions for an Elementor Pro popup.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_set_popup_settings' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The popup post ID.', 'full-elementor-mcp' ),
						),
						'triggers'   => array(
							'type'        => 'object',
							'description' => __( 'Trigger settings: { "on_page_load": {"enabled": true, "delay": 3}, "on_scroll": {"enabled": true, "direction": "down", "offset": 50}, "on_click": {"enabled": true, "times": 1}, "on_exit_intent": {"enabled": true}, "on_inactivity": {"enabled": true, "time": 30} }.', 'full-elementor-mcp' ),
						),
						'conditions' => array(
							'type'        => 'array',
							'description' => __( 'Display conditions, same format as set-template-conditions.', 'full-elementor-mcp' ),
							'items'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						),
						'timing'     => array(
							'type'        => 'object',
							'description' => __( 'Timing rules: { "devices": ["desktop","tablet","mobile"], "show_after_x_page_views": 0, "show_after_x_sessions": 0, "show_up_to_x_times": 0, "url_contains": "", "url_not_contains": "" }.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_set_popup_settings( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$triggers   = $input['triggers'] ?? null;
		$conditions = $input['conditions'] ?? null;
		$timing     = $input['timing'] ?? null;

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_params', __( 'post_id is required.', 'full-elementor-mcp' ) );
		}

		// Elementor Pro popups store triggers / timing inside the document's
		// `_elementor_page_settings` JSON, NOT in dedicated post-meta keys.
		// Flatten the AI-friendly nested input into the flat `triggers__*` /
		// `timing__*` keys Elementor Pro's popup module reads.
		$flat_settings = array();

		if ( is_array( $triggers ) ) {
			foreach ( $triggers as $trigger_name => $trigger_cfg ) {
				if ( ! is_array( $trigger_cfg ) ) {
					continue;
				}
				foreach ( $trigger_cfg as $sub_key => $value ) {
					$flat_settings[ 'triggers__' . sanitize_key( $trigger_name ) . '__' . sanitize_key( $sub_key ) ] = $value;
				}
			}
		}

		if ( is_array( $timing ) ) {
			foreach ( $timing as $rule_name => $value ) {
				$flat_settings[ 'timing__' . sanitize_key( $rule_name ) ] = $value;
			}
		}

		if ( ! empty( $flat_settings ) ) {
			$save_settings = $this->data->save_page_settings( $post_id, $flat_settings );
			if ( is_wp_error( $save_settings ) ) {
				return $save_settings;
			}
		}

		if ( is_array( $conditions ) ) {
			$formatted = array();
			foreach ( $conditions as $condition ) {
				if ( is_array( $condition ) ) {
					$formatted[] = implode( '/', $condition );
				} elseif ( is_string( $condition ) ) {
					$formatted[] = $condition;
				}
			}
			$meta_res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_conditions', $formatted );
			if ( is_wp_error( $meta_res ) ) {
				return $meta_res;
			}

			delete_transient( 'elementor_theme_builder_conditions_cache' );
			delete_option( 'elementor_pro_theme_builder_conditions' );
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// delete-template
	// -------------------------------------------------------------------------

	private function register_delete_template(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/delete-template',
			array(
				'label'               => __( 'Delete Template', 'full-elementor-mcp' ),
				'description'         => __( 'Deletes an Elementor library template (saved page/section/widget/popup/theme template). Defaults to Trash; pass force=true to delete permanently. Refuses to operate on non-Elementor-library posts to avoid accidental page deletion (use delete-page for those).', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_delete_template' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_id' => array(
							'type'        => 'integer',
							'description' => __( 'Template post ID (must be elementor_library post type).', 'full-elementor-mcp' ),
						),
						'force'       => array(
							'type'        => 'boolean',
							'description' => __( 'If true, bypass Trash. Default: false.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'template_id' ),
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
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_delete_template( $input ) {
		$template_id = absint( $input['template_id'] ?? 0 );
		$force       = true === ( $input['force'] ?? false );

		if ( ! $template_id ) {
			return new \WP_Error( 'missing_template_id', __( 'The template_id parameter is required.', 'full-elementor-mcp' ) );
		}

		$post = get_post( $template_id );
		if ( ! $post ) {
			return new \WP_Error( 'template_not_found', __( 'Template not found.', 'full-elementor-mcp' ) );
		}

		if ( 'elementor_library' !== $post->post_type ) {
			return new \WP_Error(
				'not_a_template',
				__( 'The given post is not an Elementor library template. Use delete-page for regular pages.', 'full-elementor-mcp' )
			);
		}

		$result = Full_Elementor_MCP_Safe_Writes::delete_post( $template_id, $force );

		if ( false === $result || null === $result || is_wp_error( $result ) ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'delete_failed', __( 'Failed to delete the template.', 'full-elementor-mcp' ) );
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// list-templates
	// -------------------------------------------------------------------------

	private function register_list_templates(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/list-templates',
			array(
				'label'               => __( 'List Templates', 'full-elementor-mcp' ),
				'description'         => __( 'Lists Elementor library templates (saved sections, pages, widgets, popups, theme templates). Optionally filter by template_type and search by title.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_list_templates' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_type' => array(
							'type'        => 'string',
							'description' => __( 'Filter by template type (page, section, widget, popup, header, footer, single, archive, etc.). Omit for all types.', 'full-elementor-mcp' ),
						),
						'search'        => array(
							'type'        => 'string',
							'description' => __( 'Optional case-insensitive title substring to match.', 'full-elementor-mcp' ),
						),
						'limit'         => array(
							'type'        => 'integer',
							'description' => __( 'Max results to return. Default 50, max 200.', 'full-elementor-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'templates' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'count'     => array( 'type' => 'integer' ),
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

	public function execute_list_templates( $input ) {
		$template_type = sanitize_key( $input['template_type'] ?? '' );
		$search        = sanitize_text_field( $input['search'] ?? '' );
		$limit         = max( 1, min( 200, (int) ( $input['limit'] ?? 50 ) ) );

		$args = array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $template_type ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_elementor_template_type',
					'value' => $template_type,
				),
			);
		}

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new \WP_Query( $args );

		$results = array();
		foreach ( $query->posts as $tpl ) {
			$results[] = array(
				'id'            => $tpl->ID,
				'title'         => $tpl->post_title,
				'status'        => $tpl->post_status,
				'template_type' => get_post_meta( $tpl->ID, '_elementor_template_type', true ),
				'modified'      => $tpl->post_modified,
				'edit_url'      => admin_url( 'post.php?post=' . $tpl->ID . '&action=elementor' ),
			);
		}

		return array(
			'templates' => $results,
			'count'     => count( $results ),
		);
	}

	// -------------------------------------------------------------------------
	// unset-template-conditions (Pro)
	// -------------------------------------------------------------------------

	private function register_unset_template_conditions(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/unset-template-conditions',
			array(
				'label'               => __( 'Unset Template Conditions', 'full-elementor-mcp' ),
				'description'         => __( 'Removes display conditions from a theme template, reverting it to "no conditions" (the template will not be auto-applied anywhere). Pass conditions to remove only specific entries instead of clearing all.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_unset_template_conditions' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'Theme template post ID.', 'full-elementor-mcp' ),
						),
						'conditions' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Optional list of slash-encoded condition strings to remove (e.g. "include/singular/post"). Omit to clear all conditions.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'remaining' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_unset_template_conditions( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'full-elementor-mcp' ) );
		}

		$remove = $input['conditions'] ?? null;

		if ( null === $remove ) {
			$del_res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_conditions' );
			if ( is_wp_error( $del_res ) ) {
				return $del_res;
			}
			$remaining = array();
		} else {
			$remove    = array_map( 'sanitize_text_field', (array) $remove );
			$existing  = (array) get_post_meta( $post_id, '_elementor_conditions', true );
			$remaining = array_values( array_diff( $existing, $remove ) );
			if ( empty( $remaining ) ) {
				$del_res = Full_Elementor_MCP_Safe_Writes::delete_post_meta( $post_id, '_elementor_conditions' );
				if ( is_wp_error( $del_res ) ) {
					return $del_res;
				}
			} else {
				$upd_res = Full_Elementor_MCP_Safe_Writes::update_post_meta( $post_id, '_elementor_conditions', $remaining );
				if ( is_wp_error( $upd_res ) ) {
					return $upd_res;
				}
			}
		}

		delete_transient( 'elementor_theme_builder_conditions_cache' );
		delete_option( 'elementor_pro_theme_builder_conditions' );

		return array( 'success' => true, 'remaining' => $remaining );
	}

	// -------------------------------------------------------------------------
	// list-theme-templates (Pro)
	// -------------------------------------------------------------------------

	private function register_list_theme_templates(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/list-theme-templates',
			array(
				'label'               => __( 'List Theme Templates', 'full-elementor-mcp' ),
				'description'         => __( 'Lists Elementor Pro Theme Builder templates (header, footer, single, archive, search-results, error-404). Returns each template\'s display conditions so AI agents can audit which template applies where.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_list_theme_templates' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_type' => array(
							'type'        => 'string',
							'enum'        => array( 'header', 'footer', 'single', 'archive', 'search-results', 'error-404', 'loop-item' ),
							'description' => __( 'Optional filter by Theme Builder template type.', 'full-elementor-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'templates' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'count'     => array( 'type' => 'integer' ),
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

	public function execute_list_theme_templates( $input ) {
		$type            = sanitize_key( $input['template_type'] ?? '' );
		$theme_types     = array( 'header', 'footer', 'single', 'archive', 'search-results', 'error-404', 'loop-item' );
		$filtered_types  = ! empty( $type ) ? array( $type ) : $theme_types;

		$args = array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'meta_query'     => array(
				array(
					'key'     => '_elementor_template_type',
					'value'   => $filtered_types,
					'compare' => 'IN',
				),
			),
		);

		$query  = new \WP_Query( $args );
		$result = array();
		foreach ( $query->posts as $tpl ) {
			$conditions = (array) get_post_meta( $tpl->ID, '_elementor_conditions', true );
			$result[]   = array(
				'id'            => $tpl->ID,
				'title'         => $tpl->post_title,
				'status'        => $tpl->post_status,
				'template_type' => get_post_meta( $tpl->ID, '_elementor_template_type', true ),
				'conditions'    => $conditions,
				'edit_url'      => admin_url( 'post.php?post=' . $tpl->ID . '&action=elementor' ),
			);
		}

		return array( 'templates' => $result, 'count' => count( $result ) );
	}
}
