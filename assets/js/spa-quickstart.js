/**
 * Quick Start checklist behavior for the Announcer for SportsPress settings page.
 *
 * Expects a localized `SPA_QS` global: { ajaxurl, nonce }.
 *
 * @package SportsPress_Announcer
 */
( function () {
	var cfg = window.SPA_QS || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		function spaQsSetUI( li, done ) {
			var icon = li.querySelector( '.spa-qs-icon' );
			if ( done ) {
				li.classList.add( 'is-done' );
				if ( icon ) { icon.textContent = '✓'; }
			} else {
				li.classList.remove( 'is-done' );
				if ( icon ) { icon.textContent = '○'; }
			}
		}

		function spaQsMark( item, done ) {
			var li = document.querySelector( '.spa-qs-item[data-item="' + item + '"]' );
			if ( ! li ) { return; }
			spaQsSetUI( li, done );
			var fd = new FormData();
			fd.append( 'action', 'spa_qs_dismiss' );
			fd.append( 'nonce', cfg.nonce );
			fd.append( 'item', item );
			fd.append( 'checked', done ? '1' : '0' );
			fetch( cfg.ajaxurl, { method: 'POST', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( ! json.success ) {
						// Revert optimistic UI and log.
						spaQsSetUI( li, ! done );
						window.console && console.warn( 'SPA QS save failed', json );
					}
				} )
				.catch( function ( err ) {
					spaQsSetUI( li, ! done );
					window.console && console.warn( 'SPA QS request failed', err );
				} );
		}

		// Click to toggle any unchecked (or checked) item.
		document.querySelectorAll( '.spa-qs-item' ).forEach( function ( li ) {
			li.addEventListener( 'click', function () {
				var isDone = li.classList.contains( 'is-done' );
				spaQsMark( li.dataset.item, ! isDone );
			} );
		} );

		// Auto-check "tested" when a Discord or Slack test message succeeds.
		function observeTestSuccess( resultElId ) {
			var el = document.getElementById( resultElId );
			if ( ! el ) { return; }
			var obs = new MutationObserver( function () {
				if ( el.style.color === 'rgb(70, 180, 80)' || el.style.color === '#46b450' ) {
					spaQsMark( 'tested', true );
				}
			} );
			obs.observe( el, { attributes: true, childList: true, subtree: true } );
		}
		observeTestSuccess( 'spa-test-result' );
		observeTestSuccess( 'spa-test-slack-result' );

		// Auto-check "published" when the digest publish succeeds.
		var publishResult = document.getElementById( 'spa-publish-result' );
		if ( publishResult ) {
			new MutationObserver( function () {
				if ( publishResult.style.color === 'rgb(70, 180, 80)' || publishResult.style.color === '#46b450' ) {
					spaQsMark( 'published', true );
				}
			} ).observe( publishResult, { attributes: true, childList: true, subtree: true } );
		}
	} );
}() );
