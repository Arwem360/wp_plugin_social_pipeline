<?php
/**
 * Renderer de placas: toda la geometría del layout vive acá (una sola vez) y se
 * dibuja a través de un VD_Social_Placa_Canvas (Imagick o GD).
 *
 * Reglas clave implementadas como cálculo (no a ojo):
 * - Anclaje del bloque de texto desde abajo (nunca pisa la franja / zona segura).
 * - Anti-colisión del marcador contra el bloque de título (>= 30 px de aire).
 * - Zonas seguras de IG en la variante historia (250 px arriba, 340 px abajo).
 * - Centrado vertical de texto en cajas/cinta/franja por bbox real.
 * - Rombos dibujados como polígonos (Bebas Neue no trae el glifo ◆).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Placa_Renderer {

	/**
	 * Configuración de la variante "feed" (1080×1350).
	 *
	 * @return array<string,mixed>
	 */
	public static function cfg_feed(): array {
		return array(
			'w'              => 1080,
			'h'              => 1350,
			'is_story'       => false,
			'safe_top'       => 0,
			'safe_bottom'    => 0,
			'strip_h'        => 72,
			'side'           => 60,
			'brand_top'      => 56,
			'brand_wm_size'  => 52,
			'ribbon_h'       => 58,
			'ribbon_font'    => 34,
			'title_start'    => 84,
			'title_min'      => 56,
			'title_leading'  => 104,
			'title_max_line' => 4,
			'box_h'          => 58,
			'box_font'       => 30,
			'score_full'     => 230,
			'score_small'    => 180,
			'score_floor'    => 140,
			'score_sub_h'    => 58,
		);
	}

	/**
	 * Configuración de la variante "historia" (1080×1920).
	 *
	 * @return array<string,mixed>
	 */
	public static function cfg_story(): array {
		return array(
			'w'              => 1080,
			'h'              => 1920,
			'is_story'       => true,
			'safe_top'       => 250,
			'safe_bottom'    => 340,
			'strip_h'        => 72,
			'side'           => 60,
			'brand_top'      => 270,
			'brand_wm_size'  => 58,
			'ribbon_h'       => 66,
			'ribbon_font'    => 38,
			'title_start'    => 92,
			'title_min'      => 56,
			'title_leading'  => 114,
			'title_max_line' => 4,
			'box_h'          => 62,
			'box_font'       => 34,
			'score_full'     => 280,
			'score_small'    => 200,
			'score_floor'    => 150,
			'score_sub_h'    => 64,
		);
	}

	// --- Constantes de estilo comunes ---------------------------------------

	private const FRAME_MARGIN    = 14;
	private const FRAME_THICKNESS = 3;
	private const BRAND_MARGIN    = 56;
	private const LOGO_MAX_H       = 120;
	private const GAP_RIBBON_TITLE = 28;
	private const GAP_TITLE_BOX     = 24;
	private const GAP_BOX_HANDLE    = 16;
	private const FEED_SAFETY       = 36;
	private const BOX_PAD_X         = 26;
	private const DIAMOND_R_BOX     = 9;
	private const DIAMOND_R_STRIP   = 8;
	private const SCORE_AIR         = 30;

	private const TITLE_TOP    = '#FFFFFF';
	private const TITLE_BOTTOM = '#C4CAD2';
	private const OUTLINE      = '#000000';
	private const SHADOW       = '#000000';

	/**
	 * Dibuja la placa completa en el canvas.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $cfg
	 */
	public function render( VD_Social_Placa_Canvas $c, array $data, array $cfg ): void {
		$anton = VD_Social_Placa_Fonts::anton();
		$bebas = VD_Social_Placa_Fonts::bebas();

		// 1) Fondo.
		$has_image = false;
		if ( '' !== (string) $data['image_path'] ) {
			$has_image = $c->draw_cover( (string) $data['image_path'] );
		}
		if ( ! $has_image ) {
			$this->draw_texture_bg( $c, $data );
		} else {
			$c->vignette( 0.5 );
		}

		// 2) Gradiente de legibilidad (desde ~45% hacia abajo).
		$c->gradient_overlay( (int) round( $cfg['h'] * 0.45 ), $cfg['h'], '#000000', 0, 230 );

		// 3) Título: elegir tamaño y envolver ANTES de posicionar nada.
		$title      = VD_Social_Placa_Data::upper( (string) $data['title'] );
		$max_width  = $cfg['w'] - 2 * $cfg['side'];
		$fit        = $this->fit_title( $c, $title, $anton, $max_width, $cfg );
		$title_size = $fit['size'];
		$lines      = $fit['lines'];
		$n_lines    = count( $lines );

		// 4) Layout del bloque inferior (anclado desde abajo).
		$layout    = $this->layout_block( $c, $data, $cfg, $n_lines, $bebas );
		$title_top = $layout['title_top'];

		// 5) Marca (arriba a la izquierda).
		$this->draw_brand( $c, $data, $cfg, $bebas );

		// 6) Marcador (si corresponde), con anti-colisión contra el título.
		if ( '' !== trim( (string) $data['score'] ) ) {
			$this->draw_score( $c, $data, $cfg, $anton, $bebas, $title_top, $n_lines );
		}

		// 7) Cinta de categoría (opcional, por default apagada).
		if ( ! empty( $data['show_category'] ) && '' !== (string) $data['category'] ) {
			$this->draw_ribbon( $c, $data, $cfg, $bebas, $cfg['side'], $layout['ribbon_top'] );
		}

		// 8) Título (líneas con degradado + contorno + sombra).
		foreach ( $lines as $i => $line ) {
			$y_top = $title_top + $i * $cfg['title_leading'];
			$c->text_gradient_line(
				$cfg['side'],
				$y_top,
				$line,
				$anton,
				$title_size,
				self::TITLE_TOP,
				self::TITLE_BOTTOM,
				self::OUTLINE,
				4,
				5,
				6,
				self::SHADOW,
				190
			);
		}

		// 9) Caja de fecha.
		if ( $layout['has_date'] ) {
			$this->draw_badge(
				$c,
				$cfg['side'],
				$layout['date_top'],
				$layout['date_w'],
				$cfg['box_h'],
				VD_Social_Placa_Data::upper( (string) $data['date'] ),
				$bebas,
				$cfg['box_font'],
				$data
			);
		}

		// 10) Franja inferior naranja (ambas variantes; en historia va arriba de la
		// zona segura de IG).
		$this->draw_bottom_strip( $c, $data, $cfg, $bebas );

		// 11) Marco.
		$this->draw_frame( $c, $cfg, $data );
	}

	// --- Layout --------------------------------------------------------------

	/**
	 * Calcula las coordenadas del bloque inferior anclado desde abajo.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $cfg
	 * @return array<string,mixed>
	 */
	private function layout_block( VD_Social_Placa_Canvas $c, array $data, array $cfg, int $n_lines, string $bebas ): array {
		$has_date = '' !== (string) $data['date'];
		$leading  = (int) $cfg['title_leading'];
		$title_h  = $n_lines * $leading;

		// La franja apoya sobre el borde inferior (feed) o sobre el límite de la
		// zona segura de IG (historia). El bloque de texto se ancla arriba de ella.
		$strip_bottom = $this->strip_bottom( $cfg );
		$block_bottom = $strip_bottom - $cfg['strip_h'] - self::FEED_SAFETY;

		$out    = array( 'has_date' => $has_date );
		$cursor = $block_bottom; // apilamos hacia arriba.

		// Caja de fecha.
		if ( $has_date ) {
			$date_text       = VD_Social_Placa_Data::upper( (string) $data['date'] );
			$out['date_w']   = $this->badge_width( $c, $date_text, $bebas, $cfg['box_font'] );
			$out['date_top'] = $cursor - $cfg['box_h'];
			$cursor          = $out['date_top'] - self::GAP_TITLE_BOX;
		}

		// Título.
		$out['title_bottom'] = $cursor;
		$out['title_top']    = $cursor - $title_h;
		$cursor              = $out['title_top'] - self::GAP_RIBBON_TITLE;

		// Cinta (arriba del título).
		$out['ribbon_top'] = $cursor - $cfg['ribbon_h'];

		return $out;
	}

	/**
	 * Y del borde inferior de la franja naranja.
	 *
	 * @param array<string,mixed> $cfg
	 */
	private function strip_bottom( array $cfg ): int {
		return ! empty( $cfg['is_story'] ) ? ( (int) $cfg['h'] - (int) $cfg['safe_bottom'] ) : (int) $cfg['h'];
	}

	/**
	 * Elige el tamaño de título que entra en <= max_line líneas y devuelve las líneas.
	 *
	 * @param array<string,mixed> $cfg
	 * @return array{size:int,lines:array<int,string>}
	 */
	private function fit_title( VD_Social_Placa_Canvas $c, string $text, string $font, int $max_width, array $cfg ): array {
		$max_lines = (int) $cfg['title_max_line'];
		for ( $size = (int) $cfg['title_start']; $size >= (int) $cfg['title_min']; $size -= 4 ) {
			$lines = $this->wrap( $c, $text, $font, $size, $max_width );
			if ( count( $lines ) <= $max_lines ) {
				return array(
					'size'  => $size,
					'lines' => $lines,
				);
			}
		}
		// No entra ni en el mínimo: envolver al mínimo y truncar con "…".
		$size  = (int) $cfg['title_min'];
		$lines = $this->wrap( $c, $text, $font, $size, $max_width );
		if ( count( $lines ) > $max_lines ) {
			$lines   = array_slice( $lines, 0, $max_lines );
			$last    = $lines[ $max_lines - 1 ];
			$lines[ $max_lines - 1 ] = $this->truncate_line( $c, $last, $font, $size, $max_width );
		}
		return array(
			'size'  => $size,
			'lines' => $lines,
		);
	}

	/**
	 * Word-wrap greedy por ancho.
	 *
	 * @return array<int,string>
	 */
	private function wrap( VD_Social_Placa_Canvas $c, string $text, string $font, int $size, int $max_width ): array {
		$words = preg_split( '/\s+/u', trim( $text ) );
		if ( empty( $words ) ) {
			return array( '' );
		}
		$lines = array();
		$cur   = '';
		foreach ( $words as $w ) {
			$try = '' === $cur ? $w : $cur . ' ' . $w;
			$m   = $c->measure( $try, $font, $size );
			if ( '' === $cur || $m['width'] <= $max_width ) {
				$cur = $try;
			} else {
				$lines[] = $cur;
				$cur     = $w;
			}
		}
		$lines[] = $cur;
		return $lines;
	}

	private function truncate_line( VD_Social_Placa_Canvas $c, string $line, string $font, int $size, int $max_width ): string {
		$ell = '…';
		while ( '' !== $line ) {
			$m = $c->measure( $line . $ell, $font, $size );
			if ( $m['width'] <= $max_width ) {
				return $line . $ell;
			}
			$line = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, -1, 'UTF-8' ) : substr( $line, 0, -1 );
		}
		return $ell;
	}

	// --- Marca ---------------------------------------------------------------

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $cfg
	 */
	private function draw_brand( VD_Social_Placa_Canvas $c, array $data, array $cfg, string $bebas ): void {
		$top = (int) $cfg['brand_top'];

		if ( '' !== (string) $data['logo_path'] ) {
			if ( $c->draw_png( (string) $data['logo_path'], self::BRAND_MARGIN, $top, self::LOGO_MAX_H ) ) {
				return;
			}
		}

		// Wordmark tipográfico: barrita + VERMOUTH / DEPORTIVO.
		$size = (int) $cfg['brand_wm_size'];
		$m1   = $c->measure( 'VERMOUTH', $bebas, $size );
		$m2   = $c->measure( 'DEPORTIVO', $bebas, $size );
		$gap  = 6;
		$line1_top = $top;
		$line2_top = $top + $m1['height'] + $gap;
		$bar_h     = ( $line2_top + $m2['height'] ) - $top;

		$c->fill_rect( self::BRAND_MARGIN, $top, 10, $bar_h, (string) $data['accent'] );
		$tx = self::BRAND_MARGIN + 10 + 18;
		$c->text_line( $tx, $line1_top, 'VERMOUTH', $bebas, $size, '#FFFFFF' );
		$c->text_line( $tx, $line2_top, 'DEPORTIVO', $bebas, $size, (string) $data['accent'] );
	}

	// --- Marcador ------------------------------------------------------------

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $cfg
	 */
	private function draw_score( VD_Social_Placa_Canvas $c, array $data, array $cfg, string $anton, string $bebas, int $title_top, int $n_lines ): void {
		$score  = trim( (string) $data['score'] );
		$sub    = trim( (string) $data['score_sub'] );
		$right  = $cfg['w'] - $cfg['side'];
		$min_top = (int) $cfg['brand_top'] + self::LOGO_MAX_H + 24;

		// Tamaño inicial: reducido si el título usa 4 líneas.
		$size = ( $n_lines >= 4 ) ? (int) $cfg['score_small'] : (int) $cfg['score_full'];

		// Reducir hasta que el bloque entre arriba del título (aire >= 30 px) y
		// no invada la zona de la marca.
		$block_bottom = $title_top - self::SCORE_AIR;
		while ( true ) {
			$mn        = $c->measure( $score, $anton, $size );
			$sub_h     = '' !== $sub ? (int) $cfg['score_sub_h'] : 0;
			$sub_gap   = '' !== $sub ? 16 : 0;
			$block_h   = $mn['height'] + $sub_gap + $sub_h;
			$block_top = $block_bottom - $block_h;
			if ( $block_top >= $min_top || $size <= (int) $cfg['score_floor'] ) {
				break;
			}
			$size -= 10;
		}

		$mn        = $c->measure( $score, $anton, $size );
		$sub_h     = '' !== $sub ? (int) $cfg['score_sub_h'] : 0;
		$sub_gap   = '' !== $sub ? 16 : 0;
		$block_h   = $mn['height'] + $sub_gap + $sub_h;
		$block_top = $block_bottom - $block_h;

		// Número, alineado a la derecha.
		$num_x = $right - $mn['width'];
		$c->text_gradient_line(
			$num_x,
			$block_top,
			$score,
			$anton,
			$size,
			'#FFFFFF',
			(string) $data['accent_light'],
			self::OUTLINE,
			6,
			6,
			8,
			self::SHADOW,
			200
		);

		// Caja de subtítulo debajo, alineada a la derecha.
		if ( '' !== $sub ) {
			$sub_text = VD_Social_Placa_Data::upper( $sub );
			$sub_w    = $this->badge_width( $c, $sub_text, $bebas, (int) $cfg['box_font'] );
			$sub_x    = $right - $sub_w;
			$sub_y    = $block_top + $mn['height'] + $sub_gap;
			$this->draw_badge( $c, $sub_x, $sub_y, $sub_w, $sub_h, $sub_text, $bebas, (int) $cfg['box_font'], $data );
		}
	}

	// --- Cinta de categoría --------------------------------------------------

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $cfg
	 */
	private function draw_ribbon( VD_Social_Placa_Canvas $c, array $data, array $cfg, string $bebas, int $rx, int $ry ): void {
		$text  = (string) $data['category'];
		$font  = (int) $cfg['ribbon_font'];
		$rh    = (int) $cfg['ribbon_h'];
		$pad_x = 30;
		$notch = 20;
		$m     = $c->measure( $text, $bebas, $font );
		$rw    = $pad_x * 2 + $m['width'] + $notch;

		// Cola izquierda doblada (naranja oscuro), detrás.
		$tail_w = 22;
		$tail_h = 14;
		$c->polygon(
			array(
				array( $rx, $ry + $rh - 2 ),
				array( $rx, $ry + $rh + $tail_h ),
				array( $rx + $tail_w, $ry + $rh ),
			),
			(string) $data['accent_dark']
		);

		// Cuerpo con punta derecha recortada en "V".
		$c->polygon(
			array(
				array( $rx, $ry ),
				array( $rx + $rw, $ry ),
				array( $rx + $rw - $notch, $ry + (int) round( $rh / 2 ) ),
				array( $rx + $rw, $ry + $rh ),
				array( $rx, $ry + $rh ),
			),
			(string) $data['accent']
		);

		// Filete de brillo en el borde superior.
		$c->fill_rect( $rx, $ry, $rw - $notch, 3, (string) $data['accent_light'] );

		// Texto centrado vertical por bbox real.
		$vm = $c->vmetrics( $text, $bebas, $font );
		$ty = $ry + (int) round( ( $rh - $vm['height'] ) / 2 );
		$c->text_line( $rx + $pad_x, $ty, $text, $bebas, $font, '#FFFFFF' );
	}

	// --- Cajas negras con borde naranja y rombos -----------------------------

	private function badge_width( VD_Social_Placa_Canvas $c, string $text, string $font, int $size ): int {
		$m = $c->measure( $text, $font, $size );
		$r = self::DIAMOND_R_BOX;
		$g = 14;
		return self::BOX_PAD_X * 2 + ( 2 * $r ) + $g + $m['width'] + $g + ( 2 * $r );
	}

	/**
	 * Caja negra con filete interior naranja de 2 px, rombos dibujados a los
	 * lados y texto (naranja claro) centrado vertical por bbox.
	 *
	 * @param array<string,mixed> $data
	 */
	private function draw_badge( VD_Social_Placa_Canvas $c, int $x, int $y, int $w, int $h, string $text, string $font, int $size, array $data ): void {
		$c->fill_rect( $x, $y, $w, $h, '#000000', 235 );
		$c->stroke_rect( $x + 4, $y + 4, $w - 8, $h - 8, (string) $data['accent'], 2 );

		$m  = $c->measure( $text, $font, $size );
		$vm = $c->vmetrics( $text, $font, $size );
		$r  = self::DIAMOND_R_BOX;
		$g  = 14;
		$grp = ( 2 * $r ) + $g + $m['width'] + $g + ( 2 * $r );
		$sx = $x + (int) round( ( $w - $grp ) / 2 );
		$cy = $y + (int) round( $h / 2 );

		$this->diamond( $c, $sx + $r, $cy, $r, (string) $data['accent'] );
		$tx = $sx + 2 * $r + $g;
		$ty = $y + (int) round( ( $h - $vm['height'] ) / 2 );
		$c->text_line( $tx, $ty, $text, $font, $size, (string) $data['accent_light'] );
		$rx = $tx + $m['width'] + $g + $r;
		$this->diamond( $c, $rx, $cy, $r, (string) $data['accent'] );
	}

	private function diamond( VD_Social_Placa_Canvas $c, int $cx, int $cy, int $r, string $hex ): void {
		$c->polygon(
			array(
				array( $cx, $cy - $r ),
				array( $cx + $r, $cy ),
				array( $cx, $cy + $r ),
				array( $cx - $r, $cy ),
			),
			$hex
		);
	}

	// --- Franja inferior (feed) ----------------------------------------------

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $cfg
	 */
	private function draw_bottom_strip( VD_Social_Placa_Canvas $c, array $data, array $cfg, string $bebas ): void {
		$w     = (int) $cfg['w'];
		$strip = (int) $cfg['strip_h'];
		$top   = $this->strip_bottom( $cfg ) - $strip;

		// Barra.
		$c->fill_rect( 0, $top, $w, $strip, (string) $data['accent'] );

		// Doble filete de remate por encima de la barra.
		$c->line( 40, $top - 12, $w - 40, $top - 12, (string) $data['accent_light'], 2 );
		$c->line( 40, $top - 6, $w - 40, $top - 6, (string) $data['accent_light'], 2 );

		// Texto handle/dominio centrado (blanco), rombo dibujado como separador.
		$this->draw_handle_domain(
			$c,
			(int) round( $w / 2 ),
			$top + (int) round( $strip / 2 ),
			(string) $data['handle_domain'],
			$bebas,
			30,
			'#FFFFFF',
			'#FFFFFF',
			'#FFFFFF',
			self::DIAMOND_R_STRIP
		);
	}

	/**
	 * Dibuja "HANDLE ◆ DOMINIO" centrado en (center_x, center_y), con el rombo
	 * como polígono. handle y dominio pueden tener colores distintos.
	 */
	private function draw_handle_domain( VD_Social_Placa_Canvas $c, int $center_x, int $center_y, string $text, string $font, int $size, string $handle_hex, string $domain_hex, string $diamond_hex, int $r ): void {
		$parts  = preg_split( '/\s*[·•]\s*/u', $text, 2 );
		$handle = VD_Social_Placa_Data::upper( isset( $parts[0] ) ? $parts[0] : '' );
		$domain = VD_Social_Placa_Data::upper( isset( $parts[1] ) ? $parts[1] : '' );

		$g   = 16;
		$mh  = $c->measure( $handle, $font, $size );
		$vh  = $c->vmetrics( $handle, $font, $size );
		$total = $mh['width'];
		$md    = null;
		$vd    = null;
		if ( '' !== $domain ) {
			$md    = $c->measure( $domain, $font, $size );
			$vd    = $c->vmetrics( $domain, $font, $size );
			$total += $g + ( 2 * $r ) + $g + $md['width'];
		}

		$x = $center_x - (int) round( $total / 2 );

		$c->text_line( $x, $center_y - (int) round( $vh['height'] / 2 ), $handle, $font, $size, $handle_hex );
		$x += $mh['width'];

		if ( '' !== $domain && null !== $md ) {
			$this->diamond( $c, $x + $g + $r, $center_y, $r, $diamond_hex );
			$x += $g + 2 * $r + $g;
			$c->text_line( $x, $center_y - (int) round( $vd['height'] / 2 ), $domain, $font, $size, $domain_hex );
		}
	}

	// --- Marco y fondo -------------------------------------------------------

	/**
	 * @param array<string,mixed> $cfg
	 * @param array<string,mixed> $data
	 */
	private function draw_frame( VD_Social_Placa_Canvas $c, array $cfg, array $data ): void {
		$m = self::FRAME_MARGIN;
		$c->stroke_rect( $m, $m, $cfg['w'] - 2 * $m, $cfg['h'] - 2 * $m, (string) $data['accent'], self::FRAME_THICKNESS );
		$mi = $m + self::FRAME_THICKNESS + 1;
		$c->stroke_rect( $mi, $mi, $cfg['w'] - 2 * $mi, $cfg['h'] - 2 * $mi, '#FFFFFF', 1, 180 );
	}

	/**
	 * Fondo de marca (cuando la nota no tiene imagen destacada).
	 *
	 * @param array<string,mixed> $data
	 */
	private function draw_texture_bg( VD_Social_Placa_Canvas $c, array $data ): void {
		$c->fill( (string) $data['accent_dark'] );
		// Textura sutil: líneas diagonales del color de marca a baja opacidad.
		$w = $c->width();
		$h = $c->height();
		for ( $x = -$h; $x < $w; $x += 46 ) {
			$c->line( $x, 0, $x + $h, $h, (string) $data['accent'], 2 );
		}
		$c->fill_rect( 0, 0, $w, $h, '#000000', 60 );
	}
}
