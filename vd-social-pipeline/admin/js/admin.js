/* VD Social Pipeline — JS del admin (sin dependencias). */
( function () {
	'use strict';

	// Contador de caracteres para X. El link cuenta ~23 (t.co).
	var LINK_COST = 23;

	function updateCounter( textarea ) {
		var wrap = textarea.closest( '.vd-variant' );
		if ( ! wrap ) {
			return;
		}
		var counter = wrap.querySelector( '.vd-counter' );
		if ( ! counter ) {
			return;
		}
		var limit = parseInt( textarea.getAttribute( 'data-x-limit' ), 10 ) || 280;
		var total = textarea.value.length + LINK_COST;
		counter.textContent = total + ' / ' + limit;
		if ( total > limit ) {
			counter.classList.add( 'vd-over' );
		} else {
			counter.classList.remove( 'vd-over' );
		}
	}

	function initCounters() {
		var areas = document.querySelectorAll( 'textarea[data-x-limit]' );
		Array.prototype.forEach.call( areas, function ( ta ) {
			updateCounter( ta );
			ta.addEventListener( 'input', function () {
				updateCounter( ta );
			} );
		} );
	}

	function initTests() {
		if ( typeof window.vdSocial === 'undefined' ) {
			return;
		}
		var buttons = document.querySelectorAll( '.vd-test' );
		Array.prototype.forEach.call( buttons, function ( btn ) {
			btn.addEventListener( 'click', function () {
				var service = btn.getAttribute( 'data-service' );
				var result = document.querySelector( '.vd-test-result[data-for="' + service + '"]' );
				if ( result ) {
					result.className = 'vd-test-result';
					result.textContent = window.vdSocial.testing;
				}
				btn.disabled = true;

				var body = new URLSearchParams();
				body.append( 'action', 'vd_social_test' );
				body.append( 'nonce', window.vdSocial.nonce );
				body.append( 'service', service );

				fetch( window.vdSocial.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( json ) {
						if ( result ) {
							var ok = json && json.success;
							result.className = 'vd-test-result ' + ( ok ? 'vd-ok' : 'vd-err' );
							result.textContent = ( json && json.data && json.data.message ) ? json.data.message : ( ok ? 'OK' : 'Error' );
						}
					} )
					.catch( function () {
						if ( result ) {
							result.className = 'vd-test-result vd-err';
							result.textContent = 'Error de red';
						}
					} )
					.finally( function () {
						btn.disabled = false;
					} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initCounters();
		initTests();
	} );
} )();
