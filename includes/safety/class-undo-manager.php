<?php
/**
 * Safe User-Facing Undo Manager for Full Elementor MCP.
 *
 * Orchestrates user-facing rollbacks with strict conflict detection,
 * durable pre-undo encrypted checkpoints, and frozen Phase 2 WAL rollback guarantees.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Undo Manager.
 *
 * @since 1.8.0
 */
final class Full_Elementor_MCP_Undo_Manager {

	/**
	 * Reverts a specific committed Journal mutation safely.
	 *
	 * Order of operations:
	 * 1. Validate journal entry existence, committed status, and rollback support.
	 * 2. If dry_run -> return predictive eligibility and stop.
	 * 3. Acquire canonical resource lock before capturing authoritative current state.
	 * 4. Capture current live resource state under lock.
	 * 5. Verify live state equals journal after-state (conflict detection). Never overwrite newer changes.
	 * 6. Create durable pre-undo encrypted checkpoint before modifying persistent state.
	 * 7. Execute frozen Phase 2 Journal::rollback() under active lock and fencing.
	 * 8. Verify exact persistent state matches journal before_hash.
	 * 9. Mark audit trail and return structured success result.
	 * 10. Always release lock safely in finally block.
	 *
	 * @param int                  $journal_id Target journal ID to revert.
	 * @param bool|array<string, mixed> $options    Execution options (dry_run, user_id, credential_uuid) or bool dry_run.
	 * @return array<string, mixed>|\WP_Error Result array or WP_Error.
	 */
	public static function undo_change( int $journal_id, $options = array() ) {
		global $wpdb;

		if ( is_bool( $options ) ) {
			$options = array( 'dry_run' => $options );
		} elseif ( ! is_array( $options ) ) {
			$options = array();
		}

		if ( $journal_id <= 0 ) {
			return new \WP_Error( 'invalid_journal_parameters', __( 'Invalid journal ID for undo.', 'full-elementor-mcp' ) );
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Journal' ) || ! class_exists( 'Full_Elementor_MCP_Lock_Manager' ) ) {
			return new \WP_Error( 'safety_dependency_missing', __( 'Safety subsystem unavailable.', 'full-elementor-mcp' ) );
		}

		// 1. Validate target journal entry:
		$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
		if ( empty( $entry ) ) {
			return new \WP_Error( 'change_not_found', __( 'Target journal change entry not found.', 'full-elementor-mcp' ), array( 'journal_id' => $journal_id ) );
		}

		if ( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK === $entry['status'] ) {
			return new \WP_Error(
				'change_already_undone',
				__( 'This change has already been rolled back.', 'full-elementor-mcp' ),
				array( 'journal_id' => $journal_id, 'status' => $entry['status'] )
			);
		}

		if ( Full_Elementor_MCP_Journal::STATUS_COMMITTED !== $entry['status'] ) {
			return new \WP_Error(
				'change_not_committed',
				sprintf(
					/* translators: %s: status */
					__( 'Only committed changes can be undone. Target status is "%s".', 'full-elementor-mcp' ),
					$entry['status']
				),
				array( 'journal_id' => $journal_id, 'status' => $entry['status'] )
			);
		}

		if ( empty( $entry['rollback_supported'] ) ) {
			return new \WP_Error(
				'change_not_rollbackable',
				__( 'This mutation was marked non-rollbackable (e.g. permanent deletion or irreversible external state).', 'full-elementor-mcp' ),
				array( 'journal_id' => $journal_id, 'ability' => $entry['ability'] )
			);
		}

		$strategy = Full_Elementor_MCP_Mutation_Registry::get( (string) $entry['ability'] );
		if ( empty( $strategy ) ) {
			return new \WP_Error(
				'strategy_not_found',
				sprintf(
					/* translators: %s: ability */
					__( 'No mutation strategy registered for original ability "%s".', 'full-elementor-mcp' ),
					$entry['ability']
				)
			);
		}

		$is_create = Full_Elementor_MCP_Mutation_Registry::is_create( (string) $entry['ability'] );
		if ( $is_create ) {
			$created_id = absint( $entry['created_object_id'] ?? 0 );
			if ( $created_id <= 0 ) {
				return new \WP_Error(
					'undo_target_unresolvable',
					__( 'Cannot undo creation mutation: created_object_id is missing or untracked.', 'full-elementor-mcp' ),
					array(
						'journal_id' => $journal_id,
						'ability'    => $entry['ability'],
					)
				);
			}
			$target_object_id    = $created_id;
			$target_resource_key = Full_Elementor_MCP_Mutation_Registry::resolve_rollback_resource_key(
				(string) $entry['ability'],
				$entry,
				array(
					'created_object_id'     => $target_object_id,
					'post_id'               => $target_object_id,
					'object_id'             => $target_object_id,
					'page_id'               => $target_object_id,
					'template_id'           => $target_object_id,
					'resource_key'          => (string) ( $entry['resource_key'] ?? '' ),
					'rollback_resource_key' => 'post:' . $target_object_id,
				)
			);
			if ( is_wp_error( $target_resource_key ) || '' === trim( (string) $target_resource_key ) ) {
				return new \WP_Error(
					'undo_target_unresolvable',
					__( 'Could not resolve authoritative target resource key for undo.', 'full-elementor-mcp' ),
					array(
						'journal_id' => $journal_id,
						'ability'    => $entry['ability'],
					)
				);
			}
			$target_resource_key = (string) $target_resource_key;
		} else {
			$target_object_id    = (int) $entry['object_id'];
			$target_resource_key = Full_Elementor_MCP_Mutation_Registry::resolve_rollback_resource_key(
				(string) $entry['ability'],
				$entry,
				array(
					'post_id'      => $target_object_id,
					'object_id'    => $target_object_id,
					'page_id'      => $target_object_id,
					'resource_key' => (string) ( $entry['resource_key'] ?? '' ),
				)
			);
			if ( is_wp_error( $target_resource_key ) || '' === trim( (string) $target_resource_key ) ) {
				$target_resource_key = (string) ( $entry['resource_key'] ?? '' );
			}
			if ( '' === trim( (string) $target_resource_key ) ) {
				return new \WP_Error( 'invalid_resource_key', __( 'Journal entry lacks a valid canonical resource key.', 'full-elementor-mcp' ) );
			}
			$target_resource_key = (string) $target_resource_key;
		}

		$is_dry_run = true === ( $options['dry_run'] ?? false );

		// 2. Dry-Run Handling:
		if ( $is_dry_run ) {
			return array(
				'dry_run'               => true,
				'allowed'               => true,
				'can_undo'              => true,
				'journal_id'            => $journal_id,
				'ability'               => $entry['ability'],
				'action'                => $entry['action'],
				'resource_key'          => $target_resource_key,
				'object_type'           => $entry['object_type'],
				'object_id'             => $target_object_id,
				'rollback_supported'    => ! empty( $entry['rollback_supported'] ),
				'confirmation_required' => true,
				'target_after_hash'     => $entry['after_hash'],
				'target_before_hash'    => $entry['before_hash'],
			);
		}

		// 3. Acquire canonical resource lock before capturing authoritative current state:
		$req_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$owner_id = 'rst_undo_' . $req_uuid;

		$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( $target_resource_key, $owner_id, 60 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$fencing_token = (int) $lock['fencing_token'];
		$user_id       = (int) ( $options['user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
		$cred_uuid     = $options['credential_uuid'] ?? null;
		$lock_released = false;

		// Audit undo initiation:
		if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
			Full_Elementor_MCP_Audit_Logger::log(
				Full_Elementor_MCP_Audit_Logger::EVENT_UNDO_STARTED,
				array(
					'ability'         => $entry['ability'],
					'resource_key'    => $target_resource_key,
					'change_id'       => $journal_id,
					'user_id'         => $user_id,
					'credential_uuid' => $cred_uuid,
					'request_uuid'    => $req_uuid,
					'metadata'        => array(
						'journal_id'       => $journal_id,
						'target_object_id' => $target_object_id,
					),
				)
			);
		}

		try {
			// 4. Capture current live resource state under lock using the original mutation strategy:
			$after_hash = (string) ( $entry['after_hash'] ?? '' );
			if ( '' === $after_hash ) {
				return new \WP_Error(
					'undo_state_unverifiable',
					__( 'Cannot undo change: target journal entry lacks a verifiable after_hash baseline.', 'full-elementor-mcp' ),
					array( 'journal_id' => $journal_id )
				);
			}

			$capture_fn = $strategy['capture_after'] ?? ( $strategy['capture_before'] ?? null );
			if ( ! is_callable( $capture_fn ) ) {
				return new \WP_Error(
					'undo_state_unverifiable',
					__( 'Cannot undo change: registered mutation strategy lacks a callable live capture function.', 'full-elementor-mcp' ),
					array( 'journal_id' => $journal_id, 'ability' => $entry['ability'] )
				);
			}

			$resolver_args = array(
				'resource_key'          => $target_resource_key,
				'rollback_resource_key' => $target_resource_key,
				'target_resource'       => $target_resource_key,
				'target_resource_key'   => $target_resource_key,
				'object_type'           => (string) ( $entry['object_type'] ?? '' ),
				'post_id'               => $target_object_id,
				'object_id'             => $target_object_id,
				'page_id'               => $target_object_id,
				'created_object_id'     => $is_create ? $target_object_id : 0,
				'id'                    => $target_object_id,
			);

			$current_state = call_user_func( $capture_fn, $target_object_id, $resolver_args, null );
			if ( is_wp_error( $current_state ) || null === $current_state ) {
				return new \WP_Error(
					'undo_state_unverifiable',
					sprintf(
						/* translators: %s: failure detail */
						__( 'Cannot undo change: live state could not be captured for verification: %s', 'full-elementor-mcp' ),
						is_wp_error( $current_state ) ? $current_state->get_error_message() : 'State returned null'
					),
					array( 'journal_id' => $journal_id )
				);
			}

			// 5. Live State Conflict Check:
			// Target journal has a verified after_hash; current persistent state MUST match it.
			// Newer mutations MUST NOT be silently overwritten.
			$current_hash = Full_Elementor_MCP_Journal::hash_state( $current_state );
			if ( is_wp_error( $current_hash ) || '' === (string) $current_hash ) {
				return new \WP_Error(
					'undo_state_unverifiable',
					__( 'Cannot undo change: live state hashing failed during conflict verification.', 'full-elementor-mcp' ),
					array( 'journal_id' => $journal_id )
				);
			}

			if ( ! hash_equals( $after_hash, (string) $current_hash ) ) {
				return new \WP_Error(
					'journal_state_conflict',
					__( 'Live resource state has changed since this mutation was committed. Undo cannot overwrite newer modifications.', 'full-elementor-mcp' ),
					array(
						'journal_id'    => $journal_id,
						'resource_key'  => $target_resource_key,
						'expected_hash' => $after_hash,
						'current_hash'  => $current_hash,
					)
				);
			}

			// 6. Create durable pre-undo encrypted checkpoint before persistent rollback:
			$pre_undo_res = null;
			if ( class_exists( 'Full_Elementor_MCP_Checkpoint_Manager' ) ) {
				$chk_meta = array(
					'source_ability'    => 'full-elementor-mcp/undo-change',
					'source_journal_id' => $journal_id,
					'user_id'           => $user_id,
					'credential_uuid'   => $cred_uuid,
					'label'             => 'Pre-Undo snapshot for journal #' . $journal_id,
					'target_object_id'  => $target_object_id,
				);

				$pre_undo_res = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( $target_resource_key, 'pre_undo', $chk_meta );
				if ( is_wp_error( $pre_undo_res ) ) {
					return new \WP_Error(
						'undo_pre_checkpoint_failed',
						sprintf(
							/* translators: %s: error message */
							__( 'Failed to create required pre-undo safety checkpoint: %s', 'full-elementor-mcp' ),
							$pre_undo_res->get_error_message()
						),
						array( 'journal_id' => $journal_id )
					);
				}
			}

			// 6.5. Assert fence ownership before execution readiness:
			$fence_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $target_resource_key, $owner_id, $fencing_token );
			if ( is_wp_error( $fence_check ) ) {
				return $fence_check;
			}

			// 6.9. Atomic confirmation consumption at final execution readiness:
			if ( isset( $options['_confirmation_consumer'] ) && is_callable( $options['_confirmation_consumer'] ) ) {
				$consume_res = ( $options['_confirmation_consumer'] )();
				if ( is_wp_error( $consume_res ) ) {
					return $consume_res;
				}
			}

			// 7. Execute frozen Phase 2 Journal::rollback() under active lock and fencing:
			$rollback_result = Full_Elementor_MCP_Journal::rollback(
				$journal_id,
				$owner_id,
				$fencing_token,
				array( 'force' => false )
			);

			if ( is_wp_error( $rollback_result ) ) {
				$err_code          = $rollback_result->get_error_code();
				$write_started     = in_array( $err_code, array( 'manual_recovery_required', 'rollback_verification_failed', 'rollback_failed' ), true );
				$recovery_required = in_array( $err_code, array( 'manual_recovery_required', 'rollback_verification_failed' ), true );

				$existing_data = is_array( $rollback_result->get_error_data() ) ? $rollback_result->get_error_data() : array();
				$merged_data   = array_merge(
					$existing_data,
					array(
						'_safety_outcome' => array(
							'write_started'     => $write_started,
							'rollback_verified' => false,
							'known_safe'        => ! $recovery_required,
							'recovery_required' => $recovery_required,
							'journal_id'        => $journal_id,
						),
					)
				);

				$rollback_result = new \WP_Error( $rollback_result->get_error_code(), $rollback_result->get_error_message(), $merged_data );

				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_UNDO_FAILED,
						array(
							'ability'         => $entry['ability'],
							'resource_key'    => $target_resource_key,
							'change_id'       => $journal_id,
							'severity'        => Full_Elementor_MCP_Audit_Logger::SEVERITY_ERROR,
							'error_code'      => $rollback_result->get_error_code(),
							'user_id'         => $user_id,
							'credential_uuid' => $cred_uuid,
							'checkpoint_uuid' => $pre_undo_res['checkpoint_uuid'] ?? null,
						)
					);
				}
				return $rollback_result;
			}

			// 8. Verify persistent state after rollback:
			// Journal::rollback() already validates against before_hash.
			// Transition and audit:
			$pre_undo_uuid = $pre_undo_res['checkpoint_uuid'] ?? '';

			if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
				Full_Elementor_MCP_Audit_Logger::log(
					Full_Elementor_MCP_Audit_Logger::EVENT_UNDO_COMPLETED,
					array(
						'ability'         => $entry['ability'],
						'resource_key'    => $target_resource_key,
						'change_id'       => $journal_id,
						'checkpoint_uuid' => $pre_undo_uuid,
						'result_status'   => 'success',
						'user_id'         => $user_id,
						'credential_uuid' => $cred_uuid,
						'metadata'        => array(
							'journal_id'               => $journal_id,
							'target_object_id'         => $target_object_id,
							'pre_undo_checkpoint_uuid' => $pre_undo_uuid,
							'before_hash'              => $entry['before_hash'],
						),
					)
				);
			}

