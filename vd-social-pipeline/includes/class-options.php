<?php
/**
 * Opciones centralizadas en una sola option (array), con defaults y cache.
 *
 * IMPORTANTE: las credenciales pueden estar definidas por constante en
 * wp-config.php (ver VD_Social_Credentials); para leer un secreto usá esa clase,
 * no accedas directo a la option.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Options {

	public const OPTION_KEY = 'vd_social_options';

	/** @var array<string,mixed>|null */
	private static ?array $cache = null;

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Pipeline.
			'pipeline_enabled'      => false,
			'excluded_categories'   => array(),   // IDs de categorías excluidas.

			// Gemini.
			'gemini_api_key'        => '',
			'gemini_model'          => 'gemini-3.5-flash',

			// Auto-publicación por red (default apagado en las tres).
			'auto_x'                => false,
			'auto_facebook'         => false,
			'auto_instagram'        => false,

			// Credenciales X (OAuth 1.0a user context).
			'x_consumer_key'        => '',
			'x_consumer_secret'     => '',
			'x_access_token'        => '',
			'x_access_token_secret' => '',

			// Credenciales Meta (Facebook + Instagram comparten access token).
			'meta_page_id'          => '',
			'meta_ig_user_id'       => '',
			'meta_access_token'     => '',
			'meta_graph_version'    => 'v21.0',
		);
	}

	public static function install_defaults(): void {
		if ( null === get_option( self::OPTION_KEY, null ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION_KEY, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			self::$cache = array_merge( self::defaults(), $stored );
		}
		return self::$cache;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * @param array<string,mixed> $values Ya saneados por el caller.
	 */
	public static function update( array $values ): void {
		update_option( self::OPTION_KEY, $values );
		self::$cache = null;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}
}
