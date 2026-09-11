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
	 * Core recognized valid structural elType identifiers.
	 */
	private const CORE_EL_TYPES = array(
		'container',
		'widget',
		'section',
		'column',
	);

	/**
	 * Atomic element container types (Elementor 4.0+).
	 */
	private const ATOMIC_EL_TYPES = array(
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
	 * Checks if an array is a sequential 0-indexed ordered list.
	 *
	 * PHP 8.0 compatible fallback for array_is_list().
	 *
	 * @param array $arr Array to inspect.
	 * @return bool True if list array.
	 */
	public static function is_list_array( array $arr ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}

		$i = 0;
		foreach ( $arr as $k => $v ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}

		return true;
	}

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

		// Document tree must be a sequential numeric ordered list (no associative maps).
		if ( ! self::is_list_array( $elements ) ) {
			return new \WP_Error(
				'invalid_child_structure',
				__( 'Elementor document tree must be a sequential numeric ordered list of element nodes.', 'full-elementor-mcp' ),
				array( 'actual_structure' => 'associative_map' )
			);
		}

		// Fast-path: empty document tree is valid (e.g. clean/blank page).
		if ( empty( $elements ) ) {
			return true;
		}

		// Preflight type-safety check: reject unsafe runtime objects/resources/closures anywhere in tree
		// BEFORE invoking wp_json_encode() to avoid calling arbitrary JsonSerializable methods.
		$type_preflight = self::validate_json_safe_values( $elements, 'elements' );
		if ( is_wp_error( $type_preflight ) ) {
			return $type_preflight;
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

		$el_type       = $node['elType'];
		$is_known_type = in_array( $el_type, self::CORE_EL_TYPES, true ) || in_array( $el_type, self::ATOMIC_EL_TYPES, true );

		// Check runtime registered element types in Elementor if not in static list.
		if ( ! $is_known_type ) {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->elements_manager ) ) {
				try {
					if ( method_exists( \Elementor\Plugin::$instance->elements_manager, 'get_element_types' ) ) {
						$runtime_types = \Elementor\Plugin::$instance->elements_manager->get_element_types();
						if ( is_array( $runtime_types ) && isset( $runtime_types[ $el_type ] ) ) {
							$is_known_type = true;
						}
					}
				} catch ( \Throwable $e ) {
					// Fall through.
				}
			}
		}

		if ( ! $is_known_type ) {
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

		// Feature gating for structural element types:
		if ( 'container' === $el_type ) {
			$allow_containers = ! empty( $context['allow_containers'] ) ||
				( isset( $context['operation'] ) && 'rollback_restore' === $context['operation'] ) ||
				Full_Elementor_MCP_Elementor_Features::supports_containers();
			if ( ! $allow_containers ) {
				return new \WP_Error(
					'unsupported_element_feature',
					sprintf(
						/* translators: %s: path */
						__( 'Container elements at "%s" require the flexbox/grid container feature to be active in Elementor.', 'full-elementor-mcp' ),
						$path
					),
					array( 'path' => $path, 'element_id' => $element_id, 'elType' => 'container' )
				);
			}
		}

		if ( in_array( $el_type, self::ATOMIC_EL_TYPES, true ) ) {
			if ( ! Full_Elementor_MCP_Elementor_Features::supports_atomic_elements() ) {
				return new \WP_Error(
					'unsupported_element_feature',
					sprintf(
						/* translators: 1: type, 2: path */
						__( 'Atomic element type "%1$s" at "%2$s" requires Elementor 4.0+ atomic elements capability.', 'full-elementor-mcp' ),
						esc_html( $el_type ),
						$path
					),
					array( 'path' => $path, 'element_id' => $element_id, 'elType' => $el_type )
				);
			}
		}

		// 7. Widget-specific invariants and nested element capability.
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

			$widget_type  = $node['widgetType'];
			$has_children = isset( $node['elements'] ) && is_array( $node['elements'] ) && ! empty( $node['elements'] );

			if ( $has_children ) {
				$is_nested_widget = Full_Elementor_MCP_Elementor_Features::is_nested_element_widget( $widget_type );
				$nested_active    = Full_Elementor_MCP_Elementor_Features::supports_nested_elements();

				if ( ! $is_nested_widget ) {
					// Ordinary classic widgets must NEVER contain child elements.
					return new \WP_Error(
						'invalid_child_structure',
						sprintf(
							/* translators: 1: widget type, 2: path */
							__( 'Widget "%1$s" at path "%2$s" must not contain child elements.', 'full-elementor-mcp' ),
							esc_html( $widget_type ),
							$path
						),
						array( 'path' => $path, 'element_id' => $element_id, 'widgetType' => $widget_type )
					);
				}

				if ( ! $nested_active ) {
					// Nested element widget requires nested-elements feature to be active.
					return new \WP_Error(
						'unsupported_element_feature',
						sprintf(
							/* translators: 1: widget type, 2: path */
							__( 'Nested element widget "%1$s" at path "%2$s" requires the nested-elements feature to be active in Elementor.', 'full-elementor-mcp' ),
							esc_html( $widget_type ),
							$path
						),
						array( 'path' => $path, 'element_id' => $element_id, 'widgetType' => $widget_type )
					);
				}

				// Nested element widgets contain container children (or atomic elements if atomic active).
				foreach ( $node['elements'] as $child_idx => $child_node ) {
					$child_el_type         = is_array( $child_node ) ? ( $child_node['elType'] ?? '' ) : '';
					$is_valid_nested_child = 'container' === $child_el_type || in_array( $child_el_type, self::ATOMIC_EL_TYPES, true );
					if ( ! $is_valid_nested_child ) {
						return new \WP_Error(
							'invalid_child_structure',
							sprintf(
								/* translators: 1: widget type, 2: path, 3: child type */
								__( 'Nested widget "%1$s" at "%2$s" can only contain container children, found "%3$s".', 'full-elementor-mcp' ),
								esc_html( $widget_type ),
								$path . '.elements[' . $child_idx . ']',
								esc_html( (string) $child_el_type )
							),
							array( 'path' => $path, 'element_id' => $element_id, 'widgetType' => $widget_type )
						);
					}
				}
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

		// 9. Atomic and top-level field shape validation:
		if ( isset( $node['styles'] ) && ! is_array( $node['styles'] ) ) {
			return new \WP_Error(
				'invalid_atomic_structure',
				sprintf(
					/* translators: %s: path */
					__( 'Element styles at path "%s.styles" must be an array/map.', 'full-elementor-mcp' ),
					$path
				),
				array( 'path' => $path . '.styles', 'element_id' => $element_id )
			);
		}

		if ( isset( $node['interactions'] ) && ! is_array( $node['interactions'] ) ) {
			return new \WP_Error(
				'invalid_atomic_structure',
				sprintf(
					/* translators: %s: path */
					__( 'Element interactions at path "%s.interactions" must be an array/map.', 'full-elementor-mcp' ),
					$path
				),
				array( 'path' => $path . '.interactions', 'element_id' => $element_id )
			);
		}

		if ( isset( $node['editor_settings'] ) && ! is_array( $node['editor_settings'] ) ) {
			return new \WP_Error(
				'invalid_field_structure',
				sprintf(
					/* translators: %s: path */
					__( 'Element editor_settings at path "%s.editor_settings" must be an array.', 'full-elementor-mcp' ),
					$path
				),
				array( 'path' => $path . '.editor_settings', 'element_id' => $element_id )
			);
		}

		if ( isset( $node['version'] ) && ( ! is_string( $node['version'] ) && ! is_numeric( $node['version'] ) ) ) {
			return new \WP_Error(
				'invalid_field_structure',
				sprintf(
					/* translators: %s: path */
					__( 'Element version at path "%s.version" must be a scalar string.', 'full-elementor-mcp' ),
					$path
				),
				array( 'path' => $path . '.version', 'element_id' => $element_id )
			);
		}

		// 10. Settings value safety.
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

		// 11. Recursive child elements validation.
		if ( isset( $node['elements'] ) ) {
			if ( ! is_array( $node['elements'] ) || ! self::is_list_array( $node['elements'] ) ) {
				return new \WP_Error(
					'invalid_child_structure',
					sprintf(
						/* translators: %s: path */
						__( 'Child elements at path "%s.elements" must be a sequential numeric ordered list.', 'full-elementor-mcp' ),
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
	 * Rejected: PHP resources, closures, and arbitrary PHP objects.
	 * (Persisted Elementor trees must contain only JSON scalar and array values).
	 *
	 * Does NOT strip unknown third-party settings.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @param string               $path     Current path for error context.
	 * @return true|\WP_Error
	 */
	private static function validate_settings_values( array $settings, string $path ) {
		return self::validate_json_safe_values( $settings, $path );
	}

	/**
	 * Recursively validates data across the entire element tree for JSON-safety.
	 *
	 * Allowed types: null, bool, int, float, string, array.
	 * Rejects all PHP objects (including stdClass and JsonSerializable), closures, and resources.
	 *
	 * @param mixed  $val  Value to inspect.
	 * @param string $path Current path.
	 * @return true|\WP_Error
	 */
	public static function validate_json_safe_values( mixed $val, string $path ) {
		if ( is_null( $val ) || is_bool( $val ) || is_int( $val ) || is_float( $val ) || is_string( $val ) ) {
			return true;
		}

		if ( is_array( $val ) ) {
			foreach ( $val as $k => $sub_val ) {
				$sub_path = $path . '.' . $k;
				$res      = self::validate_json_safe_values( $sub_val, $sub_path );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			return true;
		}

		$is_settings = str_contains( $path, '.settings' ) || str_contains( $path, "['settings']" );
		$err_code    = $is_settings ? 'unsafe_setting_value' : 'unsafe_node_value';

		// Reject PHP resources or closures.
		if ( is_resource( $val ) || $val instanceof \Closure ) {
			return new \WP_Error(
				$err_code,
				sprintf(
					/* translators: 1: path, 2: type */
					__( 'Unsafe PHP runtime value (%2$s) detected at "%1$s". Only JSON-serializable values are permitted.', 'full-elementor-mcp' ),
					$path,
					is_resource( $val ) ? 'resource' : 'closure'
				),
				array( 'path' => $path )
			);
		}

		// Reject all PHP objects (including stdClass and JsonSerializable) anywhere in tree.
		if ( is_object( $val ) ) {
			return new \WP_Error(
				$err_code,
				sprintf(
					/* translators: 1: path, 2: class */
					__( 'PHP object (%2$s) detected at "%1$s". Elementor trees must contain only JSON scalar and array values.', 'full-elementor-mcp' ),
					$path,
					get_class( $val )
				),
				array( 'path' => $path, 'class' => get_class( $val ) )
			);
		}

		return new \WP_Error(
			$err_code,
			sprintf(
				/* translators: 1: path, 2: type */
				__( 'Unrecognized value type (%2$s) at "%1$s".', 'full-elementor-mcp' ),
				$path,
				gettype( $val )
			),
			array( 'path' => $path )
		);
	}
}
