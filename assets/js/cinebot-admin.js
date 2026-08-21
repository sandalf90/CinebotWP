( function () {
	'use strict';

	// ---------------------------------------------------------------- API page.

	var statusEl = document.getElementById( 'cinebot-ajax-status' );

	function showStatus( message, isError ) {
		if ( ! statusEl ) { return; }
		statusEl.textContent = message;
		statusEl.className = 'cinebot-ajax-status' + ( isError ? ' error' : ' success' );
	}

	function postAjax( action, callback, button ) {
		var formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', window.cinebotAdmin ? window.cinebotAdmin.nonce : '' );

		fetch( window.cinebotAdmin.ajaxUrl, {
			method: 'POST',
			body: formData
		} )
			.then( function ( response ) { return response.json(); } )
			.then( callback )
			.catch( function () {
				showStatus( window.cinebotAdmin.i18n.error, true );
			} )
			.finally( function () {
				if ( button ) {
					button.disabled = false;
				}
			} );
	}

	var testBtn = document.getElementById( 'cinebot-test-connection' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			testBtn.disabled = true;
			showStatus( window.cinebotAdmin.i18n.testing, false );
			postAjax( 'cinebot_wp_test_connection', function ( data ) {
				if ( data.success ) {
					var count = data.data.titoli_count || 0;
					showStatus( window.cinebotAdmin.i18n.success + ': ' + count + ' titoli', false );
				} else {
					showStatus( data.data.message || window.cinebotAdmin.i18n.error, true );
				}
			}, testBtn );
		} );
	}

	var syncBtn = document.getElementById( 'cinebot-sync-now' );
	if ( syncBtn ) {
		syncBtn.addEventListener( 'click', function () {
			syncBtn.disabled = true;
			showStatus( window.cinebotAdmin.i18n.syncing, false );
			postAjax( 'cinebot_wp_sync_now', function ( data ) {
				if ( data.success ) {
					var stats = data.data.stats || {};
					var msg = window.cinebotAdmin.i18n.success +
						': +' + ( stats.titoli_added || 0 ) +
						' / Δ' + ( stats.titoli_updated || 0 ) +
						' titoli';
					showStatus( msg, false );
				} else {
					showStatus( data.data.message || window.cinebotAdmin.i18n.error, true );
				}
			}, syncBtn );
		} );
	}

	// ---------------------------------------------------- Title editor page.

	var eventTpl   = document.getElementById( 'cinebot-event-template' );
	var eventsWrap = document.getElementById( 'cinebot-events' );

	if ( ! eventTpl || ! eventsWrap ) {
		return;
	}

	function nextIndex( container ) {
		var n = parseInt( container.getAttribute( 'data-next-index' ), 10 ) || 1;
		container.setAttribute( 'data-next-index', String( n + 1 ) );
		return String( n );
	}

	function cloneTemplate( tpl ) {
		return tpl.content.firstElementChild.cloneNode( true );
	}

	function addEvent() {
		var idx = nextIndex( eventsWrap );
		var node = cloneTemplate( eventTpl );
		node.innerHTML = node.innerHTML.replace( /__INDEX__/g, idx );
		eventsWrap.appendChild( node );
	}

	function removeRow( button ) {
		var eventFs = button.closest( '.cinebot-event-fieldset' );

		if ( eventFs ) {
			eventFs.remove();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var target = e.target;
		if ( ! target || 'BUTTON' !== target.tagName ) { return; }

		if ( target.classList.contains( 'cinebot-add-event' ) ) {
			addEvent();
		} else if ( target.classList.contains( 'cinebot-remove-event' ) ) {
			removeRow( target );
		}
	} );
} )();
