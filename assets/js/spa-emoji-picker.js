/* global document, window */
( function () {
	'use strict';

	// ---------------------------------------------------------------------------
	// Emoji picker
	// ---------------------------------------------------------------------------

	var EMOJIS = [
		// Sports & activities
		'⚽','🏀','🏈','⚾','🎾','🏐','🏉','🎱','🏓','🏸','🥊','🏆','🥇','🥈','🥉','🎯',
		'⛷️','🏂','🏊','🚴','🤸','🤼','🤺','🥋','🏋️','🤾','🏇','🧗','🤿','🎿','🛷','🥌',
		// Celebration & reaction
		'🎉','🎊','🎈','🔥','⚡','💥','✨','🌟','⭐','🏅','🎖️','🎗️','🎀',
		// Faces
		'😀','😃','😄','😁','😆','😂','🤣','😊','😍','🥳','😎','🤩','😤','😡','🤬',
		'👏','🙌','🤜','🤛','✊','👊','🫶','❤️','🧡','💛','💚','💙','💜',
		// Common
		'📢','📣','🔔','🗓️','📅','🕐','🏟️','🎽','👕','👟',
	];

	var PICKER_ID    = 'spa-emoji-picker-popup';
	var activeTarget = null;

	function createPicker() {
		var existing = document.getElementById( PICKER_ID );
		if ( existing ) return existing;

		var popup = document.createElement( 'div' );
		popup.id  = PICKER_ID;
		Object.assign( popup.style, {
			position:            'absolute',
			zIndex:              '99999',
			background:          '#fff',
			border:              '1px solid #dcdcde',
			borderRadius:        '4px',
			boxShadow:           '0 4px 12px rgba(0,0,0,.15)',
			padding:             '8px',
			display:             'grid',
			gridTemplateColumns: 'repeat(10, 28px)',
			gap:                 '2px',
			maxWidth:            '320px',
		} );

		EMOJIS.forEach( function ( emoji ) {
			var btn = document.createElement( 'button' );
			btn.type        = 'button';
			btn.textContent = emoji;
			btn.title       = emoji;
			Object.assign( btn.style, {
				width:        '28px',
				height:       '28px',
				fontSize:     '16px',
				lineHeight:   '1',
				border:       'none',
				background:   'transparent',
				cursor:       'pointer',
				borderRadius: '3px',
				padding:      '0',
			} );
			btn.addEventListener( 'mouseover', function () { btn.style.background = '#f0f0f1'; } );
			btn.addEventListener( 'mouseout',  function () { btn.style.background = 'transparent'; } );
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				insertEmoji( emoji );
				closePicker();
			} );
			popup.appendChild( btn );
		} );

		document.body.appendChild( popup );
		return popup;
	}

	function insertAtCursor( field, text ) {
		var start   = field.selectionStart;
		var end     = field.selectionEnd;
		field.value = field.value.slice( 0, start ) + text + field.value.slice( end );
		var pos     = start + text.length;
		field.setSelectionRange( pos, pos );
		field.focus();
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	function insertEmoji( emoji ) {
		if ( activeTarget ) insertAtCursor( activeTarget, emoji );
	}

	function openPicker( triggerBtn, textarea ) {
		activeTarget     = textarea;
		var popup        = createPicker();
		var rect         = triggerBtn.getBoundingClientRect();
		popup.style.top  = ( rect.bottom + window.scrollY + 4 ) + 'px';
		popup.style.left = ( rect.left   + window.scrollX ) + 'px';
		popup.style.display = 'grid';
	}

	function closePicker() {
		var popup = document.getElementById( PICKER_ID );
		if ( popup ) popup.style.display = 'none';
		activeTarget = null;
	}

	document.addEventListener( 'click', function ( e ) {
		var popup = document.getElementById( PICKER_ID );
		if ( ! popup || popup.style.display === 'none' ) return;
		if ( ! popup.contains( e.target ) && ! e.target.closest( '.spa-emoji-trigger' ) ) {
			closePicker();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) closePicker();
	} );

	// ---------------------------------------------------------------------------
	// Placeholder highlighting
	// ---------------------------------------------------------------------------

	var PLACEHOLDER_RE = /(\{[a-z_]+\})/g;

	// Styles copied onto the backdrop so it mirrors the field exactly.
	var COPY_STYLES = [
		'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'letterSpacing',
		'lineHeight', 'textTransform',
		'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
		'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
		'borderTopStyle', 'borderRightStyle', 'borderBottomStyle', 'borderLeftStyle',
		'boxSizing', 'tabSize', 'whiteSpace', 'wordWrap', 'overflowWrap',
	];

	function escapeHtml( str ) {
		return str
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function buildHighlightHtml( text ) {
		// For <input type="text"> collapse newlines; for textarea keep them.
		return escapeHtml( text ).replace(
			PLACEHOLDER_RE,
			'<mark style="background:#dbeafe;color:inherit;border-radius:3px;padding:0 1px;">$1</mark>'
		);
	}

	function syncBackdrop( field, backdrop ) {
		backdrop.innerHTML = buildHighlightHtml( field.value ) + ' '; // trailing space prevents last-line collapse
		// Sync scroll position (textarea only).
		backdrop.scrollTop  = field.scrollTop;
		backdrop.scrollLeft = field.scrollLeft;
	}

	function attachHighlight( field ) {
		var cs = window.getComputedStyle( field );

		// Wrap field in a relative container.
		var wrapper = document.createElement( 'div' );
		wrapper.style.position = 'relative';
		wrapper.style.display  = 'block';
		field.parentNode.insertBefore( wrapper, field );
		wrapper.appendChild( field );

		// Build the backdrop.
		var backdrop = document.createElement( 'div' );
		backdrop.setAttribute( 'aria-hidden', 'true' );

		// Copy typographic + spacing styles so text layout is identical.
		COPY_STYLES.forEach( function ( prop ) {
			backdrop.style[ prop ] = cs[ prop ];
		} );

		Object.assign( backdrop.style, {
			position:   'absolute',
			top:        '0',
			left:       '0',
			width:      '100%',
			height:     '100%',
			overflow:   'hidden',
			pointerEvents: 'none',
			color:      'transparent',
			background: '#fff',
			// Must sit below the field.
			zIndex:     '0',
			// Prevent backdrop from affecting layout.
			margin:     '0',
		} );

		wrapper.insertBefore( backdrop, field );

		// Make the field sit above the backdrop and show its background as transparent.
		Object.assign( field.style, {
			position:   'relative',
			zIndex:     '1',
			background: 'transparent',
		} );

		// Keep backdrop in sync.
		field.addEventListener( 'input',  function () { syncBackdrop( field, backdrop ); } );
		field.addEventListener( 'scroll', function () { syncBackdrop( field, backdrop ); } );

		// Initial render.
		syncBackdrop( field, backdrop );

		// Re-sync if the field is resized (textarea resize handle).
		if ( typeof ResizeObserver !== 'undefined' ) {
			new ResizeObserver( function () {
				var cs2 = window.getComputedStyle( field );
				backdrop.style.width  = cs2.width;
				backdrop.style.height = cs2.height;
				syncBackdrop( field, backdrop );
			} ).observe( field );
		}
	}

	// ---------------------------------------------------------------------------
	// Init
	// ---------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		// Placeholder chips.
		document.querySelectorAll( '.spa-placeholder' ).forEach( function ( chip ) {
			var targetId = chip.dataset.target;
			if ( ! targetId ) return;
			var field = document.getElementById( targetId );
			if ( ! field ) return;
			chip.addEventListener( 'click', function () {
				insertAtCursor( field, chip.textContent );
			} );
		} );

		var fieldIds = [
			'spa_result_template',
			'spa_upcoming_template',
			'spa_facebook_template',
		];

		fieldIds.forEach( function ( id ) {
			var field = document.getElementById( id );
			if ( ! field ) return;
			attachHighlight( field );
		} );

		document.querySelectorAll( '.spa-emoji-trigger' ).forEach( function ( btn ) {
			var targetId = btn.dataset.target;
			if ( ! targetId ) return;
			var field = document.getElementById( targetId );
			if ( ! field ) return;

			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var popup = document.getElementById( PICKER_ID );
				if ( popup && popup.style.display !== 'none' && activeTarget === field ) {
					closePicker();
				} else {
					openPicker( btn, field );
				}
			} );
		} );
	} );
} )();
