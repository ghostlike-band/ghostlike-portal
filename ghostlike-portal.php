<?php
/**
 * Plugin Name: GHOSTLIKE Member-Portal
 * Plugin URI:  https://github.com/ghostlike-band/ghostlike-portal
 * Description: Internes Portal für die Band unter /portal: Übersicht, Kalender mit Proben und Zusagen, Studio-Tool mit Aufnahmestand. Updates per Klick über GitHub Releases.
 * Version:     0.9.4
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author:      GHOSTLIKE
 * License:     Proprietary
 * Text Domain: ghostlike-portal
 */

defined( 'ABSPATH' ) || exit;

define( 'GLP_VERSION', '0.9.4' );
define( 'GLP_FILE', __FILE__ );
define( 'GLP_DIR', plugin_dir_path( __FILE__ ) );
define( 'GLP_URL', plugin_dir_url( __FILE__ ) );
define( 'GLP_DB_VERSION', 7 );

// GitHub-Repo für Updates. Kann in wp-config.php überschrieben werden.
if ( ! defined( 'GLP_GITHUB_REPO' ) ) {
	define( 'GLP_GITHUB_REPO', 'ghostlike-band/ghostlike-portal' );
}

require GLP_DIR . 'inc/install.php';
require GLP_DIR . 'inc/data.php';
require GLP_DIR . 'inc/rest.php';
require GLP_DIR . 'inc/frontend.php';
require GLP_DIR . 'inc/admin.php';
require GLP_DIR . 'inc/members.php';
require GLP_DIR . 'inc/audio.php';
require GLP_DIR . 'inc/notify.php';
require GLP_DIR . 'inc/history.php';
require GLP_DIR . 'inc/cash.php';
require GLP_DIR . 'inc/updater.php';

register_activation_hook( __FILE__, 'glp_activate' );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

// Datenbank nach Plugin-Updates automatisch anpassen.
add_action( 'plugins_loaded', function () {
	if ( (int) get_option( 'glp_db_version' ) < GLP_DB_VERSION ) {
		glp_install();
	}
} );
