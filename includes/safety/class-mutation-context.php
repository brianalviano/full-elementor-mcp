<?php
/**
 * Request-Local Mutation Execution Context for Full Elementor MCP.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the server-side request-local execution context stack.
 *
 * Ensures:
 * - Thread-safe (request-local) context propagation during ability execution.
 * - Re-entrancy safety: nested mutations fail closed with 'nested_mutation_not_supported'.
 * - Centralized low-level persistence guard asserting lock & fencing immediately before write.
 * - Strict context cleanup in finally blocks.
 *
 * @since 1.8.0
 */
final class Full_Elementor_MCP_Mutation_Context {

	/**
	 * Active execution context stack.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $stack = array();

	/**
	 * Enters a new mutation execution context.
	 *
	 * @param array<string, mixed> $data Context properties.
	 * @return string Context token for verified exit.
	 * @throws \RuntimeException If nested mutations are attempted.
	 */
	public static function enter( array $data ): string {
		$is_rollback = ! empty( $data['is_rollback'] );

		// If a mutation context is already active and the new one is not an internal rollback, fail closed.
		if ( ! empty( self::$stack ) && ! $is_rollback ) {
			$current = end( self::$stack );
			if ( empty( $current['is_readonly'] ) ) {
				throw new \RuntimeException( 'nested_mutation_not_supported' );
			}
		}

		$token = wp_generate_uuid4();
		if ( empty( $token ) ) {
			$token = bin2hex( random_bytes( 16 ) );
		}

		$entry = array(
			'token'             => $token,
			'ability'           => (string) ( $data['ability'] ?? '' ),
			'request_uuid'      => (string) ( $data['request_uuid'] ?? $token ),
			'user_id'           => (int) ( $data['user_id'] ?? get_current_user_id() ),
			'credential_uuid'   => isset( $data['credential_uuid'] ) ? (string) $data['credential_uuid'] : null,
			'resource_key'      => (string) ( $data['resource_key'] ?? '' ),
			'object_id'         => (int) ( $data['object_id'] ?? 0 ),
			'owner_id'          => (string) ( $data['owner_id'] ?? '' ),
			'fencing_token'     => (int) ( $data['fencing_token'] ?? 0 ),
			'journal_id'        => (int) ( $data['journal_id'] ?? 0 ),
			'idempotency_key'   => isset( $data['idempotency_key'] ) ? (string) $data['idempotency_key'] : null,
			'is_dry_run'        => ! empty( $data['is_dry_run'] ),
			'is_rollback'       => $is_rollback,
			'is_readonly'       => ! empty( $data['is_readonly'] ),
			'created_object_id' => isset( $data['created_object_id'] ) ? (int) $data['created_object_id'] : null,
		);

		self::$stack[] = $entry;

		return $token;
	}

	/**
	 * Leaves an active context using the matching token.
	 *
	 * @param string $token The token returned by enter().
	 * @return bool True if successfully popped.
	 */
	public static function leave( string $token ): bool {
		if ( empty( self::$stack ) ) {
			return false;
		}

		$last_index = count( self::$stack ) - 1;
		if ( self::$stack[ $last_index ]['token'] === $token ) {
			array_pop( self::$stack );
			return true;
		}

		// Fallback search in case of unexpected unnesting.
		foreach ( self::$stack as $idx => $ctx ) {
			if ( $ctx['token'] === $token ) {
				array_splice( self::$stack, $idx, 1 );
				return true;
			}
		}

		return false;
	}

	/**
	 * Resets the context stack completely.
	 *
	 * Primarily used for test harness isolation and process cleanups.
	 */
	public static function reset(): void {
		self::$stack = array();
	}

	/**
	 * Gets the current topmost active context.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		if ( empty( self::$stack ) ) {
			return null;
		}
		return self::$stack[ count( self::$stack ) - 1 ];
	}

	/**
	 * Checks whether an active execution context exists.
	 *
	 * @return bool
	 */
	public static function has_active_context(): bool {
		return ! empty( self::$stack );
	}

