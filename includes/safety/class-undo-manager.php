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

		$resource_key = (string) ( $entry['resource_key'] ?? '' );
		if ( '' === $resource_key ) {
			return new \WP_Error( 'invalid_resource_key', __( 'Journal entry lacks a valid canonical resource key.', 'full-elementor-mcp' ) );
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
				'resource_key'          => $resource_key,
				'object_type'           => $entry['object_type'],
				'object_id'             => (int) $entry['object_id'],
				'rollback_supported'    => ! empty( $entry['rollback_supported'] ),
				'confirmation_required' => true,
				'target_after_hash'     => $entry['after_hash'],
				'target_before_hash'    => $entry['before_hash'],
			);
		}

		// 3. Acquire canonical resource lock before capturing authoritative current state:
		$req_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$owner_id = 'rst_undo_' . $req_uuid;

		$lock = Full_Elementor_MCP_Lock_Manager::acquire_lock( $resource_key, $owner_id, 60 );
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
					'resource_key'    => $resource_key,
					'change_id'       => $journal_id,
					'user_id'         => $user_id,
					'credential_uuid' => $cred_uuid,
					'request_uuid'    => $req_uuid,
					'metadata'        => array( 'journal_id' => $journal_id ),
				)
			);
		}

		try {
			// 4. Capture current live resource state under lock using the original mutation strategy:
			$capture_fn = $strategy['capture_after'] ?? ( $strategy['capture_before'] ?? null );
			$resolver_args = array(
				'post_id'           => (int) $entry['object_id'],
				'object_id'         => (int) $entry['object_id'],
				'page_id'           => (int) $entry['object_id'],
				'created_object_id' => (int) $entry['object_id'],
				'id'                => (int) $entry['object_id'],
			);

			$current_state = is_callable( $capture_fn )
				? call_user_func( $capture_fn, (int) $entry['object_id'], $resolver_args, null )
				: null;

			if ( is_wp_error( $current_state ) ) {
				return $current_state;
			}

			// 5. Live State Conflict Check:
			// If target journal has an after_hash, current persistent state MUST match it.
			// Newer mutations MUST NOT be silently overwritten.
			if ( ! empty( $entry['after_hash'] ) ) {
				$current_hash = Full_Elementor_MCP_Journal::hash_state( $current_state );
				if ( is_wp_error( $current_hash ) ) {
					return $current_hash;
				}

				if ( ! hash_equals( (string) $entry['after_hash'], (string) $current_hash ) ) {
					return new \WP_Error(
						'journal_state_conflict',
						__( 'Live resource state has changed since this mutation was committed. Undo cannot overwrite newer modifications.', 'full-elementor-mcp' ),
						array(
							'journal_id'    => $journal_id,
							'resource_key'  => $resource_key,
							'expected_hash' => $entry['after_hash'],
							'current_hash'  => $current_hash,
						)
					);
				}
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
				);

				$pre_undo_res = Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( $resource_key, 'pre_undo', $chk_meta );
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

			// 7. Execute frozen Phase 2 Journal::rollback() under active lock and fencing:
			$rollback_result = Full_Elementor_MCP_Journal::rollback(
				$journal_id,
				$owner_id,
				$fencing_token,
				array( 'force' => false )
			);

			if ( is_wp_error( $rollback_result ) ) {
				if ( class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
					Full_Elementor_MCP_Audit_Logger::log(
						Full_Elementor_MCP_Audit_Logger::EVENT_UNDO_FAILED,
						array(
							'ability'         => $entry['ability'],
							'resource_key'    => $resource_key,
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
						'resource_key'    => $resource_key,
						'change_id'       => $journal_id,
						'checkpoint_uuid' => $pre_undo_uuid,
						'result_status'   => 'success',
						'user_id'         => $user_id,
						'credential_uuid' => $cred_uuid,
						'metadata'        => array(
							'journal_id'                 => $journal_id,
							'pre_undo_checkpoint_uuid'   => $pre_undo_uuid,
							'before_hash'                => $entry['before_hash'],
						),
					)
				);
			}

			Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			$lock_released = true;

			return array(
				'success'                  => true,
				'undone'                   => true,
				'journal_id'               => $journal_id,
				'resource_key'             => $resource_key,
				'pre_undo_checkpoint_uuid' => $pre_undo_uuid,
				'status'                   => Full_Elementor_MCP_Journal::STATUS_ROLLED_BACK,
			);
		} finally {
			if ( ! $lock_released ) {
				Full_Elementor_MCP_Lock_Manager::release_lock( $resource_key, $owner_id, $fencing_token );
			}
		}
	}

	/**
	 * Reverts the latest committed mutation for a specific resource key.
	 *
	 * Enforces strict conservatism:
	 * If the newest committed mutation is non-rollbackable or requires manual recovery,
	 * this method MUST NOT silently skip backward to an older rollbackable mutation.
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

		// Query the latest committed mutation for this exact resource:
		$latest = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE resource_key = %s
				   AND status = %s
				 ORDER BY id DESC
				 LIMIT 1",
				$resource_key,
				Full_Elementor_MCP_Journal::STATUS_COMMITTED
			),
			ARRAY_A
		);

		if ( empty( $latest ) ) {
			return new \WP_Error(
				'change_not_found',
				sprintf(
					/* translators: %s: resource key */
					__( 'No committed changes found to undo for resource "%s".', 'full-elementor-mcp' ),
					$resource_key
				),
				array( 'resource_key' => $resource_key )
			);
		}

		// Conservation check: if newest change is NOT rollbackable, fail closed:
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

		// Delegate to undo_change for the latest change:
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

		$options = array(
			'dry_run'         => ( true === ( $input['dry_run'] ?? false ) ) || ( true === ( $input['_safety']['dry_run'] ?? false ) ),
			'user_id'         => (int) ( $input['user_id'] ?? 0 ),
			'credential_uuid' => $input['credential_uuid'] ?? null,
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
		if ( ! empty( $input['resource_key'] ) && is_string( $input['resource_key'] ) ) {
			$resource_key = sanitize_text_field( $input['resource_key'] );
		} elseif ( ! empty( $input['post_id'] ) && is_numeric( $input['post_id'] ) ) {
			$resource_key = 'post:' . (int) $input['post_id'];
		} elseif ( ! empty( $input['page_id'] ) && is_numeric( $input['page_id'] ) ) {
			$resource_key = 'post:' . (int) $input['page_id'];
		}

		if ( '' === $resource_key ) {
			return new \WP_Error( 'missing_resource_key', __( 'resource_key (or post_id) is required for undo-last-change.', 'full-elementor-mcp' ) );
		}

		$options = array(
			'dry_run'         => ( true === ( $input['dry_run'] ?? false ) ) || ( true === ( $input['_safety']['dry_run'] ?? false ) ),
			'user_id'         => (int) ( $input['user_id'] ?? 0 ),
			'credential_uuid' => $input['credential_uuid'] ?? null,
		);

		return self::undo_last_change( $resource_key, $options );
	}
}
