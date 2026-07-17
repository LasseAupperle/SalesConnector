/**
 * Launch Up Sales Connector — settings page interactions (specs/03).
 * No inline JS anywhere else; this file is enqueued on our page only.
 */
( function () {
	'use strict';

	if ( typeof window.luscAdmin === 'undefined' ) {
		return;
	}
	var cfg = window.luscAdmin;

	function post( action, onDone ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( '_ajax_nonce', cfg.nonce );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( onDone )
			.catch( function () {
				onDone( { success: false, data: { message: cfg.i18n.error } } );
			} );
	}

	function showResult( el, ok, message ) {
		el.textContent = message;
		el.style.color = ok ? '#008a20' : '#d63638';
	}

	var toggle = document.getElementById( 'lusc_toggle_key' );
	if ( toggle ) {
		toggle.addEventListener( 'click', function () {
			var field = document.getElementById( 'lusc_api_key' );
			field.type = 'password' === field.type ? 'text' : 'password';
		} );
	}

	var testBtn = document.getElementById( 'lusc_test' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			var out = document.getElementById( 'lusc_test_result' );
			out.textContent = '…';
			post( 'lusc_test', function ( json ) {
				showResult( out, !! json.success, json.data && json.data.message ? json.data.message : cfg.i18n.error );
			} );
		} );
	}

	var pushBtn = document.getElementById( 'lusc_push_now' );
	if ( pushBtn ) {
		pushBtn.addEventListener( 'click', function () {
			var out = document.getElementById( 'lusc_action_result' );
			post( 'lusc_push', function ( json ) {
				showResult( out, !! json.success, json.data && json.data.message ? json.data.message : cfg.i18n.error );
			} );
		} );
	}

	var backfillBtn = document.getElementById( 'lusc_backfill' );
	if ( backfillBtn ) {
		backfillBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( cfg.i18n.backfillConfirm ) ) {
				return;
			}
			var out = document.getElementById( 'lusc_action_result' );
			post( 'lusc_backfill', function ( json ) {
				showResult( out, !! json.success, json.data && json.data.message ? json.data.message : cfg.i18n.error );
			} );
		} );
	}

	// Dismissal of our notices: WordPress injects the ✕ button on
	// .is-dismissible notices; persist the dismissal per notice type.
	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.classList.contains( 'notice-dismiss' ) ) {
			return;
		}
		var notice = event.target.closest( '[data-lusc-notice]' );
		if ( ! notice ) {
			return;
		}
		var kind = notice.getAttribute( 'data-lusc-notice' );
		post( 'backfill' === kind ? 'lusc_dismiss_backfill_notice' : 'lusc_dismiss_failure_notice', function () {} );
	} );
}() );
