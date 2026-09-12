<?php
/**
 * Safety, Audit, and Undo MCP abilities for Full Elementor MCP.
 *
 * Exposes the safety capabilities (WAL journal inspection, checkpoint inspection & restoration,
 * audit log viewing, undo tools, and safety status) through the WordPress Abilities API
 * while strictly routing mutating tools through the central safety middleware.
 *
 * @package Full_Elementor_MCP
 * @since   1.9.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the safety and audit abilities.
 *
 * @since 1.9.0
 */
class Full_Elementor_MCP_Safety_Abilities {

	/**
	 * Dynamically built list of ability names registered by this class.
	 *
	 * @var string[]
	 */
	private array $ability_names = array();

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers all 10 safety abilities.
	 */
	public function register(): void {
		$this->register_safety_status();
		$this->register_list_changes();
		$this->register_get_change();
		$this->register_list_checkpoints();
		$this->register_get_checkpoint();
		$this->register_list_audit_events();
		$this->register_undo_change();
		$this->register_undo_last_change();
		$this->register_restore_checkpoint();
		$this->register_create_checkpoint();
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Admin-level permission check (manage_options).
	 *
	 * @param mixed $input
	 * @return bool
	 */
	public function check_admin_permission( mixed $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Editor-level permission check (edit_posts).
	 *
	 * @param mixed $input
	 * @return bool
	 */
	public function check_edit_permission( mixed $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	// -------------------------------------------------------------------------
	// 1. safety-status (Readonly)
	// -------------------------------------------------------------------------

	private function register_safety_status(): void {
		$name                  = 'full-elementor-mcp/safety-status';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Safety Status', 'full-elementor-mcp' ),
				'description'         => __( 'Returns the operational health and safety status of the Full Elementor MCP system, including database verification, active locks, unresolved recovery items, and keyring status.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'meta'                => array(
					'annotations' => array( 'readonly' => true ),
				),
				'execute_callback'    => array( $this, 'execute_safety_status' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
			)
		);
	}

	public function execute_safety_status( array $input = array() ): array {
		global $wpdb;

		$schema_verified = class_exists( 'Full_Elementor_MCP_Database_Installer' )
			&& Full_Elementor_MCP_Database_Installer::verify_schema();

		$db_version = class_exists( 'Full_Elementor_MCP_Database_Installer' )
			? Full_Elementor_MCP_Database_Installer::DB_VERSION
			: 'unknown';

		// Count table rows:
		$tables = array(
			'journal'     => Full_Elementor_MCP_Database_Installer::get_journal_table(),
			'checkpoints' => Full_Elementor_MCP_Database_Installer::get_checkpoints_table(),
			'audit_log'   => Full_Elementor_MCP_Database_Installer::get_audit_log_table(),
			'tokens'      => Full_Elementor_MCP_Database_Installer::get_tokens_table(),
		);

		$table_counts = array();
		foreach ( $tables as $key => $table_name ) {
			$cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$table_counts[ $key ] = $cnt;
		}

		// Active locks count:
		$tokens_tbl   = $tables['tokens'];
		$active_locks = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$tokens_tbl} WHERE token_type = 'lock' AND expires_at > UTC_TIMESTAMP() AND used = 0"
		);

		// Unresolved recovery count (pending WAL entries past lease or failed):
		$journal_tbl         = $tables['journal'];
		$unresolved_recovery = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$journal_tbl} WHERE status = %s OR error_message LIKE %s",
				'pending',
				'%manual_recovery_required%'
			)
		);

		// Recent failures in last 24h:
		$recent_failures = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$journal_tbl} WHERE status = %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)",
				'failed'
			)
		);

		// Keyring status:
		$keyring_active = class_exists( 'Full_Elementor_MCP_Checkpoint_Crypto' )
			&& Full_Elementor_MCP_Checkpoint_Crypto::has_active_key();

		// Overall health status:
		$status = 'healthy';
		if ( ! $schema_verified || $unresolved_recovery > 0 || ! $keyring_active ) {
			$status = 'action_required';
		} elseif ( $recent_failures > 5 || $active_locks > 10 ) {
			$status = 'degraded';
		}

		return array(
			'status'                    => $status,
			'db_version'                => $db_version,
			'schema_verified'           => $schema_verified,
			'keyring_active'            => $keyring_active,
			'active_locks'              => $active_locks,
			'unresolved_recovery_count' => $unresolved_recovery,
			'recent_failures_count'     => $recent_failures,
			'table_counts'              => $table_counts,
		);
	}

	// -------------------------------------------------------------------------
	// 2. list-changes (Readonly)
	// -------------------------------------------------------------------------

	private function register_list_changes(): void {
		$name                  = 'full-elementor-mcp/list-changes';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'List Changes', 'full-elementor-mcp' ),
				'description'         => __( 'Lists recorded Write-Ahead Journal entries with filtering and pagination. Omits raw before_state for performance and security.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'meta'                => array(
					'annotations' => array( 'readonly' => true ),
				),
				'execute_callback'    => array( $this, 'execute_list_changes' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'resource_filter' => array(
							'type'        => 'string',
							'description' => __( 'Optional canonical resource key filter (e.g. post:123).', 'full-elementor-mcp' ),
						),
						'status'          => array(
							'type'        => 'string',
							'description' => __( 'Optional status filter (pending, committed, rolled_back, failed).', 'full-elementor-mcp' ),
						),
						'since'           => array(
							'type'        => 'string',
							'description' => __( 'Optional ISO UTC timestamp lower bound.', 'full-elementor-mcp' ),
						),
						'limit'           => array(
							'type'        => 'integer',
							'description' => __( 'Maximum number of items to return (1-100, default 20).', 'full-elementor-mcp' ),
						),
						'offset'          => array(
							'type'        => 'integer',
							'description' => __( 'Offset for pagination (default 0).', 'full-elementor-mcp' ),
						),
					),
				),
			)
		);
	}

	public function execute_list_changes( array $input = array() ): array {
		if ( ! class_exists( 'Full_Elementor_MCP_Journal' ) ) {
			return array( 'changes' => array() );
		}

		if ( isset( $input['resource_filter'] ) && ! isset( $input['resource_key'] ) ) {
			$input['resource_key'] = $input['resource_filter'];
		}

		$entries = Full_Elementor_MCP_Journal::list_entries( $input );
		return array(
			'changes' => $entries,
			'count'   => count( $entries ),
			'limit'   => isset( $input['limit'] ) ? (int) $input['limit'] : 20,
			'offset'  => isset( $input['offset'] ) ? (int) $input['offset'] : 0,
		);
	}

	// -------------------------------------------------------------------------
	// 3. get-change (Readonly)
	// -------------------------------------------------------------------------

	private function register_get_change(): void {
		$name                  = 'full-elementor-mcp/get-change';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Get Change', 'full-elementor-mcp' ),
				'description'         => __( 'Retrieves detailed metadata for a single Write-Ahead Journal entry by its ID, with sensitive credentials safely redacted.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'meta'                => array(
					'annotations' => array( 'readonly' => true ),
				),
				'execute_callback'    => array( $this, 'execute_get_change' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'change_id'  => array(
							'type'        => 'integer',
							'description' => __( 'The journal entry ID.', 'full-elementor-mcp' ),
						),
						'journal_id' => array(
							'type'        => 'integer',
							'description' => __( 'Alias for change_id.', 'full-elementor-mcp' ),
						),
					),
				),
			)
		);
	}

	public function execute_get_change( array $input = array() ): array|\WP_Error {
		$id = absint( $input['change_id'] ?? ( $input['journal_id'] ?? ( $input['id'] ?? 0 ) ) );
		if ( $id <= 0 ) {
			return new \WP_Error( 'missing_change_id', __( 'change_id or journal_id is required.', 'full-elementor-mcp' ) );
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Journal' ) ) {
			return new \WP_Error( 'journal_unavailable', __( 'Journal unavailable.', 'full-elementor-mcp' ) );
		}

		$entry = Full_Elementor_MCP_Journal::get_entry( $id );
		if ( ! $entry ) {
			return new \WP_Error( 'change_not_found', __( 'Journal entry not found.', 'full-elementor-mcp' ) );
		}

		// Never expose raw before_state or arbitrary historical content:
		if ( ! empty( $entry['before_state'] ) && is_string( $entry['before_state'] ) ) {
			$raw = (string) $entry['before_state'];
			$entry['before_state_summary'] = array(
				'byte_length' => strlen( $raw ),
				'type'        => 'json',
				'hash'        => $entry['before_hash'] ?? null,
			);
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$entry['before_state_summary']['type']          = 'array';
				$entry['before_state_summary']['element_count'] = count( $decoded );
			}
		}
		unset( $entry['before_state'] );
		unset( $entry['before_state_sanitized'] );

		return $entry;
	}

	// -------------------------------------------------------------------------
	// 4. list-checkpoints (Readonly)
	// -------------------------------------------------------------------------

	private function register_list_checkpoints(): void {
		$name                  = 'full-elementor-mcp/list-checkpoints';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'List Checkpoints', 'full-elementor-mcp' ),
				'description'         => __( 'Lists checkpoint metadata rows without mass decrypting payloads. Annotates rows with historical profile classification.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'meta'                => array(
					'annotations' => array( 'readonly' => true ),
				),
				'execute_callback'    => array( $this, 'execute_list_checkpoints' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'resource_filter'    => array(
							'type'        => 'string',
							'description' => __( 'Optional canonical resource key filter.', 'full-elementor-mcp' ),
						),
						'checkpoint_type'    => array(
							'type'        => 'string',
							'description' => __( 'Optional checkpoint type filter (automatic, manual, recovery, pre_restore, pre_undo).', 'full-elementor-mcp' ),
						),
						'restore_capability' => array(
							'type'        => 'string',
							'description' => __( 'Optional restore capability filter (automatic, recovery_evidence_only, unsupported).', 'full-elementor-mcp' ),
						),
						'since'              => array(
							'type'        => 'string',
							'description' => __( 'Optional ISO UTC timestamp lower bound.', 'full-elementor-mcp' ),
						),
						'limit'              => array(
							'type'        => 'integer',
							'description' => __( 'Maximum items to return (1-100, default 20).', 'full-elementor-mcp' ),
						),
						'offset'             => array(
							'type'        => 'integer',
							'description' => __( 'Pagination offset (default 0).', 'full-elementor-mcp' ),
						),
					),
				),
			)
		);
	}

	public function execute_list_checkpoints( array $input = array() ): array {
		if ( ! class_exists( 'Full_Elementor_MCP_Checkpoint_Manager' ) ) {
			return array( 'checkpoints' => array() );
		}

		if ( isset( $input['resource_filter'] ) && ! isset( $input['resource_key'] ) ) {
			$input['resource_key'] = $input['resource_filter'];
		}

		$checkpoints = Full_Elementor_MCP_Checkpoint_Manager::list_checkpoints( $input );
		return array(
			'checkpoints' => $checkpoints,
			'count'       => count( $checkpoints ),
			'limit'       => isset( $input['limit'] ) ? (int) $input['limit'] : 20,
			'offset'      => isset( $input['offset'] ) ? (int) $input['offset'] : 0,
		);
	}

	// -------------------------------------------------------------------------
	// 5. get-checkpoint (Readonly)
	// -------------------------------------------------------------------------

	private function register_get_checkpoint(): void {
		$name                  = 'full-elementor-mcp/get-checkpoint';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Get Checkpoint', 'full-elementor-mcp' ),
				'description'         => __( 'Inspects metadata, envelope verification status, and historical profile of a single checkpoint by UUID or ID.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'meta'                => array(
					'annotations' => array( 'readonly' => true ),
				),
				'execute_callback'    => array( $this, 'execute_get_checkpoint' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'checkpoint_uuid' => array(
							'type'        => 'string',
							'description' => __( 'The checkpoint UUID.', 'full-elementor-mcp' ),
						),
						'checkpoint_id'   => array(
							'type'        => 'integer',
							'description' => __( 'The numeric checkpoint row ID.', 'full-elementor-mcp' ),
						),
					),
				),
			)
		);
	}

	public function execute_get_checkpoint( array $input = array() ): array|\WP_Error {
		$id_or_uuid = $input['checkpoint_uuid'] ?? ( $input['checkpoint_id'] ?? ( $input['id'] ?? '' ) );
		if ( empty( $id_or_uuid ) ) {
			return new \WP_Error( 'missing_checkpoint_id', __( 'checkpoint_uuid or checkpoint_id is required.', 'full-elementor-mcp' ) );
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Checkpoint_Manager' ) ) {
			return new \WP_Error( 'checkpoint_unavailable', __( 'Checkpoint manager unavailable.', 'full-elementor-mcp' ) );
		}

		$row = Full_Elementor_MCP_Checkpoint_Manager::get_checkpoint( $id_or_uuid );
		if ( ! $row ) {
			return new \WP_Error( 'checkpoint_not_found', __( 'Checkpoint not found.', 'full-elementor-mcp' ) );
		}

		$profile = Full_Elementor_MCP_Checkpoint_Manager::classify_historical_profile( $row );

		// Omit raw ciphertext, nonce, and auth tag for safe inspection:
		unset( $row['encrypted_payload'], $row['nonce'], $row['auth_tag'] );
		$row['historical_profile'] = $profile;

		return $row;
	}

	// -------------------------------------------------------------------------
	// 6. list-audit-events (Readonly)
	// -------------------------------------------------------------------------

	private function register_list_audit_events(): void {
		$name                  = 'full-elementor-mcp/list-audit-events';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'List Audit Events', 'full-elementor-mcp' ),
				'description'         => __( 'Searches append-only forensic audit log events with filtering and pagination.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'meta'                => array(
					'annotations' => array( 'readonly' => true ),
				),
				'execute_callback'    => array( $this, 'execute_list_audit_events' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_type'      => array(
							'type'        => 'string',
							'description' => __( 'Optional event type filter.', 'full-elementor-mcp' ),
						),
						'severity'        => array(
							'type'        => 'string',
							'description' => __( 'Optional severity filter (debug, info, notice, warning, error, critical).', 'full-elementor-mcp' ),
						),
						'request_uuid'    => array(
							'type'        => 'string',
							'description' => __( 'Optional request UUID correlation filter.', 'full-elementor-mcp' ),
						),
						'resource_filter' => array(
							'type'        => 'string',
							'description' => __( 'Optional resource key filter.', 'full-elementor-mcp' ),
						),
						'since'           => array(
							'type'        => 'string',
							'description' => __( 'Optional ISO UTC timestamp lower bound.', 'full-elementor-mcp' ),
						),
						'limit'           => array(
							'type'        => 'integer',
							'description' => __( 'Maximum items to return (1-200, default 50).', 'full-elementor-mcp' ),
						),
						'offset'          => array(
							'type'        => 'integer',
							'description' => __( 'Pagination offset (default 0).', 'full-elementor-mcp' ),
						),
					),
				),
			)
		);
	}

	public function execute_list_audit_events( array $input = array() ): array {
		if ( ! class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
			return array( 'events' => array() );
		}

		if ( isset( $input['resource_filter'] ) && ! isset( $input['resource_key'] ) ) {
			$input['resource_key'] = $input['resource_filter'];
		}

		$events = Full_Elementor_MCP_Audit_Logger::query( $input );
		return array(
			'events' => $events,
			'count'  => count( $events ),
			'limit'  => isset( $input['limit'] ) ? (int) $input['limit'] : 50,
			'offset' => isset( $input['offset'] ) ? (int) $input['offset'] : 0,
		);
	}

	// -------------------------------------------------------------------------
	// 7. undo-change (Mutating - Managed Safety Action)
	// -------------------------------------------------------------------------

	private function register_undo_change(): void {
		$name                  = 'full-elementor-mcp/undo-change';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Undo Change', 'full-elementor-mcp' ),
				'description'         => __( 'Reverts a specific committed Write-Ahead Journal change back to its before_state, creating a durable pre_undo checkpoint and checking for state conflicts.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'meta'                => array(
					'annotations' => array( 'readonly' => false ),
				),
				'execute_callback'    => array( 'Full_Elementor_MCP_Undo_Manager', 'execute_undo_change_ability' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'change_id'          => array(
							'type'        => 'integer',
							'description' => __( 'The journal entry ID to undo.', 'full-elementor-mcp' ),
						),
						'journal_id'         => array(
							'type'        => 'integer',
							'description' => __( 'Alias for change_id.', 'full-elementor-mcp' ),
						),
						'target_resource'    => array(
							'type'        => 'string',
							'description' => __( 'Optional expected target resource key for verification.', 'full-elementor-mcp' ),
						),
						'dry_run'            => array(
							'type'        => 'boolean',
							'description' => __( 'When true, returns preview of undo without making persistent changes.', 'full-elementor-mcp' ),
						),
						'confirmation_token' => array(
							'type'        => 'string',
							'description' => __( 'Confirmation challenge token for execution.', 'full-elementor-mcp' ),
						),
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// 8. undo-last-change (Mutating - Managed Safety Action)
	// -------------------------------------------------------------------------

	private function register_undo_last_change(): void {
		$name                  = 'full-elementor-mcp/undo-last-change';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Undo Last Change', 'full-elementor-mcp' ),
				'description'         => __( 'Reverts the most recent change on a resource if safe. Fails closed with undo_latest_not_safe if the newest change is not rollbackable.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'meta'                => array(
					'annotations' => array( 'readonly' => false ),
				),
				'execute_callback'    => array( 'Full_Elementor_MCP_Undo_Manager', 'execute_undo_last_change_ability' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'target_resource'     => array(
							'type'        => 'string',
							'description' => __( 'The canonical target resource (e.g. post:123 or global:elementor-kit-state).', 'full-elementor-mcp' ),
						),
						'target_resource_key' => array(
							'type'        => 'string',
							'description' => __( 'Alias for target_resource.', 'full-elementor-mcp' ),
						),
						'post_id'             => array(
							'type'        => 'integer',
							'description' => __( 'The post ID (alternative to target_resource).', 'full-elementor-mcp' ),
						),
						'dry_run'             => array(
							'type'        => 'boolean',
							'description' => __( 'When true, returns preview of undo without persistent modifications.', 'full-elementor-mcp' ),
						),
						'confirmation_token'  => array(
							'type'        => 'string',
							'description' => __( 'Confirmation challenge token for execution.', 'full-elementor-mcp' ),
						),
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// 9. restore-checkpoint (Mutating - Managed Safety Action)
	// -------------------------------------------------------------------------

	private function register_restore_checkpoint(): void {
		$name                  = 'full-elementor-mcp/restore-checkpoint';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Restore Checkpoint', 'full-elementor-mcp' ),
				'description'         => __( 'Restores an encrypted checkpoint, capturing a durable pre_restore checkpoint before any persistent writes.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'meta'                => array(
					'annotations' => array( 'readonly' => false ),
				),
				'execute_callback'    => array( 'Full_Elementor_MCP_Checkpoint_Manager', 'execute_restore_ability' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'checkpoint_uuid'     => array(
							'type'        => 'string',
							'description' => __( 'The UUID of the checkpoint to restore.', 'full-elementor-mcp' ),
						),
						'target_resource'     => array(
							'type'        => 'string',
							'description' => __( 'Optional expected target resource key for verification.', 'full-elementor-mcp' ),
						),
						'target_resource_key' => array(
							'type'        => 'string',
							'description' => __( 'Alias for target_resource.', 'full-elementor-mcp' ),
						),
						'dry_run'             => array(
							'type'        => 'boolean',
							'description' => __( 'When true, analyzes restore plan without modifying persistent state.', 'full-elementor-mcp' ),
						),
						'confirmation_token'  => array(
							'type'        => 'string',
							'description' => __( 'Confirmation challenge token for execution.', 'full-elementor-mcp' ),
						),
					),
					'required'   => array( 'checkpoint_uuid' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// 10. create-checkpoint (Mutating - Managed Safety Action)
	// -------------------------------------------------------------------------

	private function register_create_checkpoint(): void {
		$name                  = 'full-elementor-mcp/create-checkpoint';
		$this->ability_names[] = $name;

		full_elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Create Checkpoint', 'full-elementor-mcp' ),
				'description'         => __( 'Captures and encrypts an immutable point-in-time manual checkpoint of a resource.', 'full-elementor-mcp' ),
				'category'            => 'full-elementor-mcp',
				'meta'                => array(
					'annotations' => array( 'readonly' => false ),
				),
				'execute_callback'    => static function ( array $input ) {
					$strategy = Full_Elementor_MCP_Mutation_Registry::get( 'full-elementor-mcp/create-checkpoint' );
					$delegate = $strategy['managed_delegate'] ?? null;
					if ( is_callable( $delegate ) ) {
						return call_user_func( $delegate, $input );
					}
					return new \WP_Error( 'delegate_missing', __( 'Create checkpoint delegate missing.', 'full-elementor-mcp' ) );
				},
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'target_resource'     => array(
							'type'        => 'string',
							'description' => __( 'The canonical resource key (e.g. post:123 or global:elementor-kit-state).', 'full-elementor-mcp' ),
						),
						'target_resource_key' => array(
							'type'        => 'string',
							'description' => __( 'Alias for target_resource.', 'full-elementor-mcp' ),
						),
						'post_id'             => array(
							'type'        => 'integer',
							'description' => __( 'The post ID to checkpoint.', 'full-elementor-mcp' ),
						),
						'label'               => array(
							'type'        => 'string',
							'description' => __( 'Human-readable label for this checkpoint.', 'full-elementor-mcp' ),
						),
					),
				),
			)
		);
	}
}
