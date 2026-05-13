<?php
/**
 * Layout/container MCP abilities for Elementor.
 *
 * Registers 4 tools for adding containers, moving, removing,
 * and duplicating elements within Elementor page trees.
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the layout abilities.
 *
 * @since 1.0.0
 */
class Full_Full_Elementor_MCP_Layout_Abilities {

	/**
	 * @var Full_Full_Elementor_MCP_Data
	 */
	private $data;

	/**
	 * @var Full_Full_Elementor_MCP_Element_Factory
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Full_Full_Elementor_MCP_Data            $data    The data access layer.
	 * @param Full_Full_Elementor_MCP_Element_Factory $factory The element factory.
	 */
	public function __construct( Full_Full_Elementor_MCP_Data $data, Full_Full_Elementor_MCP_Element_Factory $factory ) {
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
			'full-elementor-mcp/add-container',
			'full-elementor-mcp/update-container',
			'full-elementor-mcp/update-element',
			'full-elementor-mcp/batch-update',
			'full-elementor-mcp/reorder-elements',
			'full-elementor-mcp/move-element',
			'full-elementor-mcp/remove-element',
			'full-elementor-mcp/duplicate-element',
			'full-elementor-mcp/wrap-element-in-container',
			'full-elementor-mcp/unwrap-element',
			'full-elementor-mcp/replace-element',
			'full-elementor-mcp/find-elements',
			'full-elementor-mcp/set-element-classes',
			'full-elementor-mcp/add-element-class',
			'full-elementor-mcp/remove-element-class',
		);
	}

	/**
	 * Registers all layout abilities.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		$this->register_add_container();
		$this->register_update_container();
		$this->register_update_element();
		$this->register_batch_update();
		$this->register_reorder_elements();
		$this->register_move_element();
		$this->register_remove_element();
		$this->register_duplicate_element();
		$this->register_wrap_element_in_container();
		$this->register_unwrap_element();
		$this->register_replace_element();
		$this->register_find_elements();
		$this->register_set_element_classes();
		$this->register_add_element_class();
		$this->register_remove_element_class();
	}

	/**
	 * Permission check for element editing.
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
	// add-container
	// -------------------------------------------------------------------------

	private function register_add_container(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/add-container',
			array(
				'label'               => __( 'Add Container', 'full-elementor-mcp' ),
				'description'         => __( 'Adds a container to a page. Supports both flex (default) and grid layouts via container_type. Omit parent_id for top-level, or provide a parent container ID for nesting. Flex tips: Use flex_direction=row for side-by-side children, flex_wrap=wrap for wrapping, flex_justify_content for main-axis alignment (e.g. space-between, center), flex_align_items for cross-axis alignment. (The shorthand justify_content / align_items are also accepted and remapped to flex_justify_content / flex_align_items.) Grid tips: Set container_type=grid with grid_columns_grid, grid_rows_grid, grid_gaps. Background: set background_background=classic and background_color=#hex. Border: set border_border=solid, border_width, border_color. Also supports min_height, overflow, html_tag, padding, margin, position, z_index, animation.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_add_container' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'parent_id' => array(
							'type'        => 'string',
							'description' => __( 'Parent container ID for nesting. Omit for top-level.', 'full-elementor-mcp' ),
						),
						'position'  => array(
							'type'        => 'integer',
							'description' => __( 'Insert position. -1 = append (default).', 'full-elementor-mcp' ),
						),
						'settings'  => array(
							'type'        => 'object',
							'description' => __( 'Container settings: flex_direction, flex_wrap, flex_justify_content, flex_align_items, gap, content_width, padding, margin, background, border, etc. (Unprefixed justify_content / align_items / align_content are accepted and remapped to the flex_-prefixed keys.)', 'full-elementor-mcp' ),
						),
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
	 * Executes the add-container ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_add_container( $input ) {
		$post_id   = absint( $input['post_id'] ?? 0 );
		$parent_id = sanitize_text_field( $input['parent_id'] ?? '' );
		$position  = intval( $input['position'] ?? -1 );
		$settings  = $input['settings'] ?? array();

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// When nesting inside a parent, mark as inner container.
		$container = $this->factory->create_container( $settings );
		if ( ! empty( $parent_id ) ) {
			$container['isInner'] = true;
		}

		$inserted = $this->data->insert_element( $page_data, $parent_id, $container, $position );

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

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'element_id' => $container['id'],
			'post_id'    => $post_id,
		);
	}

	// -------------------------------------------------------------------------
	// update-container
	// -------------------------------------------------------------------------

	private function register_update_container(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/update-container',
			array(
				'label'               => __( 'Update Container', 'full-elementor-mcp' ),
				'description'         => __( 'Updates settings on an existing container. Settings are merged (partial update). Supports all container controls: flex_direction, flex_justify_content, flex_align_items, flex_wrap, flex_align_content, gap, content_width, min_height, overflow, html_tag, container_type, grid controls, background (set background_background=classic first), border (set border_border=solid first), border_radius, box_shadow, padding, margin, position, z_index, animation, shape dividers, etc. (The unprefixed justify_content / align_items / align_content are accepted and remapped to the flex_-prefixed keys.)', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_update_container' ),
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
							'description' => __( 'The container element ID.', 'full-elementor-mcp' ),
						),
						'settings'   => array(
							'type'        => 'object',
							'description' => __( 'Partial settings to merge into the container.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'settings' ),
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
	 * Executes the update-container ability.
	 *
	 * @since 1.1.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_update_container( $input ) {
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

		$element = $this->data->find_element_by_id( $page_data, $element_id );

		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		if ( 'container' !== ( $element['elType'] ?? '' ) ) {
			return new \WP_Error( 'not_container', __( 'Element is not a container. Use update-widget for widgets.', 'full-elementor-mcp' ) );
		}

		$updated = $this->data->update_element_settings( $page_data, $element_id, $settings );

		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update container settings.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// update-element (universal — works for both containers and widgets)
	// -------------------------------------------------------------------------

	private function register_update_element(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/update-element',
			array(
				'label'               => __( 'Update Element', 'full-elementor-mcp' ),
				'description'         => __( 'Updates settings on any element (container or widget). Settings are merged (partial update). Works for all element types — no need to know if the target is a container or widget.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_update_element' ),
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
							'description' => __( 'The element ID (container or widget).', 'full-elementor-mcp' ),
						),
						'settings'   => array(
							'type'        => 'object',
							'description' => __( 'Partial settings to merge into the element.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'element_id'  => array( 'type' => 'string' ),
						'element_type' => array( 'type' => 'string' ),
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

	public function execute_update_element( $input ) {
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

		$element = $this->data->find_element_by_id( $page_data, $element_id );

		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		$updated = $this->data->update_element_settings( $page_data, $element_id, $settings );

		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update element settings.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'      => true,
			'element_id'   => $element_id,
			'element_type' => $element['elType'] ?? 'unknown',
		);
	}

	// -------------------------------------------------------------------------
	// batch-update
	// -------------------------------------------------------------------------

	private function register_batch_update(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/batch-update',
			array(
				'label'               => __( 'Batch Update Elements', 'full-elementor-mcp' ),
				'description'         => __( 'Updates multiple elements in a single save operation. Each operation specifies an element_id and settings to merge. Much more efficient than calling update-element multiple times.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_batch_update' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'operations' => array(
							'type'        => 'array',
							'description' => __( 'Array of update operations.', 'full-elementor-mcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'element_id' => array( 'type' => 'string', 'description' => __( 'Element ID to update.', 'full-elementor-mcp' ) ),
									'settings'   => array( 'type' => 'object', 'description' => __( 'Settings to merge.', 'full-elementor-mcp' ) ),
								),
								'required'   => array( 'element_id', 'settings' ),
							),
						),
					),
					'required'   => array( 'post_id', 'operations' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'updated'  => array( 'type' => 'integer' ),
						'failed'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
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

	public function execute_batch_update( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$operations = $input['operations'] ?? array();

		if ( ! $post_id || empty( $operations ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and operations are required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$updated_count = 0;
		$failed        = array();

		foreach ( $operations as $op ) {
			$eid      = sanitize_text_field( $op['element_id'] ?? '' );
			$settings = $op['settings'] ?? array();

			if ( empty( $eid ) || empty( $settings ) ) {
				$failed[] = array( 'element_id' => $eid, 'reason' => 'missing element_id or settings' );
				continue;
			}

			$element = $this->data->find_element_by_id( $page_data, $eid );

			if ( null === $element ) {
				$failed[] = array( 'element_id' => $eid, 'reason' => 'element not found' );
				continue;
			}

			$ok = $this->data->update_element_settings( $page_data, $eid, $settings );

			if ( $ok ) {
				$updated_count++;
			} else {
				$failed[] = array( 'element_id' => $eid, 'reason' => 'update failed' );
			}
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => empty( $failed ),
			'updated' => $updated_count,
			'failed'  => $failed,
		);
	}

	// -------------------------------------------------------------------------
	// reorder-elements
	// -------------------------------------------------------------------------

	private function register_reorder_elements(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/reorder-elements',
			array(
				'label'               => __( 'Reorder Elements', 'full-elementor-mcp' ),
				'description'         => __( 'Reorders the children of a container by providing an ordered array of element IDs. By default any children whose IDs are NOT in the list are appended to the end (preserving them); pass strict=true to refuse the operation if the list is incomplete.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_reorder_elements' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'container_id' => array(
							'type'        => 'string',
							'description' => __( 'The parent container element ID.', 'full-elementor-mcp' ),
						),
						'element_ids'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Ordered array of child element IDs in the desired order.', 'full-elementor-mcp' ),
						),
						'strict'       => array(
							'type'        => 'boolean',
							'description' => __( 'If true, every direct child must be present in element_ids or the call fails. Default: false.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'container_id', 'element_ids' ),
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

	public function execute_reorder_elements( $input ) {
		$post_id      = absint( $input['post_id'] ?? 0 );
		$container_id = sanitize_text_field( $input['container_id'] ?? '' );
		$element_ids  = $input['element_ids'] ?? array();
		$strict       = ! empty( $input['strict'] );

		if ( ! $post_id || empty( $container_id ) || empty( $element_ids ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id, container_id, and element_ids are required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$container = $this->data->find_element_by_id( $page_data, $container_id );

		if ( null === $container ) {
			return new \WP_Error( 'element_not_found', __( 'Container not found.', 'full-elementor-mcp' ) );
		}

		if ( 'container' !== ( $container['elType'] ?? '' ) && ! Full_Full_Elementor_MCP_Element_Factory::is_atomic_container_type( $container['elType'] ?? '' ) ) {
			return new \WP_Error( 'not_container', __( 'Element is not a container.', 'full-elementor-mcp' ) );
		}

		$children = $container['elements'] ?? array();

		// Build lookup of children by ID.
		$children_by_id = array();
		foreach ( $children as $child ) {
			$children_by_id[ $child['id'] ] = $child;
		}

		// Validate all IDs are actual children.
		foreach ( $element_ids as $eid ) {
			if ( ! isset( $children_by_id[ $eid ] ) ) {
				return new \WP_Error(
					'invalid_element_id',
					sprintf( __( 'Element "%s" is not a direct child of the container.', 'full-elementor-mcp' ), $eid )
				);
			}
		}

		// Build reordered children array.
		$reordered = array();
		foreach ( $element_ids as $eid ) {
			$reordered[] = $children_by_id[ $eid ];
			unset( $children_by_id[ $eid ] );
		}

		// Strict mode: refuse the call if any child was omitted.
		if ( $strict && ! empty( $children_by_id ) ) {
			return new \WP_Error(
				'incomplete_order',
				sprintf(
					/* translators: %s: comma-separated list of missing element IDs */
					__( 'Strict reorder requires every child ID. Missing from element_ids: %s.', 'full-elementor-mcp' ),
					implode( ', ', array_keys( $children_by_id ) )
				)
			);
		}

		// Append any children not in the provided list (preserve them at end).
		foreach ( $children_by_id as $remaining ) {
			$reordered[] = $remaining;
		}

		// Apply reorder.
		$applied = $this->reorder_children( $page_data, $container_id, $reordered );

		if ( ! $applied ) {
			return new \WP_Error( 'reorder_failed', __( 'Failed to reorder elements.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	/**
	 * Recursively finds a container and replaces its children array.
	 *
	 * @param array  &$data         The page data tree (by reference).
	 * @param string $container_id  The container element ID.
	 * @param array  $new_children  The reordered children array.
	 * @return bool
	 */
	private function reorder_children( array &$data, string $container_id, array $new_children ): bool {
		foreach ( $data as &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $container_id ) {
				$item['elements'] = $new_children;
				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->reorder_children( $item['elements'], $container_id, $new_children ) ) {
					return true;
				}
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// move-element
	// -------------------------------------------------------------------------

	private function register_move_element(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/move-element',
			array(
				'label'               => __( 'Move Element', 'full-elementor-mcp' ),
				'description'         => __( 'Moves an element to a new parent container and/or position within the page tree.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_move_element' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array(
							'type'        => 'integer',
							'description' => __( 'The post/page ID.', 'full-elementor-mcp' ),
						),
						'element_id'       => array(
							'type'        => 'string',
							'description' => __( 'The element ID to move.', 'full-elementor-mcp' ),
						),
						'target_parent_id' => array(
							'type'        => 'string',
							'description' => __( 'Target parent container ID. Empty string for top-level.', 'full-elementor-mcp' ),
						),
						'position'         => array(
							'type'        => 'integer',
							'description' => __( 'Position within target parent. -1 = append.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'target_parent_id', 'position' ),
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
	 * Executes the move-element ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_move_element( $input ) {
		$post_id          = absint( $input['post_id'] ?? 0 );
		$element_id       = sanitize_text_field( $input['element_id'] ?? '' );
		$target_parent_id = sanitize_text_field( $input['target_parent_id'] ?? '' );
		$position         = intval( $input['position'] ?? -1 );

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// Find the element first.
		$element = $this->data->find_element_by_id( $page_data, $element_id );

		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		// Validate target parent exists BEFORE removing the element.
		// Without this check a missing-parent insert leaves the element
		// orphaned in memory and the saved tree has the element deleted.
		if ( ! empty( $target_parent_id ) ) {
			$target_parent = $this->data->find_element_by_id( $page_data, $target_parent_id );
			if ( null === $target_parent ) {
				return new \WP_Error(
					'target_parent_not_found',
					sprintf(
						/* translators: %s: target parent element ID */
						__( 'Target parent "%s" not found. Move aborted; nothing was changed.', 'full-elementor-mcp' ),
						$target_parent_id
					)
				);
			}
			// Disallow moving an element into itself or its own descendants.
			if ( $target_parent_id === $element_id || $this->data->find_element_by_id( $element['elements'] ?? array(), $target_parent_id ) ) {
				return new \WP_Error(
					'invalid_target',
					__( 'Cannot move an element into itself or one of its descendants.', 'full-elementor-mcp' )
				);
			}
		}

		// Remove from current position.
		$removed = $this->data->remove_element( $page_data, $element_id );

		if ( ! $removed ) {
			return new \WP_Error( 'remove_failed', __( 'Failed to remove element from current position.', 'full-elementor-mcp' ) );
		}

		// Insert at new position.
		$inserted = $this->data->insert_element( $page_data, $target_parent_id, $element, $position );

		if ( ! $inserted ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to insert element at target position.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// remove-element
	// -------------------------------------------------------------------------

	private function register_remove_element(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/remove-element',
			array(
				'label'               => __( 'Remove Element', 'full-elementor-mcp' ),
				'description'         => __( 'Removes an element and all its children from a page.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_remove_element' ),
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
							'description' => __( 'The element ID to remove.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id' ),
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

	/**
	 * Executes the remove-element ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_remove_element( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$removed = $this->data->remove_element( $page_data, $element_id );

		if ( ! $removed ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// duplicate-element
	// -------------------------------------------------------------------------

	private function register_duplicate_element(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/duplicate-element',
			array(
				'label'               => __( 'Duplicate Element', 'full-elementor-mcp' ),
				'description'         => __( 'Duplicates an element (including all children) with fresh IDs. The duplicate is placed immediately after the original.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_duplicate_element' ),
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
							'description' => __( 'The element ID to duplicate.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'new_element_id' => array( 'type' => 'string' ),
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
	 * Executes the duplicate-element ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_duplicate_element( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );

		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$element = $this->data->find_element_by_id( $page_data, $element_id );

		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		// Deep-clone and reassign all IDs. Reserve the existing tree's IDs
		// first so the freshly generated ones cannot collide with siblings.
		Full_Full_Elementor_MCP_Id_Generator::reserve( $this->data->collect_ids( $page_data ) );
		$clone = $this->data->reassign_element_ids( $element );

		// Find parent and insert after original.
		$inserted = $this->insert_after( $page_data, $element_id, $clone );

		if ( ! $inserted ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to insert duplicate.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'new_element_id' => $clone['id'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Inserts an element immediately after a target element in the tree.
	 *
	 * @param array  &$data     The page data tree (by reference).
	 * @param string $target_id The element ID to insert after.
	 * @param array  $element   The element to insert.
	 * @return bool True if inserted successfully.
	 */
	private function insert_after( array &$data, string $target_id, array $element ): bool {
		foreach ( $data as $index => &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $target_id ) {
				array_splice( $data, $index + 1, 0, array( $element ) );
				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->insert_after( $item['elements'], $target_id, $element ) ) {
					return true;
				}
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// wrap-element-in-container
	// -------------------------------------------------------------------------

	private function register_wrap_element_in_container(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/wrap-element-in-container',
			array(
				'label'               => __( 'Wrap Element In Container', 'full-elementor-mcp' ),
				'description'         => __( 'Wraps an existing element with a new container at the same position. The original element becomes the only child of the new container, preserving its place in the parent. Pass settings to apply container settings (flex_direction, padding, etc.) on the wrapper.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_wrap_element_in_container' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array( 'type' => 'integer' ),
						'element_id'     => array( 'type' => 'string' ),
						'container_type' => array(
							'type'        => 'string',
							'enum'        => array( 'flex', 'grid' ),
							'description' => __( 'Layout type for the wrapper container. Default: flex.', 'full-elementor-mcp' ),
						),
						'settings'       => array(
							'type'        => 'object',
							'description' => __( 'Optional container settings to apply.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'container_id' => array( 'type' => 'string' ),
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

	public function execute_wrap_element_in_container( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );
		$cont_type  = sanitize_key( $input['container_type'] ?? 'flex' );
		$settings   = is_array( $input['settings'] ?? null ) ? $input['settings'] : array();

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$element = $this->data->find_element_by_id( $page_data, $element_id );
		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		// Build the wrapper container, embedding the original element as its sole child.
		if ( 'grid' === $cont_type ) {
			$settings = array_merge( array( 'container_type' => 'grid' ), $settings );
		}
		$wrapper = $this->factory->create_container( $settings, array( $element ) );

		// Replace the original element in-place with the wrapper.
		$replaced = $this->data->replace_element( $page_data, $element_id, $wrapper );
		if ( ! $replaced ) {
			return new \WP_Error( 'wrap_failed', __( 'Failed to wrap the element.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true, 'container_id' => $wrapper['id'] );
	}

	// -------------------------------------------------------------------------
	// unwrap-element
	// -------------------------------------------------------------------------

	private function register_unwrap_element(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/unwrap-element',
			array(
				'label'               => __( 'Unwrap Element', 'full-elementor-mcp' ),
				'description'         => __( 'Removes a container while keeping its children, splicing them into the parent at the container\'s position. Refuses to unwrap a top-level container (no parent to splice into) and refuses to unwrap a non-container element. Useful for "remove this wrapping div but keep what\'s inside".', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_unwrap_element' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id', 'element_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'moved_ids'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
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

	public function execute_unwrap_element( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$element = $this->data->find_element_by_id( $page_data, $element_id );
		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		$el_type = $element['elType'] ?? '';
		if ( 'container' !== $el_type && ! Full_Full_Elementor_MCP_Element_Factory::is_atomic_container_type( $el_type ) ) {
			return new \WP_Error( 'not_container', __( 'Only containers can be unwrapped.', 'full-elementor-mcp' ) );
		}

		$location = $this->data->find_element_parent( $page_data, $element_id );
		if ( null === $location ) {
			return new \WP_Error( 'element_not_found', __( 'Element location could not be resolved.', 'full-elementor-mcp' ) );
		}

		if ( null === $location['parent_id'] ) {
			return new \WP_Error(
				'top_level_container',
				__( 'Cannot unwrap a top-level container; there is no parent to splice the children into.', 'full-elementor-mcp' )
			);
		}

		$children  = $element['elements'] ?? array();
		$moved_ids = array();
		foreach ( $children as $child ) {
			if ( ! empty( $child['id'] ) ) {
				$moved_ids[] = $child['id'];
			}
		}

		// Remove the container itself, then splice its children into the parent
		// at the same index the container occupied.
		$parent     = $this->data->find_element_by_id( $page_data, $location['parent_id'] );
		$parent_idx = $location['index'];

		// Re-walk: swap the container with its children inline. We do this by
		// rebuilding the parent's `elements` array.
		$rebuilt = false;
		$walker  = function ( array &$nodes ) use ( &$walker, $element_id, $children, &$rebuilt ) {
			foreach ( $nodes as $idx => &$node ) {
				if ( isset( $node['id'] ) && $node['id'] === $element_id ) {
					array_splice( $nodes, $idx, 1, $children );
					$rebuilt = true;
					return;
				}
				if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
					$walker( $node['elements'] );
					if ( $rebuilt ) {
						return;
					}
				}
			}
		};
		$walker( $page_data );

		if ( ! $rebuilt ) {
			return new \WP_Error( 'unwrap_failed', __( 'Failed to unwrap the container.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true, 'moved_ids' => $moved_ids );
	}

	// -------------------------------------------------------------------------
	// replace-element
	// -------------------------------------------------------------------------

	private function register_replace_element(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/replace-element',
			array(
				'label'               => __( 'Replace Element', 'full-elementor-mcp' ),
				'description'         => __( 'Replaces an element in-place with a new element node. Useful for swapping a heading for a different widget, or substituting an entire subtree. The replacement must be a fully-formed element object ({elType, widgetType?, settings, elements?}) — IDs in the replacement are reassigned to avoid collisions.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_replace_element' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'element_id'  => array( 'type' => 'string' ),
						'replacement' => array(
							'type'        => 'object',
							'description' => __( 'New element node to install at the target position.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'replacement' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'new_element_id' => array( 'type' => 'string' ),
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

	public function execute_replace_element( $input ) {
		$post_id     = absint( $input['post_id'] ?? 0 );
		$element_id  = sanitize_text_field( $input['element_id'] ?? '' );
		$replacement = $input['replacement'] ?? null;

		if ( ! $post_id || empty( $element_id ) || ! is_array( $replacement ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id, element_id, and replacement are required.', 'full-elementor-mcp' ) );
		}

		if ( empty( $replacement['elType'] ) ) {
			return new \WP_Error( 'invalid_replacement', __( 'replacement.elType is required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		// Reassign IDs in the incoming subtree to prevent collisions.
		Full_Full_Elementor_MCP_Id_Generator::reserve( $this->data->collect_ids( $page_data ) );
		$replacement = $this->data->reassign_ids( array( $replacement ) );
		$replacement = $this->data->normalize_tree_containers( $replacement );
		$new_node    = $replacement[0];

		$replaced = $this->data->replace_element( $page_data, $element_id, $new_node );
		if ( ! $replaced ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true, 'new_element_id' => $new_node['id'] );
	}

	// -------------------------------------------------------------------------
	// find-elements
	// -------------------------------------------------------------------------

	private function register_find_elements(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/find-elements',
			array(
				'label'               => __( 'Find Elements', 'full-elementor-mcp' ),
				'description'         => __( 'Returns every element on a page matching the supplied criteria. Filter by widget_type / elType (exact match), css_class (must appear in _css_classes), or settings_contains (substring match across stringified settings). Combine filters with AND. Useful for AI agents that need to locate widgets to update without knowing IDs upfront.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_find_elements' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'           => array( 'type' => 'integer' ),
						'widget_type'       => array( 'type' => 'string' ),
						'el_type'           => array(
							'type' => 'string',
							'enum' => array( 'widget', 'container', 'section', 'column', 'e-flexbox', 'e-div-block' ),
						),
						'css_class'         => array( 'type' => 'string' ),
						'settings_contains' => array( 'type' => 'string' ),
						'limit'             => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'matches' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'count'   => array( 'type' => 'integer' ),
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

	public function execute_find_elements( $input ) {
		$post_id           = absint( $input['post_id'] ?? 0 );
		$widget_type       = sanitize_text_field( $input['widget_type'] ?? '' );
		$el_type           = sanitize_text_field( $input['el_type'] ?? '' );
		$css_class         = sanitize_text_field( $input['css_class'] ?? '' );
		$settings_contains = (string) ( $input['settings_contains'] ?? '' );
		$limit             = max( 1, min( 500, (int) ( $input['limit'] ?? 100 ) ) );

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'The post_id parameter is required.', 'full-elementor-mcp' ) );
		}

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$predicate = function ( array $element ) use ( $widget_type, $el_type, $css_class, $settings_contains ) {
			if ( '' !== $widget_type && ( $element['widgetType'] ?? '' ) !== $widget_type ) {
				return false;
			}
			if ( '' !== $el_type && ( $element['elType'] ?? '' ) !== $el_type ) {
				return false;
			}
			if ( '' !== $css_class ) {
				$classes = (string) ( $element['settings']['_css_classes'] ?? '' );
				$tokens  = preg_split( '/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY );
				if ( ! in_array( $css_class, $tokens, true ) ) {
					return false;
				}
			}
			if ( '' !== $settings_contains ) {
				$json = wp_json_encode( $element['settings'] ?? array() );
				if ( ! is_string( $json ) || false === stripos( $json, $settings_contains ) ) {
					return false;
				}
			}
			return true;
		};

		$matches = $this->data->find_elements_where( $page_data, $predicate );
		$matches = array_slice( $matches, 0, $limit );

		// Project to a compact response (drop nested elements to keep the
		// payload small; callers can fetch full subtree via export-page).
		$projected = array();
		foreach ( $matches as $m ) {
			$projected[] = array(
				'id'         => $m['id'] ?? '',
				'elType'     => $m['elType'] ?? '',
				'widgetType' => $m['widgetType'] ?? '',
				'settings'   => $m['settings'] ?? array(),
			);
		}

		return array( 'matches' => $projected, 'count' => count( $projected ) );
	}

	// -------------------------------------------------------------------------
	// set/add/remove element classes
	// -------------------------------------------------------------------------

	private function register_set_element_classes(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/set-element-classes',
			array(
				'label'               => __( 'Set Element CSS Classes', 'full-elementor-mcp' ),
				'description'         => __( 'Replaces the entire CSS class list (`_css_classes`) on an element. Pass `classes` as an array of class names or as a single space-separated string. Use add-element-class / remove-element-class for non-destructive edits.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_set_element_classes' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'classes'    => array(
							'oneOf' => array(
								array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
								array( 'type' => 'string' ),
							),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'classes' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'classes' => array( 'type' => 'string' ),
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

	public function execute_set_element_classes( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'full-elementor-mcp' ) );
		}

		$classes_in = $input['classes'] ?? null;
		if ( null === $classes_in ) {
			return new \WP_Error( 'missing_classes', __( 'classes is required.', 'full-elementor-mcp' ) );
		}

		$classes = $this->normalize_class_list( $classes_in );

		return $this->mutate_element_classes(
			$post_id,
			$element_id,
			function ( $unused ) use ( $classes ) {
				return $classes;
			}
		);
	}

	private function register_add_element_class(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/add-element-class',
			array(
				'label'               => __( 'Add Element CSS Class', 'full-elementor-mcp' ),
				'description'         => __( 'Adds one or more CSS classes to an element\'s `_css_classes` without removing existing classes. Duplicates are deduped.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_add_element_class' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'classes'    => array(
							'oneOf' => array(
								array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
								array( 'type' => 'string' ),
							),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'classes' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'classes' => array( 'type' => 'string' ),
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

	public function execute_add_element_class( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'full-elementor-mcp' ) );
		}

		$add = $this->normalize_class_list( $input['classes'] ?? '' );

		return $this->mutate_element_classes(
			$post_id,
			$element_id,
			function ( $existing ) use ( $add ) {
				$tokens = preg_split( '/\s+/', (string) $existing, -1, PREG_SPLIT_NO_EMPTY );
				foreach ( explode( ' ', $add ) as $cls ) {
					if ( '' !== $cls && ! in_array( $cls, $tokens, true ) ) {
						$tokens[] = $cls;
					}
				}
				return implode( ' ', $tokens );
			}
		);
	}

	private function register_remove_element_class(): void {
		full_elementor_mcp_register_ability(
			'full-elementor-mcp/remove-element-class',
			array(
				'label'               => __( 'Remove Element CSS Class', 'full-elementor-mcp' ),
				'description'         => __( 'Removes one or more CSS classes from an element\'s `_css_classes`. Missing classes are silently ignored (idempotent).', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'execute_callback'    => array( $this, 'execute_remove_element_class' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'classes'    => array(
							'oneOf' => array(
								array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
								array( 'type' => 'string' ),
							),
						),
					),
					'required'   => array( 'post_id', 'element_id', 'classes' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'classes' => array( 'type' => 'string' ),
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

	public function execute_remove_element_class( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );

		if ( ! $post_id || empty( $element_id ) ) {
			return new \WP_Error( 'missing_params', __( 'post_id and element_id are required.', 'full-elementor-mcp' ) );
		}

		$remove_str = $this->normalize_class_list( $input['classes'] ?? '' );
		$remove     = preg_split( '/\s+/', $remove_str, -1, PREG_SPLIT_NO_EMPTY );

		return $this->mutate_element_classes(
			$post_id,
			$element_id,
			function ( $existing ) use ( $remove ) {
				$tokens = preg_split( '/\s+/', (string) $existing, -1, PREG_SPLIT_NO_EMPTY );
				$kept   = array_values( array_diff( $tokens, $remove ) );
				return implode( ' ', $kept );
			}
		);
	}

	/**
	 * Normalizes either array or string input into a single space-separated
	 * sanitized class string.
	 *
	 * @param mixed $classes Array of class names or space-separated string.
	 * @return string
	 */
	private function normalize_class_list( $classes ): string {
		if ( is_array( $classes ) ) {
			$classes = implode( ' ', $classes );
		}
		$classes = (string) $classes;
		$tokens  = preg_split( '/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY );
		$clean   = array();
		foreach ( $tokens as $tok ) {
			$s = sanitize_html_class( $tok );
			if ( '' !== $s ) {
				$clean[] = $s;
			}
		}
		return implode( ' ', array_unique( $clean ) );
	}

	/**
	 * Loads the page, applies a transform to the element's `_css_classes`
	 * setting, and saves. Centralized to avoid repeating the load/save dance
	 * across set/add/remove.
	 *
	 * @param int      $post_id    The page ID.
	 * @param string   $element_id The element ID.
	 * @param callable $mutator    `function( string $existing ): string`.
	 * @return array|\WP_Error
	 */
	private function mutate_element_classes( int $post_id, string $element_id, callable $mutator ) {
		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$element = $this->data->find_element_by_id( $page_data, $element_id );
		if ( null === $element ) {
			return new \WP_Error( 'element_not_found', __( 'Element not found.', 'full-elementor-mcp' ) );
		}

		$existing = (string) ( $element['settings']['_css_classes'] ?? '' );
		$new      = (string) $mutator( $existing );

		$updated = $this->data->update_element_settings(
			$page_data,
			$element_id,
			array( '_css_classes' => $new )
		);
		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update element classes.', 'full-elementor-mcp' ) );
		}

		$result = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true, 'classes' => $new );
	}
}
