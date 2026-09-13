<?php
/**
 * Atomic Idempotency Lifecycle Manager for Safe Elementor MCP.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages atomic idempotency reservations with explicit pending/completed lifecycle.
 *
 * Replaces blind REPLACE INTO semantics with atomic SQL INSERT claims:
 * - Status 'pending' during active mutation execution.
 * - Status 'completed' with cached result summary on successful commit.
 * - Reconciles stale claims against Write-Ahead Journal status.
 * - Enforces strict argument, ability, user, and credential UUID bindings.
 *
 * @since 1.8.0
 */
final class Full_Elementor_MCP_Idempotency_Manager {

	/**
	 * Default execution lease duration for pending claims in seconds (2 minutes).
	 */
	const PENDING_LEASE_SECONDS = 120;

	/**
	 * Default retention duration for completed idempotency records (24 hours).
	 */
	const COMPLETED_TTL_SECONDS = 86400;

	/**
	 * Pre-checks idempotency state before acquiring locks or issuing confirmation challenges.
	 *
	 * Allows completed idempotent replays to return cached results immediately without
	 * prompting for re-confirmation or acquiring resources.
	 *
	 * @param string      $idempotency_key Caller idempotency key.
	 * @param string      $ability         Target ability.
	 * @param int         $user_id         Active user ID.
	 * @param string|null $credential_uuid Authenticated App Password UUID.
	 * @param array       $args            Incoming mutation arguments.
	 * @return array{status: string, token_key: string, result?: array}|\WP_Error
	 */
	public static function precheck(
		string $idempotency_key,
		string $ability,
		int $user_id,
		?string $credential_uuid,
		array $args
	): array|\WP_Error {
		global $wpdb;

		if ( '' === trim( $idempotency_key ) ) {
			return new \WP_Error( 'idempotency_key_empty', __( 'Idempotency key cannot be empty.', 'full-elementor-mcp' ) );
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) || ! class_exists( 'Full_Elementor_MCP_Lock_Manager' ) ) {
			return new \WP_Error( 'safety_db_unavailable', __( 'Safety infrastructure unavailable.', 'full-elementor-mcp' ) );
		}

		$table     = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$token_key = Full_Elementor_MCP_Lock_Manager::get_idempotency_token_key( $idempotency_key, $ability, $user_id, $credential_uuid );
		$args_hash = Full_Elementor_MCP_Confirmation_Manager::canonical_args_hash( $args );

