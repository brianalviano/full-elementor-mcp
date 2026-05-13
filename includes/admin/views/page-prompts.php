<?php
/**
 * Prompts tab view for the Full Elementor MCP admin settings page.
 *
 * Displays sample landing page prompts with one-click copy.
 *
 * @package Full_Elementor_MCP
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prompt metadata: filename (without .md) => title, industry tag, description.
 */
$full_elementor_mcp_prompt_meta = array(
	'LOCAL_BUSINESS'          => array(
		'title'       => __( 'Local Business', 'full-elementor-mcp' ),
		'industry'    => __( 'General', 'full-elementor-mcp' ),
		'description' => __( 'Multi-purpose small business landing page with hero, services, testimonials, and contact section.', 'full-elementor-mcp' ),
	),
	'DENTAL_CLINIC'           => array(
		'title'       => __( 'Dental Clinic', 'full-elementor-mcp' ),
		'industry'    => __( 'Health & Wellness', 'full-elementor-mcp' ),
		'description' => __( 'Professional dental practice with services grid, team profiles, insurance info, and appointment booking.', 'full-elementor-mcp' ),
	),
	'WEB_DEVELOPER_PORTFOLIO' => array(
		'title'       => __( 'Web Developer Portfolio', 'full-elementor-mcp' ),
		'industry'    => __( 'Professional Services', 'full-elementor-mcp' ),
		'description' => __( 'Developer portfolio with project showcase, tech stack, GitHub stats, and contact form.', 'full-elementor-mcp' ),
	),
	'HAIR_SALON'              => array(
		'title'       => __( 'Hair Salon', 'full-elementor-mcp' ),
		'industry'    => __( 'Lifestyle', 'full-elementor-mcp' ),
		'description' => __( 'Stylish salon page with services menu, stylist profiles, gallery, and online booking.', 'full-elementor-mcp' ),
	),
	'CAR_WASH'                => array(
		'title'       => __( 'Car Wash', 'full-elementor-mcp' ),
		'industry'    => __( 'Lifestyle', 'full-elementor-mcp' ),
		'description' => __( 'Car wash site with wash packages, add-on services, membership plans, and booking form.', 'full-elementor-mcp' ),
	),
);

$full_elementor_mcp_prompts_dir = FULL_ELEMENTOR_MCP_DIR . 'prompts/';
?>

<div class="full-elementor-mcp-prompts">

	<div class="full-elementor-mcp-prompts-intro">
		<h2><?php esc_html_e( 'Sample Prompts', 'full-elementor-mcp' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Ready-to-use landing page blueprints for AI agents. Copy any prompt below and paste it into your AI client (Claude, Cursor, etc.) — it will automatically build a complete Elementor page using MCP tools.', 'full-elementor-mcp' ); ?>
		</p>
	</div>

	<div class="full-elementor-mcp-prompts-grid">
		<?php foreach ( $full_elementor_mcp_prompt_meta as $full_elementor_mcp_slug => $full_elementor_mcp_meta ) :
			$full_elementor_mcp_file_path = $full_elementor_mcp_prompts_dir . $full_elementor_mcp_slug . '.md';
			if ( ! file_exists( $full_elementor_mcp_file_path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file.
			$full_elementor_mcp_content = file_get_contents( $full_elementor_mcp_file_path );
			$full_elementor_mcp_copy_id = 'full-elementor-mcp-prompt-' . sanitize_title( $full_elementor_mcp_slug );
		?>
			<div class="full-elementor-mcp-prompt-card">
				<div class="full-elementor-mcp-prompt-header">
					<h3 class="full-elementor-mcp-prompt-title"><?php echo esc_html( $full_elementor_mcp_meta['title'] ); ?></h3>
					<span class="full-elementor-mcp-prompt-tag"><?php echo esc_html( $full_elementor_mcp_meta['industry'] ); ?></span>
				</div>
				<p class="full-elementor-mcp-prompt-desc"><?php echo esc_html( $full_elementor_mcp_meta['description'] ); ?></p>
				<div class="full-elementor-mcp-prompt-actions">
					<button type="button" class="button full-elementor-mcp-copy-btn" data-target="<?php echo esc_attr( $full_elementor_mcp_copy_id ); ?>">
						<svg viewBox="0 0 20 20" width="14" height="14" xmlns="http://www.w3.org/2000/svg"><path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/><path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z"/></svg>
						<?php esc_html_e( 'Copy Prompt', 'full-elementor-mcp' ); ?>
					</button>
				</div>
				<textarea id="<?php echo esc_attr( $full_elementor_mcp_copy_id ); ?>" class="full-elementor-mcp-copy-source"><?php echo esc_textarea( $full_elementor_mcp_content ); ?></textarea>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="full-elementor-mcp-prompts-cta">
		<div class="full-elementor-mcp-prompts-cta-content">
			<h3><?php esc_html_e( 'Want More Prompts?', 'full-elementor-mcp' ); ?></h3>
			<p><?php esc_html_e( 'Get 50 industry-specific landing page prompts — restaurants, med spas, law firms, florists, photography studios, and more.', 'full-elementor-mcp' ); ?></p>
			<a href="https://wpacademy.gumroad.com/l/vlrihk" class="button button-primary full-elementor-mcp-prompts-cta-btn" target="_blank" rel="noopener noreferrer">
				<svg viewBox="0 0 20 20" width="16" height="16" xmlns="http://www.w3.org/2000/svg"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
				<?php esc_html_e( 'Get Premium Prompts', 'full-elementor-mcp' ); ?>
			</a>
		</div>
	</div>

</div>
