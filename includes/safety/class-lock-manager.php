<?php
/**
 * Concurrency Lock and Idempotency Manager.
 *
 * Implements lease-based object locks with request owner IDs, monotonically
 * increasing fencing tokens, and lease renewal to prevent race conditions and
 * reject stale writers. Also manages request idempotency caching.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages atomic concurrency locking, fencing verification, and idempotency.
 */
class Full_Elementor_MCP_Lock_Manager {

	/**
	 * Default lease TTL in seconds.
	 */
	public const DEFAULT_LEASE_TTL = 45;

	/**
	 * Default idempotency cache TTL in seconds (10 minutes).
	 */
	public const DEFAULT_IDEMPOTENCY_TTL = 600;

	/**
	 * Acquires a lease lock on a target resource with a monotonically increasing fencing token.
	 *
	 * @param string $resource_key Identifier for resource (e.g. 'post_42', 'kit_12').
	 * @param string $owner_id     Unique request/client UUID.
	 * @param int    $ttl_seconds  Lease duration in seconds.
	 * @return array{acquired: bool, resource_key: string, owner_id: string, fencing_token: int, expires_at: int}|\WP_Error
	 */
	public static function acquire_lock( string $resource_key, string $owner_id, int $ttl_seconds = self::DEFAULT_LEASE_TTL ) {
		global $wpdb;

		$table     = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$lock_key  = 'lock_' . sanitize_key( $resource_key );
		$now_ts    = time();
		$now_dt    = gmdate( 'Y-m-d H:i:s', $now_ts );
		$expires_ts = $now_ts + max( 5, $ttl_seconds );
		$expires_dt = gmdate( 'Y-m-d H:i:s', $expires_ts );

		// Query existing lock status.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT owner_id, fencing_token, expires_at FROM {$table} WHERE token_key = %s AND token_type = 'lock'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lock_key
			),
			ARRAY_A
		);

		if ( $existing ) {
			$lock_expires_ts = strtotime( $existing['expires_at'] . ' UTC' );
			$is_unexpired    = ( false !== $lock_expires_ts && $lock_expires_ts > $now_ts );

			// Check if lock is held by another active request.
			if ( $is_unexpired && $existing['owner_id'] !== $owner_id ) {
				$remaining = $lock_expires_ts - $now_ts;
				return new \WP_Error(
					'resource_locked',
					sprintf(
						/* translators: 1: resource key, 2: remaining seconds */
						__( 'Resource "%1$s" is currently locked by another operation. Retry in %2$d seconds.', 'full-elementor-mcp' ),
						esc_html( $resource_key ),
						$remaining
					),
					array(
						'resource_key'     => $resource_key,
						'retry_after_secs' => $remaining,
						'locked_by'        => substr( $existing['owner_id'], 0, 8 ) . '...',
					)
				);
			}

			// If current request already holds the lock, renew lease with same fencing token.
			if ( $is_unexpired && $existing['owner_id'] === $owner_id ) {
				$fencing_token = (int) $existing['fencing_token'];
				$wpdb->update(
					$table,
					array(
						'expires_at' => $expires_dt,
					),
					array(
						'token_key' => $lock_key,
					),
					array( '%s' ),
					array( '%s' )
				);

				return array(
					'acquired'      => true,
					'resource_key'  => $resource_key,
					'owner_id'      => $owner_id,
					'fencing_token' => $fencing_token,
					'expires_at'    => $expires_ts,
				);
			}

			// Existing lock has expired or is taking over: increment fencing token.
			$fencing_token = (int) $existing['fencing_token'] + 1;
			$wpdb->update(
				$table,
				array(
					'owner_id'      => $owner_id,
					'fencing_token' => $fencing_token,
					'created_at'    => $now_dt,
					'expires_at'    => $expires_dt,
					'used'          => 0,
				),
				array(
					'token_key' => $lock_key,
				),
				array( '%s', '%d', '%s', '%s', '%d' ),
				array( '%s' )
			);
		} else {
			// First time resource is locked: start fencing token at 1.
			$fencing_token = 1;
			$wpdb->insert(
				$table,
				array(
					'token_key'     => $lock_key,
					'token_type'    => 'lock',
					'owner_id'      => $owner_id,
					'fencing_token' => $fencing_token,
					'created_at'    => $now_dt,
					'expires_at'    => $expires_dt,
					'used'          => 0,
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
			);
		}

		return array(
			'acquired'      => true,
			'resource_key'  => $resource_key,
			'owner_id'      => $owner_id,
			'fencing_token' => $fencing_token,
			'expires_at'    => $expires_ts,
		);
	}

	/**
	 * Asserts that the caller still owns the active lock and valid fencing token.
	 *
	 * MUST be checked immediately before any persistent write (e.g. save_page_data)
	 * and before committing WAL entries.
	 *
	 * @param string $resource_key Identifier for resource.
	 * @param string $owner_id     Caller request UUID.
	 * @param int    $fencing_token Caller fencing token.
	 * @return true|\WP_Error True if ownership valid, WP_Error if stale writer.
	 */
	public static function assert_fencing_token_ownership( string $resource_key, string $owner_id, int $fencing_token ) {
		global $wpdb;

		$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$lock_key = 'lock_' . sanitize_key( $resource_key );
		$now_dt   = gmdate( 'Y-m-d H:i:s' );

		$current = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT owner_id, fencing_token, expires_at FROM {$table} WHERE token_key = %s AND token_type = 'lock'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lock_key
			),
			ARRAY_A
		);

		if ( ! $current ) {
			return new \WP_Error(
				'stale_writer_conflict',
				sprintf(
					/* translators: %s: resource key */
					__( 'Persistence rejected: lock for "%s" was missing or prematurely released.', 'full-elementor-mcp' ),
					esc_html( $resource_key )
				)
			);
		}

		if ( $current['owner_id'] !== $owner_id ) {
			return new \WP_Error(
				'stale_writer_conflict',
				sprintf(
					/* translators: %s: resource key */
					__( 'Persistence rejected: lock for "%s" was acquired by another transaction.', 'full-elementor-mcp' ),
					esc_html( $resource_key )
				)
			);
		}

		if ( (int) $current['fencing_token'] !== $fencing_token ) {
			return new \WP_Error(
				'stale_writer_conflict',
				sprintf(
					/* translators: %s: resource key */
					__( 'Persistence rejected: fencing token mismatch for "%s" (stale writer detected).', 'full-elementor-mcp' ),
					esc_html( $resource_key )
				)
			);
		}

		if ( strtotime( $current['expires_at'] . ' UTC' ) <= time() ) {
			return new \WP_Error(
				'stale_writer_conflict',
				sprintf(
					/* translators: %s: resource key */
					__( 'Persistence rejected: lock lease for "%s" has expired.', 'full-elementor-mcp' ),
					esc_html( $resource_key )
				)
			);
		}

		return true;
	}

	/**
	 * Extends lease expiration for a currently held lock (heartbeat).
	 *
	 * @param string $resource_key  Identifier for resource.
	 * @param string $owner_id      Caller request UUID.
	 * @param int    $fencing_token Caller fencing token.
	 * @param int    $extra_seconds Additional seconds to extend lease.
	 * @return bool True if extended, false if lock not owned.
	 */
	public static function renew_lease( string $resource_key, string $owner_id, int $fencing_token, int $extra_seconds = 30 ): bool {
		global $wpdb;

		$table      = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$lock_key   = 'lock_' . sanitize_key( $resource_key );
		$now_ts     = time();
		$expires_dt = gmdate( 'Y-m-d H:i:s', $now_ts + max( 5, $extra_seconds ) );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET expires_at = %s WHERE token_key = %s AND token_type = 'lock' AND owner_id = %s AND fencing_token = %d AND expires_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$expires_dt,
				$lock_key,
				$owner_id,
				$fencing_token,
				gmdate( 'Y-m-d H:i:s', $now_ts )
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Releases an acquired lock.
	 *
	 * Only releases if owner matches, ensuring a stale caller never unlocks a new transaction.
	 *
	 * @param string $resource_key Identifier for resource.
	 * @param string $owner_id     Caller request UUID.
	 * @return bool True if released.
	 */
	public static function release_lock( string $resource_key, string $owner_id ): bool {
		global $wpdb;

		$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$lock_key = 'lock_' . sanitize_key( $resource_key );
		$past_dt  = gmdate( 'Y-m-d H:i:s', 0 );

		// Rather than hard-deleting the row, we expire the lock and mark it used
		// while retaining the fencing_token counter to ensure strict monotonic increase.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET expires_at = %s, used = 1 WHERE token_key = %s AND token_type = 'lock' AND owner_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$past_dt,
				$lock_key,
				$owner_id
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Retrieves cached idempotent mutation result if exists and valid.
	 *
	 * @param string $idempotency_key Caller idempotency UUID or key.
	 * @return array|null Cached response array, or null on cache miss.
	 */
	public static function get_idempotent_result( string $idempotency_key ): ?array {
		global $wpdb;

		if ( empty( $idempotency_key ) ) {
			return null;
		}

		$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$key_hash = 'idemp_' . hash( 'sha256', $idempotency_key );
		$now_dt   = gmdate( 'Y-m-d H:i:s' );

		$payload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT payload FROM {$table} WHERE token_key = %s AND token_type = 'idempotency' AND expires_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key_hash,
				$now_dt
			)
		);

		if ( ! empty( $payload ) && is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Stores mutation result for idempotency caching.
	 *
	 * @param string $idempotency_key Caller idempotency UUID or key.
	 * @param array  $result          Response data to cache.
	 * @param int    $ttl_seconds     Cache TTL in seconds.
	 * @return bool True if stored.
	 */
	public static function set_idempotent_result( string $idempotency_key, array $result, int $ttl_seconds = self::DEFAULT_IDEMPOTENCY_TTL ): bool {
		global $wpdb;

		if ( empty( $idempotency_key ) ) {
			return false;
		}

		$table      = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$key_hash   = 'idemp_' . hash( 'sha256', $idempotency_key );
		$now_dt     = gmdate( 'Y-m-d H:i:s' );
		$expires_dt = gmdate( 'Y-m-d H:i:s', time() + max( 10, $ttl_seconds ) );
		$encoded    = wp_json_encode( $result );

		if ( false === $encoded ) {
			return false;
		}

		$wpdb->replace(
			$table,
			array(
				'token_key'     => $key_hash,
				'token_type'    => 'idempotency',
				'owner_id'      => null,
				'fencing_token' => 0,
				'payload'       => $encoded,
				'created_at'    => $now_dt,
				'expires_at'    => $expires_dt,
				'used'          => 0,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);

		return true;
	}

	/**
	 * Prunes expired tokens and locks older than threshold. Can be scheduled via daily cron.
	 *
	 * @param int $older_than_seconds Cutoff age in seconds (default 24h = 86400).
	 * @return int Number of pruned rows.
	 */
	public static function prune_expired( int $older_than_seconds = 86400 ): int {
		global $wpdb;

		$table  = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 60, $older_than_seconds ) );

		$pruned = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return false !== $pruned ? (int) $pruned : 0;
	}
}
