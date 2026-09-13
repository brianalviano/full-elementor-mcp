<?php
/**
 * Cryptographic Subsystem for Encrypted Checkpoints.
 *
 * Provides authenticated encryption at rest (AEAD) for historical checkpoint
 * snapshots using libsodium (XChaCha20-Poly1305-IETF) or OpenSSL (AES-256-GCM).
 * Binds ciphertext cryptographically to immutable checkpoint metadata via AAD,
 * supports key versioning and rotation, and enforces deterministic state hashing.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all cryptographic operations for checkpoint snapshots.
 */
final class Full_Elementor_MCP_Checkpoint_Crypto {

	/**
	 * Supported AEAD algorithms.
	 */
	public const ALGO_XCHACHA20_POLY1305 = 'XChaCha20-Poly1305-IETF';
	public const ALGO_AES_256_GCM         = 'AES-256-GCM';

	/**
	 * Current crypto envelope and payload schema versions.
	 */
	public const CURRENT_ENVELOPE_VERSION       = 2;
	public const CURRENT_PAYLOAD_SCHEMA_VERSION = 2;

	/**
	 * Hard maximum for checkpoint plaintext payload (16 MiB).
	 */
	public const MAX_PAYLOAD_BYTES = 16777216; // 16 * 1024 * 1024.

	/**
	 * Registered key material for key rotation and historical decryption.
	 *
	 * Map: key_id => array{ key_id: string, version: int, raw_key: string, is_active: bool }
	 *
	 * @var array<string, array{key_id: string, version: int, raw_key: string, is_active: bool}>
	 */
	private static array $keyring = array();

	/**
	 * Test override for active algorithm.
	 */
	private static ?string $forced_algorithm = null;

	/**
	 * Registers a key in the historical key provider.
	 *
	 * @param string $key_id    Unique key identifier (e.g. 'key_2026_01' or 'wp_salt_v1').
	 * @param int    $version   Key version integer.
	 * @param string $raw_key   Raw 32-byte binary key material.
	 * @param bool   $is_active Whether this key should be used for new encryptions.
	 */
	public static function register_key( string $key_id, int $version, string $raw_key, bool $is_active = false ): void {
		if ( 32 !== strlen( $raw_key ) ) {
			throw new \InvalidArgumentException( 'Checkpoint key must be exactly 32 bytes.' );
		}

		if ( $is_active ) {
			foreach ( self::$keyring as $kid => $entry ) {
				self::$keyring[ $kid ]['is_active'] = false;
			}
		}

		self::$keyring[ $key_id ] = array(
			'key_id'    => $key_id,
			'version'   => $version,
			'raw_key'   => $raw_key,
			'is_active' => $is_active,
		);
	}

	/**
	 * Clears registered historical keys and algorithm overrides (test utility).
	 */
	public static function reset_keys(): void {
		self::$keyring          = array();
		self::$forced_algorithm = null;
	}

	/**
	 * Forces a specific algorithm for testing fallback paths.
	 *
	 * @param string|null $algo ALGO_XCHACHA20_POLY1305, ALGO_AES_256_GCM, or null to clear.
	 */
	public static function set_forced_algorithm( ?string $algo ): void {
		self::$forced_algorithm = $algo;
	}

	/**
	 * Checks if an active encryption key can be successfully resolved.
	 *
	 * @return bool True if active key is available.
	 */
	public static function has_active_key(): bool {
		$res = self::get_active_key();
		return ! is_wp_error( $res );
	}