	/**
	 * Updates properties on the current active context (e.g. created_object_id).
	 *
	 * @param array<string, mixed> $data
	 * @return bool
	 */
	public static function update_current( array $data ): bool {
		if ( empty( self::$stack ) ) {
			return false;
		}

		$last_index = count( self::$stack ) - 1;
		foreach ( $data as $k => $v ) {
			self::$stack[ $last_index ][ $k ] = $v;
		}

		return true;
	}

	/**
	 * Binds newly created object ID to the active mutation context.
	 *
	 * @param int $created_object_id Durably inserted entity ID.
	 * @return bool True on success.
	 */
	public static function bind_created_object_id( int $created_object_id ): bool {
		return self::update_current( array( 'created_object_id' => $created_object_id ) );
	}

	/**
	 * Marks that persistent writing has begun for the current mutation context.
	 */
	public static function mark_write_started(): void {
		self::update_current( array( 'write_started' => true ) );
	}

	/**
	 * Increments the persistent write counter for the current mutation context.
	 */
	public static function increment_write_count(): void {
		$ctx   = self::current();
		$count = (int) ( $ctx['write_count'] ?? 0 ) + 1;
		self::update_current( array( 'write_count' => $count ) );
	}

	/**
	 * Checks whether a persistent write has been started under the active context.
	 *
	 * @return bool
	 */
	public static function has_write_started(): bool {
		$ctx = self::current();
		return ! empty( $ctx['write_started'] );
	}

	/**
	 * Returns the count of persistent writes executed under the active context.
	 *
	 * @return int
	 */
	public static function get_write_count(): int {
		$ctx = self::current();
		return (int) ( $ctx['write_count'] ?? 0 );
	}

