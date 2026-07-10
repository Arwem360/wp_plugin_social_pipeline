<?php
/**
 * Publicador de X (Twitter) — API v2, OAuth 1.0a user context.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_X_Publisher {

	private const TWEETS_URL = 'https://api.twitter.com/2/tweets';
	private const ME_URL     = 'https://api.twitter.com/2/users/me';

	/**
	 * Publica un tweet con el texto (el link ya viene incluido por el manager).
	 *
	 * @return array{ok:bool,remote_id:string,code:string,message:string}
	 */
	public function publish( string $text ): array {
		$creds = $this->credentials();
		if ( '' === $creds['consumer_key'] || '' === $creds['token'] ) {
			return $this->fail( '', __( 'Faltan credenciales de X.', 'vd-social-pipeline' ) );
		}

		$auth     = VD_Social_OAuth1::authorization_header( 'POST', self::TWEETS_URL, $creds );
		$response = wp_remote_post(
			self::TWEETS_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => $auth,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'text' => $text ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->fail( '', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			return $this->fail( '429', __( 'X: rate limit (429).', 'vd-social-pipeline' ) );
		}
		if ( 201 === $code && isset( $body['data']['id'] ) ) {
			return array(
				'ok'        => true,
				'remote_id' => (string) $body['data']['id'],
				'code'      => (string) $code,
				'message'   => 'OK',
			);
		}

		return $this->fail( (string) $code, $this->error_message( $body ) );
	}

	/**
	 * Verificación de conexión: GET /2/users/me.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function test(): array {
		$creds = $this->credentials();
		if ( '' === $creds['consumer_key'] || '' === $creds['token'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'Faltan credenciales de X.', 'vd-social-pipeline' ),
			);
		}

		$auth     = VD_Social_OAuth1::authorization_header( 'GET', self::ME_URL, $creds );
		$response = wp_remote_get(
			self::ME_URL,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => $auth ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && isset( $body['data']['username'] ) ) {
			return array(
				'ok'      => true,
				/* translators: %s: usuario de X */
				'message' => sprintf( __( 'Conectado como @%s', 'vd-social-pipeline' ), $body['data']['username'] ),
			);
		}
		return array(
			'ok'      => false,
			'message' => $this->error_message( $body ) . ' (HTTP ' . $code . ')',
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function credentials(): array {
		return array(
			'consumer_key'    => VD_Social_Credentials::get( 'x_consumer_key' ),
			'consumer_secret' => VD_Social_Credentials::get( 'x_consumer_secret' ),
			'token'           => VD_Social_Credentials::get( 'x_access_token' ),
			'token_secret'    => VD_Social_Credentials::get( 'x_access_token_secret' ),
		);
	}

	/**
	 * @param mixed $body
	 */
	private function error_message( $body ): string {
		if ( is_array( $body ) ) {
			if ( isset( $body['title'] ) ) {
				return (string) $body['title'] . ( isset( $body['detail'] ) ? ': ' . $body['detail'] : '' );
			}
			if ( isset( $body['errors'][0]['message'] ) ) {
				return (string) $body['errors'][0]['message'];
			}
		}
		return __( 'Error desconocido de la API de X.', 'vd-social-pipeline' );
	}

	/**
	 * @return array{ok:bool,remote_id:string,code:string,message:string}
	 */
	private function fail( string $code, string $message ): array {
		return array(
			'ok'        => false,
			'remote_id' => '',
			'code'      => $code,
			'message'   => $message,
		);
	}
}
