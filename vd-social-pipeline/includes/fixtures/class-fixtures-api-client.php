<?php
/**
 * Cliente HTTP contra la API de estadísticas (servicio Node propio que proxea
 * API-Football, normalizado al español). Sin dependencias externas: usa la HTTP
 * API de WordPress.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Fixtures_Api_Client {

	/** Timeout por request (segundos). */
	private const TIMEOUT = 15;

	private string $base;

	public function __construct( string $base ) {
		// Normaliza: sin barra final para concatenar rutas de forma predecible.
		$this->base = untrailingslashit( trim( $base ) );
	}

	/**
	 * Trae los partidos de una liga en una fecha (YYYY-MM-DD).
	 *
	 * @return array<int,array<string,mixed>>|WP_Error Lista de partidos normalizados.
	 */
	public function fixtures_by_day( string $league, string $date, string $season = '' ) {
		$query = array(
			'league' => $league,
			'date'   => $date,
		);
		if ( '' !== $season ) {
			$query['season'] = $season;
		}

		$data = $this->get( '/fixtures', $query );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$partidos = isset( $data['partidos'] ) && is_array( $data['partidos'] ) ? $data['partidos'] : array();
		return $partidos;
	}

	/**
	 * Partidos con filtros combinables (league, date, season, from, to, next,
	 * last, team, id). Devuelve la lista de partidos normalizados.
	 *
	 * @param array<string,string> $params
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function fixtures( array $params ) {
		$data = $this->get( '/fixtures', $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['partidos'] ) && is_array( $data['partidos'] ) ? $data['partidos'] : array();
	}

	/**
	 * Eventos de un partido (goles, tarjetas, cambios, VAR). Devuelve la lista.
	 *
	 * @param array<string,string> $params fixture (req), team, type opcionales.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function events( array $params ) {
		$data = $this->get( '/events', $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['eventos'] ) && is_array( $data['eventos'] ) ? $data['eventos'] : array();
	}

	/**
	 * Formaciones de un partido (titulares con grid, suplentes, DT y colores).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function lineups( string $fixture, string $team = '' ) {
		$params = array( 'fixture' => $fixture );
		if ( '' !== $team ) {
			$params['team'] = $team;
		}
		return $this->get( '/lineups', $params );
	}

	/**
	 * Trae la tabla de posiciones de una liga (id o slug). Devuelve el payload
	 * completo (liga + grupos) o WP_Error.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function standings( string $liga, string $season = '', string $fase = '' ) {
		$query = array();
		if ( '' !== $season ) {
			$query['season'] = $season;
		}
		if ( '' !== $fase ) {
			$query['fase'] = $fase;
		}
		return $this->get( '/standings/' . rawurlencode( $liga ), $query );
	}

	/**
	 * Chequeo de salud del servicio. Devuelve true si responde 200.
	 */
	public function health(): bool {
		$data = $this->get( '/health', array() );
		return ! is_wp_error( $data );
	}

	/**
	 * GET genérico que devuelve el JSON decodificado como array asociativo.
	 *
	 * @param array<string,string> $query
	 * @return array<string,mixed>|WP_Error
	 */
	private function get( string $path, array $query ) {
		if ( '' === $this->base ) {
			return new WP_Error( 'vd_fixtures_no_base', __( 'Falta configurar la URL de la API de partidos.', 'vd-social-pipeline' ) );
		}

		$url = $this->base . $path;
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$msg = '';
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
				$msg = (string) $decoded['error'];
			}
			/* translators: 1: HTTP code, 2: error message from the API. */
			return new WP_Error(
				'vd_fixtures_http',
				sprintf( __( 'La API respondió %1$d: %2$s', 'vd-social-pipeline' ), $code, $msg )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'vd_fixtures_bad_json', __( 'Respuesta de la API ilegible (no es JSON válido).', 'vd-social-pipeline' ) );
		}

		return $decoded;
	}
}
