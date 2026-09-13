<?php
/**
 * Shared Real WordPress & Real MySQL Bootstrap Helper for Test Harnesses.
 *
 * Locates authentic WordPress installation, establishes real MySQL database connection,
 * boots WordPress core, sets fail-closed error handling, and loads safety subsystem.
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( ! function_exists( 'bootstrap_real_wordpress' ) ) {
	/**
	 * Bootstraps the authentic WordPress environment connected to real MySQL.
	 *
	 * @return void
	 */
	function bootstrap_real_wordpress(): void {
		if ( defined( 'ABSPATH' ) && isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb ) {
			return;
		}

		$candidates = array();
		if ( getenv( 'WP_PATH' ) ) {
			$candidates[] = rtrim( getenv( 'WP_PATH' ), '/\\' );
		}
		$candidates[] = 'D:/Vino/Work/Software/Website/Laravel/wordpress-ai';
		$candidates[] = 'C:/tmp/wordpress';
		$candidates[] = '/tmp/wordpress';

		$wp_dir = null;
		foreach ( $candidates as $c ) {
			if ( $c && is_dir( $c ) && file_exists( $c . '/wp-settings.php' ) ) {
				$wp_dir = $c;
				break;
			}
		}

		if ( ! $wp_dir ) {
			fwrite( STDERR, "FATAL: Real WordPress core not found. Please set WP_PATH=/path/to/wordpress\n" );
			exit( 1 );
		}

		$db_host = getenv( 'DB_HOST' ) ?: '127.0.0.1';
		$db_port = getenv( 'DB_PORT' ) ?: ( getenv( 'MYSQL_PORT' ) ?: null );
		$db_name = getenv( 'DB_NAME' ) ?: ( getenv( 'MYSQL_DATABASE' ) ?: 'safe_elementor_test' );
		$db_user = getenv( 'DB_USER' ) ?: ( getenv( 'MYSQL_USER' ) ?: 'root' );
		$db_pass = getenv( 'DB_PASSWORD' ) !== false ? (string) getenv( 'DB_PASSWORD' ) : ( getenv( 'MYSQL_PWD' ) !== false ? (string) getenv( 'MYSQL_PWD' ) : null );

		if ( ! $db_port || null === $db_pass ) {
			$pdo_candidates = array(
				array( 'host' => $db_host, 'port' => 3306, 'user' => 'root', 'pass' => 'root' ),
				array( 'host' => $db_host, 'port' => 3306, 'user' => 'root', 'pass' => '' ),
				array( 'host' => $db_host, 'port' => 3307, 'user' => 'root', 'pass' => 'mysql' ),
				array( 'host' => $db_host, 'port' => 3306, 'user' => 'root', 'pass' => 'mariadb' ),
				array( 'host' => $db_host, 'port' => 3307, 'user' => 'root', 'pass' => 'root' ),
			);
			foreach ( $pdo_candidates as $pc ) {
				try {
					$test_pdo = new PDO( "mysql:host={$pc['host']};port={$pc['port']}", $pc['user'], $pc['pass'], array(
						PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					) );
					$test_pdo->exec( "CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
					$db_port = $pc['port'];
					$db_user = $pc['user'];
					$db_pass = $pc['pass'];
					break;
				} catch ( Exception $e ) {
					continue;
				}
			}
		}

		try {
			$init_pdo = new PDO( "mysql:host={$db_host};port={$db_port};dbname={$db_name}", $db_user, (string) $db_pass, array(
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			) );
			$init_pdo->exec( "CREATE TABLE IF NOT EXISTS `wp_options` (
				`option_id` bigint(20) unsigned NOT NULL auto_increment,
				`option_name` varchar(191) NOT NULL default '',
				`option_value` longtext NOT NULL,
				`autoload` varchar(20) NOT NULL default 'yes',
				PRIMARY KEY (`option_id`),
				UNIQUE KEY `option_name` (`option_name`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
			$init_pdo->exec( "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES ('siteurl', 'http://localhost'), ('home', 'http://localhost') ON DUPLICATE KEY UPDATE `option_name` = `option_name`" );
		} catch ( Exception $e ) {
			// continue with bootstrap
		}

		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', false );
		}
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}
		if ( ! defined( 'WP_INSTALLING' ) ) {
			define( 'WP_INSTALLING', true );
		}
		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', $db_name );
		}
		if ( ! defined( 'DB_USER' ) ) {
			define( 'DB_USER', $db_user );
		}
		if ( ! defined( 'DB_PASSWORD' ) ) {
			define( 'DB_PASSWORD', (string) $db_pass );
		}
		if ( ! defined( 'DB_HOST' ) ) {
			define( 'DB_HOST', $db_host . ( $db_port ? ':' . $db_port : '' ) );
		}
		if ( ! defined( 'DB_CHARSET' ) ) {
			define( 'DB_CHARSET', 'utf8mb4' );
		}
		if ( ! defined( 'DB_COLLATE' ) ) {
			define( 'DB_COLLATE', '' );
		}
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'safe-elementor-auth-key-test-32ch!' );
			define( 'SECURE_AUTH_KEY', 'safe-elementor-sec-key-test-32ch!' );
			define( 'LOGGED_IN_KEY', 'safe-elementor-log-key-test-32ch!' );
			define( 'NONCE_KEY', 'safe-elementor-non-key-test-32ch!' );
			define( 'AUTH_SALT', 'safe-elementor-salt-key-test-1!' );
			define( 'SECURE_AUTH_SALT', 'safe-elementor-salt-key-test-2!' );
			define( 'LOGGED_IN_SALT', 'safe-elementor-salt-key-test-3!' );
			define( 'NONCE_SALT', 'safe-elementor-salt-key-test-4!' );
		}

		global $table_prefix;
		$table_prefix = 'wp_';
		$GLOBALS['table_prefix'] = 'wp_';

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', rtrim( $wp_dir, '/\\' ) . '/' );
		}

		$_SERVER['HTTP_HOST']   = 'localhost';
		$_SERVER['SERVER_NAME'] = 'localhost';
		$_SERVER['REQUEST_URI'] = '/';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		require_once ABSPATH . 'wp-settings.php';
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$has_posts = $GLOBALS['wpdb']->get_var( "SHOW TABLES LIKE '{$GLOBALS['wpdb']->prefix}posts'" );
		if ( ! $has_posts ) {
			wp_install( 'Safe Elementor Test', 'admin', 'admin@example.com', true, '', 'adminpass123' );
		}

		// Setup test administrator user and capabilities
		wp_set_current_user( 1 );
		add_filter( 'user_has_cap', function ( $caps ) {
			$caps['read']                 = true;
			$caps['edit_posts']           = true;
			$caps['edit_pages']           = true;
			$caps['edit_others_posts']    = true;
			$caps['edit_published_posts'] = true;
			$caps['manage_options']       = true;
			return $caps;
		} );

		// Fail-closed wp_die handler: prevent silent test exits.
		add_filter( 'wp_die_handler', function () {
			return function ( $message, $title = '', $args = array() ) {
				$err_msg = is_wp_error( $message ) ? $message->get_error_message() : (string) $message;
				fwrite( STDERR, "FATAL wp_die encountered during test: {$title} - {$err_msg}\n" );
				exit( 1 );
			};
		} );
	}
}

