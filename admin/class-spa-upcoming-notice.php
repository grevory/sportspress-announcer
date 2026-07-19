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

		$by_date   = $this->group_by_date( $games );
		$copy_text = $this->build_copy_text( $by_date );
		?>
		<div class="notice notice-info is-dismissible spa-upcoming-notice">
			<p><strong><?php esc_html_e( 'Announcer for SportsPress - Upcoming Games', 'announcer-for-sportspress' ); ?></strong></p>
			<?php $this->render_event_list( $by_date ); ?>
			<?php $this->render_action_buttons( $copy_text, $dismiss_url ); ?>
			<?php $this->render_upsell(); ?>
		</div>
		<?php
		$this->render_inline_script();
	}

	/**
	 * Group games by their display date, sorted ascending.
	 *
	 * @param array[] $games Games from get_upcoming_games().
	 * @return array<string, array[]>
	 */
	private function group_by_date( array $games ): array {
		$by_date = array();
		foreach ( $games as $g ) {
			$by_date[ $g['date'] ][] = $g;
		}
		ksort( $by_date );
		return $by_date;
	}

	/**
	 * Build the plain-text schedule used by the "Copy schedule" button.
	 *
	 * @param array<string, array[]> $by_date Games grouped by date.
	 * @return string
	 */
	private function build_copy_text( array $by_date ): string {
		$parts = array();
		foreach ( $by_date as $date => $group ) {
			$parts[] = $date;
			foreach ( $group as $g ) {
				$line = $g['label'];
				if ( $g['time'] ) {
					$line .= ' (' . $g['time'] . ')';
				}
				if ( $g['venue'] ) {
					$line .= ' @ ' . $g['venue'];
				}
				$parts[] = $line;
			}
		}
		return implode( "\n", $parts );
	}

	/**
	 * Render the copy / send / dismiss action buttons row.
	 *
	 * @param string $copy_text   Plain-text schedule for the copy button.
	 * @param string $dismiss_url Nonce'd dismissal URL.
	 * @return void
	 */
	private function render_action_buttons( string $copy_text, string $dismiss_url ): void {
		?>
		<p style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
			<button
				type="button"
				class="button"
				data-spa-copy="<?php echo esc_attr( $copy_text ); ?>"
			><?php esc_html_e( 'Copy schedule', 'announcer-for-sportspress' ); ?></button>
			<?php if ( get_option( 'spa_discord_webhook_url', '' ) ) : ?>
			<button
				type="button"
				class="button spa-send-upcoming-btn"
				data-action="spa_send_upcoming"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'spa_send_upcoming_nonce' ) ); ?>"
			><?php esc_html_e( 'Send to Discord', 'announcer-for-sportspress' ); ?></button>
			<?php endif; ?>
			<?php if ( get_option( SPA_Settings::OPTION_SLACK_WEBHOOK, '' ) ) : ?>
			<button
				type="button"
				class="button spa-send-upcoming-btn"
				data-action="spa_send_upcoming_slack"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'spa_send_upcoming_slack_nonce' ) ); ?>"
			><?php esc_html_e( 'Send to Slack', 'announcer-for-sportspress' ); ?></button>
			<?php endif; ?>
			<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button"><?php esc_html_e( 'Dismiss', 'announcer-for-sportspress' ); ?></a>
			<span class="spa-send-feedback" style="display:none;"></span>
			<span class="spa-copy-feedback" style="display:none; color:#3c763d;"><?php esc_html_e( 'Copied!', 'announcer-for-sportspress' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Render a setup hint pointing at the Digest tab when Slack isn't configured.
	 *
	 * @return void
	 */
	private function render_upsell(): void {
		if ( get_option( SPA_Settings::OPTION_SLACK_WEBHOOK, '' ) ) {
			return;
		}
		?>
		<p style="color:#666; font-size:13px; margin:0 0 8px;">
			<?php
			printf(
				wp_kses(
					/* translators: %s: settings page URL */
					__( 'Want this sent to Discord or Slack automatically on a schedule? <a href="%s">Set up auto-send →</a>', 'announcer-for-sportspress' ),
					array(
						'a' => array( 'href' => array() ),
					)
				),
				esc_url( admin_url( 'options-general.php?page=announcer-for-sportspress&tab=digest' ) )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the grouped list of games inside the notice.
	 *
	 * @param array<string, array[]> $by_date Games grouped by date.
	 * @return void
	 */
	private function render_event_list( array $by_date ): void {
		foreach ( $by_date as $date => $group ) {
			printf(
				'<p style="margin: 4px 0 2px; font-weight:600;">%s</p>',
				esc_html( $date )
			);
			echo '<ul style="margin: 0 0 8px 0; padding-left: 1.5em; list-style: disc;">';
			foreach ( $group as $game ) {
				$this->render_event_item( $game );
			}
			echo '</ul>';
		}
	}

	/**
	 * Render a single game as a list item.
	 *
	 * @param array $game One game row.
	 * @return void
	 */
	private function render_event_item( array $game ): void {
		echo '<li>' . esc_html( $game['label'] );
		if ( $game['time'] ) {
			echo ' <span style="color:#666;">(' . esc_html( $game['time'] ) . ')</span>';
		}
		if ( $game['venue'] ) {
			echo ' <span style="color:#666;">@ ' . esc_html( $game['venue'] ) . '</span>';
		}
		echo '</li>';
	}

	/**
	 * Output the copy / send-to-channel inline script for the notice.
	 *
	 * @return void
	 */
	private function render_inline_script(): void {
		?>
		<script>
		( function () {
			<?php $this->copy_button_js(); ?>
			<?php $this->send_button_js(); ?>
		} )();
		</script>
		<?php
	}

	/**
	 * JS wiring for the copy-to-clipboard button.
	 *
	 * @return void
	 */
	private function copy_button_js(): void {
		?>
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
		<?php
	}

	/**
	 * JS wiring for the send-to-Discord/Slack buttons.
	 *
	 * @return void
	 */
	private function send_button_js(): void {
		?>
			document.querySelectorAll( '.spa-send-upcoming-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var feedback = btn.parentElement.querySelector( '.spa-send-feedback' );
					btn.disabled = true;
					if ( feedback ) {
						feedback.style.display = 'inline';
						feedback.style.color   = '';
						feedback.textContent   = '<?php echo esc_js( __( 'Sending…', 'announcer-for-sportspress' ) ); ?>';
					}
					var data = new FormData();
					data.append( 'action', btn.getAttribute( 'data-action' ) );
					data.append( 'nonce',  btn.getAttribute( 'data-nonce' ) );
					fetch( ajaxurl, { method: 'POST', body: data } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( json ) {
							if ( json.success ) {
								if ( feedback ) {
									feedback.textContent = '<?php echo esc_js( __( '✓ Sent!', 'announcer-for-sportspress' ) ); ?>';
									feedback.style.color = '#3c763d';
									feedback.style.display = 'inline';
								}
								setTimeout( function () {
									var notice = btn.closest( '.notice' );
									if ( notice ) { notice.style.display = 'none'; }
									fetch( '<?php echo esc_js( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ), self::ACTION_DISMISS ) ); ?>', { method: 'GET', redirect: 'manual' } );
								}, 1500 );
							} else {
								if ( feedback ) {
									feedback.textContent = '✗ ' + ( json.data || '<?php echo esc_js( __( 'Error', 'announcer-for-sportspress' ) ); ?>' );
									feedback.style.color = '#a94442';
									feedback.style.display = 'inline';
								}
								btn.disabled = false;
							}
						} )
						.catch( function () {
							if ( feedback ) {
								feedback.textContent = '<?php echo esc_js( __( '✗ Request failed.', 'announcer-for-sportspress' ) ); ?>';
								feedback.style.color = '#a94442';
								feedback.style.display = 'inline';
							}
							btn.disabled = false;
						} );
				} );
			} );
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
			wp_die( esc_html__( 'You do not have permission to do this.', 'announcer-for-sportspress' ) );
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
		$home       = wp_specialchars_decode( $home_title ? $home_title : __( 'Home', 'announcer-for-sportspress' ), ENT_QUOTES );
		$away       = wp_specialchars_decode( $away_title ? $away_title : __( 'Away', 'announcer-for-sportspress' ), ENT_QUOTES );

		$leagues     = wp_get_post_terms( $post_id, 'sp_league', array( 'fields' => 'names' ) );
		$competition = ( ! is_wp_error( $leagues ) && ! empty( $leagues ) ) ? $leagues[0] : '';

		$placeholders = array(
			'{home}'        => $home,
			'{away}'        => $away,
			'{competition}' => $competition,
			'{venue}'       => $venue,
			'{time}'        => $time,
			'{date}'        => $date,
			'{event_url}'   => (string) get_permalink( $post_id ),
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
