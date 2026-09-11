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
	 * - rollback_resource_key_resolver: (?callable(array, array): string) Resolves rollback resource key.
	 * - object_id_resolver: (callable(array): int) Resolves primary object ID.
	 * - capture_before: (callable(int, array): mixed) Captures pre-mutation state.
	 * - restore_before: (callable(mixed, array): (bool|\WP_Error)) Restores pre-mutation state.
	 * - capture_after: (?callable(int, array, mixed): mixed) Captures post-mutation state.
	 * - supports_rollback: (bool) True if rollback is reliably supported.
	 * - supports_rollback_for_args: (?callable(array): bool) Checks if specific args support rollback.
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

		$is_create = ! empty( $descriptor['created_object_tracking'] ) || ( self::CATEGORY_WP_OBJECT_CREATE === ( $descriptor['category'] ?? '' ) );
		if ( $is_create ) {
			if ( empty( $descriptor['created_object_id_resolver'] ) || ! is_callable( $descriptor['created_object_id_resolver'] ) ) {
				return new \WP_Error(
					'missing_created_object_id_resolver',
					sprintf(
						/* translators: %s: ability name */
						__( 'Creation mutation strategy for "%s" must declare a callable created_object_id_resolver.', 'full-elementor-mcp' ),
						esc_html( $ability )
					)
				);
			}
		}

		// Normalize defaults for optional properties.
		$descriptor['created_object_id_resolver']     = isset( $descriptor['created_object_id_resolver'] ) && is_callable( $descriptor['created_object_id_resolver'] )
			? $descriptor['created_object_id_resolver']
			: null;
		$descriptor['capture_after']                  = isset( $descriptor['capture_after'] ) && is_callable( $descriptor['capture_after'] )
			? $descriptor['capture_after']
			: null;
		$descriptor['rollback_resource_key_resolver'] = isset( $descriptor['rollback_resource_key_resolver'] ) && is_callable( $descriptor['rollback_resource_key_resolver'] )
			? $descriptor['rollback_resource_key_resolver']
			: null;
		$descriptor['supports_rollback_for_args']     = isset( $descriptor['supports_rollback_for_args'] ) && is_callable( $descriptor['supports_rollback_for_args'] )
			? $descriptor['supports_rollback_for_args']
			: null;
		$descriptor['created_object_tracking']        = ! empty( $descriptor['created_object_tracking'] );
		$descriptor['is_destructive']                 = ! empty( $descriptor['is_destructive'] );
		$descriptor['supports_rollback']              = (bool) $descriptor['supports_rollback'];

		// Phase 3 security and validation metadata:
		$descriptor['requires_tree_validation'] = isset( $descriptor['requires_tree_validation'] )
			? (bool) $descriptor['requires_tree_validation']
			: ( self::CATEGORY_ELEMENTOR_DATA === $descriptor['category'] );
		$descriptor['security_profile']         = (string) ( $descriptor['security_profile'] ?? ( self::CATEGORY_CUSTOM_CODE === $descriptor['category'] ? 'high_risk' : 'standard' ) );
		$descriptor['high_risk']                = isset( $descriptor['high_risk'] )
			? (bool) $descriptor['high_risk']
			: ( 'high_risk' === ( $descriptor['security_profile'] ?? '' ) || ! empty( $descriptor['executable_content'] ) );
		$descriptor['external_network_access']  = isset( $descriptor['external_network_access'] )
			? (bool) $descriptor['external_network_access']
			: ( self::CATEGORY_UNSUPPORTED === $descriptor['category'] && str_contains( $ability, 'image' ) );
		$descriptor['executable_content']       = isset( $descriptor['executable_content'] )
			? (bool) $descriptor['executable_content']
			: ( self::CATEGORY_CUSTOM_CODE === $descriptor['category'] );
		$descriptor['requires_unfiltered_html'] = isset( $descriptor['requires_unfiltered_html'] )
			? (bool) $descriptor['requires_unfiltered_html']
			: ( self::CATEGORY_CUSTOM_CODE === $descriptor['category'] );

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
	 * Checks if an ability supports rollback, optionally taking arguments into account.
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $args    Optional call arguments.
	 * @return bool True if rollback is supported.
	 */
	public static function supports_rollback( string $ability, array $args = array() ): bool {
		$strategy = self::get( $ability );
		if ( ! $strategy ) {
			return false;
		}

		if ( ! empty( $strategy['supports_rollback_for_args'] ) && is_callable( $strategy['supports_rollback_for_args'] ) ) {
			return (bool) call_user_func( $strategy['supports_rollback_for_args'], $args );
		}

		return ! empty( $strategy['supports_rollback'] );
	}

	/**
	 * Returns all registered mutation strategies.
	 *
	 * @return array<string, array<string, mixed>> All strategies keyed by ability name.
	 */
	public static function all(): array {
		if ( ! self::$initialized ) {
			self::init_core_strategies();
		}

		return self::$strategies;
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

		if ( '' === trim( $key ) || 'post:0' === $key ) {
			return new \WP_Error(
				'invalid_resource_key',
				sprintf(
					/* translators: 1: ability name, 2: key */
					__( 'Mutation strategy for "%1$s" resolved an invalid resource key ("%2$s"). Target entity ID is required.', 'full-elementor-mcp' ),
					esc_html( $ability ),
					esc_html( $key )
				)
			);
		}

		return $key;
	}

	/**
	 * Resolves primary object ID for an ability call using its registered strategy.
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $args    Input arguments.
	 * @return int|\WP_Error Object ID or WP_Error.
	 */
	public static function resolve_object_id( string $ability, array $args ) {
		$strategy = self::get( $ability );
		if ( ! $strategy ) {
			return new \WP_Error(
				'unknown_mutation_strategy',
				sprintf(
					/* translators: %s: ability name */
					__( 'Cannot resolve object ID: ability "%s" is not registered in mutation registry.', 'full-elementor-mcp' ),
					esc_html( $ability )
				)
			);
		}

		$resolver = $strategy['object_id_resolver'] ?? null;
		if ( ! is_callable( $resolver ) ) {
			return 0;
		}

		return max( 0, (int) $resolver( $args ) );
	}

	/**
	 * Resolves the deterministic resource key required for rollback fencing.
	 *
	 * For creation operations where an object was created, this resolves to the
	 * created object (e.g. post:123). For updates, it resolves to the modified resource.
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $entry   Journal entry row.
	 * @param array<string, mixed> $args    Resolved contextual arguments.
	 * @return string|\WP_Error Canonical rollback resource key or WP_Error.
	 */
	public static function resolve_rollback_resource_key( string $ability, array $entry, array $args = array() ) {
		$strategy = self::get( $ability );
		if ( ! $strategy ) {
			return new \WP_Error(
				'unknown_mutation_strategy',
				sprintf(
					/* translators: %s: ability name */
					__( 'Cannot resolve rollback resource key: ability "%s" is not registered in mutation registry.', 'full-elementor-mcp' ),
					esc_html( $ability )
				)
			);
		}

		if ( isset( $strategy['rollback_resource_key_resolver'] ) && is_callable( $strategy['rollback_resource_key_resolver'] ) ) {
			$key = (string) ( $strategy['rollback_resource_key_resolver'] )( $entry, $args );
		} else {
			// Default: use the resource key recorded in the journal entry, or resolve via normal resolver.
			$key = (string) ( $entry['resource_key'] ?? '' );
			if ( '' === $key ) {
				$resolved = self::resolve_resource_key( $ability, $args );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}
				$key = $resolved;
			}
		}

		if ( '' === trim( $key ) ) {
			return new \WP_Error(
				'invalid_rollback_resource_key',
				sprintf(
					/* translators: %s: ability name */
					__( 'Mutation strategy for "%s" resolved an empty rollback resource key.', 'full-elementor-mcp' ),
					esc_html( $ability )
				)
			);
		}

		return $key;
	}

	/**
	 * Checks if an ability statically supports reliable rollback.
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
	 * Checks if an ability supports rollback for specific execution arguments.
	 *
	 * For example, delete-page supports rollback for trashing, but permanent deletion
	 * (force=true) cannot be undone and returns false.
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $args    Input arguments.
	 * @return bool True if rollback is supported for these specific args.
	 */
	public static function supports_rollback_for_args( string $ability, array $args = array() ): bool {
		$strategy = self::get( $ability );
		if ( ! $strategy || empty( $strategy['supports_rollback'] ) ) {
			return false;
		}

		if ( isset( $strategy['supports_rollback_for_args'] ) && is_callable( $strategy['supports_rollback_for_args'] ) ) {
			return (bool) ( $strategy['supports_rollback_for_args'] )( $args );
		}

		return true;
	}

	/**
	 * Checks if an ability modifies Elementor document trees and requires tree validation.
	 *
	 * @param string $ability Ability name.
	 * @return bool True if tree validation is required.
	 */
	public static function requires_tree_validation( string $ability ): bool {
		$strategy = self::get( $ability );
		return ! empty( $strategy['requires_tree_validation'] );
	}

	/**
	 * Checks if an ability is destructive.
	 *
	 * @param string $ability Ability name.
	 * @return bool True if destructive.
	 */
	public static function is_destructive( string $ability ): bool {
		$strategy = self::get( $ability );
		return ! empty( $strategy['is_destructive'] );
	}

	/**
	 * Checks if an ability is an entity-creation mutation.
	 *
	 * @param string $ability Ability name.
	 * @return bool True if creation mutation.
	 */
	public static function is_create( string $ability ): bool {
		$strategy = self::get( $ability );
		return ! empty( $strategy['created_object_tracking'] ) || ( self::CATEGORY_WP_OBJECT_CREATE === ( $strategy['category'] ?? '' ) );
	}

	/**
	 * Resolves the created object ID from execution result.
	 *
	 * @param string $ability Ability name.
	 * @param mixed  $result  Ability execution result.
	 * @return int Created object ID or 0.
	 */
	public static function resolve_created_object_id( string $ability, mixed $result ): int {
		$strategy = self::get( $ability );
		if ( ! empty( $strategy['created_object_id_resolver'] ) && is_callable( $strategy['created_object_id_resolver'] ) ) {
			return absint( ( $strategy['created_object_id_resolver'] )( $result ) );
		}
		return 0;
	}

	/**
	 * Resolves the authoritative normalized security profile for an ability.
	 *
	 * Delegates to Full_Elementor_MCP_Security_Strategies::get_security_profile().
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $args    Input arguments for dynamic classification.
	 * @return array<string, mixed> Normalized security profile.
	 */
	public static function get_security_profile( string $ability, array $args = array() ): array {
		if ( class_exists( 'Full_Elementor_MCP_Security_Strategies' ) ) {
			return Full_Elementor_MCP_Security_Strategies::get_security_profile( $ability, $args );
		}

		$strategy = self::get( $ability );
		return array(
			'ability'                     => $ability,
			'executable_content'          => ! empty( $strategy['executable_content'] ),
			'high_risk'                   => ! empty( $strategy['high_risk'] ) || 'high_risk' === ( $strategy['security_profile'] ?? '' ),
			'requires_unfiltered_html'    => ! empty( $strategy['requires_unfiltered_html'] ),
			'external_network_access'     => ! empty( $strategy['external_network_access'] ),
			'irreversible'                => ! empty( $strategy['is_destructive'] ),
			'protected_resource_possible' => false,
			'tree_validation_required'    => ! empty( $strategy['requires_tree_validation'] ),
			'security_category'           => (string) ( $strategy['security_profile'] ?? 'standard' ),
			'reasons'                     => array(),
		);
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
	 * Builds a deterministic resource key for object creation mutations.
	 *
	 * Strips transient runtime parameters, canonicalizes relevant arguments,
	 * and computes a deterministic SHA-256 hash.
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $args    Mutation input arguments.
	 * @return string Canonical deterministic creation key (e.g. 'create:create_page:<hash>').
	 */
	public static function build_create_resource_key( string $ability, array $args ): string {
		$clean_ability = sanitize_key( str_replace( array( 'full-elementor-mcp/', '/' ), array( '', '_' ), $ability ) );

		// If arguments are nested under 'args', unwrap them.
		if ( isset( $args['args'] ) && is_array( $args['args'] ) ) {
			$args = $args['args'];
		}

		// Strip transient runtime tokens / lock fields and journal control metadata.
		$filtered_args = $args;
		$runtime_keys  = array(
			'fencing_token',
			'owner_id',
			'user_id',
			'credential_uuid',
			'_wpnonce',
			'nonce',
			'ability',
			'action',
			'object_type',
			'resource_key',
			'before_state',
			'after_state',
			'confirmation_token',
			'dry_run',
			'idempotency_key',
			'_safety',
			'allow_critical_override',
			'rollback_supported',
			'created_object_id',
		);
		foreach ( $runtime_keys as $rk ) {
			unset( $filtered_args[ $rk ] );
		}

		$canonical = Full_Elementor_MCP_Journal::canonicalize_data( $filtered_args );
		$json      = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$hash      = hash( 'sha256', false !== $json ? $json : serialize( $canonical ) );

		return 'create:' . $clean_ability . ':' . $hash;
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

		// Common post resolvers.
		$post_id_resolver = static function ( array $args = array() ): int {
			return absint( $args['post_id'] ?? ( $args['object_id'] ?? ( $args['page_id'] ?? 0 ) ) );
		};
		$post_resource_key_resolver = static function ( array $args = array() ): string {
			$post_id = absint( $args['post_id'] ?? ( $args['object_id'] ?? ( $args['page_id'] ?? 0 ) ) );
			return self::build_resource_key( 'post', $post_id );
		};

		// ---------------------------------------------------------------------
		// 1. Elementor Document Data Mutations (94 abilities)
		// ---------------------------------------------------------------------
		$elementor_data_abilities = array(
			// Container / Layout (19)
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
			'full-elementor-mcp/add-custom-js'            => array( 'action' => 'add_custom_js', 'destructive' => false, 'executable_content' => true, 'high_risk' => true, 'requires_unfiltered_html' => true, 'security_profile' => 'custom_code' ),

			// Universal & Atomic Widgets (12)
			'full-elementor-mcp/add-widget'               => array( 'action' => 'add_widget', 'destructive' => false ),
			'full-elementor-mcp/update-widget'            => array( 'action' => 'update_widget', 'destructive' => false ),
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

			// Template Application (2)
			'full-elementor-mcp/apply-template'           => array( 'action' => 'apply_template', 'destructive' => false ),
			'full-elementor-mcp/import-template'          => array( 'action' => 'import_template', 'destructive' => false ),

			// Convenience Widgets (62)
			'full-elementor-mcp/add-accordion'            => array( 'action' => 'add_accordion', 'destructive' => false ),
			'full-elementor-mcp/add-alert'                => array( 'action' => 'add_alert', 'destructive' => false ),
			'full-elementor-mcp/add-animated-headline'    => array( 'action' => 'add_animated_headline', 'destructive' => false ),
			'full-elementor-mcp/add-author-box'           => array( 'action' => 'add_author_box', 'destructive' => false ),
			'full-elementor-mcp/add-blockquote'           => array( 'action' => 'add_blockquote', 'destructive' => false ),
			'full-elementor-mcp/add-button'               => array( 'action' => 'add_button', 'destructive' => false ),
			'full-elementor-mcp/add-call-to-action'       => array( 'action' => 'add_call_to_action', 'destructive' => false ),
			'full-elementor-mcp/add-code-highlight'       => array( 'action' => 'add_code_highlight', 'destructive' => false ),
			'full-elementor-mcp/add-countdown'            => array( 'action' => 'add_countdown', 'destructive' => false ),
			'full-elementor-mcp/add-counter'              => array( 'action' => 'add_counter', 'destructive' => false ),
			'full-elementor-mcp/add-divider'              => array( 'action' => 'add_divider', 'destructive' => false ),
			'full-elementor-mcp/add-flip-box'             => array( 'action' => 'add_flip_box', 'destructive' => false ),
			'full-elementor-mcp/add-form'                 => array( 'action' => 'add_form', 'destructive' => false ),
			'full-elementor-mcp/add-gallery'              => array( 'action' => 'add_gallery', 'destructive' => false ),
			'full-elementor-mcp/add-google-maps'          => array( 'action' => 'add_google_maps', 'destructive' => false ),
			'full-elementor-mcp/add-heading'              => array( 'action' => 'add_heading', 'destructive' => false ),
			'full-elementor-mcp/add-hotspot'              => array( 'action' => 'add_hotspot', 'destructive' => false ),
			'full-elementor-mcp/add-html'                 => array( 'action' => 'add_html', 'destructive' => false, 'requires_unfiltered_html' => true, 'security_profile' => 'potentially_executable' ),
			'full-elementor-mcp/add-icon'                 => array( 'action' => 'add_icon', 'destructive' => false ),
			'full-elementor-mcp/add-icon-box'             => array( 'action' => 'add_icon_box', 'destructive' => false ),
			'full-elementor-mcp/add-icon-list'            => array( 'action' => 'add_icon_list', 'destructive' => false ),
			'full-elementor-mcp/add-image'                => array( 'action' => 'add_image', 'destructive' => false ),
			'full-elementor-mcp/add-image-box'            => array( 'action' => 'add_image_box', 'destructive' => false ),
			'full-elementor-mcp/add-image-carousel'       => array( 'action' => 'add_image_carousel', 'destructive' => false ),
			'full-elementor-mcp/add-login'                => array( 'action' => 'add_login', 'destructive' => false ),
			'full-elementor-mcp/add-loop-carousel'        => array( 'action' => 'add_loop_carousel', 'destructive' => false ),
			'full-elementor-mcp/add-loop-grid'            => array( 'action' => 'add_loop_grid', 'destructive' => false ),
			'full-elementor-mcp/add-lottie'               => array( 'action' => 'add_lottie', 'destructive' => false ),
			'full-elementor-mcp/add-media-carousel'       => array( 'action' => 'add_media_carousel', 'destructive' => false ),
			'full-elementor-mcp/add-menu-anchor'          => array( 'action' => 'add_menu_anchor', 'destructive' => false ),
			'full-elementor-mcp/add-nav-menu'             => array( 'action' => 'add_nav_menu', 'destructive' => false ),
			'full-elementor-mcp/add-nested-accordion'     => array( 'action' => 'add_nested_accordion', 'destructive' => false ),
			'full-elementor-mcp/add-nested-tabs'          => array( 'action' => 'add_nested_tabs', 'destructive' => false ),
			'full-elementor-mcp/add-off-canvas'           => array( 'action' => 'add_off_canvas', 'destructive' => false ),
			'full-elementor-mcp/add-portfolio'            => array( 'action' => 'add_portfolio', 'destructive' => false ),
			'full-elementor-mcp/add-posts-grid'           => array( 'action' => 'add_posts_grid', 'destructive' => false ),
			'full-elementor-mcp/add-price-list'           => array( 'action' => 'add_price_list', 'destructive' => false ),
			'full-elementor-mcp/add-price-table'          => array( 'action' => 'add_price_table', 'destructive' => false ),
			'full-elementor-mcp/add-progress'             => array( 'action' => 'add_progress', 'destructive' => false ),
			'full-elementor-mcp/add-progress-tracker'     => array( 'action' => 'add_progress_tracker', 'destructive' => false ),
			'full-elementor-mcp/add-rating'               => array( 'action' => 'add_rating', 'destructive' => false ),
			'full-elementor-mcp/add-reviews'              => array( 'action' => 'add_reviews', 'destructive' => false ),
			'full-elementor-mcp/add-search'               => array( 'action' => 'add_search', 'destructive' => false ),
			'full-elementor-mcp/add-share-buttons'        => array( 'action' => 'add_share_buttons', 'destructive' => false ),
			'full-elementor-mcp/add-shortcode'            => array( 'action' => 'add_shortcode', 'destructive' => false ),
			'full-elementor-mcp/add-slides'               => array( 'action' => 'add_slides', 'destructive' => false ),
			'full-elementor-mcp/add-social-icons'         => array( 'action' => 'add_social_icons', 'destructive' => false ),
			'full-elementor-mcp/add-spacer'               => array( 'action' => 'add_spacer', 'destructive' => false ),
			'full-elementor-mcp/add-star-rating'          => array( 'action' => 'add_star_rating', 'destructive' => false ),
			'full-elementor-mcp/add-table-of-contents'    => array( 'action' => 'add_table_of_contents', 'destructive' => false ),
			'full-elementor-mcp/add-tabs'                 => array( 'action' => 'add_tabs', 'destructive' => false ),
			'full-elementor-mcp/add-testimonial'          => array( 'action' => 'add_testimonial', 'destructive' => false ),
			'full-elementor-mcp/add-testimonial-carousel' => array( 'action' => 'add_testimonial_carousel', 'destructive' => false ),
			'full-elementor-mcp/add-text-editor'          => array( 'action' => 'add_text_editor', 'destructive' => false ),
			'full-elementor-mcp/add-text-path'            => array( 'action' => 'add_text_path', 'destructive' => false ),
			'full-elementor-mcp/add-toggle'               => array( 'action' => 'add_toggle', 'destructive' => false ),
			'full-elementor-mcp/add-video'                => array( 'action' => 'add_video', 'destructive' => false ),
			'full-elementor-mcp/add-wc-add-to-cart'       => array( 'action' => 'add_wc_add_to_cart', 'destructive' => false ),
			'full-elementor-mcp/add-wc-cart'              => array( 'action' => 'add_wc_cart', 'destructive' => false ),
			'full-elementor-mcp/add-wc-checkout'          => array( 'action' => 'add_wc_checkout', 'destructive' => false ),
			'full-elementor-mcp/add-wc-menu-cart'         => array( 'action' => 'add_wc_menu_cart', 'destructive' => false ),
			'full-elementor-mcp/add-wc-products'          => array( 'action' => 'add_wc_products', 'destructive' => false ),
		);

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
					'created_object_tracking'  => false,
					'is_destructive'           => $meta['destructive'],
					'executable_content'       => $meta['executable_content'] ?? false,
					'requires_unfiltered_html' => $meta['requires_unfiltered_html'] ?? false,
					'security_profile'         => $meta['security_profile'] ?? 'standard',
				)
			);
		}

		// ---------------------------------------------------------------------
		// 2. Elementor Page Settings (1 ability)
		// ---------------------------------------------------------------------
		self::register(
			array(
				'ability'                 => 'full-elementor-mcp/update-page-settings',
				'action'                  => 'update_page_settings',
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

		// ---------------------------------------------------------------------
		// 3. Dedicated Post Fields (2 abilities)
		// ---------------------------------------------------------------------
		// set-featured-image: WordPress featured image attachment ID
		self::register(
			array(
				'ability'                 => 'full-elementor-mcp/set-featured-image',
				'action'                  => 'set_featured_image',
				'object_type'             => 'post',
				'category'                => self::CATEGORY_WP_OBJECT_UPDATE,
				'resource_key_resolver'   => $post_resource_key_resolver,
				'object_id_resolver'      => $post_id_resolver,
				'capture_before'          => static function ( int $post_id, array $args = array() ) {
					return self::capture_featured_image_callback( $post_id );
				},
				'restore_before'          => static function ( mixed $before_state, array $context = array() ) {
					return self::restore_featured_image_callback( $before_state, $context );
				},
				'capture_after'           => static function ( int $post_id, array $args = array(), mixed $result = null ) {
					return self::capture_featured_image_callback( $post_id );
				},
				'supports_rollback'       => true,
				'created_object_tracking' => false,
				'is_destructive'          => false,
			)
		);

		// set-page-slug: WordPress post_name field
		self::register(
			array(
				'ability'                 => 'full-elementor-mcp/set-page-slug',
				'action'                  => 'set_page_slug',
				'object_type'             => 'post',
				'category'                => self::CATEGORY_WP_OBJECT_UPDATE,
				'resource_key_resolver'   => $post_resource_key_resolver,
				'object_id_resolver'      => $post_id_resolver,
				'capture_before'          => static function ( int $post_id, array $args = array() ) {
					return self::capture_page_slug_callback( $post_id );
				},
				'restore_before'          => static function ( mixed $before_state, array $context = array() ) {
					return self::restore_page_slug_callback( $before_state, $context );
				},
				'capture_after'           => static function ( int $post_id, array $args = array(), mixed $result = null ) {
					return self::capture_page_slug_callback( $post_id );
				},
				'supports_rollback'       => true,
				'created_object_tracking' => false,
				'is_destructive'          => false,
			)
		);

		// ---------------------------------------------------------------------
		// 4. WordPress Object Creation (6 abilities)
		// ---------------------------------------------------------------------
		$create_abilities = array(
			'full-elementor-mcp/create-page'           => array( 'action' => 'create_page', 'object_type' => 'page', 'key' => 'post_id' ),
			'full-elementor-mcp/duplicate-page'        => array( 'action' => 'duplicate_page', 'object_type' => 'page', 'key' => 'post_id' ),
			'full-elementor-mcp/create-theme-template' => array( 'action' => 'create_theme_template', 'object_type' => 'template', 'key' => 'post_id', 'secondary_key' => 'template_id' ),
			'full-elementor-mcp/create-popup'          => array( 'action' => 'create_popup', 'object_type' => 'popup', 'key' => 'post_id', 'secondary_key' => 'template_id' ),
			'full-elementor-mcp/build-page'            => array( 'action' => 'build_page', 'object_type' => 'page', 'key' => 'post_id' ),
			'full-elementor-mcp/save-as-template'      => array( 'action' => 'save_as_template', 'object_type' => 'template', 'key' => 'template_id' ),
		);

		foreach ( $create_abilities as $ability => $meta ) {
			self::register(
				array(
					'ability'                        => $ability,
					'action'                         => $meta['action'],
					'object_type'                    => $meta['object_type'],
					'category'                       => self::CATEGORY_WP_OBJECT_CREATE,
					'resource_key_resolver'          => static function ( array $args = array() ) use ( $ability ): string {
						return self::build_create_resource_key( $ability, $args );
					},
					'rollback_resource_key_resolver' => static function ( array $entry, array $args = array() ): string {
						$created_id = absint( $entry['created_object_id'] ?? ( $args['created_object_id'] ?? 0 ) );
						if ( $created_id > 0 ) {
							return self::build_resource_key( 'post', $created_id );
						}
						return (string) ( $entry['resource_key'] ?? '' );
					},
					'object_id_resolver'             => static function ( array $args = array() ): int {
						return 0; // Object does not exist yet.
					},
					'created_object_id_resolver'     => static function ( mixed $result ) use ( $meta ): int {
						if ( ! is_array( $result ) ) {
							return 0;
						}
						$primary = $meta['key'] ?? 'post_id';
						if ( isset( $result[ $primary ] ) && absint( $result[ $primary ] ) > 0 ) {
							return absint( $result[ $primary ] );
						}
						if ( ! empty( $meta['secondary_key'] ) && isset( $result[ $meta['secondary_key'] ] ) && absint( $result[ $meta['secondary_key'] ] ) > 0 ) {
							return absint( $result[ $meta['secondary_key'] ] );
						}
						return 0;
					},
					'capture_before'                 => static function ( int $object_id, array $args = array() ) {
						return array( 'exists' => false );
					},
					'restore_before'                 => static function ( mixed $before_state, array $context = array() ) {
						return self::restore_created_object_callback( $before_state, $context );
					},
					'capture_after'                  => static function ( int $object_id, array $args = array(), mixed $result = null ) use ( $meta ) {
						$primary   = $meta['key'] ?? 'post_id';
						$target_id = $object_id > 0 ? $object_id : ( is_array( $result ) ? absint( $result[ $primary ] ?? ( ! empty( $meta['secondary_key'] ) ? ( $result[ $meta['secondary_key'] ] ?? 0 ) : 0 ) ) : 0 );
						return self::capture_created_object_callback( $target_id );
					},
					'supports_rollback'              => true,
					'created_object_tracking'        => true,
					'is_destructive'                 => false,
				)
			);
		}

		// ---------------------------------------------------------------------
		// 5. Delete / Trash Operations (2 abilities)
		// ---------------------------------------------------------------------
		$delete_abilities = array(
			'full-elementor-mcp/delete-page'     => array( 'action' => 'delete_page', 'object_type' => 'page' ),
			'full-elementor-mcp/delete-template' => array( 'action' => 'delete_template', 'object_type' => 'template' ),
		);

		foreach ( $delete_abilities as $ability => $meta ) {
			self::register(
				array(
					'ability'                    => $ability,
					'action'                     => $meta['action'],
					'object_type'                => $meta['object_type'],
					'category'                   => self::CATEGORY_WP_OBJECT_DELETE,
					'resource_key_resolver'      => static function ( array $args = array() ): string {
						$id = absint( $args['post_id'] ?? ( $args['template_id'] ?? ( $args['object_id'] ?? ( $args['page_id'] ?? 0 ) ) ) );
						return self::build_resource_key( 'post', $id );
					},
					'object_id_resolver'         => static function ( array $args = array() ): int {
						return absint( $args['post_id'] ?? ( $args['template_id'] ?? ( $args['object_id'] ?? 0 ) ) );
					},
					'capture_before'             => static function ( int $object_id, array $args = array() ) {
						$force = ( true === ( $args['force'] ?? false ) ) || ( true === ( $args['force_delete'] ?? false ) );
						if ( $force ) {
							return array(
								'id'    => $object_id,
								'force' => true,
							);
						}
						$status = get_post_status( $object_id );
						return array(
							'id'     => $object_id,
							'status' => false !== $status ? $status : 'publish',
							'force'  => false,
						);
					},
					'restore_before'             => static function ( mixed $before_state, array $context = array() ) {
						return self::restore_deleted_object_callback( $before_state, $context );
					},
					'capture_after'              => static function ( int $object_id, array $args = array(), mixed $result = null ) {
						return array( 'status' => get_post_status( $object_id ) );
					},
					'supports_rollback'          => true,
					'supports_rollback_for_args' => static function ( array $args = array() ): bool {
						$force = ( true === ( $args['force'] ?? false ) ) || ( true === ( $args['force_delete'] ?? false ) );
						return ! $force;
					},
					'created_object_tracking'    => false,
					'is_destructive'             => true,
					'high_risk'                  => true,
				)
			);
		}

		// ---------------------------------------------------------------------
		// 6. Global Elementor Kit Settings (3 abilities)
		// ---------------------------------------------------------------------
		// Coarse global lock domain 'global:elementor-kit-state':
		// - Active kit selection ('elementor_active_kit' option) and kit-level color/typography
		//   settings are coupled global state.
		// - update-global-* resolves active kit dynamically.
		// - set-active-kit must not switch active kit while global settings are being mutated.
		$global_kit_abilities = array(
			'full-elementor-mcp/update-global-colors'     => array( 'action' => 'update_global_colors', 'object_type' => 'kit', 'destructive' => false ),
			'full-elementor-mcp/update-global-typography' => array( 'action' => 'update_global_typography', 'object_type' => 'kit', 'destructive' => false ),
			'full-elementor-mcp/set-active-kit'           => array( 'action' => 'set_active_kit', 'object_type' => 'kit', 'destructive' => false ),
		);

		foreach ( $global_kit_abilities as $ability => $meta ) {
			self::register(
				array(
					'ability'                 => $ability,
					'action'                  => $meta['action'],
					'object_type'             => $meta['object_type'],
					'category'                => self::CATEGORY_GLOBAL_SETTINGS,
					'security_profile'        => 'global_settings',
					'high_risk'               => true,
					'resource_key_resolver'   => static function ( array $args = array() ): string {
						return 'global:elementor-kit-state';
					},
					'object_id_resolver'      => static function ( array $args = array() ): int {
						$kit_id = absint( $args['kit_id'] ?? 0 );
						if ( $kit_id <= 0 && function_exists( 'get_option' ) ) {
							$kit_id = absint( get_option( 'elementor_active_kit', 0 ) );
						}
						return $kit_id;
					},
					'capture_before'          => static function ( int $object_id, array $args = array() ) {
						return null;
					},
					'restore_before'          => static function ( mixed $before_state, array $context = array() ) {
						return new \WP_Error(
							'mutation_not_rollbackable',
							__( 'Global kit settings mutations do not support automated rollback.', 'full-elementor-mcp' )
						);
					},
					'capture_after'           => null,
					'supports_rollback'       => false,
					'created_object_tracking' => false,
					'is_destructive'          => $meta['destructive'],
				)
			);
		}

		// ---------------------------------------------------------------------
		// 7. Newly Created Custom Code & Media Entities (3 abilities)
		// ---------------------------------------------------------------------
		$new_entity_abilities = array(
			'full-elementor-mcp/add-code-snippet' => array( 'action' => 'add_code_snippet', 'object_type' => 'custom_code', 'category' => self::CATEGORY_CUSTOM_CODE, 'destructive' => false, 'executable_content' => true, 'requires_unfiltered_html' => true, 'security_profile' => 'custom_code', 'key' => 'snippet_id' ),
			'full-elementor-mcp/sideload-image'   => array( 'action' => 'sideload_image', 'object_type' => 'attachment', 'category' => self::CATEGORY_UNSUPPORTED, 'destructive' => false, 'external_network_access' => true, 'key' => 'attachment_id' ),
			'full-elementor-mcp/upload-svg-icon'  => array( 'action' => 'upload_svg_icon', 'object_type' => 'attachment', 'category' => self::CATEGORY_UNSUPPORTED, 'destructive' => false, 'external_network_access' => true, 'key' => 'attachment_id' ),
		);

		foreach ( $new_entity_abilities as $ability => $meta ) {
			self::register(
				array(
					'ability'                        => $ability,
					'action'                         => $meta['action'],
					'object_type'                    => $meta['object_type'],
					'category'                       => $meta['category'],
					'resource_key_resolver'          => static function ( array $args = array() ) use ( $ability ): string {
						return self::build_create_resource_key( $ability, $args );
					},
					'rollback_resource_key_resolver' => static function ( array $entry, array $args = array() ): string {
						$created_id = absint( $entry['created_object_id'] ?? ( $args['created_object_id'] ?? 0 ) );
						if ( $created_id > 0 ) {
							return self::build_resource_key( 'post', $created_id );
						}
						return (string) ( $entry['resource_key'] ?? '' );
					},
					'object_id_resolver'             => static function ( array $args = array() ): int {
						return 0; // Newly created entity ID unknown before execution.
					},
					'created_object_id_resolver'     => static function ( mixed $result ) use ( $meta ): int {
						if ( ! is_array( $result ) ) {
							return 0;
						}
						$primary = $meta['key'] ?? 'attachment_id';
						return absint( $result[ $primary ] ?? 0 );
					},
					'capture_before'                 => static function ( int $object_id, array $args = array() ) {
						return null;
					},
					'restore_before'                 => static function ( mixed $before_state, array $context = array() ) {
						return new \WP_Error(
							'mutation_not_rollbackable',
							__( 'This mutation category does not support automated rollback.', 'full-elementor-mcp' )
						);
					},
					'capture_after'                  => static function ( int $object_id, array $args = array(), mixed $result = null ) use ( $meta ) {
						$primary   = $meta['key'] ?? 'attachment_id';
						$target_id = $object_id > 0 ? $object_id : ( is_array( $result ) ? absint( $result[ $primary ] ?? 0 ) : 0 );
						return self::capture_created_object_callback( $target_id );
					},
					'supports_rollback'              => false,
					'created_object_tracking'        => true,
					'is_destructive'                 => $meta['destructive'],
					'executable_content'             => $meta['executable_content'] ?? false,
					'requires_unfiltered_html'       => $meta['requires_unfiltered_html'] ?? false,
					'external_network_access'        => $meta['external_network_access'] ?? false,
					'security_profile'               => $meta['security_profile'] ?? ( self::CATEGORY_CUSTOM_CODE === $meta['category'] ? 'high_risk' : 'standard' ),
				)
			);
		}

		// ---------------------------------------------------------------------
		// 8. Existing Post-Backed Custom Code, Meta, & Composite Mutations (9 abilities)
		// ---------------------------------------------------------------------
		$post_backed_custom_or_composite = array(
			'full-elementor-mcp/update-code-snippet'        => array( 'action' => 'update_code_snippet', 'object_type' => 'custom_code', 'category' => self::CATEGORY_CUSTOM_CODE, 'destructive' => false, 'executable_content' => true, 'requires_unfiltered_html' => true, 'security_profile' => 'custom_code' ),
			'full-elementor-mcp/delete-code-snippet'        => array( 'action' => 'delete_code_snippet', 'object_type' => 'custom_code', 'category' => self::CATEGORY_CUSTOM_CODE, 'destructive' => true, 'requires_unfiltered_html' => true, 'security_profile' => 'custom_code' ),
			'full-elementor-mcp/toggle-code-snippet-status' => array( 'action' => 'toggle_code_snippet', 'object_type' => 'custom_code', 'category' => self::CATEGORY_CUSTOM_CODE, 'destructive' => false, 'requires_unfiltered_html' => true, 'security_profile' => 'custom_code' ),
			'full-elementor-mcp/add-custom-css'             => array( 'action' => 'add_custom_css', 'object_type' => 'custom_code', 'category' => self::CATEGORY_CUSTOM_CODE, 'destructive' => false, 'executable_content' => false, 'requires_unfiltered_html' => true, 'security_profile' => 'custom_css' ),
			'full-elementor-mcp/add-stock-image'            => array( 'action' => 'add_stock_image', 'object_type' => 'post', 'category' => self::CATEGORY_COMPOSITE, 'destructive' => false, 'external_network_access' => true ),
			'full-elementor-mcp/set-page-meta'              => array( 'action' => 'set_page_meta', 'object_type' => 'post', 'category' => self::CATEGORY_UNSUPPORTED, 'destructive' => false ),
			'full-elementor-mcp/set-popup-settings'         => array( 'action' => 'set_popup_settings', 'object_type' => 'popup', 'category' => self::CATEGORY_COMPOSITE, 'destructive' => false ),
			'full-elementor-mcp/set-template-conditions'    => array( 'action' => 'set_template_conditions', 'object_type' => 'template', 'category' => self::CATEGORY_COMPOSITE, 'destructive' => false ),
			'full-elementor-mcp/unset-template-conditions'  => array( 'action' => 'unset_template_conditions', 'object_type' => 'template', 'category' => self::CATEGORY_COMPOSITE, 'destructive' => false ),
		);

		foreach ( $post_backed_custom_or_composite as $ability => $meta ) {
			self::register(
				array(
					'ability'                  => $ability,
					'action'                   => $meta['action'],
					'object_type'              => $meta['object_type'],
					'category'                 => $meta['category'],
					'resource_key_resolver'    => static function ( array $args = array() ): string {
						$id = absint( $args['snippet_id'] ?? ( $args['template_id'] ?? ( $args['popup_id'] ?? ( $args['post_id'] ?? ( $args['id'] ?? ( $args['object_id'] ?? 0 ) ) ) ) ) );
						return self::build_resource_key( 'post', $id );
					},
					'object_id_resolver'       => static function ( array $args = array() ): int {
						return absint( $args['snippet_id'] ?? ( $args['template_id'] ?? ( $args['popup_id'] ?? ( $args['post_id'] ?? ( $args['id'] ?? ( $args['object_id'] ?? 0 ) ) ) ) ) );
					},
					'capture_before'           => static function ( int $object_id, array $args = array() ) {
						return null;
					},
					'restore_before'           => static function ( mixed $before_state, array $context = array() ) {
						return new \WP_Error(
							'mutation_not_rollbackable',
							__( 'This mutation category does not support automated rollback.', 'full-elementor-mcp' )
						);
					},
					'capture_after'            => null,
					'supports_rollback'        => false,
					'created_object_tracking'  => false,
					'is_destructive'           => $meta['destructive'],
					'executable_content'       => $meta['executable_content'] ?? ( self::CATEGORY_CUSTOM_CODE === $meta['category'] ),
					'requires_unfiltered_html' => $meta['requires_unfiltered_html'] ?? ( self::CATEGORY_CUSTOM_CODE === $meta['category'] ),
					'external_network_access'  => $meta['external_network_access'] ?? false,
					'security_profile'         => $meta['security_profile'] ?? ( self::CATEGORY_CUSTOM_CODE === $meta['category'] ? 'high_risk' : 'standard' ),
				)
			);
		}

		// Phase 5 internal strategy: ensure checkpoint-restore is registered upon core strategy initialization:
		if ( class_exists( 'Full_Elementor_MCP_Checkpoint_Manager' ) ) {
			Full_Elementor_MCP_Checkpoint_Manager::ensure_restore_strategy_registered();
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
	 * Captures current state fingerprint of a created WordPress entity for conflict detection.
	 *
	 * @param int $post_id Created post ID.
	 * @return array<string, mixed> Fingerprint array.
	 */
	public static function capture_created_object_callback( int $post_id ): array {
		if ( $post_id < 1 ) {
			return array( 'exists' => false );
		}

		$status = function_exists( 'get_post_status' ) ? get_post_status( $post_id ) : false;
		if ( false === $status || 'trash' === $status || 'trashed' === $status ) {
			return array( 'exists' => false, 'status' => $status );
		}

		$post          = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		$page_data     = self::capture_page_data_callback( $post_id );
		$page_settings = self::capture_page_settings_callback( $post_id );

		$get_prop = static function ( mixed $obj, string $key, mixed $default = '' ): mixed {
			if ( is_array( $obj ) ) {
				return $obj[ $key ] ?? $default;
			}
			if ( is_object( $obj ) ) {
				return $obj->$key ?? $default;
			}
			return $default;
		};

		$raw_password = (string) $get_prop( $post, 'post_password', '' );

		$thumb_id = function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post_id ) : 0;
		if ( 0 === $thumb_id && function_exists( 'get_post_meta' ) ) {
			$thumb_id = (int) get_post_meta( $post_id, '_thumbnail_id', true );
		}

		$template_type = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $post_id, '_elementor_template_type', true ) : '';
		$conditions    = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id, '_elementor_conditions', true ) : null;
		$popup_display = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id, '_elementor_popup_display_settings', true ) : null;

		return array(
			'exists'                  => true,
			'ID'                      => $post_id,
			'status'                  => $status,
			'post_type'               => (string) $get_prop( $post, 'post_type', '' ),
			'post_title'              => (string) $get_prop( $post, 'post_title', '' ),
			'post_name'               => (string) $get_prop( $post, 'post_name', '' ),
			'post_content'            => (string) $get_prop( $post, 'post_content', '' ),
			'post_excerpt'            => (string) $get_prop( $post, 'post_excerpt', '' ),
			'post_parent'             => (int) $get_prop( $post, 'post_parent', 0 ),
			'menu_order'              => (int) $get_prop( $post, 'menu_order', 0 ),
			'comment_status'          => (string) $get_prop( $post, 'comment_status', '' ),
			'ping_status'             => (string) $get_prop( $post, 'ping_status', '' ),
			'post_password_hash'      => '' !== $raw_password ? hash( 'sha256', $raw_password ) : '',
			'featured_image_id'       => $thumb_id,
			'elementor_template_type' => $template_type,
			'template_conditions'     => $conditions,
			'popup_display_settings'  => $popup_display,
			'page_data'               => $page_data,
			'page_settings'           => $page_settings,
		);
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

		// Mandatory authoritative fencing assertion immediately before persistent write:
		$resource_key = trim( (string) ( $context['rollback_resource_key'] ?? ( $context['resource_key'] ?? '' ) ) );
		$owner_id     = trim( (string) ( $context['current_owner_id'] ?? ( $context['owner_id'] ?? '' ) ) );
		$token        = (int) ( $context['caller_fencing_token'] ?? ( $context['fencing_token'] ?? 0 ) );

		if ( '' === $resource_key || '' === $owner_id || $token < 1 ) {
			return new \WP_Error(
				'rollback_fencing_required',
				__( 'Persistent restoration requires valid rollback_resource_key, owner ID, and fencing token (>= 1).', 'full-elementor-mcp' )
			);
		}

		$fence_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $token );
		if ( is_wp_error( $fence_check ) ) {
			return $fence_check;
		}

		$post_id = absint( $context['object_id'] ?? ( $context['post_id'] ?? 0 ) );
		if ( $post_id < 1 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for page data restoration.', 'full-elementor-mcp' ) );
		}

		if ( ! is_array( $before_state ) ) {
			return new \WP_Error( 'invalid_before_state', __( 'Before-state must be an array of Elementor elements.', 'full-elementor-mcp' ) );
		}

		// Phase 3: Validate tree structure before restoring to persistent storage.
		// Malformed or corrupted journal state must NOT be written back into Elementor.
		if ( class_exists( 'Full_Elementor_MCP_Tree_Validator' ) ) {
			$tree_validation = Full_Elementor_MCP_Tree_Validator::validate_document(
				$before_state,
				array(
					'post_id'   => $post_id,
					'operation' => 'rollback_restore',
				)
			);
			if ( is_wp_error( $tree_validation ) ) {
				return $tree_validation;
			}
		}

		if ( class_exists( 'Full_Elementor_MCP_Data' ) ) {
			$context_token = null;
			if ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
				$context_token = Full_Elementor_MCP_Mutation_Context::enter(
					array(
						'ability'       => 'rollback',
						'resource_key'  => $resource_key,
						'object_id'     => $post_id,
						'owner_id'      => $owner_id,
						'fencing_token' => $token,
						'is_rollback'   => true,
					)
				);
			}
			try {
				$data_layer = new \Full_Elementor_MCP_Data();
				$save_res   = $data_layer->save_page_data( $post_id, $before_state );
				if ( is_wp_error( $save_res ) ) {
					return $save_res;
				}
			} finally {
				if ( $context_token && class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
					Full_Elementor_MCP_Mutation_Context::leave( $context_token );
				}
			}
		} else {
			// Fallback: direct meta restore.
			$json = wp_json_encode( $before_state );
			if ( false === $json ) {
				return new \WP_Error( 'json_encode_failed', __( 'Failed to encode element data as JSON.', 'full-elementor-mcp' ) );
			}

			update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		}

		// Verify persisted state from authoritative storage BEFORE claiming success.
		$persisted_raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $persisted_raw ) ) {
			$persisted_data = json_decode( wp_unslash( $persisted_raw ), true );
		} elseif ( is_array( $persisted_raw ) ) {
			$persisted_data = $persisted_raw;
		} else {
			$persisted_data = null;
		}

		if ( ! is_array( $persisted_data ) ) {
			return new \WP_Error(
				'rollback_write_failed',
				__( 'Persisted element data meta is invalid or missing after restoration write.', 'full-elementor-mcp' )
			);
		}

		$persisted_canonical = Full_Elementor_MCP_Journal::canonicalize_data( $persisted_data );
		$expected_canonical  = Full_Elementor_MCP_Journal::canonicalize_data( $before_state );
		if ( $persisted_canonical !== $expected_canonical ) {
			return new \WP_Error(
				'rollback_verification_failed',
				__( 'Database write failed during page data restore: persisted storage does not match before-state.', 'full-elementor-mcp' )
			);
		}

		// Invalidate Elementor CSS cache.
		delete_post_meta( $post_id, '_elementor_css' );
		$upload_dir = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : null;
		if ( is_array( $upload_dir ) && isset( $upload_dir['basedir'] ) ) {
			$css_file = $upload_dir['basedir'] . '/elementor/css/post-' . $post_id . '.css';
			if ( file_exists( $css_file ) ) {
				@unlink( $css_file );
			}
		}

		return true;
	}

	/**
	 * Captures current page settings.
	 *
	 * Prioritizes raw persistent post meta to avoid masking database write failures
	 * via transient in-memory models.
	 *
	 * @param int $post_id The post ID.
	 * @return array Page settings array.
	 */
	public static function capture_page_settings_callback( int $post_id ): array {
		if ( $post_id < 1 ) {
			return array();
		}

		$existing = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( is_array( $existing ) ) {
			return $existing;
		}

		if ( class_exists( 'Full_Elementor_MCP_Data' ) ) {
			$data_layer = new \Full_Elementor_MCP_Data();
			$settings   = $data_layer->get_page_settings( $post_id );
			if ( is_array( $settings ) ) {
				return $settings;
			}
		}

		return array();
	}

	/**
	 * Restores page settings from before-state with authoritative storage verification.
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

		// Mandatory authoritative fencing assertion immediately before persistent write:
		$resource_key = trim( (string) ( $context['rollback_resource_key'] ?? ( $context['resource_key'] ?? '' ) ) );
		$owner_id     = trim( (string) ( $context['current_owner_id'] ?? ( $context['owner_id'] ?? '' ) ) );
		$token        = (int) ( $context['caller_fencing_token'] ?? ( $context['fencing_token'] ?? 0 ) );

		if ( '' === $resource_key || '' === $owner_id || $token < 1 ) {
			return new \WP_Error(
				'rollback_fencing_required',
				__( 'Persistent restoration requires valid rollback_resource_key, owner ID, and fencing token (>= 1).', 'full-elementor-mcp' )
			);
		}

		$fence_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $token );
		if ( is_wp_error( $fence_check ) ) {
			return $fence_check;
		}

		$post_id = absint( $context['object_id'] ?? ( $context['post_id'] ?? 0 ) );
		if ( $post_id < 1 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for page settings restoration.', 'full-elementor-mcp' ) );
		}

		if ( ! is_array( $before_state ) ) {
			return new \WP_Error( 'invalid_before_state', __( 'Before-state must be an array of settings.', 'full-elementor-mcp' ) );
		}

		// EXACT REPLACEMENT IN AUTHORITATIVE STORAGE:
		update_post_meta( $post_id, '_elementor_page_settings', $before_state );

		// Verify persisted state from storage BEFORE updating in-memory models.
		$persisted_meta = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( ! is_array( $persisted_meta ) ) {
			return new \WP_Error(
				'rollback_write_failed',
				__( 'Persisted page settings meta is invalid or missing after restoration write.', 'full-elementor-mcp' )
			);
		}

		$persisted_canonical = Full_Elementor_MCP_Journal::canonicalize_data( $persisted_meta );
		$expected_canonical  = Full_Elementor_MCP_Journal::canonicalize_data( $before_state );
		if ( $persisted_canonical !== $expected_canonical ) {
			return new \WP_Error(
				'rollback_verification_failed',
				__( 'Database write failed during page settings restore: persisted storage does not match before-state.', 'full-elementor-mcp' )
			);
		}

		// Invalidate Elementor CSS cache.
		delete_post_meta( $post_id, '_elementor_css' );
		$upload_dir = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : null;
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$css_path = $upload_dir['basedir'] . '/elementor/css/post-' . $post_id . '.css';
			if ( file_exists( $css_path ) && function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( $css_path );
			}
		}

		// Only after database storage is verified: update in-memory Elementor document settings model.
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			$doc = \Elementor\Plugin::$instance->documents->get( $post_id );
			if ( $doc && method_exists( $doc, 'get_settings_model' ) ) {
				$model = $doc->get_settings_model();
				if ( $model && method_exists( $model, 'set_settings' ) ) {
					$model->set_settings( $before_state );
				}
			}
		}

		return true;
	}

	/**
	 * Captures current featured image attachment ID.
	 *
	 * @param int $post_id The post ID.
	 * @return array{thumbnail_id: int}
	 */
	public static function capture_featured_image_callback( int $post_id ): array {
		if ( $post_id < 1 ) {
			return array( 'thumbnail_id' => 0 );
		}

		$thumb_id = function_exists( 'get_post_thumbnail_id' ) ? get_post_thumbnail_id( $post_id ) : get_post_meta( $post_id, '_thumbnail_id', true );
		return array( 'thumbnail_id' => absint( $thumb_id ) );
	}

	/**
	 * Restores featured image from before-state.
	 *
	 * @param mixed                $before_state Captured before state.
	 * @param array<string, mixed> $context      Restoration context.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function restore_featured_image_callback( mixed $before_state, array $context = array() ) {
		$filtered = apply_filters( 'full_elementor_mcp_restore_featured_image', null, $before_state, $context );
		if ( null !== $filtered ) {
			return $filtered;
		}

		// Mandatory authoritative fencing assertion immediately before persistent write:
		$resource_key = trim( (string) ( $context['rollback_resource_key'] ?? ( $context['resource_key'] ?? '' ) ) );
		$owner_id     = trim( (string) ( $context['current_owner_id'] ?? ( $context['owner_id'] ?? '' ) ) );
		$token        = (int) ( $context['caller_fencing_token'] ?? ( $context['fencing_token'] ?? 0 ) );

		if ( '' === $resource_key || '' === $owner_id || $token < 1 ) {
			return new \WP_Error(
				'rollback_fencing_required',
				__( 'Persistent restoration requires valid rollback_resource_key, owner ID, and fencing token (>= 1).', 'full-elementor-mcp' )
			);
		}

		$fence_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $token );
		if ( is_wp_error( $fence_check ) ) {
			return $fence_check;
		}

		$post_id = absint( $context['object_id'] ?? ( $context['post_id'] ?? 0 ) );
		if ( $post_id < 1 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for featured image restoration.', 'full-elementor-mcp' ) );
		}

		$thumb_id = absint( is_array( $before_state ) ? ( $before_state['thumbnail_id'] ?? 0 ) : 0 );

		if ( $thumb_id > 0 ) {
			if ( function_exists( 'set_post_thumbnail' ) ) {
				set_post_thumbnail( $post_id, $thumb_id );
			} else {
				update_post_meta( $post_id, '_thumbnail_id', $thumb_id );
			}
		} else {
			if ( function_exists( 'delete_post_thumbnail' ) ) {
				delete_post_thumbnail( $post_id );
			} else {
				delete_post_meta( $post_id, '_thumbnail_id' );
			}
		}

		// Verify restoration after write:
		$recaptured = self::capture_featured_image_callback( $post_id );
		if ( (int) $recaptured['thumbnail_id'] !== $thumb_id ) {
			return new \WP_Error(
				'rollback_verification_failed',
				sprintf(
					/* translators: 1: expected thumbnail ID, 2: actual thumbnail ID */
					__( 'Featured image restoration failed verification: expected thumbnail ID %1$d, got %2$d.', 'full-elementor-mcp' ),
					$thumb_id,
					$recaptured['thumbnail_id']
				)
			);
		}

		return true;
	}

	/**
	 * Captures current WordPress post slug (post_name).
	 *
	 * @param int $post_id The post ID.
	 * @return array{post_name: string}
	 */
	public static function capture_page_slug_callback( int $post_id ): array {
		if ( $post_id < 1 ) {
			return array( 'post_name' => '' );
		}

		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		return array(
			'post_name' => $post && isset( $post->post_name ) ? (string) $post->post_name : '',
		);
	}

	/**
	 * Restores WordPress post slug (post_name) from before-state.
	 *
	 * @param mixed                $before_state Captured before state.
	 * @param array<string, mixed> $context      Restoration context.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function restore_page_slug_callback( mixed $before_state, array $context = array() ) {
		$filtered = apply_filters( 'full_elementor_mcp_restore_page_slug', null, $before_state, $context );
		if ( null !== $filtered ) {
			return $filtered;
		}

		// Mandatory authoritative fencing assertion immediately before persistent write:
		$resource_key = trim( (string) ( $context['rollback_resource_key'] ?? ( $context['resource_key'] ?? '' ) ) );
		$owner_id     = trim( (string) ( $context['current_owner_id'] ?? ( $context['owner_id'] ?? '' ) ) );
		$token        = (int) ( $context['caller_fencing_token'] ?? ( $context['fencing_token'] ?? 0 ) );

		if ( '' === $resource_key || '' === $owner_id || $token < 1 ) {
			return new \WP_Error(
				'rollback_fencing_required',
				__( 'Persistent restoration requires valid rollback_resource_key, owner ID, and fencing token (>= 1).', 'full-elementor-mcp' )
			);
		}

		$fence_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $token );
		if ( is_wp_error( $fence_check ) ) {
			return $fence_check;
		}

		$post_id = absint( $context['object_id'] ?? ( $context['post_id'] ?? 0 ) );
		if ( $post_id < 1 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for page slug restoration.', 'full-elementor-mcp' ) );
		}

		$slug = is_array( $before_state ) ? (string) ( $before_state['post_name'] ?? '' ) : '';

		if ( function_exists( 'wp_update_post' ) ) {
			$res = wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => $slug,
				),
				true
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		// Verify restoration after write:
		$recaptured = self::capture_page_slug_callback( $post_id );
		if ( (string) $recaptured['post_name'] !== $slug ) {
			return new \WP_Error(
				'rollback_verification_failed',
				sprintf(
					/* translators: 1: expected slug, 2: actual slug */
					__( 'Page slug restoration failed verification (slug collision or modification): expected "%1$s", got "%2$s".', 'full-elementor-mcp' ),
					$slug,
					$recaptured['post_name']
				)
			);
		}

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

		// Mandatory authoritative fencing assertion immediately before persistent write:
		$resource_key = trim( (string) ( $context['rollback_resource_key'] ?? ( $context['resource_key'] ?? '' ) ) );
		$owner_id     = trim( (string) ( $context['current_owner_id'] ?? ( $context['owner_id'] ?? '' ) ) );
		$token        = (int) ( $context['caller_fencing_token'] ?? ( $context['fencing_token'] ?? 0 ) );

		if ( '' === $resource_key || '' === $owner_id || $token < 1 ) {
			return new \WP_Error(
				'rollback_fencing_required',
				__( 'Persistent restoration requires valid rollback_resource_key, owner ID, and fencing token (>= 1).', 'full-elementor-mcp' )
			);
		}

		$fence_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $token );
		if ( is_wp_error( $fence_check ) ) {
			return $fence_check;
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
	 * Fails closed if permanent deletion was requested.
	 *
	 * @param mixed                $before_state Captured before state.
	 * @param array<string, mixed> $context      Restoration context.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function restore_deleted_object_callback( mixed $before_state, array $context = array() ) {
		$filtered = apply_filters( 'full_elementor_mcp_restore_deleted_object', null, $before_state, $context );
		if ( null !== $filtered ) {
			return $filtered;
		}

		if ( is_array( $before_state ) && ! empty( $before_state['force'] ) ) {
			return new \WP_Error(
				'permanent_delete_not_rollbackable',
				__( 'Cannot rollback: object was permanently deleted and cannot be untrashed.', 'full-elementor-mcp' )
			);
		}

		// Mandatory authoritative fencing assertion immediately before persistent write:
		$resource_key = trim( (string) ( $context['rollback_resource_key'] ?? ( $context['resource_key'] ?? '' ) ) );
		$owner_id     = trim( (string) ( $context['current_owner_id'] ?? ( $context['owner_id'] ?? '' ) ) );
		$token        = (int) ( $context['caller_fencing_token'] ?? ( $context['fencing_token'] ?? 0 ) );

		if ( '' === $resource_key || '' === $owner_id || $token < 1 ) {
			return new \WP_Error(
				'rollback_fencing_required',
				__( 'Persistent restoration requires valid rollback_resource_key, owner ID, and fencing token (>= 1).', 'full-elementor-mcp' )
			);
		}

		$fence_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $token );
		if ( is_wp_error( $fence_check ) ) {
			return $fence_check;
		}

		$object_id = absint( $context['object_id'] ?? ( $context['post_id'] ?? 0 ) );
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
