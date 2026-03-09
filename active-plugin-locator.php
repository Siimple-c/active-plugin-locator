<?php
/**
 * Plugin Name: Active Plugin Locator
 * Description: Shows a one-time admin toast after plugin activation indicating where to manage the plugin (when detectable).
 * Version: 0.1.0
 * Author: Your Name
 * Text Domain: active-plugin-locator
 *
 * @package ActivePluginLocator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'APL_VERSION', '0.1.0' );
define( 'APL_PLUGIN_FILE', __FILE__ );
define( 'APL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'APL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once APL_PLUGIN_DIR . 'includes/class-apl-activation-queue.php';
require_once APL_PLUGIN_DIR . 'includes/class-apl-admin-toast.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( is_admin() ) {
			APL_Admin_Toast::init();
		}
	}
);
