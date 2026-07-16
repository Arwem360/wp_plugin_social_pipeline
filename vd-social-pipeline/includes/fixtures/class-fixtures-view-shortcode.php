<?php
/**
 * Shortcode [vd_fixtures] — lista de partidos traída de la API (server-side,
 * con caché por transient). Layout simétrico: nombre+escudo | marcador | escudo+nombre.
 *
 * Uso:
 *   [vd_fixtures liga="128" date="2026-07-13"]
 *   [vd_fixtures liga="liga-argentina" next="10"]
 *   [vd_fixtures liga="128" from="2026-07-11" to="2026-07-13"]
 *   [vd_fixtures equipo="435" last="5" titulo="Últimos de River"]
 *   [vd_fixtures id="1545443"]
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Fixtures_View_Shortcode {

	public const SHORTCODE      = 'vd_fixtures';
	private const CACHE_PREFIX  = 'vd_fx_view_';
	private const CACHE_TTL     = 5 * MINUTE_IN_SECONDS;
	private const CACHE_TTL_ERR = 2 * MINUTE_IN_SECONDS;

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
				'liga'    => '',
				'equipo'  => '',
				'season'  => '',
				'date'    => '',
				'from'    => '',
				'to'      => '',
				'next'    => '',
				'last'    => '',
				'id'      => '',
				'titulo'  => '',
				'agrupar' => 'si',
			),
			$atts,
			self::SHORTCODE
		);

		$params = $this->build_params( $atts );
		if ( empty( $params ) ) {
			return '';
		}

		$partidos = $this->get_data( $params );

		if ( is_wp_error( $partidos ) || empty( $partidos ) ) {
			return '<div class="vd-fx vd-fx--empty">'
				. esc_html__( 'No hay partidos para mostrar.', 'vd-social-pipeline' )
				. '</div>';
		}

		$agrupar = ! in_array( strtolower( (string) $atts['agrupar'] ), array( 'no', '0', 'false' ), true ) && '' === $atts['id'];

		ob_start();
		echo $this->css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="vd-fx">
			<?php if ( '' !== (string) $atts['titulo'] ) : ?>
				<h3 class="vd-fx-title"><?php echo esc_html( $atts['titulo'] ); ?></h3>
			<?php endif; ?>
			<?php
			if ( $agrupar ) {
				echo $this->render_grouped( $partidos ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				foreach ( $partidos as $p ) {
					echo $this->render_row( is_array( $p ) ? $p : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Agrupa por fecha local (día) y renderiza con un encabezado por día.
	 *
	 * @param array<int,array<string,mixed>> $partidos
	 */
	private function render_grouped( array $partidos ): string {
		$by_day = array();
		foreach ( $partidos as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$dt  = $this->to_local( isset( $p['fecha'] ) ? (string) $p['fecha'] : '' );
			$key = $dt ? $dt->format( 'Y-m-d' ) : '0000-00-00';
			$by_day[ $key ][] = $p;
		}
		ksort( $by_day );

		$out = '';
		foreach ( $by_day as $key => $items ) {
			$label = '0000-00-00' === $key ? __( 'Sin fecha', 'vd-social-pipeline' ) : $this->fecha_legible( $key );
			$out  .= '<div class="vd-fx-day">' . esc_html( $label ) . '</div>';
			foreach ( $items as $p ) {
				$out .= $this->render_row( $p );
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $p
	 */
	private function render_row( array $p ): string {
		$estado = $this->estado( $p );
		$local  = isset( $p['local'] ) && is_array( $p['local'] ) ? $p['local'] : array();
		$visita = isset( $p['visitante'] ) && is_array( $p['visitante'] ) ? $p['visitante'] : array();

		$goles     = isset( $p['goles'] ) && is_array( $p['goles'] ) ? $p['goles'] : array();
		$gl        = $goles['local'] ?? null;
		$gv        = $goles['visitante'] ?? null;
		$has_score = ( null !== $gl && null !== $gv );

		$h_win = ! empty( $local['ganador'] );
		$a_win = ! empty( $visita['ganador'] );

		ob_start();
		?>
		<div class="vd-fx-row vd-fx--<?php echo esc_attr( $estado['tipo'] ); ?>">
			<div class="vd-fx-status">
				<span class="vd-fx-badge vd-fx-badge--<?php echo esc_attr( $estado['tipo'] ); ?>"><?php echo esc_html( $estado['texto'] ); ?></span>
			</div>
			<div class="vd-fx-match">
				<div class="vd-fx-team home <?php echo $h_win ? 'win' : ''; ?>">
					<span class="vd-fx-name"><?php echo esc_html( $local['nombre'] ?? '' ); ?></span>
					<?php if ( ! empty( $local['logo'] ) ) : ?>
						<img class="vd-fx-logo" src="<?php echo esc_url( (string) $local['logo'] ); ?>" alt="" loading="lazy" width="24" height="24">
					<?php endif; ?>
				</div>
				<div class="vd-fx-center">
					<?php if ( $has_score ) : ?>
						<span class="vd-fx-score"><span class="<?php echo $h_win ? 'w' : ''; ?>"><?php echo (int) $gl; ?></span><span class="d">-</span><span class="<?php echo $a_win ? 'w' : ''; ?>"><?php echo (int) $gv; ?></span></span>
					<?php else : ?>
						<span class="vd-fx-vs">VS</span>
					<?php endif; ?>
				</div>
				<div class="vd-fx-team away <?php echo $a_win ? 'win' : ''; ?>">
					<?php if ( ! empty( $visita['logo'] ) ) : ?>
						<img class="vd-fx-logo" src="<?php echo esc_url( (string) $visita['logo'] ); ?>" alt="" loading="lazy" width="24" height="24">
					<?php endif; ?>
					<span class="vd-fx-name"><?php echo esc_html( $visita['nombre'] ?? '' ); ?></span>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Estado del partido → tipo (live|fin|otro|ns) + texto legible.
	 *
	 * @param array<string,mixed> $p
	 * @return array{tipo:string,texto:string}
	 */
	private function estado( array $p ): array {
		$corto = isset( $p['estado']['corto'] ) ? strtoupper( (string) $p['estado']['corto'] ) : '';
		$min   = isset( $p['estado']['minuto'] ) ? (int) $p['estado']['minuto'] : null;

		if ( 'HT' === $corto ) {
			return array( 'tipo' => 'live', 'texto' => __( 'Entretiempo', 'vd-social-pipeline' ) );
		}
		if ( in_array( $corto, array( '1H', '2H', 'ET', 'BT', 'P', 'LIVE', 'INT' ), true ) ) {
			return array( 'tipo' => 'live', 'texto' => null !== $min ? $min . "'" : __( 'En vivo', 'vd-social-pipeline' ) );
		}
		if ( in_array( $corto, array( 'FT', 'AET', 'PEN' ), true ) ) {
			$t = 'AET' === $corto ? __( 'Final (alargue)', 'vd-social-pipeline' ) : ( 'PEN' === $corto ? __( 'Final (pen)', 'vd-social-pipeline' ) : __( 'Final', 'vd-social-pipeline' ) );
			return array( 'tipo' => 'fin', 'texto' => $t );
		}
		if ( in_array( $corto, array( 'PST', 'CANC', 'SUSP', 'ABD' ), true ) ) {
			$map = array(
				'PST'  => __( 'Postergado', 'vd-social-pipeline' ),
				'CANC' => __( 'Cancelado', 'vd-social-pipeline' ),
				'SUSP' => __( 'Suspendido', 'vd-social-pipeline' ),
				'ABD'  => __( 'Abandonado', 'vd-social-pipeline' ),
			);
			return array( 'tipo' => 'otro', 'texto' => $map[ $corto ] );
		}
		// NS / por jugarse: hora local.
		$dt = $this->to_local( isset( $p['fecha'] ) ? (string) $p['fecha'] : '' );
		return array( 'tipo' => 'ns', 'texto' => $dt ? $dt->format( 'H:i' ) : __( 'Próximo', 'vd-social-pipeline' ) );
	}

	/**
	 * Traduce los atributos del shortcode a los parámetros de la API.
	 *
	 * @param array<string,string> $atts
	 * @return array<string,string>
	 */
	private function build_params( array $atts ): array {
		if ( '' !== $atts['id'] ) {
			return array( 'id' => preg_replace( '/[^0-9]/', '', (string) $atts['id'] ) );
		}

		$params = array();
		$liga   = sanitize_text_field( (string) $atts['liga'] );
		$equipo = preg_replace( '/[^0-9]/', '', (string) $atts['equipo'] );

		if ( '' !== $liga ) {
			$params['league'] = $liga;
		}
		if ( '' !== $equipo ) {
			$params['team'] = $equipo;
		}
		if ( '' === $liga && '' === $equipo ) {
			return array(); // Sin liga ni equipo no hay a qué apuntar.
		}

		$season = preg_replace( '/[^0-9]/', '', (string) $atts['season'] );
		if ( '' !== $season ) {
			$params['season'] = $season;
		}
		foreach ( array( 'date', 'from', 'to' ) as $k ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $atts[ $k ] ) ) {
				$params[ $k ] = $atts[ $k ];
			}
		}
		foreach ( array( 'next', 'last' ) as $k ) {
			$n = preg_replace( '/[^0-9]/', '', (string) $atts[ $k ] );
			if ( '' !== $n ) {
				$params[ $k ] = $n;
			}
		}
		return $params;
	}

	/**
	 * @param array<string,string> $params
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function get_data( array $params ) {
		ksort( $params );
		$key    = self::CACHE_PREFIX . md5( wp_json_encode( $params ) );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$base   = (string) VD_Social_Fixtures_Module::config()['api_base'];
		$client = new VD_Social_Fixtures_Api_Client( $base );
		$data   = $client->fixtures( $params );
		set_transient( $key, $data, is_wp_error( $data ) ? self::CACHE_TTL_ERR : self::CACHE_TTL );
		return $data;
	}

	private function to_local( string $iso ): ?DateTimeImmutable {
		if ( '' === $iso ) {
			return null;
		}
		try {
			$dt = new DateTimeImmutable( $iso );
			return $dt->setTimezone( wp_timezone() );
		} catch ( Exception $e ) {
			return null;
		}
	}

	private function fecha_legible( string $ymd ): string {
		$dt = $this->to_local( $ymd . 'T12:00:00' );
		if ( ! $dt ) {
			return $ymd;
		}
		$dias  = array( 'Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado' );
		$meses = array( '', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		return $dias[ (int) $dt->format( 'w' ) ] . ' ' . $dt->format( 'j' ) . ' de ' . $meses[ (int) $dt->format( 'n' ) ];
	}

	private function css(): string {
		if ( self::$css_done ) {
			return '';
		}
		self::$css_done = true;

		return '<style>'
			. '.vd-fx{font-family:var(--font-ui,system-ui,sans-serif);color:var(--color-ink,#111);border:1px solid var(--color-rule,#e8e8e8);border-radius:var(--radius,2px);background:var(--color-surface,#fff);margin:0 0 1.5rem;overflow:hidden}'
			. '.vd-fx-title{font-family:var(--font-display,inherit);font-size:1.05rem;text-transform:uppercase;margin:0;padding:.85rem 1rem;border-bottom:1px solid var(--color-rule,#e8e8e8)}'
			. '.vd-fx-day{font-size:.68rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--color-ink-muted,#666);background:var(--color-bg,#f4f4f2);padding:.5rem 1rem;border-bottom:1px solid var(--color-rule,#e8e8e8)}'
			. '.vd-fx-row{padding:.7rem 1rem;border-bottom:1px solid var(--color-rule,#eee)}'
			. '.vd-fx-row:last-child{border-bottom:0}'
			. '.vd-fx-row.vd-fx--live{background:#fff8f8;border-left:3px solid var(--color-live,#e8210a)}'
			. '.vd-fx-status{margin-bottom:.45rem}'
			. '.vd-fx-badge{display:inline-block;font-size:.6rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:.16rem .45rem;border-radius:2px}'
			. '.vd-fx-badge--fin{background:var(--color-ink,#111);color:#fff}'
			. '.vd-fx-badge--live{background:var(--color-live,#e8210a);color:#fff}'
			. '.vd-fx-badge--ns{background:transparent;color:var(--color-ink-muted,#666);border:1px solid var(--color-rule,#e8e8e8);font-weight:700}'
			. '.vd-fx-badge--otro{background:#efe7d2;color:#8a6d1b}'
			. '.vd-fx-match{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:.75rem}'
			. '.vd-fx-team{display:flex;align-items:center;gap:.5rem;min-width:0}'
			. '.vd-fx-team.home{justify-content:flex-end;text-align:right}'
			. '.vd-fx-team.away{justify-content:flex-start;text-align:left}'
			. '.vd-fx-name{font-size:.85rem;font-weight:700;color:var(--color-ink,#111);line-height:1.2}'
			. '.vd-fx-team.win .vd-fx-name{color:var(--color-accent,#1a7a3c)}'
			. '.vd-fx-logo{width:24px;height:24px;object-fit:contain;flex:none}'
			. '.vd-fx-center{min-width:52px;display:flex;justify-content:center}'
			. '.vd-fx-score{display:inline-flex;align-items:baseline;gap:.35rem;font-family:var(--font-display,inherit);font-size:1.4rem;font-weight:700;line-height:1}'
			. '.vd-fx-score .w{color:var(--color-accent,#1a7a3c)}.vd-fx-score .d{color:var(--color-rule,#ccc);font-size:1rem;font-weight:400}'
			. '.vd-fx-vs{font-size:.68rem;font-weight:800;letter-spacing:.08em;color:var(--color-ink-muted,#666)}'
			. '.vd-fx--empty{padding:1rem;color:var(--color-ink-muted,#666);font-size:.85rem}'
			. '</style>';
	}
}
