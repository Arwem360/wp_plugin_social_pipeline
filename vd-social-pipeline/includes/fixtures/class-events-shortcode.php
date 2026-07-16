<?php
/**
 * Shortcode [vd_eventos] — timeline de eventos de un partido (goles, tarjetas,
 * cambios, VAR) desde la API. Server-side con caché por transient. Trae también
 * el partido para armar el encabezado con equipos y marcador.
 *
 * Uso:
 *   [vd_eventos fixture="1545443"]
 *   [vd_eventos fixture="1545443" tipo="goal"]
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Events_Shortcode {

	public const SHORTCODE      = 'vd_eventos';
	private const CACHE_PREFIX  = 'vd_events_';
	private const CACHE_TTL     = 2 * MINUTE_IN_SECONDS;
	private const CACHE_TTL_ERR = 1 * MINUTE_IN_SECONDS;

	private static bool $css_done = false;

	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'fixture' => '',
				'equipo'  => '',
				'tipo'    => '',
			),
			$atts,
			self::SHORTCODE
		);

		$fixture = preg_replace( '/[^0-9]/', '', (string) $atts['fixture'] );
		if ( '' === $fixture ) {
			return '';
		}
		$team = preg_replace( '/[^0-9]/', '', (string) $atts['equipo'] );
		$tipo = preg_replace( '/[^a-z]/', '', strtolower( (string) $atts['tipo'] ) );

		$data    = $this->get_data( $fixture, $team, $tipo );
		$eventos = $data['eventos'];

		// Sin eventos (o error): no mostramos nada.
		if ( is_wp_error( $eventos ) || empty( $eventos ) ) {
			return '';
		}

		$fx      = is_array( $data['fixture'] ) ? $data['fixture'] : array();
		$home_id = $this->home_id( $fx, $eventos );

		ob_start();
		echo $this->css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="vd-ev">
			<?php echo $this->header( $fx, $eventos, $home_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="vd-ev-timeline">
				<div class="vd-ev-track">
					<?php foreach ( $eventos as $e ) : ?>
						<?php echo $this->row( is_array( $e ) ? $e : array(), $home_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Encabezado con equipos y marcador (del fixture si está; si no, contando goles).
	 *
	 * @param array<string,mixed>            $fx
	 * @param array<int,array<string,mixed>> $eventos
	 */
	private function header( array $fx, array $eventos, int $home_id ): string {
		$local  = isset( $fx['local'] ) && is_array( $fx['local'] ) ? $fx['local'] : array();
		$visita = isset( $fx['visitante'] ) && is_array( $fx['visitante'] ) ? $fx['visitante'] : array();

		// Marcador: del fixture si viene, si no se cuenta de los goles.
		$goles = isset( $fx['goles'] ) && is_array( $fx['goles'] ) ? $fx['goles'] : array();
		if ( isset( $goles['local'], $goles['visitante'] ) && null !== $goles['local'] ) {
			$gl = (int) $goles['local'];
			$gv = (int) $goles['visitante'];
		} else {
			$gl = 0;
			$gv = 0;
			foreach ( $eventos as $e ) {
				if ( isset( $e['categoria'] ) && 'gol' === $e['categoria'] ) {
					( (int) ( $e['equipo']['id'] ?? 0 ) === $home_id ) ? $gl++ : $gv++;
				}
			}
		}

		$h_logo = isset( $local['logo'] ) ? (string) $local['logo'] : '';
		$a_logo = isset( $visita['logo'] ) ? (string) $visita['logo'] : '';

		ob_start();
		?>
		<div class="vd-ev-score">
			<div class="vd-ev-team home">
				<?php if ( '' !== $h_logo ) : ?><img src="<?php echo esc_url( $h_logo ); ?>" alt="" width="24" height="24"><?php endif; ?>
				<span><?php echo esc_html( $local['nombre'] ?? '' ); ?></span>
			</div>
			<div class="vd-ev-marcador"><?php echo (int) $gl; ?> - <?php echo (int) $gv; ?></div>
			<div class="vd-ev-team away">
				<span><?php echo esc_html( $visita['nombre'] ?? '' ); ?></span>
				<?php if ( '' !== $a_logo ) : ?><img src="<?php echo esc_url( $a_logo ); ?>" alt="" width="24" height="24"><?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Una fila del timeline: contenido a un lado según el equipo, minuto al centro.
	 *
	 * @param array<string,mixed> $e
	 */
	private function row( array $e, int $home_id ): string {
		$es_local = (int) ( $e['equipo']['id'] ?? 0 ) === $home_id;
		$cell     = $this->detalle( $e );

		ob_start();
		?>
		<div class="vd-ev-row">
			<div class="vd-ev-side home"><?php echo $es_local ? $cell : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="vd-ev-min"><?php echo esc_html( $this->minuto( $e ) ); ?></div>
			<div class="vd-ev-side away"><?php echo $es_local ? '' : $cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $e
	 */
	private function detalle( array $e ): string {
		$cat    = isset( $e['categoria'] ) ? (string) $e['categoria'] : '';
		$jug    = isset( $e['jugador']['nombre'] ) ? (string) $e['jugador']['nombre'] : '';
		$asist  = isset( $e['asistente']['nombre'] ) ? (string) $e['asistente']['nombre'] : '';
		$prefix = 'cambio' === $cat ? '▶ ' : '⇢ ';

		$extra = '';
		if ( '' !== $asist ) {
			$extra = '<span class="vd-ev-assist">' . esc_html( $prefix . $asist ) . '</span>';
		}

		return $this->icono( $cat )
			. '<span class="vd-ev-txt"><span class="vd-ev-name">' . esc_html( $jug ) . '</span>' . $extra . '</span>';
	}

	private function icono( string $cat ): string {
		switch ( $cat ) {
			case 'gol':
				return '<span class="vd-ev-ico">⚽</span>';
			case 'amarilla':
				return '<span class="vd-ev-ico amarilla"></span>';
			case 'roja':
				return '<span class="vd-ev-ico roja"></span>';
			case 'cambio':
				return '<span class="vd-ev-ico cambio"><span class="in"></span><span class="out"></span></span>';
			case 'var':
				return '<span class="vd-ev-ico var">VAR</span>';
			default:
				return '<span class="vd-ev-ico">•</span>';
		}
	}

	/**
	 * @param array<string,mixed> $e
	 */
	private function minuto( array $e ): string {
		$min   = isset( $e['minuto'] ) ? (string) (int) $e['minuto'] : '';
		$extra = ! empty( $e['extra'] ) ? '+' . (int) $e['extra'] : '';
		return $min . $extra . "'";
	}

	/**
	 * Determina el id del equipo local (para ubicar los eventos a la izquierda).
	 *
	 * @param array<string,mixed>            $fx
	 * @param array<int,array<string,mixed>> $eventos
	 */
	private function home_id( array $fx, array $eventos ): int {
		if ( isset( $fx['local']['id'] ) ) {
			return (int) $fx['local']['id'];
		}
		return isset( $eventos[0]['equipo']['id'] ) ? (int) $eventos[0]['equipo']['id'] : 0;
	}

	/**
	 * Trae eventos + fixture (para el encabezado), con caché combinada.
	 *
	 * @return array{eventos:array<int,array<string,mixed>>|WP_Error,fixture:array<string,mixed>|null}
	 */
	private function get_data( string $fixture, string $team, string $tipo ): array {
		$key    = self::CACHE_PREFIX . md5( $fixture . '|' . $team . '|' . $tipo );
		$cached = get_transient( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$base   = (string) VD_Social_Fixtures_Module::config()['api_base'];
		$client = new VD_Social_Fixtures_Api_Client( $base );

		$params = array( 'fixture' => $fixture );
		if ( '' !== $team ) {
			$params['team'] = $team;
		}
		if ( '' !== $tipo ) {
			$params['type'] = $tipo;
		}

		$eventos = $client->events( $params );
		$fx_list = $client->fixtures( array( 'id' => $fixture ) );
		$fixture_data = ( is_array( $fx_list ) && ! empty( $fx_list ) && is_array( $fx_list[0] ) ) ? $fx_list[0] : null;

		$result = array(
			'eventos' => $eventos,
			'fixture' => $fixture_data,
		);

		set_transient( $key, $result, is_wp_error( $eventos ) ? self::CACHE_TTL_ERR : self::CACHE_TTL );
		return $result;
	}

	private function css(): string {
		if ( self::$css_done ) {
			return '';
		}
		self::$css_done = true;

		return '<style>'
			. '.vd-ev{font-family:var(--font-ui,system-ui,sans-serif);color:var(--color-ink,#111);border:1px solid var(--color-rule,#e8e8e8);border-radius:var(--radius,2px);background:var(--color-surface,#fff);margin:0 0 1.5rem;overflow:hidden}'
			. '.vd-ev-score{display:flex;align-items:center;justify-content:center;gap:1rem;padding:.9rem 1rem;border-bottom:1px solid var(--color-rule,#e8e8e8)}'
			. '.vd-ev-team{display:flex;align-items:center;gap:.5rem;font-weight:700;font-size:.9rem;flex:1}'
			. '.vd-ev-team.home{justify-content:flex-end;text-align:right}'
			. '.vd-ev-team.away{justify-content:flex-start;text-align:left}'
			. '.vd-ev-team img{width:24px;height:24px;object-fit:contain;flex:none}'
			. '.vd-ev-marcador{font-family:var(--font-display,inherit);font-weight:800;font-size:1.5rem;line-height:1;flex:none}'
			. '.vd-ev-timeline{padding:.5rem 0}'
			. '.vd-ev-track{position:relative}'
			. '.vd-ev-track::before{content:"";position:absolute;top:0;bottom:0;left:50%;width:2px;transform:translateX(-50%);background:var(--color-rule,#e8e8e8)}'
			. '.vd-ev-row{position:relative;display:grid;grid-template-columns:1fr 46px 1fr;align-items:center;column-gap:.75rem;padding:.5rem 1rem}'
			. '.vd-ev-side{display:flex;align-items:center;gap:.5rem;min-width:0}'
			. '.vd-ev-side.home{flex-direction:row-reverse;text-align:right}'
			. '.vd-ev-side.away{text-align:left}'
			. '.vd-ev-txt{display:flex;flex-direction:column;min-width:0}'
			. '.vd-ev-name{font-size:.82rem;font-weight:600;color:var(--color-ink,#111);line-height:1.2}'
			. '.vd-ev-assist{font-size:.7rem;color:var(--color-ink-muted,#666)}'
			. '.vd-ev-min{width:38px;height:38px;margin:0 auto;flex:none;border-radius:50%;border:1px solid var(--color-rule,#e8e8e8);background:var(--color-surface,#fff);display:flex;align-items:center;justify-content:center;font-size:.68rem;color:var(--color-ink-muted,#666);z-index:1}'
			. '.vd-ev-ico{width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;flex:none}'
			. '.vd-ev-ico.amarilla{width:11px;height:15px;background:#f5c518;border-radius:2px}'
			. '.vd-ev-ico.roja{width:11px;height:15px;background:var(--color-live,#e8210a);border-radius:2px}'
			. '.vd-ev-ico.var{font-size:8px;font-weight:800;color:#fff;background:#6c48c9;border-radius:3px;width:auto;padding:2px 4px}'
			. '.vd-ev-ico.cambio{gap:1px}'
			. '.vd-ev-ico.cambio .in,.vd-ev-ico.cambio .out{width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent}'
			. '.vd-ev-ico.cambio .in{border-right:7px solid #16955c}'
			. '.vd-ev-ico.cambio .out{border-left:7px solid var(--color-live,#e8210a)}'
			. '.vd-ev--empty{padding:1rem;color:var(--color-ink-muted,#666);font-size:.85rem}'
			. '</style>';
	}
}
