<?php
/**
 * Registers the [spa_announcement] shortcode for embedding a league recap
 * into any WordPress post or page.
 *
 * FREE — reuses the digest builder and HTML formatter that already power the
 * wp-admin preview, so the front-end embed never diverges from the digest.
 *
 * The matching Gutenberg block (see SPA_Announcement_Block) renders through the
 * same render_recap() method, so shortcode and block output stay identical.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a league recap on the front end via the [spa_announcement] shortcode.
 */
class SPA_Shortcode {

	/**
	 * Shortcode tag.
	 */
	private const TAG = 'spa_announcement';

	/**
	 * Register the shortcode.
	 */
	public function __construct() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * Supported attributes:
	 *   league — league term ID or slug (required).
	 *   days   — days of history to include (default 7).
	 *
	 * @param array|string $atts Raw shortcode attributes.
	 * @return string Escaped HTML, or '' when nothing can be shown.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'league' => '',
				'days'   => 7,
			),
			$atts,
			self::TAG
		);

		return self::render_recap( self::resolve_league_id( $atts['league'] ), intval( $atts['days'] ) );
	}

	/**
	 * Build and format a league recap. Shared by the shortcode and the block.
	 *
	 * @param int $league_id League term ID (0 when unresolved).
	 * @param int $days       Days of history to include.
	 * @return string Escaped HTML, or '' when nothing can be shown.
	 */
	public static function render_recap( int $league_id, int $days ): string {
		if ( ! class_exists( 'SportsPress' ) || $league_id <= 0 ) {
			return '';
		}

		$options                    = SPA_Digest_Builder::options_from_settings();
		$options['date_range_days'] = max( 1, $days );

		$data = ( new SPA_Digest_Builder( $league_id, $options ) )->build();

		if ( ! empty( $data['is_empty'] ) ) {
			return '';
		}

		return ( new SPA_Digest_Formatter( $data ) )->to_html();
	}

	/**
	 * Resolve a league attribute (numeric term ID or slug) to a term ID.
	 *
	 * @param string $league Raw league attribute.
	 * @return int Term ID, or 0 when it cannot be resolved.
	 */
	public static function resolve_league_id( string $league ): int {
		$league = trim( $league );
		if ( '' === $league ) {
			return 0;
		}

		if ( ctype_digit( $league ) ) {
			return (int) $league;
		}

		$term = get_term_by( 'slug', sanitize_title( $league ), 'sp_league' );

		return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
	}
}
