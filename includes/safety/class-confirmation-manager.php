<?php
/**
 * Server-Issued Confirmation Token Manager for Safe Elementor MCP.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages cryptographically random, short-lived, argument-bound confirmation tokens.
 *
 * Enforces Phase 4 confirmation gates for high-risk, irreversible, or protected mutations.
 * All tokens are single-use, bound strictly to user, credential UUID, ability,
 * canonical semantic arguments hash, and canonical resource key.
 *
 * @since 1.8.0
 */
final class Full_Elementor_MCP_Confirmation_Manager {

	/**
	 * Default token validity duration in seconds (5 minutes).
	 */
	const TOKEN_LIFETIME_SECONDS = 300;

	/**
	 * Control arguments stripped when computing semantic mutation args hash.
	 */
	const CONTROL_ARGUMENTS = array(
		'confirmation_token',
		'dry_run',
		'idempotency_key',
		'_safety',
		'allow_critical_override',
	);

	/**
	 * Computes a deterministic canonical SHA-256 hash of mutation arguments.
	 *
	 * Strips only middleware control envelope/fields at the top level and canonicalizes map keys.
	 *
	 * @param array<string, mixed> $args
	 * @return string|\WP_Error 64-character hex hash or WP_Error on JSON encode failure.
	 */
	public static function canonical_args_hash( array $args ): string|\WP_Error {
		$clean = self::strip_top_level_control_fields( $args );
		$canon = self::canonicalize_value( $clean );
		$json  = wp_json_encode( $canon );
		if ( false === $json ) {
			return new \WP_Error( 'json_encode_failed', __( 'Failed to encode arguments for canonical hash.', 'full-elementor-mcp' ) );
		}
		return hash( 'sha256', $json );
	}

	/**
	 * Strips top-level middleware control fields only.
	 *
	 * Preserves nested element parameters (e.g. settings.dry_run).
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private static function strip_top_level_control_fields( array $args ): array {
		$result = array();
		foreach ( $args as $k => $v ) {
			if ( in_array( $k, self::CONTROL_ARGUMENTS, true ) ) {
				continue;
			}
			$result[ $k ] = $v;
		}
		return $result;
	}

	/**
	 * Recursively canonicalizes data (sorts associative keys, preserves sequential arrays).
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	private static function canonicalize_value( mixed $data ): mixed {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$is_assoc = array_keys( $data ) !== range( 0, count( $data ) - 1 );
		if ( $is_assoc ) {
			ksort( $data, SORT_STRING );
		}

		$result = array();
		foreach ( $data as $k => $v ) {
			$result[ $k ] = self::canonicalize_value( $v );
		}

		return $result;
	}

	/**
	 * Issues a new server-side confirmation challenge for a high-risk mutation.
	 *
	 * @param string      $ability         Ability slug.
	 * @param array       $args            Incoming mutation arguments.
	 * @param int         $user_id         Active user ID.
	 * @param string|null $credential_uuid Authenticated App Password UUID.
	 * @param string      $resource_key    Canonical resource key.
	 * @param array       $reasons         Human-readable safety reasons.
	 * @return array{confirmation_required: bool, confirmation_token: string, expires_in_seconds: int, ability: string, resource_key: string, reasons: string[], impact_summary: string}|\WP_Error
	 */
	public static function create_challenge(
		string $ability,
		array $args,
		int $user_id,
		?string $credential_uuid,
		string $resource_key,
		array $reasons = array()
	): array|\WP_Error {
		global $wpdb;

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return new \WP_Error( 'safety_db_unavailable', __( 'Safety database installer unavailable.', 'full-elementor-mcp' ) );
		}

		$table = Full_Elementor_MCP_Database_Installer::get_tokens_table();

		// Generate cryptographically random 32-character token.
		$raw_token = bin2hex( random_bytes( 16 ) );
		$token_key = 'conf:' . hash( 'sha256', $raw_token );
		$args_hash = self::canonical_args_hash( $args );

		if ( is_wp_error( $args_hash ) ) {
			return $args_hash;
		}

		$payload = array(
			'ability'         => $ability,
			'user_id'         => $user_id,
			'credential_uuid' => $credential_uuid,
			'resource_key'    => $resource_key,
			'args_hash'       => $args_hash,
			'reasons'         => array_values( array_unique( $reasons ) ),
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		);

		$payload_json = wp_json_encode( $payload );
		if ( false === $payload_json ) {
			return new \WP_Error( 'payload_encode_failed', __( 'Failed to encode confirmation token payload.', 'full-elementor-mcp' ) );
		}

		$lifetime = (int) apply_filters( 'full_elementor_mcp_confirmation_token_lifetime', self::TOKEN_LIFETIME_SECONDS, $ability );

