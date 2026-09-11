<?php
/**
 * Atomic Idempotency Lifecycle Manager for Full Elementor MCP.
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

		// 5. Active Pending Lease: Another concurrent worker is currently processing this exact mutation.
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

		// 6. Stale Pending Claim: The previous execution crashed or timed out.
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
				// Journal was committed, but idempotency completion failed: reconcile to completed.
				self::complete( $token_key, (string) $existing['owner_id'], $payload['result'] ?? array( 'success' => true ), $journal_id );
				return array(
					'status'    => 'completed',
					'token_key' => $token_key,
					'result'    => $payload['result'] ?? array( 'success' => true ),
				);
			}
		}

		// If previous execution is definitively failed, allow controlled retry takeover.
		if ( 'failed' === $status ) {
			$initial_payload['owner_id'] = $owner_id;
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET owner_id = %s, payload = %s, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), used = 0
					 WHERE token_key = %s AND token_type = 'idempotency' AND used = 0",
					$owner_id,
					wp_json_encode( $initial_payload ),
					self::PENDING_LEASE_SECONDS,
					$token_key
				)
			);

			if ( 1 === $updated ) {
				return array(
					'status'    => 'claimed',
					'token_key' => $token_key,
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
	 * Associates an active journal ID with the pending idempotency claim.
	 *
	 * @param string $token_key
	 * @param string $owner_id
	 * @param int    $journal_id
	 * @return bool
	 */
	public static function attach_journal_id( string $token_key, string $owner_id, int $journal_id ): bool {
		global $wpdb;
		$table = Full_Elementor_MCP_Database_Installer::get_tokens_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT payload FROM {$table} WHERE token_key = %s AND token_type = 'idempotency' AND owner_id = %s", $token_key, $owner_id ),
			ARRAY_A
		);
		if ( empty( $row ) ) {
			return false;
		}

		$payload = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$payload['journal_id'] = $journal_id;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET payload = %s WHERE token_key = %s AND token_type = 'idempotency' AND owner_id = %s",
				wp_json_encode( $payload ),
				$token_key,
				$owner_id
			)
		);

		return false !== $updated;
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
				 WHERE token_key = %s AND token_type = 'idempotency'",
				wp_json_encode( $payload ),
				self::COMPLETED_TTL_SECONDS,
				$token_key
			)
		);

		return false !== $updated;
	}

	/**
	 * Marks an idempotency claim as failed or cleans it up.
	 *
	 * @param string $token_key
	 * @param string $owner_id
	 * @param string $error_message
	 * @return bool
	 */
	public static function fail( string $token_key, string $owner_id, string $error_message ): bool {
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

		return false !== $deleted;
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
			$sanitized[ $k ] = $v;
		}
		return $sanitized;
	}
}
