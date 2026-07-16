<?php
/**
 * Sincronizador: trae los partidos de hoy y mañana (por cada liga configurada)
 * y los inserta/actualiza en el CPT `vermouth_fixture` del tema (upsert por el ID
 * de la API). Escribe en los MISMOS meta keys que el tema ya renderiza, así la
 * tabla de posiciones y los widgets funcionan sin tocar nada.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Fixtures_Sync {

	/** CPT y taxonomía definidos por el tema. */
	private const CPT      = 'vermouth_fixture';
	private const TAX      = 'competition';
	private const META_API = 'fixture_api_id';

	/** Estados de la API (estado.corto) agrupados en los buckets del tema. */
	private const CORTO_FINAL = array( 'FT', 'AET', 'PEN' );
	private const CORTO_LIVE  = array( '1H', 'HT', '2H', 'ET', 'BT', 'P' );

	/**
	 * Ejecuta la sincronización completa. Devuelve un resumen para logueo/UI.
	 *
	 * @return array<string,int> created|updated|skipped|errors
	 */
	public function run(): array {
		$config = VD_Social_Fixtures_Module::config();

		$summary = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
		);

		$leagues = $config['leagues'];
		if ( empty( $leagues ) ) {
			return $summary;
		}

		$client = new VD_Social_Fixtures_Api_Client( (string) $config['api_base'] );
		$season = (string) $config['season'];

		foreach ( $this->target_dates() as $date ) {
			foreach ( $leagues as $league ) {
				$partidos = $client->fixtures_by_day( (string) $league, $date, $season );

				if ( is_wp_error( $partidos ) ) {
					++$summary['errors'];
					VD_Social_Logger::log( 'fixtures', 0, 0, 'error', $partidos->get_error_code(), $partidos->get_error_message() );
					continue;
				}

				foreach ( $partidos as $match ) {
					$result = $this->upsert( is_array( $match ) ? $match : array() );
					if ( isset( $summary[ $result ] ) ) {
						++$summary[ $result ];
					}
				}
			}
		}

		VD_Social_Logger::log(
			'fixtures',
			0,
			0,
			'info',
			'sync',
			sprintf(
				'Sync partidos: %d nuevos, %d actualizados, %d omitidos, %d errores.',
				$summary['created'],
				$summary['updated'],
				$summary['skipped'],
				$summary['errors']
			)
		);

		return $summary;
	}

	/**
	 * Inserta o actualiza un partido. Devuelve created|updated|skipped|errors.
	 */
	private function upsert( array $match ): string {
		$api_id = isset( $match['id'] ) ? (int) $match['id'] : 0;
		$home   = isset( $match['local']['nombre'] ) ? sanitize_text_field( (string) $match['local']['nombre'] ) : '';
		$away   = isset( $match['visitante']['nombre'] ) ? sanitize_text_field( (string) $match['visitante']['nombre'] ) : '';

		if ( $api_id <= 0 || '' === $home || '' === $away ) {
			return 'skipped';
		}

		$existing = $this->find_by_api_id( $api_id );

		$postarr = array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			/* translators: 1: equipo local, 2: equipo visitante. */
			'post_title'  => sprintf( _x( '%1$s vs %2$s', 'título de partido', 'vd-social-pipeline' ), $home, $away ),
		);

		if ( $existing > 0 ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
			$action        = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$action  = 'created';
		}

		if ( is_wp_error( $post_id ) || 0 === (int) $post_id ) {
			return 'errors';
		}
		$post_id = (int) $post_id;

		$this->write_meta( $post_id, $api_id, $home, $away, $match );
		$this->assign_competition( $post_id, $match );

		return $action;
	}

	/**
	 * Escribe todos los meta que el tema consume.
	 */
	private function write_meta( int $post_id, int $api_id, string $home, string $away, array $match ): void {
		$corto = isset( $match['estado']['corto'] ) ? strtoupper( (string) $match['estado']['corto'] ) : 'NS';
		$is_final = in_array( $corto, self::CORTO_FINAL, true );
		$is_live  = in_array( $corto, self::CORTO_LIVE, true );

		if ( $is_final ) {
			$estado = 'final';
		} elseif ( $is_live ) {
			$estado = 'live';
		} else {
			$estado = 'proximo';
		}

		$minute = isset( $match['estado']['minuto'] ) ? (string) (int) $match['estado']['minuto'] : '';

		$meta = array(
			self::META_API           => $api_id,
			'fixture_home'           => $home,
			'fixture_away'           => $away,
			'fixture_estado'         => $estado,
			'fixture_estado_corto'   => $corto,       // Estado fino para el label del tema.
			'fixture_live'           => $is_live ? '1' : '',
			'fixture_time'           => $is_live ? $minute : '',
			'fixture_score'          => $this->format_score( $match ),
			'fixture_penales'        => $this->format_penales( $match ),
			'fixture_ronda'          => isset( $match['ronda'] ) ? sanitize_text_field( (string) $match['ronda'] ) : '',
			'fixture_home_team_id'   => isset( $match['local']['id'] ) ? (int) $match['local']['id'] : 0,
			'fixture_away_team_id'   => isset( $match['visitante']['id'] ) ? (int) $match['visitante']['id'] : 0,
		);

		// Fecha desglosada (en la zona horaria del sitio) + string de display.
		$meta = array_merge( $meta, $this->format_fecha( $match ) );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		// Escudos: descarga única por equipo, guarda el ID de adjunto.
		$this->sync_logo( $post_id, 'fixture_home_logo_id', $match['local'] ?? array() );
		$this->sync_logo( $post_id, 'fixture_away_logo_id', $match['visitante'] ?? array() );
	}

	/**
	 * Descarga (si hace falta) y asigna el escudo de un equipo.
	 *
	 * @param array<string,mixed> $team
	 */
	private function sync_logo( int $post_id, string $meta_key, array $team ): void {
		$team_id = isset( $team['id'] ) ? (int) $team['id'] : 0;
		$logo    = isset( $team['logo'] ) ? esc_url_raw( (string) $team['logo'] ) : '';
		$name    = isset( $team['nombre'] ) ? (string) $team['nombre'] : '';

		if ( $team_id <= 0 || '' === $logo ) {
			return;
		}

		$attachment_id = VD_Social_Fixtures_Logos::attachment_for_team( $team_id, $logo, $name );
		if ( $attachment_id > 0 ) {
			update_post_meta( $post_id, $meta_key, $attachment_id );
		}
	}

	/**
	 * Marcador "L - V" si hubo goles cargados; si no, cadena vacía.
	 */
	private function format_score( array $match ): string {
		$goles = isset( $match['goles'] ) && is_array( $match['goles'] ) ? $match['goles'] : array();
		$local = $goles['local'] ?? null;
		$visit = $goles['visitante'] ?? null;

		if ( null === $local || null === $visit ) {
			return '';
		}
		return (int) $local . ' - ' . (int) $visit;
	}

	/**
	 * Penales "L - V" si el partido se definió por penales; si no, vacío.
	 */
	private function format_penales( array $match ): string {
		$score = isset( $match['score'] ) && is_array( $match['score'] ) ? $match['score'] : array();

		// API-Football normalizado: la definición por penales suele venir en
		// score.penalty (o score.penales). Contemplamos ambas variantes.
		foreach ( array( 'penalty', 'penales' ) as $key ) {
			if ( isset( $score[ $key ] ) && is_array( $score[ $key ] ) ) {
				$p     = $score[ $key ];
				$home  = $p['home'] ?? ( $p['local'] ?? null );
				$away  = $p['away'] ?? ( $p['visitante'] ?? null );
				if ( null !== $home && null !== $away ) {
					return (int) $home . ' - ' . (int) $away;
				}
			}
		}
		return '';
	}

	/**
	 * Convierte la fecha ISO (UTC) a la zona del sitio y la desglosa como el tema.
	 *
	 * @return array<string,string>
	 */
	private function format_fecha( array $match ): array {
		$iso = isset( $match['fecha'] ) ? (string) $match['fecha'] : '';
		if ( '' === $iso ) {
			return array();
		}

		try {
			$dt = new DateTimeImmutable( $iso );
			$dt = $dt->setTimezone( wp_timezone() );
		} catch ( Exception $e ) {
			return array();
		}

		return array(
			'fixture_fecha_dia'  => $dt->format( 'j' ),
			'fixture_fecha_mes'  => $dt->format( 'n' ),
			'fixture_fecha_anio' => $dt->format( 'Y' ),
			'fixture_fecha_hora' => $dt->format( 'H' ),
			'fixture_fecha_min'  => $dt->format( 'i' ),
			'fixture_fecha'      => $dt->format( 'd/m · H:i' ),
		);
	}

	/**
	 * Asegura el término de competición y lo asigna al partido.
	 */
	private function assign_competition( int $post_id, array $match ): void {
		$nombre = isset( $match['liga']['nombre'] ) ? sanitize_text_field( (string) $match['liga']['nombre'] ) : '';
		if ( '' === $nombre ) {
			return;
		}

		$term = get_term_by( 'name', $nombre, self::TAX );
		if ( $term instanceof WP_Term ) {
			$term_id = (int) $term->term_id;
		} else {
			$inserted = wp_insert_term( $nombre, self::TAX );
			if ( is_wp_error( $inserted ) ) {
				return;
			}
			$term_id = (int) $inserted['term_id'];
		}

		wp_set_object_terms( $post_id, $term_id, self::TAX, false );
	}

	/**
	 * Busca un partido ya guardado por su ID de la API.
	 */
	private function find_by_api_id( int $api_id ): int {
		$ids = get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'meta_key'         => self::META_API,
				'meta_value'       => $api_id,
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Fechas objetivo: hoy y mañana, en la zona horaria del sitio.
	 *
	 * @return array<int,string> YYYY-MM-DD
	 */
	private function target_dates(): array {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'now', $tz );
		return array(
			$today->format( 'Y-m-d' ),
			$today->modify( '+1 day' )->format( 'Y-m-d' ),
		);
	}
}
