<?php
/**
 * Shortcode [vd_posiciones] — tabla de posiciones traída de la API de stats,
 * renderizada server-side (HTML real, bueno para SEO) con caché por transient.
 *
 * Uso:
 *   [vd_posiciones liga="liga-argentina"]
 *   [vd_posiciones liga="128" season="2026" fase="Clausura" titulo="Liga Profesional"]
 *   [vd_posiciones liga="129" forma="no"]
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Standings_Shortcode {

	public const SHORTCODE    = 'vd_posiciones';
	private const CACHE_PREFIX = 'vd_standings_';
	private const CACHE_TTL    = 30 * MINUTE_IN_SECONDS;
	private const CACHE_TTL_ERR = 5 * MINUTE_IN_SECONDS;

	/** Para imprimir el CSS una sola vez por página. */
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
				'liga'   => '',
				'season' => '',
				'fase'   => '',
				'titulo' => '',
				'forma'  => 'si',
			),
			$atts,
			self::SHORTCODE
		);

		$liga = sanitize_text_field( (string) $atts['liga'] );
		if ( '' === $liga ) {
			return '';
		}

		$season     = preg_replace( '/[^0-9]/', '', (string) $atts['season'] );
		$fase       = sanitize_text_field( (string) $atts['fase'] );
		$show_forma = ! in_array( strtolower( (string) $atts['forma'] ), array( 'no', '0', 'false' ), true );

		$data = $this->get_data( $liga, $season, $fase );

		if ( is_wp_error( $data ) || empty( $data['grupos'] ) ) {
			return '<div class="vd-standings vd-standings--empty">'
				. esc_html__( 'No se pudo cargar la tabla de posiciones en este momento.', 'vd-social-pipeline' )
				. '</div>';
		}

		$titulo = '' !== $atts['titulo'] ? $atts['titulo'] : ( $data['liga']['nombre'] ?? '' );
		$multi  = count( $data['grupos'] ) > 1;

		ob_start();
		echo $this->css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="vd-standings">
			<?php if ( '' !== (string) $titulo ) : ?>
				<h3 class="vd-standings-title"><?php echo esc_html( $titulo ); ?></h3>
			<?php endif; ?>

			<?php foreach ( $data['grupos'] as $grupo ) : ?>
				<?php echo $this->render_grupo( is_array( $grupo ) ? $grupo : array(), $multi, $show_forma ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>

			<div class="vd-standings-legend">
				<span><i class="z-promo"></i> <?php esc_html_e( 'Clasificación', 'vd-social-pipeline' ); ?></span>
				<span><i class="z-playoff"></i> <?php esc_html_e( 'Reducido / Play-offs', 'vd-social-pipeline' ); ?></span>
				<span><i class="z-releg"></i> <?php esc_html_e( 'Descenso', 'vd-social-pipeline' ); ?></span>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $grupo
	 */
	private function render_grupo( array $grupo, bool $multi, bool $show_forma ): string {
		$tabla = isset( $grupo['tabla'] ) && is_array( $grupo['tabla'] ) ? $grupo['tabla'] : array();
		if ( empty( $tabla ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="vd-standings-group">
			<?php if ( $multi && ! empty( $grupo['nombre'] ) ) : ?>
				<div class="vd-standings-group-name"><?php echo esc_html( $grupo['nombre'] ); ?></div>
			<?php endif; ?>
			<div class="vd-standings-scroll">
				<table>
					<thead>
						<tr>
							<th class="c">#</th>
							<th class="l"><?php esc_html_e( 'Equipo', 'vd-social-pipeline' ); ?></th>
							<th>PJ</th><th>G</th><th>E</th><th>P</th>
							<th>GF</th><th>GC</th><th>DG</th><th class="pts">Pts</th>
							<?php if ( $show_forma ) : ?><th class="l"><?php esc_html_e( 'Forma', 'vd-social-pipeline' ); ?></th><?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tabla as $r ) : ?>
							<?php echo $this->render_row( is_array( $r ) ? $r : array(), $show_forma ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $r
	 */
	private function render_row( array $r, bool $show_forma ): string {
		$zone   = $this->zone_of( isset( $r['descripcion'] ) ? (string) $r['descripcion'] : '' );
		$dg     = isset( $r['diferencia'] ) ? (int) $r['diferencia'] : 0;
		$dg_str = ( $dg > 0 ? '+' : '' ) . $dg;
		$dg_cls = $dg > 0 ? 'up' : ( $dg < 0 ? 'down' : '' );

		$equipo = isset( $r['equipo'] ) && is_array( $r['equipo'] ) ? $r['equipo'] : array();
		$logo   = isset( $equipo['logo'] ) ? (string) $equipo['logo'] : '';
		$nombre = isset( $equipo['nombre'] ) ? (string) $equipo['nombre'] : '';

		ob_start();
		?>
		<tr class="<?php echo esc_attr( $zone ); ?>">
			<td class="pos"><?php echo (int) ( $r['posicion'] ?? 0 ); ?></td>
			<td class="team">
				<span class="cell">
					<?php if ( '' !== $logo ) : ?>
						<img class="crest" src="<?php echo esc_url( $logo ); ?>" alt="" loading="lazy" width="20" height="20">
					<?php endif; ?>
					<span><?php echo esc_html( $nombre ); ?></span>
				</span>
			</td>
			<td><?php echo (int) ( $r['jugados'] ?? 0 ); ?></td>
			<td><?php echo (int) ( $r['ganados'] ?? 0 ); ?></td>
			<td><?php echo (int) ( $r['empatados'] ?? 0 ); ?></td>
			<td><?php echo (int) ( $r['perdidos'] ?? 0 ); ?></td>
			<td><?php echo (int) ( $r['golesFavor'] ?? 0 ); ?></td>
			<td><?php echo (int) ( $r['golesContra'] ?? 0 ); ?></td>
			<td class="dg <?php echo esc_attr( $dg_cls ); ?>"><?php echo esc_html( $dg_str ); ?></td>
			<td class="pts"><?php echo (int) ( $r['puntos'] ?? 0 ); ?></td>
			<?php if ( $show_forma ) : ?>
				<td class="l"><?php echo $this->form_html( isset( $r['forma'] ) ? (string) $r['forma'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			<?php endif; ?>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	private function form_html( string $forma ): string {
		if ( '' === $forma ) {
			return '';
		}
		$out = '<span class="vd-form">';
		foreach ( str_split( $forma ) as $c ) {
			$c   = strtoupper( $c );
			$cls = in_array( $c, array( 'W', 'D', 'L' ), true ) ? $c : '';
			$out .= '<i class="' . esc_attr( $cls ) . '">' . esc_html( $c ) . '</i>';
		}
		return $out . '</span>';
	}

	/**
	 * Mapea la descripción de la API a una zona (color). Misma lógica que la demo.
	 */
	private function zone_of( string $desc ): string {
		$d = strtolower( $desc );
		if ( '' === $d ) {
			return '';
		}
		if ( false !== strpos( $d, 'relegation' ) ) {
			return 'z-releg';
		}
		if ( false !== strpos( $d, 'play off' ) || false !== strpos( $d, 'play-off' ) || false !== strpos( $d, 'reduc' ) ) {
			return 'z-playoff';
		}
		foreach ( array( 'promotion', 'knockout', 'super final', 'champions', 'libertad' ) as $needle ) {
			if ( false !== strpos( $d, $needle ) ) {
				return 'z-promo';
			}
		}
		return '';
	}

	/**
	 * Datos de la API con caché por transient (por liga/season/fase).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_data( string $liga, string $season, string $fase ) {
		$key    = self::CACHE_PREFIX . md5( $liga . '|' . $season . '|' . $fase );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$base   = (string) VD_Social_Fixtures_Module::config()['api_base'];
		$client = new VD_Social_Fixtures_Api_Client( $base );
		$data   = $client->standings( $liga, $season, $fase );

		// Cacheamos también el error (poco tiempo) para no martillar la API si falla.
		set_transient( $key, $data, is_wp_error( $data ) ? self::CACHE_TTL_ERR : self::CACHE_TTL );

		return $data;
	}

	/**
	 * CSS scoped, una vez por página. Usa las variables del tema con fallback.
	 */
	private function css(): string {
		if ( self::$css_done ) {
			return '';
		}
		self::$css_done = true;

		return '<style>'
			. '.vd-standings{--vds-promo:#16955c;--vds-playoff:#c98a1e;--vds-releg:#d3453f;font-family:var(--font-ui,system-ui,sans-serif);color:var(--color-ink,#111);margin:0 0 1.5rem}'
			. '.vd-standings-title{font-family:var(--font-display,inherit);font-size:1.1rem;text-transform:uppercase;margin:0 0 .75rem;padding:0 1rem}'
			. '.vd-standings-group{margin-bottom:1rem}'
			. '.vd-standings-group-name{font-size:.72rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--color-ink-muted,#666);padding:.5rem 1rem}'
			. '.vd-standings-scroll{overflow-x:auto;border:1px solid var(--color-rule,#e8e8e8);border-radius:var(--radius,2px)}'
			. '.vd-standings table{border-collapse:collapse;width:100%;font-size:.82rem;background:var(--color-surface,#fff)}'
			. '.vd-standings th{text-align:right;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--color-ink-muted,#666);padding:.8rem .7rem;border-bottom:1px solid var(--color-rule,#e8e8e8);white-space:nowrap}'
			. '.vd-standings th.l{text-align:left}.vd-standings th.c{text-align:center}'
			. '.vd-standings td{text-align:right;padding:.85rem .7rem;border-bottom:1px solid var(--color-rule,#eee);white-space:nowrap;font-variant-numeric:tabular-nums;color:var(--color-ink-muted,#555)}'
			. '.vd-standings th:first-child,.vd-standings td:first-child{padding-left:1rem}'
			. '.vd-standings th:last-child,.vd-standings td:last-child{padding-right:1.1rem}'
			. '.vd-standings tbody tr:last-child td{border-bottom:0}'
			. '.vd-standings td.pos{position:relative;text-align:center;font-weight:700;color:var(--color-ink,#111);padding-left:1.15rem;width:2.4rem}'
			. '.vd-standings td.pos::before{content:"";position:absolute;left:0;top:5px;bottom:5px;width:3px;border-radius:2px;background:transparent}'
			. '.vd-standings tr.z-promo td.pos::before{background:var(--vds-promo)}'
			. '.vd-standings tr.z-playoff td.pos::before{background:var(--vds-playoff)}'
			. '.vd-standings tr.z-releg td.pos::before{background:var(--vds-releg)}'
			. '.vd-standings td.team{text-align:left;color:var(--color-ink,#111);font-weight:500;min-width:150px}'
			. '.vd-standings td.team .cell{display:flex;align-items:center;gap:.5rem}'
			. '.vd-standings img.crest{width:20px;height:20px;object-fit:contain;flex:none}'
			. '.vd-standings td.pts{color:var(--color-ink,#111);font-weight:700}'
			. '.vd-standings td.dg.up{color:var(--vds-promo)}.vd-standings td.dg.down{color:var(--vds-releg)}'
			. '.vd-form{display:inline-flex;gap:2px}'
			. '.vd-form i{width:15px;height:15px;border-radius:3px;font-size:9px;font-weight:700;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-style:normal}'
			. '.vd-form i.W{background:var(--vds-promo)}.vd-form i.D{background:#8a938a}.vd-form i.L{background:var(--vds-releg)}'
			. '.vd-standings-legend{display:flex;flex-wrap:wrap;gap:1rem;padding:.6rem 1rem;font-size:.7rem;color:var(--color-ink-muted,#666)}'
			. '.vd-standings-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;vertical-align:middle;margin-right:.3rem}'
			. '.vd-standings-legend .z-promo{background:var(--vds-promo)}.vd-standings-legend .z-playoff{background:var(--vds-playoff)}.vd-standings-legend .z-releg{background:var(--vds-releg)}'
			. '.vd-standings--empty{padding:1rem;color:var(--color-ink-muted,#666);font-family:var(--font-ui,system-ui,sans-serif);font-size:.85rem}'
			. '</style>';
	}
}