		if ( is_wp_error( $args_hash ) ) {
			return $args_hash;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload, owner_id, used, expires_at, (expires_at > UTC_TIMESTAMP()) AS is_active_lease FROM {$table} WHERE token_key = %s AND token_type = 'idempotency'",
				$token_key
			),
			ARRAY_A
		);

		if ( empty( $existing ) ) {
			return array(
				'status'    => 'proceed',
				'token_key' => $token_key,
			);
		}

		$payload = json_decode( (string) $existing['payload'], true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'idempotency_cache_corrupt', __( 'Corrupted idempotency record: payload is invalid.', 'full-elementor-mcp' ) );
		}

		// Conflict check: same key with different arguments:
		if ( empty( $payload['args_hash'] ) || ! hash_equals( (string) $payload['args_hash'], $args_hash ) ) {
			return new \WP_Error(
				'idempotency_conflict',
				__( 'Idempotency key has already been used with different arguments.', 'full-elementor-mcp' ),
				array(
					'idempotency_key' => $idempotency_key,
					'ability'         => $ability,
				)
			);
		}

		$status = (string) ( $payload['status'] ?? ( 1 === (int) $existing['used'] ? 'completed' : 'pending' ) );

		if ( 'completed' === $status ) {
			return array(
				'status'    => 'completed',
				'token_key' => $token_key,
				'result'    => is_array( $payload['result'] ?? null ) ? $payload['result'] : array( 'success' => true ),
			);
		}

		if ( 'recovery_required' === $status ) {
			return new \WP_Error(
				'idempotency_recovery_required',
				__( 'Prior mutation state is unverified or awaiting crash recovery. Please inspect journal before retrying.', 'full-elementor-mcp' ),
				array( 'journal_id' => (int) ( $payload['journal_id'] ?? 0 ) )
			);
		}

		if ( ! empty( $existing['is_active_lease'] ) ) {
			return new \WP_Error(
				'idempotency_in_progress',
				__( 'An identical mutation request is currently in progress. Please retry shortly.', 'full-elementor-mcp' ),
				array(
					'idempotency_key' => $idempotency_key,
					'ability'         => $ability,
				)
			);
		}

		// If expired lease, check WAL reconciliation:
		$journal_id = (int) ( $payload['journal_id'] ?? 0 );
		if ( $journal_id > 0 && class_exists( 'Full_Elementor_MCP_Journal' ) ) {
			$journal_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}elementor_mcp_journal WHERE id = %d",
					$journal_id
				),
				ARRAY_A
			);

			if ( ! empty( $journal_row ) && 'committed' === $journal_row['status'] ) {
				self::complete( $token_key, (string) $existing['owner_id'], $payload['result'] ?? array( 'success' => true ), $journal_id );
				return array(
					'status'    => 'completed',
					'token_key' => $token_key,
					'result'    => $payload['result'] ?? array( 'success' => true ),
				);
			}

			if ( ! empty( $journal_row ) && 'rolled_back' === $journal_row['status'] ) {
				return array(
					'status'    => 'proceed',
					'token_key' => $token_key,
				);
			}
		}

		if ( 'failed_safe' === $status || 'failed' === $status ) {
			return array(
				'status'    => 'proceed',
				'token_key' => $token_key,
			);
		}

		return new \WP_Error(
			'idempotency_recovery_required',
			__( 'Prior mutation state is unverified or awaiting crash recovery. Please inspect journal before retrying.', 'full-elementor-mcp' ),
			array( 'journal_id' => $journal_id )
		);
	}

	/**
	 * Atomically claims an idempotency key before mutation execution.
	 *
	 * @param string      $idempotency_key Caller idempotency key/UUID.
	 * @param string      $ability         Target ability slug.
	 * @param int         $user_id         Active user ID.
	 * @param string|null $credential_uuid Authenticated App Password UUID.
	 * @param array       $args            Incoming mutation arguments.
	 * @param string      $owner_id        Server-generated request execution UUID.
	 * @return array{status: string, token_key: string, result?: array}|\WP_Error
	 */
	public static function claim(
		string $idempotency_key,
		string $ability,
		int $user_id,
		?string $credential_uuid,
		array $args,
		string $owner_id
	): array|\WP_Error {
		global $wpdb;

		if ( '' === trim( $idempotency_key ) ) {
			return new \WP_Error( 'idempotency_key_empty', __( 'Idempotency key cannot be empty.', 'full-elementor-mcp' ) );
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) || ! class_exists( 'Full_Elementor_MCP_Lock_Manager' ) ) {
			return new \WP_Error( 'safety_db_unavailable', __( 'Safety infrastructure unavailable.', 'full-elementor-mcp' ) );
		}

		$table     = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$token_key = Full_Elementor_MCP_Lock_Manager::get_idempotency_token_key( $idempotency_key, $ability, $user_id, $credential_uuid );
		$args_hash = Full_Elementor_MCP_Confirmation_Manager::canonical_args_hash( $args );

		if ( is_wp_error( $args_hash ) ) {
			return $args_hash;
		}

		$initial_payload = array(
			'status'          => 'pending',
			'ability'         => $ability,
			'user_id'         => $user_id,
			'credential_uuid' => $credential_uuid,
			'args_hash'       => $args_hash,
			'owner_id'        => $owner_id,
			'journal_id'      => 0,
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		);

		$payload_json = wp_json_encode( $initial_payload );

		// 1. Atomic claim attempt via INSERT.
		// Uses DB UTC_TIMESTAMP() for authoritative expiry.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
				 VALUES (%s, 'idempotency', %s, 0, %s, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), 0)",
				$token_key,
				$owner_id,
				$payload_json,
				self::PENDING_LEASE_SECONDS
			)
		);

		if ( false !== $inserted && 1 === $inserted ) {
			// Successfully claimed exclusively.
			return array(
				'status'    => 'claimed',
				'token_key' => $token_key,
			);
		}

		// 2. INSERT collided with an existing claim. Read existing record.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload, owner_id, used, expires_at, (expires_at > UTC_TIMESTAMP()) AS is_active_lease FROM {$table} WHERE token_key = %s AND token_type = 'idempotency'",
				$token_key
			),
			ARRAY_A
		);

		if ( empty( $existing ) ) {
			return new \WP_Error( 'idempotency_claim_failed', __( 'Could not claim idempotency key.', 'full-elementor-mcp' ) );
		}

		$payload = json_decode( (string) $existing['payload'], true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'idempotency_cache_corrupt', __( 'Corrupted idempotency record: payload is invalid.', 'full-elementor-mcp' ) );
		}

		// 3. Strict conflict check: same idempotency key used with different arguments.
		if ( empty( $payload['args_hash'] ) || ! hash_equals( (string) $payload['args_hash'], $args_hash ) ) {
			return new \WP_Error(
				'idempotency_conflict',
				__( 'Idempotency key has already been used with different arguments.', 'full-elementor-mcp' ),
				array(
					'idempotency_key' => $idempotency_key,
					'ability'         => $ability,
				)
			);
		}

		$status = (string) ( $payload['status'] ?? ( 1 === (int) $existing['used'] ? 'completed' : 'pending' ) );

		// 4. Completed: Return stored result safely without re-executing.
		if ( 'completed' === $status ) {
			return array(
				'status'    => 'completed',
				'token_key' => $token_key,
				'result'    => is_array( $payload['result'] ?? null ) ? $payload['result'] : array( 'success' => true ),
			);
		}

		// 5. Recovery required:
		if ( 'recovery_required' === $status ) {
			return new \WP_Error(
				'idempotency_recovery_required',
				__( 'Prior mutation state is unverified or awaiting crash recovery. Please inspect journal before retrying.', 'full-elementor-mcp' ),
				array( 'journal_id' => (int) ( $payload['journal_id'] ?? 0 ) )
			);
		}

		// 6. Active Pending Lease: Another concurrent worker is currently processing this exact mutation.
		if ( ! empty( $existing['is_active_lease'] ) ) {
			return new \WP_Error(
				'idempotency_in_progress',
				__( 'An identical mutation request is currently in progress. Please retry shortly.', 'full-elementor-mcp' ),
				array(
					'idempotency_key' => $idempotency_key,
					'ability'         => $ability,
				)
			);
		}

		// 7. Stale Pending Claim: The previous execution crashed or timed out.
		// Check associated Write-Ahead Journal status for reconciliation.
		$journal_id = (int) ( $payload['journal_id'] ?? 0 );
		if ( $journal_id > 0 && class_exists( 'Full_Elementor_MCP_Journal' ) ) {
			$journal_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}elementor_mcp_journal WHERE id = %d",
					$journal_id
				),
				ARRAY_A
			);

			if ( ! empty( $journal_row ) && 'committed' === $journal_row['status'] ) {
				self::complete( $token_key, (string) $existing['owner_id'], $payload['result'] ?? array( 'success' => true ), $journal_id );
				return array(
					'status'    => 'completed',
					'token_key' => $token_key,
					'result'    => $payload['result'] ?? array( 'success' => true ),
				);
			}

			if ( ! empty( $journal_row ) && 'rolled_back' === $journal_row['status'] ) {
				$status = 'failed_safe';
			}
		}

		// If previous execution was failed_safe or failed, allow controlled retry takeover via true CAS.
		if ( 'failed' === $status || 'failed_safe' === $status ) {
			$initial_payload['owner_id'] = $owner_id;
			$expected_prev_owner         = (string) ( $existing['owner_id'] ?? '' );

			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET owner_id = %s, payload = %s, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), used = 0
					 WHERE token_key = %s
					   AND token_type = 'idempotency'
					   AND owner_id = %s
					   AND used = 0
					   AND expires_at <= UTC_TIMESTAMP()
					   AND (payload LIKE '%%\"status\":\"failed_safe\"%%' OR payload LIKE '%%\"status\":\"failed\"%%' OR payload LIKE '%%\"status\":\"pending\"%%')",
					$owner_id,
					wp_json_encode( $initial_payload ),
					self::PENDING_LEASE_SECONDS,
					$token_key,
					$expected_prev_owner
				)
			);

			if ( 1 === $updated ) {
				return array(
					'status'    => 'claimed',
					'token_key' => $token_key,
				);
			}

			// If UPDATE affects 0 rows, another worker won the CAS or lease state changed.
			// Re-read the record and return the correct authoritative state; do NOT blindly retry.
			$re_read = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT payload, owner_id, used, expires_at, (expires_at > UTC_TIMESTAMP()) AS is_active_lease FROM {$table} WHERE token_key = %s AND token_type = 'idempotency'",
					$token_key
				),
				ARRAY_A
			);

			if ( empty( $re_read ) ) {
				return new \WP_Error( 'idempotency_claim_failed', __( 'Could not claim idempotency key.', 'full-elementor-mcp' ) );
			}

			$re_payload = json_decode( (string) $re_read['payload'], true );
			if ( ! is_array( $re_payload ) ) {
				return new \WP_Error( 'idempotency_cache_corrupt', __( 'Corrupted idempotency record: payload is invalid.', 'full-elementor-mcp' ) );
			}

			if ( empty( $re_payload['args_hash'] ) || ! hash_equals( (string) $re_payload['args_hash'], $args_hash ) ) {
				return new \WP_Error(
					'idempotency_conflict',
					__( 'Idempotency key has already been used with different arguments.', 'full-elementor-mcp' ),
					array(
						'idempotency_key' => $idempotency_key,
						'ability'         => $ability,
					)
				);
			}

			$re_status = (string) ( $re_payload['status'] ?? ( 1 === (int) $re_read['used'] ? 'completed' : 'pending' ) );

			if ( 'completed' === $re_status || 1 === (int) $re_read['used'] ) {
				return array(
					'status'    => 'completed',
					'token_key' => $token_key,
					'result'    => is_array( $re_payload['result'] ?? null ) ? $re_payload['result'] : array( 'success' => true ),
				);
			}

			if ( 'recovery_required' === $re_status ) {
				return new \WP_Error(
					'idempotency_recovery_required',
					__( 'Prior mutation state is unverified or awaiting crash recovery. Please inspect journal before retrying.', 'full-elementor-mcp' ),
					array( 'journal_id' => (int) ( $re_payload['journal_id'] ?? 0 ) )
				);
			}

			if ( ! empty( $re_read['is_active_lease'] ) ) {
				return new \WP_Error(
					'idempotency_in_progress',
					__( 'An identical mutation request is currently in progress. Please retry shortly.', 'full-elementor-mcp' ),
					array(
						'idempotency_key' => $idempotency_key,
						'ability'         => $ability,
					)
				);
			}
		}

		// Conservative fail-closed for uncertain pending crash states:
		return new \WP_Error(
			'idempotency_recovery_required',
			__( 'Prior mutation state is unverified or awaiting crash recovery. Please inspect journal before retrying.', 'full-elementor-mcp' ),
			array( 'journal_id' => $journal_id )
		);
	}

	/**
	 * Associates an active journal ID with the pending idempotency claim using write-once CAS.
	 *
	 * Semantics:
	 * - Initial binding: journal_id = 0 -> bind to journal N.
	 * - Idempotent repeat: journal_id = N -> attaching N again succeeds.
	 * - Conflict: journal_id = N -> attaching M (where M != N) fails with idempotency_journal_conflict.
	 *
	 * @param string $token_key  Idempotency token key.
	 * @param string $owner_id   Execution owner ID.
	 * @param int    $journal_id Associated journal ID.
	 * @return true|\WP_Error True on success/idempotent match, WP_Error on failure/conflict.
	 */
	public static function attach_journal_id( string $token_key, string $owner_id, int $journal_id ): bool|\WP_Error {
		global $wpdb;

		if ( $journal_id <= 0 ) {
			return new \WP_Error( 'invalid_journal_id', __( 'Invalid journal ID for idempotency attachment.', 'full-elementor-mcp' ) );
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return new \WP_Error( 'safety_db_unavailable', __( 'Safety database installer unavailable.', 'full-elementor-mcp' ) );
		}

		$table = Full_Elementor_MCP_Database_Installer::get_tokens_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload, owner_id, used FROM {$table} WHERE token_key = %s AND token_type = 'idempotency'",
				$token_key
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return new \WP_Error( 'idempotency_record_missing', __( 'Idempotency record not found for journal attachment.', 'full-elementor-mcp' ) );
		}

		// Owner check: must match current owner.
		if ( ! hash_equals( (string) $row['owner_id'], $owner_id ) ) {
			return new \WP_Error( 'idempotency_owner_mismatch', __( 'Cannot attach journal: idempotency claim is owned by another worker.', 'full-elementor-mcp' ) );
		}

		if ( 1 === (int) $row['used'] ) {
			return new \WP_Error( 'idempotency_already_completed', __( 'Cannot attach journal: idempotency claim is already completed.', 'full-elementor-mcp' ) );
		}

		$payload = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'idempotency_payload_corrupt', __( 'Cannot attach journal: idempotency payload is corrupt.', 'full-elementor-mcp' ) );
		}

		$current_status     = (string) ( $payload['status'] ?? 'pending' );
		$current_journal_id = (int) ( $payload['journal_id'] ?? 0 );

		if ( 'pending' !== $current_status ) {
			return new \WP_Error( 'idempotency_not_pending', __( 'Cannot attach journal: idempotency claim is not in pending status.', 'full-elementor-mcp' ) );
		}

		// Idempotent repeat:
		if ( $current_journal_id === $journal_id ) {
			return true;
		}

		// Conflict: journal_id already set to a different non-zero ID:
		if ( $current_journal_id > 0 && $current_journal_id !== $journal_id ) {
			return new \WP_Error(
				'idempotency_journal_conflict',
				sprintf(
					/* translators: 1: existing journal ID, 2: attempted journal ID */
					__( 'Idempotency journal binding conflict: claim is already bound to journal #%1$d, cannot bind to #%2$d.', 'full-elementor-mcp' ),
					$current_journal_id,
					$journal_id
				),
				array(
					'existing_journal_id'  => $current_journal_id,
					'attempted_journal_id' => $journal_id,
				)
			);
		}

		// Initial binding (0 -> N): Atomic CAS:
		$payload['journal_id'] = $journal_id;
		$new_json              = wp_json_encode( $payload );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET payload = %s
				 WHERE token_key = %s
				   AND token_type = 'idempotency'
				   AND owner_id = %s
				   AND used = 0
				   AND (payload LIKE '%%\"journal_id\":0%%' OR payload NOT LIKE '%%\"journal_id\"%%')",
				$new_json,
				$token_key,
				$owner_id
			)
		);

		if ( 1 === $updated ) {
			return true;
		}

		// 0 affected rows: re-read to distinguish idempotent success from conflict:
		$re_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload, owner_id, used FROM {$table} WHERE token_key = %s AND token_type = 'idempotency'",
				$token_key
			),
			ARRAY_A
		);

		if ( ! empty( $re_row ) ) {
			$re_payload = json_decode( (string) $re_row['payload'], true );
			if ( is_array( $re_payload ) ) {
				$re_jid = (int) ( $re_payload['journal_id'] ?? 0 );
				if ( $re_jid === $journal_id ) {
					return true; // Idempotent success
				}
				if ( $re_jid > 0 && $re_jid !== $journal_id ) {
					return new \WP_Error(
						'idempotency_journal_conflict',
						__( 'Idempotency journal binding conflict after concurrent update.', 'full-elementor-mcp' ),
						array(
							'existing_journal_id'  => $re_jid,
							'attempted_journal_id' => $journal_id,
						)
					);
				}
			}
		}

		return new \WP_Error( 'idempotency_journal_attach_failed', __( 'Could not atomically bind journal to idempotency claim.', 'full-elementor-mcp' ) );
	}

	/**
	 * Completes an idempotency claim, persisting the sanitized result for replay.
	 *
	 * @param string   $token_key         Token key from claim.
	 * @param string   $owner_id          Expected owner ID.
	 * @param array    $result            Mutation result array.
	 * @param int      $journal_id        Associated journal ID.
	 * @param int|null $created_object_id Created object ID if applicable.
	 * @return bool
	 */
	public static function complete(
		string $token_key,
		string $owner_id,
		array $result,
		int $journal_id = 0,
		?int $created_object_id = null
	): bool {
		global $wpdb;

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return false;
		}

		$table = Full_Elementor_MCP_Database_Installer::get_tokens_table();

		// Fetch existing payload to preserve identity metadata.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload FROM {$table} WHERE token_key = %s AND token_type = 'idempotency'",
				$token_key
			),
			ARRAY_A
		);

		$payload = array();
		if ( ! empty( $existing['payload'] ) ) {
			$payload = json_decode( (string) $existing['payload'], true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
		}

		$payload['status']            = 'completed';
		$payload['journal_id']        = $journal_id > 0 ? $journal_id : ( $payload['journal_id'] ?? 0 );
		$payload['created_object_id'] = $created_object_id ?? ( $payload['created_object_id'] ?? null );
		$payload['result']            = self::sanitize_result_for_storage( $result );
		$payload['completed_at']      = gmdate( 'Y-m-d H:i:s' );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET payload = %s, used = 1, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)
				 WHERE token_key = %s AND token_type = 'idempotency' AND owner_id = %s AND used = 0",
				wp_json_encode( $payload ),
				self::COMPLETED_TTL_SECONDS,
				$token_key,
				$owner_id
			)
		);

		return ! empty( $updated ) && $updated > 0;
	}

	/**
	 * Cleans up or marks claim as failed_safe when mutation failed BEFORE persistent writes began.
	 *
	 * Allows immediate safe retries.
	 *
	 * @param string $token_key
	 * @param string $owner_id
	 * @param string $error_message
	 * @return bool
	 */
	public static function fail_safe( string $token_key, string $owner_id, string $error_message = '' ): bool {
		global $wpdb;
		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return false;
		}

		$table = Full_Elementor_MCP_Database_Installer::get_tokens_table();

		// Clean up the claim so caller can immediately retry without collision:
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE token_key = %s AND token_type = 'idempotency' AND owner_id = %s AND used = 0",
				$token_key,
				$owner_id
			)
		);

		return ! empty( $deleted ) && $deleted > 0;
	}

	/**
	 * Marks claim as recovery_required when writes occurred or uncertainty exists.
	 *
	 * Blocks blind retries until journal is resolved.
	 *
	 * @param string $token_key
	 * @param string $owner_id
	 * @param int    $journal_id
	 * @param string $reason
	 * @return bool
	 */
	public static function mark_recovery_required( string $token_key, string $owner_id, int $journal_id = 0, string $reason = '' ): bool {
		global $wpdb;
		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return false;
		}

		$table = Full_Elementor_MCP_Database_Installer::get_tokens_table();

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload FROM {$table} WHERE token_key = %s AND token_type = 'idempotency' AND owner_id = %s",
				$token_key,
				$owner_id
			),
			ARRAY_A
		);

		$payload = array();
		if ( ! empty( $existing['payload'] ) ) {
			$payload = json_decode( (string) $existing['payload'], true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
		}

		$payload['status']     = 'recovery_required';
		$payload['journal_id'] = $journal_id > 0 ? $journal_id : ( $payload['journal_id'] ?? 0 );
		$payload['reason']     = $reason;
		$payload['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET payload = %s WHERE token_key = %s AND token_type = 'idempotency' AND owner_id = %s AND used = 0",
				wp_json_encode( $payload ),
				$token_key,
				$owner_id
			)
		);

		return ! empty( $updated ) && $updated > 0;
	}

	/**
	 * Backward compatibility alias for fail_safe().
	 *
	 * @param string $token_key
	 * @param string $owner_id
	 * @param string $error_message
	 * @return bool
	 */
	public static function fail( string $token_key, string $owner_id, string $error_message = '' ): bool {
		return self::fail_safe( $token_key, $owner_id, $error_message );
	}

	/**
	 * Sanitizes result data before storage, preventing sensitive credentials or unbounded trees.
	 *
	 * @param array $result
	 * @return array
	 */
	private static function sanitize_result_for_storage( array $result ): array {
		$sanitized = array();
		foreach ( $result as $k => $v ) {
			if ( in_array( $k, array( 'auth_token', 'password', 'secret', 'key' ), true ) ) {
				continue;
			}
			// Avoid caching multi-megabyte trees inside idempotency payload:
			if ( 'elements' === $k && is_array( $v ) ) {
				$sanitized['element_count'] = count( $v );
				continue;
			}
			// Bound large string properties to 16KB to keep tokens table lean:
			if ( is_string( $v ) && strlen( $v ) > 16384 ) {
				$sanitized[ $k ] = substr( $v, 0, 16384 ) . '...[truncated]';
				continue;
			}
			$sanitized[ $k ] = $v;
		}
		return $sanitized;
	}
}
