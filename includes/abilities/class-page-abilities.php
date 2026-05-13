<?php
/**
 * Page CRUD MCP abilities for Elementor.
 *
 * Registers 5 tools for creating, updating, clearing, importing,
 * and exporting Elementor pages.
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the page CRUD abilities.
 *
 * @since 1.0.0
 */
class Full_Elementor_MCP_Page_Abilities {

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
		return array(
			'full-elementor-mcp/create-page',
			'full-elementor-mcp/update-page-settings',
			'full-elementor-mcp/delete-page-content',
			'full-elementor-mcp/import-template',
			'full-elementor-mcp/export-page',
			'full-elementor-mcp/delete-page',
			'full-elementor-mcp/duplicate-page',
			'full-elementor-mcp/set-featured-image',
			'full-elementor-mcp/set-page-meta',
			'full-elementor-mcp/set-page-slug',
		);
	}

	/**
	 * Registers all page abilities.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		$this->register_create_page();
		$this->register_update_page_settings();
		$this->register_delete_page_content();
		$this->register_import_template();
		$this->register_export_page();
		$this->register_delete_page();
		$this->register_duplicate_page();
		$this->register_set_featured_image();
		$this->register_set_page_meta();
		$this->register_set_page_slug();
	}

	/**
	 * Permission check for page creation.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function check_create_permission(): bool {
		return current_user_can( 'publish_pages' ) || current_user_can( 'edit_pages' );
	}

	/**
	 * Permission check for page editing.
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

	/**
	 * Permission check for destructive page content deletion.
	 *
	 * Requires both edit and delete capabilities since this operation
	 * is destructive and removes all Elementor content from the page.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $input The input data.
	 * @return bool
	 */
	public function check_delete_permission( $input = null ): bool {
		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'delete_posts' ) ) {
			return false;
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'delete_post', $post_id ) ) {
				return false;
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// create-page
	// -------------------------------------------------------------------------

	private function register_create_page(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/create-page',
			array(
				'label'               => __( 'Create Elementor Page', 'full-elementor-mcp' ),
				'description'         => __( 'Creates a new WordPress page with Elementor enabled. Optionally provide initial element content.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_create_page' ),
				'permission_callback' => array( $this, 'check_create_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array(
							'type'        => 'string',
							'description' => __( 'Page title.', 'full-elementor-mcp' ),
						),
						'status'    => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'publish' ),
							'description' => __( 'Post status. Default: draft.', 'full-elementor-mcp' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'enum'        => array( 'page', 'post' ),
							'description' => __( 'Post type. Default: page.', 'full-elementor-mcp' ),
						),
						'template'  => array(
							'type'        => 'string',
							'description' => __( 'Elementor template slug.', 'full-elementor-mcp' ),
						),
						'content'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object' ),
							'description' => __( 'Initial element tree.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'title' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'edit_url'    => array( 'type' => 'string' ),
						'preview_url' => array( 'type' => 'string' ),
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
	 * Executes the create-page ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_create_page( $input ) {
		$title     = sanitize_text_field( $input['title'] ?? '' );
		$status    = sanitize_key( $input['status'] ?? 'draft' );
		$post_type = sanitize_key( $input['post_type'] ?? 'page' );

		if ( empty( $title ) ) {
			return new \WP_Error( 'missing_title', __( 'The title parameter is required.', 'full-elementor-mcp' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => $status,
				'post_type'   => $post_type,
				'meta_input'  => array(
					'_elementor_edit_mode'     => 'builder',
					'_elementor_template_type' => 'wp-' . $post_type,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set page template if provided.
		if ( ! empty( $input['template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $input['template'] ) );
		}

		// Save initial content if provided.
		if ( ! empty( $input['content'] ) && is_array( $input['content'] ) ) {
			// Reassign IDs and normalize legacy container flex shorthand keys
			// so caller-supplied trees never collide with already-generated
			// in-request IDs and never silently lose justify/align settings.
			$content     = $this->data->reassign_ids( $input['content'] );
			$content     = $this->data->normalize_tree_containers( $content );
			$save_result = $this->data->save_page_data( $post_id, $content );
		} else {
			// Save empty Elementor data to initialize.
			$save_result = $this->data->save_page_data( $post_id, array() );
		}

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$edit_url    = admin_url( 'post.php?post=' . $post_id . '&action=elementor' );
		$preview_url = get_permalink( $post_id );

		return array(
			'post_id'     => $post_id,
			'title'       => $title,
			'edit_url'    => $edit_url,
			'preview_url' => $preview_url ? $preview_url : '',
		);
	}

	// -------------------------------------------------------------------------
	// update-page-settings
	// -------------------------------------------------------------------------

	private function register_update_page_settings(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/update-page-settings',
			array(
				'label'               => __( 'Update Page Settings', 'full-elementor-mcp' ),
				'description'         => __( 'Updates page-level Elementor settings such as background, padding, custom CSS, and layout options.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_update_page_settings' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'settings' => array(
							'type'        => 'object',
							'description' => __( 'Page settings object.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
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

	public function execute_update_page_settings( $input ) {
		$post_id  = absint( $input['post_id'] ?? 0 );
		$settings = $input['settings'] ?? array();

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_settings( $post_id, $settings );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'post_id' => $post_id,
		);
	}

	// -------------------------------------------------------------------------
	// delete-page-content
	// -------------------------------------------------------------------------

	private function register_delete_page_content(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/delete-page-content',
			array(
				'label'               => __( 'Delete Page Content', 'full-elementor-mcp' ),
				'description'         => __( 'Clears all Elementor content from a page, resetting it to blank while keeping the page itself.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_delete_page_content' ),
				'permission_callback' => array( $this, 'check_delete_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
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

	public function execute_delete_page_content( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, array() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// import-template
	// -------------------------------------------------------------------------

	private function register_import_template(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/import-template',
			array(
				'label'               => __( 'Import Template', 'full-elementor-mcp' ),
				'description'         => __( 'Imports a JSON template structure into a page at an optional position.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_import_template' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'template_json' => array(
							'type'        => 'array',
							'description' => __( 'Elementor JSON element structure to import.', 'full-elementor-mcp' ),
							'items'       => array(
								'type' => 'object',
							),
						),
						'position'      => array(
							'type'        => 'integer',
							'description' => __( 'Insert position. -1 = append.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'template_json' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'elements_count' => array( 'type' => 'integer' ),
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

	public function execute_import_template( $input ) {
		$post_id       = absint( $input['post_id'] ?? 0 );
		$template_json = $input['template_json'] ?? array();
		$position      = intval( $input['position'] ?? -1 );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		if ( empty( $template_json ) ) {
			return new \WP_Error( 'missing_template', __( 'The template_json parameter is required.', 'full-elementor-mcp' ) );
		}

		$data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Reserve existing IDs so the freshly-generated ones don't collide
		// with anything already on the page, then assign new IDs and
		// normalize legacy container flex shorthand keys.
		Full_Elementor_MCP_Id_Generator::reserve( $this->data->collect_ids( $data ) );
		$template_json = $this->data->reassign_ids( $template_json );
		$template_json = $this->data->normalize_tree_containers( $template_json );
		$count         = $this->data->count_elements( $template_json );

		// Insert at position.
		if ( $position < 0 || $position >= count( $data ) ) {
			$data = array_merge( $data, $template_json );
		} else {
			array_splice( $data, $position, 0, $template_json );
		}

		$result = $this->data->save_page_data( $post_id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'        => true,
			'elements_count' => $count,
		);
	}

	// -------------------------------------------------------------------------
	// export-page
	// -------------------------------------------------------------------------

	private function register_export_page(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/export-page',
			array(
				'label'               => __( 'Export Page', 'full-elementor-mcp' ),
				'description'         => __( 'Exports a page\'s full Elementor data as a JSON structure that can be imported elsewhere.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_export_page' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'json' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
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

	public function execute_export_page( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		$data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array( 'json' => $data );
	}

	// -------------------------------------------------------------------------
	// delete-page
	// -------------------------------------------------------------------------

	private function register_delete_page(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/delete-page',
			array(
				'label'               => __( 'Delete Page', 'full-elementor-mcp' ),
				'description'         => __( 'Deletes a page (or post). By default the post is moved to Trash; pass force=true to bypass Trash and delete permanently. Returns success and whether the trash bin was used.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_delete_page' ),
				'permission_callback' => array( $this, 'check_delete_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'force'   => array(
							'type'        => 'boolean',
							'description' => __( 'If true, bypass Trash and delete permanently. Default: false.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'trashed'   => array( 'type' => 'boolean' ),
						'permanent' => array( 'type' => 'boolean' ),
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

	public function execute_delete_page( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		$force   = ! empty( $input['force'] );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'full-elementor-mcp' ) );
		}

		$result = wp_delete_post( $post_id, $force );

		if ( false === $result || null === $result ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete the post.', 'full-elementor-mcp' ) );
		}

		return array(
			'success'   => true,
			'trashed'   => ! $force,
			'permanent' => $force,
		);
	}

	// -------------------------------------------------------------------------
	// duplicate-page
	// -------------------------------------------------------------------------

	private function register_duplicate_page(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/duplicate-page',
			array(
				'label'               => __( 'Duplicate Page', 'full-elementor-mcp' ),
				'description'         => __( 'Creates a deep copy of an existing Elementor page including all element data, page settings, and Elementor-specific post-meta. The duplicate is saved as a draft by default with " (Copy)" appended to the title; pass title/status to override.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_duplicate_page' ),
				'permission_callback' => array( $this, 'check_create_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The source post/page ID to duplicate.', 'full-elementor-mcp' ),
						),
						'title'   => array(
							'type'        => 'string',
							'description' => __( 'Title for the duplicate. Default: "<original> (Copy)".', 'full-elementor-mcp' ),
						),
						'status'  => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
							'description' => __( 'Post status for the duplicate. Default: draft.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id' ),
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

	public function execute_duplicate_page( $input ) {
		$source_id = absint( $input['post_id'] ?? 0 );

		if ( ! $source_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		$source = get_post( $source_id );
		if ( ! $source ) {
			return new \WP_Error( 'post_not_found', __( 'Source post not found.', 'full-elementor-mcp' ) );
		}

		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'draft';
		$title  = isset( $input['title'] )
			? sanitize_text_field( $input['title'] )
			: $source->post_title . ' ' . __( '(Copy)', 'full-elementor-mcp' );

		$new_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_status'  => $status,
				'post_type'    => $source->post_type,
				'post_content' => $source->post_content,
				'post_excerpt' => $source->post_excerpt,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Copy all relevant Elementor meta. Reassign element IDs so the
		// duplicate's tree never collides with the source's IDs (which would
		// break per-element CSS classes shared between the two pages).
		$source_data = $this->data->get_page_data( $source_id );
		if ( ! is_wp_error( $source_data ) && is_array( $source_data ) ) {
			// Reserve the source IDs before reassigning so the freshly generated
			// duplicate IDs cannot collide with the source page's IDs (which
			// matter when the same request later touches the source).
			Full_Elementor_MCP_Id_Generator::reserve( $this->data->collect_ids( $source_data ) );
			$duplicate_data = $this->data->reassign_ids( $source_data );
			$save           = $this->data->save_page_data( $new_id, $duplicate_data );
			if ( is_wp_error( $save ) ) {
				wp_delete_post( $new_id, true );
				return $save;
			}
		} else {
			// Still need to flag the duplicate as Elementor-built.
			update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
		}

		// Mirror the most common Elementor meta keys.
		$copyable_meta = array(
			'_elementor_template_type',
			'_elementor_version',
			'_elementor_page_settings',
			'_elementor_conditions',
			'_elementor_extra_options',
			'_wp_page_template',
		);
		foreach ( $copyable_meta as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			if ( '' !== $value && null !== $value ) {
				update_post_meta( $new_id, $key, $value );
			}
		}

		// Copy featured image.
		$thumb = get_post_thumbnail_id( $source_id );
		if ( $thumb ) {
			set_post_thumbnail( $new_id, $thumb );
		}

		return array(
			'post_id'  => $new_id,
			'title'    => $title,
			'edit_url' => admin_url( 'post.php?post=' . $new_id . '&action=elementor' ),
		);
	}

	// -------------------------------------------------------------------------
	// set-featured-image
	// -------------------------------------------------------------------------

	private function register_set_featured_image(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/set-featured-image',
			array(
				'label'               => __( 'Set Featured Image', 'full-elementor-mcp' ),
				'description'         => __( 'Assigns or removes the WordPress featured image (post thumbnail) for a page. Pass attachment_id to set, or attachment_id=0 / null to remove.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_set_featured_image' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'attachment_id' => array(
							'type'        => 'integer',
							'description' => __( 'Media library attachment ID. Use 0 to remove the featured image.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'attachment_id' => array( 'type' => 'integer' ),
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

	public function execute_set_featured_image( $input ) {
		$post_id       = absint( $input['post_id'] ?? 0 );
		$attachment_id = absint( $input['attachment_id'] ?? 0 );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'full-elementor-mcp' ) );
		}

		if ( 0 === $attachment_id ) {
			delete_post_thumbnail( $post_id );
			return array( 'success' => true, 'attachment_id' => 0 );
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error( 'invalid_attachment', __( 'The attachment_id does not refer to a media library attachment.', 'full-elementor-mcp' ) );
		}

		$result = set_post_thumbnail( $post_id, $attachment_id );
		if ( false === $result ) {
			return new \WP_Error( 'set_failed', __( 'Failed to set the featured image.', 'full-elementor-mcp' ) );
		}

		return array( 'success' => true, 'attachment_id' => $attachment_id );
	}

	// -------------------------------------------------------------------------
	// set-page-meta
	// -------------------------------------------------------------------------

	private function register_set_page_meta(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/set-page-meta',
			array(
				'label'               => __( 'Set Page Meta', 'full-elementor-mcp' ),
				'description'         => __( 'Updates standard WordPress post fields on a page: title, excerpt, status, slug, post_password, comment_status, ping_status, menu_order. Only the fields you provide are touched. For Elementor page settings (page_title, custom_css, etc.) use update-page-settings.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_set_page_meta' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'title'          => array( 'type' => 'string' ),
						'excerpt'        => array( 'type' => 'string' ),
						'status'         => array(
							'type' => 'string',
							'enum' => array( 'draft', 'publish', 'pending', 'private', 'future' ),
						),
						'slug'           => array( 'type' => 'string' ),
						'post_password'  => array( 'type' => 'string' ),
						'comment_status' => array(
							'type' => 'string',
							'enum' => array( 'open', 'closed' ),
						),
						'ping_status'    => array(
							'type' => 'string',
							'enum' => array( 'open', 'closed' ),
						),
						'menu_order'     => array( 'type' => 'integer' ),
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

	public function execute_set_page_meta( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'full-elementor-mcp' ) );
		}

		$update = array( 'ID' => $post_id );

		$field_map = array(
			'title'          => 'post_title',
			'excerpt'        => 'post_excerpt',
			'status'         => 'post_status',
			'slug'           => 'post_name',
			'post_password'  => 'post_password',
			'comment_status' => 'comment_status',
			'ping_status'    => 'ping_status',
		);

		foreach ( $field_map as $api_key => $wp_key ) {
			if ( array_key_exists( $api_key, $input ) ) {
				$update[ $wp_key ] = is_string( $input[ $api_key ] )
					? sanitize_text_field( $input[ $api_key ] )
					: $input[ $api_key ];
			}
		}

		if ( array_key_exists( 'menu_order', $input ) ) {
			$update['menu_order'] = (int) $input['menu_order'];
		}

		// Nothing to update beyond ID.
		if ( count( $update ) <= 1 ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to update.', 'full-elementor-mcp' ) );
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// set-page-slug
	// -------------------------------------------------------------------------

	private function register_set_page_slug(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/set-page-slug',
			array(
				'label'               => __( 'Set Page Slug', 'full-elementor-mcp' ),
				'description'         => __( 'Sets the URL slug (post_name) for a page. The slug is sanitized via sanitize_title and WordPress will auto-suffix with -2/-3 if it would collide with another post of the same type. Returns the actual slug stored.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_set_page_slug' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'slug'    => array(
							'type'        => 'string',
							'description' => __( 'Desired slug. Will be sanitized.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'slug' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'slug'    => array( 'type' => 'string' ),
						'url'     => array( 'type' => 'string' ),
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

	public function execute_set_page_slug( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		$slug    = sanitize_title( $input['slug'] ?? '' );

		if ( ! $post_id || empty( $slug ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and slug are required.', 'full-elementor-mcp' ) );
		}

		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'full-elementor-mcp' ) );
		}

		$result = wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => $slug,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$saved_slug = get_post_field( 'post_name', $post_id );

		return array(
			'success' => true,
			'slug'    => $saved_slug,
			'url'     => get_permalink( $post_id ),
		);
	}

}
