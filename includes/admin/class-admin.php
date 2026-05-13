<?php
/**
 * Admin settings page for Full Elementor MCP.
 *
 * Provides a UI to toggle individual MCP tools on/off and view
 * connection information for various MCP clients.
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page orchestrator.
 *
 * @since 1.0.0
 */
class Full_Full_Elementor_MCP_Admin {

	/**
	 * The page hook suffix returned by add_options_page().
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Option name for storing disabled tools.
	 *
	 * @var string
	 */
	const OPTION_DISABLED_TOOLS = 'full_elementor_mcp_disabled_tools';

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'full_elementor_mcp_settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'full-elementor-mcp';

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'full_elementor_mcp_ability_names', array( $this, 'filter_ability_names' ) );
	}

	/**
	 * Add the settings page under the Settings menu.
	 *
	 * @since 1.0.0
	 */
	public function add_settings_page(): void {
		$this->hook_suffix = add_options_page(
			__( 'Full Elementor MCP', 'full-elementor-mcp' ),
			__( 'EMCP Tools', 'full-elementor-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings with the WordPress Settings API.
	 *
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_DISABLED_TOOLS,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_disabled_tools' ),
			)
		);
	}

	/**
	 * Sanitize the disabled tools option value.
	 *
	 * The form submits an array of enabled tool slugs. We compute the
	 * disabled list as the difference between all known tools and the
	 * enabled ones submitted.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input The raw form input.
	 * @return string[] Sanitized array of disabled tool slugs.
	 */
	public function sanitize_disabled_tools( $input ): array {
		$enabled_tools = array();

		if ( is_array( $input ) ) {
			$enabled_tools = array_map( 'sanitize_text_field', $input );
		}

		// Get all known tool slugs.
		$all_tools = $this->get_all_tool_slugs();

		// Disabled = all tools minus the ones that were checked.
		$disabled = array_values( array_diff( $all_tools, $enabled_tools ) );

		return $disabled;
	}

	/**
	 * Enqueue admin CSS on our settings page only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'full-elementor-mcp-admin',
			FULL_ELEMENTOR_MCP_URL . 'assets/css/admin.css',
			array(),
			FULL_ELEMENTOR_MCP_VERSION
		);

		wp_enqueue_script(
			'full-elementor-mcp-admin',
			FULL_ELEMENTOR_MCP_URL . 'assets/js/admin.js',
			array(),
			FULL_ELEMENTOR_MCP_VERSION,
			true
		);

		wp_localize_script(
			'full-elementor-mcp-admin',
			'fullElementorMcpAdmin',
			array(
				'copied'      => __( 'Copied!', 'full-elementor-mcp' ),
				'mcpEndpoint' => rest_url( 'mcp/full-elementor-mcp-server' ),
				'siteUrl'     => site_url(),
				'proxyPath'   => FULL_ELEMENTOR_MCP_DIR . 'bin' . DIRECTORY_SEPARATOR . 'mcp-proxy.mjs',
			)
		);
	}

	/**
	 * Filter ability names to remove disabled tools.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $names The registered ability names.
	 * @return string[] Filtered ability names.
	 */
	public function filter_ability_names( array $names ): array {
		$disabled = get_option( self::OPTION_DISABLED_TOOLS, array() );

		if ( empty( $disabled ) ) {
			return $names;
		}

		return array_values( array_diff( $names, $disabled ) );
	}

	/**
	 * Render the settings page.
	 *
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'tools'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$enabled_count = $this->get_enabled_tool_count();
		$total_count   = $this->get_total_tool_count();

		// Count Pro tools.
		$pro_count = 0;
		foreach ( $this->get_all_tools() as $category ) {
			foreach ( $category['tools'] as $tool ) {
				if ( in_array( 'pro', $tool['badges'], true ) ) {
					$pro_count++;
				}
			}
		}

		// Count sample prompts.
		$prompts_dir   = FULL_ELEMENTOR_MCP_DIR . 'prompts/';
		$prompt_files  = is_dir( $prompts_dir ) ? glob( $prompts_dir . '*.md' ) : array();
		$prompt_count  = count( $prompt_files );

		?>
		<div class="wrap full-elementor-mcp-admin">
			<h1><?php esc_html_e( 'Full Elementor MCP', 'full-elementor-mcp' ); ?></h1>

			<!-- Header -->
			<div class="full-elementor-mcp-header">
				<span class="full-elementor-mcp-header-icon">
					<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
				</span>
				<div class="full-elementor-mcp-header-info">
					<h2 class="full-elementor-mcp-header-title">
						<?php esc_html_e( 'Full Elementor MCP', 'full-elementor-mcp' ); ?>
						<span class="full-elementor-mcp-header-version">v<?php echo esc_html( FULL_ELEMENTOR_MCP_VERSION ); ?></span>
					</h2>
					<p class="full-elementor-mcp-header-subtitle"><?php esc_html_e( 'AI-powered page building tools for Elementor via Model Context Protocol.', 'full-elementor-mcp' ); ?></p>
				</div>
				<div class="full-elementor-mcp-header-actions">
					<a href="https://www.youtube.com/watch?v=tXCpGa-hqxk" class="full-elementor-mcp-header-btn full-elementor-mcp-header-btn--secondary" target="_blank" rel="noopener noreferrer">
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"/></svg>
						<?php esc_html_e( 'Watch Tutorial', 'full-elementor-mcp' ); ?>
					</a>
					<a href="https://msrbuilds.com/lets-talk/" class="full-elementor-mcp-header-btn full-elementor-mcp-header-btn--secondary">
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
						<?php esc_html_e( 'Contact Me', 'full-elementor-mcp' ); ?>
					</a>
					<a href="https://wpacademy.gumroad.com/l/vlrihk" class="full-elementor-mcp-header-btn full-elementor-mcp-header-btn--primary" target="_blank" rel="noopener noreferrer">
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
						<?php esc_html_e( 'Get Premium Prompts', 'full-elementor-mcp' ); ?>
					</a>
				</div>
			</div>

			<!-- Stats Bar -->
			<div class="full-elementor-mcp-stats">
				<div class="full-elementor-mcp-stat">
					<span class="full-elementor-mcp-stat-icon full-elementor-mcp-stat-icon--tools">
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
					</span>
					<span class="full-elementor-mcp-stat-content">
						<span class="full-elementor-mcp-stat-value"><?php echo esc_html( $total_count ); ?></span>
						<span class="full-elementor-mcp-stat-label"><?php esc_html_e( 'Total Tools', 'full-elementor-mcp' ); ?></span>
					</span>
				</div>
				<div class="full-elementor-mcp-stat">
					<span class="full-elementor-mcp-stat-icon full-elementor-mcp-stat-icon--active">
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
					</span>
					<span class="full-elementor-mcp-stat-content">
						<span class="full-elementor-mcp-stat-value"><?php echo esc_html( $enabled_count ); ?></span>
						<span class="full-elementor-mcp-stat-label"><?php esc_html_e( 'Active', 'full-elementor-mcp' ); ?></span>
					</span>
				</div>
				<div class="full-elementor-mcp-stat">
					<span class="full-elementor-mcp-stat-icon full-elementor-mcp-stat-icon--pro">
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
					</span>
					<span class="full-elementor-mcp-stat-content">
						<span class="full-elementor-mcp-stat-value"><?php echo esc_html( $pro_count ); ?></span>
						<span class="full-elementor-mcp-stat-label"><?php esc_html_e( 'Pro Tools', 'full-elementor-mcp' ); ?></span>
					</span>
				</div>
				<div class="full-elementor-mcp-stat">
					<span class="full-elementor-mcp-stat-icon full-elementor-mcp-stat-icon--prompts">
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
					</span>
					<span class="full-elementor-mcp-stat-content">
						<span class="full-elementor-mcp-stat-value"><?php echo esc_html( $prompt_count ); ?></span>
						<span class="full-elementor-mcp-stat-label"><?php esc_html_e( 'Prompts', 'full-elementor-mcp' ); ?></span>
					</span>
				</div>
			</div>

			<!-- Tabs -->
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=tools' ) ); ?>"
				   class="nav-tab <?php echo esc_attr( 'tools' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Tools', 'full-elementor-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=connection' ) ); ?>"
				   class="nav-tab <?php echo esc_attr( 'connection' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Connection', 'full-elementor-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=prompts' ) ); ?>"
				   class="nav-tab <?php echo esc_attr( 'prompts' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Prompts', 'full-elementor-mcp' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=changelog' ) ); ?>"
				   class="nav-tab <?php echo esc_attr( 'changelog' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Changelog', 'full-elementor-mcp' ); ?>
				</a>
			</nav>

			<!-- Content -->
			<div class="tab-content">
				<?php
				if ( 'connection' === $active_tab ) {
					include FULL_ELEMENTOR_MCP_DIR . 'includes/admin/views/page-connection.php';
				} elseif ( 'prompts' === $active_tab ) {
					include FULL_ELEMENTOR_MCP_DIR . 'includes/admin/views/page-prompts.php';
				} elseif ( 'changelog' === $active_tab ) {
					include FULL_ELEMENTOR_MCP_DIR . 'includes/admin/views/page-changelog.php';
				} else {
					include FULL_ELEMENTOR_MCP_DIR . 'includes/admin/views/page-tools.php';
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get all tools grouped by category for the UI.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, tools: array<string, array{label: string, description: string, badges: string[]}>}> Grouped tools.
	 */
	public function get_all_tools(): array {
		return array(
			'query'            => array(
				'label' => __( 'Query & Discovery', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/list-widgets'         => array(
						'label'       => __( 'List Widgets', 'full-elementor-mcp' ),
						'description' => __( 'Lists all available Elementor widget types and their names.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
					'full-elementor-mcp/get-widget-schema'    => array(
						'label'       => __( 'Get Widget Schema', 'full-elementor-mcp' ),
						'description' => __( 'Returns the JSON schema for a specific widget type.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
					'full-elementor-mcp/get-page-structure'   => array(
						'label'       => __( 'Get Page Structure', 'full-elementor-mcp' ),
						'description' => __( 'Returns the full Elementor element tree for a page.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
					'full-elementor-mcp/get-element-settings' => array(
						'label'       => __( 'Get Element Settings', 'full-elementor-mcp' ),
						'description' => __( 'Returns the settings of a specific element by ID.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
					'full-elementor-mcp/list-pages'           => array(
						'label'       => __( 'List Pages', 'full-elementor-mcp' ),
						'description' => __( 'Lists all pages/posts that use Elementor.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
					'full-elementor-mcp/list-templates'       => array(
						'label'       => __( 'List Templates', 'full-elementor-mcp' ),
						'description' => __( 'Lists all saved Elementor templates.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
					'full-elementor-mcp/get-global-settings'  => array(
						'label'       => __( 'Get Global Settings', 'full-elementor-mcp' ),
						'description' => __( 'Returns global colors, typography, and theme settings.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
				),
			),
			'page'             => array(
				'label' => __( 'Page Management', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/create-page'          => array(
						'label'       => __( 'Create Page', 'full-elementor-mcp' ),
						'description' => __( 'Creates a new WordPress page with Elementor enabled.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/update-page-settings' => array(
						'label'       => __( 'Update Page Settings', 'full-elementor-mcp' ),
						'description' => __( 'Updates Elementor page-level settings (layout, canvas, etc).', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/delete-page-content'  => array(
						'label'       => __( 'Delete Page Content', 'full-elementor-mcp' ),
						'description' => __( 'Removes all Elementor content from a page.', 'full-elementor-mcp' ),
						'badges'      => array( 'destructive' ),
					),
					'full-elementor-mcp/import-template'      => array(
						'label'       => __( 'Import Template', 'full-elementor-mcp' ),
						'description' => __( 'Imports an Elementor template JSON into a page.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/export-page'          => array(
						'label'       => __( 'Export Page', 'full-elementor-mcp' ),
						'description' => __( 'Exports a page\'s Elementor data as JSON.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
				),
			),
			'layout'           => array(
				'label' => __( 'Layout & Structure', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/add-container'     => array(
						'label'       => __( 'Add Container', 'full-elementor-mcp' ),
						'description' => __( 'Adds a new flexbox container to a page or inside another container.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/move-element'      => array(
						'label'       => __( 'Move Element', 'full-elementor-mcp' ),
						'description' => __( 'Moves an element to a new parent or position.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/remove-element'    => array(
						'label'       => __( 'Remove Element', 'full-elementor-mcp' ),
						'description' => __( 'Removes an element and all its children from the page.', 'full-elementor-mcp' ),
						'badges'      => array( 'destructive' ),
					),
					'full-elementor-mcp/duplicate-element'    => array(
						'label'       => __( 'Duplicate Element', 'full-elementor-mcp' ),
						'description' => __( 'Creates a deep copy of an element and inserts it after the original.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/update-container'     => array(
						'label'       => __( 'Update Container', 'full-elementor-mcp' ),
						'description' => __( 'Updates settings on an existing container element.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/get-container-schema' => array(
						'label'       => __( 'Get Container Schema', 'full-elementor-mcp' ),
						'description' => __( 'Returns the JSON schema for container settings.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
					'full-elementor-mcp/find-element'         => array(
						'label'       => __( 'Find Element', 'full-elementor-mcp' ),
						'description' => __( 'Finds elements by type, settings, or CSS class within a page.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
					'full-elementor-mcp/update-element'       => array(
						'label'       => __( 'Update Element', 'full-elementor-mcp' ),
						'description' => __( 'Updates settings on any element (widget or container) by ID.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/batch-update'         => array(
						'label'       => __( 'Batch Update', 'full-elementor-mcp' ),
						'description' => __( 'Applies multiple element updates in a single call.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/reorder-elements'     => array(
						'label'       => __( 'Reorder Elements', 'full-elementor-mcp' ),
						'description' => __( 'Reorders child elements within a container.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
				),
			),
			'widget_universal' => array(
				'label' => __( 'Widget Tools', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/add-widget'    => array(
						'label'       => __( 'Add Widget', 'full-elementor-mcp' ),
						'description' => __( 'Adds any widget type to a container with full settings control.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/update-widget' => array(
						'label'       => __( 'Update Widget', 'full-elementor-mcp' ),
						'description' => __( 'Updates settings on an existing widget element.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
				),
			),
			'widget_core'      => array(
				'label' => __( 'Widget Shortcuts', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/add-heading'     => array(
						'label'       => __( 'Add Heading', 'full-elementor-mcp' ),
						'description' => __( 'Adds a heading widget with simplified parameters.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-text-editor' => array(
						'label'       => __( 'Add Text Editor', 'full-elementor-mcp' ),
						'description' => __( 'Adds a text editor (WYSIWYG) widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-image'       => array(
						'label'       => __( 'Add Image', 'full-elementor-mcp' ),
						'description' => __( 'Adds an image widget by media library ID or URL.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-button'      => array(
						'label'       => __( 'Add Button', 'full-elementor-mcp' ),
						'description' => __( 'Adds a button widget with text and link.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-video'       => array(
						'label'       => __( 'Add Video', 'full-elementor-mcp' ),
						'description' => __( 'Adds a video embed widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-icon'        => array(
						'label'       => __( 'Add Icon', 'full-elementor-mcp' ),
						'description' => __( 'Adds an icon widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-spacer'      => array(
						'label'       => __( 'Add Spacer', 'full-elementor-mcp' ),
						'description' => __( 'Adds a spacer widget for vertical spacing.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-divider'     => array(
						'label'       => __( 'Add Divider', 'full-elementor-mcp' ),
						'description' => __( 'Adds a horizontal divider/separator widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-icon-box'        => array(
						'label'       => __( 'Add Icon Box', 'full-elementor-mcp' ),
						'description' => __( 'Adds an icon box widget (icon + title + description).', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-accordion'       => array(
						'label'       => __( 'Add Accordion', 'full-elementor-mcp' ),
						'description' => __( 'Adds a collapsible accordion widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-alert'           => array(
						'label'       => __( 'Add Alert', 'full-elementor-mcp' ),
						'description' => __( 'Adds an alert/notice widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-counter'         => array(
						'label'       => __( 'Add Counter', 'full-elementor-mcp' ),
						'description' => __( 'Adds an animated counter widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-google-maps'     => array(
						'label'       => __( 'Add Google Maps', 'full-elementor-mcp' ),
						'description' => __( 'Adds an embedded Google Maps widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-icon-list'       => array(
						'label'       => __( 'Add Icon List', 'full-elementor-mcp' ),
						'description' => __( 'Adds an icon list widget for feature lists and checklists.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-image-box'       => array(
						'label'       => __( 'Add Image Box', 'full-elementor-mcp' ),
						'description' => __( 'Adds an image box widget (image + title + description).', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-image-carousel'  => array(
						'label'       => __( 'Add Image Carousel', 'full-elementor-mcp' ),
						'description' => __( 'Adds a rotating image carousel widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-progress'        => array(
						'label'       => __( 'Add Progress Bar', 'full-elementor-mcp' ),
						'description' => __( 'Adds an animated progress bar widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-social-icons'    => array(
						'label'       => __( 'Add Social Icons', 'full-elementor-mcp' ),
						'description' => __( 'Adds social media icon links.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-star-rating'     => array(
						'label'       => __( 'Add Star Rating', 'full-elementor-mcp' ),
						'description' => __( 'Adds a star rating display widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-tabs'            => array(
						'label'       => __( 'Add Tabs', 'full-elementor-mcp' ),
						'description' => __( 'Adds a tabbed content widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-testimonial'     => array(
						'label'       => __( 'Add Testimonial', 'full-elementor-mcp' ),
						'description' => __( 'Adds a testimonial widget with quote and author.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-toggle'          => array(
						'label'       => __( 'Add Toggle', 'full-elementor-mcp' ),
						'description' => __( 'Adds a toggle/expandable content widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-html'            => array(
						'label'       => __( 'Add HTML', 'full-elementor-mcp' ),
						'description' => __( 'Adds a custom HTML code widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-menu-anchor'     => array(
						'label'       => __( 'Add Menu Anchor', 'full-elementor-mcp' ),
						'description' => __( 'Adds an invisible anchor for one-page navigation links.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-shortcode'       => array(
						'label'       => __( 'Add Shortcode', 'full-elementor-mcp' ),
						'description' => __( 'Adds a shortcode widget to embed WordPress shortcodes.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-rating'          => array(
						'label'       => __( 'Add Rating', 'full-elementor-mcp' ),
						'description' => __( 'Adds a rating widget with customizable scale and icons.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-text-path'       => array(
						'label'       => __( 'Add Text Path', 'full-elementor-mcp' ),
						'description' => __( 'Adds a text-on-path widget for curved/circular text.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
				),
			),
			'widget_pro'       => array(
				'label' => __( 'Pro Widget Shortcuts', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/add-form'              => array(
						'label'       => __( 'Add Form', 'full-elementor-mcp' ),
						'description' => __( 'Adds a form widget with configurable fields.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-posts-grid'        => array(
						'label'       => __( 'Add Posts Grid', 'full-elementor-mcp' ),
						'description' => __( 'Adds a posts grid/listing widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-countdown'         => array(
						'label'       => __( 'Add Countdown', 'full-elementor-mcp' ),
						'description' => __( 'Adds a countdown timer widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-price-table'       => array(
						'label'       => __( 'Add Price Table', 'full-elementor-mcp' ),
						'description' => __( 'Adds a pricing table widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-flip-box'          => array(
						'label'       => __( 'Add Flip Box', 'full-elementor-mcp' ),
						'description' => __( 'Adds a flip box widget with front/back sides.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-animated-headline'    => array(
						'label'       => __( 'Add Animated Headline', 'full-elementor-mcp' ),
						'description' => __( 'Adds an animated headline widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-call-to-action'       => array(
						'label'       => __( 'Add Call to Action', 'full-elementor-mcp' ),
						'description' => __( 'Adds a call-to-action widget with title, description, and button.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-slides'               => array(
						'label'       => __( 'Add Slides', 'full-elementor-mcp' ),
						'description' => __( 'Adds a full-width slides/slider widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-testimonial-carousel'  => array(
						'label'       => __( 'Add Testimonial Carousel', 'full-elementor-mcp' ),
						'description' => __( 'Adds a testimonial carousel/slider widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-price-list'           => array(
						'label'       => __( 'Add Price List', 'full-elementor-mcp' ),
						'description' => __( 'Adds a price list widget for menus and services.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-gallery'              => array(
						'label'       => __( 'Add Gallery', 'full-elementor-mcp' ),
						'description' => __( 'Adds an advanced gallery widget with grid/masonry layout.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-share-buttons'        => array(
						'label'       => __( 'Add Share Buttons', 'full-elementor-mcp' ),
						'description' => __( 'Adds social share buttons widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-table-of-contents'    => array(
						'label'       => __( 'Add Table of Contents', 'full-elementor-mcp' ),
						'description' => __( 'Adds an auto-generated table of contents widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-blockquote'           => array(
						'label'       => __( 'Add Blockquote', 'full-elementor-mcp' ),
						'description' => __( 'Adds a styled blockquote widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-lottie'               => array(
						'label'       => __( 'Add Lottie Animation', 'full-elementor-mcp' ),
						'description' => __( 'Adds a Lottie animation widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-hotspot'              => array(
						'label'       => __( 'Add Hotspot', 'full-elementor-mcp' ),
						'description' => __( 'Adds an image hotspot widget with interactive points.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-nav-menu'             => array(
						'label'       => __( 'Add Nav Menu', 'full-elementor-mcp' ),
						'description' => __( 'Adds a navigation menu widget from registered WordPress menus.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-loop-grid'            => array(
						'label'       => __( 'Add Loop Grid', 'full-elementor-mcp' ),
						'description' => __( 'Adds a loop grid widget for dynamic post/CPT listings.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-loop-carousel'        => array(
						'label'       => __( 'Add Loop Carousel', 'full-elementor-mcp' ),
						'description' => __( 'Adds a loop carousel widget for dynamic post/CPT carousels.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-media-carousel'       => array(
						'label'       => __( 'Add Media Carousel', 'full-elementor-mcp' ),
						'description' => __( 'Adds a media carousel widget for images and videos.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-nested-tabs'          => array(
						'label'       => __( 'Add Nested Tabs', 'full-elementor-mcp' ),
						'description' => __( 'Adds nested tabs widget where each tab is a container.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-nested-accordion'     => array(
						'label'       => __( 'Add Nested Accordion', 'full-elementor-mcp' ),
						'description' => __( 'Adds nested accordion widget where each item is a container.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-code-highlight'       => array(
						'label'       => __( 'Add Code Highlight', 'full-elementor-mcp' ),
						'description' => __( 'Adds a syntax-highlighted code block widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-reviews'              => array(
						'label'       => __( 'Add Reviews', 'full-elementor-mcp' ),
						'description' => __( 'Adds a reviews/testimonials carousel widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-off-canvas'           => array(
						'label'       => __( 'Add Off-Canvas', 'full-elementor-mcp' ),
						'description' => __( 'Adds an off-canvas panel widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-progress-tracker'     => array(
						'label'       => __( 'Add Progress Tracker', 'full-elementor-mcp' ),
						'description' => __( 'Adds a scroll progress tracker widget.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-search'               => array(
						'label'       => __( 'Add Search', 'full-elementor-mcp' ),
						'description' => __( 'Adds a search widget with live results support.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
				),
			),
			'template'         => array(
				'label' => __( 'Templates', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/save-as-template' => array(
						'label'       => __( 'Save as Template', 'full-elementor-mcp' ),
						'description' => __( 'Saves the current page content as a reusable template.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/apply-template'       => array(
						'label'       => __( 'Apply Template', 'full-elementor-mcp' ),
						'description' => __( 'Applies a saved template to a target page.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/create-theme-template' => array(
						'label'       => __( 'Create Theme Template', 'full-elementor-mcp' ),
						'description' => __( 'Creates a theme builder template (header, footer, single, archive, etc).', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/set-template-conditions' => array(
						'label'       => __( 'Set Template Conditions', 'full-elementor-mcp' ),
						'description' => __( 'Sets display conditions on a theme builder template.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/list-dynamic-tags'    => array(
						'label'       => __( 'List Dynamic Tags', 'full-elementor-mcp' ),
						'description' => __( 'Lists all available dynamic tags and their categories.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'full-elementor-mcp/set-dynamic-tag'      => array(
						'label'       => __( 'Set Dynamic Tag', 'full-elementor-mcp' ),
						'description' => __( 'Sets a dynamic tag on a specific element setting.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/create-popup'         => array(
						'label'       => __( 'Create Popup', 'full-elementor-mcp' ),
						'description' => __( 'Creates an Elementor popup template.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/set-popup-settings'   => array(
						'label'       => __( 'Set Popup Settings', 'full-elementor-mcp' ),
						'description' => __( 'Sets triggers, conditions, and timing on a popup template.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
				),
			),
			'global'           => array(
				'label' => __( 'Global Settings', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/update-global-colors'     => array(
						'label'       => __( 'Update Global Colors', 'full-elementor-mcp' ),
						'description' => __( 'Updates the site-wide Elementor color palette.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/update-global-typography' => array(
						'label'       => __( 'Update Global Typography', 'full-elementor-mcp' ),
						'description' => __( 'Updates the site-wide Elementor typography presets.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
				),
			),
			'composite'        => array(
				'label' => __( 'Composite', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/build-page' => array(
						'label'       => __( 'Build Page', 'full-elementor-mcp' ),
						'description' => __( 'Creates a complete page from a declarative structure in one call.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
				),
			),
			'stock_images'     => array(
				'label' => __( 'Stock Images', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/search-images'    => array(
						'label'       => __( 'Search Images', 'full-elementor-mcp' ),
						'description' => __( 'Searches Openverse for Creative Commons licensed images.', 'full-elementor-mcp' ),
						'badges'      => array( 'read-only' ),
					),
					'full-elementor-mcp/sideload-image'   => array(
						'label'       => __( 'Sideload Image', 'full-elementor-mcp' ),
						'description' => __( 'Downloads an external image into the WordPress Media Library.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-stock-image'  => array(
						'label'       => __( 'Add Stock Image', 'full-elementor-mcp' ),
						'description' => __( 'Searches, downloads, and adds a stock image to the page in one call.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
				),
			),
			'svg_icons'        => array(
				'label' => __( 'SVG Icons', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/upload-svg-icon'  => array(
						'label'       => __( 'Upload SVG Icon', 'full-elementor-mcp' ),
						'description' => __( 'Uploads an SVG icon (from URL or raw markup) for use with icon/icon-box widgets.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
				),
			),
			'custom_code'      => array(
				'label' => __( 'Custom Code', 'full-elementor-mcp' ),
				'tools' => array(
					'full-elementor-mcp/add-custom-css'     => array(
						'label'       => __( 'Add Custom CSS', 'full-elementor-mcp' ),
						'description' => __( 'Adds custom CSS to a specific element or the entire page.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/add-custom-js'      => array(
						'label'       => __( 'Add Custom JavaScript', 'full-elementor-mcp' ),
						'description' => __( 'Adds a JavaScript snippet to a page via an HTML widget.', 'full-elementor-mcp' ),
						'badges'      => array(),
					),
					'full-elementor-mcp/add-code-snippet'   => array(
						'label'       => __( 'Add Code Snippet', 'full-elementor-mcp' ),
						'description' => __( 'Creates a site-wide Custom Code snippet for head/body injection.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro' ),
					),
					'full-elementor-mcp/list-code-snippets' => array(
						'label'       => __( 'List Code Snippets', 'full-elementor-mcp' ),
						'description' => __( 'Lists all existing Custom Code snippets.', 'full-elementor-mcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
				),
			),
		);
	}

	/**
	 * Get a flat list of all tool slugs.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] All tool slugs.
	 */
	public function get_all_tool_slugs(): array {
		$slugs = array();

		foreach ( $this->get_all_tools() as $category ) {
			foreach ( $category['tools'] as $slug => $tool ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Count enabled tools.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of enabled tools.
	 */
	public function get_enabled_tool_count(): int {
		$all      = $this->get_all_tool_slugs();
		$disabled = get_option( self::OPTION_DISABLED_TOOLS, array() );

		return count( array_diff( $all, $disabled ) );
	}

	/**
	 * Count total tools.
	 *
	 * @since 1.0.0
	 *
	 * @return int Total number of tools.
	 */
	public function get_total_tool_count(): int {
		return count( $this->get_all_tool_slugs() );
	}
}
