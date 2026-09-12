<?php
/**
 * Admin Safety Console for Safe Elementor MCP.
 *
 * Provides a classic WordPress Admin Control Plane for inspecting WAL journal
 * changes, encrypted checkpoints, forensic audit logs, and triggering safe recovery/undo operations.
 *
 * Security Invariants:
 * - Strictly gates access on manage_options capability.
 * - Zero GET mutations. All state-modifying actions require POST + valid CSRF nonces.
 * - Contextual output escaping on every output field.
 *
 * @package Full_Elementor_MCP
 * @since   1.9.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safety Admin Orchestrator.
 *
 * @since 1.9.0
 */
class Full_Elementor_MCP_Safety_Admin {

	/**
	 * Menu slug for safety submenu.
	 */
	const MENU_SLUG = 'full-elementor-mcp-safety';

	/**
	 * Nonce action for safety POST actions.
	 */
	const NONCE_ACTION = 'full_elementor_mcp_safety_action';

	/**
	 * Notices to display on current screen.
	 *
	 * @var array<int, array{type: string, message: string}>
	 */
	private static array $notices = array();

	/**
	 * Pending confirmation challenge to render.
	 *
	 * @var array{action: string, title: string, description: string, fields: array<string, string>, confirmation_token: string, expires_at: string}|null
	 */
	private static ?array $pending_confirmation = null;

