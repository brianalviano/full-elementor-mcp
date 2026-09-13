<?php
/**
 * Configuration and policy settings for the Safe Elementor MCP safety subsystem.
 *
 * Single source of truth for Safe Mode, Break-Glass policies, undo retention,
 * checkpoint storage quotas, and protected page IDs.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages safety configuration options.
 */
class Full_Elementor_MCP_Safety_Settings {

	/**
	 * Option name in WordPress options table.
	 */
	public const OPTION_NAME = 'full_elementor_mcp_safety_settings';

	/**
	 * Policy constants for critical operations.
	 */
	public const POLICY_AGENT_CONFIRMATION = 'agent_confirmation';
	public const POLICY_ADMIN_APPROVAL     = 'admin_approval';
	public const POLICY_DISABLED           = 'disabled';

	/**
	 * In-memory cache of settings for current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Returns default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'safe_mode'                  => true,
			'break_glass_policy'         => self::POLICY_AGENT_CONFIRMATION,
			'undo_enabled'               => true,
			'audit_retention_days'       => 30,
			'max_checkpoints_count'      => 15,
			'max_checkpoints_storage_mb' => 100,
			'allow_permanent_delete'     => false, // Enforce Trash over permanent purge by default.
			'custom_protected_page_ids'  => array(),
			'checkpoint_key_version'     => 1,
		);
	}

	/**
	 * Retrieves all safety settings, merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Normalize raw persisted settings through sanitize() before caching and returning.
		self::$cache = self::sanitize( $stored );
		return self::$cache;
	}

	/**
	 * Gets a specific safety setting value.
	 *
	 * @param string $key     Setting key name.
	 * @param mixed  $default Optional fallback if setting does not exist.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$settings = self::get_all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Updates safety settings.
	 *
	 * @param array<string, mixed> $new_settings Settings to merge and persist.
	 * @return bool True if updated.
	 */
	public static function update( array $new_settings ): bool {
		$current   = self::get_all();
		$sanitized = self::sanitize( array_merge( $current, $new_settings ) );

		$updated = update_option( self::OPTION_NAME, $sanitized );
		if ( $updated ) {
			self::$cache = $sanitized;
		}

		return $updated;
	}

	/**
	 * Sanitizes a boolean value predictably, preventing false positives from string representations.
	 *
	 * Explicitly maps:
	 * - false, 0, '0', 'false', 'off', 'no', '' => false
	 * - true, 1, '1', 'true', 'on', 'yes'       => true
	 * Unsupported/malformed types (arrays, objects, resources) fail-safe to $default.
	 *
	 * @param mixed $value   Input value.
	 * @param bool  $default Fallback if null, unsupported, or unparseable.
	 * @return bool Sanitized boolean.
	 */
	public static function sanitize_bool( mixed $value, bool $default = false ): bool {
		if ( null === $value ) {
			return $default;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return 1 === (int) $value;
		}
		if ( is_string( $value ) ) {
			$filtered = filter_var( trim( $value ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			return null !== $filtered ? $filtered : $default;
		}
		// Strictly fail safe on unsupported or malformed types (arrays, objects, resources).
		return $default;
	}

	/**
	 * Sanitizes safety settings array.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::get_defaults();
		$clean    = array();

		$clean['safe_mode']              = isset( $input['safe_mode'] ) ? self::sanitize_bool( $input['safe_mode'], true ) : true;
		$clean['undo_enabled']           = isset( $input['undo_enabled'] ) ? self::sanitize_bool( $input['undo_enabled'], true ) : true;
		$clean['allow_permanent_delete'] = isset( $input['allow_permanent_delete'] ) ? self::sanitize_bool( $input['allow_permanent_delete'], false ) : false;

		$valid_policies = array(
			self::POLICY_AGENT_CONFIRMATION,
			self::POLICY_ADMIN_APPROVAL,
			self::POLICY_DISABLED,
		);
		$policy = sanitize_key( $input['break_glass_policy'] ?? self::POLICY_AGENT_CONFIRMATION );
		$clean['break_glass_policy'] = in_array( $policy, $valid_policies, true ) ? $policy : self::POLICY_AGENT_CONFIRMATION;

		$clean['audit_retention_days']       = max( 1, min( 365, absint( $input['audit_retention_days'] ?? 30 ) ) );
		$clean['max_checkpoints_count']      = max( 1, min( 100, absint( $input['max_checkpoints_count'] ?? 15 ) ) );
		$clean['max_checkpoints_storage_mb'] = max( 10, min( 2048, absint( $input['max_checkpoints_storage_mb'] ?? 100 ) ) );
		$clean['checkpoint_key_version']     = max( 1, absint( $input['checkpoint_key_version'] ?? 1 ) );

		$custom_ids = array();
		if ( ! empty( $input['custom_protected_page_ids'] ) && is_array( $input['custom_protected_page_ids'] ) ) {
			foreach ( $input['custom_protected_page_ids'] as $id ) {
				$val = absint( $id );
				if ( $val > 0 ) {
					$custom_ids[] = $val;
				}
			}
		}
		$clean['custom_protected_page_ids'] = array_values( array_unique( $custom_ids ) );

		return $clean;
	}

	/**
	 * Whether Safe Mode is enabled.
	 */
	public static function is_safe_mode_enabled(): bool {
		return self::sanitize_bool( self::get( 'safe_mode', true ), true );
	}

	/**
	 * Whether undo journaling is enabled.
	 */
	public static function is_undo_enabled(): bool {
		return self::sanitize_bool( self::get( 'undo_enabled', true ), true );
	}

	/**
	 * Gets the active Break-Glass policy for critical operations.
	 */
	public static function get_break_glass_policy(): string {
		return (string) self::get( 'break_glass_policy', self::POLICY_AGENT_CONFIRMATION );
	}

	/**
	 * Whether permanent delete (`force=true`) is allowed.
	 */
	public static function is_permanent_delete_allowed(): bool {
		return self::sanitize_bool( self::get( 'allow_permanent_delete', false ), false );
	}

	/**
	 * Clears runtime cache (useful for tests).
	 */
	public static function clear_cache(): void {
		self::$cache = null;
	}
}
