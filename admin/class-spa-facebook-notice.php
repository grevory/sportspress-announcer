<?php
/**
 * Admin notice showing a digest of recent results with Facebook share buttons.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPA_Facebook_Notice {

	private const USER_META_DISMISSED = 'spa_facebook_notice_dismissed_at';
	private const ACTION_DISMISS      = 'spa_dismiss_facebook_notice';

	public function __construct() {
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'admin_post_' . self::ACTION_DISMISS, [ $this, 'handle_dismiss' ] );
	}

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

		$dismiss_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ),
			self::ACTION_DISMISS
		);

		$by_date = [];
		foreach ( $events as $e ) {
			$by_date[ $e['date'] ][] = $e;
		}
		ksort( $by_date );

		$digest_parts = [];
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
			$by_date_display = [];
			foreach ( $events as $e ) {
				$by_date_display[ $e['date'] ][] = $e;
			}
			ksort( $by_date_display );
			foreach ( $by_date_display as $date => $group ) : ?>
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
					navigator.clipboard.writeText( text ).then( function () {
						if ( feedback ) {
							feedback.style.display = 'inline';
							setTimeout( function () { feedback.style.display = 'none'; }, 2500 );
						}
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}

	public function handle_dismiss(): void {
		check_admin_referer( self::ACTION_DISMISS );

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sportspress-announcer' ) );
		}

		update_user_meta( get_current_user_id(), self::USER_META_DISMISSED, time() );

		$redirect = wp_get_referer() ?: admin_url();
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

		$args = [
			'post_type'      => 'sp_event',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];

		if ( $dismissed_at > 0 ) {
			$args['date_query'] = [
				[
					'after'     => gmdate( 'Y-m-d H:i:s', $dismissed_at ),
					'inclusive' => false,
				],
			];
		}

		$query  = new WP_Query( $args );
		$events = [];

		foreach ( $query->posts as $post ) {
			$post_id  = (int) $post->ID;
			$events[] = [
				'id'    => $post_id,
				'date'  => date_i18n( 'l, F j Y', strtotime( $post->post_date ) ),
				'time'  => $this->get_event_time( $post_id ),
				'venue' => $this->get_event_venue( $post_id ),
				'label' => $this->build_label( $post_id, $post->post_title ),
			];
		}

		return $events;
	}

	/**
	 * Builds a human-readable result label (e.g. "Home 2 – 1 Away").
	 * Falls back to the post title if score data is unavailable.
	 */
	private function build_label( int $post_id, string $fallback ): string {
		$team_ids = get_post_meta( $post_id, 'sp_team', false );
		if ( empty( $team_ids ) || count( $team_ids ) < 2 ) {
			return $fallback;
		}

		$home_id = (int) $team_ids[0];
		$away_id = (int) $team_ids[1];
		$home    = get_the_title( $home_id ) ?: __( 'Home', 'sportspress-announcer' );
		$away    = get_the_title( $away_id ) ?: __( 'Away', 'sportspress-announcer' );

		$results    = get_post_meta( $post_id, 'sp_results', true );
		$home_score = '';
		$away_score = '';

		if ( is_array( $results ) ) {
			$home_score = $results[ $home_id ]['goals'] ?? ( $results[ $home_id ]['outcome'] ?? '' );
			$away_score = $results[ $away_id ]['goals'] ?? ( $results[ $away_id ]['outcome'] ?? '' );
		}

		if ( '' !== $home_score && '' !== $away_score ) {
			return sprintf(
				'%s %s – %s %s',
				wp_specialchars_decode( $home, ENT_QUOTES ),
				$home_score,
				$away_score,
				wp_specialchars_decode( $away, ENT_QUOTES )
			);
		}

		return $fallback;
	}

	private function get_event_time( int $post_id ): string {
		$time = get_post_meta( $post_id, 'sp_time', true );
		return is_string( $time ) ? trim( $time ) : '';
	}

	private function get_event_venue( int $post_id ): string {
		$venues = wp_get_post_terms( $post_id, 'sp_venue', [ 'fields' => 'names' ] );
		return ( ! is_wp_error( $venues ) && ! empty( $venues ) ) ? $venues[0] : '';
	}

	private function facebook_share_url(): string {
		return 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( home_url() );
	}
}