	/**
	 * Resolves active encryption key from configuration constant, keyring, or WP salt fallback.
	 *
	 * @return array{key_id: string, version: int, raw_key: string}|\WP_Error
	 */
	public static function get_active_key(): array|\WP_Error {
		// 1. Explicitly configured production key constant:
		if ( defined( 'FULL_ELEMENTOR_MCP_CHECKPOINT_KEY' ) ) {
			$const_val = constant( 'FULL_ELEMENTOR_MCP_CHECKPOINT_KEY' );
			if ( ! is_string( $const_val ) || '' === trim( $const_val ) ) {
				return new \WP_Error(
					'checkpoint_key_invalid',
					__( 'Configured FULL_ELEMENTOR_MCP_CHECKPOINT_KEY is empty or invalid string.', 'full-elementor-mcp' )
				);
			}

			$raw_key = base64_decode( trim( $const_val ), true );
			if ( false === $raw_key || 32 !== strlen( $raw_key ) ) {
				return new \WP_Error(
					'checkpoint_key_invalid',
					__( 'Configured FULL_ELEMENTOR_MCP_CHECKPOINT_KEY must be a valid base64-encoded 32-byte key.', 'full-elementor-mcp' )
				);
			}

			$key_id = 'cfg_' . substr( hash( 'sha256', $raw_key ), 0, 16 );
			return array(
				'key_id'  => $key_id,
				'version' => 1,
				'raw_key' => $raw_key,
			);
		}

		// 2. Explicitly registered active key in keyring:
		foreach ( self::$keyring as $entry ) {
			if ( ! empty( $entry['is_active'] ) ) {
				return array(
					'key_id'  => $entry['key_id'],
					'version' => $entry['version'],
					'raw_key' => $entry['raw_key'],
				);
			}
		}

		// If keyring has keys but none marked active, use latest registered:
		if ( ! empty( self::$keyring ) ) {
			$last = end( self::$keyring );
			return array(
				'key_id'  => $last['key_id'],
				'version' => $last['version'],
				'raw_key' => $last['raw_key'],
			);
		}

		// 3. Fallback derived from WordPress secret salts via HKDF-SHA256:
		return self::derive_wordpress_salt_key();
	}

	/**
	 * Retrieves a historical key by key_id for decryption.
	 *
	 * @param string $key_id The key identifier stored in the checkpoint row.
	 * @return array{key_id: string, version: int, raw_key: string}|\WP_Error
	 */
	public static function get_key_by_id( string $key_id ): array|\WP_Error {
		// Check explicit keyring:
		if ( isset( self::$keyring[ $key_id ] ) ) {
			return array(
				'key_id'  => self::$keyring[ $key_id ]['key_id'],
				'version' => self::$keyring[ $key_id ]['version'],
				'raw_key' => self::$keyring[ $key_id ]['raw_key'],
			);
		}

		// Check configured production key:
		if ( defined( 'FULL_ELEMENTOR_MCP_CHECKPOINT_KEY' ) ) {
			$active = self::get_active_key();
			if ( ! is_wp_error( $active ) && $active['key_id'] === $key_id ) {
				return $active;
			}
		}

		// Check WP salt fallback key:
		$salt_key = self::derive_wordpress_salt_key();
		if ( ! is_wp_error( $salt_key ) ) {
			if ( $salt_key['key_id'] === $key_id ) {
				return $salt_key;
			}
			// Legacy checkpoint key compatibility:
			// If key_id is 'wp_salt_v1', allow attempting with current salt key material under key_id 'wp_salt_v1'
			if ( 'wp_salt_v1' === $key_id ) {
				return array(
					'key_id'  => 'wp_salt_v1',
					'version' => 1,
					'raw_key' => $salt_key['raw_key'],
				);
			}
		}

		// If key starts with wp_salt_ or equals wp_salt_v1, return explicit rotation error:
		if ( 'wp_salt_v1' === $key_id || str_starts_with( $key_id, 'wp_salt_' ) ) {
			return new \WP_Error(
				'checkpoint_key_rotated',
				sprintf(
					/* translators: %s: key ID */
					__( 'WordPress salts have rotated since checkpoint was created. Key "%s" is no longer available.', 'full-elementor-mcp' ),
					$key_id
				),
				array( 'key_id' => $key_id )
			);
		}

		return new \WP_Error(
			'checkpoint_key_unavailable',
			sprintf(
				/* translators: %s: key ID */
				__( 'Historical encryption key "%s" is unavailable for checkpoint decryption.', 'full-elementor-mcp' ),
				$key_id
			),
			array( 'key_id' => $key_id )
		);
	}

