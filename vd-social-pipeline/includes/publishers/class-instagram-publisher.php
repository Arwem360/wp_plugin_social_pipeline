<?php
/**
 * Publicador de Instagram (cuenta profesional) — Meta Graph API, flujo 2 pasos.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Instagram_Publisher {

	/**
	 * Publica una foto con caption. Requiere una image_url pública.
	 *
	 * @return array{ok:bool,remote_id:string,code:string,message:string,retry_after?:int}
	 */
	public function publish( string $caption, string $image_url ): array {
		$ig_id = VD_Social_Credentials::get( 'meta_ig_user_id' );
		$token = VD_Social_Credentials::get( 'meta_access_token' );
		if ( '' === $ig_id || '' === $token ) {
			return $this->fail( '', __( 'Faltan credenciales de Meta (IG user ID / token).', 'vd-social-pipeline' ) );
		}
		if ( '' === $image_url ) {
			return $this->fail( '', __( 'Instagram requiere una imagen destacada pública y no se encontró.', 'vd-social-pipeline' ) );
		}

		// 1) Chequear el límite de publicaciones por día; si se alcanzó, diferir.
		$limit = $this->publishing_limit( $ig_id, $token );
		if ( $limit['reached'] ) {
			return array(
				'ok'          => false,
				'remote_id'   => '',
				'code'        => 'limit_reached',
				'message'     => __( 'Se alcanzó el límite diario de publicaciones de Instagram; se reintenta más tarde.', 'vd-social-pipeline' ),
				'retry_after' => HOUR_IN_SECONDS,
			);
		}

		// 2) Crear el contenedor de media.
		$create = wp_remote_post(
			$this->graph_url( $ig_id . '/media' ),
			array(
				'timeout' => 45,
				'body'    => array(
					'image_url'    => $image_url,
					'caption'      => $caption,
					'access_token' => $token,
				),
			)
		);
		if ( is_wp_error( $create ) ) {
			return $this->fail( '', $create->get_error_message() );
		}
		$create_code = (int) wp_remote_retrieve_response_code( $create );
		$create_body = json_decode( (string) wp_remote_retrieve_body( $create ), true );
		if ( 200 !== $create_code || ! isset( $create_body['id'] ) ) {
			return $this->fail( (string) $create_code, VD_Social_Meta_Error::message( $create_body ) );
		}
		$creation_id = (string) $create_body['id'];

		// 3) Publicar el contenedor.
		$publish = wp_remote_post(
			$this->graph_url( $ig_id . '/media_publish' ),
			array(
				'timeout' => 45,
				'body'    => array(
					'creation_id'  => $creation_id,
					'access_token' => $token,
				),
			)
		);
		if ( is_wp_error( $publish ) ) {
			return $this->fail( '', $publish->get_error_message() );
		}
		$pub_code = (int) wp_remote_retrieve_response_code( $publish );
		$pub_body = json_decode( (string) wp_remote_retrieve_body( $publish ), true );
		if ( 200 === $pub_code && isset( $pub_body['id'] ) ) {
			return array(
				'ok'        => true,
				'remote_id' => (string) $pub_body['id'],
				'code'      => (string) $pub_code,
				'message'   => 'OK',
			);
		}
		return $this->fail( (string) $pub_code, VD_Social_Meta_Error::message( $pub_body ) );
	}

	/**
	 * Verificación: GET /{ig-user-id}?fields=username.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function test(): array {
		$ig_id = VD_Social_Credentials::get( 'meta_ig_user_id' );
		$token = VD_Social_Credentials::get( 'meta_access_token' );
		if ( '' === $ig_id || '' === $token ) {
			return array(
				'ok'      => false,
				'message' => __( 'Faltan credenciales de Meta.', 'vd-social-pipeline' ),
			);
		}
		$url      = $this->graph_url( $ig_id ) . '?' . http_build_query(
			array(
				'fields'       => 'username',
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
		if ( 200 === $code && isset( $body['username'] ) ) {
			return array(
				'ok'      => true,
				/* translators: %s: usuario de IG */
				'message' => sprintf( __( 'Conectado como @%s', 'vd-social-pipeline' ), $body['username'] ),
			);
		}
		return array(
			'ok'      => false,
			'message' => VD_Social_Meta_Error::message( $body ) . ' (HTTP ' . $code . ')',
		);
	}

	/**
	 * Consulta content_publishing_limit. Ante duda (error), asume que NO se alcanzó.
	 *
	 * @return array{reached:bool}
	 */
	private function publishing_limit( string $ig_id, string $token ): array {
		$url      = $this->graph_url( $ig_id . '/content_publishing_limit' ) . '?' . http_build_query(
			array(
				'fields'       => 'config,quota_usage',
				'access_token' => $token,
			)
		);
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'reached' => false );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['data'][0] ) ) {
			return array( 'reached' => false );
		}
		$row   = $body['data'][0];
		$usage = isset( $row['quota_usage'] ) ? (int) $row['quota_usage'] : 0;
		$total = isset( $row['config']['quota_total'] ) ? (int) $row['config']['quota_total'] : 25;
		return array( 'reached' => $usage >= $total );
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
