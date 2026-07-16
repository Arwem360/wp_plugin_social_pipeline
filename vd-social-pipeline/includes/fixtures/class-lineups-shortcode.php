<?php
/**
 * Shortcode [vd_formaciones] — formaciones de un partido desde la API: cancha
 * con titulares (posicionados por su `grid`), suplentes y DT. Server-side, con
 * caché por transient.
 *
 * Uso:
 *   [vd_formaciones fixture="1545443"]
 *   [vd_formaciones fixture="1545443" equipo="435"]
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Lineups_Shortcode {

	public const SHORTCODE      = 'vd_formaciones';
	private const CACHE_PREFIX  = 'vd_lineups_';
	private const CACHE_TTL     = 15 * MINUTE_IN_SECONDS;
	private const CACHE_TTL_ERR = 3 * MINUTE_IN_SECONDS;

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
			),
			$atts,
			self::SHORTCODE
		);

		$fixture = preg_replace( '/[^0-9]/', '', (string) $atts['fixture'] );
		if ( '' === $fixture ) {
			return '';
		}
		$team = preg_replace( '/[^0-9]/', '', (string) $atts['equipo'] );

		$data = $this->get_data( $fixture, $team );

		// Error de la API: no le mostramos nada al lector.
		if ( is_wp_error( $data ) ) {
			return '';
		}

		$equipos    = isset( $data['equipos'] ) && is_array( $data['equipos'] ) ? $data['equipos'] : array();
		$disponible = ! empty( $data['disponible'] );

		// Todavía no hay formaciones para este partido (se cargan ~20-40 min antes).
		if ( ! $disponible || empty( $equipos ) ) {
			return $this->notice();
		}

		$local  = isset( $equipos[0] ) && is_array( $equipos[0] ) ? $equipos[0] : array();
		$visita = isset( $equipos[1] ) && is_array( $equipos[1] ) ? $equipos[1] : array();

		// La API a veces marca disponible=true sin cargar el XI: sin titulares no
		// hay nada que dibujar ni listar.
		if ( empty( $local['titulares'] ) && empty( $visita['titulares'] ) ) {
			return $this->notice();
		}

		// ¿El partido trae posiciones? En Primera Nacional y el ascenso es frecuente
		// que lleguen los jugadores pero sin `grid`. Sin grid no se puede posicionar
		// a nadie en la cancha: en ese caso listamos en vez de dibujar una cancha vacía.
		$hay_grid = $this->has_grid( $local ) || $this->has_grid( $visita );

		ob_start();
		echo $this->css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="vd-lu">
			<div class="vd-lu-heads">
				<?php echo $this->team_head( $local, 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->team_head( $visita, 'away' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php if ( $hay_grid ) : ?>
				<div class="vd-lu-pitch">
					<div class="vd-lu-lines"></div>
					<?php echo $this->pitch_players( $local, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->pitch_players( $visita, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php else : ?>
				<div class="vd-lu-list">
					<?php echo $this->starters_list( $local ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->starters_list( $visita ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
			<div class="vd-lu-subs">
				<?php echo $this->subs( $local ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->subs( $visita ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * ¿Algún titular del equipo trae `grid` (fila:columna)?
	 *
	 * @param array<string,mixed> $t
	 */
	private function has_grid( array $t ): bool {
		$titulares = isset( $t['titulares'] ) && is_array( $t['titulares'] ) ? $t['titulares'] : array();
		foreach ( $titulares as $p ) {
			if ( ! empty( $p['grid'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Aviso de "todavía no hay formaciones".
	 */
	private function notice(): string {
		return $this->css()
			. '<div class="vd-lu vd-lu--empty">'
			. esc_html__( 'Formaciones no disponibles todavía.', 'vd-social-pipeline' )
			. '</div>';
	}

	/**
	 * Lista de titulares (fallback cuando el partido no trae posiciones).
	 *
	 * @param array<string,mixed> $t
	 */
	private function starters_list( array $t ): string {
		$equipo    = isset( $t['equipo'] ) && is_array( $t['equipo'] ) ? $t['equipo'] : array();
		$titulares = isset( $t['titulares'] ) && is_array( $t['titulares'] ) ? $t['titulares'] : array();

		ob_start();
		?>
		<div class="vd-lu-list-col">
			<div class="vd-lu-subs-title"><?php echo esc_html__( 'Titulares', 'vd-social-pipeline' ) . ' · ' . esc_html( $equipo['nombre'] ?? '' ); ?></div>
			<?php foreach ( $titulares as $p ) : ?>
				<?php $pos = trim( (string) ( $p['pos'] ?? '' ) ); ?>
				<div class="vd-lu-sub">
					<span class="vd-lu-sub-num"><?php echo esc_html( (string) ( $p['numero'] ?? '' ) ); ?></span>
					<span class="vd-lu-sub-name"><?php echo esc_html( (string) ( $p['nombre'] ?? '' ) ); ?></span>
					<?php if ( '' !== $pos ) : ?>
						<span class="vd-lu-sub-pos"><?php echo esc_html( $pos ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $t
	 */
	private function team_head( array $t, string $side ): string {
		$equipo = isset( $t['equipo'] ) && is_array( $t['equipo'] ) ? $t['equipo'] : array();
		$dt     = isset( $t['dt']['nombre'] ) ? trim( (string) $t['dt']['nombre'] ) : '';
		$logo   = isset( $equipo['logo'] ) ? (string) $equipo['logo'] : '';

		// `formacion` puede venir null (partidos sin cobertura de posiciones): en ese
		// caso se omite el dato y no queda el separador suelto.
		$formacion = isset( $t['formacion'] ) ? trim( (string) $t['formacion'] ) : '';
		$bits      = array();
		if ( '' !== $formacion ) {
			$bits[] = $formacion;
		}
		if ( '' !== $dt ) {
			$bits[] = 'DT ' . $dt;
		}
		$sub = implode( ' · ', $bits );

		ob_start();
		?>
		<div class="vd-lu-head <?php echo esc_attr( $side ); ?>">
			<?php if ( '' !== $logo ) : ?>
				<img class="vd-lu-head-logo" src="<?php echo esc_url( $logo ); ?>" alt="" loading="lazy" width="26" height="26">
			<?php endif; ?>
			<div>
				<div class="vd-lu-head-name"><?php echo esc_html( $equipo['nombre'] ?? '' ); ?></div>
				<div class="vd-lu-head-sub"><?php echo esc_html( $sub ); ?></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Posiciona los titulares en la mitad de cancha del equipo (misma lógica que
	 * la demo: fila por `grid`, columna repartida, local a la izquierda).
	 *
	 * @param array<string,mixed> $t
	 */
	private function pitch_players( array $t, bool $es_local ): string {
		$titulares = isset( $t['titulares'] ) && is_array( $t['titulares'] ) ? $t['titulares'] : array();
		$colores   = isset( $t['colores'] ) && is_array( $t['colores'] ) ? $t['colores'] : array();

		// Agrupar por fila (primer número del grid "fila:columna").
		$rows = array();
		foreach ( $titulares as $p ) {
			if ( empty( $p['grid'] ) ) {
				continue;
			}
			$parts = explode( ':', (string) $p['grid'] );
			$x     = (int) ( $parts[0] ?? 0 );
			$y     = (int) ( $parts[1] ?? 0 );
			if ( $x <= 0 ) {
				continue;
			}
			$rows[ $x ][] = array( 'p' => $p, 'y' => $y );
		}
		if ( empty( $rows ) ) {
			return '';
		}
		ksort( $rows );
		$max_row = max( array_keys( $rows ) );

		$out = '';
		foreach ( $rows as $x => $list ) {
			usort(
				$list,
				static function ( $a, $b ) {
					return $a['y'] <=> $b['y'];
				}
			);
			$n    = count( $list );
			$frac = $max_row > 1 ? ( $x - 1 ) / ( $max_row - 1 ) : 0;
			$left = $es_local ? 4 + $frac * 44 : 96 - $frac * 44;

			$i = 0;
			foreach ( $list as $item ) {
				$p   = $item['p'];
				$top = 9 + ( ( $i + 0.5 ) / $n ) * 82;
				$col = ( ( $p['pos'] ?? '' ) === 'G' )
					? ( isset( $colores['goalkeeper'] ) && is_array( $colores['goalkeeper'] ) ? $colores['goalkeeper'] : array() )
					: ( isset( $colores['player'] ) && is_array( $colores['player'] ) ? $colores['player'] : array() );

				$bg = $this->hex( $col['primary'] ?? '', '#444' );
				$fg = $this->hex( $col['number'] ?? '', '#fff' );
				$bd = $this->hex( $col['border'] ?? '', '#fff' );

				$style = sprintf( 'left:%s%%;top:%s%%', round( $left, 2 ), round( $top, 2 ) );
				$shirt = sprintf( 'background:%s;color:%s;border-color:%s', $bg, $fg, $bd );

				$out .= '<div class="vd-lu-player" style="' . esc_attr( $style ) . '">'
					. '<span class="vd-lu-shirt" style="' . esc_attr( $shirt ) . '">' . esc_html( (string) ( $p['numero'] ?? '' ) ) . '</span>'
					. '<span class="vd-lu-pname">' . esc_html( (string) ( $p['nombre'] ?? '' ) ) . '</span>'
					. '</div>';
				++$i;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $t
	 */
	private function subs( array $t ): string {
		$equipo    = isset( $t['equipo'] ) && is_array( $t['equipo'] ) ? $t['equipo'] : array();
		$suplentes = isset( $t['suplentes'] ) && is_array( $t['suplentes'] ) ? $t['suplentes'] : array();

		ob_start();
		?>
		<div class="vd-lu-subs-col">
			<div class="vd-lu-subs-title"><?php echo esc_html__( 'Suplentes', 'vd-social-pipeline' ) . ' · ' . esc_html( $equipo['nombre'] ?? '' ); ?></div>
			<?php foreach ( $suplentes as $p ) : ?>
				<?php $pos = trim( (string) ( $p['pos'] ?? '' ) ); ?>
				<div class="vd-lu-sub">
					<span class="vd-lu-sub-num"><?php echo esc_html( (string) ( $p['numero'] ?? '' ) ); ?></span>
					<span class="vd-lu-sub-name"><?php echo esc_html( (string) ( $p['nombre'] ?? '' ) ); ?></span>
					<?php if ( '' !== $pos ) : ?>
						<span class="vd-lu-sub-pos"><?php echo esc_html( $pos ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function hex( $c, string $fallback ): string {
		$c = preg_replace( '/[^0-9a-fA-F]/', '', (string) $c );
		if ( 6 === strlen( $c ) || 3 === strlen( $c ) ) {
			return '#' . $c;
		}
		return $fallback;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_data( string $fixture, string $team ) {
		$key    = self::CACHE_PREFIX . md5( $fixture . '|' . $team );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$base   = (string) VD_Social_Fixtures_Module::config()['api_base'];
		$client = new VD_Social_Fixtures_Api_Client( $base );
		$data   = $client->lineups( $fixture, $team );
		set_transient( $key, $data, is_wp_error( $data ) ? self::CACHE_TTL_ERR : self::CACHE_TTL );
		return $data;
	}

	private function css(): string {
		if ( self::$css_done ) {
			return '';
		}
		self::$css_done = true;

		return '<style>'
			. '.vd-lu{font-family:var(--font-ui,system-ui,sans-serif);color:var(--color-ink,#111);border:1px solid var(--color-rule,#e8e8e8);border-radius:var(--radius,2px);background:var(--color-surface,#fff);margin:0 0 1.5rem;overflow:hidden}'
			. '.vd-lu-heads{display:grid;grid-template-columns:1fr 1fr}'
			. '.vd-lu-head{display:flex;align-items:center;gap:.6rem;padding:.8rem 1rem;border-bottom:1px solid var(--color-rule,#e8e8e8)}'
			. '.vd-lu-head.away{flex-direction:row-reverse;text-align:right;border-left:1px solid var(--color-rule,#e8e8e8)}'
			. '.vd-lu-head-logo{width:26px;height:26px;object-fit:contain;flex:none}'
			. '.vd-lu-head-name{font-weight:700;font-size:.9rem}'
			. '.vd-lu-head-sub{font-size:.72rem;color:var(--color-ink-muted,#666)}'
			. '.vd-lu-pitch{position:relative;width:100%;aspect-ratio:16/10;overflow:hidden;background:repeating-linear-gradient(90deg,#2e7d43 0 6.25%,#2a7640 6.25% 12.5%)}'
			. '.vd-lu-lines{position:absolute;inset:0;pointer-events:none}'
			. '.vd-lu-lines::before{content:"";position:absolute;left:50%;top:0;bottom:0;width:2px;transform:translateX(-50%);background:rgba(255,255,255,.35)}'
			. '.vd-lu-lines::after{content:"";position:absolute;left:50%;top:50%;width:16%;aspect-ratio:1;transform:translate(-50%,-50%);border:2px solid rgba(255,255,255,.35);border-radius:50%}'
			. '.vd-lu-player{position:absolute;transform:translate(-50%,-50%);display:flex;flex-direction:column;align-items:center;width:74px}'
			. '.vd-lu-shirt{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12.5px;font-variant-numeric:tabular-nums;border:2px solid;box-shadow:0 2px 5px rgba(0,0,0,.45)}'
			. '.vd-lu-pname{margin-top:4px;font-size:10px;line-height:1.2;color:#fff;text-align:center;white-space:nowrap;background:rgba(0,0,0,.42);border-radius:4px;padding:1px 5px}'
			. '.vd-lu-list{display:grid;grid-template-columns:1fr 1fr}'
			. '.vd-lu-list-col{padding:.85rem 1rem}.vd-lu-list-col+.vd-lu-list-col{border-left:1px solid var(--color-rule,#e8e8e8)}'
			. '.vd-lu-list+.vd-lu-subs{border-top:1px solid var(--color-rule,#e8e8e8)}'
			. '.vd-lu-subs{display:grid;grid-template-columns:1fr 1fr}'
			. '.vd-lu-subs-col{padding:.85rem 1rem}.vd-lu-subs-col+.vd-lu-subs-col{border-left:1px solid var(--color-rule,#e8e8e8)}'
			. '.vd-lu-subs-title{font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:var(--color-ink-muted,#666);margin-bottom:.5rem;font-weight:700}'
			. '.vd-lu-sub{display:flex;align-items:center;gap:.5rem;padding:.25rem 0;font-size:.8rem}'
			. '.vd-lu-sub-num{font-size:.7rem;color:var(--color-ink-muted,#666);min-width:1.2rem;text-align:right}'
			. '.vd-lu-sub-name{color:var(--color-ink,#111)}'
			. '.vd-lu-sub-pos{margin-left:auto;font-size:.62rem;color:var(--color-ink-muted,#666);border:1px solid var(--color-rule,#e8e8e8);border-radius:3px;padding:0 .3rem}'
			. '@media(max-width:560px){.vd-lu-player{width:54px}.vd-lu-shirt{width:25px;height:25px;font-size:11px}.vd-lu-pname{font-size:8.5px;padding:1px 3px}}'
			. '.vd-lu--empty{padding:1rem;color:var(--color-ink-muted,#666);font-size:.85rem}'
			. '</style>';
	}
}
