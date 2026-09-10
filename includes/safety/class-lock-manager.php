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
	 * Uses atomic conditional writes and compare-and-swap (CAS) to eliminate race conditions:
	 * 1. Initial attempt: atomic INSERT with fencing_token = 1.
	 * 2. Active owner re-entry: atomic lease renewal without incrementing fencing_token.
	 * 3. Expired/released takeover: atomic CAS updating owner, lease, and strictly incrementing fencing_token.
	 * 4. Active conflict: returns WP_Error('resource_locked').
	 *
	 * @param string $resource_key Identifier for resource (e.g. 'post_42', 'kit_12').
	 * @param string $owner_id     Unique request/client UUID.
	 * @param int    $ttl_seconds  Lease duration in seconds.
	 * @return array{acquired: bool, resource_key: string, owner_id: string, fencing_token: int, expires_at: int}|\WP_Error
	 */
	public static function acquire_lock( string $resource_key, string $owner_id, int $ttl_seconds = self::DEFAULT_LEASE_TTL ) {
		global $wpdb;

		if ( '' === trim( $resource_key ) || '' === trim( $owner_id ) ) {
			return new \WP_Error(
				'invalid_lock_parameters',
				__( 'Resource key and owner ID cannot be empty.', 'full-elementor-mcp' )
			);
		}

		$table      = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$lock_key   = 'lock_' . sanitize_key( $resource_key );
		$now_ts     = time();
		$now_dt     = gmdate( 'Y-m-d H:i:s', $now_ts );
		$expires_ts = $now_ts + max( 5, $ttl_seconds );
		$expires_dt = gmdate( 'Y-m-d H:i:s', $expires_ts );

		// STEP 1: Attempt atomic INSERT for a brand new lock (fencing_token = 1).
		// If another operation concurrently attempts insertion, PRIMARY KEY constraint rejects it.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
				VALUES (%s, 'lock', %s, 1, NULL, %s, %s, 0)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lock_key,
				$owner_id,
				$now_dt,
				$expires_dt
			)
		);

		if ( false !== $inserted && $inserted > 0 ) {
			return array(
				'acquired'      => true,
				'resource_key'  => $resource_key,
				'owner_id'      => $owner_id,
				'fencing_token' => 1,
				'expires_at'    => $expires_ts,
			);
		}

		// STEP 2: The row already exists.
		// If the CURRENT owner holds an ACTIVE lease, renew lease without incrementing fencing_token.
		$renewed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET expires_at = %s
				WHERE token_key = %s
				  AND token_type = 'lock'
				  AND owner_id = %s
				  AND expires_at >= %s
				  AND used = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$expires_dt,
				$lock_key,
				$owner_id,
				$now_dt
			)
		);

		if ( false !== $renewed && $renewed > 0 ) {
			$fencing_token = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT fencing_token FROM {$table} WHERE token_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$lock_key
				)
			);

			return array(
				'acquired'      => true,
				'resource_key'  => $resource_key,
				'owner_id'      => $owner_id,
				'fencing_token' => $fencing_token > 0 ? $fencing_token : 1,
				'expires_at'    => $expires_ts,
			);
		}

		// STEP 3: Atomic Compare-And-Swap (CAS) takeover for expired or released locks.
		// Strictly increments fencing_token = fencing_token + 1 in SQL.
		// Only succeeds if current lock is expired (expires_at <= now) OR marked used/released (used = 1).
		$taken_over = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET owner_id = %s,
				    fencing_token = fencing_token + 1,
				    created_at = %s,
				    expires_at = %s,
				    used = 0
				WHERE token_key = %s
				  AND token_type = 'lock'
				  AND (expires_at <= %s OR used = 1)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$now_dt,
				$expires_dt,
				$lock_key,
				$now_dt
			)
		);

		if ( false !== $taken_over && $taken_over > 0 ) {
			$fencing_token = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT fencing_token FROM {$table} WHERE token_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$lock_key
				)
			);

			return array(
				'acquired'      => true,
				'resource_key'  => $resource_key,
				'owner_id'      => $owner_id,
				'fencing_token' => $fencing_token > 0 ? $fencing_token : 2,
				'expires_at'    => $expires_ts,
			);
		}

		// STEP 4: Lock is actively held by another client. Fetch current owner for rejection info.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT owner_id, expires_at FROM {$table} WHERE token_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lock_key
			),
			ARRAY_A
		);

		if ( ! empty( $existing ) && isset( $existing['expires_at'] ) ) {
			$lock_expires_ts = strtotime( $existing['expires_at'] . ' UTC' );
			$remaining       = max( 1, ( false !== $lock_expires_ts ) ? ( $lock_expires_ts - $now_ts ) : $ttl_seconds );
			$locked_by       = ! empty( $existing['owner_id'] ) ? substr( (string) $existing['owner_id'], 0, 8 ) . '...' : 'unknown';

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
					'locked_by'        => $locked_by,
				)
			);
		}

		// STEP 5: Reached on explicit database write failure. Never return acquired=true!
		return new \WP_Error(
			'lock_acquisition_failed',
			sprintf(
				/* translators: %s: resource key */
				__( 'Failed to acquire concurrency lock on resource "%s" due to a database write error.', 'full-elementor-mcp' ),
				esc_html( $resource_key )
			)
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
	 * Generates a normalized SHA-256 hash of mutation arguments.
	 *
	 * @param array $args Mutation arguments.
	 * @return string 64-character SHA-256 hex hash.
	 */
	public static function hash_args( array $args ): string {
		ksort( $args );
		$json = wp_json_encode( $args );
		return hash( 'sha256', false !== $json ? $json : serialize( $args ) );
	}

	/**
	 * Generates a bound token key for idempotency caching.
	 *
	 * Binds user/credential identity, ability name, and caller idempotency key.
	 *
	 * @param string      $idempotency_key Caller idempotency key.
	 * @param string      $ability         Ability name.
	 * @param int         $user_id         User ID.
	 * @param string|null $credential_uuid Application password UUID.
	 * @return string Token key string.
	 */
	public static function get_idempotency_token_key( string $idempotency_key, string $ability, int $user_id, ?string $credential_uuid = null ): string {
		$identity = $user_id . ':' . ( $credential_uuid ?? 'none' ) . ':' . $ability;
		return 'idemp_' . hash( 'sha256', $identity . ':' . $idempotency_key );
	}

	/**
	 * Retrieves cached idempotent mutation result if exists, valid, and matches arguments.
	 *
	 * @param string      $idempotency_key Caller idempotency UUID or key.
	 * @param string      $ability         Target ability name.
	 * @param int         $user_id         Authenticated user ID.
	 * @param string|null $credential_uuid Authenticated credential UUID.
	 * @param array       $args            Arguments passed to the mutation.
	 * @return array|\WP_Error|null Cached response array, WP_Error on conflict, or null on cache miss.
	 */
	public static function get_idempotent_result(
		string $idempotency_key,
		string $ability = '',
		int $user_id = 0,
		?string $credential_uuid = null,
		array $args = array()
	): array|\WP_Error|null {
		global $wpdb;

		if ( '' === trim( $idempotency_key ) ) {
			return null;
		}

		$table     = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$token_key = self::get_idempotency_token_key( $idempotency_key, $ability, $user_id, $credential_uuid );
		$now_dt    = gmdate( 'Y-m-d H:i:s' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload FROM {$table} WHERE token_key = %s AND token_type = 'idempotency' AND expires_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token_key,
				$now_dt
			),
			ARRAY_A
		);

		if ( empty( $row ) || empty( $row['payload'] ) ) {
			return null;
		}

		$payload = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		// Verify args_hash to prevent argument substitution / payload confusion.
		if ( ! empty( $args ) && isset( $payload['args_hash'] ) ) {
			$incoming_hash = self::hash_args( $args );
			if ( ! hash_equals( (string) $payload['args_hash'], $incoming_hash ) ) {
				return new \WP_Error(
					'idempotency_conflict',
					__( 'Idempotency key has already been used with different arguments.', 'full-elementor-mcp' ),
					array(
						'idempotency_key' => $idempotency_key,
						'ability'         => $ability,
					)
				);
			}
		}

		return isset( $payload['result'] ) && is_array( $payload['result'] )
			? $payload['result']
			: $payload;
	}

	/**
	 * Stores mutation result for idempotency caching with identity and argument binding.
	 *
	 * Supports both strict identity signature and convenience ($idempotency_key, $result, $ttl_seconds) overload.
	 *
	 * @param string            $idempotency_key   Caller idempotency UUID or key.
	 * @param string|array      $ability_or_result Target ability name, or result array for convenience call.
	 * @param int|array         $user_id_or_args   Authenticated user ID, or TTL seconds for convenience call.
	 * @param string|null       $credential_uuid   Authenticated credential UUID.
	 * @param array             $args              Arguments passed to the mutation.
	 * @param array             $result            Response data to cache.
	 * @param int               $ttl_seconds       Cache TTL in seconds.
	 * @return bool True if stored, false on DB failure.
	 */
	public static function set_idempotent_result(
		string $idempotency_key,
		string|array $ability_or_result,
		int|array $user_id_or_args = 0,
		?string $credential_uuid = null,
		array $args = array(),
		array $result = array(),
		int $ttl_seconds = self::DEFAULT_IDEMPOTENCY_TTL
	): bool {
		global $wpdb;

		if ( '' === trim( $idempotency_key ) ) {
			return false;
		}

		if ( is_array( $ability_or_result ) ) {
			// Convenience signature: set_idempotent_result($idemp_key, $result, $ttl_seconds).
			$actual_result  = $ability_or_result;
			$actual_ability = '';
			$actual_user_id = 0;
			$actual_cred    = null;
			$actual_args    = array();
			$actual_ttl     = is_int( $user_id_or_args ) ? $user_id_or_args : self::DEFAULT_IDEMPOTENCY_TTL;
		} else {
			$actual_ability = (string) $ability_or_result;
			$actual_user_id = is_int( $user_id_or_args ) ? $user_id_or_args : 0;
			$actual_cred    = $credential_uuid;
			$actual_args    = $args;
			$actual_result  = $result;
			$actual_ttl     = $ttl_seconds;
		}

		$table      = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$token_key  = self::get_idempotency_token_key( $idempotency_key, $actual_ability, $actual_user_id, $actual_cred );
		$now_dt     = gmdate( 'Y-m-d H:i:s' );
		$expires_dt = gmdate( 'Y-m-d H:i:s', time() + max( 10, $actual_ttl ) );
		$args_hash  = self::hash_args( $actual_args );

		$payload_data = array(
			'args_hash'       => $args_hash,
			'ability'         => $actual_ability,
			'user_id'         => $actual_user_id,
			'credential_uuid' => $actual_cred,
			'result'          => $actual_result,
		);

		$encoded = wp_json_encode( $payload_data );
		if ( false === $encoded ) {
			return false;
		}

		$replaced = $wpdb->replace(
			$table,
			array(
				'token_key'     => $token_key,
				'token_type'    => 'idempotency',
				'owner_id'      => $actual_cred ?? (string) $actual_user_id,
				'fencing_token' => 0,
				'payload'       => $encoded,
				'created_at'    => $now_dt,
				'expires_at'    => $expires_dt,
				'used'          => 0,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);

		// Check DB write result: false on failure, 0 if no rows affected.
		return ( false !== $replaced && $replaced > 0 );
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
