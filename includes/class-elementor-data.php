<?php
/**
 * Elementor data access layer.
 *
 * Wraps Elementor internals to provide a clean API for reading and writing
 * Elementor page data, widget registrations, and element trees.
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access layer wrapping Elementor's internal APIs.
 *
 * @since 1.0.0
 */
class Full_Elementor_MCP_Data {

	/**
	 * Gets the Elementor document for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return \Elementor\Core\Base\Document|\WP_Error The document instance or WP_Error.
	 */
	public function get_document( int $post_id ) {
		$document = \Elementor\Plugin::$instance->documents->get( $post_id );

		if ( ! $document ) {
			return new \WP_Error(
				'document_not_found',
				sprintf(
					/* translators: %d: post ID */
					__( 'Elementor document not found for post ID %d.', 'full-elementor-mcp' ),
					$post_id
				)
			);
		}

		return $document;
	}

	/**
	 * Gets the element tree for an Elementor page.
	 *
	 * Tries the Elementor document API first, falls back to reading raw
	 * post meta if the document returns empty data (common in CLI contexts).
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return array|\WP_Error The elements data array or WP_Error.
	 */
	public function get_page_data( int $post_id ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$data = $document->get_elements_data();

		if ( is_array( $data ) && ! empty( $data ) ) {
			return $data;
		}

		// Fallback: read from raw post meta (handles CLI/proxy contexts).
		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! empty( $raw ) && is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * Gets the page-level settings for an Elementor document.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return array|\WP_Error The page settings array or WP_Error.
	 */
	public function get_page_settings( int $post_id ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return $document->get_settings();
	}

	/**
	 * Gets the document type for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return string|\WP_Error The document type string or WP_Error.
	 */
	public function get_document_type( int $post_id ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return get_post_meta( $post_id, '_elementor_template_type', true );
	}

	/**
	 * Gets all registered Elementor widget types.
	 *
	 * @since 1.0.0
	 *
	 * @return \Elementor\Widget_Base[] Array of widget instances keyed by widget name.
	 */
	public function get_registered_widgets(): array {
		return \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
	}

	/**
	 * Gets the controls for a specific widget type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $widget_type The widget type name.
	 * @return array|\WP_Error The controls array or WP_Error if widget not found.
	 */
	public function get_widget_controls( string $widget_type ) {
		$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget_type );

		if ( ! $widget ) {
			return new \WP_Error(
				'widget_not_found',
				sprintf(
					/* translators: %s: widget type name */
					__( 'Widget type "%s" not found.', 'full-elementor-mcp' ),
					$widget_type
				)
			);
		}

		return $widget->get_controls();
	}

	/**
	 * Recursively searches for an element by ID within an element tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data The element tree array.
	 * @param string $id   The element ID to find.
	 * @return array|null The element array if found, null otherwise.
	 */
	public function find_element_by_id( array $data, string $id ): ?array {
		foreach ( $data as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $id ) {
				return $element;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = $this->find_element_by_id( $element['elements'], $id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Saves page data using Elementor's native save mechanism.
	 *
	 * Tries document save() first (triggers CSS regeneration). If that fails
	 * (e.g. non-browser context like WP-CLI or REST API), falls back to direct
	 * meta update and manual CSS cache invalidation.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    The elements data array.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function save_page_data( int $post_id, array $data ) {
		// Tree validation immediately before write:
		if ( class_exists( 'Full_Elementor_MCP_Tree_Validator' ) ) {
			$tree_validation = Full_Elementor_MCP_Tree_Validator::validate_document(
				$data,
				array(
					'post_id'   => $post_id,
					'operation' => 'save_page_data',
				)
			);
			if ( is_wp_error( $tree_validation ) ) {
				return $tree_validation;
			}
		}

		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		// Low-level write guard: assert active mutation context & fencing immediately before physical write:
		if ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
			$context_guard = Full_Elementor_MCP_Mutation_Context::assert_active_write_context( "post:{$post_id}" );
			if ( is_wp_error( $context_guard ) ) {
				return $context_guard;
			}
			Full_Elementor_MCP_Mutation_Context::mark_write_started();
			Full_Elementor_MCP_Mutation_Context::increment_write_count();
		}

		// Attempt native Elementor save (handles CSS regen, cache busting).
		$result = $document->save( array( 'elements' => $data ) );

		if ( false === $result ) {
			// Fallback: direct meta write for non-browser contexts (CLI, REST proxy).
			$json = wp_json_encode( $data );

			if ( false === $json ) {
				return new \WP_Error(
					'json_encode_failed',
					__( 'Failed to encode element data as JSON.', 'full-elementor-mcp' )
				);
			}

			if ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
				$context_guard = Full_Elementor_MCP_Mutation_Context::assert_active_write_context( "post:{$post_id}" );
				if ( is_wp_error( $context_guard ) ) {
					return $context_guard;
				}
				Full_Elementor_MCP_Mutation_Context::increment_write_count();
			}

			update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

			// Verify the write actually persisted (filters may reject it).
			$saved = get_post_meta( $post_id, '_elementor_data', true );
			if ( ! is_string( $saved ) || '' === $saved ) {
				return new \WP_Error(
					'save_meta_failed',
					__( 'Failed to persist Elementor data to post meta.', 'full-elementor-mcp' )
				);
			}

			// Ensure Elementor meta flags are set.
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
			}

			// Invalidate Elementor CSS cache so it regenerates on next page view.
			delete_post_meta( $post_id, '_elementor_css' );

			$upload_dir = wp_get_upload_dir();
			$css_path   = $upload_dir['basedir'] . '/elementor/css/post-' . $post_id . '.css';
			if ( file_exists( $css_path ) ) {
				wp_delete_file( $css_path );
			}
		}

		// Reset the per-request ID dedupe pool so subsequent calls reseed
		// from the freshly-saved tree (avoids unbounded memory growth in
		// long-running CLI/agents).
		if ( method_exists( 'Full_Elementor_MCP_Id_Generator', 'reserve' ) ) {
			Full_Elementor_MCP_Id_Generator::reserve( $this->collect_ids( $data ) );
		}

		return true;
	}

