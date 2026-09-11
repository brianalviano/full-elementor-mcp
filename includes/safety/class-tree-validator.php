<?php
/**
 * Central Elementor document/tree validator.
 *
 * Provides recursive structural validation, document-wide ID uniqueness verification,
 * resource exhaustion guards (depth, node count, byte size), and settings value safety
 * before trees are persisted or restored during rollback.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates Elementor element trees recursively and fails closed on invalid structures.
 */
class Full_Elementor_MCP_Tree_Validator {

	/**
	 * Maximum allowed nesting depth for Elementor trees.
	 */
	public const MAX_TREE_DEPTH = 50;

	/**
	 * Maximum total element/node count in a single document.
	 */
	public const MAX_NODE_COUNT = 2000;

	/**
	 * Maximum serialized size of the element tree (8 MB).
	 */
	public const MAX_SERIALIZED_BYTES = 8388608;

	/**
	 * Recognized valid elType identifiers.
	 */
	private const VALID_EL_TYPES = array(
		'container',
		'widget',
		'section',
		'column',
		// Atomic element container types (Elementor 4.0+).
		'e-div-block',
		'e-flexbox',
		'e-tabs',
		'e-tabs-menu',
		'e-tab',
		'e-tabs-content-area',
		'e-tab-content',
		'e-form',
		'e-form-success-message',
		'e-form-error-message',
	);

