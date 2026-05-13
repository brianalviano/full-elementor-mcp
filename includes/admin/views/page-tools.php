<?php
/**
 * Tools tab view for the Full Elementor MCP admin settings page.
 *
 * Displays all MCP tools grouped by category with toggle switches.
 *
 * @package Full_Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var Full_Full_Elementor_MCP_Admin $this */
$full_elementor_mcp_all_tools     = $this->get_all_tools();
$full_elementor_mcp_disabled      = get_option( Full_Full_Elementor_MCP_Admin::OPTION_DISABLED_TOOLS, array() );
$full_elementor_mcp_enabled_count = $this->get_enabled_tool_count();
$full_elementor_mcp_total_count   = $this->get_total_tool_count();
?>

<form method="post" action="options.php" id="full-elementor-mcp-tools-form">
	<?php settings_fields( Full_Full_Elementor_MCP_Admin::SETTINGS_GROUP ); ?>

	<p class="full-elementor-mcp-tools-summary">
		<?php
		printf(
			/* translators: %1$s: opening strong tag, %2$d: enabled count, %3$d: total count, %4$s: closing strong tag */
			esc_html__( '%1$s%2$d of %3$d%4$s tools enabled.', 'full-elementor-mcp' ),
			'<strong>',
			(int) $full_elementor_mcp_enabled_count,
			(int) $full_elementor_mcp_total_count,
			'</strong>'
		);
		?>
	</p>

	<div class="full-elementor-mcp-bulk-actions">
		<button type="button" class="button full-elementor-mcp-enable-all"><?php esc_html_e( 'Enable All', 'full-elementor-mcp' ); ?></button>
		<button type="button" class="button full-elementor-mcp-disable-all"><?php esc_html_e( 'Disable All', 'full-elementor-mcp' ); ?></button>
	</div>

	<?php foreach ( $full_elementor_mcp_all_tools as $full_elementor_mcp_category_id => $full_elementor_mcp_category ) : ?>
		<div class="full-elementor-mcp-category" data-category="<?php echo esc_attr( $full_elementor_mcp_category_id ); ?>">
			<h2 class="full-elementor-mcp-category-header">
				<?php echo esc_html( $full_elementor_mcp_category['label'] ); ?>
				<span class="full-elementor-mcp-category-count">
					<?php
					$full_elementor_mcp_cat_total   = count( $full_elementor_mcp_category['tools'] );
					$full_elementor_mcp_cat_enabled = 0;
					foreach ( $full_elementor_mcp_category['tools'] as $full_elementor_mcp_slug => $full_elementor_mcp_tool ) {
						if ( ! in_array( $full_elementor_mcp_slug, $full_elementor_mcp_disabled, true ) ) {
							$full_elementor_mcp_cat_enabled++;
						}
					}
					printf(
						/* translators: %1$d: enabled, %2$d: total */
						esc_html__( '%1$d / %2$d', 'full-elementor-mcp' ),
						(int) $full_elementor_mcp_cat_enabled,
						(int) $full_elementor_mcp_cat_total
					);
					?>
				</span>
				<span class="full-elementor-mcp-category-actions">
					<button type="button" class="button-link full-elementor-mcp-cat-enable-all"><?php esc_html_e( 'All', 'full-elementor-mcp' ); ?></button>
					<span class="full-elementor-mcp-separator">&middot;</span>
					<button type="button" class="button-link full-elementor-mcp-cat-disable-all"><?php esc_html_e( 'None', 'full-elementor-mcp' ); ?></button>
				</span>
			</h2>

			<div class="full-elementor-mcp-tools-grid">
				<?php foreach ( $full_elementor_mcp_category['tools'] as $full_elementor_mcp_slug => $full_elementor_mcp_tool ) : ?>
					<?php $full_elementor_mcp_is_enabled = ! in_array( $full_elementor_mcp_slug, $full_elementor_mcp_disabled, true ); ?>
					<label class="full-elementor-mcp-tool-card <?php echo esc_attr( $full_elementor_mcp_is_enabled ? 'is-enabled' : 'is-disabled' ); ?>">
						<input
							type="checkbox"
							name="<?php echo esc_attr( Full_Full_Elementor_MCP_Admin::OPTION_DISABLED_TOOLS ); ?>[]"
							value="<?php echo esc_attr( $full_elementor_mcp_slug ); ?>"
							<?php checked( $full_elementor_mcp_is_enabled ); ?>
						/>
						<span class="full-elementor-mcp-toggle" aria-hidden="true">
							<span class="full-elementor-mcp-toggle-track"></span>
						</span>
						<span class="full-elementor-mcp-tool-info">
							<span class="full-elementor-mcp-tool-name">
								<?php echo esc_html( $full_elementor_mcp_tool['label'] ); ?>
								<?php foreach ( $full_elementor_mcp_tool['badges'] as $full_elementor_mcp_badge ) : ?>
									<span class="full-elementor-mcp-badge full-elementor-mcp-badge--<?php echo esc_attr( $full_elementor_mcp_badge ); ?>">
										<?php echo esc_html( $full_elementor_mcp_badge ); ?>
									</span>
								<?php endforeach; ?>
							</span>
							<span class="full-elementor-mcp-tool-desc"><?php echo esc_html( $full_elementor_mcp_tool['description'] ); ?></span>
							<code class="full-elementor-mcp-tool-slug"><?php echo esc_html( $full_elementor_mcp_slug ); ?></code>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endforeach; ?>

	<?php submit_button( __( 'Save Changes', 'full-elementor-mcp' ) ); ?>
</form>