if ( ! function_exists( 'bootstrap_safety_subsystem' ) ) {
	/**
	 * Loads all production safety subsystem classes and ensures schema is installed.
	 *
	 * @return void
	 */
	function bootstrap_safety_subsystem(): void {
		$inc_dir = dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR;
		require_once $inc_dir . 'class-compatibility-checker.php';
		require_once $inc_dir . 'class-elementor-data.php';
		require_once $inc_dir . 'safety/class-database-installer.php';
		require_once $inc_dir . 'safety/class-safety-settings.php';
		require_once $inc_dir . 'safety/class-lock-manager.php';
		require_once $inc_dir . 'safety/class-security-guard.php';
		require_once $inc_dir . 'safety/class-elementor-features.php';
		require_once $inc_dir . 'safety/class-tree-validator.php';
		require_once $inc_dir . 'safety/class-security-strategies.php';
		require_once $inc_dir . 'safety/class-mutation-registry.php';
		require_once $inc_dir . 'safety/class-journal.php';
		require_once $inc_dir . 'safety/class-mutation-context.php';
		require_once $inc_dir . 'safety/class-safe-writes.php';
		require_once $inc_dir . 'safety/class-confirmation-manager.php';
		require_once $inc_dir . 'safety/class-idempotency-manager.php';
		require_once $inc_dir . 'safety/class-checkpoint-crypto.php';
		require_once $inc_dir . 'safety/class-checkpoint-strategies.php';
		require_once $inc_dir . 'safety/class-checkpoint-manager.php';
		require_once $inc_dir . 'safety/class-audit-logger.php';
		require_once $inc_dir . 'safety/class-undo-manager.php';
		require_once $inc_dir . 'safety/class-mutation-middleware.php';

		Full_Elementor_MCP_Mutation_Registry::init_core_strategies();
		Full_Elementor_MCP_Checkpoint_Manager::ensure_restore_strategy_registered();
		Full_Elementor_MCP_Database_Installer::install();
	}
}
