<?php
/**
 * Plugin Name:       WP Pro Devs Toolkit
 * Plugin URI:        https://wpprodevs.com/
 * Description:       A collection of common features and site enhancements used across WP ProDevs managed WordPress websites. Each feature can be enabled or disabled individually per site.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mukto
 * Author URI:        https://mukto.info
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-pro-devs-toolkit
 * Domain Path:       /languages
 *
 * @package WPProDevs_Toolkit
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Another copy of the toolkit is already loaded, e.g. a second folder left by
// uploading a zip with a different folder name. Loading this copy as well
// would be a fatal error, so stay inactive and tell admins which one to remove.
if ( defined( 'WPPDT_PLUGIN_FILE' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				sprintf(
					/* translators: 1: folder of this duplicate copy, 2: folder of the copy in use. */
					esc_html__( 'WP Pro Devs Toolkit is installed twice. The copy in %1$s is not running because the copy in %2$s is already in use. Deactivate and delete the copy in %1$s.', 'wp-pro-devs-toolkit' ),
					'<code>' . esc_html( dirname( plugin_basename( __FILE__ ) ) ) . '</code>',
					'<code>' . esc_html( dirname( WPPDT_PLUGIN_BASENAME ) ) . '</code>'
				)
			);
		}
	);

	return;
}

// Plugin constants.
define( 'WPPDT_VERSION', '1.0.2' );
define( 'WPPDT_PLUGIN_FILE', __FILE__ );
define( 'WPPDT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPPDT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPPDT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WPPDT_PLUGIN_DIR . 'includes/class-wppdt-plugin.php';

register_activation_hook( __FILE__, array( 'WPPDT_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPPDT_Plugin', 'deactivate' ) );

// Declared inside a condition so PHP only defines it when this line runs. A
// plain top-level function is defined as soon as the file is included, which
// would still clash with a copy already loaded despite the early return above.
if ( ! function_exists( 'wppdt' ) ) {
	/**
	 * Returns the main plugin instance.
	 *
	 * @since 1.0.0
	 *
	 * @return WPPDT_Plugin
	 */
	function wppdt() {
		return WPPDT_Plugin::instance();
	}
}

// Boot the plugin.
wppdt();