	/**
	 * Asserts that the active mutation context is valid for an initial object creation primitive.
	 *
	 * Before the object exists, context authorizes ONLY creation. After creation,
	 * bind_created_object_id must be called before subsequent post writes.
	 *
	 * @return true|\WP_Error True if authorized for creation; WP_Error on failure.
	 */
	public static function assert_create_write_context(): bool|\WP_Error {
		$ctx = self::current();
		if ( null === $ctx ) {
			return new \WP_Error(
				'mutation_context_missing',
				__( 'Persistent write rejected: no active Full Elementor MCP mutation context exists.', 'full-elementor-mcp' )
			);
		}

		if ( ! empty( $ctx['is_dry_run'] ) ) {
			return new \WP_Error(
				'dry_run_write_blocked',
				__( 'Persistent write rejected: execution is running in dry-run mode.', 'full-elementor-mcp' )
			);
		}

		if ( ! empty( $ctx['is_readonly'] ) ) {
			return new \WP_Error(
				'readonly_context_write_blocked',
				__( 'Persistent write rejected: readonly context cannot perform persistent modifications.', 'full-elementor-mcp' )
			);
		}

		$norm_actual = trim( (string) $ctx['resource_key'] );
		if ( 0 !== strpos( $norm_actual, 'create:' ) ) {
			return new \WP_Error(
				'invalid_create_context',
				__( 'Persistent creation rejected: active context is not a creation operation.', 'full-elementor-mcp' )
			);
		}

		if ( ! empty( $ctx['created_object_id'] ) ) {
			return new \WP_Error(
				'created_object_already_bound',
				__( 'Persistent creation rejected: an object ID has already been created and bound to this context.', 'full-elementor-mcp' )
			);
		}

		$owner_id      = (string) $ctx['owner_id'];
		$fencing_token = (int) $ctx['fencing_token'];

		if ( '' === $owner_id || $fencing_token < 1 ) {
			return new \WP_Error(
				'write_fencing_missing',
				__( 'Persistent write rejected: active context lacks valid owner ID or fencing token.', 'full-elementor-mcp' )
			);
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Lock_Manager' ) ) {
			return new \WP_Error(
				'write_fencing_unavailable',
				__( 'Persistent write rejected: authoritative Lock Manager infrastructure is unavailable.', 'full-elementor-mcp' )
			);
		}

		return Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $norm_actual, $owner_id, $fencing_token );
	}

	/**
	 * Asserts that an active, valid mutation context exists and owns the target resource
	 * immediately before a persistent write.
	 *
	 * Checks:
	 * 1. Active context exists.
	 * 2. Not in dry-run mode.
	 * 3. Not in readonly mode.
	 * 4. Target resource matches expected canonical resource key (or newly bound created entity).
	 * 5. Owner ID and fencing token exist.
	 * 6. Fencing ownership is strictly asserted against Lock Manager (fails closed if missing).
	 *
	 * @param string $expected_resource_key The canonical resource key (e.g. 'post:123').
	 * @return true|\WP_Error True if authorized to write; WP_Error on failure.
	 */
	public static function assert_active_write_context( string $expected_resource_key ): bool|\WP_Error {
		/**
		 * Filter to bypass context enforcement in non-MCP environments (e.g. WP-Admin native editor).
		 * Default: true when MCP context is active.
		 */
		$enforce = apply_filters( 'full_elementor_mcp_enforce_write_context', self::has_active_context(), $expected_resource_key );
		if ( ! $enforce ) {
			return true;
		}

		$ctx = self::current();
		if ( null === $ctx ) {
			return new \WP_Error(
				'mutation_context_missing',
				__( 'Persistent write rejected: no active Full Elementor MCP mutation context exists.', 'full-elementor-mcp' )
			);
		}

		if ( ! empty( $ctx['is_dry_run'] ) ) {
			return new \WP_Error(
				'dry_run_write_blocked',
				__( 'Persistent write rejected: execution is running in dry-run mode.', 'full-elementor-mcp' )
			);
		}

		if ( ! empty( $ctx['is_readonly'] ) ) {
			return new \WP_Error(
				'readonly_context_write_blocked',
				__( 'Persistent write rejected: readonly context cannot perform persistent modifications.', 'full-elementor-mcp' )
			);
		}

		$norm_expected = trim( $expected_resource_key );
		$norm_actual   = trim( (string) $ctx['resource_key'] );

		// For create operations, resource key is 'create:<ability>:<hash>' before persistence,
		// and post write binds to post:<ID>.
		$is_create = 0 === strpos( $norm_actual, 'create:' );

		if ( $is_create ) {
			$bound_id = (int) ( $ctx['created_object_id'] ?? 0 );
			if ( $bound_id <= 0 ) {
				return new \WP_Error(
					'created_object_not_yet_bound',
					__( 'Persistent post write rejected: target entity ID has not been durably created or bound yet.', 'full-elementor-mcp' )
				);
			}

			$expected_bound_key = "post:{$bound_id}";
			if ( $norm_expected !== $expected_bound_key ) {
				return new \WP_Error(
					'write_resource_mismatch',
					sprintf(
						/* translators: 1: expected resource key, 2: actual resource key */
						__( 'Persistent write rejected: target resource "%1$s" does not match newly bound created entity "%2$s".', 'full-elementor-mcp' ),
						$norm_expected,
						$expected_bound_key
					)
				);
			}
		} elseif ( '' !== $norm_expected && '' !== $norm_actual && $norm_expected !== $norm_actual ) {
			return new \WP_Error(
				'write_resource_mismatch',
				sprintf(
					/* translators: 1: expected resource key, 2: actual resource key */
					__( 'Persistent write rejected: target resource "%1$s" does not match active lock resource "%2$s".', 'full-elementor-mcp' ),
					$norm_expected,
					$norm_actual
				)
			);
		}

		$owner_id      = (string) $ctx['owner_id'];
		$fencing_token = (int) $ctx['fencing_token'];

		if ( '' === $owner_id || $fencing_token < 1 ) {
			return new \WP_Error(
				'write_fencing_missing',
				__( 'Persistent write rejected: active context lacks valid owner ID or fencing token.', 'full-elementor-mcp' )
			);
		}

		// Fail closed if Lock Manager is unavailable:
		if ( ! class_exists( 'Full_Elementor_MCP_Lock_Manager' ) ) {
			return new \WP_Error(
				'write_fencing_unavailable',
				__( 'Persistent write rejected: authoritative Lock Manager infrastructure is unavailable.', 'full-elementor-mcp' )
			);
		}

		return Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership( $norm_actual, $owner_id, $fencing_token );
	}
}