	/**
	 * Derives a 32-byte secret key from WordPress salts using HKDF-SHA256.
	 *
	 * @return array{key_id: string, version: int, raw_key: string}|\WP_Error
	 */
	public static function derive_wordpress_salt_key(): array|\WP_Error {
		$auth_key        = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		$secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '';
		$salt_material   = $auth_key . $secure_auth_key;

		if ( function_exists( 'wp_salt' ) ) {
			$salt_material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}

		if ( empty( $salt_material ) ) {
			return new \WP_Error(
				'checkpoint_key_unavailable',
				__( 'WordPress salt material is missing or unconfigured. Checkpoint encryption unavailable.', 'full-elementor-mcp' )
			);
		}

		$site_context = function_exists( 'get_site_url' ) ? get_site_url() : 'full-elementor-mcp';
		$raw_key      = hash_hkdf( 'sha256', $salt_material, 32, 'full-elementor-mcp-checkpoint-v1', $site_context );

		if ( false === $raw_key || 32 !== strlen( $raw_key ) ) {
			return new \WP_Error(
				'checkpoint_key_unavailable',
				__( 'Failed to derive checkpoint encryption key from WordPress salts.', 'full-elementor-mcp' )
			);
		}

		$fingerprint = substr( hash_hmac( 'sha256', 'salt_fingerprint', $raw_key ), 0, 16 );
		$key_id      = 'wp_salt_v1_' . $fingerprint;

		return array(
			'key_id'  => $key_id,
			'version' => 1,
			'raw_key' => $raw_key,
		);
	}

	/**
	 * Detects best available AEAD encryption algorithm.
	 *
	 * @return string|\WP_Error Algorithm identifier or WP_Error if none available.
	 */
	public static function resolve_algorithm(): string|\WP_Error {
		if ( null !== self::$forced_algorithm ) {
			return self::$forced_algorithm;
		}

		// 1. Preferred: libsodium XChaCha20-Poly1305-IETF:
		if ( function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
			return self::ALGO_XCHACHA20_POLY1305;
		}

		// 2. Fallback: OpenSSL AES-256-GCM:
		if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			return self::ALGO_AES_256_GCM;
		}

