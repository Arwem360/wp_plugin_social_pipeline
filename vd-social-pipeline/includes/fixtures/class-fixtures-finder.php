<?php
/**
 * Buscador de partidos en el editor: metabox que consulta la API (por AJAX) y
 * muestra los partidos con su ID y botones para copiar los shortcodes
 * ([vd_formaciones], [vd_eventos], [vd_fixtures id=...]).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Fixtures_Finder {

	public const AJAX_ACTION = 'vd_fixtures_finder';
	private const NONCE      = 'vd_fixtures_finder';

	/** Ligas ofrecidas en el selector (slug|id => etiqueta). */
	private function leagues(): array {
		return array(
			'liga-argentina'   => 'Liga Profesional (128)',
			'primera-nacional' => 'Primera Nacional (129)',
			'primera-b'        => 'Primera B Metro (131)',
			'primera-c'        => 'Primera C (132)',
			'federal-a'        => 'Torneo Federal A (134)',
			'copa-argentina'   => 'Copa Argentina (130)',
			'libertadores'     => 'Copa Libertadores (13)',
			'sudamericana'     => 'Copa Sudamericana (11)',
			'champions'        => 'Champions League (2)',
			'laliga'           => 'La Liga (140)',
			'premier'          => 'Premier League (39)',
		);
	}

	public function register_hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_search' ) );
	}

	public function add_box(): void {
		foreach ( array( 'post', 'page' ) as $type ) {
			add_meta_box(
				'vd_fixtures_finder',
				__( '⚽ Buscar partido (shortcodes)', 'vd-social-pipeline' ),
				array( $this, 'render_box' ),
				$type,
				'side',
				'low'
			);
		}
	}

	public function render_box(): void {
		?>
		<div class="vd-finder"
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>"
			data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>">

			<p class="howto" style="margin-top:0"><?php esc_html_e( 'Buscá el partido y copiá el shortcode.', 'vd-social-pipeline' ); ?></p>

			<select class="vd-finder-league widefat" style="margin-bottom:6px">
				<?php foreach ( $this->leagues() as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<select class="vd-finder-mode widefat" style="margin-bottom:6px">
				<option value="fecha"><?php esc_html_e( 'Por fecha', 'vd-social-pipeline' ); ?></option>
				<option value="proximos"><?php esc_html_e( 'Próximos partidos', 'vd-social-pipeline' ); ?></option>
				<option value="ultimos"><?php esc_html_e( 'Últimos partidos', 'vd-social-pipeline' ); ?></option>
			</select>

			<input type="date" class="vd-finder-date widefat" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" style="margin-bottom:6px">

			<button type="button" class="button button-secondary vd-finder-search" style="width:100%"><?php esc_html_e( 'Buscar', 'vd-social-pipeline' ); ?></button>

			<div class="vd-finder-results" style="margin-top:10px"></div>
		</div>

		<style>
			.vd-finder-match{border:1px solid #dcdcde;border-radius:4px;padding:8px;margin-bottom:8px;font-size:12px}
			.vd-finder-match .vs{font-weight:600;color:#1e1e1e;line-height:1.3}
			.vd-finder-match .meta{color:#787c82;margin:2px 0 6px}
			.vd-finder-btns{display:flex;flex-wrap:wrap;gap:4px}
			.vd-finder-btns .button{font-size:11px;height:auto;padding:1px 7px;line-height:1.7}
			.vd-finder-copied{color:#1e8c3a;font-weight:600}
			.vd-finder-empty,.vd-finder-error{color:#787c82;font-size:12px}
			.vd-finder-error{color:#d63638}
		</style>

		<script>
		( function () {
			var root = document.currentScript.parentNode.querySelector( '.vd-finder' );
			if ( ! root || root.dataset.bound ) { return; }
			root.dataset.bound = '1';

			var results = root.querySelector( '.vd-finder-results' );

			function esc( s ) {
				return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
					return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
				} );
			}

			function copy( text, btn ) {
				var done = function () {
					var old = btn.textContent;
					btn.textContent = '¡Copiado!';
					btn.classList.add( 'vd-finder-copied' );
					setTimeout( function () { btn.textContent = old; btn.classList.remove( 'vd-finder-copied' ); }, 1200 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( done ).catch( function () { fallback( text ); done(); } );
				} else {
					fallback( text ); done();
				}
			}
			function fallback( text ) {
				var ta = document.createElement( 'textarea' );
				ta.value = text; document.body.appendChild( ta ); ta.select();
				try { document.execCommand( 'copy' ); } catch ( e ) {}
				document.body.removeChild( ta );
			}

			function render( matches ) {
				if ( ! matches.length ) {
					results.innerHTML = '<p class="vd-finder-empty">Sin partidos para esa búsqueda.</p>';
					return;
				}
				results.innerHTML = matches.map( function ( m ) {
					return '<div class="vd-finder-match">' +
						'<div class="vs">' + esc( m.home ) + ' vs ' + esc( m.away ) + '</div>' +
						'<div class="meta">' + esc( m.cuando ) + ' · ' + esc( m.estado ) + ' · ID ' + esc( m.id ) + '</div>' +
						'<div class="vd-finder-btns">' +
							'<button type="button" class="button vd-finder-copy" data-sc="[vd_formaciones fixture=&quot;' + esc( m.id ) + '&quot;]">Formaciones</button>' +
							'<button type="button" class="button vd-finder-copy" data-sc="[vd_eventos fixture=&quot;' + esc( m.id ) + '&quot;]">Eventos</button>' +
							'<button type="button" class="button vd-finder-copy" data-sc="[vd_fixtures id=&quot;' + esc( m.id ) + '&quot;]">Partido</button>' +
						'</div>' +
					'</div>';
				} ).join( '' );
			}

			function search() {
				results.innerHTML = '<p class="vd-finder-empty">Buscando…</p>';
				var body = new URLSearchParams();
				body.set( 'action', root.dataset.action );
				body.set( 'nonce', root.dataset.nonce );
				body.set( 'league', root.querySelector( '.vd-finder-league' ).value );
				body.set( 'mode', root.querySelector( '.vd-finder-mode' ).value );
				body.set( 'date', root.querySelector( '.vd-finder-date' ).value );

				fetch( root.dataset.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success ) { render( res.data.matches || [] ); }
						else { results.innerHTML = '<p class="vd-finder-error">' + esc( ( res && res.data && res.data.message ) || 'Error en la búsqueda.' ) + '</p>'; }
					} )
					.catch( function () { results.innerHTML = '<p class="vd-finder-error">Error de conexión.</p>'; } );
			}

			root.querySelector( '.vd-finder-search' ).addEventListener( 'click', search );
			results.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.vd-finder-copy' );
				if ( btn ) { copy( btn.dataset.sc, btn ); }
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Handler AJAX: busca partidos y devuelve una lista liviana para el editor.
	 */
	public function ajax_search(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'vd-social-pipeline' ) ) );
		}

		$league = isset( $_POST['league'] ) ? sanitize_text_field( wp_unslash( $_POST['league'] ) ) : '';
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'fecha';
		$date   = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';

		if ( '' === $league ) {
			wp_send_json_error( array( 'message' => __( 'Elegí una liga.', 'vd-social-pipeline' ) ) );
		}

		$params = array( 'league' => $league );
		if ( 'proximos' === $mode ) {
			$params['next'] = '20';
		} elseif ( 'ultimos' === $mode ) {
			$params['last'] = '20';
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$params['date'] = $date;
		} else {
			wp_send_json_error( array( 'message' => __( 'Fecha inválida.', 'vd-social-pipeline' ) ) );
		}

		$base   = (string) VD_Social_Fixtures_Module::config()['api_base'];
		$client = new VD_Social_Fixtures_Api_Client( $base );
		$partidos = $client->fixtures( $params );

		if ( is_wp_error( $partidos ) ) {
			wp_send_json_error( array( 'message' => $partidos->get_error_message() ) );
		}

		$out = array();
		foreach ( $partidos as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$out[] = array(
				'id'     => (int) ( $p['id'] ?? 0 ),
				'home'   => (string) ( $p['local']['nombre'] ?? '' ),
				'away'   => (string) ( $p['visitante']['nombre'] ?? '' ),
				'cuando' => $this->cuando( isset( $p['fecha'] ) ? (string) $p['fecha'] : '' ),
				'estado' => $this->estado_corto( $p ),
			);
		}

		wp_send_json_success( array( 'matches' => $out ) );
	}

	private function cuando( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		try {
			$dt = new DateTimeImmutable( $iso );
			$dt = $dt->setTimezone( wp_timezone() );
			return $dt->format( 'd/m H:i' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * @param array<string,mixed> $p
	 */
	private function estado_corto( array $p ): string {
		$c = isset( $p['estado']['corto'] ) ? strtoupper( (string) $p['estado']['corto'] ) : '';
		if ( in_array( $c, array( 'FT', 'AET', 'PEN' ), true ) ) {
			return __( 'Final', 'vd-social-pipeline' );
		}
		if ( in_array( $c, array( '1H', '2H', 'HT', 'ET', 'BT', 'P', 'LIVE', 'INT' ), true ) ) {
			return __( 'En vivo', 'vd-social-pipeline' );
		}
		return __( 'Programado', 'vd-social-pipeline' );
	}
}
