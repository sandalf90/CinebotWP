( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var containers = document.querySelectorAll( '.cinebot-programmazione' );

		containers.forEach( function ( container ) {
			var instance = container.getAttribute( 'data-instance' );
			var detailUrl = container.getAttribute( 'data-detail-url' ) || '';
			var filters = container.querySelector( '.cinebot-filters' );
			var cardsEl = container.querySelector( '.cinebot-cards' );
			var loadMore = container.querySelector( '.cinebot-load-more' );
			var liveRegion = container.querySelector( '[aria-live]' ) || createLiveRegion( container );

			if ( filters ) {
				filters.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					var formData = new FormData( filters );
					var params = new URLSearchParams( formData );
					params.append( 'action', 'cinebot_wp_filter' );
					params.append( 'nonce', window.cinebotWpFrontend ? window.cinebotWpFrontend.nonce : '' );
					params.append( 'instance', instance );
					params.append( 'detail_url', detailUrl );
					params.set( 'offset', '0' );

					updateUrl( formData );

					disableButtons( true );
					fetch( window.cinebotWpFrontend.ajaxUrl, {
						method: 'POST',
						body: params
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							if ( data.success ) {
								cardsEl.innerHTML = data.data.html;
								updateLoadMore( loadMore, data.data.has_more, 2 );
								announce( liveRegion, ( data.data.total || 0 ) + ' risultati' );
							}
							disableButtons( false );
						} )
						.catch( function () { disableButtons( false ); } );
				} );
			}

			if ( loadMore ) {
				loadMore.addEventListener( 'click', function () {
					var page = parseInt( loadMore.getAttribute( 'data-page' ), 10 ) || 2;
					var limit = parseInt( loadMore.getAttribute( 'data-limit' ), 10 ) || 50;
					var params = new URLSearchParams();
					if ( filters ) {
						new FormData( filters ).forEach( function ( val, key ) { params.append( key, val ); } );
					}
					params.append( 'action', 'cinebot_wp_filter' );
					params.append( 'nonce', window.cinebotWpFrontend ? window.cinebotWpFrontend.nonce : '' );
					params.append( 'instance', instance );
					params.append( 'detail_url', detailUrl );
					params.append( 'offset', String( ( page - 1 ) * limit ) );

					loadMore.disabled = true;
					fetch( window.cinebotWpFrontend.ajaxUrl, {
						method: 'POST',
						body: params
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							if ( data.success ) {
								cardsEl.insertAdjacentHTML( 'beforeend', data.data.html );
								updateLoadMore( loadMore, data.data.has_more, page + 1 );
							}
							loadMore.disabled = false;
						} )
						.catch( function () { loadMore.disabled = false; } );
				} );
			}
		} );
	} );

	function createLiveRegion( container ) {
		var el = document.createElement( 'div' );
		el.setAttribute( 'aria-live', 'polite' );
		el.setAttribute( 'role', 'status' );
		el.className = 'cinebot-live-region';
		container.appendChild( el );
		return el;
	}

	function announce( el, msg ) {
		if ( el ) { el.textContent = msg; }
	}

	function updateLoadMore( btn, hasMore, nextPage ) {
		if ( ! btn ) { return; }
		if ( hasMore ) {
			btn.style.display = '';
			btn.setAttribute( 'data-page', String( nextPage ) );
		} else {
			btn.style.display = 'none';
		}
	}

	function disableButtons( disabled ) {
		var btn = document.querySelector( '.cinebot-filters [type="submit"]' );
		if ( btn ) { btn.disabled = disabled; }
	}

	function updateUrl( formData ) {
		var url = new URLSearchParams();
		formData.forEach( function ( val, key ) {
			if ( val && '0' !== val ) {
				url.set( key, val );
			}
		} );
		var qs = url.toString();
		var newUrl = window.location.pathname + ( qs ? '?' + qs : '' );
		window.history.replaceState( null, '', newUrl );
	}
} )();
