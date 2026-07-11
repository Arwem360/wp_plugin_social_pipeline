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

	function initLogoPicker() {
		var selectBtn = document.querySelector( '.vd-logo-select' );
		if ( ! selectBtn || typeof window.wp === 'undefined' || ! window.wp.media ) {
			return;
		}
		var input = document.getElementById( 'placa_logo_id' );
		var preview = document.querySelector( '.vd-logo-preview' );
		var removeBtn = document.querySelector( '.vd-logo-remove' );
		var frame;

		selectBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( frame ) {
				frame.open();
				return;
			}
			frame = window.wp.media( {
				title: 'Logo de la placa',
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				if ( input ) { input.value = att.id; }
				if ( preview ) {
					var url = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
					preview.innerHTML = '<img src="' + url + '" alt="" style="max-height:80px;background:#222;padding:6px;border-radius:4px;" />';
				}
				if ( removeBtn ) { removeBtn.style.display = ''; }
			} );
			frame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				if ( input ) { input.value = '0'; }
				if ( preview ) { preview.innerHTML = ''; }
				removeBtn.style.display = 'none';
			} );
		}
	}

	function initAccentSync() {
		var text = document.getElementById( 'placa_accent' );
		var color = document.querySelector( '.vd-accent-sync' );
		if ( ! text || ! color ) {
			return;
		}
		color.addEventListener( 'input', function () { text.value = color.value; } );
		text.addEventListener( 'input', function () {
			if ( /^#[0-9a-fA-F]{6}$/.test( text.value ) ) { color.value = text.value; }
		} );
	}

	function initPlacaGenerate() {
		if ( typeof window.vdPlaca === 'undefined' ) {
			return;
		}
		var wrap = document.querySelector( '.vd-placa-editor' );
		var btn = document.querySelector( '.vd-placa-generate' );
		if ( ! wrap || ! btn ) {
			return;
		}
		var postId = wrap.getAttribute( 'data-post' );
		var previews = wrap.querySelector( '.vd-placa-previews' );

		btn.addEventListener( 'click', function () {
			var original = btn.textContent;
			btn.disabled = true;
			btn.textContent = window.vdPlaca.generating;

			var body = new URLSearchParams();
			body.append( 'action', window.vdPlaca.action );
			body.append( 'nonce', window.vdPlaca.nonce );
			body.append( 'post_id', postId );

			fetch( window.vdPlaca.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( json && json.success && previews ) {
						var d = json.data;
						var warn = '';
						if ( d.noimage ) { warn = '<p class="description">Sin imagen destacada (fondo de marca).</p>'; }
						else if ( d.lowres ) { warn = '<p class="description" style="color:#8a6d1b;">Imagen de origen de baja resolución.</p>'; }
						previews.innerHTML = warn +
							'<div class="vd-placa-thumbs">' +
							'<div class="vd-placa-thumb"><img src="' + d.feed + '" alt="" /><a class="button button-small" href="' + d.feedRaw + '" download>Feed 4:5</a></div>' +
							'<div class="vd-placa-thumb"><img src="' + d.story + '" alt="" /><a class="button button-small" href="' + d.storyRaw + '" download>Historia 9:16</a></div>' +
							'</div>';
					} else {
						alert( ( json && json.data && json.data.message ) ? json.data.message : window.vdPlaca.error );
					}
				} )
				.catch( function () { alert( window.vdPlaca.error ); } )
				.finally( function () {
					btn.disabled = false;
					btn.textContent = original;
				} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initCounters();
		initTests();
		initLogoPicker();
		initAccentSync();
		initPlacaGenerate();
	} );
} )();
