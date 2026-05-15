<?php
/**
 * Plugin Name: CLP Varnish Cache
 * Description: Varnish Cache Plugin by cloudpanel.io
 * Version: 1.1.5
 * Text Domain: clp-varnish-cache
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: cloudpanel.io
 * Author URI: https://www.cloudpanel.io
 * GitHub Plugin URI: https://github.com/cloudpanel-io/clp-wp-varnish-cache
 * GitHub Branch: master
 *
 * @package CLP_Varnish_Cache
 */

if ( false === function_exists( 'add_action' ) ) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit;
}

define( 'CLP_VARNISH_VERSION', '1.1.5' );
define( 'CLP_VARNISH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CLP_VARNISH_PLUGIN_DIR . 'enum-purge-type.php';
require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-logger.php';
require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-manager.php';
require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-auto-purge.php';

// Auto-purge loads on every request so save_post, comment, and WooCommerce hooks fire on REST and CLI too.
$clp_varnish_cache_auto_purge = new ClpVarnishCacheAutoPurge();

if ( is_admin() ) {
	require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-health.php';
	require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-admin.php';
	$clp_varnish_cache_admin = new ClpVarnishCacheAdmin();
}

// WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-cli.php';
}

// Activation hook.
register_activation_hook(
	__FILE__,
	static function (): void {
		$manager = new ClpVarnishCacheManager();
		if ( empty( $manager->get_cache_settings() ) ) {
			set_transient( 'clp_varnish_activation_notice', true, 30 );
		}
	}
);

// Deactivation hook.
register_deactivation_hook(
	__FILE__,
	static function (): void {
		$manager = new ClpVarnishCacheManager();
		if ( ! $manager->is_enabled() ) {
			return;
		}

		try {
			$manager->purge_everything();
		} catch ( \Exception $e ) {
			error_log( sprintf( 'CLP Varnish Cache: deactivation purge failed — %s', $e->getMessage() ) );
		}

		wp_cache_delete( 'clp_varnish_settings', 'clp_varnish' );
	}
);
