<?php
/**
 * Cliente de la Gemini API (Google AI) con salida estructurada nativa.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Gemini_Client {

	private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	/**
	 * Llama a Gemini con instrucción de sistema + contenido de usuario y devuelve
	 * el JSON estructurado decodificado, o WP_Error.
	 *
	 * En error 429 (cuota) devuelve WP_Error con code 'rate_limit' para que el
	 * caller pueda reintentar diferido.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function generate( string $system_instruction, string $user_content ) {
		$api_key = VD_Social_Credentials::get( 'gemini_api_key' );
		if ( '' === $api_key ) {
			return new WP_Error( 'no_api_key', __( 'Falta la API key de Gemini.', 'vd-social-pipeline' ) );
		}

		$model = (string) VD_Social_Options::get( 'gemini_model', 'gemini-3.5-flash' );
		$url   = sprintf( self::ENDPOINT, rawurlencode( $model ) );

		$body = array(
			'systemInstruction' => array(
				'parts' => array( array( 'text' => $system_instruction ) ),
			),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $user_content ) ),
				),
			),
			'generationConfig'  => array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => self::response_schema(),
				'temperature'      => 0.7,
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( 429 === $code ) {
			return new WP_Error( 'rate_limit', __( 'Gemini devolvió 429 (cuota/rate limit).', 'vd-social-pipeline' ), array( 'status' => 429 ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'http_error',
				sprintf( /* translators: %d: código HTTP */ __( 'Gemini respondió HTTP %d.', 'vd-social-pipeline' ), $code ),
				array(
					'status' => $code,
					'body'   => substr( $raw, 0, 500 ),
				)
			);
		}

		return $this->parse( $raw );
	}

	/**
	 * Verificación de conexión: una llamada mínima que confirma que la key sirve.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function test(): array {
		$result = $this->generate(
			'Respondé sólo con el JSON pedido.',
			'Devolvé x.text="ok", facebook.text="ok", instagram.caption="ok".'
		);
		if ( is_wp_error( $result ) ) {
			return array(
				'ok'      => false,
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'ok'      => true,
			'message' => __( 'Conexión con Gemini correcta.', 'vd-social-pipeline' ),
		);
	}

	/**
	 * Extrae y decodifica el JSON estructurado de la respuesta de Gemini.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function parse( string $raw ) {
		$outer = json_decode( $raw, true );
		if ( ! is_array( $outer ) ) {
			return new WP_Error( 'bad_response', __( 'Respuesta de Gemini no es JSON.', 'vd-social-pipeline' ) );
		}

		// candidates[0].content.parts[0].text contiene el JSON pedido.
		$text = '';
		if ( isset( $outer['candidates'][0]['content']['parts'] ) && is_array( $outer['candidates'][0]['content']['parts'] ) ) {
			foreach ( $outer['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= (string) $part['text'];
				}
			}
		}

		if ( '' === $text ) {
			return new WP_Error( 'empty_candidate', __( 'Gemini no devolvió contenido.', 'vd-social-pipeline' ) );
		}

		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bad_json', __( 'El contenido de Gemini no es JSON válido.', 'vd-social-pipeline' ) );
		}

		return $data;
	}

	/**
	 * Schema de salida estructurada (subconjunto OpenAPI que acepta Gemini).
	 *
	 * @return array<string,mixed>
	 */
	private static function response_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'x'         => array(
					'type'       => 'object',
					'properties' => array( 'text' => array( 'type' => 'string' ) ),
					'required'   => array( 'text' ),
				),
				'facebook'  => array(
					'type'       => 'object',
					'properties' => array( 'text' => array( 'type' => 'string' ) ),
					'required'   => array( 'text' ),
				),
				'instagram' => array(
					'type'       => 'object',
					'properties' => array( 'caption' => array( 'type' => 'string' ) ),
					'required'   => array( 'caption' ),
				),
			),
			'required'   => array( 'x', 'facebook', 'instagram' ),
		);
	}
}
