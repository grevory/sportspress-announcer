<?php
/**
 * Plugin Name: SportsPress Announcer
 * Plugin URI:  https://github.com/grevory/sportspress-announcer
 * Description: Automatically posts game results from SportsPress to Discord and other chat platforms.
 * Version:     0.1.0
 * Author:      Greg Pike
 * Author URI:  https://github.com/grevory
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sportspress-announcer
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPA_VERSION', '0.1.0' );
define( 'SPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SPA_PLUGIN_DIR . 'includes/class-spa-message-formatter.php';
require_once SPA_PLUGIN_DIR . 'includes/class-spa-webhook-discord.php';
require_once SPA_PLUGIN_DIR . 'includes/class-spa-event-handler.php';
require_once SPA_PLUGIN_DIR . 'includes/class-spa-digest-scheduler.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-settings.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-facebook-notice.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-upcoming-notice.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-upcoming-discord.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-team-color.php';

/**
 * Initialize the plugin services.
 *
 * @return void
 */
function spa_init(): void {
	if ( is_admin() ) {
		new SPA_Settings();
		new SPA_Facebook_Notice();
		new SPA_Upcoming_Notice();
		new SPA_Upcoming_Discord();
		new SPA_Team_Color();
	}
	new SPA_Event_Handler();
	new SPA_Digest_Scheduler();
}
add_action( 'plugins_loaded', 'spa_init' );

register_deactivation_hook( __FILE__, array( 'SPA_Digest_Scheduler', 'deactivate' ) );