			Full_Elementor_MCP_Lock_Manager::release_lock( $target_resource_key, $owner_id, $fencing_token );
			$lock_released = true;

			return array(
				'success'                  => true,
				'undone'                   => true,
				'journal_id'               => $journal_id,
				'resource_key'             => $target_resource_key,
				'pre_undo_checkpoint_uuid' => $pre_undo_uuid,
				'status'                   => Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK,
			);
		} finally {
			if ( ! $lock_released ) {
				Full_Elementor_MCP_Lock_Manager::release_lock( $target_resource_key, $owner_id, $fencing_token );
			}
		}
	}

	/**
	 * Reverts the latest committed mutation for a specific resource key.
	 *
	 * Enforces strict conservatism:
	 * First queries the actual newest relevant journal row for the canonical resource.
	 * If the newest row is pending, failed with uncertain writes, recovery-required,
	 * rolled_back, or non-rollbackable, this method MUST NOT silently skip backward.
	 * It returns 'undo_latest_not_safe'.
	 *
	 * @param string                    $resource_key Canonical resource key (e.g. 'post:123').
	 * @param bool|array<string, mixed> $options      Execution options (dry_run, user_id, credential_uuid) or bool dry_run.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function undo_last_change( string $resource_key, $options = array() ) {
		global $wpdb;

		if ( is_bool( $options ) ) {
			$options = array( 'dry_run' => $options );
		} elseif ( ! is_array( $options ) ) {
			$options = array();
		}

		$resource_key = trim( $resource_key );
		if ( '' === $resource_key ) {
			return new \WP_Error( 'invalid_resource_key', __( 'Resource key is required for undo-last-change.', 'full-elementor-mcp' ) );
		}

		$table = Full_Elementor_MCP_Database_Installer::get_journal_table();

		$post_id = 0;
		if ( 0 === strpos( $resource_key, 'post:' ) ) {
			$post_id = absint( substr( $resource_key, 5 ) );
		}

		// Query the actual NEWEST mutation row for this logical resource (including CREATE rows whose created_object_id = N):
		if ( $post_id > 0 ) {
			$latest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE resource_key = %s OR created_object_id = %d
					 ORDER BY id DESC
					 LIMIT 1",
					$resource_key,
					$post_id
				),
				ARRAY_A
			);
		} else {
			$latest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE resource_key = %s
					 ORDER BY id DESC
					 LIMIT 1",
					$resource_key
				),
				ARRAY_A
			);
		}

		if ( empty( $latest ) ) {
			return new \WP_Error(
				'change_not_found',
				sprintf(
					/* translators: %s: resource key */
					__( 'No recorded journal changes found to undo for resource "%s".', 'full-elementor-mcp' ),
					$resource_key
				),
				array( 'resource_key' => $resource_key )
			);
		}

		// Conservation check: if newest row is already rolled back, fail closed:
		if ( Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK === $latest['status'] ) {
			return new \WP_Error(
				'undo_latest_not_safe',
				sprintf(
					/* translators: 1: journal id, 2: ability */
					__( 'The latest change on resource "%1$s" (#%2$d) has already been rolled back. Skipping backward to older mutations is prohibited.', 'full-elementor-mcp' ),
					$resource_key,
					(int) $latest['id']
				),
				array(
					'journal_id'   => (int) $latest['id'],
					'status'       => 'rolled_back',
					'resource_key' => $resource_key,
				)
			);
		}

		// Conservation check: if newest row is pending or failed or unresolved, fail closed:
		if ( Full_Elementor_MCP_Journal::STATUS_COMMITTED !== $latest['status'] ) {
			return new \WP_Error(
				'undo_latest_not_safe',
				sprintf(
					/* translators: 1: journal id, 2: status */
					__( 'The latest change on this resource (#%1$d) is in unresolved status "%2$s". Skipping backward to older mutations is prohibited.', 'full-elementor-mcp' ),
					(int) $latest['id'],
					$latest['status']
				),
				array(
					'journal_id'   => (int) $latest['id'],
					'status'       => $latest['status'],
					'resource_key' => $resource_key,
				)
			);
		}

		// Conservation check: if newest committed change is NOT rollbackable, fail closed:
		if ( empty( $latest['rollback_supported'] ) ) {
			return new \WP_Error(
				'undo_latest_not_safe',
				sprintf(
					/* translators: 1: journal id, 2: ability name */
					__( 'The latest change on this resource (#%1$d: %2$s) does not support automatic rollback. Skipping backward to older mutations is prohibited to avoid state corruption.', 'full-elementor-mcp' ),
					(int) $latest['id'],
					$latest['ability']
				),
				array(
					'journal_id'   => (int) $latest['id'],
					'ability'      => $latest['ability'],
					'resource_key' => $resource_key,
				)
			);
		}

		// Delegate to undo_change for the verified latest change:
		return self::undo_change( (int) $latest['id'], $options );
	}

	/**
	 * Delegate for MCP ability 'full-elementor-mcp/undo-change'.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute_undo_change_ability( array $input ) {
		$journal_id = isset( $input['journal_id'] ) ? (int) $input['journal_id'] : ( isset( $input['change_id'] ) ? (int) $input['change_id'] : 0 );
		if ( $journal_id <= 0 ) {
			return new \WP_Error( 'missing_journal_id', __( 'journal_id (or change_id) is required for undo-change.', 'full-elementor-mcp' ) );
		}

		// If caller provided target_resource, validate against journal entry's actual resource_key or authoritative rollback target:
		$target_resource = (string) ( $input['target_resource'] ?? ( $input['target_resource_key'] ?? '' ) );
		if ( '' !== $target_resource && class_exists( 'Full_Elementor_MCP_Journal' ) ) {
			$entry = Full_Elementor_MCP_Journal::get_entry( $journal_id );
			if ( $entry ) {
				$fwd_res      = (string) ( $entry['resource_key'] ?? '' );
				$rollback_res = '';
				if ( class_exists( 'Full_Elementor_MCP_Mutation_Registry' ) && ! empty( $entry['ability'] ) ) {
					$is_cr = Full_Elementor_MCP_Mutation_Registry::is_create( (string) $entry['ability'] );
					$t_id  = $is_cr ? absint( $entry['created_object_id'] ?? 0 ) : (int) ( $entry['object_id'] ?? 0 );
					$res   = Full_Elementor_MCP_Mutation_Registry::resolve_rollback_resource_key(
						(string) $entry['ability'],
						$entry,
						array(
							'post_id'           => $t_id,
							'object_id'         => $t_id,
							'created_object_id' => $t_id,
						)
					);
					if ( is_string( $res ) ) {
						$rollback_res = $res;
					}
				}
				if ( $target_resource !== $fwd_res && $target_resource !== $rollback_res ) {
					return new \WP_Error(
						'resource_mismatch',
						sprintf(
							/* translators: 1: target resource, 2: journal resource */
							__( 'Target resource "%1$s" does not match journal resource key "%2$s". Cross-resource undo is prohibited.', 'full-elementor-mcp' ),
							$target_resource,
							$fwd_res
						),
						array( 'journal_id' => $journal_id )
					);
				}
			}
		}

		$options = array(
			'dry_run'                => ( true === ( $input['dry_run'] ?? false ) ) || ( true === ( $input['_safety']['dry_run'] ?? false ) ),
			'user_id'                => (int) ( $input['_user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) ),
			'credential_uuid'        => $input['_cred_uuid'] ?? null,
			'request_uuid'           => $input['_request_uuid'] ?? null,
			'owner_id'               => $input['_owner_id'] ?? null,
			'_confirmation_consumer' => $input['_confirmation_consumer'] ?? null,
		);

		return self::undo_change( $journal_id, $options );
	}

	/**
	 * Delegate for MCP ability 'full-elementor-mcp/undo-last-change'.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute_undo_last_change_ability( array $input ) {
		$resource_key = '';
		if ( ! empty( $input['_resource_key'] ) && is_string( $input['_resource_key'] ) ) {
			$resource_key = sanitize_text_field( $input['_resource_key'] );
		} elseif ( ! empty( $input['target_resource'] ) && is_string( $input['target_resource'] ) ) {
			$resource_key = sanitize_text_field( $input['target_resource'] );
		} elseif ( ! empty( $input['target_resource_key'] ) && is_string( $input['target_resource_key'] ) ) {
			$resource_key = sanitize_text_field( $input['target_resource_key'] );
		} elseif ( ! empty( $input['post_id'] ) && is_numeric( $input['post_id'] ) ) {
			$resource_key = 'post:' . (int) $input['post_id'];
		} elseif ( ! empty( $input['page_id'] ) && is_numeric( $input['page_id'] ) ) {
			$resource_key = 'post:' . (int) $input['page_id'];
		}

		if ( '' === $resource_key ) {
			return new \WP_Error( 'missing_resource_key', __( 'target_resource (or post_id) is required for undo-last-change.', 'full-elementor-mcp' ) );
		}

		$options = array(
			'dry_run'                => ( true === ( $input['dry_run'] ?? false ) ) || ( true === ( $input['_safety']['dry_run'] ?? false ) ),
			'user_id'                => (int) ( $input['_user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) ),
			'credential_uuid'        => $input['_cred_uuid'] ?? null,
			'request_uuid'           => $input['_request_uuid'] ?? null,
			'owner_id'               => $input['_owner_id'] ?? null,
			'_confirmation_consumer' => $input['_confirmation_consumer'] ?? null,
		);

		return self::undo_last_change( $resource_key, $options );
	}
}
