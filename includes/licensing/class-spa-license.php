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
}
