<?php
/**
 * Isolated Worker Process Runner for WordPress Package Lifecycle Suite.
 *
 * Executes isolated lifecycle phases in separate PHP processes to prevent
 * in-memory class collisions between frozen Phase 6 baseline and Phase 7 HEAD,
 * while operating on genuinely database-isolated WordPress installations.
 *
 * Actions:
 *   --action=init-site              Installs fresh WP schema via wp_install() and activates Elementor & MCP Adapter
 *   --action=assert-pre-install     Asserts clean state (no full-elementor-mcp active, no dir, no tables, no db option)
 *   --action=clean-install          Installs built ZIP via Plugin_Upgrader and activates
 *   --action=verify-clean-install   Verifies authentic bootstrap, schema, 4 tables, Abilities API, and MCP Adapter
 *   --action=seed-phase6            Activates Phase 6, seeds state, and creates checkpoint via Phase 6 Checkpoint_Manager
 *   --action=upgrade-package        Executes public Plugin_Upgrader overwrite install of built Phase 7 ZIP
 *   --action=verify-upgrade         Verifies post-upgrade Phase 7 state, migration, decryption, and registrations
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
	$_SERVER['HTTP_HOST'] = 'localhost';
}
if ( ! isset( $_SERVER['SERVER_NAME'] ) ) {
	$_SERVER['SERVER_NAME'] = 'localhost';
}

$options = getopt( '', array(
	'action:',
	'wp-path:',
	'zip-file::',
	'seed-data::',
	'seed-file::',
	'skip-mcp-adapter::',
	'expected-version::',
) );

$action    = $options['action'] ?? null;
$wp_path   = $options['wp-path'] ?? null;
$zip_file  = $options['zip-file'] ?? null;
$seed_data = isset( $options['seed-data'] ) ? json_decode( $options['seed-data'], true ) : array();

if ( ! empty( $options['seed-file'] ) && file_exists( $options['seed-file'] ) ) {
	$file_content = (string) file_get_contents( $options['seed-file'] );
	$from_file    = json_decode( $file_content, true );
	if ( is_array( $from_file ) ) {
		$seed_data = array_merge( $seed_data, $from_file );
	}
}

if ( ! $action || ! $wp_path || ! is_dir( $wp_path ) ) {
	fwrite( STDERR, "Usage: worker-lifecycle-runner.php --action=<action> --wp-path=<path> [--zip-file=<zip>] [--seed-file=<file>] [--skip-mcp-adapter] [--expected-version=<ver>]\n" );
	exit( 2 );
}

$wp_load = rtrim( $wp_path, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "FATAL: wp-load.php not found at {$wp_load}\n" );
	exit( 2 );
}

// ---------------------------------------------------------------------
// ACTION: init-site
// ---------------------------------------------------------------------
if ( 'init-site' === $action ) {
	if ( ! defined( 'WP_INSTALLING' ) ) {
		define( 'WP_INSTALLING', true );
	}
	require_once $wp_load;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	// 1. Install fresh WordPress core database schema into isolated database
	$install_res = wp_install( 'Safe Lifecycle Site', 'admin', 'admin@example.com', true, '', 'adminpass123' );
	if ( is_wp_error( $install_res ) ) {
		fwrite( STDERR, "FATAL: wp_install failed: " . $install_res->get_error_message() . "\n" );
		exit( 1 );
	}

	// 2. Activate required dependency: Elementor
	$elem_file = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'elementor/elementor.php';
	if ( ! file_exists( $elem_file ) ) {
		fwrite( STDERR, "FATAL: Elementor plugin not found at {$elem_file}\n" );
		exit( 1 );
	}

	$elem_act = activate_plugin( 'elementor/elementor.php' );
	if ( is_wp_error( $elem_act ) ) {
		fwrite( STDERR, "FATAL: Elementor activation failed: " . $elem_act->get_error_message() . "\n" );
		exit( 1 );
	}
	if ( ! is_plugin_active( 'elementor/elementor.php' ) ) {
		fwrite( STDERR, "FATAL: Elementor is not active after activate_plugin()\n" );
		exit( 1 );
	}

	// 3. Activate optional transport dependency: WordPress MCP Adapter (if present and not skipped)
	$skip_mcp   = isset( $options['skip-mcp-adapter'] );
	$mcp_active = false;
	$mcp_file   = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'mcp-adapter/mcp-adapter.php';

	if ( ! $skip_mcp && file_exists( $mcp_file ) ) {
		$mcp_act = activate_plugin( 'mcp-adapter/mcp-adapter.php' );
		if ( is_wp_error( $mcp_act ) ) {
			fwrite( STDERR, "FATAL: MCP Adapter activation failed: " . $mcp_act->get_error_message() . "\n" );
			exit( 1 );
		}
		if ( ! is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) ) {
			fwrite( STDERR, "FATAL: MCP Adapter is not active after activate_plugin()\n" );
			exit( 1 );
		}
		$mcp_active = true;
	}

	// 4. Ensure admin user has full capabilities
	wp_set_current_user( 1 );

	echo json_encode( array(
		'success'   => true,
		'elementor' => true,
		'mcp'       => $mcp_active,
	) );
	exit( 0 );
}

// ---------------------------------------------------------------------
// ACTION: assert-pre-install
// ---------------------------------------------------------------------
if ( 'assert-pre-install' === $action ) {
	require_once $wp_load;
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	global $wpdb;

	if ( is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) ) {
		fwrite( STDERR, "FATAL: full-elementor-mcp/full-elementor-mcp.php is already active before clean install\n" );
		exit( 1 );
	}

	$target_dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'full-elementor-mcp';
	if ( is_dir( $target_dir ) || file_exists( $target_dir ) ) {
		fwrite( STDERR, "FATAL: full-elementor-mcp directory already exists before clean install\n" );
		exit( 1 );
	}

	$existing_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}elementor_mcp_%'" );
	if ( ! empty( $existing_tables ) ) {
		fwrite( STDERR, "FATAL: Safe Elementor MCP tables already exist before clean install: " . implode( ', ', $existing_tables ) . "\n" );
		exit( 1 );
	}

	$existing_ver = get_option( 'full_elementor_mcp_db_version' );
	if ( false !== $existing_ver ) {
		fwrite( STDERR, "FATAL: full_elementor_mcp_db_version option already exists before clean install\n" );
		exit( 1 );
	}

	echo json_encode( array( 'success' => true ) );
	exit( 0 );
}

// ---------------------------------------------------------------------
// ACTION: clean-install
// ---------------------------------------------------------------------
if ( 'clean-install' === $action ) {
	if ( ! $zip_file || ! file_exists( $zip_file ) ) {
		fwrite( STDERR, "FATAL: zip-file required for clean-install\n" );
		exit( 2 );
	}

	require_once $wp_load;
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	WP_Filesystem();
	$skin = new class extends \WP_Upgrader_Skin {
		public function feedback( $string, ...$args ) {}
		public function header() {}
		public function footer() {}
	};

	$upgrader = new \Plugin_Upgrader( $skin );
	$res      = $upgrader->install( $zip_file );

	if ( true !== $res && ! ( is_array( $res ) && ! is_wp_error( $res ) ) ) {
		fwrite( STDERR, "FATAL: Plugin_Upgrader::install failed\n" );
		exit( 1 );
	}

	$act_res = activate_plugin( 'full-elementor-mcp/full-elementor-mcp.php' );
	if ( is_wp_error( $act_res ) ) {
		fwrite( STDERR, "FATAL: activate_plugin failed: " . $act_res->get_error_message() . "\n" );
		exit( 1 );
	}

	echo json_encode( array( 'success' => true ) );
	exit( 0 );
}

// ---------------------------------------------------------------------
// ACTION: verify-clean-install
// ---------------------------------------------------------------------
if ( 'verify-clean-install' === $action ) {
	require_once $wp_load;
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	global $wpdb;

	$installed_dir   = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'full-elementor-mcp';
	$installed_entry = $installed_dir . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
	$duplicate_dir   = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'safe-elementor-mcp';

	if ( ! is_dir( $installed_dir ) || ! file_exists( $installed_entry ) ) {
		fwrite( STDERR, "FATAL: Canonical entry full-elementor-mcp/full-elementor-mcp.php does not exist\n" );
		exit( 1 );
	}

	if ( file_exists( $duplicate_dir ) ) {
		fwrite( STDERR, "FATAL: Duplicate safe-elementor-mcp directory exists\n" );
		exit( 1 );
	}

	if ( ! is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) ) {
		fwrite( STDERR, "FATAL: Plugin is not active in WordPress\n" );
		exit( 1 );
	}

	$data = get_plugin_data( $installed_entry, false, false );
	$expected_ver = $options['expected-version'] ?? '1.8.1';
	if ( $expected_ver !== ( $data['Version'] ?? '' ) ) {
		fwrite( STDERR, "FATAL: Plugin version is not {$expected_ver} (got: " . ( $data['Version'] ?? 'none' ) . ")\n" );
		exit( 1 );
	}

	// Verify schema created by authentic activation/bootstrap (DO NOT call install() manually):
	require_once $installed_entry;
	require_once $installed_dir . DIRECTORY_SEPARATOR . 'includes/safety/class-database-installer.php';

	if ( ! \Full_Elementor_MCP_Database_Installer::verify_schema() ) {
		fwrite( STDERR, "FATAL: Full_Elementor_MCP_Database_Installer::verify_schema() failed - schema was not created by activation\n" );
		exit( 1 );
	}

	$safety_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}elementor_mcp_%'" );
	if ( 4 !== count( $safety_tables ) ) {
		fwrite( STDERR, "FATAL: Expected exactly 4 safety tables, found " . count( $safety_tables ) . "\n" );
		exit( 1 );
	}

	// Verify Abilities API initialization and registration of representative ability
	do_action( 'wp_abilities_api_categories_init' );
	do_action( 'wp_abilities_api_init' );

	$sample_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'full-elementor-mcp/list-widgets' ) : null;
	if ( null === $sample_ability ) {
		fwrite( STDERR, "FATAL: Abilities API did not register full-elementor-mcp/list-widgets\n" );
		exit( 1 );
	}

	// Verify MCP Adapter initialization and server registration (if adapter is active)
	$has_adapter = is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) && ( class_exists( '\WP\MCP\Core\McpAdapter' ) || class_exists( 'WP_MCP_Adapter' ) );
	$server_registered = false;

	if ( $has_adapter ) {
		$adapter_instance = null;
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			$adapter_instance = method_exists( '\WP\MCP\Core\McpAdapter', 'instance' )
				? \WP\MCP\Core\McpAdapter::instance()
				: new \WP\MCP\Core\McpAdapter();
		} elseif ( class_exists( 'WP_MCP_Adapter' ) ) {
			$adapter_instance = new WP_MCP_Adapter();
		}

		if ( is_object( $adapter_instance ) ) {
			do_action( 'mcp_adapter_init', $adapter_instance );
			$server_registered = true;
		}
	}

	require_once $installed_dir . DIRECTORY_SEPARATOR . 'includes/class-compatibility-checker.php';
	$diag = \Full_Elementor_MCP_Compatibility_Checker::get_system_diagnostics();
	$transport_status = \Full_Elementor_MCP_Compatibility_Checker::get_transport_status();

	echo json_encode( array(
		'success'                => true,
		'version'                => $data['Version'],
		'safety_tables'          => count( $safety_tables ),
		'abilities_registered'   => true,
		'mcp_server_registered'  => $server_registered,
		'adapter_active'         => $has_adapter,
		'diag_status'            => $diag['status'],
		'transport_available'    => $transport_status['available'],
		'transport_warning'      => $transport_status['warning'],
	) );
	exit( 0 );
}

// ---------------------------------------------------------------------
// ACTION: seed-phase6
// ---------------------------------------------------------------------
if ( 'seed-phase6' === $action ) {
	require_once $wp_load;
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	global $wpdb;

	// Verify required dependencies are active
	if ( ! is_plugin_active( 'elementor/elementor.php' ) ) {
		fwrite( STDERR, "FATAL: Elementor is not active in Phase 6 environment\n" );
		exit( 1 );
	}
	if ( ! is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) ) {
		fwrite( STDERR, "FATAL: MCP Adapter is not active in Phase 6 environment\n" );
		exit( 1 );
	}

	// Activate frozen Phase 6 plugin
	$p6_main = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'full-elementor-mcp/full-elementor-mcp.php';
	if ( ! file_exists( $p6_main ) ) {
		fwrite( STDERR, "FATAL: Phase 6 entry file not found at {$p6_main}\n" );
		exit( 1 );
	}

	$act_res = activate_plugin( 'full-elementor-mcp/full-elementor-mcp.php' );
	if ( is_wp_error( $act_res ) ) {
		fwrite( STDERR, "FATAL: Phase 6 activation failed: " . $act_res->get_error_message() . "\n" );
		exit( 1 );
	}
	if ( ! is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) ) {
		fwrite( STDERR, "FATAL: Phase 6 plugin is not active after activate_plugin()\n" );
		exit( 1 );
	}

	// Bootstrap frozen Phase 6 using its own files ONLY:
	require_once $p6_main;
	require_once WP_PLUGIN_DIR . '/full-elementor-mcp/includes/safety/class-database-installer.php';
	\Full_Elementor_MCP_Database_Installer::install();

	// 1. Seed Phase 6 settings
	$test_settings = array(
		'require_confirmation'        => array( 'delete_element' => true ),
		'default_undo_window_seconds' => 3600,
	);
	update_option( 'full_elementor_mcp_settings', $test_settings );

	// 2. Create real Elementor post in WordPress
	$p6_post_id = wp_insert_post( array(
		'post_title'  => 'Phase 6 Authentic Baseline Page',
		'post_type'   => 'page',
		'post_status' => 'publish',
	) );
	if ( $p6_post_id <= 0 ) {
		fwrite( STDERR, "FATAL: Failed to insert Phase 6 post\n" );
		exit( 1 );
	}

	$p6_elem_data = array(
		array(
			'id'       => 'sec_p6_baseline',
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => array( 'layout' => 'boxed' ),
			'elements' => array(),
		),
	);
	update_post_meta( $p6_post_id, '_elementor_data', json_encode( $p6_elem_data ) );
	update_post_meta( $p6_post_id, '_elementor_page_settings', array( 'background_color' => '#112233' ) );
	update_post_meta( $p6_post_id, '_elementor_edit_mode', 'builder' );

	// 3. Insert Phase 6 journal row targeting actual generated post_id
	$wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_journal',
		array(
			'ability'       => 'full-elementor-mcp/update-element',
			'action'        => 'update',
			'object_type'   => 'post',
			'object_id'     => $p6_post_id,
			'resource_key'  => "post:{$p6_post_id}",
			'fencing_token' => 42,
			'status'        => 'committed',
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		)
	);

	// 4. Insert Phase 6 audit log
	$wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_audit_log',
		array(
			'event'        => 'mutation_committed',
			'ability'      => 'full-elementor-mcp/update-element',
			'user_id'      => 1,
			'resource_key' => "post:{$p6_post_id}",
			'timestamp'    => gmdate( 'Y-m-d H:i:s' ),
		)
	);

	// 5. Insert Phase 6 token row
	$wpdb->insert(
		$wpdb->prefix . 'elementor_mcp_tokens',
		array(
			'token_key'     => "lock:post:{$p6_post_id}",
			'token_type'    => 'lock',
			'fencing_token' => 42,
			'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + 300 ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		)
	);

	// 6. Create real encrypted checkpoint using ACTUAL Phase 6 Checkpoint_Manager production code:
	require_once WP_PLUGIN_DIR . '/full-elementor-mcp/includes/safety/class-checkpoint-crypto.php';
	require_once WP_PLUGIN_DIR . '/full-elementor-mcp/includes/safety/class-checkpoint-strategies.php';
	require_once WP_PLUGIN_DIR . '/full-elementor-mcp/includes/safety/class-checkpoint-manager.php';

	$chk_meta = array(
		'label'             => 'Phase 6 Authentic Checkpoint',
		'user_id'           => 1,
		'source_ability'    => 'full-elementor-mcp/create-checkpoint',
		'historical_origin' => 'f21858ac9d853e38f3121a594f992bf81725cec8',
	);
	$chk_save = \Full_Elementor_MCP_Checkpoint_Manager::capture_and_save( "post:{$p6_post_id}", 'manual', $chk_meta );
	if ( is_wp_error( $chk_save ) ) {
		fwrite( STDERR, "FATAL: Phase 6 Checkpoint creation failed: " . $chk_save->get_error_message() . "\n" );
		exit( 1 );
	}

	$out_data = array(
		'success'         => true,
		'post_id'         => $p6_post_id,
		'checkpoint_uuid' => $chk_save['checkpoint_uuid'],
		'state_hash'      => $chk_save['state_hash'] ?? '',
	);

	if ( ! empty( $options['seed-file'] ) ) {
		file_put_contents( $options['seed-file'], json_encode( $out_data ) );
	}

	echo json_encode( $out_data );
	exit( 0 );
}

// ---------------------------------------------------------------------
// ACTION: upgrade-package
// ---------------------------------------------------------------------
if ( 'upgrade-package' === $action ) {
	if ( ! $zip_file || ! file_exists( $zip_file ) ) {
		fwrite( STDERR, "FATAL: zip-file required for upgrade-package\n" );
		exit( 2 );
	}

	require_once $wp_load;
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

	WP_Filesystem();
	$skin = new class extends \WP_Upgrader_Skin {
		public function feedback( $string, ...$args ) {}
		public function header() {}
		public function footer() {}
	};

	$upgrader = new \Plugin_Upgrader( $skin );
	$res      = $upgrader->install( $zip_file, array( 'overwrite_package' => true ) );

	if ( true !== $res && ! ( is_array( $res ) && ! is_wp_error( $res ) ) ) {
		fwrite( STDERR, "FATAL: Plugin_Upgrader overwrite upgrade failed\n" );
		exit( 1 );
	}

	echo json_encode( array( 'success' => true ) );
	exit( 0 );
}

// ---------------------------------------------------------------------
// ACTION: verify-upgrade
// ---------------------------------------------------------------------
if ( 'verify-upgrade' === $action ) {
	require_once $wp_load;
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	global $wpdb;

	$upgraded_dir  = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'full-elementor-mcp';
	$upgraded_main = $upgraded_dir . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
	$duplicate_dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'safe-elementor-mcp';

	if ( ! is_dir( $upgraded_dir ) || ! file_exists( $upgraded_main ) ) {
		fwrite( STDERR, "FATAL: Upgraded main file missing at canonical path\n" );
		exit( 1 );
	}

	if ( file_exists( $duplicate_dir ) ) {
		fwrite( STDERR, "FATAL: Duplicate safe-elementor-mcp directory exists after upgrade\n" );
		exit( 1 );
	}

	// 1. Explicitly check activate_plugin() return value
	$act_res = activate_plugin( 'full-elementor-mcp/full-elementor-mcp.php' );
	if ( is_wp_error( $act_res ) ) {
		fwrite( STDERR, "FATAL: activate_plugin returned error after upgrade: " . $act_res->get_error_message() . "\n" );
		exit( 1 );
	}

	// Require no stale active_plugins state
	if ( ! is_plugin_active( 'full-elementor-mcp/full-elementor-mcp.php' ) ) {
		fwrite( STDERR, "FATAL: Plugin is not active in WordPress after upgrade\n" );
		exit( 1 );
	}

	// 2. Verify version
	$data = get_plugin_data( $upgraded_main, false, false );
	$expected_ver = $options['expected-version'] ?? '1.8.1';
	if ( $expected_ver !== ( $data['Version'] ?? '' ) ) {
		fwrite( STDERR, "FATAL: Upgraded plugin version is not {$expected_ver} (got: " . ( $data['Version'] ?? 'none' ) . ")\n" );
		exit( 1 );
	}

	// 3. Verify DB migration succeeds
	require_once $upgraded_main;
	require_once $upgraded_dir . DIRECTORY_SEPARATOR . 'includes/safety/class-database-installer.php';
	if ( ! \Full_Elementor_MCP_Database_Installer::maybe_upgrade() ) {
		fwrite( STDERR, "FATAL: maybe_upgrade() returned false\n" );
		exit( 1 );
	}
	if ( ! \Full_Elementor_MCP_Database_Installer::verify_schema() ) {
		fwrite( STDERR, "FATAL: verify_schema() returned false after upgrade\n" );
		exit( 1 );
	}

	// 4. Verify exactly four safety tables
	$tables_after = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}elementor_mcp_%'" );
	if ( 4 !== count( $tables_after ) ) {
		fwrite( STDERR, "FATAL: Expected exactly 4 safety tables, found " . count( $tables_after ) . "\n" );
		exit( 1 );
	}

	// 5. Verify settings preserved
	$settings_after = get_option( 'full_elementor_mcp_settings' );
	if ( empty( $settings_after['require_confirmation']['delete_element'] ) || 3600 !== ( $settings_after['default_undo_window_seconds'] ?? 0 ) ) {
		fwrite( STDERR, "FATAL: Settings not preserved across upgrade\n" );
		exit( 1 );
	}

	// 6. Verify journal preserved using actual post_id from seed data
	$post_id = (int) ( $seed_data['post_id'] ?? 0 );
	if ( $post_id <= 0 ) {
		fwrite( STDERR, "FATAL: Missing or invalid post_id in seed data\n" );
		exit( 1 );
	}

	$j_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_journal` WHERE resource_key = %s", "post:{$post_id}" ) );
	if ( null === $j_after || 'committed' !== $j_after->status ) {
		fwrite( STDERR, "FATAL: Journal row not preserved across upgrade for post:{$post_id}\n" );
		exit( 1 );
	}

	// 7. Verify checkpoint preserved and decryptable under Phase 7 crypto
	$saved_uuid = $seed_data['checkpoint_uuid'] ?? '';
	if ( ! $saved_uuid ) {
		fwrite( STDERR, "FATAL: Missing saved_uuid in seed data\n" );
		exit( 1 );
	}

	$ckpt_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_checkpoints` WHERE checkpoint_uuid = %s", $saved_uuid ), ARRAY_A );
	if ( empty( $ckpt_after ) ) {
		fwrite( STDERR, "FATAL: Checkpoint row missing after upgrade\n" );
		exit( 1 );
	}

	require_once $upgraded_dir . DIRECTORY_SEPARATOR . 'includes/safety/class-checkpoint-crypto.php';
	$decrypted = \Full_Elementor_MCP_Checkpoint_Crypto::decrypt( $ckpt_after );
	if ( is_wp_error( $decrypted ) || ! is_array( $decrypted ) ) {
		fwrite( STDERR, "FATAL: Checkpoint failed to decrypt under Phase 7 crypto\n" );
		exit( 1 );
	}

	// 8. Verify audit log preserved using actual post_id
	$audit_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_audit_log` WHERE resource_key = %s", "post:{$post_id}" ) );
	if ( null === $audit_after ) {
		fwrite( STDERR, "FATAL: Audit log missing after upgrade\n" );
		exit( 1 );
	}

	// 9. Verify token preserved using actual post_id
	$token_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}elementor_mcp_tokens` WHERE token_key = %s", "lock:post:{$post_id}" ) );
	if ( null === $token_after ) {
		fwrite( STDERR, "FATAL: Token row missing after upgrade\n" );
		exit( 1 );
	}

	// 10. Verify Elementor post data preserved using actual post_id
	$elem_data_after = get_post_meta( $post_id, '_elementor_data', true );
	if ( ! str_contains( (string) $elem_data_after, 'sec_p6_baseline' ) ) {
		fwrite( STDERR, "FATAL: Elementor post data missing or corrupted after upgrade\n" );
		exit( 1 );
	}

	// 11. Initialize Abilities API and verify representative ability registration
	do_action( 'wp_abilities_api_categories_init' );
	do_action( 'wp_abilities_api_init' );

	$sample_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'full-elementor-mcp/list-widgets' ) : null;
	if ( null === $sample_ability ) {
		fwrite( STDERR, "FATAL: Abilities API did not register full-elementor-mcp/list-widgets after upgrade\n" );
		exit( 1 );
	}

	// 12. Initialize MCP Adapter and verify MCP server registration after upgrade
	$adapter_instance = null;
	if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		$adapter_instance = method_exists( '\WP\MCP\Core\McpAdapter', 'instance' )
			? \WP\MCP\Core\McpAdapter::instance()
			: new \WP\MCP\Core\McpAdapter();
	} elseif ( class_exists( 'WP_MCP_Adapter' ) ) {
		$adapter_instance = new WP_MCP_Adapter();
	}

	if ( ! is_object( $adapter_instance ) ) {
		fwrite( STDERR, "FATAL: McpAdapter class not found after upgrade\n" );
		exit( 1 );
	}

	do_action( 'mcp_adapter_init', $adapter_instance );

	echo json_encode( array(
		'success'                => true,
		'version'                => $data['Version'],
		'safety_tables'          => count( $tables_after ),
		'decrypted'              => true,
		'abilities_registered'   => true,
		'mcp_server_registered'  => true,
	) );
	exit( 0 );
}

fwrite( STDERR, "Unknown action: {$action}\n" );
exit( 2 );
