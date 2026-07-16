<?php
/**
 * Plugin Name:       VD Social Pipeline
 * Description:       Al publicarse una nota, genera con Gemini los posteos para X, Facebook e Instagram, los deja en una cola de aprobación y los publica en cada red (manual o automático). Incluye generación de placas (imágenes) para Instagram.
 * Version:           1.6.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Vermouth Deportivo
 * License:           GPL-2.0-or-later
 * Text Domain:       vd-social-pipeline
 * Domain Path:       /languages
 *
 * @package VD_Social
 */

// Bloquear acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constantes.
// ---------------------------------------------------------------------------
define( 'VD_SOCIAL_VERSION', '1.6.1' );
define( 'VD_SOCIAL_FILE', __FILE__ );
define( 'VD_SOCIAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'VD_SOCIAL_URL', plugin_dir_url( __FILE__ ) );
define( 'VD_SOCIAL_BASENAME', plugin_basename( __FILE__ ) );

// ---------------------------------------------------------------------------
// Autoloader (carga de clases por demanda).
// ---------------------------------------------------------------------------
require_once VD_SOCIAL_DIR . 'includes/class-autoloader.php';
VD_Social_Autoloader::register();

// ---------------------------------------------------------------------------
// Activación / desactivación.
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( 'VD_Social_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VD_Social_Activator', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Bootstrap mínimo: decide qué cargar según el contexto.
// ---------------------------------------------------------------------------
add_action(
	'plugins_loaded',
	static function (): void {
		VD_Social_Plugin::instance()->boot();
	}
);
