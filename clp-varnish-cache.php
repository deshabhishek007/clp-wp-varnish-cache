<?php
/*
 * Plugin Name: CLP Varnish Cache
 * Description: Varnish Cache Plugin by cloudpanel.io
 * Version: 1.1.3
 * Text Domain: clp-varnish-cache
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: cloudpanel.io
 * Author URI: https://www.cloudpanel.io
 * GitHub Plugin URI: https://github.com/cloudpanel-io/clp-wp-varnish-cache
 * GitHub Branch: master
 */

if (false === function_exists('add_action')) {
    echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
    exit;
}

define('CLP_VARNISH_VERSION', '1.1.3');
define('CLP_VARNISH_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once CLP_VARNISH_PLUGIN_DIR . 'enum-purge-type.php';
require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-logger.php';
require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-manager.php';
require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-auto-purge.php';

// Auto-purge loads on every request so save_post / comment / WooCommerce hooks fire on REST and CLI too
$clp_varnish_cache_auto_purge = new ClpVarnishCacheAutoPurge();

if (is_admin()) {
    require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-health.php';
    require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-admin.php';
    $clp_varnish_cache_admin = new ClpVarnishCacheAdmin();
}

// WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    require_once CLP_VARNISH_PLUGIN_DIR . 'class.varnish-cache-cli.php';
}

// ── Activation ────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, static function (): void {
    $manager = new ClpVarnishCacheManager();
    if (empty($manager->get_cache_settings())) {
        set_transient('clp_varnish_activation_notice', true, 30);
    }
});

// ── Deactivation ──────────────────────────────────────────────────────────
register_deactivation_hook(__FILE__, static function (): void {
    $manager = new ClpVarnishCacheManager();
    if (!$manager->is_enabled()) return;

    try {
        $host   = wp_parse_url(home_url(), PHP_URL_HOST);
        $prefix = $manager->get_cache_tag_prefix();
        if (!empty($host) && !empty($prefix)) {
            $manager->purge_host_and_tag($host, $prefix);
        } elseif (!empty($host)) {
            $manager->purge_host($host);
        } elseif (!empty($prefix)) {
            $manager->purge_tag($prefix);
        }
    } catch (\Exception $e) {
        error_log(sprintf('CLP Varnish Cache: deactivation purge failed — %s', $e->getMessage()));
    }

    wp_cache_delete('clp_varnish_settings', 'clp_varnish');
});