		// Authoritative clock insertion using DB UTC_TIMESTAMP():
		$query = $wpdb->prepare(
			"INSERT INTO {$table} (token_key, token_type, owner_id, fencing_token, payload, created_at, expires_at, used)
			 VALUES (%s, 'confirmation', %s, 0, %s, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), 0)",
			$token_key,
			(string) $user_id,
			$payload_json,
			$lifetime
		);

		$inserted = $wpdb->query( $query );
		if ( false === $inserted ) {
			return new \WP_Error( 'confirmation_token_storage_failed', __( 'Failed to store confirmation challenge token.', 'full-elementor-mcp' ) );
		}

		return array(
			'confirmation_required' => true,
			'confirmation_token'    => $raw_token,
			'expires_in_seconds'    => $lifetime,
			'ability'               => $ability,
			'resource_key'          => $resource_key,
			'reasons'               => $payload['reasons'],
			'impact_summary'        => self::generate_impact_summary( $ability, $args, $payload['reasons'] ),
		);
	}

	/**
	 * Validates a confirmation token without consuming it.
	 *
	 * @param string      $raw_token       Caller-supplied token plaintext.
	 * @param string      $ability         Ability being executed.
	 * @param array       $args            Incoming mutation arguments.
	 * @param int         $user_id         Current user ID.
	 * @param string|null $credential_uuid Current credential UUID.
	 * @param string      $resource_key    Target canonical resource key.
	 * @return true|\WP_Error True if valid; WP_Error on mismatch/expiry.
	 */
	public static function validate(
		string $raw_token,
		string $ability,
		array $args,
		int $user_id,
		?string $credential_uuid,
		string $resource_key
	): bool|\WP_Error {
		return self::validate_internal( $raw_token, $ability, $args, $user_id, $credential_uuid, $resource_key, false );
	}

	/**
	 * Atomically consumes a validated confirmation token via CAS.
	 *
	 * @param string      $raw_token       Caller-supplied token plaintext.
	 * @param string      $ability         Ability being executed.
	 * @param array       $args            Incoming mutation arguments.
	 * @param int         $user_id         Current user ID.
	 * @param string|null $credential_uuid Current credential UUID.
	 * @param string      $resource_key    Target canonical resource key.
	 * @return true|\WP_Error True if successfully consumed; WP_Error otherwise.
	 */
	public static function consume(
		string $raw_token,
		string $ability,
		array $args,
		int $user_id,
		?string $credential_uuid,
		string $resource_key
	): bool|\WP_Error {
		return self::validate_internal( $raw_token, $ability, $args, $user_id, $credential_uuid, $resource_key, true );
	}

	/**
	 * Validates a confirmation token and optionally consumes it atomically via CAS.
	 *
	 * @param string      $raw_token       Caller-supplied token plaintext.
	 * @param string      $ability         Ability being executed.
	 * @param array       $args            Incoming mutation arguments.
	 * @param int         $user_id         Current user ID.
	 * @param string|null $credential_uuid Current credential UUID.
	 * @param string      $resource_key    Target canonical resource key.
	 * @param bool        $consume         True to atomically consume the token (default: true).
	 * @return true|\WP_Error True if valid (and consumed); WP_Error on mismatch/expiry.
	 */
	public static function validate_and_consume(
		string $raw_token,
		string $ability,
		array $args,
		int $user_id,
		?string $credential_uuid,
		string $resource_key,
		bool $consume = true
	): bool|\WP_Error {
		return self::validate_internal( $raw_token, $ability, $args, $user_id, $credential_uuid, $resource_key, $consume );
	}

	/**
	 * Internal helper to validate and optionally consume a confirmation token.
	 *
	 * @param string      $raw_token
	 * @param string      $ability
	 * @param array       $args
	 * @param int         $user_id
	 * @param string|null $credential_uuid
	 * @param string      $resource_key
	 * @param bool        $consume
	 * @return bool|\WP_Error
	 */
	private static function validate_internal(
		string $raw_token,
		string $ability,
		array $args,
		int $user_id,
		?string $credential_uuid,
		string $resource_key,
		bool $consume
	): bool|\WP_Error {
		global $wpdb;

		$token_clean = trim( $raw_token );
		if ( '' === $token_clean || strlen( $token_clean ) < 16 ) {
			return new \WP_Error( 'confirmation_token_invalid', __( 'Confirmation token is invalid or missing.', 'full-elementor-mcp' ) );
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Database_Installer' ) ) {
			return new \WP_Error( 'safety_db_unavailable', __( 'Safety database installer unavailable.', 'full-elementor-mcp' ) );
		}

		$table     = Full_Elementor_MCP_Database_Installer::get_tokens_table();
		$token_key = 'conf:' . hash( 'sha256', $token_clean );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload, used, (expires_at > UTC_TIMESTAMP()) AS is_valid_time FROM {$table} WHERE token_key = %s AND token_type = 'confirmation'",
				$token_key
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return new \WP_Error( 'confirmation_token_not_found', __( 'Confirmation challenge token not found.', 'full-elementor-mcp' ) );
		}

		if ( 1 === (int) $row['used'] ) {
			return new \WP_Error( 'confirmation_token_used', __( 'Confirmation token has already been consumed.', 'full-elementor-mcp' ) );
		}

		if ( empty( $row['is_valid_time'] ) ) {
			return new \WP_Error( 'confirmation_token_expired', __( 'Confirmation token has expired.', 'full-elementor-mcp' ) );
		}

		$payload = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'confirmation_payload_corrupt', __( 'Stored confirmation challenge payload is corrupted.', 'full-elementor-mcp' ) );
		}

		// Verify strict argument bindings:
		if ( ( $payload['ability'] ?? '' ) !== $ability ) {
			return new \WP_Error( 'confirmation_ability_mismatch', __( 'Confirmation token was issued for a different ability.', 'full-elementor-mcp' ) );
		}

		if ( (int) ( $payload['user_id'] ?? 0 ) !== $user_id ) {
			return new \WP_Error( 'confirmation_user_mismatch', __( 'Confirmation token was issued for a different user identity.', 'full-elementor-mcp' ) );
		}

		// Exact credential UUID equality: null matches null, non-null matches exact string.
		if ( array_key_exists( 'credential_uuid', $payload ) ) {
			if ( $payload['credential_uuid'] !== $credential_uuid ) {
				return new \WP_Error( 'confirmation_credential_mismatch', __( 'Confirmation token was issued for a different credential UUID.', 'full-elementor-mcp' ) );
			}
		}

		if ( (string) ( $payload['resource_key'] ?? '' ) !== $resource_key ) {
			return new \WP_Error( 'confirmation_resource_mismatch', __( 'Confirmation token was issued for a different resource.', 'full-elementor-mcp' ) );
		}

		$current_args_hash = self::canonical_args_hash( $args );
		if ( is_wp_error( $current_args_hash ) ) {
			return $current_args_hash;
		}
		if ( (string) ( $payload['args_hash'] ?? '' ) !== $current_args_hash ) {
			return new \WP_Error( 'confirmation_args_mismatch', __( 'Confirmation token does not match modified mutation arguments.', 'full-elementor-mcp' ) );
		}

		if ( ! $consume ) {
			// Read-only validation passed.
			return true;
		}

		// Single-use atomic CAS consumption using DB UTC:
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET used = 1 WHERE token_key = %s AND token_type = 'confirmation' AND used = 0 AND expires_at > UTC_TIMESTAMP()",
				$token_key
			)
		);

		if ( 1 !== $updated ) {
			return new \WP_Error( 'confirmation_token_used', __( 'Confirmation token could not be claimed (already consumed or expired).', 'full-elementor-mcp' ) );
		}

		return true;
	}

	/**
	 * Generates a concise, safe, human-readable summary of the mutation's impact.
	 *
	 * @param string   $ability
	 * @param array    $args
	 * @param string[] $reasons
	 * @return string
	 */
	public static function generate_impact_summary( string $ability, array $args, array $reasons ): string {
		$post_id = absint( $args['post_id'] ?? ( $args['template_id'] ?? ( $args['snippet_id'] ?? 0 ) ) );
		$force   = ( true === ( $args['force'] ?? false ) ) || ( true === ( $args['force_delete'] ?? false ) );

		if ( false !== strpos( $ability, 'delete' ) ) {
			if ( $force ) {
				return sprintf( __( 'Permanently and irreversibly deletes resource %d from the database.', 'full-elementor-mcp' ), $post_id );
			}
			return sprintf( __( 'Moves resource %d to trash.', 'full-elementor-mcp' ), $post_id );
		}

		if ( false !== strpos( $ability, 'global' ) || 'full-elementor-mcp/set-active-kit' === $ability ) {
			return __( 'Applies site-wide design kit changes that impact the entire website layout.', 'full-elementor-mcp' );
		}

		if ( false !== strpos( $ability, 'custom-js' ) || false !== strpos( $ability, 'code-snippet' ) ) {
			return __( 'Injects raw executable code into the document or site.', 'full-elementor-mcp' );
		}

		if ( ! empty( $reasons ) ) {
			return implode( ' ', $reasons );
		}

		return sprintf( __( 'Performs high-risk modification on resource %d.', 'full-elementor-mcp' ), $post_id );
	}
}
