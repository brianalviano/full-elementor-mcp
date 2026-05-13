<?php
/**
 * Connection info tab view for the Full Elementor MCP admin settings page.
 *
 * Displays MCP connection configurations for various clients.
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var Full_Full_Elementor_MCP_Admin $this */
$full_elementor_mcp_endpoint      = rest_url( 'mcp/full-elementor-mcp-server' );
$full_elementor_mcp_enabled_count = $this->get_enabled_tool_count();
$full_elementor_mcp_total_count   = $this->get_total_tool_count();
$full_elementor_mcp_has_adapter   = class_exists( '\WP\MCP\Core\McpAdapter' );
?>

<div class="full-elementor-mcp-connection">

	<!-- Server Status -->
	<div class="full-elementor-mcp-section">
		<h2><?php esc_html_e( 'Server Status', 'full-elementor-mcp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Current status of your MCP server and connected components.', 'full-elementor-mcp' ); ?></p>

		<div class="full-elementor-mcp-status-grid">
			<div class="full-elementor-mcp-status-card">
				<span class="full-elementor-mcp-status-card-icon full-elementor-mcp-status-card-icon--ok">
					<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
				</span>
				<span class="full-elementor-mcp-status-card-info">
					<span class="full-elementor-mcp-status-card-label"><?php esc_html_e( 'Full Elementor MCP', 'full-elementor-mcp' ); ?></span>
					<span class="full-elementor-mcp-status-card-value"><?php esc_html_e( 'Active', 'full-elementor-mcp' ); ?></span>
				</span>
			</div>

			<div class="full-elementor-mcp-status-card">
				<span class="full-elementor-mcp-status-card-icon <?php echo esc_attr( $full_elementor_mcp_has_adapter ? 'full-elementor-mcp-status-card-icon--ok' : 'full-elementor-mcp-status-card-icon--warn' ); ?>">
					<?php if ( $full_elementor_mcp_has_adapter ) : ?>
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
					<?php else : ?>
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/></svg>
					<?php endif; ?>
				</span>
				<span class="full-elementor-mcp-status-card-info">
					<span class="full-elementor-mcp-status-card-label"><?php esc_html_e( 'MCP Adapter', 'full-elementor-mcp' ); ?></span>
					<span class="full-elementor-mcp-status-card-value"><?php echo esc_html( $full_elementor_mcp_has_adapter ? __( 'Active', 'full-elementor-mcp' ) : __( 'Not Active', 'full-elementor-mcp' ) ); ?></span>
				</span>
			</div>

			<div class="full-elementor-mcp-status-card">
				<span class="full-elementor-mcp-status-card-icon full-elementor-mcp-status-card-icon--ok">
					<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
				</span>
				<span class="full-elementor-mcp-status-card-info">
					<span class="full-elementor-mcp-status-card-label"><?php esc_html_e( 'Tools Enabled', 'full-elementor-mcp' ); ?></span>
					<span class="full-elementor-mcp-status-card-value">
						<?php
						printf(
							/* translators: %1$d: enabled count, %2$d: total count */
							esc_html__( '%1$d / %2$d', 'full-elementor-mcp' ),
							(int) $full_elementor_mcp_enabled_count,
							(int) $full_elementor_mcp_total_count
						);
						?>
					</span>
				</span>
			</div>
		</div>

		<div class="full-elementor-mcp-endpoint">
			<code><?php echo esc_html( $full_elementor_mcp_endpoint ); ?></code>
			<button type="button" class="button full-elementor-mcp-copy-btn" data-target="full-elementor-mcp-endpoint-copy"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
			<textarea id="full-elementor-mcp-endpoint-copy" class="full-elementor-mcp-copy-source"><?php echo esc_html( $full_elementor_mcp_endpoint ); ?></textarea>
		</div>
	</div>

	<!-- HTTP Connection -->
	<div class="full-elementor-mcp-section">
		<h2><?php esc_html_e( 'Connect Your AI Client', 'full-elementor-mcp' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Connect to this site from any AI client using HTTP. No proxy or Node.js needed — just an Application Password.', 'full-elementor-mcp' ); ?>
		</p>

		<h3><?php esc_html_e( 'Step 1: Generate Your Credentials', 'full-elementor-mcp' ); ?></h3>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to application passwords */
				esc_html__( 'Enter your username and Application Password (create one at %s).', 'full-elementor-mcp' ),
				'<a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'Users > Profile', 'full-elementor-mcp' ) . '</a>'
			);
			?>
		</p>

		<div class="full-elementor-mcp-cred-form">
			<div class="full-elementor-mcp-cred-field">
				<label for="full-elementor-mcp-b64-username"><?php esc_html_e( 'Username', 'full-elementor-mcp' ); ?></label>
				<input type="text" id="full-elementor-mcp-b64-username" value="<?php echo esc_attr( wp_get_current_user()->user_login ); ?>" />
			</div>
			<div class="full-elementor-mcp-cred-field">
				<label for="full-elementor-mcp-b64-app-password"><?php esc_html_e( 'Application Password', 'full-elementor-mcp' ); ?></label>
				<input type="text" id="full-elementor-mcp-b64-app-password" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" />
				<p class="description">
					<?php
					printf(
						/* translators: %s: link */
						esc_html__( 'Create one at %s', 'full-elementor-mcp' ),
						'<a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'Application Passwords', 'full-elementor-mcp' ) . '</a>'
					);
					?>
				</p>
			</div>
			<button type="button" class="button full-elementor-mcp-generate-btn" id="full-elementor-mcp-generate-b64"><?php esc_html_e( 'Generate Configs', 'full-elementor-mcp' ); ?></button>

			<div id="full-elementor-mcp-b64-result-row" style="display: none;">
				<div class="full-elementor-mcp-auth-result">
					<code id="full-elementor-mcp-b64-result"></code>
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="full-elementor-mcp-b64-result-copy"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
					<textarea id="full-elementor-mcp-b64-result-copy" class="full-elementor-mcp-copy-source"></textarea>
				</div>
			</div>
		</div>

		<div id="full-elementor-mcp-proxy-configs" style="display: none;">

			<h3><?php esc_html_e( 'Step 2: Node.js Proxy Configs (Recommended)', 'full-elementor-mcp' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'These configs use the bundled Node.js proxy which handles session management and protocol version compatibility automatically. Requires Node.js 18+ installed on the machine running your AI client.', 'full-elementor-mcp' ); ?>
			</p>

			<!-- Claude Code (Proxy) -->
			<div class="full-elementor-mcp-config-card">
				<div class="full-elementor-mcp-config-card-header">
					<span class="full-elementor-mcp-config-card-title"><?php esc_html_e( 'Claude Code', 'full-elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; .mcp.json</span></span>
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="claude-code-proxy"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
				</div>
				<pre><code id="full-elementor-mcp-claude-code-proxy-code"></code></pre>
				<textarea id="claude-code-proxy" class="full-elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Claude Desktop (Proxy) -->
			<div class="full-elementor-mcp-config-card">
				<div class="full-elementor-mcp-config-card-header">
					<span class="full-elementor-mcp-config-card-title"><?php esc_html_e( 'Claude Desktop', 'full-elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; claude_desktop_config.json</span></span>
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="claude-desktop-proxy"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
				</div>
				<pre><code id="full-elementor-mcp-claude-desktop-proxy-code"></code></pre>
				<textarea id="claude-desktop-proxy" class="full-elementor-mcp-copy-source"></textarea>
			</div>

			<p class="description">
				<strong><?php esc_html_e( 'Note:', 'full-elementor-mcp' ); ?></strong>
				<?php esc_html_e( 'Replace the proxy path with the absolute path to bin/mcp-proxy.mjs in your plugin installation directory.', 'full-elementor-mcp' ); ?>
			</p>

		</div>

		<div id="full-elementor-mcp-http-configs" style="display: none;">

			<h3><?php esc_html_e( 'Step 3: Direct HTTP Configs (Advanced)', 'full-elementor-mcp' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Direct HTTP connections require your AI client to handle session management (Mcp-Session-Id headers). Use only if the Node.js proxy is not an option.', 'full-elementor-mcp' ); ?>
			</p>

			<!-- Claude Code -->
			<div class="full-elementor-mcp-config-card">
				<div class="full-elementor-mcp-config-card-header">
					<span class="full-elementor-mcp-config-card-title"><?php esc_html_e( 'Claude Code', 'full-elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; .mcp.json</span></span>
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="claude-code-http"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
				</div>
				<pre><code id="full-elementor-mcp-claude-code-http-code"></code></pre>
				<textarea id="claude-code-http" class="full-elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Claude Desktop -->
			<div class="full-elementor-mcp-config-card">
				<div class="full-elementor-mcp-config-card-header">
					<span class="full-elementor-mcp-config-card-title"><?php esc_html_e( 'Claude Desktop', 'full-elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; claude_desktop_config.json</span></span>
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="claude-desktop-http"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
				</div>
				<pre><code id="full-elementor-mcp-claude-desktop-http-code"></code></pre>
				<textarea id="claude-desktop-http" class="full-elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Cursor -->
			<div class="full-elementor-mcp-config-card">
				<div class="full-elementor-mcp-config-card-header">
					<span class="full-elementor-mcp-config-card-title"><?php esc_html_e( 'Cursor', 'full-elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; .cursor/mcp.json</span></span>
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="cursor-config"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
				</div>
				<pre><code id="full-elementor-mcp-cursor-code"></code></pre>
				<textarea id="cursor-config" class="full-elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Windsurf -->
			<div class="full-elementor-mcp-config-card">
				<div class="full-elementor-mcp-config-card-header">
					<span class="full-elementor-mcp-config-card-title"><?php esc_html_e( 'Windsurf', 'full-elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; mcp_config.json</span></span>
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="windsurf-config"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
				</div>
				<pre><code id="full-elementor-mcp-windsurf-code"></code></pre>
				<textarea id="windsurf-config" class="full-elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Antigravity -->
			<div class="full-elementor-mcp-config-card">
				<div class="full-elementor-mcp-config-card-header">
					<span class="full-elementor-mcp-config-card-title"><?php esc_html_e( 'Antigravity', 'full-elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; mcp_config.json</span></span>
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="antigravity-config"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
				</div>
				<pre><code id="full-elementor-mcp-antigravity-code"></code></pre>
				<textarea id="antigravity-config" class="full-elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Codex -->
			<div class="full-elementor-mcp-config-card">
				<div class="full-elementor-mcp-config-card-header">
					<span class="full-elementor-mcp-config-card-title"><?php esc_html_e( 'Codex', 'full-elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; config.toml</span></span>
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="codex-config"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
				</div>
				<pre><code id="full-elementor-mcp-codex-code"></code></pre>
				<textarea id="codex-config" class="full-elementor-mcp-copy-source"></textarea>
			</div>

			<!-- npx mcp-remote -->
			<div class="full-elementor-mcp-config-card">
				<div class="full-elementor-mcp-config-card-header">
					<span class="full-elementor-mcp-config-card-title"><?php esc_html_e( 'npx mcp-remote', 'full-elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; <?php esc_html_e( 'any stdio client', 'full-elementor-mcp' ); ?></span></span>
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="mcp-remote-config"><?php esc_html_e( 'Copy', 'full-elementor-mcp' ); ?></button>
				</div>
				<pre><code id="full-elementor-mcp-mcp-remote-code"></code></pre>
				<textarea id="mcp-remote-config" class="full-elementor-mcp-copy-source"></textarea>
			</div>

		</div>
	</div>

</div>
