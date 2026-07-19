<?php
/**
 * License check — stub implementation.
 *
 * Real key-validation wired in a future sprint. Until then is_pro() returns
 * true so all Pro features are accessible during development.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gatekeeper for Pro features. Currently a stub; real key validation and a
 * grace period will be wired in a later licensing sprint.
 */
class SPA_License {

	/**
	 * Whether the site has an active Pro license.
	 *
	 * Stub: always returns true. Replace with a real key-check API call
	 * once the licensing sprint ships.
	 */
	public static function is_pro(): bool {
		return true;
	}

	/**
	 * URL of the in-plugin Pro page (Settings → Announcer for SportsPress → Pro tab).
	 *
	 * Every upgrade link in the plugin must point here rather than at an
	 * external site, so the links resolve even before the Pro checkout exists.
	 *
	 * @param string $context Optional link source, recorded as a spa_ref query arg.
	 * @return string
	 */
	public static function upgrade_url( string $context = '' ): string {
		$url = admin_url( 'options-general.php?page=announcer-for-sportspress&tab=pro' );
		if ( '' !== $context ) {
			$url = add_query_arg( 'spa_ref', $context, $url );
		}
		return $url;
	}
}
