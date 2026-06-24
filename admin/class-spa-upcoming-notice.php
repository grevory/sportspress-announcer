<?php
/**
 * Admin notice showing a digest of upcoming games with copy/share buttons.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays an admin notice containing the upcoming game schedule.
 */
class SPA_Upcoming_Notice {

	private const USER_META_DISMISSED = 'spa_upcoming_notice_dismissed_at';
	private const ACTION_DISMISS      = 'spa_dismiss_upcoming_notice';
	private const SUPPRESS_HOURS      = 24;

	/**
	 * Register notice and dismissal callbacks.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Render the upcoming-games notice for authorized users.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$dismissed_at = (int) get_user_meta( get_current_user_id(), self::USER_META_DISMISSED, true );
		if ( $dismissed_at > 0 && ( time() - $dismissed_at ) < ( self::SUPPRESS_HOURS * HOUR_IN_SECONDS ) ) {
			return;
		}

		$games = $this->get_upcoming_games();
		if ( empty( $games ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ),
			self::ACTION_DISMISS
		);

		$by_date = array();
		foreach ( $games as $g ) {
			$by_date[ $g['date'] ][] = $g;
		}
		ksort( $by_date );

		$copy_parts = array();
		foreach ( $by_date as $date => $group ) {
			$copy_parts[] = $date;
			foreach ( $group as $g ) {
				$line = $g['label'];
				if ( $g['time'] ) {
					$line .= ' (' . $g['time'] . ')';
				}
				if ( $g['venue'] ) {
					$line .= ' @ ' . $g['venue'];
				}
				$copy_parts[] = $line;
			}
		}
		$copy_text = implode( "\n", $copy_parts );
		?>
		<div class="notice notice-info is-dismissible spa-upcoming-notice">
			<p><strong><?php esc_html_e( 'SportsPress Announcer - Upcoming Games', 'sportspress-announcer' ); ?></strong></p>
			<?php foreach ( $by_date as $date => $group ) : ?>
				<p style="margin: 4px 0 2px; font-weight:600;"><?php echo esc_html( $date ); ?></p>
				<ul style="margin: 0 0 8px 0; padding-left: 1.5em; list-style: disc;">
					<?php foreach ( $group as $game ) : ?>
						<li>
							<?php echo esc_html( $game['label'] ); ?>
							<?php if ( $game['time'] ) : ?>
								<span style="color:#666;">(<?php echo esc_html( $game['time'] ); ?>)</span>
							<?php endif; ?>
							<?php if ( $game['venue'] ) : ?>
								<span style="color:#666;">@ <?php echo esc_html( $game['venue'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
			<p style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
				<button
					type="button"
					class="button"
					data-spa-copy="<?php echo esc_attr( $copy_text ); ?>"
				><?php esc_html_e( 'Copy schedule', 'sportspress-announcer' ); ?></button>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button"><?php esc_html_e( 'Dismiss', 'sportspress-announcer' ); ?></a>
				<span class="spa-copy-feedback" style="display:none; color:#3c763d;"><?php esc_html_e( 'Copied!', 'sportspress-announcer' ); ?></span>
			</p>
		</div>
		<script>
		( function () {
			document.querySelectorAll( '[data-spa-copy]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var text     = btn.getAttribute( 'data-spa-copy' );
					var feedback = btn.parentElement.querySelector( '.spa-copy-feedback' );
					var notice   = btn.closest( '.notice' );
					navigator.clipboard.writeText( text ).then( function () {
						if ( feedback ) {
							feedback.style.display = 'inline';
						}
						setTimeout( function () {
							if ( notice ) { notice.style.display = 'none'; }
						}, 1500 );
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Dismiss the upcoming-games notice for the current user.
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		check_admin_referer( self::ACTION_DISMISS );

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sportspress-announcer' ) );
		}

		update_user_meta( get_current_user_id(), self::USER_META_DISMISSED, time() );

		$referer  = wp_get_referer();
		$redirect = $referer ? $referer : admin_url();
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Returns upcoming sp_event posts scheduled within the next 7 days.
	 *
	 * @return array<int, array{id: int, date: string, time: string, venue: string, label: string}>
	 */
	public function get_upcoming_games(): array {
		$now = current_datetime();
		$end = $now->modify( '+7 days' );

		$args = array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'publish', 'future' ),
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'date_query'     => array(
				array(
					'after'     => $now->format( 'Y-m-d H:i:s' ),
					'before'    => $end->format( 'Y-m-d H:i:s' ),
					'inclusive' => true,
				),
			),
		);

		$query = new WP_Query( $args );
		$games = array();

		foreach ( $query->posts as $post ) {
			$post_id = (int) $post->ID;
			$date    = date_i18n( 'l, F j Y', strtotime( $post->post_date ) );
			$time    = $this->get_event_time( $post_id );
			$venue   = $this->get_event_venue( $post_id );
			$games[] = array(
				'id'    => $post_id,
				'date'  => $date,
				'time'  => $time,
				'venue' => $venue,
				'label' => $this->format_label( $post_id, $post->post_title, $date, $time, $venue ),
			);
		}

		return $games;
	}

	/**
	 * Format an upcoming-game label using the configured template.
	 *
	 * @param int    $post_id Event post ID.
	 * @param string $fallback Fallback event title.
	 * @param string $date Event date.
	 * @param string $time Event time.
	 * @param string $venue Event venue.
	 *
	 * @return string
	 */
	private function format_label( int $post_id, string $fallback, string $date, string $time, string $venue ): string {
		$template = get_option( SPA_Settings::OPTION_UPCOMING_TEMPLATE, SPA_Settings::DEFAULT_UPCOMING_TEMPLATE );

		$team_ids = get_post_meta( $post_id, 'sp_team', false );
		if ( empty( $team_ids ) || count( $team_ids ) < 2 ) {
			return $fallback;
		}

		$home_id    = (int) $team_ids[0];
		$away_id    = (int) $team_ids[1];
		$home_title = get_the_title( $home_id );
		$away_title = get_the_title( $away_id );
		$home       = wp_specialchars_decode( $home_title ? $home_title : __( 'Home', 'sportspress-announcer' ), ENT_QUOTES );
		$away       = wp_specialchars_decode( $away_title ? $away_title : __( 'Away', 'sportspress-announcer' ), ENT_QUOTES );

		$leagues     = wp_get_post_terms( $post_id, 'sp_league', array( 'fields' => 'names' ) );
		$competition = ( ! is_wp_error( $leagues ) && ! empty( $leagues ) ) ? $leagues[0] : '';

		$placeholders = array(
			'{home}'        => $home,
			'{away}'        => $away,
			'{competition}' => $competition,
			'{venue}'       => $venue,
			'{time}'        => $time,
			'{date}'        => $date,
		);

		return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $template );
	}

	/**
	 * Get the SportsPress event time.
	 *
	 * @param int $post_id Event post ID.
	 *
	 * @return string
	 */
	private function get_event_time( int $post_id ): string {
		$time = get_post_meta( $post_id, 'sp_time', true );
		return is_string( $time ) ? trim( $time ) : '';
	}

	/**
	 * Get the first venue assigned to an event.
	 *
	 * @param int $post_id Event post ID.
	 *
	 * @return string
	 */
	private function get_event_venue( int $post_id ): string {
		$venues = wp_get_post_terms( $post_id, 'sp_venue', array( 'fields' => 'names' ) );
		return ( ! is_wp_error( $venues ) && ! empty( $venues ) ) ? $venues[0] : '';
	}
}
