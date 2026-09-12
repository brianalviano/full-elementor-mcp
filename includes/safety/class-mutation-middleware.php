<?php
/**
 * Central Mutation Middleware & Safety Execution Layer for Full Elementor MCP.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative Central Safety Middleware.
 *
 * Connects Phase 1 (Locks & Fencing), Phase 2 (WAL Journal & Recovery), and
 * Phase 3 (Tree Validator & Security Strategies) into ONE authoritative execution layer.
 *
 * @since 1.8.0
 */
final class Full_Elementor_MCP_Mutation_Middleware {

	/**
	 * Registry of wrapped abilities and their underlying original callbacks.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $ability_registry = array();

	/**
	 * Reserved internal safety fields that caller must never supply.
	 */
	const RESERVED_SAFETY_FIELDS = array(
		'fencing_token',
		'journal_id',
		'owner_id',
		'resource_key',
		'rollback_supported',
		'created_object_id',
		'managed_safety_action',
		'managed_delegate',
	);

	/**
	 * Allowlist of ability names permitted to execute in managed safety mutation mode.
	 */
	const ALLOWED_MANAGED_SAFETY_ABILITIES = array(
		'full-elementor-mcp/restore-checkpoint',
		'full-elementor-mcp/undo-change',
		'full-elementor-mcp/undo-last-change',
		'full-elementor-mcp/create-checkpoint',
	);

	/**
	 * Registers and wraps an ability at bootstrap/registration time.
	 *
	 * Preserves original callbacks and definitions, injecting safety schema properties
	 * and routing execution through central middleware.
	 *
	 * @param string               $name The full ability name (e.g. 'full-elementor-mcp/create-page').
	 * @param array<string, mixed> $args The ability definition arguments.
	 * @return array<string, mixed> The modified ability definition arguments.
	 */
	public static function wrap_ability( string $name, array $args ): array {
		$original_execute    = $args['execute_callback'] ?? null;
		$original_permission = $args['permission_callback'] ?? null;

		self::$ability_registry[ $name ] = array(
			'name'                => $name,
			'execute_callback'    => $original_execute,
			'permission_callback' => $original_permission,
			'meta'                => $args['meta'] ?? array(),
			'input_schema'        => $args['input_schema'] ?? array(),
		);

		// Inject optional '_safety' envelope and top-level safety controls into input_schema:
		if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
			if ( ! isset( $args['input_schema']['properties'] ) || ! is_array( $args['input_schema']['properties'] ) ) {
				$args['input_schema']['properties'] = array();
			}

			$args['input_schema']['properties']['_safety'] = array(
				'type'        => 'object',
				'description' => __( 'Optional safety controls (confirmation_token, idempotency_key, dry_run).', 'full-elementor-mcp' ),
				'properties'  => array(
					'confirmation_token' => array( 'type' => 'string' ),
					'idempotency_key'    => array( 'type' => 'string' ),
					'dry_run'            => array( 'type' => 'boolean' ),
				),
			);
			$args['input_schema']['properties']['confirmation_token'] = array(
				'type'        => 'string',
				'description' => __( 'Server-issued confirmation challenge token for high-risk operations.', 'full-elementor-mcp' ),
			);
			$args['input_schema']['properties']['idempotency_key'] = array(
				'type'        => 'string',
				'description' => __( 'Unique client token to guarantee at-most-once execution.', 'full-elementor-mcp' ),
			);
			$args['input_schema']['properties']['dry_run'] = array(
				'type'        => 'boolean',
				'description' => __( 'When true, validates the mutation without making persistent modifications.', 'full-elementor-mcp' ),
			);
		}

		// Wrap execute_callback with centralized middleware dispatch:
		$args['execute_callback'] = static function ( $input ) use ( $name ) {
			return self::execute( $name, is_array( $input ) ? $input : array() );
		};

