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

		// 3. Resolve ability registration definition:
		$def = self::$ability_registry[ $ability ] ?? null;
		$is_readonly = false;

		if ( null !== $def ) {
			$is_readonly = isset( $def['meta']['annotations']['readonly'] ) && true === $def['meta']['annotations']['readonly'];
		}

		// 4. Global disabled tool gate (applies to readonly and mutations alike):
		$disabled_tools = get_option( 'full_elementor_mcp_disabled_tools', array() );
		if ( is_array( $disabled_tools ) && in_array( $ability, $disabled_tools, true ) ) {
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
		$scope = class_exists( 'Full_Elementor_MCP_Security_Guard' )
			? Full_Elementor_MCP_Security_Guard::resolve_current_scope()
			: array( 'mode' => 'full' );

		if ( ! $is_readonly && 'read_only' === ( $scope['mode'] ?? '' ) ) {
			return new \WP_Error(
				'credential_scope_readonly',
				__( 'Mutation rejected: active credential scope is read-only.', 'full-elementor-mcp' ),
				array( 'ability' => $ability )
			);
		}

		if ( class_exists( 'Full_Elementor_MCP_Security_Guard' ) ) {
			$annotations = array( 'readonly' => $is_readonly );
			if ( ! Full_Elementor_MCP_Security_Guard::is_ability_in_scope( $ability, $annotations, $scope ) ) {
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
				return new \WP_Error(
					'permission_denied',
					__( 'You do not have permission to execute this ability.', 'full-elementor-mcp' ),
					array( 'ability' => $ability )
				);
			}
		}

		$orig_execute_cb = $def['execute_callback'] ?? null;

		// ---------------------------------------------------------------------
		// READONLY ROUTE: Bypasses mutation WAL, lock, idempotency claim.
		// ---------------------------------------------------------------------
		if ( $is_readonly ) {
			if ( ! is_callable( $orig_execute_cb ) ) {
				return new \WP_Error( 'execute_callback_missing', __( 'Ability execute callback is not callable.', 'full-elementor-mcp' ) );
			}
			return call_user_func( $orig_execute_cb, $input );
		}

		// ---------------------------------------------------------------------
		// MUTATION ROUTE: Full centralized safety orchestration.
		// ---------------------------------------------------------------------

		// Re-entrancy safety: exactly one forward mutation context is permitted per request/thread.
		// Nested forward mutations must fail closed immediately.
		if ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) && Full_Elementor_MCP_Mutation_Context::has_active_context() ) {
			$active_ctx = Full_Elementor_MCP_Mutation_Context::current();
			if ( empty( $active_ctx['is_rollback'] ) ) {
				return new \WP_Error(
					'nested_mutation_not_supported',
					__( 'Nested forward mutations are not supported. Elementor mutations must not recursively mutate under active contexts.', 'full-elementor-mcp' ),
					array( 'ability' => $ability )
				);
			}
		}

		// Invariant: registered ability says readonly=false => registry strategy MUST exist.
		if ( ! class_exists( 'Full_Elementor_MCP_Mutation_Registry' ) ) {
			return new \WP_Error( 'safety_registry_unavailable', __( 'Mutation registry unavailable.', 'full-elementor-mcp' ) );
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

		// 7. Security Profile & unfiltered_html capability check:
		$security_profile = array();
		if ( class_exists( 'Full_Elementor_MCP_Security_Strategies' ) ) {
			$security_profile = Full_Elementor_MCP_Security_Strategies::get_security_profile( $ability, $input );
		}

		if ( ! empty( $security_profile['requires_unfiltered_html'] ) ) {
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'unfiltered_html' ) ) {
				return new \WP_Error(
					'unfiltered_html_required',
					__( 'This mutation contains raw HTML, script, or stylesheet rules requiring the unfiltered_html capability.', 'full-elementor-mcp' ),
					array( 'ability' => $ability )
				);
			}
		}

		// 8. Resolve canonical resource key & object ID:
		$resource_key = Full_Elementor_MCP_Mutation_Registry::resolve_resource_key( $ability, $input );
		$object_id    = Full_Elementor_MCP_Mutation_Registry::resolve_object_id( $ability, $input );
		$user_id      = (int) ( $scope['user_id'] ?? get_current_user_id() );
		$cred_uuid    = $scope['credential_uuid'] ?? null;

		// 9. High-Risk / Protected / Irreversible Confirmation Gate:
		$requires_confirmation = ! empty( $security_profile['high_risk'] )
			|| ! empty( $security_profile['irreversible'] )
			|| ! empty( $security_profile['protected_resource_possible'] )
			|| ! empty( $security_profile['executable_content'] );

		if ( $requires_confirmation && class_exists( 'Full_Elementor_MCP_Confirmation_Manager' ) ) {
			if ( ! empty( $confirmation_token ) ) {
				$val_res = Full_Elementor_MCP_Confirmation_Manager::validate_and_consume(
					$confirmation_token,
					$ability,
					$input,
					$user_id,
					$cred_uuid,
					$resource_key,
					! $is_dry_run // Do not consume token permanently during dry-run
				);
				if ( is_wp_error( $val_res ) ) {
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
				return new \WP_Error(
					'confirmation_required',
					__( 'This operation is classified as high-risk, irreversible, or targets a protected resource and requires confirmation.', 'full-elementor-mcp' ),
					$challenge
				);
			}
		}

		// 10. Dry-Run Handling:
		if ( $is_dry_run ) {
			return array(
				'dry_run'               => true,
				'allowed'               => true,
				'ability'               => $ability,
				'resource_key'          => $resource_key,
				'object_id'             => $object_id,
				'security_profile'      => $security_profile,
				'protected_resource'    => ! empty( $security_profile['protected_resource_possible'] ),
				'confirmation_required' => $requires_confirmation,
				'rollback_supported'    => Full_Elementor_MCP_Mutation_Registry::supports_rollback( $ability ),
				'reasons'               => $security_profile['reasons'] ?? array(),
			);
		}

		// 11. Server-Generated Execution UUID & Owner ID:
		$req_uuid = wp_generate_uuid4();
		if ( empty( $req_uuid ) ) {
			$req_uuid = bin2hex( random_bytes( 16 ) );
		}
		$owner_id = 'mw_' . $req_uuid;

		// 12. Atomic Idempotency Reservation:
		$idemp_token_key = null;
		if ( ! empty( $idempotency_key ) && class_exists( 'Full_Elementor_MCP_Idempotency_Manager' ) ) {
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
				return $claim['result'] ?? array( 'success' => true );
			}

			$idemp_token_key = $claim['token_key'] ?? null;
		}

		// 13. Acquire Phase 1 Lock:
		if ( ! class_exists( 'Full_Elementor_MCP_Lock_Manager' ) ) {
			if ( $idemp_token_key ) {
				Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, 'lock_manager_unavailable' );
			}
			return new \WP_Error( 'lock_manager_unavailable', __( 'Lock Manager is unavailable.', 'full-elementor-mcp' ) );
		}

		$lock_result = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 30 );
		if ( is_wp_error( $lock_result ) ) {
			if ( $idemp_token_key ) {
				Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, $lock_result->get_error_code() );
			}
			return $lock_result;
		}

		$fencing_token = (int) ( $lock_result['fencing_token'] ?? 0 );

		// 14. Pre-State Capture & WAL Journal Pending:
		if ( ! class_exists( 'Full_Elementor_MCP_Journal' ) ) {
			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			if ( $idemp_token_key ) {
				Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, 'journal_unavailable' );
			}
			return new \WP_Error( 'journal_unavailable', __( 'Write-Ahead Journal is unavailable.', 'full-elementor-mcp' ) );
		}

		$before_state = null;
		$is_create    = ! empty( $strategy['created_object_tracking'] ) || Full_Elementor_MCP_Mutation_Registry::CATEGORY_WP_OBJECT_CREATE === $strategy['category'];
		if ( ! $is_create && Full_Elementor_MCP_Mutation_Registry::supports_rollback_for_args( $ability, $input ) ) {
			if ( isset( $strategy['capture_before'] ) && is_callable( $strategy['capture_before'] ) ) {
				$before_state = call_user_func( $strategy['capture_before'], $object_id, $input );
				if ( is_wp_error( $before_state ) ) {
					Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
					if ( $idemp_token_key ) {
						Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, $before_state->get_error_code() );
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
				Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, $journal_result->get_error_code() );
			}
			return $journal_result;
		}

		$journal_id = (int) $journal_result;

		if ( $idemp_token_key ) {
			Full_Elementor_MCP_Idempotency_Manager::attach_journal_id( $idemp_token_key, $owner_id, $journal_id );
		}

		// 15. Enter Request-Local Execution Context:
		$context_token = null;
		if ( class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
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
					)
				);
			} catch ( \Throwable $ctx_err ) {
				Full_Elementor_MCP_Journal::mark_failed( $journal_id, $ctx_err->getMessage(), $fencing_token );
				Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
				if ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, $ctx_err->getMessage() );
				}
				return new \WP_Error( $ctx_err->getMessage(), __( 'Mutation context initialization failed.', 'full-elementor-mcp' ) );
			}
		}

		// 16. Execute Original Ability Callback:
		$result     = null;
		$created_id = null;
		$execution_failed = false;

		try {
			if ( ! is_callable( $orig_execute_cb ) ) {
				throw new \RuntimeException( 'original_callback_not_callable' );
			}

			// Pre-execution fencing assertion:
			$fence_preflight = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $resource_key, $owner_id, $fencing_token );
			if ( is_wp_error( $fence_preflight ) ) {
				$execution_failed = true;
				Full_Elementor_MCP_Journal::mark_failed( $journal_id, $fence_preflight->get_error_code(), $fencing_token );
				if ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, $fence_preflight->get_error_code() );
				}
				return $fence_preflight;
			}

			$result = call_user_func( $orig_execute_cb, $input );

			if ( is_wp_error( $result ) ) {
				$execution_failed = true;
				Full_Elementor_MCP_Journal::mark_failed( $journal_id, $result->get_error_code(), $fencing_token );
				if ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, $result->get_error_code() );
				}
				return $result;
			}

			// 17. For CREATE mutations: record created object ID immediately and durably:
			if ( ! empty( $strategy['created_object_tracking'] ) ) {
				$created_id = self::extract_created_object_id( $strategy, $result );
				if ( $created_id < 1 ) {
					$execution_failed = true;
					Full_Elementor_MCP_Journal::mark_failed( $journal_id, 'created_object_id_unresolved', $fencing_token );
					if ( $idemp_token_key ) {
						Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, 'created_object_id_unresolved' );
					}
					return new \WP_Error( 'created_object_id_unresolved', __( 'Creation completed but created object ID could not be detected.', 'full-elementor-mcp' ) );
				}

				$rec_res = Full_Elementor_MCP_Journal::record_created_object_id( $journal_id, $created_id, $fencing_token );
				if ( is_wp_error( $rec_res ) ) {
					$execution_failed = true;
					if ( $idemp_token_key ) {
						Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, $rec_res->get_error_code() );
					}
					return $rec_res;
				}

				if ( $context_token && class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
					Full_Elementor_MCP_Mutation_Context::update_current( array( 'created_object_id' => $created_id ) );
				}
			}

			// 18. Post-Mutation Validation & Same-Generation Rollback on Corruption:
			if ( ! empty( $strategy['requires_tree_validation'] ) && class_exists( 'Full_Elementor_MCP_Tree_Validator' ) ) {
				$target_post_id = $object_id > 0 ? $object_id : ( $created_id ?? 0 );
				if ( $target_post_id > 0 && function_exists( 'get_post_meta' ) ) {
					$saved_raw = get_post_meta( $target_post_id, '_elementor_data', true );
					if ( is_string( $saved_raw ) && '' !== $saved_raw ) {
						$saved_tree = json_decode( $saved_raw, true );
						if ( is_array( $saved_tree ) ) {
							$post_tree_check = Full_Elementor_MCP_Tree_Validator::validate_document( $saved_tree );
							if ( is_wp_error( $post_tree_check ) ) {
								// Post-mutation validation failed! Attempt safe same-generation rollback while holding lock:
								$execution_failed = true;
								$rollback_res     = Full_Elementor_MCP_Journal::rollback( $journal_id, $owner_id, $fencing_token );
								if ( $idemp_token_key ) {
									Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, 'post_mutation_validation_failed' );
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
				}
			}

			// 19. Commit Journal Durably:
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
				$execution_failed = true;
				if ( $idemp_token_key ) {
					Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, $commit_res->get_error_code() );
				}
				return $commit_res;
			}

			// 20. Complete Idempotency Record:
			if ( $idemp_token_key && class_exists( 'Full_Elementor_MCP_Idempotency_Manager' ) ) {
				$res_arr = is_array( $result ) ? $result : array( 'result' => $result );
				Full_Elementor_MCP_Idempotency_Manager::complete( $idemp_token_key, $owner_id, $res_arr, $journal_id, $created_id );
			}

		} catch ( \Throwable $e ) {
			$execution_failed = true;
			Full_Elementor_MCP_Journal::mark_failed( $journal_id, $e->getMessage(), $fencing_token );
			if ( $idemp_token_key && class_exists( 'Full_Elementor_MCP_Idempotency_Manager' ) ) {
				Full_Elementor_MCP_Idempotency_Manager::fail( $idemp_token_key, $owner_id, $e->getMessage() );
			}
			return new \WP_Error( 'mutation_exception', __( 'Mutation encountered unexpected runtime exception.', 'full-elementor-mcp' ), array( 'message' => $e->getMessage() ) );
		} finally {
			// Invariant: Context is ALWAYS cleared and lock released in finally:
			if ( $context_token && class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
				Full_Elementor_MCP_Mutation_Context::leave( $context_token );
			}
			if ( class_exists( 'Full_Elementor_MCP_Lock_Manager' ) ) {
				Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			}
		}

		return $result;
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

		$id = $result['post_id'] ?? ( $result['template_id'] ?? ( $result['snippet_id'] ?? ( $result['attachment_id'] ?? ( $result['id'] ?? ( $result['page_id'] ?? 0 ) ) ) ) );
		return absint( $id );
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
