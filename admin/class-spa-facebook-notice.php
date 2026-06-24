<?php
/**
 * Admin notice showing a digest of recent results with Facebook share buttons.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays recent SportsPress results with Facebook sharing controls.
 */
class SPA_Facebook_Notice {

	private const USER_META_DISMISSED = 'spa_facebook_notice_dismissed_at';
	private const ACTION_DISMISS      = 'spa_dismiss_facebook_notice';

	/**
	 * Register notice and dismissal callbacks.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Render the recent-results notice for authorized users.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! (bool) get_option( SPA_Settings::OPTION_FACEBOOK_ENABLED, false ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$events = $this->get_events_since_last_dismiss();
		if ( empty( $events ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ),
			self::ACTION_DISMISS
		);

		$by_date = array();
		foreach ( $events as $e ) {
			$by_date[ $e['date'] ][] = $e;
		}
		ksort( $by_date );

		$digest_parts = array();
		foreach ( $by_date as $date => $group ) {
			$digest_parts[] = $date;
			foreach ( $group as $e ) {
				$line = $e['label'];
				if ( $e['time'] ) {
					$line .= ' (' . $e['time'] . ')';
				}
				if ( $e['venue'] ) {
					$line .= ' @ ' . $e['venue'];
				}
				$digest_parts[] = $line;
			}
		}
		$digest_text = implode( "\n", $digest_parts );
		?>
		<div class="notice notice-info is-dismissible spa-facebook-notice">
			<p><strong><?php esc_html_e( 'SportsPress Announcer — Recent Results', 'sportspress-announcer' ); ?></strong></p>
			<?php
			$by_date_display = array();
			foreach ( $events as $e ) {
				$by_date_display[ $e['date'] ][] = $e;
			}
			ksort( $by_date_display );
			foreach ( $by_date_display as $date => $group ) :
				?>
				<p style="margin: 4px 0 2px; font-weight:600;"><?php echo esc_html( $date ); ?></p>
				<ul style="margin: 0 0 8px 0; padding-left: 1.5em; list-style: disc;">
					<?php foreach ( $group as $event ) : ?>
						<li>
							<?php echo esc_html( $event['label'] ); ?>
							<?php if ( $event['time'] ) : ?>
								<span style="color:#666;">(<?php echo esc_html( $event['time'] ); ?>)</span>
							<?php endif; ?>
							<?php if ( $event['venue'] ) : ?>
								<span style="color:#666;">@ <?php echo esc_html( $event['venue'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
			<p style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
				<button
					type="button"
					class="button"
					data-spa-copy="<?php echo esc_attr( $digest_text ); ?>"
				><?php esc_html_e( 'Copy results', 'sportspress-announcer' ); ?></button>
				<a
					href="<?php echo esc_url( $this->facebook_share_url() ); ?>"
					target="_blank"
					rel="noopener noreferrer"
					class="button button-primary"
				><?php esc_html_e( 'Share to Facebook', 'sportspress-announcer' ); ?></a>
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

			document.querySelectorAll( '.spa-facebook-notice .button-primary' ).forEach( function ( link ) {
				link.addEventListener( 'click', function () {
					var notice = link.closest( '.notice' );
					setTimeout( function () {
						if ( notice ) { notice.style.display = 'none'; }
					}, 300 );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Dismiss the recent-results notice for the current user.
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
	 * Returns sp_event posts published after the current user last dismissed this notice.
	 *
	 * @return array<int, array{id: int, label: string}>
	 */
	private function get_events_since_last_dismiss(): array {
		$dismissed_at = (int) get_user_meta( get_current_user_id(), self::USER_META_DISMISSED, true );

		$args = array(
			'post_type'      => 'sp_event',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( $dismissed_at > 0 ) {
			$args['date_query'] = array(
				array(
					'after'     => gmdate( 'Y-m-d H:i:s', $dismissed_at ),
					'inclusive' => false,
				),
			);
		}

		$query  = new WP_Query( $args );
		$events = array();

		foreach ( $query->posts as $post ) {
			$post_id  = (int) $post->ID;
			$date     = date_i18n( 'l, F j Y', strtotime( $post->post_date ) );
			$time     = $this->get_event_time( $post_id );
			$venue    = $this->get_event_venue( $post_id );
			$events[] = array(
				'id'    => $post_id,
				'date'  => $date,
				'time'  => $time,
				'venue' => $venue,
				'label' => $this->format_label( $post_id, $post->post_title, $date, $time, $venue ),
			);
		}

		return $events;
	}

	/**
	 * Format a result label using the configured Facebook template.
	 *
	 * @param int    $post_id  Event post ID.
	 * @param string $fallback Fallback event title.
	 * @param string $date     Event date.
	 * @param string $time     Event time.
	 * @param string $venue    Event venue.
	 *
	 * @return string
	 */
	private function format_label( int $post_id, string $fallback, string $date, string $time, string $venue ): string {
		$template = get_option( SPA_Settings::OPTION_FACEBOOK_TEMPLATE, SPA_Settings::DEFAULT_FACEBOOK_TEMPLATE );

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

		$results    = get_post_meta( $post_id, 'sp_results', true );
		$home_score = '';
		$away_score = '';

		if ( is_array( $results ) ) {
			$home_score = (string) ( $results[ $home_id ]['goals'] ?? ( $results[ $home_id ]['outcome'] ?? '' ) );
			$away_score = (string) ( $results[ $away_id ]['goals'] ?? ( $results[ $away_id ]['outcome'] ?? '' ) );
		}

		$leagues     = wp_get_post_terms( $post_id, 'sp_league', array( 'fields' => 'names' ) );
		$competition = ( ! is_wp_error( $leagues ) && ! empty( $leagues ) ) ? $leagues[0] : '';

		$placeholders = array(
			'{home}'        => $home,
			'{away}'        => $away,
			'{home_score}'  => $home_score,
			'{away_score}'  => $away_score,
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

	/**
	 * Build the Facebook share-dialog URL for the site.
	 *
	 * @return string
	 */
	private function facebook_share_url(): string {
		return 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( home_url() );
	}
}
