<?php

class ClpVarnishCacheAutoPurge {

    // Prevents duplicate full-cache purges within the same request (e.g. bulk plugin operations)
    private static $purged = false;

    public function __construct() {
        add_action('upgrader_process_complete', array($this, 'auto_purge'), 100, 2);
        add_action('deactivated_plugin', array($this, 'auto_purge'), 100);
        add_action('activated_plugin', array($this, 'auto_purge'), 100);
        add_action('switch_theme', array($this, 'auto_purge'), 100);
        add_action('save_post', array($this, 'purge_post'), 100);
        add_action('deleted_post', array($this, 'purge_post'), 100);
        add_action('comment_approved_comment', array($this, 'purge_post_from_comment'), 100);
    }

    public function auto_purge($upgrader_or_plugin = null, $options = null) {
        if (current_action() === 'upgrader_process_complete') {
            if (empty($options['action']) || $options['action'] !== 'update') return;
            if (empty($options['type']) || !in_array($options['type'], ['plugin', 'theme', 'core'], true)) return;
        }

        if (self::$purged) return;

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
            self::$purged = true;
        } catch (\Exception $e) {
            error_log(sprintf('CLP Varnish Cache auto_purge failed: %s', $e->getMessage()));
        }
    }

    public function purge_post($post_id) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if ('publish' !== get_post_status($post_id)) return;

        $manager = new ClpVarnishCacheManager();
        if (!$manager->is_enabled()) return;

        $url = get_permalink($post_id);
        if (empty($url)) return;

        try {
            $manager->purge_url($url);
        } catch (\Exception $e) {
            error_log(sprintf('CLP Varnish Cache purge_post failed for post %d: %s', $post_id, $e->getMessage()));
        }
    }

    public function purge_post_from_comment($comment) {
        $post_id = (int) (is_object($comment) ? $comment->comment_post_ID : $comment);
        if ($post_id > 0) {
            $this->purge_post($post_id);
        }
    }
}
