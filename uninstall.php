<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clear all transients created by this plugin
delete_transient('clp_varnish_purge_log');
delete_transient('clp_varnish_activation_notice');

// Clear the object cache group
wp_cache_delete('clp_varnish_settings', 'clp_varnish');
