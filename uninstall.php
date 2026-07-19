<?php
/**
 * Uninstall cleanup for Announcer for SportsPress.
 *
 * Runs only when the plugin is deleted from wp-admin. Removes all plugin
 * options (including dynamically league-keyed ones), user meta, and any
 * scheduled cron events.
 *
 * @package SportsPress_Announcer
 */

// Exit if uninstall is not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Fixed option keys created by the plugin.
 *
 * Note: `spa_section_*`, `spa_settings_group`, and `*_nonce` are not stored
 * options, so they are intentionally omitted here.
 */
$spa_options = array(
	// Discord.
	'spa_discord_webhook_url',
	'spa_discord_enabled',
	'spa_discord_channel_map',
	'spa_discord_webhook_error',
	// Slack.
	'spa_slack_webhook_url',
	'spa_slack_enabled',
	'spa_slack_channel_map',
	'spa_slack_webhook_error',
	// Score column + templates.
	'spa_score_column',
	'spa_result_template',
	'spa_upcoming_template',
	'spa_facebook_template',
	'spa_facebook_enabled',
	'spa_upcoming_publish',
	// Daily (upcoming-games) digest.
	'spa_digest_schedule_enabled',
	'spa_digest_frequency',
	'spa_digest_day',
	'spa_digest_time',
	'spa_last_digest_sent',
	// Weekly (results recap) digest.
	'spa_weekly_digest_enabled',
	'spa_weekly_digest_day',
	'spa_weekly_digest_time',
	'spa_weekly_digest_leagues',
	'spa_weekly_digest_include_results',
	'spa_weekly_digest_include_standings',
	'spa_weekly_digest_include_leaders',
	'spa_weekly_digest_include_upcoming',
	'spa_weekly_digest_publish_as_post',
	'spa_weekly_digest_stat_keys',
	// Runtime state.
	'spa_sent_log',
	'spa_last_score_hash',
);

foreach ( $spa_options as $spa_option ) {
	delete_option( $spa_option );
}

global $wpdb;

// League-keyed options: spa_digest_standings_snapshot_{id} and spa_digest_last_sent_{id}.
$spa_like_prefixes = array(
	$wpdb->esc_like( 'spa_digest_standings_snapshot_' ) . '%',
	$wpdb->esc_like( 'spa_digest_last_sent_' ) . '%',
);
foreach ( $spa_like_prefixes as $spa_like ) {
	// Direct query is unavoidable to enumerate dynamically-keyed options on
	// uninstall; caching is irrelevant since the plugin is being deleted.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$spa_names = $wpdb->get_col(
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $spa_like )
	);
	foreach ( (array) $spa_names as $spa_name ) {
		delete_option( $spa_name );
	}
}

// Per-user dismissal / quick-start meta.
$spa_user_meta = array(
	'spa_qs_dismissed',
	'spa_facebook_notice_dismissed_at',
	'spa_upcoming_notice_dismissed_at',
	'spa_weekly_upsell_dismissed',
);
foreach ( $spa_user_meta as $spa_meta_key ) {
	delete_metadata( 'user', 0, $spa_meta_key, '', true );
}

// Clear any scheduled cron events.
wp_clear_scheduled_hook( 'spa_digest_send' );
wp_clear_scheduled_hook( 'spa_weekly_digest' );
