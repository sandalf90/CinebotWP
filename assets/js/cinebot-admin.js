( function () {
	'use strict';

	var statusEl = document.getElementById( 'cinebot-ajax-status' );

	if ( ! statusEl ) {
		return;
	}

	function showStatus( message, isError ) {
		statusEl.textContent = message;
		statusEl.className = 'cinebot-ajax-status' + ( isError ? ' error' : ' success' );
	}

	function postAjax( action, callback ) {
		var formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', window.cinebotAdmin ? window.cinebotAdmin.nonce : '' );

		fetch( window.cinebotAdmin.ajaxUrl, {
			method: 'POST',
			body: formData
		} )
			.then( function ( response ) { return response.json(); } )
			.then( callback )
			.catch( function () { showStatus( window.cinebotAdmin.i18n.error, true ); } );
	}

	var testBtn = document.getElementById( 'cinebot-test-connection' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			testBtn.disabled = true;
			showStatus( window.cinebotAdmin.i18n.testing, false );
			postAjax( 'cinebot_wp_test_connection', function ( data ) {
				testBtn.disabled = false;
				if ( data.success ) {
					var count = data.data.titoli_count || 0;
					showStatus( window.cinebotAdmin.i18n.success + ': ' + count + ' titoli', false );
				} else {
					showStatus( data.data.message || window.cinebotAdmin.i18n.error, true );
				}
			} );
		} );
	}

	var syncBtn = document.getElementById( 'cinebot-sync-now' );
	if ( syncBtn ) {
		syncBtn.addEventListener( 'click', function () {
			syncBtn.disabled = true;
			showStatus( window.cinebotAdmin.i18n.syncing, false );
			postAjax( 'cinebot_wp_sync_now', function ( data ) {
				syncBtn.disabled = false;
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
			} );
		} );
	}
} )();