	/**
	 * Validates an entire Elementor document tree before persistence or rollback.
	 *
	 * @param mixed                $elements Element tree array to validate.
	 * @param array<string, mixed> $context  Validation context (post_id, ability, operation).
	 * @return true|\WP_Error True on success, structured WP_Error on validation failure.
	 */
	public static function validate_document( mixed $elements, array $context = array() ) {
		if ( ! is_array( $elements ) ) {
			return new \WP_Error(
				'invalid_elementor_tree',
				__( 'Elementor document tree must be an array of element nodes.', 'full-elementor-mcp' ),
				array( 'actual_type' => gettype( $elements ) )
			);
		}

		// Fast-path: empty document tree is valid (e.g. clean/blank page).
		if ( empty( $elements ) ) {
			return true;
		}

		// 1. Serialization & byte size limit check.
		$json = wp_json_encode( $elements );
		if ( false === $json ) {
			return new \WP_Error(
				'tree_serialization_failed',
				__( 'Elementor document tree could not be serialized to JSON.', 'full-elementor-mcp' ),
				array( 'json_error' => json_last_error_msg() )
			);
		}

		$size_bytes = strlen( $json );
		$max_bytes  = (int) apply_filters( 'full_elementor_mcp_max_tree_size_bytes', self::MAX_SERIALIZED_BYTES, $context );
		if ( $size_bytes > $max_bytes ) {
			return new \WP_Error(
				'tree_size_exceeded',
				sprintf(
					/* translators: 1: actual size, 2: max size */
					__( 'Document tree size (%1$d bytes) exceeds the maximum allowed limit (%2$d bytes).', 'full-elementor-mcp' ),
					$size_bytes,
					$max_bytes
				),
				array(
					'size_bytes' => $size_bytes,
					'max_bytes'  => $max_bytes,
				)
			);
		}

		// 2. Recursive structural validation with state tracking.
		$seen_ids   = array();
		$node_count = 0;
		$max_nodes  = (int) apply_filters( 'full_elementor_mcp_max_tree_node_count', self::MAX_NODE_COUNT, $context );
		$max_depth  = (int) apply_filters( 'full_elementor_mcp_max_tree_depth', self::MAX_TREE_DEPTH, $context );

		foreach ( $elements as $index => $node ) {
			$path = 'elements[' . $index . ']';
			$res  = self::validate_node( $node, $path, 1, $seen_ids, $node_count, $max_depth, $max_nodes, $context );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		return true;
	}

	/**
	 * Reusable pre-write hook for future Phase 4 middleware.
	 *
	 * @param array<string, mixed> $elements Element tree.
	 * @param array<string, mixed> $context  Context arguments.
	 * @return true|\WP_Error
	 */
	public static function validate_before_write( array $elements, array $context = array() ) {
		return self::validate_document( $elements, array_merge( $context, array( 'stage' => 'before_write' ) ) );
	}

	/**
	 * Reusable post-mutation hook for future Phase 4 middleware.
	 *
	 * @param array<string, mixed> $elements Element tree.
	 * @param array<string, mixed> $context  Context arguments.
	 * @return true|\WP_Error
	 */
	public static function validate_after_mutation( array $elements, array $context = array() ) {
		return self::validate_document( $elements, array_merge( $context, array( 'stage' => 'after_mutation' ) ) );
	}

	/**
	 * Validates a single element ID format.
	 *
	 * Elementor typically uses 7-hex character strings (e.g. 'a1b2c3d'), but existing
	 * documents, imports, or custom widgets may use alphanumeric IDs with underscores or hyphens.
	 *
	 * @param mixed $id Element ID to validate.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_element_id( mixed $id ) {
		if ( ! is_string( $id ) || '' === trim( $id ) ) {
			return new \WP_Error(
				'invalid_element_id',
				__( 'Element ID must be a non-empty string.', 'full-elementor-mcp' ),
				array( 'id' => $id )
			);
		}

		$trimmed = trim( $id );
		$len     = strlen( $trimmed );

		if ( $len < 1 || $len > 64 ) {
			return new \WP_Error(
				'invalid_element_id',
				sprintf(
					/* translators: %d: length */
					__( 'Element ID length (%d) is invalid. Must be between 1 and 64 characters.', 'full-elementor-mcp' ),
					$len
				),
				array( 'id' => $trimmed, 'length' => $len )
			);
		}

		// Disallow control characters, quotes, HTML tags, or dangerous punctuation.
		if ( ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $trimmed ) ) {
			return new \WP_Error(
				'invalid_element_id',
				__( 'Element ID contains invalid characters. Only alphanumeric characters, hyphens, and underscores are allowed.', 'full-elementor-mcp' ),
				array( 'id' => $trimmed )
			);
		}

		return true;
	}

	/**
	 * Recursively validates a single element node in the tree.
	 *
	 * @param mixed                    $node       The node array.
	 * @param string                   $path       Current JSON/array path.
	 * @param int                      $depth      Current recursion depth (1-based).
	 * @param array<string, string>    $seen_ids   Hash map of seen element IDs => first path.
	 * @param int                      $node_count Counter of total nodes visited.
	 * @param int                      $max_depth  Maximum allowed depth.
	 * @param int                      $max_nodes  Maximum allowed node count.
	 * @param array<string, mixed>     $context    Execution context.
	 * @return true|\WP_Error
	 */
	private static function validate_node(
		mixed $node,
		string $path,
		int $depth,
		array &$seen_ids,
		int &$node_count,
		int $max_depth,
		int $max_nodes,
		array $context
	) {
		// 1. Must be an array (not scalar, not null, not object).
		if ( ! is_array( $node ) ) {
			return new \WP_Error(
				'invalid_elementor_tree',
				sprintf(
					/* translators: 1: path, 2: type */
					__( 'Element at path "%1$s" is malformed: expected array, got %2$s.', 'full-elementor-mcp' ),
					$path,
					gettype( $node )
				),
				array( 'path' => $path )
			);
		}

		// 2. Resource limits: depth.
		if ( $depth > $max_depth ) {
			return new \WP_Error(
				'tree_depth_exceeded',
				sprintf(
					/* translators: 1: depth, 2: max */
					__( 'Element tree depth (%1$d) at path "%2$s" exceeds maximum depth (%3$d).', 'full-elementor-mcp' ),
					$depth,
					$path,
					$max_depth
				),
				array( 'path' => $path, 'depth' => $depth, 'max_depth' => $max_depth )
			);
		}

		// 3. Resource limits: node count.
		$node_count++;
		if ( $node_count > $max_nodes ) {
			return new \WP_Error(
				'tree_node_limit_exceeded',
				sprintf(
					/* translators: %d: max */
					__( 'Document tree element count exceeds maximum limit of %d nodes.', 'full-elementor-mcp' ),
					$max_nodes
				),
				array( 'path' => $path, 'node_count' => $node_count, 'max_nodes' => $max_nodes )
			);
		}

		// 4. Element ID validation.
		if ( ! isset( $node['id'] ) ) {
			return new \WP_Error(
				'invalid_element_id',
				sprintf(
					/* translators: %s: path */
					__( 'Element at path "%s" is missing the required "id" field.', 'full-elementor-mcp' ),
					$path
				),
				array( 'path' => $path )
			);
		}

		$id_check = self::validate_element_id( $node['id'] );
		if ( is_wp_error( $id_check ) ) {
			return new \WP_Error(
				$id_check->get_error_code(),
				sprintf(
					/* translators: 1: path, 2: message */
					__( 'Invalid element ID at path "%1$s": %2$s', 'full-elementor-mcp' ),
					$path,
					$id_check->get_error_message()
				),
				array_merge( (array) $id_check->get_error_data(), array( 'path' => $path ) )
			);
		}

		$element_id = (string) $node['id'];

		// 5. Document-wide ID uniqueness check.
		if ( isset( $seen_ids[ $element_id ] ) ) {
			return new \WP_Error(
				'duplicate_element_id',
				sprintf(
					/* translators: 1: ID, 2: first path, 3: duplicate path */
					__( 'Duplicate element ID "%1$s" detected. First defined at "%2$s", duplicated at "%3$s".', 'full-elementor-mcp' ),
					$element_id,
					$seen_ids[ $element_id ],
					$path
				),
				array(
					'duplicate_id'   => $element_id,
					'first_path'     => $seen_ids[ $element_id ],
					'duplicate_path' => $path,
				)
			);
		}
		$seen_ids[ $element_id ] = $path;

		// 6. elType validation.
		if ( empty( $node['elType'] ) || ! is_string( $node['elType'] ) ) {
			return new \WP_Error(
				'invalid_element_type',
				sprintf(
					/* translators: %s: path */
					__( 'Element at path "%s" is missing or has empty "elType".', 'full-elementor-mcp' ),
					$path
				),
				array( 'path' => $path, 'element_id' => $element_id )
			);
		}

		$el_type = $node['elType'];
		if ( ! in_array( $el_type, self::VALID_EL_TYPES, true ) ) {
			return new \WP_Error(
				'invalid_element_type',
				sprintf(
					/* translators: 1: type, 2: path */
					__( 'Unsupported element type "%1$s" at path "%2$s".', 'full-elementor-mcp' ),
					esc_html( $el_type ),
					$path
				),
				array( 'path' => $path, 'element_id' => $element_id, 'elType' => $el_type )
			);
		}

		// 7. Widget-specific invariants.
		if ( 'widget' === $el_type ) {
			if ( empty( $node['widgetType'] ) || ! is_string( $node['widgetType'] ) ) {
				return new \WP_Error(
					'invalid_element_type',
					sprintf(
						/* translators: %s: path */
						__( 'Widget element at path "%s" is missing required "widgetType".', 'full-elementor-mcp' ),
						$path
					),
					array( 'path' => $path, 'element_id' => $element_id )
				);
			}

			// Invariant: Widgets must NEVER contain child elements.
			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) && ! empty( $node['elements'] ) ) {
				return new \WP_Error(
					'invalid_child_structure',
					sprintf(
						/* translators: 1: widget type, 2: path */
						__( 'Widget "%1$s" at path "%2$s" must not contain child elements.', 'full-elementor-mcp' ),
						esc_html( $node['widgetType'] ),
						$path
					),
					array( 'path' => $path, 'element_id' => $element_id, 'widgetType' => $node['widgetType'] )
				);
			}
		}

		// 8. Section/Column container invariants.
		if ( 'section' === $el_type && isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $child_idx => $child_node ) {
				$child_type = is_array( $child_node ) ? ( $child_node['elType'] ?? '' ) : '';
				if ( 'column' !== $child_type ) {
					return new \WP_Error(
						'invalid_child_structure',
						sprintf(
							/* translators: 1: child type, 2: path */
							__( 'Section element at "%2$s" can only contain column children, found "%1$s".', 'full-elementor-mcp' ),
							esc_html( (string) $child_type ),
							$path . '.elements[' . $child_idx . ']'
						),
						array( 'path' => $path, 'element_id' => $element_id )
					);
				}
			}
		}

		// 9. Settings value safety.
		if ( isset( $node['settings'] ) ) {
			if ( ! is_array( $node['settings'] ) ) {
				return new \WP_Error(
					'invalid_elementor_tree',
					sprintf(
						/* translators: %s: path */
						__( 'Element settings at path "%s.settings" must be an array/map.', 'full-elementor-mcp' ),
						$path
					),
					array( 'path' => $path, 'element_id' => $element_id )
				);
			}

			$settings_check = self::validate_settings_values( $node['settings'], $path . '.settings' );
			if ( is_wp_error( $settings_check ) ) {
				return $settings_check;
			}
		}

		// 10. Recursive child elements validation.
		if ( isset( $node['elements'] ) ) {
			if ( ! is_array( $node['elements'] ) ) {
				return new \WP_Error(
					'invalid_elementor_tree',
					sprintf(
						/* translators: %s: path */
						__( 'Child elements at path "%s.elements" must be an ordered array.', 'full-elementor-mcp' ),
						$path
					),
					array( 'path' => $path, 'element_id' => $element_id )
				);
			}

			foreach ( $node['elements'] as $child_index => $child_node ) {
				$child_path = $path . '.elements[' . $child_index . ']';
				$child_res  = self::validate_node(
					$child_node,
					$child_path,
					$depth + 1,
					$seen_ids,
					$node_count,
					$max_depth,
					$max_nodes,
					$context
				);
				if ( is_wp_error( $child_res ) ) {
					return $child_res;
				}
			}
		}

		return true;
	}

	/**
	 * Recursively validates settings values for JSON serializability and dangerous PHP structures.
	 *
	 * Allowed types: null, bool, int, float, string, array.
	 * Rejected: PHP resources, closures, arbitrary non-serializable objects.
	 *
	 * Does NOT strip unknown third-party settings.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @param string               $path     Current path for error context.
	 * @return true|\WP_Error
	 */
	private static function validate_settings_values( array $settings, string $path ) {
		foreach ( $settings as $key => $val ) {
			$val_path = $path . '.' . $key;

			if ( is_null( $val ) || is_bool( $val ) || is_int( $val ) || is_float( $val ) || is_string( $val ) ) {
				continue;
			}

			if ( is_array( $val ) ) {
				$sub = self::validate_settings_values( $val, $val_path );
				if ( is_wp_error( $sub ) ) {
					return $sub;
				}
				continue;
			}

			// Reject PHP resources or closures.
			if ( is_resource( $val ) || $val instanceof \Closure ) {
				return new \WP_Error(
					'unsafe_setting_value',
					sprintf(
						/* translators: 1: path, 2: type */
						__( 'Unsafe PHP runtime value (%2$s) detected in settings at "%1$s". Only JSON-serializable values are permitted.', 'full-elementor-mcp' ),
						$val_path,
						is_resource( $val ) ? 'resource' : 'closure'
					),
					array( 'path' => $val_path, 'key' => $key )
				);
			}

			// Reject arbitrary objects unless JSON-serializable stdClass.
			if ( is_object( $val ) ) {
				if ( $val instanceof \stdClass || $val instanceof \JsonSerializable ) {
					continue;
				}
				return new \WP_Error(
					'unsafe_setting_value',
					sprintf(
						/* translators: 1: path, 2: class */
						__( 'Arbitrary PHP object (%2$s) detected in settings at "%1$s". Objects must implement JsonSerializable or be stdClass.', 'full-elementor-mcp' ),
						$val_path,
						get_class( $val )
					),
					array( 'path' => $val_path, 'key' => $key, 'class' => get_class( $val ) )
				);
			}

			return new \WP_Error(
				'unsafe_setting_value',
				sprintf(
					/* translators: 1: path, 2: type */
					__( 'Unrecognized value type (%2$s) in settings at "%1$s".', 'full-elementor-mcp' ),
					$val_path,
					gettype( $val )
				),
				array( 'path' => $val_path, 'key' => $key )
			);
		}

		return true;
	}
}
