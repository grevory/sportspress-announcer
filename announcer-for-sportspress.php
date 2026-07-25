<?php
/**
 * Plugin Name: Announcer for SportsPress
 * Plugin URI:  https://github.com/grevory/sportspress-announcer
 * Description: Automatically posts game results from SportsPress to Discord and other chat platforms, and shows the latest results and upcoming games on your site via shortcode or block.
 * Version:     0.1.0
 * Author:      Greg Pike
 * Author URI:  https://github.com/grevory
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: announcer-for-sportspress
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

require_once SPA_PLUGIN_DIR . 'includes/class-spa-log.php';
require_once SPA_PLUGIN_DIR . 'includes/class-spa-message-formatter.php';
require_once SPA_PLUGIN_DIR . 'includes/class-spa-webhook-discord.php';
require_once SPA_PLUGIN_DIR . 'includes/class-spa-webhook-slack.php';
require_once SPA_PLUGIN_DIR . 'includes/class-spa-event-handler.php';
require_once SPA_PLUGIN_DIR . 'includes/class-spa-shortcode.php';
require_once SPA_PLUGIN_DIR . 'includes/class-spa-announcement-block.php';
require_once SPA_PLUGIN_DIR . 'includes/licensing/class-spa-license.php';
require_once SPA_PLUGIN_DIR . 'includes/digest/class-spa-digest-builder.php';
require_once SPA_PLUGIN_DIR . 'includes/digest/class-spa-digest-formatter.php';
require_once SPA_PLUGIN_DIR . 'includes/digest/class-spa-daily-digest-scheduler.php';
require_once SPA_PLUGIN_DIR . 'includes/digest/class-spa-weekly-digest-scheduler.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-pro-tab.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-settings.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-facebook-notice.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-upcoming-notice.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-upcoming-discord.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-upcoming-slack.php';
require_once SPA_PLUGIN_DIR . 'admin/class-spa-team-color.php';

/**
 * Render the admin notice shown when SportsPress is not active.
 *
 * @return void
 */
function spa_render_sportspress_missing_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-warning">
		<p><?php esc_html_e( 'Announcer for SportsPress requires the SportsPress plugin to be installed and active. Announcements are paused until it is.', 'announcer-for-sportspress' ); ?></p>
	</div>
	<?php
}

/**
 * Initialize the plugin services.
 *
 * @return void
 */
function spa_init(): void {
	if ( ! class_exists( 'SportsPress' ) ) {
		add_action( 'admin_notices', 'spa_render_sportspress_missing_notice' );
		return;
	}
	if ( is_admin() ) {
		new SPA_Settings();
		new SPA_Facebook_Notice();
		new SPA_Upcoming_Notice();
		new SPA_Upcoming_Discord();
		new SPA_Upcoming_Slack();
		new SPA_Team_Color();
	}
	new SPA_Event_Handler();
	new SPA_Shortcode();
	new SPA_Announcement_Block();
	new SPA_Daily_Digest_Scheduler();
	new SPA_Weekly_Digest_Scheduler();
}
add_action( 'plugins_loaded', 'spa_init' );

register_deactivation_hook( __FILE__, array( 'SPA_Daily_Digest_Scheduler', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'SPA_Weekly_Digest_Scheduler', 'deactivate' ) );
