<?php
/**
 * Publicador de Facebook (página) — Meta Graph API.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Facebook_Publisher {

	/**
	 * Publica en el feed de la página: message + link.
	 *
	 * @return array{ok:bool,remote_id:string,code:string,message:string}
	 */
	public function publish( string $message, string $link ): array {
		$page_id = VD_Social_Credentials::get( 'meta_page_id' );
		$token   = VD_Social_Credentials::get( 'meta_access_token' );
		if ( '' === $page_id || '' === $token ) {
			return $this->fail( '', __( 'Faltan credenciales de Meta (page ID / token).', 'vd-social-pipeline' ) );
		}

		$url  = $this->graph_url( $page_id . '/feed' );
		$args = array(
			'message'      => $message,
			'access_token' => $token,
		);
		if ( '' !== $link ) {
			$args['link'] = $link;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'body'    => $args,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->fail( '', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && isset( $body['id'] ) ) {
			return array(
				'ok'        => true,
				'remote_id' => (string) $body['id'],
				'code'      => (string) $code,
				'message'   => 'OK',
			);
		}
		return $this->fail( (string) $code, VD_Social_Meta_Error::message( $body ) );
	}

	/**
	 * Verificación: GET /{page-id}?fields=name.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function test(): array {
		$page_id = VD_Social_Credentials::get( 'meta_page_id' );
		$token   = VD_Social_Credentials::get( 'meta_access_token' );
		if ( '' === $page_id || '' === $token ) {
			return array(
				'ok'      => false,
				'message' => __( 'Faltan credenciales de Meta.', 'vd-social-pipeline' ),
			);
		}
		$url      = $this->graph_url( $page_id ) . '?' . http_build_query(
			array(
				'fields'       => 'name',
				'access_token' => $token,
			)
		);
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && isset( $body['name'] ) ) {
			return array(
				'ok'      => true,
				/* translators: %s: nombre de la página */
				'message' => sprintf( __( 'Conectado a la página "%s"', 'vd-social-pipeline' ), $body['name'] ),
			);
		}
		return array(
			'ok'      => false,
			'message' => VD_Social_Meta_Error::message( $body ) . ' (HTTP ' . $code . ')',
		);
	}

	private function graph_url( string $path ): string {
		$version = (string) VD_Social_Options::get( 'meta_graph_version', 'v21.0' );
		return 'https://graph.facebook.com/' . $version . '/' . ltrim( $path, '/' );
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
