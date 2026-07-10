<?php
/**
 * Firma OAuth 1.0a (HMAC-SHA1) para la X API v2 user context.
 *
 * En X API v2 el cuerpo va como JSON, así que la firma sólo incluye los
 * parámetros oauth_* (y los de query string, si hubiera).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_OAuth1 {

	/**
	 * Devuelve el header Authorization firmado.
	 *
	 * @param array<string,string> $creds  consumer_key, consumer_secret, token, token_secret.
	 * @param array<string,string> $query  Parámetros de query string (opcional).
	 */
	public static function authorization_header( string $method, string $url, array $creds, array $query = array() ): string {
		$oauth = array(
			'oauth_consumer_key'     => $creds['consumer_key'],
			'oauth_nonce'            => self::nonce(),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => (string) time(),
			'oauth_token'            => $creds['token'],
			'oauth_version'          => '1.0',
		);

		// Base string: se firman los oauth_* + los de query, ordenados.
		$all = array_merge( $query, $oauth );
		ksort( $all );

		$pairs = array();
		foreach ( $all as $k => $v ) {
			$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
		}
		$param_string = implode( '&', $pairs );

		$base = strtoupper( $method ) . '&' . rawurlencode( $url ) . '&' . rawurlencode( $param_string );
		$key  = rawurlencode( $creds['consumer_secret'] ) . '&' . rawurlencode( $creds['token_secret'] );

		$oauth['oauth_signature'] = base64_encode( hash_hmac( 'sha1', $base, $key, true ) );

		// Header: sólo los parámetros oauth_*.
		ksort( $oauth );
		$header_parts = array();
		foreach ( $oauth as $k => $v ) {
			$header_parts[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
		}

		return 'OAuth ' . implode( ', ', $header_parts );
	}

	private static function nonce(): string {
		return md5( wp_generate_password( 32, false, false ) . microtime() );
	}
}
