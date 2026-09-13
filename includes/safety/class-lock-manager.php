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
	 * Generates a deterministic, collision-resistant token key for a resource lock.
	 *
	 * Combines an ASCII slug prefix (up to 32 characters) for human inspection/debugging
	 * with the full 64-character SHA-256 hash of the exact raw canonical resource key.
	 * Total length <= 102 characters, well within varchar(128) column size.
	 *
	 * @param string $resource_key Raw resource identifier (e.g. 'post:42', 'template/header').
	 * @return string Unique, collision-resistant token key.
	 */
	public static function get_lock_token_key( string $resource_key ): string {
		$slug   = sanitize_key( $resource_key );
		$prefix = ( '' !== $slug ) ? substr( $slug, 0, 32 ) : 'res';
		$hash   = hash( 'sha256', $resource_key );
		return 'lock_' . $prefix . '_' . $hash;
	}

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

		$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$lock_key = self::get_lock_token_key( $resource_key );
		$ttl      = max( 5, $ttl_seconds );

		// STEP 1: Attempt atomic INSERT for a brand new lock (fencing_token = 1).
		// Expiry and creation timestamps are generated directly by the database UTC authority.
		// If another operation concurrently attempts insertion, PRIMARY KEY constraint rejects it.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
				VALUES (%s, 'lock', %s, 1, NULL, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), 0)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lock_key,
				$owner_id,
				$ttl
			)
		);

		if ( false !== $inserted && $inserted > 0 ) {
			$persisted = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT fencing_token, expires_at FROM {$table} WHERE token_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$lock_key
				),
				ARRAY_A
			);
			$persisted_exp_ts = ( ! empty( $persisted ) && ! empty( $persisted['expires_at'] ) )
				? (int) strtotime( $persisted['expires_at'] . ' UTC' )
				: 0;

			return array(
				'acquired'      => true,
				'resource_key'  => $resource_key,
				'owner_id'      => $owner_id,
				'fencing_token' => 1,
				'expires_at'    => $persisted_exp_ts,
			);
		}

		// STEP 2: The row already exists.
		// If the CURRENT owner holds an ACTIVE lease in the database, renew lease monotonically without incrementing fencing_token.
		$active_owner = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT fencing_token, expires_at, (expires_at > UTC_TIMESTAMP()) AS is_active_in_db, UTC_TIMESTAMP() AS db_now FROM {$table} WHERE token_key = %s AND token_type = 'lock' AND owner_id = %s AND used = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lock_key,
				$owner_id
			),
			ARRAY_A
		);

		if ( ! empty( $active_owner ) && ! empty( $active_owner['expires_at'] ) ) {
			$current_expires_ts = strtotime( $active_owner['expires_at'] . ' UTC' );
			$is_active_in_db    = ! empty( $active_owner['is_active_in_db'] );

			// Step 2 applies ONLY if the observed lease is still strictly active in the database.
			// If already expired at write time, same-owner must NOT re-enter with old fence; falls through to Step 3 takeover!
			if ( $is_active_in_db && false !== $current_expires_ts ) {
				$db_now_ts           = ! empty( $active_owner['db_now'] ) ? (int) strtotime( $active_owner['db_now'] . ' UTC' ) : time();
				$fencing_token       = (int) $active_owner['fencing_token'];
				$observed_expires_dt = (string) $active_owner['expires_at'];

				// Bounded refresh relative to database time authority:
				// lease horizon is capped at max(current_expires_ts, db_now + requested_ttl),
				// ensuring rapid calls do not accumulate unbounded future lease time and never shorten active lease duration.
				$target_ts = max( $current_expires_ts, $db_now_ts + $ttl );
				$target_dt = gmdate( 'Y-m-d H:i:s', $target_ts );

				// Strict write-time CAS: binds observed expires_at AND guarantees lease is still active at write time in database.
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
						SET expires_at = %s
						WHERE token_key = %s
						  AND token_type = 'lock'
						  AND owner_id = %s
						  AND fencing_token = %d
						  AND used = 0
						  AND expires_at = %s
						  AND expires_at > UTC_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$target_dt,
						$lock_key,
						$owner_id,
						$fencing_token,
						$observed_expires_dt
					)
				);

				// 1. Explicit DB write failure must return WP_Error, never acquired=true.
				if ( false === $updated ) {
					return new \WP_Error(
						'lock_acquisition_failed',
						sprintf(
							/* translators: %s: resource key */
							__( 'Failed to renew concurrency lock on resource "%s" due to a database write error.', 'full-elementor-mcp' ),
							esc_html( $resource_key )
						)
					);
				}

				// 2. Direct CAS write success (> 0 affected rows).
				if ( $updated > 0 ) {
					return array(
						'acquired'      => true,
						'resource_key'  => $resource_key,
						'owner_id'      => $owner_id,
						'fencing_token' => $fencing_token > 0 ? $fencing_token : 1,
						'expires_at'    => $target_ts,
					);
				}

				// 3. 0 affected rows: CAS missed due to concurrent update or state change. Re-read current lock state.
				$recheck = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT token_type, owner_id, fencing_token, used, expires_at, (expires_at > UTC_TIMESTAMP()) AS is_active_in_db, UTC_TIMESTAMP() AS db_now FROM {$table} WHERE token_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$lock_key
					),
					ARRAY_A
				);

				if ( ! empty( $recheck ) ) {
					$recheck_fencing = (int) $recheck['fencing_token'];
					$recheck_used    = (int) $recheck['used'];
					$recheck_exp_ts  = ! empty( $recheck['expires_at'] ) ? (int) strtotime( $recheck['expires_at'] . ' UTC' ) : 0;
					$recheck_active  = ! empty( $recheck['is_active_in_db'] );

					// If ownership or fencing token changed in the interim, reject with conflict error.
					if ( $recheck['owner_id'] !== $owner_id || $recheck_fencing !== $fencing_token ) {
						return new \WP_Error(
							'stale_writer_conflict',
							sprintf(
								/* translators: %s: resource key */
								__( 'Lock re-entry conflict: ownership or fencing token for "%s" changed concurrently.', 'full-elementor-mcp' ),
								esc_html( $resource_key )
							)
						);
					}

					// If lock was marked used or type changed, reject.
					if ( 0 !== $recheck_used || 'lock' !== $recheck['token_type'] ) {
						return new \WP_Error(
							'resource_locked',
							sprintf(
								/* translators: %s: resource key */
								__( 'Lock re-entry failed: resource "%s" is no longer active.', 'full-elementor-mcp' ),
								esc_html( $resource_key )
							)
						);
					}

					// If lease has expired in the database at write time:
					// Do NOT revive it with same fencing token! Exit Step 2 to fall through to Step 3 expired takeover.
					if ( ! $recheck_active || false === $recheck_exp_ts ) {
						// Fall through to Step 3 takeover below so a new generation fencing token is granted.
					} else {
						// STRICT RULE: Only return success if actual persisted DB expiry >= requested target expiry AND lease is still active!
						if ( $recheck_exp_ts >= $target_ts ) {
							return array(
								'acquired'      => true,
								'resource_key'  => $resource_key,
								'owner_id'      => $owner_id,
								'fencing_token' => $recheck_fencing > 0 ? $recheck_fencing : 1,
								'expires_at'    => $recheck_exp_ts,
							);
						}

						// If lease is still active and valid, but DB expiry is less than target (e.g. intermediate heartbeat raced):
						// Attempt one bounded retry using the newly observed state and DB time.
						$retry_db_now_ts = ! empty( $recheck['db_now'] ) ? (int) strtotime( $recheck['db_now'] . ' UTC' ) : $db_now_ts;
						$retry_target_ts = max( $recheck_exp_ts, $retry_db_now_ts + $ttl );
						$retry_target_dt = gmdate( 'Y-m-d H:i:s', $retry_target_ts );

						$retried = $wpdb->query(
							$wpdb->prepare(
								"UPDATE {$table}
								SET expires_at = %s
								WHERE token_key = %s
								  AND token_type = 'lock'
								  AND owner_id = %s
								  AND fencing_token = %d
								  AND used = 0
								  AND expires_at = %s
								  AND expires_at > UTC_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
								$retry_target_dt,
								$lock_key,
								$owner_id,
								$fencing_token,
								$recheck['expires_at']
							)
						);

						if ( false !== $retried && $retried > 0 ) {
							return array(
								'acquired'      => true,
								'resource_key'  => $resource_key,
								'owner_id'      => $owner_id,
								'fencing_token' => $fencing_token > 0 ? $fencing_token : 1,
								'expires_at'    => $retry_target_ts,
							);
						}

						return new \WP_Error(
							'resource_locked',
							sprintf(
								/* translators: %s: resource key */
								__( 'Lock re-entry rejected: lock lease for "%s" could not be verified or has expired.', 'full-elementor-mcp' ),
								esc_html( $resource_key )
							)
						);
					}
				}
			}
		}

		// STEP 3: Atomic Compare-And-Swap (CAS) takeover for expired or released locks.
		// Strictly increments fencing_token = fencing_token + 1 in SQL.
		// Only succeeds if current lock is expired (expires_at <= UTC_TIMESTAMP()) OR marked used/released (used = 1).
		// Persisted expiry is generated strictly from the database UTC authority.
		$taken_over = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET owner_id = %s,
				    fencing_token = fencing_token + 1,
				    created_at = UTC_TIMESTAMP(),
				    expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
				    used = 0
				WHERE token_key = %s
				  AND token_type = 'lock'
				  AND (expires_at <= UTC_TIMESTAMP() OR used = 1)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$ttl,
				$lock_key
			)
		);

		if ( false !== $taken_over && $taken_over > 0 ) {
			$persisted = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT fencing_token, expires_at FROM {$table} WHERE token_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$lock_key
				),
				ARRAY_A
			);
			$persisted_fencing = ! empty( $persisted['fencing_token'] ) ? (int) $persisted['fencing_token'] : 2;
			$persisted_exp_ts  = ( ! empty( $persisted ) && ! empty( $persisted['expires_at'] ) )
				? (int) strtotime( $persisted['expires_at'] . ' UTC' )
				: 0;

			return array(
				'acquired'      => true,
				'resource_key'  => $resource_key,
				'owner_id'      => $owner_id,
				'fencing_token' => $persisted_fencing > 0 ? $persisted_fencing : 2,
				'expires_at'    => $persisted_exp_ts,
			);
		}

		// STEP 4: Lock is actively held by another client. Fetch current owner for rejection info.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT owner_id, expires_at, UTC_TIMESTAMP() AS db_now FROM {$table} WHERE token_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lock_key
			),
			ARRAY_A
		);

		if ( ! empty( $existing ) && isset( $existing['expires_at'] ) ) {
			$db_now_ts       = ! empty( $existing['db_now'] ) ? (int) strtotime( $existing['db_now'] . ' UTC' ) : time();
			$lock_expires_ts = strtotime( $existing['expires_at'] . ' UTC' );
			$remaining       = max( 1, ( false !== $lock_expires_ts ) ? ( $lock_expires_ts - $db_now_ts ) : $ttl );
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
	 * and before committing WAL entries. Strictly validates lease validity using
	 * the database UTC time authority (expires_at > UTC_TIMESTAMP()).
	 *
	 * @param string $resource_key Identifier for resource.
	 * @param string $owner_id     Caller request UUID.
	 * @param int    $fencing_token Caller fencing token.
	 * @return true|\WP_Error True if ownership valid, WP_Error if stale writer.
	 */
	public static function assert_fencing_token_ownership( string $resource_key, string $owner_id, int $fencing_token ) {
		global $wpdb;

		$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$lock_key = self::get_lock_token_key( $resource_key );

		$current = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT owner_id, fencing_token, expires_at, used, (expires_at > UTC_TIMESTAMP()) AS is_active_in_db FROM {$table} WHERE token_key = %s AND token_type = 'lock'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		if ( 0 !== (int) $current['used'] ) {
			return new \WP_Error(
				'stale_writer_conflict',
				sprintf(
					/* translators: %s: resource key */
					__( 'Persistence rejected: lock for "%s" was already released.', 'full-elementor-mcp' ),
					esc_html( $resource_key )
				)
			);
		}

		if ( empty( $current['is_active_in_db'] ) ) {
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
	 * Guaranteed to never shorten an active lease duration: extends from max(current_expiry, db_now) + extra_seconds.
	 * Evaluates active lease validity and refresh targets strictly against the database UTC authority.
	 * Uses compare-and-swap on observed expiry to eliminate heartbeat race conditions.
	 *
	 * @param string $resource_key  Identifier for resource.
	 * @param string $owner_id      Caller request UUID.
	 * @param int    $fencing_token Caller fencing token.
	 * @param int    $extra_seconds Additional seconds to extend lease.
	 * @return bool True if extended, false if lock not owned or expired.
	 */
	public static function renew_lease( string $resource_key, string $owner_id, int $fencing_token, int $extra_seconds = 30 ): bool {
		global $wpdb;

		if ( '' === trim( $resource_key ) || '' === trim( $owner_id ) || $fencing_token < 1 ) {
			return false;
		}

		$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$lock_key = self::get_lock_token_key( $resource_key );

		// Fetch current active lease for owner and fencing token with database time evaluation.
		$current = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT expires_at, (expires_at > UTC_TIMESTAMP()) AS is_active_in_db, UTC_TIMESTAMP() AS db_now FROM {$table} WHERE token_key = %s AND token_type = 'lock' AND owner_id = %s AND fencing_token = %d AND used = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lock_key,
				$owner_id,
				$fencing_token
			),
			ARRAY_A
		);

		if ( empty( $current ) || empty( $current['expires_at'] ) || empty( $current['is_active_in_db'] ) ) {
			return false; // Missing, released, or already expired in database => cannot renew.
		}

		$current_expires_ts = strtotime( $current['expires_at'] . ' UTC' );
		if ( false === $current_expires_ts ) {
			return false;
		}

		$db_now_ts       = ! empty( $current['db_now'] ) ? (int) strtotime( $current['db_now'] . ' UTC' ) : time();
		$requested_extra = max( 5, $extra_seconds );
		$target_ts       = max( $current_expires_ts, $db_now_ts + $requested_extra );
		$target_dt       = gmdate( 'Y-m-d H:i:s', $target_ts );

		// Strict write-time CAS: binds observed expires_at AND guarantees lease is still active at write time in database.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET expires_at = %s WHERE token_key = %s AND token_type = 'lock' AND owner_id = %s AND fencing_token = %d AND used = 0 AND expires_at = %s AND expires_at > UTC_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$target_dt,
				$lock_key,
				$owner_id,
				$fencing_token,
				$current['expires_at']
			)
		);

		if ( false !== $updated && $updated > 0 ) {
			return true;
		}

		// Re-check: if 0 rows, check whether another heartbeat already extended to >= target_dt while lease remains active in DB.
		$recheck = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT expires_at, (expires_at > UTC_TIMESTAMP()) AS is_active_in_db FROM {$table} WHERE token_key = %s AND token_type = 'lock' AND owner_id = %s AND fencing_token = %d AND used = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lock_key,
				$owner_id,
				$fencing_token
			),
			ARRAY_A
		);

		if ( ! empty( $recheck ) && ! empty( $recheck['expires_at'] ) ) {
			$recheck_ts     = strtotime( $recheck['expires_at'] . ' UTC' );
			$recheck_active = ! empty( $recheck['is_active_in_db'] );
			return ( false !== $recheck_ts && $recheck_active && $recheck_ts >= $target_ts );
		}

		return false;
	}

	/**
	 * Releases an acquired lock.
	 *
	 * Strictly binds to token_key, owner_id, and fencing_token so stale generations cannot release newer leases.
	 *
	 * @param string $resource_key  Identifier for resource.
	 * @param string $owner_id      Caller request UUID.
	 * @param int    $fencing_token Caller fencing token for generation verification.
	 * @return bool True if released.
	 */
	public static function release_lock( string $resource_key, string $owner_id, int $fencing_token ): bool {
		global $wpdb;

		if ( '' === trim( $resource_key ) || '' === trim( $owner_id ) || $fencing_token < 1 ) {
			return false;
		}

		$table    = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$lock_key = self::get_lock_token_key( $resource_key );
		$past_dt  = gmdate( 'Y-m-d H:i:s', 0 );

		// Rather than hard-deleting the row, we expire the lock and mark it used
		// while retaining the fencing_token counter to ensure strict monotonic increase.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET expires_at = %s, used = 1 WHERE token_key = %s AND token_type = 'lock' AND owner_id = %s AND fencing_token = %d AND used = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$past_dt,
				$lock_key,
				$owner_id,
				$fencing_token
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Recursively canonicalizes data structures: sorts associative keys alphabetically
	 * while strictly preserving sequential/indexed list array ordering.
	 *
	 * @param mixed $data Arbitrary input data.
	 * @return mixed Canonical representation.
	 */
	public static function canonicalize_args( mixed $data ): mixed {
		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		if ( empty( $data ) ) {
			return array();
		}

		// Check if sequential indexed list.
		$is_list = function_exists( 'array_is_list' )
			? array_is_list( $data )
			: ( array_keys( $data ) === range( 0, count( $data ) - 1 ) );

		if ( $is_list ) {
			$canonical = array();
			foreach ( $data as $item ) {
				$canonical[] = self::canonicalize_args( $item );
			}
			return $canonical;
		}

		// Associative array: sort keys recursively.
		ksort( $data, SORT_STRING );
		$canonical = array();
		foreach ( $data as $key => $value ) {
			$canonical[ (string) $key ] = self::canonicalize_args( $value );
		}
		return $canonical;
	}

	/**
	 * Generates a normalized SHA-256 hash of mutation arguments using recursive canonicalization.
	 *
	 * @param array $args Mutation arguments.
	 * @return string 64-character SHA-256 hex hash.
	 */
	public static function hash_args( array $args ): string {
		$canonical = self::canonicalize_args( $args );
		$json      = wp_json_encode( $canonical );
		return hash( 'sha256', false !== $json ? $json : serialize( $canonical ) );
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
	 * Strictly identity-bound: requires idempotency_key, ability, user_id, credential_uuid, and args.
	 * Note: Provides foundational identity-bound result caching and argument conflict validation.
	 * Full atomic execution claim orchestration ('pending' -> 'completed') is implemented in mutation middleware.
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
		string $ability,
		int $user_id,
		?string $credential_uuid,
		array $args
	): array|\WP_Error|null {
		global $wpdb;

		if ( '' === trim( $idempotency_key ) || '' === trim( $ability ) || $user_id < 1 ) {
			return null;
		}

		$table     = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$token_key = self::get_idempotency_token_key( $idempotency_key, $ability, $user_id, $credential_uuid );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload FROM {$table} WHERE token_key = %s AND token_type = 'idempotency' AND expires_at > UTC_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token_key
			),
			ARRAY_A
		);

		// Case A: No database row or already expired => normal cache miss.
		if ( empty( $row ) ) {
			return null;
		}

		// Case B: Database row exists but payload is missing or empty => corrupted record.
		if ( ! isset( $row['payload'] ) || '' === trim( (string) $row['payload'] ) ) {
			return new \WP_Error(
				'idempotency_cache_corrupt',
				__( 'Corrupted idempotency record: payload is empty or invalid.', 'full-elementor-mcp' ),
				array( 'idempotency_key' => $idempotency_key )
			);
		}

		$payload = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $payload ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'idempotency_cache_corrupt',
				__( 'Corrupted idempotency record: payload is not valid JSON.', 'full-elementor-mcp' ),
				array( 'idempotency_key' => $idempotency_key )
			);
		}

		// Fail closed on malformed or corrupted payload structure:
		// Required keys: args_hash (valid 64-char hex string), ability (matching), user_id (matching),
		// credential_uuid (matching or null), result (array).
		if (
			empty( $payload['args_hash'] ) || ! is_string( $payload['args_hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/i', $payload['args_hash'] ) ||
			! isset( $payload['ability'] ) || $payload['ability'] !== $ability ||
			! isset( $payload['user_id'] ) || (int) $payload['user_id'] !== $user_id ||
			! array_key_exists( 'credential_uuid', $payload ) ||
			( (string) ( $payload['credential_uuid'] ?? '' ) !== (string) ( $credential_uuid ?? '' ) ) ||
			! isset( $payload['result'] ) || ! is_array( $payload['result'] )
		) {
			return new \WP_Error(
				'idempotency_cache_corrupt',
				__( 'Corrupted idempotency record: payload structure is malformed or identity does not match.', 'full-elementor-mcp' ),
				array( 'idempotency_key' => $idempotency_key )
			);
		}

		// Always verify args_hash when present in payload, even for empty args.
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

		return $payload['result'];
	}

	/**
	 * Stores mutation result for idempotency caching with strict identity and argument binding.
	 *
	 * @param string      $idempotency_key Caller idempotency UUID or key.
	 * @param string      $ability         Target ability name.
	 * @param int         $user_id         Authenticated user ID.
	 * @param string|null $credential_uuid Authenticated credential UUID.
	 * @param array       $args            Arguments passed to the mutation.
	 * @param array       $result          Response data to cache.
	 * @param int         $ttl_seconds     Cache TTL in seconds.
	 * @return bool True if stored, false on DB failure.
	 */
	public static function set_idempotent_result(
		string $idempotency_key,
		string $ability,
		int $user_id,
		?string $credential_uuid,
		array $args,
		array $result,
		int $ttl_seconds = self::DEFAULT_IDEMPOTENCY_TTL
	): bool {
		global $wpdb;

		if ( '' === trim( $idempotency_key ) || '' === trim( $ability ) || $user_id < 1 ) {
			return false;
		}

		$table     = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$token_key = self::get_idempotency_token_key( $idempotency_key, $ability, $user_id, $credential_uuid );
		$args_hash = self::hash_args( $args );
		$ttl       = max( 10, $ttl_seconds );
		$owner_id  = $credential_uuid ?? (string) $user_id;

		$payload_data = array(
			'args_hash'       => $args_hash,
			'ability'         => $ability,
			'user_id'         => $user_id,
			'credential_uuid' => $credential_uuid,
			'result'          => $result,
		);

		$encoded = wp_json_encode( $payload_data );
		if ( false === $encoded ) {
			return false;
		}

		// Use consistent database UTC authority for created_at and expires_at.
		$replaced = $wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO {$table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
				VALUES (%s, 'idempotency', %s, 0, %s, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), 0)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token_key,
				$owner_id,
				$encoded,
				$ttl
			)
		);

		// Check DB write result: false on failure, 0 if no rows affected.
		return ( false !== $replaced && $replaced > 0 );
	}

	/**
	 * Prunes expired temporary tokens (idempotency, confirmations, etc.) older than threshold.
	 *
	 * CRITICAL ARCHITECTURAL INVARIANT: Lock rows (token_type = 'lock') serve as the permanent
	 * authoritative fencing counter tombstones for their respective resources. They MUST NEVER be
	 * pruned or deleted from the database, ensuring monotonically increasing fencing tokens across
	 * subsequent re-acquisitions and takeovers.
	 *
	 * Uses the database UTC time authority (DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)) for
	 * internally consistent lifecycle cleanup.
	 *
	 * @param int $older_than_seconds Cutoff age in seconds (default 24h = 86400).
	 * @return int Number of pruned rows.
	 */
	public static function prune_expired( int $older_than_seconds = 86400 ): int {
		global $wpdb;

		$table   = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$seconds = max( 60, $older_than_seconds );

		$pruned = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND) AND token_type != 'lock'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$seconds
			)
		);

		return false !== $pruned ? (int) $pruned : 0;
	}

	/**
	 * Returns current UTC Unix timestamp as evaluated by the database authority.
	 *
	 * Falls back to PHP time() if database query is unavailable.
	 *
	 * @return int UTC timestamp in seconds.
	 */
	public static function get_database_utc_timestamp(): int {
		global $wpdb;
		$db_now = $wpdb->get_var( 'SELECT UTC_TIMESTAMP()' );
		return ( false !== $db_now && null !== $db_now ) ? (int) strtotime( $db_now . ' UTC' ) : time();
	}
}