		return $args;
	}

	/**
	 * Authoritative execution dispatch for Full Elementor MCP abilities.
	 *
	 * @param string               $ability Ability name (e.g. 'full-elementor-mcp/update-element').
	 * @param array<string, mixed> $input   Incoming arguments.
	 * @return mixed Result of execution or WP_Error on safety failure.
	 */
	public static function execute( string $ability, array $input ) {
		// 1. Check for reserved safety arguments:
		foreach ( self::RESERVED_SAFETY_FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) || ( isset( $input['_safety'] ) && is_array( $input['_safety'] ) && array_key_exists( $field, $input['_safety'] ) ) ) {
				return new \WP_Error(
					'reserved_safety_argument',
					sprintf(
						/* translators: %s: argument name */
						__( 'Reserved internal safety argument "%s" cannot be supplied by caller.', 'full-elementor-mcp' ),
						$field
					),
					array( 'argument' => $field )
				);
			}
		}

		// 2. Extract normalized safety controls:
		$confirmation_token = $input['_safety']['confirmation_token'] ?? ( $input['confirmation_token'] ?? null );
		$idempotency_key    = $input['_safety']['idempotency_key'] ?? ( $input['idempotency_key'] ?? null );
		$is_dry_run         = ( true === ( $input['_safety']['dry_run'] ?? false ) ) || ( true === ( $input['dry_run'] ?? false ) );

		if ( is_string( $confirmation_token ) ) {
			$confirmation_token = trim( $confirmation_token );
		}
		if ( is_string( $idempotency_key ) ) {
			$idempotency_key = trim( $idempotency_key );
		}

		// 3. Resolve ability registration definition & check readonly / mutation consistency:
		$def = self::$ability_registry[ $ability ] ?? null;
		$is_readonly = false;

		if ( null !== $def ) {
			$is_readonly = isset( $def['meta']['annotations']['readonly'] ) && true === $def['meta']['annotations']['readonly'];
		}

		if ( $is_readonly ) {
			// A readonly ability must NEVER have a registered mutation strategy.
			if ( class_exists( 'Full_Elementor_MCP_Mutation_Registry' ) && Full_Elementor_MCP_Mutation_Registry::has( $ability ) ) {
				return new \WP_Error(
					'readonly_mutation_conflict',
					sprintf(
						/* translators: %s: ability slug */
						__( 'Safety contradiction: ability "%s" is declared readonly but has a registered mutation strategy.', 'full-elementor-mcp' ),
						esc_html( $ability )
					),
					array( 'ability' => $ability )
				);
			}
		} else {
			// A mutating ability MUST have a registered mutation strategy.
			if ( ! class_exists( 'Full_Elementor_MCP_Mutation_Registry' ) || ! Full_Elementor_MCP_Mutation_Registry::has( $ability ) ) {
				return new \WP_Error(
					'mutation_strategy_missing',
					sprintf(
						/* translators: %s: ability slug */
						__( 'Fatal safety error: no registered mutation strategy found for "%s". Execution rejected.', 'full-elementor-mcp' ),
						esc_html( $ability )
					),
					array( 'ability' => $ability )
				);
			}
		}

		// 4. Global disabled tool gate (applies to readonly and mutations alike):
		$disabled_tools = get_option( 'full_elementor_mcp_disabled_tools', array() );
		if ( is_array( $disabled_tools ) && in_array( $ability, $disabled_tools, true ) ) {
			if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
				Full_Elementor_MCP_Audit_Logger::log(
					Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_BLOCKED_DISABLED,
					array(
						'ability'  => $ability,
						'severity' => Full_Elementor_MCP_Audit_Logger::SEV_WARNING,
						'args'     => $input,
					)
				);
			}
			return new \WP_Error(
				'ability_disabled',
				sprintf(
					/* translators: %s: ability slug */
					__( 'The ability "%s" is disabled by site administrator policy.', 'full-elementor-mcp' ),
					$ability
				),
				array( 'ability' => $ability )
			);
		}

		// 5. Credential scope gate:
		if ( ! class_exists( 'Full_Elementor_MCP_Security_Guard' ) ) {
			if ( ! $is_readonly ) {
				return new \WP_Error(
					'safety_dependency_missing',
					__( 'Fatal safety error: required safety dependency "Full_Elementor_MCP_Security_Guard" is missing. Mutation rejected.', 'full-elementor-mcp' ),
					array( 'dependency' => 'Full_Elementor_MCP_Security_Guard' )
				);
			}
			$scope = array( 'mode' => 'read_only' );
		} else {
			$scope = Full_Elementor_MCP_Security_Guard::resolve_current_scope();
		}

		if ( ! $is_readonly && 'read_only' === ( $scope['mode'] ?? '' ) ) {
			if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
				Full_Elementor_MCP_Audit_Logger::log(
					Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_BLOCKED_SCOPE,
					array(
						'ability'  => $ability,
						'severity' => Full_Elementor_MCP_Audit_Logger::SEV_WARNING,
						'args'     => $input,
					)
				);
			}
			return new \WP_Error(
				'credential_scope_readonly',
				__( 'Mutation rejected: active credential scope is read-only.', 'full-elementor-mcp' ),
				array( 'ability' => $ability )
			);
		}

		if ( class_exists( 'Full_Elementor_MCP_Security_Guard' ) ) {
			$annotations = array( 'readonly' => $is_readonly );
			if ( ! Full_Elementor_MCP_Security_Guard::is_ability_in_scope( $ability, $annotations, $scope ) ) {
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_BLOCKED_SCOPE,
						array(
							'ability'  => $ability,
							'severity' => Full_Elementor_MCP_Audit_Logger::SEV_WARNING,
							'args'     => $input,
						)
					);
				}
				return new \WP_Error(
					'credential_scope_denied',
					sprintf(
						/* translators: %s: ability slug */
						__( 'Access to ability "%s" is denied under current credential scope.', 'full-elementor-mcp' ),
						$ability
					),
					array( 'ability' => $ability )
				);
			}
		}

		// 6. Execute original WordPress permission callback:
		$perm_cb = $def['permission_callback'] ?? null;
		if ( is_callable( $perm_cb ) ) {
			$perm_res = call_user_func( $perm_cb, $input );
			if ( is_wp_error( $perm_res ) ) {
				return $perm_res;
			}
			if ( false === $perm_res ) {
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_BLOCKED_PERMISSION,
						array(
							'ability'  => $ability,
							'severity' => Full_Elementor_MCP_Audit_Logger::SEV_WARNING,
							'args'     => $input,
						)
					);
				}
				return new \WP_Error(
					'permission_denied',
					__( 'You do not have permission to execute this ability.', 'full-elementor-mcp' ),
					array( 'ability' => $ability )
				);
			}
		}

		$orig_execute_cb = $def['execute_callback'] ?? null;

		// ---------------------------------------------------------------------
		// READONLY ROUTE: Protected by Readonly Context Overlay
		// ---------------------------------------------------------------------
		if ( $is_readonly ) {
			if ( ! is_callable( $orig_execute_cb ) ) {
				return new \WP_Error( 'execute_callback_missing', __( 'Ability execute callback is not callable.', 'full-elementor-mcp' ) );
			}

			$context_token = null;
			if ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
				$context_token = Full_Elementor_MCP_Mutation_Context::enter(
					array(
						'ability'         => $ability,
						'user_id'         => (int) ( $scope['user_id'] ?? get_current_user_id() ),
						'credential_uuid' => $scope['credential_uuid'] ?? null,
						'is_readonly'     => true,
						'is_dry_run'      => false,
						'is_rollback'     => false,
					)
				);
			}

			try {
				return call_user_func( $orig_execute_cb, $input );
			} finally {
				if ( $context_token && class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
					Full_Elementor_MCP_Mutation_Context::leave( $context_token );
				}
			}
		}

		// ---------------------------------------------------------------------
		// MUTATION ROUTE: Full centralized safety orchestration.
		// ---------------------------------------------------------------------

		// 7. Fail-closed safety dependencies check:
		$required_dependencies = array(
			'Full_Elementor_MCP_Database_Installer',
			'Full_Elementor_MCP_Lock_Manager',
			'Full_Elementor_MCP_Journal',
			'Full_Elementor_MCP_Mutation_Registry',
			'Full_Elementor_MCP_Security_Guard',
			'Full_Elementor_MCP_Security_Strategies',
			'Full_Elementor_MCP_Tree_Validator',
			'Full_Elementor_MCP_Confirmation_Manager',
			'Full_Elementor_MCP_Idempotency_Manager',
			'Full_Elementor_MCP_Mutation_Context',
			'Full_Elementor_MCP_Safe_Writes',
		);

		foreach ( $required_dependencies as $dep ) {
			if ( ! class_exists( $dep ) ) {
				return new \WP_Error(
					'safety_dependency_missing',
					sprintf(
						/* translators: %s: class name */
						__( 'Fatal safety error: required safety dependency "%s" is missing. Mutation rejected.', 'full-elementor-mcp' ),
						esc_html( $dep )
					),
					array( 'dependency' => $dep )
				);
			}
		}

		// Re-entrancy safety: exactly one forward mutation context is permitted per request/thread.
		if ( Full_Elementor_MCP_Mutation_Context::has_active_context() ) {
			$active_ctx = Full_Elementor_MCP_Mutation_Context::current();
			if ( empty( $active_ctx['is_rollback'] ) ) {
				return new \WP_Error(
					'nested_mutation_not_supported',
					__( 'Nested forward mutations are not supported. Elementor mutations must not recursively mutate under active contexts.', 'full-elementor-mcp' ),
					array( 'ability' => $ability )
				);
			}
		}

		$strategy = Full_Elementor_MCP_Mutation_Registry::get( $ability );
		if ( empty( $strategy ) ) {
			return new \WP_Error(
				'mutation_strategy_missing',
				sprintf(
					/* translators: %s: ability slug */
					__( 'Fatal safety error: no registered mutation strategy found for "%s". Execution rejected.', 'full-elementor-mcp' ),
					$ability
				),
				array( 'ability' => $ability )
			);
		}

		// 8. Security Profile & unfiltered_html capability check:
		$security_profile = Full_Elementor_MCP_Security_Strategies::get_security_profile( $ability, $input );

		if ( ! empty( $security_profile['requires_unfiltered_html'] ) ) {
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'unfiltered_html' ) ) {
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_BLOCKED_SECURITY,
						array(
							'ability'  => $ability,
							'severity' => Full_Elementor_MCP_Audit_Logger::SEV_WARNING,
							'args'     => $input,
						)
					);
				}
				return new \WP_Error(
					'unfiltered_html_required',
					__( 'This mutation contains raw HTML, script, or stylesheet rules requiring the unfiltered_html capability.', 'full-elementor-mcp' ),
					array( 'ability' => $ability )
				);
			}
		}

		// 9. Resolve canonical resource key & object ID:
		$resource_key = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( $ability, $input );
		if ( is_wp_error( $resource_key ) ) {
			return $resource_key;
		}

		$object_id = Full_Elementor_MCP_Mutation_Registry::resolve_object_id( $ability, $input );
		if ( is_wp_error( $object_id ) ) {
			return $object_id;
		}

		$user_id   = (int) ( $scope['user_id'] ?? get_current_user_id() );
		$cred_uuid = $scope['credential_uuid'] ?? null;

		// 10. Idempotency Pre-Check (BEFORE confirmation check):
		// Completed replays return cached results without prompting for confirmation.
		if ( ! empty( $idempotency_key ) ) {
			$precheck = Full_Elementor_MCP_Idempotency_Manager::precheck(
				$idempotency_key,
				$ability,
				$user_id,
				$cred_uuid,
				$input
			);

			if ( is_wp_error( $precheck ) ) {
				return $precheck;
			}

			if ( 'completed' === ( $precheck['status'] ?? '' ) ) {
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_IDEMPOTENT_REPLAY,
						array(
							'ability'         => $ability,
							'resource_key'    => $resource_key ?? '',
							'user_id'         => $user_id,
							'credential_uuid' => $cred_uuid,
							'severity'        => Full_Elementor_MCP_Audit_Logger::SEV_NOTICE,
							'metadata'        => array( 'idempotency_key' => $idempotency_key ),
						)
					);
				}
				return $precheck['result'] ?? array( 'success' => true );
			}
		}

		// Determine if ability is a managed safety action:
		$is_managed_safety = ! empty( $strategy['managed_safety_action'] )
			&& in_array( $ability, self::ALLOWED_MANAGED_SAFETY_ABILITIES, true );

		// 11. High-Risk / Protected / Irreversible Confirmation Gate:
		$requires_confirmation = ! empty( $security_profile['high_risk'] )
			|| ! empty( $security_profile['irreversible'] )
			|| ! empty( $security_profile['protected_resource_possible'] )
			|| ! empty( $security_profile['executable_content'] );

		// 11. Dry-Run Handling (predictive execution analysis):
		if ( $is_dry_run ) {
			if ( $is_managed_safety && ! empty( $strategy['managed_delegate'] ) && is_callable( $strategy['managed_delegate'] ) ) {
				return call_user_func( $strategy['managed_delegate'], $input );
			}

			$chk_req = false;
			$chk_sup = false;
			$chk_cap = 'unsupported';
			if ( class_exists( 'Full_Elementor_MCP_Checkpoint_Manager' ) ) {
				$chk_policy = Full_Elementor_MCP_Checkpoint_Manager::get_checkpoint_requirement( $ability, $input, $strategy );
				$chk_req    = ( Full_Elementor_MCP_Checkpoint_Manager::REQUIREMENT_REQUIRED === $chk_policy );
				$chk_cap    = Full_Elementor_MCP_Checkpoint_Strategies::get_restore_capability( $resource_key, 'automatic', array( 'is_permanent_delete' => ! empty( $security_profile['irreversible'] ) ) );
				$chk_sup    = ( Full_Elementor_MCP_Checkpoint_Strategies::CAPABILITY_UNSUPPORTED !== $chk_cap );
			}

			return array(
				'dry_run'                       => true,
				'allowed'                       => true,
				'ability'                       => $ability,
				'resource_key'                  => $resource_key,
				'object_id'                     => $object_id,
				'security_profile'              => $security_profile,
				'protected_resource'            => ! empty( $security_profile['protected_resource_possible'] ),
				'confirmation_required'         => $requires_confirmation,
				'rollback_supported'            => Full_Elementor_MCP_Mutation_Registry::supports_rollback_for_args( $ability, $input ),
				'checkpoint_required'           => $chk_req,
				'checkpoint_supported'          => $chk_sup,
				'checkpoint_restore_capability' => $chk_cap,
				'reasons'                       => $security_profile['reasons'] ?? array(),
			);
		}

		// 12. High-Risk / Protected / Irreversible Confirmation Gate:
		if ( $requires_confirmation ) {
			if ( ! empty( $confirmation_token ) ) {
				// Validate ONLY - do NOT consume yet. Consumption is deferred until after lock & WAL.
				$val_res = Full_Elementor_MCP_Confirmation_Manager::validate(
					$confirmation_token,
					$ability,
					$input,
					$user_id,
					$cred_uuid,
					$resource_key
				);
				if ( is_wp_error( $val_res ) ) {
					if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
						Full_Elementor_MCP_Audit_Logger::log(
							Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_BLOCKED_CONFIRMATION,
							array(
								'ability'      => $ability,
								'resource_key' => $resource_key,
								'user_id'      => $user_id,
								'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_WARNING,
								'error_code'   => $val_res->get_error_code(),
								'args'         => $input,
							)
						);
					}
					return $val_res;
				}
			} else {
				// Issue confirmation challenge:
				$challenge = Full_Elementor_MCP_Confirmation_Manager::create_challenge(
					$ability,
					$input,
					$user_id,
					$cred_uuid,
					$resource_key,
					$security_profile['reasons'] ?? array()
				);
				if ( is_wp_error( $challenge ) ) {
					return $challenge;
				}
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_CONFIRMATION_ISSUED,
						array(
							'ability'      => $ability,
							'resource_key' => $resource_key,
							'user_id'      => $user_id,
							'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_NOTICE,
							'metadata'     => array(
								'challenge_token' => $challenge['confirmation_token'] ?? '',
								'expires_at_utc'  => $challenge['expires_at_utc'] ?? '',
							),
							'args'         => $input,
						)
					);
				}
				return new \WP_Error(
					'confirmation_required',
					__( 'This operation is classified as high-risk, irreversible, or targets a protected resource and requires confirmation.', 'full-elementor-mcp' ),
					$challenge
				);
			}
		}

		// 13. Server-Generated Execution UUID & Owner ID:
		$req_uuid = wp_generate_uuid4();
		if ( empty( $req_uuid ) ) {
			$req_uuid = bin2hex( random_bytes( 16 ) );
		}
		$owner_id = 'mw_' . $req_uuid;

		// 14. Atomic Idempotency Reservation:
		$idemp_token_key = null;
		if ( ! empty( $idempotency_key ) ) {
			$claim = Full_Elementor_MCP_Idempotency_Manager::claim(
				$idempotency_key,
				$ability,
				$user_id,
				$cred_uuid,
				$input,
				$owner_id
			);

			if ( is_wp_error( $claim ) ) {
				return $claim;
			}

			if ( 'completed' === ( $claim['status'] ?? '' ) ) {
				// Replay cached successful result without re-executing mutation:
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_IDEMPOTENT_REPLAY,
						array(
							'ability'         => $ability,
							'resource_key'    => $resource_key,
							'user_id'         => $user_id,
							'credential_uuid' => $cred_uuid,
							'severity'        => Full_Elementor_MCP_Audit_Logger::SEV_NOTICE,
							'metadata'        => array( 'idempotency_key' => $idempotency_key ),
						)
					);
				}
				return $claim['result'] ?? array( 'success' => true );
			}

			$idemp_token_key = $claim['token_key'] ?? null;
		}

		// 14.5. Managed Safety Action Execution (Phase 6):
		// Checkpoint restores and Undos manage their own WAL / locks.
		// Caller input must NEVER declare this; it is strictly read from Mutation Registry.
		if ( $is_managed_safety ) {
			$delegate = $strategy['managed_delegate'] ?? null;
			if ( ! is_callable( $delegate ) ) {
				if ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, 'invalid_safety_delegate' );
				}
				return new \WP_Error(
					'invalid_safety_delegate',
					__( 'Internal error: registered safety action delegate is not callable.', 'full-elementor-mcp' ),
					array( 'ability' => $ability )
				);
			}

			// Atomically consume confirmation token if required:
			if ( $requires_confirmation && ! empty( $confirmation_token ) ) {
				$consume_res = Full_Elementor_MCP_Confirmation_Manager::consume(
					$confirmation_token,
					$ability,
					$input,
					$user_id,
					$cred_uuid,
					$resource_key
				);
				if ( is_wp_error( $consume_res ) ) {
					if ( $idemp_token_key ) {
						Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $consume_res->get_error_code() );
					}
					return $consume_res;
				}
			}

			if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
				Full_Elementor_MCP_Audit_Logger::log(
					Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_STARTED,
					array(
						'event_uuid'   => $req_uuid,
						'request_uuid' => $req_uuid,
						'ability'      => $ability,
						'resource_key' => $resource_key,
						'user_id'      => $user_id,
						'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_INFO,
						'args'         => $input,
					)
				);
			}

			// Execute managed safety delegate with context parameters:
			$delegate_args = array_merge(
				$input,
				array(
					'_request_uuid' => $req_uuid,
					'_owner_id'     => $owner_id,
					'_user_id'      => $user_id,
					'_cred_uuid'    => $cred_uuid,
					'_resource_key' => $resource_key,
				)
			);

			$delegate_result = call_user_func( $delegate, $delegate_args );

			if ( is_wp_error( $delegate_result ) ) {
				if ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $delegate_result->get_error_code() );
				}
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_FAILED,
						array(
							'event_uuid'   => wp_generate_uuid4(),
							'request_uuid' => $req_uuid,
							'ability'      => $ability,
							'resource_key' => $resource_key,
							'user_id'      => $user_id,
							'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_WARNING,
							'error_code'   => $delegate_result->get_error_code(),
							'metadata'     => array( 'error' => $delegate_result->get_error_message() ),
						)
					);
				}
				return $delegate_result;
			}

			// Complete idempotency claim:
			if ( $idemp_token_key ) {
				$res_arr = is_array( $delegate_result ) ? $delegate_result : array( 'result' => $delegate_result );
				$journal_id = isset( $delegate_result['journal_id'] ) ? (int) $delegate_result['journal_id'] : null;
				$created_id = isset( $delegate_result['created_id'] ) ? (int) $delegate_result['created_id'] : null;
				Full_Elementor_MCP_Idempotency_Manager::complete( $idemp_token_key, $owner_id, $res_arr, $journal_id, $created_id );
			}

			if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
				Full_Elementor_MCP_Audit_Logger::log(
					Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_COMMITTED,
					array(
						'event_uuid'   => wp_generate_uuid4(),
						'request_uuid' => $req_uuid,
						'ability'      => $ability,
						'resource_key' => $resource_key,
						'user_id'      => $user_id,
						'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_INFO,
						'metadata'     => array(
							'managed_safety' => true,
							'result_status'  => is_array( $delegate_result ) ? ( $delegate_result['status'] ?? 'success' ) : 'success',
						),
					)
				);
			}

			return $delegate_result;
		}

		// 15. Acquire Phase 1 Lock:
		$lock_result = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );
		if ( is_wp_error( $lock_result ) ) {
			if ( $idemp_token_key ) {
				Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $lock_result->get_error_code() );
			}
			return $lock_result;
		}

		$fencing_token = (int) ( $lock_result['fencing_token'] ?? 0 );

		// 16. Pre-State Capture & WAL Journal Begin:
		$before_state = null;
		$is_create    = ! empty( $strategy['created_object_tracking'] ) || Full_Elementor_MCP_Mutation_Registry::CATEGORY_WP_OBJECT_CREATE === $strategy['category'];
		if ( ! $is_create && Full_Elementor_MCP_Mutation_Registry::supports_rollback_for_args( $ability, $input ) ) {
			if ( isset( $strategy['capture_before'] ) && is_callable( $strategy['capture_before'] ) ) {
				$before_state = call_user_func( $strategy['capture_before'], $object_id, $input );
				if ( is_wp_error( $before_state ) ) {
					Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
					if ( $idemp_token_key ) {
						Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $before_state->get_error_code() );
					}
					return $before_state;
				}
			}
		}

		$journal_params = array(
			'ability'         => $ability,
			'fencing_token'   => $fencing_token,
			'resource_key'    => $resource_key,
			'object_id'       => $object_id,
			'args'            => $input,
			'user_id'         => $user_id,
			'credential_uuid' => $cred_uuid,
		);
		if ( null !== $before_state ) {
			$journal_params['before_state'] = $before_state;
		}

		$journal_result = Full_Elementor_MCP_Journal::begin( $journal_params );

		if ( is_wp_error( $journal_result ) ) {
			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			if ( $idemp_token_key ) {
				Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $journal_result->get_error_code() );
			}
			return $journal_result;
		}

		$journal_id = (int) $journal_result;

		// 17. Attach WAL Journal to Idempotency Claim:
		if ( $idemp_token_key ) {
			$attach_res = Full_Elementor_MCP_Idempotency_Manager::attach_journal_id( $idemp_token_key, $owner_id, $journal_id );
			if ( true !== $attach_res ) {
				Full_Elementor_MCP_Journal::mark_failed( $journal_id, 'idempotency_journal_attachment_failed', $fencing_token );
				Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
				return is_wp_error( $attach_res )
					? $attach_res
					: new \WP_Error( 'idempotency_journal_binding_failed', __( 'Could not attach WAL journal to idempotency claim.', 'full-elementor-mcp' ) );
			}
		}

		// 17.5. Durable Encrypted Checkpoint Policy Gate:
		$checkpoint_info = null;
		if ( defined( 'FULL_ELEMENTOR_MCP_PHASE5_ACTIVE' ) && ! class_exists( 'Full_Elementor_MCP_Checkpoint_Manager' ) ) {
			Full_Elementor_MCP_Journal::mark_failed( $journal_id, 'checkpoint_infrastructure_unavailable', $fencing_token );
			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			if ( $idemp_token_key ) {
				Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, 'checkpoint_infrastructure_unavailable' );
			}
			return new \WP_Error(
				'checkpoint_infrastructure_unavailable',
				__( 'Fatal safety error: checkpoint subsystem is unavailable for required checkpoint mutation.', 'full-elementor-mcp' ),
				array( 'ability' => $ability )
			);
		}

		if ( class_exists( 'Full_Elementor_MCP_Checkpoint_Manager' ) ) {
			if ( ! class_exists( 'Full_Elementor_MCP_Checkpoint_Crypto' ) || ! class_exists( 'Full_Elementor_MCP_Checkpoint_Strategies' ) ) {
				Full_Elementor_MCP_Journal::mark_failed( $journal_id, 'checkpoint_infrastructure_unavailable', $fencing_token );
				Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
				if ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, 'checkpoint_infrastructure_unavailable' );
				}
				return new \WP_Error(
					'checkpoint_infrastructure_unavailable',
					__( 'Fatal safety error: checkpoint subsystem is unavailable for required checkpoint mutation.', 'full-elementor-mcp' ),
					array( 'ability' => $ability )
				);
			}

			$checkpoint_req   = Full_Elementor_MCP_Checkpoint_Manager::get_checkpoint_requirement( $ability, $input, $strategy );
			$caller_requested = ! empty( $input['create_checkpoint'] ) || ! empty( $input['_safety']['create_checkpoint'] );

			$should_create_checkpoint = ( Full_Elementor_MCP_Checkpoint_Manager::REQUIREMENT_REQUIRED === $checkpoint_req )
				|| ( Full_Elementor_MCP_Checkpoint_Manager::REQUIREMENT_OPTIONAL === $checkpoint_req && $caller_requested );

			if ( $should_create_checkpoint ) {
				$chk_meta = array(
					'source_ability'      => $ability,
					'source_journal_id'   => $journal_id,
					'user_id'             => $user_id,
					'credential_uuid'     => $cred_uuid,
					'is_permanent_delete' => ! empty( $security_profile['irreversible'] ),
				);

				$chk_type = ! empty( $security_profile['irreversible'] ) ? 'recovery' : 'automatic';
				$chk_res  = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( $resource_key, $chk_type, $chk_meta );

				if ( is_wp_error( $chk_res ) ) {
					if ( Full_Elementor_MCP_Checkpoint_Manager::REQUIREMENT_REQUIRED === $checkpoint_req ) {
						Full_Elementor_MCP_Journal::mark_failed( $journal_id, $chk_res->get_error_code(), $fencing_token );
						Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
						if ( $idemp_token_key ) {
							Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $chk_res->get_error_code() );
						}
						return $chk_res;
					}
				} else {
					$checkpoint_info = $chk_res;
				}
			}
		}

		// 18. Enter Request-Local Execution Context:
		$context_token = null;
		try {
			$context_token = Full_Elementor_MCP_Mutation_Context::enter(
				array(
					'ability'         => $ability,
					'request_uuid'    => $req_uuid,
					'user_id'         => $user_id,
					'credential_uuid' => $cred_uuid,
					'resource_key'    => $resource_key,
					'object_id'       => $object_id,
					'owner_id'        => $owner_id,
					'fencing_token'   => $fencing_token,
					'journal_id'      => $journal_id,
					'idempotency_key' => $idempotency_key,
					'is_dry_run'      => false,
					'is_rollback'     => false,
					'is_readonly'     => false,
					'is_create'       => $is_create,
				)
			);
		} catch ( \Throwable $ctx_err ) {
			Full_Elementor_MCP_Journal::mark_failed( $journal_id, $ctx_err->getMessage(), $fencing_token );
			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			if ( $idemp_token_key ) {
				Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $ctx_err->getMessage() );
			}
			return new \WP_Error( 'mutation_context_failed', __( 'Mutation context initialization failed.', 'full-elementor-mcp' ) );
		}

		// 19. Execute Original Ability Callback:
		$result     = null;
		$created_id = null;

		try {
			if ( ! is_callable( $orig_execute_cb ) ) {
				throw new \RuntimeException( 'original_callback_not_callable' );
			}

			// Pre-execution fencing assertion:
			$fence_preflight = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $fencing_token );
			if ( is_wp_error( $fence_preflight ) ) {
				Full_Elementor_MCP_Journal::mark_failed( $journal_id, $fence_preflight->get_error_code(), $fencing_token );
				if ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $fence_preflight->get_error_code() );
				}
				return $fence_preflight;
			}

			// Atomically consume confirmation token ONLY immediately before execution:
			if ( $requires_confirmation && ! empty( $confirmation_token ) ) {
				$consume_res = Full_Elementor_MCP_Confirmation_Manager::consume(
					$confirmation_token,
					$ability,
					$input,
					$user_id,
					$cred_uuid,
					$resource_key
				);
				if ( is_wp_error( $consume_res ) ) {
					Full_Elementor_MCP_Journal::mark_failed( $journal_id, $consume_res->get_error_code(), $fencing_token );
					if ( $idemp_token_key ) {
						Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $consume_res->get_error_code() );
					}
					return $consume_res;
				}
			}

			if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
				Full_Elementor_MCP_Audit_Logger::log(
					Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_STARTED,
					array(
						'event_uuid'   => $req_uuid,
						'request_uuid' => $req_uuid,
						'ability'      => $ability,
						'resource_key' => $resource_key,
						'journal_id'   => $journal_id,
						'user_id'      => $user_id,
						'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_INFO,
						'args'         => $input,
					)
				);
			}

			$result = call_user_func( $orig_execute_cb, $input );

			if ( is_wp_error( $result ) ) {
				$write_started = Full_Elementor_MCP_Mutation_Context::has_write_started();
				$err_data      = $result->get_error_data();
				$must_recover  = is_array( $err_data ) && ! empty( $err_data['recovery_required'] );

				if ( $write_started ) {
					$rollback_res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, $fencing_token );
					if ( ( is_wp_error( $rollback_res ) || $must_recover ) && $idemp_token_key ) {
						Full_Elementor_MCP_Idempotency_Manager::mark_recovery_required( $idemp_token_key, $owner_id, $journal_id, $result->get_error_code() );
					} elseif ( $idemp_token_key ) {
						Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $result->get_error_code() );
					}

					if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
						$ev_type = ( is_wp_error( $rollback_res ) || $must_recover )
							? Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_RECOVERY_REQUIRED
							: Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_ROLLED_BACK;
						$ev_sev  = ( is_wp_error( $rollback_res ) || $must_recover )
							? Full_Elementor_MCP_Audit_Logger::SEV_CRITICAL
							: Full_Elementor_MCP_Audit_Logger::SEV_WARNING;
						Full_Elementor_MCP_Audit_Logger::log(
							$ev_type,
							array(
								'event_uuid'   => wp_generate_uuid4(),
								'request_uuid' => $req_uuid,
								'ability'      => $ability,
								'resource_key' => $resource_key,
								'journal_id'   => $journal_id,
								'user_id'      => $user_id,
								'severity'     => $ev_sev,
								'error_code'   => $result->get_error_code(),
							)
						);
					}
				} else {
					Full_Elementor_MCP_Journal::mark_failed( $journal_id, $result->get_error_code(), $fencing_token );
					if ( $idemp_token_key ) {
						Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $result->get_error_code() );
					}
					if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
						Full_Elementor_MCP_Audit_Logger::log(
							Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_FAILED,
							array(
								'event_uuid'   => wp_generate_uuid4(),
								'request_uuid' => $req_uuid,
								'ability'      => $ability,
								'resource_key' => $resource_key,
								'journal_id'   => $journal_id,
								'user_id'      => $user_id,
								'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_WARNING,
								'error_code'   => $result->get_error_code(),
							)
						);
					}
				}
				return $result;
			}

			// 20. For CREATE mutations: verify created object ID against durable bound ID:
			if ( $is_create ) {
				$current_ctx = Full_Elementor_MCP_Mutation_Context::current();
				$bound_id    = absint( $current_ctx['created_object_id'] ?? 0 );
				$callback_id = Full_Elementor_MCP_Mutation_Registry::resolve_created_object_id( $ability, $result );

				if ( $bound_id > 0 && $callback_id > 0 && $bound_id !== $callback_id ) {
					// Mismatch: bound=123, callback=124
					$error_code = 'created_object_result_mismatch';
					$error_msg  = sprintf(
						/* translators: 1: bound ID, 2: callback ID */
						__( 'Durable created object ID (%1$d) does not match callback declared ID (%2$d).', 'full-elementor-mcp' ),
						$bound_id,
						$callback_id
					);
					$error_data = array(
						'bound_object_id'    => $bound_id,
						'callback_object_id' => $callback_id,
					);
				} elseif ( $bound_id > 0 && $callback_id <= 0 ) {
					// Missing callback ID: bound=123, callback=0
					$error_code = 'created_object_result_missing';
					$error_msg  = sprintf(
						/* translators: %d: bound ID */
						__( 'Durable created object ID (%d) exists but callback result is missing the required ID field.', 'full-elementor-mcp' ),
						$bound_id
					);
					$error_data = array(
						'bound_object_id' => $bound_id,
					);
				} elseif ( $bound_id <= 0 && $callback_id > 0 ) {
					// Identity not durable: bound=0, callback=123
					$error_code = 'created_object_identity_not_durable';
					$error_msg  = sprintf(
						/* translators: %d: callback ID */
						__( 'Callback returned created object ID (%d) but object identity was not durably recorded during creation.', 'full-elementor-mcp' ),
						$callback_id
					);
					$error_data = array(
						'callback_object_id' => $callback_id,
					);
				} elseif ( $bound_id <= 0 && $callback_id <= 0 ) {
					// Unresolved: bound=0, callback=0
					$error_code = 'created_object_id_unresolved';
					$error_msg  = __( 'Creation completed but created object ID could not be detected or durably bound.', 'full-elementor-mcp' );
					$error_data = array();
				} else {
					$error_code = null;
				}

				if ( null !== $error_code ) {
					$write_started = Full_Elementor_MCP_Mutation_Context::has_write_started() || $bound_id > 0;
					if ( $write_started ) {
						$rollback_res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, $fencing_token );
						if ( is_wp_error( $rollback_res ) && $idemp_token_key ) {
							Full_Elementor_MCP_Idempotency_Manager::mark_recovery_required( $idemp_token_key, $owner_id, $journal_id, $error_code );
						} elseif ( $idemp_token_key ) {
							Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $error_code );
						}
						if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
							$ev_type = is_wp_error( $rollback_res )
								? Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_RECOVERY_REQUIRED
								: Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_ROLLED_BACK;
							$ev_sev  = is_wp_error( $rollback_res )
								? Full_Elementor_MCP_Audit_Logger::SEV_CRITICAL
								: Full_Elementor_MCP_Audit_Logger::SEV_WARNING;
							Full_Elementor_MCP_Audit_Logger::log(
								$ev_type,
								array(
									'event_uuid'   => wp_generate_uuid4(),
									'request_uuid' => $req_uuid,
									'ability'      => $ability,
									'resource_key' => $resource_key,
									'journal_id'   => $journal_id,
									'user_id'      => $user_id,
									'severity'     => $ev_sev,
									'error_code'   => $error_code,
								)
							);
						}
					} else {
						Full_Elementor_MCP_Journal::mark_failed( $journal_id, $error_code, $fencing_token );
						if ( $idemp_token_key ) {
							Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $error_code );
						}
						if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
							Full_Elementor_MCP_Audit_Logger::log(
								Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_FAILED,
								array(
									'event_uuid'   => wp_generate_uuid4(),
									'request_uuid' => $req_uuid,
									'ability'      => $ability,
									'resource_key' => $resource_key,
									'journal_id'   => $journal_id,
									'user_id'      => $user_id,
									'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_WARNING,
									'error_code'   => $error_code,
								)
							);
						}
					}
					return new \WP_Error( $error_code, $error_msg, $error_data );
				}

				$created_id = $bound_id;
			}

			// 21. Post-Mutation Validation & Same-Generation Rollback on Corruption:
			if ( ! empty( $strategy['requires_tree_validation'] ) ) {
				$target_post_id = $object_id > 0 ? $object_id : ( $created_id ?? 0 );
				if ( $target_post_id > 0 && function_exists( 'get_post_meta' ) ) {
					$tree_read = self::read_persisted_elementor_tree( $target_post_id );

					if ( is_wp_error( $tree_read ) ) {
						// Malformed, scalar, or unreadable tree! Attempt same-generation rollback:
						$rollback_res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, $fencing_token );
						if ( is_wp_error( $rollback_res ) && $idemp_token_key ) {
							Full_Elementor_MCP_Idempotency_Manager::mark_recovery_required( $idemp_token_key, $owner_id, $journal_id, $tree_read->get_error_code() );
						} elseif ( $idemp_token_key ) {
							Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $tree_read->get_error_code() );
						}
						if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
							$ev_type = is_wp_error( $rollback_res )
								? Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_RECOVERY_REQUIRED
								: Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_ROLLED_BACK;
							$ev_sev  = is_wp_error( $rollback_res )
								? Full_Elementor_MCP_Audit_Logger::SEV_CRITICAL
								: Full_Elementor_MCP_Audit_Logger::SEV_WARNING;
							Full_Elementor_MCP_Audit_Logger::log(
								$ev_type,
								array(
									'event_uuid'   => wp_generate_uuid4(),
									'request_uuid' => $req_uuid,
									'ability'      => $ability,
									'resource_key' => $resource_key,
									'journal_id'   => $journal_id,
									'user_id'      => $user_id,
									'severity'     => $ev_sev,
									'error_code'   => $tree_read->get_error_code(),
								)
							);
						}
						return $tree_read;
					}

					$post_tree_check = Full_Elementor_MCP_Tree_Validator::validate_document( $tree_read );
					if ( is_wp_error( $post_tree_check ) ) {
						// Post-mutation validation failed! Attempt safe same-generation rollback while holding lock:
						$rollback_res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, $fencing_token );
						if ( is_wp_error( $rollback_res ) ) {
							if ( $idemp_token_key ) {
								Full_Elementor_MCP_Idempotency_Manager::mark_recovery_required( $idemp_token_key, $owner_id, $journal_id, 'post_mutation_validation_failed' );
							}
						} else {
							if ( $idemp_token_key ) {
								Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, 'post_mutation_validation_failed' );
							}
						}
						if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
							$ev_type = is_wp_error( $rollback_res )
								? Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_RECOVERY_REQUIRED
								: Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_ROLLED_BACK;
							$ev_sev  = is_wp_error( $rollback_res )
								? Full_Elementor_MCP_Audit_Logger::SEV_CRITICAL
								: Full_Elementor_MCP_Audit_Logger::SEV_WARNING;
							Full_Elementor_MCP_Audit_Logger::log(
								$ev_type,
								array(
									'event_uuid'   => wp_generate_uuid4(),
									'request_uuid' => $req_uuid,
									'ability'      => $ability,
									'resource_key' => $resource_key,
									'journal_id'   => $journal_id,
									'user_id'      => $user_id,
									'severity'     => $ev_sev,
									'error_code'   => 'post_mutation_validation_failed',
								)
							);
						}
						return new \WP_Error(
							'post_mutation_validation_failed',
							sprintf(
								/* translators: 1: error code, 2: rollback status */
								__( 'Persistent Elementor tree validation failed after mutation (%1$s). Same-generation rollback executed: %2$s.', 'full-elementor-mcp' ),
								$post_tree_check->get_error_code(),
								is_wp_error( $rollback_res ) ? 'failed' : 'restored'
							),
							array(
								'validation_error' => $post_tree_check->get_error_code(),
								'rollback_status'  => is_wp_error( $rollback_res ) ? $rollback_res->get_error_code() : 'rolled_back',
							)
						);
					}
				}
			}

			// 22. Commit Journal Durably:
			$after_state = null;
			if ( ! $is_create ) {
				if ( isset( $strategy['capture_after'] ) && is_callable( $strategy['capture_after'] ) ) {
					$after_state = call_user_func( $strategy['capture_after'], $object_id, $input, $before_state );
				} elseif ( isset( $strategy['capture_before'] ) && is_callable( $strategy['capture_before'] ) ) {
					$after_state = call_user_func( $strategy['capture_before'], $object_id, $input );
				} else {
					$after_state = $result;
				}
			}

			$commit_res = Full_Elementor_MCP_Journal::commit( $journal_id, $after_state, $fencing_token );
			if ( is_wp_error( $commit_res ) ) {
				if ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::mark_recovery_required( $idemp_token_key, $owner_id, $journal_id, $commit_res->get_error_code() );
				}
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_FAILED,
						array(
							'event_uuid'   => wp_generate_uuid4(),
							'request_uuid' => $req_uuid,
							'ability'      => $ability,
							'resource_key' => $resource_key,
							'journal_id'   => $journal_id,
							'user_id'      => $user_id,
							'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_CRITICAL,
							'error_code'   => $commit_res->get_error_code(),
						)
					);
				}
				return $commit_res;
			}

			// Complete Idempotency Record:
			if ( $idemp_token_key ) {
				$res_arr = is_array( $result ) ? $result : array( 'result' => $result );
				Full_Elementor_MCP_Idempotency_Manager::complete( $idemp_token_key, $owner_id, $res_arr, $journal_id, $created_id );
			}

			if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
				Full_Elementor_MCP_Audit_Logger::log(
					Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_COMMITTED,
					array(
						'event_uuid'   => wp_generate_uuid4(),
						'request_uuid' => $req_uuid,
						'ability'      => $ability,
						'resource_key' => $resource_key,
						'journal_id'   => $journal_id,
						'user_id'      => $user_id,
						'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_INFO,
						'metadata'     => array(
							'created_id' => $created_id,
						),
					)
				);
			}

		} catch ( \Throwable $e ) {
			$raw_msg   = $e->getMessage();
			$clean_msg = preg_replace( '/[A-Z]:\\\\[^\s]+/i', '[path]', $raw_msg );
			$write_started = Full_Elementor_MCP_Mutation_Context::has_write_started();

			if ( $write_started ) {
				$rollback_res = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, $fencing_token );
				if ( is_wp_error( $rollback_res ) && $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::mark_recovery_required( $idemp_token_key, $owner_id, $journal_id, $raw_msg );
				} elseif ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $raw_msg );
				}
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					$ev_type = is_wp_error( $rollback_res )
						? Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_RECOVERY_REQUIRED
						: Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_ROLLED_BACK;
					$ev_sev  = is_wp_error( $rollback_res )
						? Full_Elementor_MCP_Audit_Logger::SEV_CRITICAL
						: Full_Elementor_MCP_Audit_Logger::SEV_WARNING;
					Full_Elementor_MCP_Audit_Logger::log(
						$ev_type,
						array(
							'event_uuid'   => wp_generate_uuid4(),
							'request_uuid' => $req_uuid,
							'ability'      => $ability,
							'resource_key' => $resource_key,
							'journal_id'   => $journal_id,
							'user_id'      => $user_id,
							'severity'     => $ev_sev,
							'error_code'   => 'mutation_exception',
							'metadata'     => array( 'error' => $clean_msg ),
						)
					);
				}
			} else {
				Full_Elementor_MCP_Journal::mark_failed( $journal_id, $raw_msg, $fencing_token );
				if ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::fail_safe( $idemp_token_key, $owner_id, $raw_msg );
				}
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_MUTATION_FAILED,
						array(
							'event_uuid'   => wp_generate_uuid4(),
							'request_uuid' => $req_uuid,
							'ability'      => $ability,
							'resource_key' => $resource_key,
							'journal_id'   => $journal_id,
							'user_id'      => $user_id,
							'severity'     => Full_Elementor_MCP_Audit_Logger::SEV_WARNING,
							'error_code'   => 'mutation_exception',
							'metadata'     => array( 'error' => $clean_msg ),
						)
					);
				}
			}

			return new \WP_Error(
				'mutation_exception',
				__( 'Mutation encountered unexpected runtime exception.', 'full-elementor-mcp' ),
				array( 'message' => $clean_msg )
			);
		} finally {
			// Invariant: Context is ALWAYS cleared and lock released in finally:
			if ( $context_token ) {
				Full_Elementor_MCP_Mutation_Context::leave( $context_token );
			}
			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
		}

		return $result;
	}

	/**
	 * Authoritatively reads and decodes the persisted Elementor document tree for a post.
	 *
	 * Fails closed if the tree is malformed JSON, a scalar, not an array, or an unreadable type.
	 *
	 * @param int $post_id Post ID.
	 * @return array|\WP_Error Decoded array or WP_Error on unverifiable state.
	 */
	public static function read_persisted_elementor_tree( int $post_id ): array|\WP_Error {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return new \WP_Error( 'post_mutation_state_unverifiable', __( 'Cannot read persisted tree: invalid post ID.', 'full-elementor-mcp' ) );
		}

		$saved_raw = get_post_meta( $post_id, '_elementor_data', true );

		// Case 1: Already an array (e.g. cached/filtered memory representation):
		if ( is_array( $saved_raw ) ) {
			return $saved_raw;
		}

		// Case 2: Meta not set / null:
		if ( null === $saved_raw ) {
			return array(); // Explicitly valid empty tree.
		}

		// Case 3: String:
		if ( is_string( $saved_raw ) ) {
			$trimmed = trim( $saved_raw );
			if ( '' === $trimmed || '[]' === $trimmed ) {
				return array(); // Explicitly valid empty tree.
			}

			$decoded = json_decode( $saved_raw, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new \WP_Error(
					'post_mutation_state_unverifiable',
					sprintf(
						/* translators: %s: json error */
						__( 'Persistent _elementor_data contains malformed JSON (%s).', 'full-elementor-mcp' ),
						json_last_error_msg()
					),
					array( 'json_error' => json_last_error() )
				);
			}

			if ( ! is_array( $decoded ) ) {
				return new \WP_Error(
					'post_mutation_state_unverifiable',
					__( 'Persistent _elementor_data is not an array structure (scalar JSON detected).', 'full-elementor-mcp' )
				);
			}

			return $decoded;
		}

		// Case 3: Any other type (false, null, int, object, resource):
		return new \WP_Error(
			'post_mutation_state_unverifiable',
			__( 'Persistent _elementor_data has unreadable or unexpected storage type.', 'full-elementor-mcp' )
		);
	}

	/**
	 * Handles unexpected PHP process shutdown while a mutation context is active.
	 *
	 * Preserves durable WAL journal row as 'pending' so that next-request
	 * recovery can detect and recover it. Never transitions pending -> failed on uncertain state.
	 */
	public static function handle_shutdown(): void {
		if ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) && Full_Elementor_MCP_Mutation_Context::has_active_context() ) {
			$ctx = Full_Elementor_MCP_Mutation_Context::current();

			// Do NOT transition journal pending -> failed.
			// Preserve journal as 'pending' for next-request Full_Elementor_MCP_Journal::recover_pending().

			if ( ! empty( $ctx['idempotency_key'] ) && class_exists( 'Full_Elementor_MCP_Idempotency_Manager' ) && ! empty( $ctx['owner_id'] ) && class_exists( 'Full_Elementor_MCP_Lock_Manager' ) ) {
				$token_key = Full_Elementor_MCP_Lock_Manager::get_idempotency_token_key(
					(string) $ctx['idempotency_key'],
					(string) ( $ctx['ability'] ?? '' ),
					(int) ( $ctx['user_id'] ?? 0 ),
					$ctx['credential_uuid'] ?? null
				);
				if ( ! empty( $ctx['write_started'] ) ) {
					Full_Elementor_MCP_Idempotency_Manager::mark_recovery_required(
						$token_key,
						(string) $ctx['owner_id'],
						(int) ( $ctx['journal_id'] ?? 0 ),
						'unexpected_script_shutdown'
					);
				} else {
					Full_Elementor_MCP_Idempotency_Manager::fail_safe(
						$token_key,
						(string) $ctx['owner_id'],
						'unexpected_script_shutdown'
					);
				}
			}

			Full_Elementor_MCP_Mutation_Context::reset();
		}
	}

	/**
	 * Extracts the created object ID from the callback result.
	 *
	 * @param array<string, mixed> $strategy
	 * @param mixed                $result
	 * @return int Created object ID or 0 if unresolvable.
	 */
	public static function extract_created_object_id( array $strategy, mixed $result ): int {
		if ( ! is_array( $result ) ) {
			return 0;
		}

		if ( isset( $strategy['created_object_id_resolver'] ) && is_callable( $strategy['created_object_id_resolver'] ) ) {
			return absint( call_user_func( $strategy['created_object_id_resolver'], $result ) );
		}

		return 0;
	}

	/**
	 * Returns the registered ability definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_registered_abilities(): array {
		return self::$ability_registry;
	}

	/**
	 * Resets registered abilities for testing.
	 */
	public static function reset(): void {
		self::$ability_registry = array();
	}
}