	/**
	 * Saves page-level settings.
	 *
	 * Tries native Elementor save first, falls back to direct meta for
	 * non-browser contexts (WP-CLI, REST API proxy).
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id  The post ID.
	 * @param array $settings The page settings array.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function save_page_settings( int $post_id, array $settings ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		// Low-level write guard: assert active mutation context & fencing immediately before physical write:
		if ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
			$context_guard = Full_Elementor_MCP_Mutation_Context::assert_active_write_context( "post:{$post_id}" );
			if ( is_wp_error( $context_guard ) ) {
				return $context_guard;
			}
			Full_Elementor_MCP_Mutation_Context::mark_write_started();
			Full_Elementor_MCP_Mutation_Context::increment_write_count();
		}

		$result = $document->save( array( 'settings' => $settings ) );

		if ( false === $result ) {
			// Fallback: merge settings into existing page settings meta.
			$existing = get_post_meta( $post_id, '_elementor_page_settings', true );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$merged = array_merge( $existing, $settings );

			if ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
				$context_guard = Full_Elementor_MCP_Mutation_Context::assert_active_write_context( "post:{$post_id}" );
				if ( is_wp_error( $context_guard ) ) {
					return $context_guard;
				}
				Full_Elementor_MCP_Mutation_Context::increment_write_count();
			}

			update_post_meta( $post_id, '_elementor_page_settings', $merged );

			// Invalidate CSS cache.
			delete_post_meta( $post_id, '_elementor_css' );
		}

		return true;
	}

	/**
	 * Inserts an element into the page data tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data      The element tree (passed by reference).
	 * @param string $parent_id The parent element ID. Empty string for top-level.
	 * @param array  $element   The element to insert.
	 * @param int    $position  The insertion position (-1 = append).
	 * @return bool True if inserted, false if parent not found.
	 */
	public function insert_element( array &$data, string $parent_id, array $element, int $position = -1 ): bool {
		// Top-level insertion.
		if ( empty( $parent_id ) ) {
			if ( $position < 0 || $position >= count( $data ) ) {
				$data[] = $element;
			} else {
				array_splice( $data, $position, 0, array( $element ) );
			}
			return true;
		}

		// Find parent and insert.
		foreach ( $data as &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $parent_id ) {
				if ( ! isset( $item['elements'] ) ) {
					$item['elements'] = array();
				}

				if ( $position < 0 || $position >= count( $item['elements'] ) ) {
					$item['elements'][] = $element;
				} else {
					array_splice( $item['elements'], $position, 0, array( $element ) );
				}

				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->insert_element( $item['elements'], $parent_id, $element, $position ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Removes an element from the page data tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data       The element tree (passed by reference).
	 * @param string $element_id The element ID to remove.
	 * @return bool True if removed, false if not found.
	 */
	public function remove_element( array &$data, string $element_id ): bool {
		foreach ( $data as $index => &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $element_id ) {
				array_splice( $data, $index, 1 );
				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->remove_element( $item['elements'], $element_id ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Recursively reassigns fresh IDs to all elements in a tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array $elements The element tree.
	 * @return array The tree with new IDs.
	 */
	public function reassign_ids( array $elements ): array {
		foreach ( $elements as &$element ) {
			$element['id'] = Full_Elementor_MCP_Id_Generator::generate();

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->reassign_ids( $element['elements'] );
			}
		}

		return $elements;
	}

	/**
	 * Locates an element's parent container plus its index inside that
	 * container. Pass the top-level page tree as $data; the matched element
	 * may be at any depth. Returns null when the element is not found.
	 *
	 * @since 1.6.0
	 *
	 * @param array  $data       The element tree.
	 * @param string $element_id The element ID to look up.
	 * @return array{parent_id:?string,index:int,siblings:array}|null
	 */
	public function find_element_parent( array $data, string $element_id, ?string $parent_id = null ): ?array {
		foreach ( $data as $index => $item ) {
			if ( isset( $item['id'] ) && $item['id'] === $element_id ) {
				return array(
					'parent_id' => $parent_id,
					'index'     => $index,
					'siblings'  => $data,
				);
			}
			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				$found = $this->find_element_parent( $item['elements'], $element_id, $item['id'] ?? null );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Replaces an element in the tree with a new element node (in-place).
	 *
	 * @since 1.6.0
	 *
	 * @param array  $data       The element tree (by reference).
	 * @param string $element_id The element ID to replace.
	 * @param array  $new_node   The replacement element node.
	 * @return bool True if replaced, false if not found.
	 */
	public function replace_element( array &$data, string $element_id, array $new_node ): bool {
		foreach ( $data as $index => &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $element_id ) {
				$data[ $index ] = $new_node;
				return true;
			}
			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->replace_element( $item['elements'], $element_id, $new_node ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Returns every element matching a predicate. Used by the find-elements
	 * ability for AI-friendly tree querying.
	 *
	 * @since 1.6.0
	 *
	 * @param array    $data      The element tree.
	 * @param callable $predicate `function( array $element ): bool`.
	 * @return array[] Matching element nodes (deep copies preserve children).
	 */
	public function find_elements_where( array $data, callable $predicate ): array {
		$out = array();
		foreach ( $data as $item ) {
			if ( $predicate( $item ) ) {
				$out[] = $item;
			}
			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				$out = array_merge( $out, $this->find_elements_where( $item['elements'], $predicate ) );
			}
		}
		return $out;
	}

	/**
	 * Collects every element ID in a tree (including nested children).
	 *
	 * Used to reserve IDs in the per-request dedupe pool before generating
	 * new ones, so freshly-generated IDs cannot collide with existing tree
	 * elements (e.g. when inserting into an already-populated page).
	 *
	 * @since 1.6.0
	 *
	 * @param array $elements The element tree.
	 * @return string[] Flat list of every encountered element ID.
	 */
	public function collect_ids( array $elements ): array {
		$ids = array();
		foreach ( $elements as $element ) {
			if ( ! empty( $element['id'] ) && is_string( $element['id'] ) ) {
				$ids[] = $element['id'];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids = array_merge( $ids, $this->collect_ids( $element['elements'] ) );
			}
		}
		return $ids;
	}

	/**
	 * Normalizes container settings recursively across an entire tree.
	 *
	 * Imported / pasted templates frequently contain the unprefixed flex
	 * shorthand keys (`justify_content`, `align_items`, `align_content`).
	 * Without this pass, those keys are persisted but Elementor's CSS
	 * generator never reads them, so the imported containers render with
	 * default alignment on the front-end. (Matches the same fix
	 * `update_element_settings` performs on individual edits.)
	 *
	 * @since 1.6.0
	 *
	 * @param array $elements The element tree.
	 * @return array The same tree with container settings normalized.
	 */
	public function normalize_tree_containers( array $elements ): array {
		foreach ( $elements as &$element ) {
			if ( 'container' === ( $element['elType'] ?? '' ) && ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = Full_Elementor_MCP_Element_Factory::normalize_container_settings( $element['settings'] );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->normalize_tree_containers( $element['elements'] );
			}
		}
		return $elements;
	}

	/**
	 * Reassigns a fresh ID to a single element and all its children.
	 *
	 * @since 1.0.0
	 *
	 * @param array $element The element array.
	 * @return array The element with new IDs.
	 */
	public function reassign_element_ids( array $element ): array {
		$element['id'] = Full_Elementor_MCP_Id_Generator::generate();

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$element['elements'] = $this->reassign_ids( $element['elements'] );
		}

		return $element;
	}

	/**
	 * Recursively counts all elements in a tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array $elements The element tree.
	 * @return int Total count.
	 */
	public function count_elements( array $elements ): int {
		$count = count( $elements );

		foreach ( $elements as $element ) {
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += $this->count_elements( $element['elements'] );
			}
		}

		return $count;
	}

	/**
	 * Updates settings for a specific element in the tree.
	 *
	 * Modifies `$data` by reference. Returns true if element was found
	 * and updated, false if the element ID was not found.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data       The element tree (passed by reference).
	 * @param string $element_id The element ID to update.
	 * @param array  $settings   The settings to merge.
	 * @return bool True if updated, false if not found.
	 */
	public function update_element_settings( array &$data, string $element_id, array $settings ): bool {
		foreach ( $data as &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $element_id ) {
				if ( ! isset( $item['settings'] ) ) {
					$item['settings'] = array();
				}

				// Containers: rewrite MCP shorthand keys (`justify_content`,
				// `align_items`, `align_content`) to Elementor's prefixed flex
				// keys before merging. Without this, the values are saved
				// but never read by Elementor's CSS generator (issue #32).
				if ( 'container' === ( $item['elType'] ?? '' ) ) {
					$settings = Full_Elementor_MCP_Element_Factory::normalize_container_settings( $settings );
				}

				$item['settings'] = array_merge( $item['settings'], $settings );
				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->update_element_settings( $item['elements'], $element_id, $settings ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