		return new \WP_Error(
			'checkpoint_crypto_unavailable',
			__( 'No secure AEAD cipher available. Both libsodium and OpenSSL AES-256-GCM are missing.', 'full-elementor-mcp' )
		);
	}

	/**
	 * Constructs deterministic canonical Additional Authenticated Data (AAD) string.
	 *
	 * Cryptographically binds ciphertext to the checkpoint UUID, resource key, schema version,
	 * algorithm, and key metadata to prevent row-swapping or metadata tampering.
	 *
	 * Envelope v1 (legacy envelope): binds uuid, algorithm, key_id, key_version, payload_schema_version, resource_key.
	 * Envelope v2 (current): also binds checkpoint_type and restore_capability.
	 *
	 * @param array<string, mixed> $meta             Required metadata fields.
	 * @param int                  $envelope_version Crypto envelope version (1 or 2).
	 * @return string Canonical JSON AAD string.
	 */
	public static function build_aad( array $meta, int $envelope_version = 2 ): string {
		if ( 1 === $envelope_version ) {
			$bound_fields = array(
				'checkpoint_uuid'        => (string) ( $meta['checkpoint_uuid'] ?? '' ),
				'encryption_algorithm'   => (string) ( $meta['encryption_algorithm'] ?? '' ),
				'key_id'                 => (string) ( $meta['key_id'] ?? '' ),
				'key_version'            => (int) ( $meta['key_version'] ?? 1 ),
				'payload_schema_version' => (int) ( $meta['payload_schema_version'] ?? 1 ),
				'resource_key'           => (string) ( $meta['resource_key'] ?? '' ),
			);
		} elseif ( 2 === $envelope_version ) {
			$bound_fields = array(
				'checkpoint_type'        => (string) ( $meta['checkpoint_type'] ?? 'automatic' ),
				'checkpoint_uuid'        => (string) ( $meta['checkpoint_uuid'] ?? '' ),
				'encryption_algorithm'   => (string) ( $meta['encryption_algorithm'] ?? '' ),
				'key_id'                 => (string) ( $meta['key_id'] ?? '' ),
				'key_version'            => (int) ( $meta['key_version'] ?? 1 ),
				'payload_schema_version' => (int) ( $meta['payload_schema_version'] ?? 1 ),
				'resource_key'           => (string) ( $meta['resource_key'] ?? '' ),
				'restore_capability'     => (string) ( $meta['restore_capability'] ?? 'exact' ),
			);
		} else {
			return '';
		}

		ksort( $bound_fields );
		return (string) wp_json_encode( $bound_fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Validates and serializes state array to canonical JSON string.
	 *
	 * Strictly rejects PHP objects, closures, resources, and unencodable values.
	 *
	 * @param array<string, mixed> $state Plaintext resource state map.
	 * @return string|\WP_Error Canonical JSON string or WP_Error.
	 */
	public static function serialize_state( array $state ): string|\WP_Error {
		// Verify no objects, resources, or closures exist in the state hierarchy:
		$walk_err = self::assert_json_safe_types( $state );
		if ( is_wp_error( $walk_err ) ) {
			return $walk_err;
		}

		$json = wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new \WP_Error(
				'checkpoint_serialization_failed',
				__( 'Failed to JSON encode checkpoint state.', 'full-elementor-mcp' )
			);
		}

		return $json;
	}

	/**
	 * Computes canonical SHA-256 state hash of checkpoint state.
	 *
	 * Deterministically sorts associative array keys recursively before hashing.
	 *
	 * @param array<string, mixed> $state Plaintext state map.
	 * @return string|\WP_Error 64-hex SHA-256 hash or WP_Error.
	 */
	public static function hash_state( array $state ): string|\WP_Error {
		$walk_err = self::assert_json_safe_types( $state );
		if ( is_wp_error( $walk_err ) ) {
			return $walk_err;
		}

		$canonical = self::canonicalize_data( $state );
		$json      = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new \WP_Error(
				'checkpoint_serialization_failed',
				__( 'Failed to JSON encode canonical checkpoint state for hashing.', 'full-elementor-mcp' )
			);
		}

		return hash( 'sha256', $json );
	}

	/**
	 * Deserializes JSON string back into state array.
	 *
	 * @param string $json Canonical JSON string.
	 * @return array<string, mixed>|\WP_Error Decoded array or WP_Error.
	 */
	public static function deserialize_state( string $json ): array|\WP_Error {
		try {
			$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'checkpoint_serialization_failed',
				__( 'Failed to decode checkpoint state JSON.', 'full-elementor-mcp' ),
				array( 'error' => $e->getMessage() )
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'checkpoint_serialization_failed',
				__( 'Checkpoint state JSON must decode to an array.', 'full-elementor-mcp' )
			);
		}

		return $data;
	}

	/**
	 * Encrypts resource state using authenticated encryption (AEAD) and binds to metadata.
	 *
	 * @param array<string, mixed> $state    Plaintext state to encrypt.
	 * @param array<string, mixed> $metadata Metadata fields (checkpoint_uuid, resource_key, payload_schema_version).
	 * @return array<string, mixed>|\WP_Error Encryption envelope or WP_Error.
	 */
	public static function encrypt( array $state, array $metadata ): array|\WP_Error {
		// 1. Serialize and validate state:
		$json = self::serialize_state( $state );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		// 2. Bound payload size (16 MiB max):
		$size_bytes = strlen( $json );
		if ( $size_bytes > self::MAX_PAYLOAD_BYTES ) {
			return new \WP_Error(
				'checkpoint_too_large',
				sprintf(
					/* translators: 1: size, 2: max */
					__( 'Checkpoint payload size (%1$d bytes) exceeds maximum permitted bound (%2$d bytes).', 'full-elementor-mcp' ),
					$size_bytes,
					self::MAX_PAYLOAD_BYTES
				),
				array(
					'size_bytes' => $size_bytes,
					'max_bytes'  => self::MAX_PAYLOAD_BYTES,
				)
			);
		}

		// 3. Compute plaintext state hash:
		$state_hash = self::hash_state( $state );
		if ( is_wp_error( $state_hash ) ) {
			return $state_hash;
		}

		// 4. Resolve active encryption key:
		$key_info = self::get_active_key();
		if ( is_wp_error( $key_info ) ) {
			return $key_info;
		}

		// 5. Resolve algorithm:
		$algo = self::resolve_algorithm();
		if ( is_wp_error( $algo ) ) {
			return $algo;
		}

		// 6. Build immutable AAD:
		$envelope_version = (int) ( $metadata['crypto_envelope_version'] ?? self::CURRENT_ENVELOPE_VERSION );
		$schema_version   = (int) ( $metadata['payload_schema_version'] ?? self::CURRENT_PAYLOAD_SCHEMA_VERSION );
		$aad_meta         = array_merge( $metadata, array(
			'encryption_algorithm'    => $algo,
			'key_id'                  => $key_info['key_id'],
			'key_version'             => $key_info['version'],
			'crypto_envelope_version' => $envelope_version,
			'payload_schema_version'  => $schema_version,
		) );
		$aad = self::build_aad( $aad_meta, $envelope_version );

		// 7. Perform AEAD encryption with cryptographically random nonce:
		$ciphertext = '';
		$nonce      = '';
		$auth_tag   = null;

		if ( self::ALGO_XCHACHA20_POLY1305 === $algo ) {
			if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
				return new \WP_Error( 'checkpoint_crypto_unavailable', __( 'Sodium XChaCha20-Poly1305-IETF is not available.', 'full-elementor-mcp' ) );
			}
			$nonce      = random_bytes( 24 ); // SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
			$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
				$json,
				$aad,
				$nonce,
				$key_info['raw_key']
			);
		} elseif ( self::ALGO_AES_256_GCM === $algo ) {
			if ( ! function_exists( 'openssl_encrypt' ) ) {
				return new \WP_Error( 'checkpoint_crypto_unavailable', __( 'OpenSSL AES-256-GCM is not available.', 'full-elementor-mcp' ) );
			}
			$nonce      = random_bytes( 12 ); // Standard 96-bit GCM IV
			$raw_tag    = '';
			$ciphertext = openssl_encrypt(
				$json,
				'aes-256-gcm',
				$key_info['raw_key'],
				OPENSSL_RAW_DATA,
				$nonce,
				$raw_tag,
				$aad,
				16
			);

			if ( false === $ciphertext || '' === $ciphertext ) {
				return new \WP_Error( 'checkpoint_encrypt_failed', __( 'OpenSSL encryption failed.', 'full-elementor-mcp' ) );
			}
			$auth_tag = base64_encode( $raw_tag );
		} else {
			return new \WP_Error( 'checkpoint_crypto_unavailable', sprintf( __( 'Unsupported algorithm: %s', 'full-elementor-mcp' ), $algo ) );
		}

		return array(
			'encrypted_payload'       => base64_encode( $ciphertext ),
			'nonce'                   => base64_encode( $nonce ),
			'auth_tag'                => $auth_tag,
			'encryption_algorithm'    => $algo,
			'key_id'                  => $key_info['key_id'],
			'key_version'             => $key_info['version'],
			'state_hash'              => $state_hash,
			'size_bytes'              => $size_bytes,
			'crypto_envelope_version' => $envelope_version,
			'payload_schema_version'  => $schema_version,
		);
	}

	/**
	 * Decrypts and verifies an authenticated checkpoint record.
	 *
	 * Verifies AAD, authentication tag, payload size, JSON format, and state hash.
	 *
	 * @param array<string, mixed> $row Stored checkpoint row from database.
	 * @return array<string, mixed>|\WP_Error Decrypted plaintext state array or WP_Error.
	 */
	public static function decrypt( array $row ): array|\WP_Error {
		$key_id    = (string) ( $row['key_id'] ?? '' );
		$algo      = (string) ( $row['encryption_algorithm'] ?? '' );
		$b64_enc   = (string) ( $row['encrypted_payload'] ?? '' );
		$b64_nonce = (string) ( $row['nonce'] ?? '' );
		$b64_tag   = $row['auth_tag'] ?? null;
		$expected  = (string) ( $row['state_hash'] ?? '' );

		if ( empty( $b64_enc ) || empty( $b64_nonce ) ) {
			return new \WP_Error( 'checkpoint_integrity_failed', __( 'Checkpoint payload or nonce is missing.', 'full-elementor-mcp' ) );
		}

		// 0. Enforce supported crypto envelope version (v1 or v2):
		$envelope_version = isset( $row['crypto_envelope_version'] ) ? (int) $row['crypto_envelope_version'] : self::CURRENT_ENVELOPE_VERSION;
		if ( 0 === $envelope_version ) {
			$key_info = self::get_key_by_id( $key_id );
			if ( is_wp_error( $key_info ) ) {
				return new \WP_Error(
					'checkpoint_legacy_envelope_unresolved',
					__( 'Historical checkpoint crypto envelope format is unresolved and key material is unavailable for authenticated detection.', 'full-elementor-mcp' ),
					array( 'checkpoint_uuid' => $row['checkpoint_uuid'] ?? '' )
				);
			}
			$detected = self::detect_envelope_version( $row );
			if ( $detected > 0 ) {
				$envelope_version = $detected;
			} else {
				return new \WP_Error(
					'checkpoint_integrity_failed',
					__( 'Checkpoint envelope trial authentication failed for all supported envelope formats.', 'full-elementor-mcp' )
				);
			}
		}

		if ( 1 !== $envelope_version && 2 !== $envelope_version ) {
			return new \WP_Error(
				'checkpoint_envelope_unsupported',
				sprintf(
					/* translators: %d: envelope version */
					__( 'Unsupported checkpoint crypto envelope version: %d.', 'full-elementor-mcp' ),
					$envelope_version
				),
				array( 'crypto_envelope_version' => $envelope_version )
			);
		}

		// 0.1. Enforce supported payload schema version (v1 or v2):
		$schema_version = (int) ( $row['payload_schema_version'] ?? 1 );
		if ( 1 !== $schema_version && 2 !== $schema_version ) {
			return new \WP_Error(
				'checkpoint_schema_unsupported',
				sprintf(
					/* translators: %d: schema version */
					__( 'Unsupported checkpoint payload schema version: %d.', 'full-elementor-mcp' ),
					$schema_version
				),
				array( 'payload_schema_version' => $schema_version )
			);
		}

		// 1. Resolve decryption key:
		$key_info = self::get_key_by_id( $key_id );
		if ( is_wp_error( $key_info ) ) {
			return $key_info;
		}

		// Validate key version consistency:
		$expected_version = (int) ( $row['key_version'] ?? 1 );
		if ( (int) $key_info['version'] !== $expected_version ) {
			return new \WP_Error(
				'checkpoint_key_version_mismatch',
				sprintf(
					/* translators: 1: expected version, 2: key ID, 3: actual version */
					__( 'Key version mismatch: checkpoint requires version %1$d but key "%2$s" is version %3$d.', 'full-elementor-mcp' ),
					$expected_version,
					$key_id,
					$key_info['version']
				),
				array(
					'key_id'           => $key_id,
					'expected_version' => $expected_version,
					'actual_version'   => $key_info['version'],
				)
			);
		}

		// 2. Decode binary ciphertext and nonce:
		$ciphertext = base64_decode( $b64_enc, true );
		$nonce      = base64_decode( $b64_nonce, true );

		if ( false === $ciphertext || false === $nonce ) {
			return new \WP_Error( 'checkpoint_integrity_failed', __( 'Corrupted base64 encoding in checkpoint payload or nonce.', 'full-elementor-mcp' ) );
		}

		// 3. Build expected AAD from row metadata according to envelope version:
		$aad = self::build_aad( array(
			'checkpoint_type'        => $row['checkpoint_type'] ?? 'automatic',
			'checkpoint_uuid'        => $row['checkpoint_uuid'] ?? '',
			'encryption_algorithm'   => $algo,
			'key_id'                 => $key_id,
			'key_version'            => (int) ( $row['key_version'] ?? 1 ),
			'payload_schema_version' => $schema_version,
			'resource_key'           => $row['resource_key'] ?? '',
			'restore_capability'     => $row['restore_capability'] ?? 'exact',
		), $envelope_version );

		// 4. Perform authenticated decryption:
		$plaintext = false;

		if ( self::ALGO_XCHACHA20_POLY1305 === $algo ) {
			if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
				return new \WP_Error( 'checkpoint_crypto_unavailable', __( 'Sodium XChaCha20-Poly1305-IETF is not available for decryption.', 'full-elementor-mcp' ) );
			}
			try {
				$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
					$ciphertext,
					$aad,
					$nonce,
					$key_info['raw_key']
				);
			} catch ( \Throwable $e ) {
				if ( 'wp_salt_v1' === $key_id && ! isset( self::$keyring['wp_salt_v1'] ) ) {
					return new \WP_Error(
						'checkpoint_key_rotated',
						__( 'WordPress salts have rotated since legacy checkpoint was created. Key "wp_salt_v1" is no longer available.', 'full-elementor-mcp' ),
						array( 'key_id' => 'wp_salt_v1' )
					);
				}
				return new \WP_Error( 'checkpoint_integrity_failed', __( 'Checkpoint decryption authentication failed.', 'full-elementor-mcp' ) );
			}
		} elseif ( self::ALGO_AES_256_GCM === $algo ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return new \WP_Error( 'checkpoint_crypto_unavailable', __( 'OpenSSL AES-256-GCM is not available for decryption.', 'full-elementor-mcp' ) );
			}
			$tag = is_string( $b64_tag ) ? base64_decode( $b64_tag, true ) : '';
			if ( false === $tag || empty( $tag ) ) {
				return new \WP_Error( 'checkpoint_integrity_failed', __( 'Authentication tag is missing for AES-256-GCM checkpoint.', 'full-elementor-mcp' ) );
			}
			$plaintext = openssl_decrypt(
				$ciphertext,
				'aes-256-gcm',
				$key_info['raw_key'],
				OPENSSL_RAW_DATA,
				$nonce,
				$tag,
				$aad
			);
			if ( false === $plaintext ) {
				if ( 'wp_salt_v1' === $key_id && ! isset( self::$keyring['wp_salt_v1'] ) ) {
					return new \WP_Error(
						'checkpoint_key_rotated',
						__( 'WordPress salts have rotated since legacy checkpoint was created. Key "wp_salt_v1" is no longer available.', 'full-elementor-mcp' ),
						array( 'key_id' => 'wp_salt_v1' )
					);
				}
				return new \WP_Error( 'checkpoint_integrity_failed', __( 'Checkpoint integrity verification failed (ciphertext or AAD tampered).', 'full-elementor-mcp' ) );
			}
		} else {
			return new \WP_Error(
				'checkpoint_crypto_unavailable',
				sprintf(
					/* translators: %s: algorithm */
					__( 'Unsupported checkpoint encryption algorithm: %s', 'full-elementor-mcp' ),
					$algo
				)
			);
		}

		if ( false === $plaintext ) {
			if ( 'wp_salt_v1' === $key_id && ! isset( self::$keyring['wp_salt_v1'] ) ) {
				return new \WP_Error(
					'checkpoint_key_rotated',
					__( 'WordPress salts have rotated since legacy checkpoint was created. Key "wp_salt_v1" is no longer available.', 'full-elementor-mcp' ),
					array( 'key_id' => 'wp_salt_v1' )
				);
			}
			return new \WP_Error( 'checkpoint_integrity_failed', __( 'Checkpoint integrity verification failed (ciphertext or AAD tampered).', 'full-elementor-mcp' ) );
		}

		// 5. Enforce payload size limit:
		if ( strlen( $plaintext ) > self::MAX_PAYLOAD_BYTES ) {
			return new \WP_Error( 'checkpoint_too_large', __( 'Decrypted checkpoint payload exceeds maximum allowed size.', 'full-elementor-mcp' ) );
		}

		// 6. Deserialize state array:
		$decoded = self::deserialize_state( $plaintext );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		// 7. Verify plaintext state hash:
		$actual_hash = self::hash_state( $decoded );
		if ( is_wp_error( $actual_hash ) || ! hash_equals( $expected, $actual_hash ) ) {
			return new \WP_Error( 'checkpoint_integrity_failed', __( 'Decrypted checkpoint state hash mismatch.', 'full-elementor-mcp' ) );
		}

		return $decoded;
	}

	/**
	 * Asserts that a value hierarchy contains only JSON-safe scalar or array types.
	 *
	 * @param mixed $value The value to check.
	 * @return true|\WP_Error
	 */
	private static function assert_json_safe_types( mixed $value ) {
		if ( is_object( $value ) || is_resource( $value ) || $value instanceof \Closure ) {
			$type = gettype( $value );
			return new \WP_Error(
				'checkpoint_serialization_failed',
				sprintf(
					/* translators: %s: variable type */
					__( 'Cannot serialize checkpoint: illegal %s type encountered in state.', 'full-elementor-mcp' ),
					$type
				)
			);
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				$check = self::assert_json_safe_types( $v );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}
		}

		return true;
	}

	/**
	 * Recursively canonicalizes associative array keys while preserving sequential lists.
	 *
	 * @param mixed $data Data structure.
	 * @return mixed
	 */
	private static function canonicalize_data( mixed $data ): mixed {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$is_assoc = array_keys( $data ) !== range( 0, count( $data ) - 1 );
		if ( $is_assoc ) {
			ksort( $data );
		}

		foreach ( $data as $k => $v ) {
			$data[ $k ] = self::canonicalize_data( $v );
		}

		return $data;
	}

	/**
	 * Detects the crypto envelope version for a stored row using authenticated AEAD tag verification.
	 *
	 * Tests envelope v2 first (Format B / modern 8-field AAD), then envelope v1 (Format A 6-field AAD).
	 * Exactly one successful trial identifies the envelope format.
	 * Returns 0 if key is unavailable or authentication fails.
	 * Never exposes plaintext and never rewrites ciphertext.
	 *
	 * @param array<string, mixed> $row Checkpoint row from database.
	 * @return int 2, 1, or 0.
	 */
	public static function detect_envelope_version( array $row ): int {
		$key_id   = (string) ( $row['key_id'] ?? '' );
		$key_info = self::get_key_by_id( $key_id );
		if ( is_wp_error( $key_info ) ) {
			return 0;
		}

		$v2_valid = self::verify_envelope_tag( $row, 2, $key_info );
		$v1_valid = self::verify_envelope_tag( $row, 1, $key_info );

		if ( $v2_valid && ! $v1_valid ) {
			return 2;
		}
		if ( $v1_valid && ! $v2_valid ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Verifies AEAD authentication tag for a trial envelope version without exposing plaintext.
	 *
	 * @param array<string, mixed>   $row              Checkpoint row.
	 * @param int                    $envelope_version Envelope version to test (1 or 2).
	 * @param array{raw_key: string} $key_info         Resolved key info.
	 * @return bool True if AEAD authentication succeeds.
	 */
	private static function verify_envelope_tag( array $row, int $envelope_version, array $key_info ): bool {
		$algo      = (string) ( $row['encryption_algorithm'] ?? '' );
		$b64_enc   = (string) ( $row['encrypted_payload'] ?? '' );
		$b64_nonce = (string) ( $row['nonce'] ?? '' );
		$b64_tag   = $row['auth_tag'] ?? null;

		if ( empty( $b64_enc ) || empty( $b64_nonce ) ) {
			return false;
		}

		$ciphertext = base64_decode( $b64_enc, true );
		$nonce      = base64_decode( $b64_nonce, true );
		if ( false === $ciphertext || false === $nonce ) {
			return false;
		}

		$aad = self::build_aad( array(
			'checkpoint_type'        => $row['checkpoint_type'] ?? 'automatic',
			'checkpoint_uuid'        => $row['checkpoint_uuid'] ?? '',
			'encryption_algorithm'   => $algo,
			'key_id'                 => (string) ( $row['key_id'] ?? '' ),
			'key_version'            => (int) ( $row['key_version'] ?? 1 ),
			'payload_schema_version' => (int) ( $row['payload_schema_version'] ?? 1 ),
			'resource_key'           => $row['resource_key'] ?? '',
			'restore_capability'     => $row['restore_capability'] ?? 'exact',
		), $envelope_version );

		try {
			if ( self::ALGO_XCHACHA20_POLY1305 === $algo ) {
				if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
					return false;
				}
				$pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
					$ciphertext,
					$aad,
					$nonce,
					$key_info['raw_key']
				);
				return false !== $pt;
			} elseif ( self::ALGO_AES_256_GCM === $algo ) {
				if ( ! function_exists( 'openssl_decrypt' ) ) {
					return false;
				}
				$tag = is_string( $b64_tag ) ? base64_decode( $b64_tag, true ) : '';
				if ( false === $tag || empty( $tag ) ) {
					return false;
				}
				$pt = openssl_decrypt(
					$ciphertext,
					'aes-256-gcm',
					$key_info['raw_key'],
					OPENSSL_RAW_DATA,
					$nonce,
					$tag,
					$aad
				);
				return false !== $pt;
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		return false;
	}
}
