<?php
/**
 * Activación / desactivación.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Activator {

	public static function activate(): void {
		VD_Social_Options::install_defaults();
		VD_Social_Logger::install_table();
	}

	public static function deactivate(): void {
		// Limpiar cron programado por el plugin (Action Scheduler se limpia solo).
		wp_clear_scheduled_hook( VD_Social_Scheduler::HOOK_GENERATE );
		wp_clear_scheduled_hook( VD_Social_Scheduler::HOOK_PUBLISH );
		wp_clear_scheduled_hook( VD_Social_Fixtures_Module::HOOK );
	}
}