	/**
	 * Initializes hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post_actions' ) );
	}

	/**
	 * Registers submenu under Settings.
	 */
	public static function register_menu(): void {
		add_options_page(
			__( 'Safe Elementor MCP Safety', 'full-elementor-mcp' ),
			__( 'EMCP Safety', 'full-elementor-mcp' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Handles POST actions securely with CSRF verification and capability check.
	 */
	public static function handle_post_actions(): void {
		if ( ! is_admin() || ! isset( $_POST['safety_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'full-elementor-mcp' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'safety_nonce' );

		$action = sanitize_key( (string) $_POST['safety_action'] );

		switch ( $action ) {
			case 'recover_pending':
				self::do_recover_pending();
				break;

			case 'create_checkpoint':
				self::do_create_checkpoint();
				break;

			case 'restore_checkpoint':
				self::do_restore_checkpoint();
				break;

			case 'undo_change':
				self::do_undo_change();
				break;

			case 'prune_audit':
				self::do_prune_audit();
				break;
		}
	}

	/**
	 * Triggers conservative recovery of pending journals.
	 */
	private static function do_recover_pending(): void {
		if ( ! class_exists( 'Full_Elementor_MCP_Journal' ) ) {
			self::add_notice( 'error', __( 'Journal subsystem unavailable.', 'full-elementor-mcp' ) );
			return;
		}

		$results = Full_Elementor_MCP_Journal::recover_pending(
			Full_Elementor_MCP_Journal::RECOVERY_GRACE_PERIOD_SECONDS,
			array(
				'ability' => 'admin/recover_pending',
				'user_id' => get_current_user_id(),
			)
		);
		if ( empty( $results ) ) {
			self::add_notice( 'success', __( 'Recovery scan complete: zero pending or abandoned journals required recovery.', 'full-elementor-mcp' ) );
		} else {
			$recovered = count( $results );
			self::add_notice( 'info', sprintf(
				/* translators: %d: count */
				__( 'Recovery scan processed %d journal entry(ies).', 'full-elementor-mcp' ),
				$recovered
			) );
		}
	}

	/**
	 * Captures and saves a manual checkpoint under canonical resource lock.
	 */
	private static function do_create_checkpoint(): void {
		if ( class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) && Full_Elementor_MCP_Mutation_Middleware::is_ability_disabled( 'full-elementor-mcp/create-checkpoint' ) ) {
			self::add_notice( 'error', __( 'Create checkpoint is currently disabled by administrator safety policy.', 'full-elementor-mcp' ) );
			return;
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Checkpoint_Manager' ) ) {
			self::add_notice( 'error', __( 'Checkpoint subsystem unavailable.', 'full-elementor-mcp' ) );
			return;
		}

		$resource_key = sanitize_text_field( (string) ( $_POST['resource_key'] ?? ( $_POST['target_resource'] ?? '' ) ) );
		$label        = sanitize_text_field( (string) ( $_POST['label'] ?? '' ) );

		if ( '' === $resource_key ) {
			self::add_notice( 'error', __( 'Resource key is required to capture a checkpoint.', 'full-elementor-mcp' ) );
			return;
		}

		$res = Full_Elementor_MCP_Checkpoint_Manager::create_manual_checkpoint(
			$resource_key,
			array(
				'label'   => ! empty( $label ) ? $label : 'Manual Admin Checkpoint',
				'user_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $res ) ) {
			self::add_notice( 'error', sprintf(
				/* translators: %s: error message */
				__( 'Failed to create checkpoint: %s', 'full-elementor-mcp' ),
				$res->get_error_message()
			) );
		} else {
			self::add_notice( 'success', sprintf(
				/* translators: %s: checkpoint UUID */
				__( 'Checkpoint created successfully under canonical lock! UUID: %s', 'full-elementor-mcp' ),
				$res['checkpoint_uuid']
			) );
		}
	}

	/**
	 * Executes a checkpoint restore through the shared safety gate with two-step confirmation.
	 */
	private static function do_restore_checkpoint(): void {
		if ( class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) && Full_Elementor_MCP_Mutation_Middleware::is_ability_disabled( 'full-elementor-mcp/restore-checkpoint' ) ) {
			self::add_notice( 'error', __( 'Checkpoint restore is currently disabled by administrator safety policy.', 'full-elementor-mcp' ) );
			return;
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) ) {
			self::add_notice( 'error', __( 'Safety middleware unavailable.', 'full-elementor-mcp' ) );
			return;
		}

		$uuid = sanitize_text_field( (string) ( $_POST['checkpoint_uuid'] ?? '' ) );
		if ( '' === $uuid ) {
			self::add_notice( 'error', __( 'Checkpoint UUID is required for restore.', 'full-elementor-mcp' ) );
			return;
		}

		$confirmation_token = sanitize_text_field( (string) ( $_POST['confirmation_token'] ?? '' ) );

		// Step 1: If no confirmation token provided, request challenge through safety middleware:
		if ( empty( $confirmation_token ) ) {
			$res = Full_Elementor_MCP_Mutation_Middleware::execute(
				'full-elementor-mcp/restore-checkpoint',
				array(
					'checkpoint_uuid' => $uuid,
				)
			);

			if ( is_wp_error( $res ) && 'confirmation_required' === $res->get_error_code() ) {
				$data  = $res->get_error_data();
				$token = is_array( $data ) && ! empty( $data['confirmation_token'] ) ? (string) $data['confirmation_token'] : '';
				if ( ! empty( $token ) ) {
					self::$pending_confirmation = array(
						'action'             => 'restore_checkpoint',
						'title'              => __( 'Confirm Checkpoint Restore', 'full-elementor-mcp' ),
						'description'        => sprintf(
							/* translators: %s: checkpoint UUID */
							__( 'CRITICAL: You are about to restore checkpoint %s. This will overwrite the live state of the target resource with historical data. A pre-restore safety checkpoint will be durably captured before any changes are made.', 'full-elementor-mcp' ),
							$uuid
						),
						'fields'             => array(
							'checkpoint_uuid' => $uuid,
						),
						'confirmation_token' => $token,
						'expires_at'         => is_array( $data ) && ! empty( $data['expires_at'] ) ? (string) $data['expires_at'] : '',
					);
					return;
				}
			}

			if ( is_wp_error( $res ) ) {
				self::add_notice( 'error', sprintf(
					/* translators: %s: error message */
					__( 'Checkpoint restore preparation failed: %s', 'full-elementor-mcp' ),
					$res->get_error_message()
				) );
				return;
			}
		}

		// Step 2: Execute with confirmation token through the shared safety gate:
		$res = Full_Elementor_MCP_Mutation_Middleware::execute(
			'full-elementor-mcp/restore-checkpoint',
			array(
				'checkpoint_uuid'    => $uuid,
				'confirmation_token' => $confirmation_token,
			)
		);

		if ( is_wp_error( $res ) ) {
			self::add_notice( 'error', sprintf(
				/* translators: %s: error message */
				__( 'Checkpoint restore failed: %s', 'full-elementor-mcp' ),
				$res->get_error_message()
			) );
		} else {
			self::add_notice( 'success', sprintf(
				/* translators: %s: checkpoint UUID */
				__( 'Checkpoint %s restored successfully! Pre-restore safety snapshot was captured and verified.', 'full-elementor-mcp' ),
				$uuid
			) );
		}
	}

	/**
	 * Executes an undo of a journaled mutation through the shared safety gate with two-step confirmation.
	 */
	private static function do_undo_change(): void {
		if ( class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) && Full_Elementor_MCP_Mutation_Middleware::is_ability_disabled( 'full-elementor-mcp/undo-change' ) ) {
			self::add_notice( 'error', __( 'Undo change is currently disabled by administrator safety policy.', 'full-elementor-mcp' ) );
			return;
		}

		if ( ! class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) ) {
			self::add_notice( 'error', __( 'Safety middleware unavailable.', 'full-elementor-mcp' ) );
			return;
		}

		$journal_id = absint( $_POST['journal_id'] ?? 0 );
		if ( $journal_id <= 0 ) {
			self::add_notice( 'error', __( 'Valid Change / Journal ID is required for undo.', 'full-elementor-mcp' ) );
			return;
		}

		$confirmation_token = sanitize_text_field( (string) ( $_POST['confirmation_token'] ?? '' ) );

		// Step 1: Request challenge via shared safety gate:
		if ( empty( $confirmation_token ) ) {
			$res = Full_Elementor_MCP_Mutation_Middleware::execute(
				'full-elementor-mcp/undo-change',
				array(
					'change_id' => $journal_id,
				)
			);

			if ( is_wp_error( $res ) && 'confirmation_required' === $res->get_error_code() ) {
				$data  = $res->get_error_data();
				$token = is_array( $data ) && ! empty( $data['confirmation_token'] ) ? (string) $data['confirmation_token'] : '';
				if ( ! empty( $token ) ) {
					self::$pending_confirmation = array(
						'action'             => 'undo_change',
						'title'              => __( 'Confirm Change Reversion (Undo)', 'full-elementor-mcp' ),
						'description'        => sprintf(
							/* translators: %d: journal entry ID */
							__( 'CRITICAL: You are about to revert Write-Ahead Journal change #%d. This will rollback the target resource to its recorded before-state, asserting state hashes and recording a durable pre-undo checkpoint.', 'full-elementor-mcp' ),
							$journal_id
						),
						'fields'             => array(
							'journal_id' => (string) $journal_id,
						),
						'confirmation_token' => $token,
						'expires_at'         => is_array( $data ) && ! empty( $data['expires_at'] ) ? (string) $data['expires_at'] : '',
					);
					return;
				}
			}

			if ( is_wp_error( $res ) ) {
				self::add_notice( 'error', sprintf(
					/* translators: %s: error message */
					__( 'Undo preparation failed: %s', 'full-elementor-mcp' ),
					$res->get_error_message()
				) );
				return;
			}
		}

		// Step 2: Execute with confirmation token through the shared safety gate:
		$res = Full_Elementor_MCP_Mutation_Middleware::execute(
			'full-elementor-mcp/undo-change',
			array(
				'change_id'          => $journal_id,
				'confirmation_token' => $confirmation_token,
			)
		);

		if ( is_wp_error( $res ) ) {
			self::add_notice( 'error', sprintf(
				/* translators: %s: error message */
				__( 'Undo failed: %s', 'full-elementor-mcp' ),
				$res->get_error_message()
			) );
		} else {
			self::add_notice( 'success', sprintf(
				/* translators: %d: journal ID */
				__( 'Change #%d reverted successfully! A pre-undo checkpoint was durably recorded.', 'full-elementor-mcp' ),
				$journal_id
			) );
		}
	}

	/**
	 * Prunes audit log according to retention.
	 */
	private static function do_prune_audit(): void {
		if ( ! class_exists( 'Full_Elementor_MCP_Audit_Logger' ) ) {
			self::add_notice( 'error', __( 'Audit subsystem unavailable.', 'full-elementor-mcp' ) );
			return;
		}

		$days   = absint( $_POST['retention_days'] ?? 90 );
		$pruned = Full_Elementor_MCP_Audit_Logger::prune(
			$days > 0 ? $days : 90,
			100,
			array(
				'ability'      => 'admin/prune_audit',
				'user_id'      => get_current_user_id(),
				'log_if_empty' => true,
			)
		);
		self::add_notice( 'success', sprintf(
			/* translators: %d: count */
			__( 'Audit log pruned: %d old event(s) removed (critical safety recovery events preserved).', 'full-elementor-mcp' ),
			$pruned
		) );
	}

	/**
	 * Adds a transient notice.
	 */
	public static function add_notice( string $type, string $message ): void {
		self::$notices[] = array(
			'type'    => in_array( $type, array( 'error', 'warning', 'success', 'info' ), true ) ? $type : 'info',
			'message' => $message,
		);
	}

	/**
	 * Renders the Safety Admin Console.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'full-elementor-mcp' ) );
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$valid_tabs  = array( 'overview', 'changes', 'checkpoints', 'audit', 'recovery' );
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'overview';
		}

		?>
		<div class="wrap full-elementor-mcp-admin full-elementor-mcp-safety-admin">
			<h1><?php esc_html_e( 'Safe Elementor MCP — Safety & Recovery Console', 'full-elementor-mcp' ); ?></h1>

			<?php foreach ( self::$notices as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<?php if ( ! empty( self::$pending_confirmation ) ) : ?>
				<?php self::render_confirmation_dialog(); ?>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'overview' ), admin_url( 'options-general.php' ) ) ); ?>"
				   class="nav-tab <?php echo esc_attr( 'overview' === $current_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Overview', 'full-elementor-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'changes' ), admin_url( 'options-general.php' ) ) ); ?>"
				   class="nav-tab <?php echo esc_attr( 'changes' === $current_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Changes (WAL)', 'full-elementor-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'checkpoints' ), admin_url( 'options-general.php' ) ) ); ?>"
				   class="nav-tab <?php echo esc_attr( 'checkpoints' === $current_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Checkpoints', 'full-elementor-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'audit' ), admin_url( 'options-general.php' ) ) ); ?>"
				   class="nav-tab <?php echo esc_attr( 'audit' === $current_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Audit Log', 'full-elementor-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'recovery' ), admin_url( 'options-general.php' ) ) ); ?>"
				   class="nav-tab <?php echo esc_attr( 'recovery' === $current_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Recovery', 'full-elementor-mcp' ); ?>
				</a>
			</nav>

			<div class="tab-content" style="margin-top: 20px;">
				<?php
				switch ( $current_tab ) {
					case 'changes':
						self::render_tab_changes();
						break;
					case 'checkpoints':
						self::render_tab_checkpoints();
						break;
					case 'audit':
						self::render_tab_audit();
						break;
					case 'recovery':
						self::render_tab_recovery();
						break;
					case 'overview':
					default:
						self::render_tab_overview();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders confirmation warning dialog if challenge is active.
	 */
	private static function render_confirmation_dialog(): void {
		if ( empty( self::$pending_confirmation ) ) {
			return;
		}

		$conf = self::$pending_confirmation;
		?>
		<div class="notice notice-error" style="padding: 20px; border-left: 5px solid #d63638; background: #fff; margin: 20px 0 25px;">
			<h2 style="color: #d63638; margin-top: 0;">&#9888; <?php echo esc_html( $conf['title'] ); ?></h2>
			<p style="font-size: 14px; font-weight: 500;"><?php echo esc_html( $conf['description'] ); ?></p>
			<?php if ( ! empty( $conf['expires_at'] ) ) : ?>
				<p style="color: #666; font-size: 12px;">
					<?php
					printf(
						/* translators: %s: expiration time */
						esc_html__( 'Server challenge issued. Token expires at: %s UTC', 'full-elementor-mcp' ),
						esc_html( $conf['expires_at'] )
					);
					?>
				</p>
			<?php endif; ?>

			<form method="post" style="margin-top: 15px; display: flex; gap: 15px; align-items: center;">
				<?php wp_nonce_field( self::NONCE_ACTION, 'safety_nonce' ); ?>
				<input type="hidden" name="safety_action" value="<?php echo esc_attr( $conf['action'] ); ?>" />
				<input type="hidden" name="confirmation_token" value="<?php echo esc_attr( $conf['confirmation_token'] ); ?>" />
				<?php foreach ( $conf['fields'] as $name => $val ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $val ); ?>" />
				<?php endforeach; ?>

				<button type="submit" class="button button-primary" style="background: #d63638; border-color: #b32d2e; color: #fff; font-weight: bold;">
					<?php esc_html_e( 'Confirm & Execute Now', 'full-elementor-mcp' ); ?>
				</button>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'options-general.php' ) ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Cancel', 'full-elementor-mcp' ); ?>
				</a>
			</form>
		</div>
		<?php
	}

	/**
	 * Tab: Overview
	 */
	private static function render_tab_overview(): void {
		$safety_abilities = new Full_Elementor_MCP_Safety_Abilities();
		$status = $safety_abilities->execute_safety_status();

		$status_class = 'notice-success';
		if ( 'action_required' === $status['status'] ) {
			$status_class = 'notice-error';
		} elseif ( 'degraded' === $status['status'] ) {
			$status_class = 'notice-warning';
		}
		?>
		<div class="card" style="max-width: 100%; padding: 15px 20px; margin-bottom: 20px;">
			<h2><?php esc_html_e( 'Safety Subsystem Status', 'full-elementor-mcp' ); ?></h2>
			<div class="notice <?php echo esc_attr( $status_class ); ?> inline" style="margin: 10px 0 15px;">
				<p>
					<strong><?php esc_html_e( 'Current Health:', 'full-elementor-mcp' ); ?></strong>
					<code><?php echo esc_html( strtoupper( $status['status'] ) ); ?></code>
					&mdash;
					<?php esc_html_e( 'DB Version:', 'full-elementor-mcp' ); ?>
					<code><?php echo esc_html( $status['db_version'] ); ?></code>
				</p>
			</div>

			<table class="widefat striped" style="margin-top: 10px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Schema Verification', 'full-elementor-mcp' ); ?></strong></td>
						<td><?php echo $status['schema_verified'] ? '<span style="color:green;">&#10004; ' . esc_html__( 'Passed (4 tables strictly verified)', 'full-elementor-mcp' ) . '</span>' : '<span style="color:red;">&#10008; ' . esc_html__( 'Failed or Outdated', 'full-elementor-mcp' ) . '</span>'; ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'AEAD Keyring Status', 'full-elementor-mcp' ); ?></strong></td>
						<td><?php echo $status['keyring_active'] ? '<span style="color:green;">&#10004; ' . esc_html__( 'Active & Ready', 'full-elementor-mcp' ) . '</span>' : '<span style="color:red;">&#10008; ' . esc_html__( 'Inactive / Key Unavailable', 'full-elementor-mcp' ) . '</span>'; ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Active Resource Locks', 'full-elementor-mcp' ); ?></strong></td>
						<td><?php echo esc_html( (string) $status['active_locks'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Unresolved Recovery Items', 'full-elementor-mcp' ); ?></strong></td>
						<td><?php echo esc_html( (string) $status['unresolved_recovery_count'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Failures in Last 24 Hours', 'full-elementor-mcp' ); ?></strong></td>
						<td><?php echo esc_html( (string) $status['recent_failures_count'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3 style="margin-top: 25px;"><?php esc_html_e( 'Safety Tables Row Counts', 'full-elementor-mcp' ); ?></h3>
			<table class="widefat fixed" style="width: 400px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Table', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Row Count', 'full-elementor-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $status['table_counts'] as $tbl => $cnt ) : ?>
						<tr>
							<td><code><?php echo esc_html( $tbl ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( $cnt ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="margin-top: 25px;">
				<form method="post" style="display:inline-block;">
					<?php wp_nonce_field( self::NONCE_ACTION, 'safety_nonce' ); ?>
					<input type="hidden" name="safety_action" value="recover_pending" />
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Run Safe Recovery Scan', 'full-elementor-mcp' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab: Changes (WAL Journal)
	 */
	private static function render_tab_changes(): void {
		$status_filter = isset( $_GET['filter_status'] ) ? sanitize_key( (string) $_GET['filter_status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$resource_key  = isset( $_GET['resource_key'] ) ? sanitize_text_field( (string) $_GET['resource_key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$filters = array( 'limit' => 50 );
		if ( ! empty( $status_filter ) ) {
			$filters['status'] = $status_filter;
		}
		if ( ! empty( $resource_key ) ) {
			$filters['resource_key'] = $resource_key;
		}

		$entries = class_exists( 'Full_Elementor_MCP_Journal' )
			? Full_Elementor_MCP_Journal::list_entries( $filters )
			: array();
		?>
		<div class="card" style="max-width: 100%; padding: 15px 20px;">
			<h2><?php esc_html_e( 'Write-Ahead Journal (WAL) Changes', 'full-elementor-mcp' ); ?></h2>
			<form method="get" style="margin: 15px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<input type="hidden" name="tab" value="changes" />
				<label>
					<?php esc_html_e( 'Status:', 'full-elementor-mcp' ); ?>
					<select name="filter_status">
						<option value=""><?php esc_html_e( 'All Statuses', 'full-elementor-mcp' ); ?></option>
						<option value="committed" <?php selected( $status_filter, 'committed' ); ?>><?php esc_html_e( 'Committed', 'full-elementor-mcp' ); ?></option>
						<option value="rolled_back" <?php selected( $status_filter, 'rolled_back' ); ?>><?php esc_html_e( 'Rolled Back', 'full-elementor-mcp' ); ?></option>
						<option value="pending" <?php selected( $status_filter, 'pending' ); ?>><?php esc_html_e( 'Pending', 'full-elementor-mcp' ); ?></option>
						<option value="failed" <?php selected( $status_filter, 'failed' ); ?>><?php esc_html_e( 'Failed', 'full-elementor-mcp' ); ?></option>
					</select>
				</label>
				<label style="margin-left: 10px;">
					<?php esc_html_e( 'Resource Key:', 'full-elementor-mcp' ); ?>
					<input type="text" name="resource_key" value="<?php echo esc_attr( $resource_key ); ?>" placeholder="post:123" />
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'full-elementor-mcp' ); ?></button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Timestamp (UTC)', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Ability', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Resource Key', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Rollbackable', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'full-elementor-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $entries ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No journal entries found matching criteria.', 'full-elementor-mcp' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td>#<?php echo esc_html( (string) $entry['id'] ); ?></td>
								<td><?php echo esc_html( (string) $entry['created_at'] ); ?></td>
								<td><code><?php echo esc_html( (string) $entry['ability'] ); ?></code></td>
								<td><code><?php echo esc_html( (string) $entry['resource_key'] ); ?></code></td>
								<td>
									<span class="badge badge-<?php echo esc_attr( (string) $entry['status'] ); ?>">
										<?php echo esc_html( (string) $entry['status'] ); ?>
									</span>
								</td>
								<td><?php echo ! empty( $entry['rollback_supported'] ) ? '<span style="color:green;">Yes</span>' : '<span style="color:gray;">No</span>'; ?></td>
								<td>
									<?php if ( 'committed' === $entry['status'] && ! empty( $entry['rollback_supported'] ) ) : ?>
										<?php if ( class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) && Full_Elementor_MCP_Mutation_Middleware::is_ability_disabled( 'full-elementor-mcp/undo-change' ) ) : ?>
											<button type="button" class="button button-small button-secondary" disabled title="<?php esc_attr_e( 'Undo is disabled by policy', 'full-elementor-mcp' ); ?>"><?php esc_html_e( 'Disabled', 'full-elementor-mcp' ); ?></button>
										<?php else : ?>
											<form method="post" style="display:inline;">
												<?php wp_nonce_field( self::NONCE_ACTION, 'safety_nonce' ); ?>
												<input type="hidden" name="safety_action" value="undo_change" />
												<input type="hidden" name="journal_id" value="<?php echo esc_attr( (string) $entry['id'] ); ?>" />
												<button type="submit" class="button button-small button-secondary"><?php esc_html_e( 'Undo', 'full-elementor-mcp' ); ?></button>
											</form>
										<?php endif; ?>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Tab: Checkpoints
	 */
	private static function render_tab_checkpoints(): void {
		$resource_key = isset( $_GET['resource_key'] ) ? sanitize_text_field( (string) $_GET['resource_key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_filter  = isset( $_GET['checkpoint_type'] ) ? sanitize_key( (string) $_GET['checkpoint_type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$filters = array( 'limit' => 50 );
		if ( ! empty( $resource_key ) ) {
			$filters['resource_key'] = $resource_key;
		}
		if ( ! empty( $type_filter ) ) {
			$filters['checkpoint_type'] = $type_filter;
		}

		$checkpoints = class_exists( 'Full_Elementor_MCP_Checkpoint_Manager' )
			? Full_Elementor_MCP_Checkpoint_Manager::list_checkpoints( $filters )
			: array();
		?>
		<div class="card" style="max-width: 100%; padding: 15px 20px;">
			<h2><?php esc_html_e( 'Encrypted Immutable Checkpoints', 'full-elementor-mcp' ); ?></h2>

			<div style="background: #f0f6fc; border-left: 4px solid #72aee6; padding: 10px 15px; margin: 15px 0;">
				<h3 style="margin: 0 0 10px;"><?php esc_html_e( 'Create Manual Checkpoint', 'full-elementor-mcp' ); ?></h3>
				<?php if ( class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) && Full_Elementor_MCP_Mutation_Middleware::is_ability_disabled( 'full-elementor-mcp/create-checkpoint' ) ) : ?>
					<p style="color:gray; margin:0;"><?php esc_html_e( 'Manual checkpoint creation is disabled by administrator safety policy.', 'full-elementor-mcp' ); ?></p>
				<?php else : ?>
					<form method="post" style="display:flex; gap:10px; align-items:center;">
						<?php wp_nonce_field( self::NONCE_ACTION, 'safety_nonce' ); ?>
						<input type="hidden" name="safety_action" value="create_checkpoint" />
						<label>
							<?php esc_html_e( 'Resource Key:', 'full-elementor-mcp' ); ?>
							<input type="text" name="resource_key" placeholder="post:123" required />
						</label>
						<label>
							<?php esc_html_e( 'Label:', 'full-elementor-mcp' ); ?>
							<input type="text" name="label" placeholder="<?php esc_attr_e( 'Pre-deployment backup', 'full-elementor-mcp' ); ?>" />
						</label>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Checkpoint', 'full-elementor-mcp' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<table class="widefat striped" style="margin-top: 20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'UUID', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Timestamp (UTC)', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Resource', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Type', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Profile', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Restore Capability', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Size', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'full-elementor-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $checkpoints ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No checkpoints found.', 'full-elementor-mcp' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $checkpoints as $cp ) : ?>
							<tr>
								<td><code><?php echo esc_html( substr( (string) $cp['checkpoint_uuid'], 0, 13 ) . '...' ); ?></code></td>
								<td><?php echo esc_html( (string) $cp['created_at'] ); ?></td>
								<td><code><?php echo esc_html( (string) $cp['resource_key'] ); ?></code></td>
								<td><?php echo esc_html( (string) $cp['checkpoint_type'] ); ?></td>
								<td><code><?php echo esc_html( (string) ( $cp['historical_profile'] ?? 'modern' ) ); ?></code></td>
								<td><?php echo esc_html( (string) $cp['restore_capability'] ); ?></td>
								<td><?php echo esc_html( size_format( (int) $cp['size_bytes'] ) ); ?></td>
								<td>
									<?php if ( 'automatic' === $cp['restore_capability'] ) : ?>
										<?php if ( class_exists( 'Full_Elementor_MCP_Mutation_Middleware' ) && Full_Elementor_MCP_Mutation_Middleware::is_ability_disabled( 'full-elementor-mcp/restore-checkpoint' ) ) : ?>
											<button type="button" class="button button-small button-primary" disabled title="<?php esc_attr_e( 'Restore is disabled by policy', 'full-elementor-mcp' ); ?>"><?php esc_html_e( 'Disabled', 'full-elementor-mcp' ); ?></button>
										<?php else : ?>
											<form method="post" style="display:inline;">
												<?php wp_nonce_field( self::NONCE_ACTION, 'safety_nonce' ); ?>
												<input type="hidden" name="safety_action" value="restore_checkpoint" />
												<input type="hidden" name="checkpoint_uuid" value="<?php echo esc_attr( (string) $cp['checkpoint_uuid'] ); ?>" />
												<button type="submit" class="button button-small button-primary"><?php esc_html_e( 'Restore', 'full-elementor-mcp' ); ?></button>
											</form>
										<?php endif; ?>
									<?php else : ?>
										<span style="color:gray;"><?php esc_html_e( 'Evidence only', 'full-elementor-mcp' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Tab: Audit Log
	 */
	private static function render_tab_audit(): void {
		$sev_filter = isset( $_GET['severity'] ) ? sanitize_key( (string) $_GET['severity'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_filter = isset( $_GET['event_type'] ) ? sanitize_key( (string) $_GET['event_type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$filters = array( 'limit' => 50 );
		if ( ! empty( $sev_filter ) ) {
			$filters['severity'] = $sev_filter;
		}
		if ( ! empty( $type_filter ) ) {
			$filters['event_type'] = $type_filter;
		}

		$events = class_exists( 'Full_Elementor_MCP_Audit_Logger' )
			? Full_Elementor_MCP_Audit_Logger::query( $filters )
			: array();
		?>
		<div class="card" style="max-width: 100%; padding: 15px 20px;">
			<h2><?php esc_html_e( 'Forensic Audit Log', 'full-elementor-mcp' ); ?></h2>

			<form method="get" style="margin: 15px 0; display:flex; gap:10px; align-items:center;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<input type="hidden" name="tab" value="audit" />
				<label>
					<?php esc_html_e( 'Severity:', 'full-elementor-mcp' ); ?>
					<select name="severity">
						<option value=""><?php esc_html_e( 'All Severities', 'full-elementor-mcp' ); ?></option>
						<option value="info" <?php selected( $sev_filter, 'info' ); ?>><?php esc_html_e( 'Info', 'full-elementor-mcp' ); ?></option>
						<option value="notice" <?php selected( $sev_filter, 'notice' ); ?>><?php esc_html_e( 'Notice', 'full-elementor-mcp' ); ?></option>
						<option value="warning" <?php selected( $sev_filter, 'warning' ); ?>><?php esc_html_e( 'Warning', 'full-elementor-mcp' ); ?></option>
						<option value="critical" <?php selected( $sev_filter, 'critical' ); ?>><?php esc_html_e( 'Critical', 'full-elementor-mcp' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Event Type:', 'full-elementor-mcp' ); ?>
					<input type="text" name="event_type" value="<?php echo esc_attr( $type_filter ); ?>" placeholder="mutation_committed" />
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'full-elementor-mcp' ); ?></button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Timestamp (UTC)', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Event Type', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Severity', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Resource Key', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'User ID', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'IP Address', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Error Code', 'full-elementor-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $events ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No audit events found.', 'full-elementor-mcp' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $events as $event ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $event['created_at'] ); ?></td>
								<td><code><?php echo esc_html( (string) $event['event_type'] ); ?></code></td>
								<td>
									<span class="badge badge-<?php echo esc_attr( (string) $event['severity'] ); ?>">
										<?php echo esc_html( strtoupper( (string) $event['severity'] ) ); ?>
									</span>
								</td>
								<td><code><?php echo esc_html( (string) ( $event['resource_key'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( (string) $event['user_id'] ); ?></td>
								<td><code><?php echo esc_html( (string) ( $event['ip_address'] ?? '' ) ); ?></code></td>
								<td><code><?php echo esc_html( (string) ( $event['error_code'] ?? '' ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
				<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Prune audit log entries older than 90 days? (Unresolved safety errors will be preserved).', 'full-elementor-mcp' ) ); ?>');">
					<?php wp_nonce_field( self::NONCE_ACTION, 'safety_nonce' ); ?>
					<input type="hidden" name="safety_action" value="prune_audit" />
					<input type="hidden" name="retention_days" value="90" />
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Prune Old Audit Logs (>90 days)', 'full-elementor-mcp' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab: Recovery
	 */
	private static function render_tab_recovery(): void {
		global $wpdb;
		$journal_table = class_exists( 'Full_Elementor_MCP_Database_Installer' )
			? Full_Elementor_MCP_Database_Installer::get_journal_table()
			: '';

		$pending_items = array();
		if ( ! empty( $journal_table ) ) {
			$pending_items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$journal_table} WHERE status = %s OR error_message LIKE %s ORDER BY id DESC LIMIT 50",
					'pending',
					'%manual_recovery_required%'
				),
				ARRAY_A
			);
		}
		?>
		<div class="card" style="max-width: 100%; padding: 15px 20px;">
			<h2><?php esc_html_e( 'Disaster Recovery & Abandoned Operations', 'full-elementor-mcp' ); ?></h2>
			<p>
				<?php esc_html_e( 'The Write-Ahead Logging (WAL) system protects your site from half-executed mutations. If a server process crashes or terminates unexpectedly, mutations remain in pending status until safely recovered.', 'full-elementor-mcp' ); ?>
			</p>

			<div style="margin: 20px 0;">
				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION, 'safety_nonce' ); ?>
					<input type="hidden" name="safety_action" value="recover_pending" />
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Scan & Recover Abandoned Operations Now', 'full-elementor-mcp' ); ?>
					</button>
				</form>
			</div>

			<h3><?php esc_html_e( 'Unresolved Operations Requiring Attention', 'full-elementor-mcp' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Timestamp (UTC)', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Ability', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Resource Key', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'full-elementor-mcp' ); ?></th>
						<th><?php esc_html_e( 'Error Detail', 'full-elementor-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $pending_items ) ) : ?>
						<tr>
							<td colspan="6" style="color:green;">&#10004; <?php esc_html_e( 'Zero pending or stranded operations detected. All mutations reconciled cleanly.', 'full-elementor-mcp' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $pending_items as $item ) : ?>
							<tr>
								<td>#<?php echo esc_html( (string) $item['id'] ); ?></td>
								<td><?php echo esc_html( (string) $item['created_at'] ); ?></td>
								<td><code><?php echo esc_html( (string) $item['ability'] ); ?></code></td>
								<td><code><?php echo esc_html( (string) $item['resource_key'] ); ?></code></td>
								<td>
									<span class="badge badge-<?php echo esc_attr( (string) $item['status'] ); ?>">
										<?php echo esc_html( (string) $item['status'] ); ?>
									</span>
								</td>
								<td><code><?php echo esc_html( (string) ( $item['error_message'] ?? '' ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
