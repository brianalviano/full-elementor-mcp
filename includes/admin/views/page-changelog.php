<?php
/**
 * Changelog tab view for the Safe Elementor MCP admin settings page.
 *
 * Reads CHANGELOG.md and displays version entries as styled cards.
 *
 * @package Full_Elementor_MCP
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$full_elementor_mcp_changelog_file = FULL_ELEMENTOR_MCP_DIR . 'CHANGELOG.md';

if ( ! file_exists( $full_elementor_mcp_changelog_file ) ) {
	echo '<p>' . esc_html__( 'Changelog file not found.', 'full-elementor-mcp' ) . '</p>';
	return;
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file.
$full_elementor_mcp_changelog_raw = file_get_contents( $full_elementor_mcp_changelog_file );

/**
 * Parse the markdown changelog into version blocks.
 * Each block: version string + array of change lines.
 */
$full_elementor_mcp_versions      = array();
$full_elementor_mcp_current_ver   = null;
$full_elementor_mcp_current_items = array();

foreach ( explode( "\n", $full_elementor_mcp_changelog_raw ) as $full_elementor_mcp_line ) {
	// Match version headers: ## [x.x.x]
	if ( preg_match( '/^## \[([^\]]+)\]/', $full_elementor_mcp_line, $full_elementor_mcp_matches ) ) {
		// Save previous version block.
		if ( null !== $full_elementor_mcp_current_ver ) {
			$full_elementor_mcp_versions[] = array(
				'version' => $full_elementor_mcp_current_ver,
				'items'   => $full_elementor_mcp_current_items,
			);
		}
		$full_elementor_mcp_current_ver   = $full_elementor_mcp_matches[1];
		$full_elementor_mcp_current_items = array();
		continue;
	}

	// Match list items: - text
	if ( preg_match( '/^- (.+)/', $full_elementor_mcp_line, $full_elementor_mcp_matches ) ) {
		$full_elementor_mcp_current_items[] = $full_elementor_mcp_matches[1];
	}
}

// Save last version block.
if ( null !== $full_elementor_mcp_current_ver ) {
	$full_elementor_mcp_versions[] = array(
		'version' => $full_elementor_mcp_current_ver,
		'items'   => $full_elementor_mcp_current_items,
	);
}
?>

<div class="full-elementor-mcp-changelog">

	<div class="full-elementor-mcp-changelog-intro">
		<h2><?php esc_html_e( 'Changelog', 'full-elementor-mcp' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Version history for Safe Elementor MCP. See what changed in each release.', 'full-elementor-mcp' ); ?>
		</p>
	</div>

	<div class="full-elementor-mcp-changelog-list">
		<?php foreach ( $full_elementor_mcp_versions as $full_elementor_mcp_entry ) : ?>
			<div class="full-elementor-mcp-changelog-version <?php echo ( $full_elementor_mcp_entry === reset( $full_elementor_mcp_versions ) ) ? 'is-latest' : ''; ?>">
				<div class="full-elementor-mcp-changelog-version-header">
					<h3>
						<?php
						/* translators: %s: version number */
						printf( esc_html__( 'Version %s', 'full-elementor-mcp' ), esc_html( $full_elementor_mcp_entry['version'] ) );
						?>
					</h3>
					<?php if ( $full_elementor_mcp_entry === reset( $full_elementor_mcp_versions ) ) : ?>
						<span class="full-elementor-mcp-changelog-badge"><?php esc_html_e( 'Latest', 'full-elementor-mcp' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $full_elementor_mcp_entry['items'] ) ) : ?>
					<ul class="full-elementor-mcp-changelog-items">
						<?php foreach ( $full_elementor_mcp_entry['items'] as $full_elementor_mcp_item ) : ?>
							<li>
								<?php
								$full_elementor_mcp_escaped = esc_html( $full_elementor_mcp_item );
								// Highlight prefixes: New:, Fix:, Improved:
								$full_elementor_mcp_escaped = preg_replace(
									'/^(New:|Fix:|Improved:|Total)/',
									'<strong>$1</strong>',
									$full_elementor_mcp_escaped
								);
								// Highlight inline code: `text`
								$full_elementor_mcp_escaped = preg_replace(
									'/`([^`]+)`/',
									'<code>$1</code>',
									$full_elementor_mcp_escaped
								);
								echo wp_kses( $full_elementor_mcp_escaped, array(
									'strong' => array(),
									'code'   => array(),
								) );
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

</div>
