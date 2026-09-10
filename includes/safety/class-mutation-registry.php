<?php
/**
 * Mutation Strategy Registry for Full Elementor MCP.
 *
 * Provides a centralized registry describing how each mutating ability is
 * identified, locked, captured, validated, and rolled back.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages registration and resolution of mutation strategies.
 */
class Full_Elementor_MCP_Mutation_Registry {

	/**
	 * Strategy categories.
	 */
	public const CATEGORY_ELEMENTOR_DATA   = 'elementor_document_data';
	public const CATEGORY_PAGE_SETTINGS    = 'elementor_page_settings';
	public const CATEGORY_WP_OBJECT_CREATE = 'wp_object_create';
	public const CATEGORY_WP_OBJECT_UPDATE = 'wp_object_update';
	public const CATEGORY_WP_OBJECT_DELETE = 'wp_object_delete';
	public const CATEGORY_GLOBAL_SETTINGS  = 'global_settings';
	public const CATEGORY_CUSTOM_CODE      = 'custom_code';
	public const CATEGORY_COMPOSITE        = 'composite';
	public const CATEGORY_UNSUPPORTED      = 'unsupported';

	/**
	 * Registered strategy descriptors keyed by ability name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $strategies = array();

	/**
	 * Whether core strategies have been initialized.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Registers a mutation strategy descriptor.
	 *
	 * Contract for $descriptor:
	 * - ability: (string) Full ability name (e.g. 'full-elementor-mcp/add-widget').
	 * - action: (string) Normalized action identifier.
	 * - object_type: (string) Primary entity type (e.g. 'post', 'template', 'kit').
	 * - category: (string) One of CATEGORY_* constants.
	 * - resource_key_resolver: (callable(array): string) Resolves locking resource key.
	 * - object_id_resolver: (callable(array): int) Resolves primary object ID.
	 * - capture_before: (callable(int, array): mixed) Captures pre-mutation state.
	 * - restore_before: (callable(mixed, array): (bool|\WP_Error)) Restores pre-mutation state.
	 * - capture_after: (?callable(int, array, mixed): mixed) Captures post-mutation state.
	 * - supports_rollback: (bool) True if rollback is reliably supported.
	 * - created_object_tracking: (bool) True if operation creates a new entity.
	 * - is_destructive: (bool) True if operation is destructive.
	 *
	 * @param array<string, mixed> $descriptor Strategy descriptor array.
	 * @return bool|\WP_Error True on success, WP_Error on invalid contract.
	 */
	public static function register( array $descriptor ): bool|\WP_Error {
		$required_keys = array(
			'ability',
			'action',
			'object_type',
			'category',
			'resource_key_resolver',
			'object_id_resolver',
			'capture_before',
			'restore_before',
			'supports_rollback',
		);

		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $descriptor ) ) {
				return new \WP_Error(
					'invalid_strategy_descriptor',
					sprintf(
						/* translators: %s: missing key */
						__( 'Mutation strategy descriptor is missing required key: "%s".', 'full-elementor-mcp' ),
						esc_html( $key )
					)
				);
			}
		}

		$ability = trim( (string) $descriptor['ability'] );
		if ( '' === $ability ) {
			return new \WP_Error(
				'invalid_strategy_ability',
				__( 'Mutation strategy ability name cannot be empty.', 'full-elementor-mcp' )
			);
		}

		// Prevent readonly abilities from being registered as mutations.
		if ( ! empty( $descriptor['is_readonly'] ) ) {
			return new \WP_Error(
				'readonly_mutation_conflict',
				sprintf(
					/* translators: %s: ability name */
					__( 'Cannot register mutation strategy: ability "%s" is explicitly declared readonly.', 'full-elementor-mcp' ),
					esc_html( $ability )
				)
			);
		}

		if ( function_exists( 'wp_get_ability' ) ) {
			$registered_ability = wp_get_ability( $ability );
			if ( is_array( $registered_ability ) && true === ( $registered_ability['meta']['annotations']['readonly'] ?? false ) ) {
				return new \WP_Error(
					'readonly_mutation_conflict',
					sprintf(
						/* translators: %s: ability name */
						__( 'Cannot register mutation strategy: ability "%s" is registered with meta.annotations.readonly = true.', 'full-elementor-mcp' ),
						esc_html( $ability )
					)
				);
			}
		}

		if ( ! is_callable( $descriptor['resource_key_resolver'] ) ) {
			return new \WP_Error(
				'invalid_strategy_callable',
				__( 'resource_key_resolver must be a valid callable.', 'full-elementor-mcp' )
			);
		}

		if ( ! is_callable( $descriptor['object_id_resolver'] ) ) {
			return new \WP_Error(
				'invalid_strategy_callable',
				__( 'object_id_resolver must be a valid callable.', 'full-elementor-mcp' )
			);
		}

		if ( ! is_callable( $descriptor['capture_before'] ) ) {
			return new \WP_Error(
				'invalid_strategy_callable',
				__( 'capture_before must be a valid callable.', 'full-elementor-mcp' )
			);
		}

		if ( ! is_callable( $descriptor['restore_before'] ) ) {
			return new \WP_Error(
				'invalid_strategy_callable',
				__( 'restore_before must be a valid callable.', 'full-elementor-mcp' )
			);
		}

		// Normalize defaults for optional properties.
		$descriptor['capture_after']           = isset( $descriptor['capture_after'] ) && is_callable( $descriptor['capture_after'] )
			? $descriptor['capture_after']
			: null;
		$descriptor['created_object_tracking'] = ! empty( $descriptor['created_object_tracking'] );
		$descriptor['is_destructive']          = ! empty( $descriptor['is_destructive'] );
		$descriptor['supports_rollback']       = (bool) $descriptor['supports_rollback'];

		self::$strategies[ $ability ] = $descriptor;

		return true;
	}

	/**
	 * Retrieves registered strategy descriptor for an ability.
	 *
	 * Initializes core strategies on first lookup if not yet loaded.
	 *
	 * @param string $ability Ability name.
	 * @return array<string, mixed>|null Strategy descriptor or null if unknown.
	 */
	public static function get( string $ability ): ?array {
		if ( ! self::$initialized ) {
			self::init_core_strategies();
		}

		return self::$strategies[ $ability ] ?? null;
	}

	/**
	 * Checks if an ability has a registered mutation strategy.
	 *
	 * @param string $ability Ability name.
	 * @return bool True if registered.
	 */
	public static function has( string $ability ): bool {
		return null !== self::get( $ability );
	}

	/**
	 * Returns all registered ability names in the registry.
	 *
	 * @return string[] Array of ability names.
	 */
	public static function get_registered_abilities(): array {
		if ( ! self::$initialized ) {
			self::init_core_strategies();
		}

		return array_keys( self::$strategies );
	}

	/**
	 * Resolves locking resource key for an ability call using its registered strategy.
	 *
	 * Fails closed if the ability is unknown or unresolved.
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $args    Input arguments.
	 * @return string|\WP_Error Canonical resource key string or WP_Error.
	 */
	public static function resolve_resource_key( string $ability, array $args ) {
		$strategy = self::get( $ability );
		if ( ! $strategy ) {
			return new \WP_Error(
				'unknown_mutation_strategy',
				sprintf(
					/* translators: %s: ability name */
					__( 'Cannot resolve resource key: ability "%s" is not registered in mutation registry.', 'full-elementor-mcp' ),
					esc_html( $ability )
				)
			);
		}

		$resolver = $strategy['resource_key_resolver'];
		$key      = (string) $resolver( $args );

		if ( '' === trim( $key ) ) {
			return new \WP_Error(
				'invalid_resource_key',
				sprintf(
					/* translators: %s: ability name */
					__( 'Mutation strategy for "%s" resolved an empty resource key.', 'full-elementor-mcp' ),
					esc_html( $ability )
				)
			);
		}

		return $key;
	}

	/**
	 * Resolves target object ID for an ability call.
	 *
	 * Returns 0 for creation mutations where the object does not exist yet.
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $args    Input arguments.
	 * @return int Target object ID.
	 */
	public static function resolve_object_id( string $ability, array $args ): int {
		$strategy = self::get( $ability );
		if ( ! $strategy ) {
			return 0;
		}

		$resolver = $strategy['object_id_resolver'];
		return max( 0, (int) $resolver( $args ) );
	}

	/**
	 * Checks if an ability supports reliable rollback.
	 *
	 * Fails closed (returns false) if unknown.
	 *
	 * @param string $ability Ability name.
	 * @return bool True if rollback is supported.
	 */
	public static function is_rollback_supported( string $ability ): bool {
		$strategy = self::get( $ability );
		return ! empty( $strategy['supports_rollback'] );
	}

	/**
	 * Builds a standardized, canonical resource key for locking.
	 *
	 * @param string     $type Entity type (e.g. 'post', 'template', 'kit').
	 * @param int|string $id   Entity identifier.
	 * @return string Canonical resource key (e.g. 'post:42').
	 */
	public static function build_resource_key( string $type, int|string $id ): string {
		$clean_type = sanitize_key( $type );
		$clean_id   = is_numeric( $id ) ? (string) (int) $id : sanitize_key( (string) $id );
		return $clean_type . ':' . $clean_id;
	}

	/**
	 * Resets registered strategies (primarily for isolated test suites).
	 */
	public static function reset(): void {
		self::$strategies  = array();
		self::$initialized = false;
	}

	/**
	 * Initializes default core mutation strategies for known abilities.
	 */
	public static function init_core_strategies(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// ---------------------------------------------------------------------
		// 1. Elementor Document Data Mutations (Page/Container/Widget content)
		// ---------------------------------------------------------------------
		$elementor_data_abilities = array(
			// Container / Layout abilities
			'full-elementor-mcp/add-container'            => array( 'action' => 'add_container', 'destructive' => false ),
			'full-elementor-mcp/update-container'         => array( 'action' => 'update_container', 'destructive' => false ),
			'full-elementor-mcp/update-element'           => array( 'action' => 'update_element', 'destructive' => false ),
			'full-elementor-mcp/batch-update'             => array( 'action' => 'batch_update', 'destructive' => false ),
			'full-elementor-mcp/move-element'             => array( 'action' => 'move_element', 'destructive' => false ),
			'full-elementor-mcp/duplicate-element'        => array( 'action' => 'duplicate_element', 'destructive' => false ),
			'full-elementor-mcp/wrap-element-in-container'=> array( 'action' => 'wrap_element', 'destructive' => false ),
			'full-elementor-mcp/unwrap-element'           => array( 'action' => 'unwrap_element', 'destructive' => true ),
			'full-elementor-mcp/reorder-elements'         => array( 'action' => 'reorder_elements', 'destructive' => false ),
			'full-elementor-mcp/remove-element'           => array( 'action' => 'remove_element', 'destructive' => true ),
			'full-elementor-mcp/replace-element'          => array( 'action' => 'replace_element', 'destructive' => true ),
			'full-elementor-mcp/add-element-class'        => array( 'action' => 'add_class', 'destructive' => false ),
			'full-elementor-mcp/remove-element-class'     => array( 'action' => 'remove_class', 'destructive' => true ),
			'full-elementor-mcp/set-element-classes'      => array( 'action' => 'set_classes', 'destructive' => true ),
			'full-elementor-mcp/set-dynamic-tag'          => array( 'action' => 'set_dynamic_tag', 'destructive' => false ),
			'full-elementor-mcp/delete-page-content'      => array( 'action' => 'delete_page_content', 'destructive' => true ),
			'full-elementor-mcp/add-flexbox'              => array( 'action' => 'add_flexbox', 'destructive' => false ),
			'full-elementor-mcp/add-div-block'            => array( 'action' => 'add_div_block', 'destructive' => false ),
			'full-elementor-mcp/add-custom-js'            => array( 'action' => 'add_custom_js', 'destructive' => false ),

			// Universal Widget abilities
			'full-elementor-mcp/add-widget'               => array( 'action' => 'add_widget', 'destructive' => false ),
			'full-elementor-mcp/update-widget'            => array( 'action' => 'update_widget', 'destructive' => false ),
			'full-elementor-mcp/delete-widget'            => array( 'action' => 'delete_widget', 'destructive' => true ),

			// Atomic Widget abilities (Elementor 4.0+)
			'full-elementor-mcp/add-atomic-widget'        => array( 'action' => 'add_atomic_widget', 'destructive' => false ),
			'full-elementor-mcp/update-atomic-widget'     => array( 'action' => 'update_atomic_widget', 'destructive' => false ),
			'full-elementor-mcp/add-atomic-heading'       => array( 'action' => 'add_atomic_heading', 'destructive' => false ),
			'full-elementor-mcp/add-atomic-paragraph'     => array( 'action' => 'add_atomic_paragraph', 'destructive' => false ),
			'full-elementor-mcp/add-atomic-button'        => array( 'action' => 'add_atomic_button', 'destructive' => false ),
			'full-elementor-mcp/add-atomic-image'         => array( 'action' => 'add_atomic_image', 'destructive' => false ),
			'full-elementor-mcp/add-atomic-svg'           => array( 'action' => 'add_atomic_svg', 'destructive' => false ),
			'full-elementor-mcp/add-atomic-youtube'       => array( 'action' => 'add_atomic_youtube', 'destructive' => false ),
			'full-elementor-mcp/add-atomic-video'         => array( 'action' => 'add_atomic_video', 'destructive' => false ),
			'full-elementor-mcp/add-atomic-divider'       => array( 'action' => 'add_atomic_divider', 'destructive' => false ),
		);

		// Helper resolvers for elementor data abilities.
		$post_id_resolver = static function ( array $args = array() ): int {
			return absint( $args['post_id'] ?? ( $args['object_id'] ?? 0 ) );
		};
		$post_resource_key_resolver = static function ( array $args = array() ): string {
			$post_id = absint( $args['post_id'] ?? ( $args['object_id'] ?? 0 ) );
			return self::build_resource_key( 'post', $post_id );
		};

		// Helper capture & restore callbacks.
		$capture_elementor_data = static function ( int $post_id, array $args = array() ) {
			return self::capture_page_data_callback( $post_id );
		};
		$restore_elementor_data = static function ( mixed $before_state, array $context = array() ) {
			return self::restore_page_data_callback( $before_state, $context );
		};
		$capture_after_elementor_data = static function ( int $post_id, array $args = array(), mixed $result = null ) {
			return self::capture_page_data_callback( $post_id );
		};

		foreach ( $elementor_data_abilities as $ability => $meta ) {
			self::register(
				array(
					'ability'                 => $ability,
					'action'                  => $meta['action'],
					'object_type'             => 'post',
					'category'                => self::CATEGORY_ELEMENTOR_DATA,
					'resource_key_resolver'   => $post_resource_key_resolver,
					'object_id_resolver'      => $post_id_resolver,
					'capture_before'          => $capture_elementor_data,
					'restore_before'          => $restore_elementor_data,
					'capture_after'           => $capture_after_elementor_data,
					'supports_rollback'       => true,
					'created_object_tracking' => false,
					'is_destructive'          => $meta['destructive'],
				)
			);
		}

		// ---------------------------------------------------------------------
		// 2. Elementor Page Settings Mutations
		// ---------------------------------------------------------------------
		$page_settings_abilities = array(
			'full-elementor-mcp/update-page-settings' => 'update_page_settings',
			'full-elementor-mcp/set-featured-image'   => 'set_featured_image',
			'full-elementor-mcp/set-page-meta'        => 'set_page_meta',
			'full-elementor-mcp/set-page-slug'        => 'set_page_slug',
		);

		foreach ( $page_settings_abilities as $ability => $action ) {
			self::register(
				array(
					'ability'                 => $ability,
					'action'                  => $action,
					'object_type'             => 'post',
					'category'                => self::CATEGORY_PAGE_SETTINGS,
					'resource_key_resolver'   => $post_resource_key_resolver,
					'object_id_resolver'      => $post_id_resolver,
					'capture_before'          => static function ( int $post_id, array $args = array() ) {
						return self::capture_page_settings_callback( $post_id );
					},
					'restore_before'          => static function ( mixed $before_state, array $context = array() ) {
						return self::restore_page_settings_callback( $before_state, $context );
					},
					'capture_after'           => static function ( int $post_id, array $args = array(), mixed $result = null ) {
						return self::capture_page_settings_callback( $post_id );
					},
					'supports_rollback'       => true,
					'created_object_tracking' => false,
					'is_destructive'          => false,
				)
			);
		}

		// ---------------------------------------------------------------------
		// 3. WordPress Object Creation (create-page, duplicate-page, import-template)
		// ---------------------------------------------------------------------
		$create_abilities = array(
			'full-elementor-mcp/create-page'           => array( 'action' => 'create_page', 'object_type' => 'page' ),
			'full-elementor-mcp/duplicate-page'        => array( 'action' => 'duplicate_page', 'object_type' => 'page' ),
			'full-elementor-mcp/import-template'       => array( 'action' => 'import_template', 'object_type' => 'template' ),
			'full-elementor-mcp/create-theme-template' => array( 'action' => 'create_theme_template', 'object_type' => 'template' ),
			'full-elementor-mcp/create-popup'          => array( 'action' => 'create_popup', 'object_type' => 'popup' ),
		);

		foreach ( $create_abilities as $ability => $meta ) {
			self::register(
				array(
					'ability'                 => $ability,
					'action'                  => $meta['action'],
					'object_type'             => $meta['object_type'],
					'category'                => self::CATEGORY_WP_OBJECT_CREATE,
					'resource_key_resolver'   => static function ( array $args = array() ): string {
						$seed = ! empty( $args['title'] ) ? (string) $args['title'] : ( ! empty( $args['source_id'] ) ? 'copy:' . $args['source_id'] : 'create:' . uniqid() );
						return self::build_resource_key( 'create', hash( 'sha256', $seed ) );
					},
					'object_id_resolver'      => static function ( array $args = array() ): int {
						return 0; // Does not exist yet.
					},
					'capture_before'          => static function ( int $object_id, array $args = array() ) {
						return array( 'exists' => false );
					},
					'restore_before'          => static function ( mixed $before_state, array $context = array() ) {
						return self::restore_created_object_callback( $before_state, $context );
					},
					'capture_after'           => static function ( int $object_id, array $args = array(), mixed $result = null ) {
						return array( 'created' => true, 'result' => $result );
					},
					'supports_rollback'       => true,
					'created_object_tracking' => true,
					'is_destructive'          => false,
				)
			);
		}

		// ---------------------------------------------------------------------
		// 4. Delete / Trash Operations (delete-page, delete-template)
		// ---------------------------------------------------------------------
		$delete_abilities = array(
			'full-elementor-mcp/delete-page'     => array( 'action' => 'delete_page', 'object_type' => 'page' ),
			'full-elementor-mcp/delete-template' => array( 'action' => 'delete_template', 'object_type' => 'template' ),
		);

		foreach ( $delete_abilities as $ability => $meta ) {
			self::register(
				array(
					'ability'                 => $ability,
					'action'                  => $meta['action'],
					'object_type'             => $meta['object_type'],
					'category'                => self::CATEGORY_WP_OBJECT_DELETE,
					'resource_key_resolver'   => static function ( array $args = array() ) use ( $meta ): string {
						$id = absint( $args['post_id'] ?? ( $args['template_id'] ?? ( $args['object_id'] ?? 0 ) ) );
						return self::build_resource_key( $meta['object_type'], $id );
					},
					'object_id_resolver'      => static function ( array $args = array() ): int {
						return absint( $args['post_id'] ?? ( $args['template_id'] ?? ( $args['object_id'] ?? 0 ) ) );
					},
					'capture_before'          => static function ( int $object_id, array $args = array() ) {
						$status = get_post_status( $object_id );
						return array(
							'id'     => $object_id,
							'status' => false !== $status ? $status : 'publish',
						);
					},
					'restore_before'          => static function ( mixed $before_state, array $context = array() ) {
						return self::restore_deleted_object_callback( $before_state, $context );
					},
					'capture_after'           => static function ( int $object_id, array $args = array(), mixed $result = null ) {
						return array( 'status' => get_post_status( $object_id ) );
					},
					'supports_rollback'       => true,
					'created_object_tracking' => false,
					'is_destructive'          => true,
				)
			);
		}

		// ---------------------------------------------------------------------
		// 5. Global Settings & Custom Code Mutations (Declared Unsupported for Rollback)
		// ---------------------------------------------------------------------
		$unsupported_abilities = array(
			'full-elementor-mcp/update-global-colors'       => array( 'action' => 'update_global_colors', 'object_type' => 'kit' ),
			'full-elementor-mcp/update-global-typography'   => array( 'action' => 'update_global_typography', 'object_type' => 'kit' ),
			'full-elementor-mcp/set-active-kit'             => array( 'action' => 'set_active_kit', 'object_type' => 'kit' ),
			'full-elementor-mcp/add-custom-css'             => array( 'action' => 'add_custom_css', 'object_type' => 'custom_code' ),
			'full-elementor-mcp/add-code-snippet'           => array( 'action' => 'add_code_snippet', 'object_type' => 'custom_code' ),
			'full-elementor-mcp/update-code-snippet'        => array( 'action' => 'update_code_snippet', 'object_type' => 'custom_code' ),
			'full-elementor-mcp/delete-code-snippet'        => array( 'action' => 'delete_code_snippet', 'object_type' => 'custom_code' ),
			'full-elementor-mcp/toggle-code-snippet-status' => array( 'action' => 'toggle_code_snippet', 'object_type' => 'custom_code' ),
			'full-elementor-mcp/sideload-image'             => array( 'action' => 'sideload_image', 'object_type' => 'attachment' ),
			'full-elementor-mcp/upload-svg-icon'            => array( 'action' => 'upload_svg_icon', 'object_type' => 'attachment' ),
		);

		foreach ( $unsupported_abilities as $ability => $meta ) {
			self::register(
				array(
					'ability'                 => $ability,
					'action'                  => $meta['action'],
					'object_type'             => $meta['object_type'],
					'category'                => self::CATEGORY_UNSUPPORTED,
					'resource_key_resolver'   => static function ( array $args = array() ) use ( $meta ): string {
						$id = absint( $args['kit_id'] ?? ( $args['id'] ?? ( $args['snippet_id'] ?? ( $args['object_id'] ?? ( $args['post_id'] ?? 0 ) ) ) ) );
						return self::build_resource_key( $meta['object_type'], $id );
					},
					'object_id_resolver'      => static function ( array $args = array() ): int {
						return absint( $args['kit_id'] ?? ( $args['id'] ?? ( $args['snippet_id'] ?? ( $args['object_id'] ?? ( $args['post_id'] ?? 0 ) ) ) ) );
					},
					'capture_before'          => static function ( int $object_id, array $args = array() ) {
						return null;
					},
					'restore_before'          => static function ( mixed $before_state, array $context = array() ) {
						return new \WP_Error(
							'mutation_not_rollbackable',
							__( 'This mutation category does not support automated rollback.', 'full-elementor-mcp' )
						);
					},
					'capture_after'           => null,
					'supports_rollback'       => false,
					'created_object_tracking' => false,
					'is_destructive'          => 'delete_code_snippet' === $meta['action'],
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Default Callback Implementations
	// -------------------------------------------------------------------------

	/**
	 * Captures current Elementor page document data.
	 *
	 * @param int $post_id The post ID.
	 * @return array Elementor data array.
	 */
	public static function capture_page_data_callback( int $post_id ): array {
		if ( $post_id < 1 ) {
			return array();
		}

		if ( class_exists( 'Full_Elementor_MCP_Data' ) ) {
			$data_layer = new \Full_Elementor_MCP_Data();
			$data       = $data_layer->get_page_data( $post_id );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( wp_unslash( $raw ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * Restores Elementor page document data from before-state.
	 *
	 * @param mixed                $before_state Captured before state.
	 * @param array<string, mixed> $context      Restoration context.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function restore_page_data_callback( mixed $before_state, array $context = array() ) {
		$filtered = apply_filters( 'full_elementor_mcp_restore_page_data', null, $before_state, $context );
		if ( null !== $filtered ) {
			return $filtered;
		}

		$post_id = absint( $context['object_id'] ?? 0 );
		if ( $post_id < 1 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for page data restoration.', 'full-elementor-mcp' ) );
		}

		if ( ! is_array( $before_state ) ) {
			return new \WP_Error( 'invalid_before_state', __( 'Before-state must be an array of Elementor elements.', 'full-elementor-mcp' ) );
		}

		if ( class_exists( 'Full_Elementor_MCP_Data' ) ) {
			$data_layer = new \Full_Elementor_MCP_Data();
			return $data_layer->save_page_data( $post_id, $before_state );
		}

		// Fallback: direct meta restore.
		$json = wp_json_encode( $before_state );
		if ( false === $json ) {
			return new \WP_Error( 'json_encode_failed', __( 'Failed to encode element data as JSON.', 'full-elementor-mcp' ) );
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		return true;
	}

	/**
	 * Captures current page settings.
	 *
	 * @param int $post_id The post ID.
	 * @return array Page settings array.
	 */
	public static function capture_page_settings_callback( int $post_id ): array {
		if ( $post_id < 1 ) {
			return array();
		}

		if ( class_exists( 'Full_Elementor_MCP_Data' ) ) {
			$data_layer = new \Full_Elementor_MCP_Data();
			$settings   = $data_layer->get_page_settings( $post_id );
			if ( is_array( $settings ) ) {
				return $settings;
			}
		}

		$existing = get_post_meta( $post_id, '_elementor_page_settings', true );
		return is_array( $existing ) ? $existing : array();
	}

	/**
	 * Restores page settings from before-state.
	 *
	 * @param mixed                $before_state Captured before state.
	 * @param array<string, mixed> $context      Restoration context.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function restore_page_settings_callback( mixed $before_state, array $context = array() ) {
		$filtered = apply_filters( 'full_elementor_mcp_restore_page_settings', null, $before_state, $context );
		if ( null !== $filtered ) {
			return $filtered;
		}

		$post_id = absint( $context['object_id'] ?? 0 );
		if ( $post_id < 1 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for page settings restoration.', 'full-elementor-mcp' ) );
		}

		if ( ! is_array( $before_state ) ) {
			return new \WP_Error( 'invalid_before_state', __( 'Before-state must be an array of settings.', 'full-elementor-mcp' ) );
		}

		if ( class_exists( 'Full_Elementor_MCP_Data' ) ) {
			$data_layer = new \Full_Elementor_MCP_Data();
			return $data_layer->save_page_settings( $post_id, $before_state );
		}

		update_post_meta( $post_id, '_elementor_page_settings', $before_state );
		return true;
	}

	/**
	 * Restores a creation mutation by trashing/deleting the created entity.
	 *
	 * @param mixed                $before_state Captured before state.
	 * @param array<string, mixed> $context      Restoration context.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function restore_created_object_callback( mixed $before_state, array $context = array() ) {
		$filtered = apply_filters( 'full_elementor_mcp_restore_created_object', null, $before_state, $context );
		if ( null !== $filtered ) {
			return $filtered;
		}

		$created_id = absint( $context['created_object_id'] ?? 0 );
		if ( $created_id < 1 ) {
			return true;
		}

		if ( function_exists( 'wp_trash_post' ) ) {
			$trashed = wp_trash_post( $created_id );
			return false !== $trashed;
		}

		return true;
	}

	/**
	 * Restores a deleted entity by untrashing it.
	 *
	 * @param mixed                $before_state Captured before state.
	 * @param array<string, mixed> $context      Restoration context.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function restore_deleted_object_callback( mixed $before_state, array $context ) {
		$object_id = absint( $context['object_id'] ?? 0 );
		if ( $object_id < 1 ) {
			return new \WP_Error( 'invalid_object_id', __( 'Invalid object ID for untrash restoration.', 'full-elementor-mcp' ) );
		}

		if ( function_exists( 'wp_untrash_post' ) ) {
			$untrashed = wp_untrash_post( $object_id );
			return false !== $untrashed;
		}

		return true;
	}
}
