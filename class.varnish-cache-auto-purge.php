<?php

class ClpVarnishCacheAutoPurge {

    private static bool $purged = false;

    public function __construct() {
        add_action('upgrader_process_complete', $this->auto_purge(...), 100, 2);
        add_action('deactivated_plugin',        $this->auto_purge(...), 100);
        add_action('activated_plugin',          $this->auto_purge(...), 100);
        add_action('switch_theme',              $this->auto_purge(...), 100);

        add_action('save_post',                        $this->purge_post(...), 100);
        add_action('delete_post',                      $this->purge_post(...), 100);
        add_action('transition_comment_status',        $this->purge_post_from_comment_transition(...), 100, 3);

        add_action('woocommerce_product_set_stock',        $this->purge_woo_product(...), 100);
        add_action('woocommerce_variation_set_stock',      $this->purge_woo_product(...), 100);
        add_action('woocommerce_product_set_stock_status', $this->purge_woo_product_by_id(...), 100, 2);
    }

    public function auto_purge(mixed $upgrader_or_plugin = null, mixed $options = null): void {
        if (current_action() === 'upgrader_process_complete') {
            if (empty($options['action']) || $options['action'] !== 'update') return;
            if (empty($options['type']) || !in_array($options['type'], ['plugin', 'theme', 'core'], strict: true)) return;
        }

        if (self::$purged) return;

        $manager = new ClpVarnishCacheManager();
        if (!$manager->is_enabled()) return;

        try {
            $manager->purge_everything();
            self::$purged = true;
        } catch (\Exception $e) {
            error_log(sprintf('CLP Varnish Cache: auto_purge failed — %s', $e->getMessage()));
        }
    }

    public function purge_post(int $post_id): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if ('publish' !== get_post_status($post_id)) return;

        $manager = new ClpVarnishCacheManager();
        if (!$manager->is_enabled()) return;

        // Tag-based purge for the post itself — matches VCL ban rules that use
        // X-Cache-Tags: {prefix}-post-{ID} (same format as the original CLP plugin).
        $prefix = $manager->get_cache_tag_prefix();
        if (!empty($prefix)) {
            try {
                $manager->purge_tag($prefix . '-post-' . $post_id);
            } catch (\Exception $e) {
                error_log(sprintf('CLP Varnish Cache: failed to purge tag for post %d — %s', $post_id, $e->getMessage()));
            }
        }

        // URL-based purges for surrounding pages (homepage, feed, taxonomy archives,
        // author page) that don't have per-post cache tags.
        /** @var string[] $urls */
        $urls = apply_filters('clp_varnish_purge_urls', $this->get_purge_urls_for_post($post_id), $post_id, get_post($post_id));

        foreach (array_unique(array_filter($urls)) as $url) {
            try {
                $manager->purge_url($url);
            } catch (\Exception $e) {
                error_log(sprintf('CLP Varnish Cache: failed to purge %s — %s', $url, $e->getMessage()));
            }
        }
    }

    public function purge_post_from_comment_transition(string $new_status, string $old_status, \WP_Comment $comment): void {
        if ('approved' !== $new_status && 'approved' !== $old_status) return;
        $post_id = (int) $comment->comment_post_ID;
        if ($post_id > 0) {
            $this->purge_post($post_id);
        }
    }

    public function purge_woo_product(mixed $product): void {
        if (is_object($product) && method_exists($product, 'get_id')) {
            $this->purge_post((int) $product->get_id());
        }
    }

    public function purge_woo_product_by_id(int $product_id, string $_stock_status): void {
        $this->purge_post($product_id);
    }

    /** @return string[] */
    private function get_purge_urls_for_post(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) return [];

        $urls = array_filter([
            get_permalink($post_id),
            home_url('/'),
            get_feed_link(),
        ]);

        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy);
            if (is_wp_error($terms)) continue;
            foreach ($terms as $term) {
                $link = get_term_link($term, $taxonomy);
                if (!is_wp_error($link)) {
                    $urls[] = $link;
                }
            }
        }

        if (!empty($post->post_author)) {
            $author_url = get_author_posts_url((int) $post->post_author);
            if ($author_url) $urls[] = $author_url;
        }

        if (function_exists('wc_get_page_permalink')) {
            $shop_url = wc_get_page_permalink('shop');
            if ($shop_url) $urls[] = $shop_url;
        }

        return $urls;
    }
}
