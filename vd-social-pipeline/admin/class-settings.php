<?php
/**
 * Registro de ajustes vía Settings API. Todo en una option (array) con un
 * sanitize_callback global (whitelist por clave).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Settings {

	public const GROUP = 'vd_social_settings_group';

	/** Claves secretas: si llegan vacías se conserva el valor previo; nunca se re-imprimen. */
	private const SECRET_KEYS = array(
		'gemini_api_key',
		'x_consumer_key',
		'x_consumer_secret',
		'x_access_token',
		'x_access_token_secret',
		'meta_access_token',
	);

	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			VD_Social_Options::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => VD_Social_Options::defaults(),
			)
		);
	}

	/**
	 * Sanea el array completo. Preserva secretos vacíos y no toca los definidos
	 * por constante.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = VD_Social_Options::all();

		// Toggles (checkbox ausente = false; están todos en esta misma vista).
		$clean['pipeline_enabled']      = ! empty( $input['pipeline_enabled'] );
		$clean['auto_x']                = ! empty( $input['auto_x'] );
		$clean['auto_facebook']         = ! empty( $input['auto_facebook'] );
		$clean['auto_instagram']        = ! empty( $input['auto_instagram'] );
		$clean['placa_show_date']       = ! empty( $input['placa_show_date'] );
		$clean['placa_show_category']   = ! empty( $input['placa_show_category'] );
		$clean['placa_use_as_ig_image'] = ! empty( $input['placa_use_as_ig_image'] );

		// Placas.
		$clean['placa_logo_id']       = isset( $input['placa_logo_id'] ) ? absint( $input['placa_logo_id'] ) : 0;
		$accent                       = sanitize_hex_color( (string) ( $input['placa_accent'] ?? '' ) );
		$clean['placa_accent']        = $accent ? $accent : $clean['placa_accent'];
		$handle                       = sanitize_text_field( (string) ( $input['placa_handle_domain'] ?? '' ) );
		$clean['placa_handle_domain'] = '' !== $handle ? $handle : $clean['placa_handle_domain'];

		// Texto simple.
		$model                       = sanitize_text_field( (string) ( $input['gemini_model'] ?? '' ) );
		$clean['gemini_model']       = '' !== $model ? $model : $clean['gemini_model'];
		$clean['meta_graph_version'] = sanitize_text_field( (string) ( $input['meta_graph_version'] ?? $clean['meta_graph_version'] ) );
		$clean['meta_page_id']       = sanitize_text_field( (string) ( $input['meta_page_id'] ?? '' ) );
		$clean['meta_ig_user_id']    = sanitize_text_field( (string) ( $input['meta_ig_user_id'] ?? '' ) );

		// Categorías excluidas.
		$cats                         = isset( $input['excluded_categories'] ) && is_array( $input['excluded_categories'] )
			? array_values( array_unique( array_map( 'absint', $input['excluded_categories'] ) ) )
			: array();
		$clean['excluded_categories'] = $cats;

		// Secretos: si están definidos por constante no se guardan; si llegan
		// vacíos se conserva el valor previo.
		foreach ( self::SECRET_KEYS as $key ) {
			if ( VD_Social_Credentials::is_constant( $key ) ) {
				continue;
			}
			$val = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( '' !== $val ) {
				$clean[ $key ] = sanitize_text_field( $val );
			}
			// Vacío → se conserva el previo (ya está en $clean por all()).
		}

		return $clean;
	}
}
