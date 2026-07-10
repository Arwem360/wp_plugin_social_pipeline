<?php
/**
 * Autoloader por mapa explícito clase => archivo. Carga por demanda: cada clase
 * se incluye recién cuando se instancia.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Autoloader {

	/**
	 * @var array<string,string> Clase => ruta relativa a la raíz del plugin.
	 */
	private static array $map = array(
		// Núcleo.
		'VD_Social_Plugin'               => 'includes/class-plugin.php',
		'VD_Social_Activator'            => 'includes/class-activator.php',
		'VD_Social_Options'              => 'includes/class-options.php',
		'VD_Social_Credentials'          => 'includes/class-credentials.php',
		'VD_Social_CPT'                  => 'includes/class-cpt.php',
		'VD_Social_Variant'              => 'includes/class-variant.php',
		'VD_Social_UTM'                  => 'includes/class-utm.php',
		'VD_Social_Logger'               => 'includes/class-logger.php',
		'VD_Social_Scheduler'            => 'includes/class-scheduler.php',
		'VD_Social_Pipeline'             => 'includes/class-pipeline.php',
		'VD_Social_Generator'            => 'includes/class-generator.php',
		'VD_Social_Gemini_Client'        => 'includes/class-gemini-client.php',
		'VD_Social_OAuth1'               => 'includes/class-oauth1.php',
		// Publicadores.
		'VD_Social_Meta_Error'           => 'includes/publishers/class-meta-error.php',
		'VD_Social_Publish_Manager'      => 'includes/publishers/class-publish-manager.php',
		'VD_Social_X_Publisher'          => 'includes/publishers/class-x-publisher.php',
		'VD_Social_Facebook_Publisher'   => 'includes/publishers/class-facebook-publisher.php',
		'VD_Social_Instagram_Publisher'  => 'includes/publishers/class-instagram-publisher.php',
		// Admin.
		'VD_Social_Admin_Menu'           => 'admin/class-admin-menu.php',
		'VD_Social_Settings'             => 'admin/class-settings.php',
		'VD_Social_Queue'                => 'admin/class-queue.php',
		'VD_Social_Connection_Test'      => 'admin/class-connection-test.php',
	);

	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	public static function load( string $class ): void {
		if ( ! isset( self::$map[ $class ] ) ) {
			return;
		}
		$path = VD_SOCIAL_DIR . self::$map[ $class ];
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
