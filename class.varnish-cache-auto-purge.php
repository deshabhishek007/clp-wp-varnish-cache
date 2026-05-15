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
        add_action('comment_approved_comment',         $this->purge_post_from_comment(...), 100);

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
            $host   = wp_parse_url(home_url(), PHP_URL_HOST);
            $prefix = $manager->get_cache_tag_prefix();

            match (true) {
                !empty($host) && !empty($prefix) => $manager->purge_host_and_tag($host, $prefix),
                !empty($host)                    => $manager->purge_host($host),
                !empty($prefix)                  => $manager->purge_tag($prefix),
                default                          => null,
            };

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

    public function purge_post_from_comment(mixed $comment): void {
        $post_id = (int) (is_object($comment) ? $comment->comment_post_ID : $comment);
        if ($post_id > 0) {
            $this->purge_post($post_id);
        }
    }

    public function purge_woo_product(mixed $product): void {
        if (is_object($product) && method_exists($product, 'get_id')) {
            $this->purge_post((int) $product->get_id());
        }
    }

    public function purge_woo_product_by_id(int $product_id, string $stock_status): void {
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
