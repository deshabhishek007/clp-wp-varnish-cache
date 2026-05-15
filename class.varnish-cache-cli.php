<?php

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Manages Varnish Cache from the command line.
 */
final class ClpVarnishCacheCLI {

    /**
     * Purge the Varnish cache.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Purge the entire cache (host + tag prefix).
     *
     * [--url=<url>]
     * : Purge a specific URL.
     *
     * [--tag=<tag>]
     * : Purge by cache tag.
     *
     * ## EXAMPLES
     *
     *   wp varnish purge --all
     *   wp varnish purge --url=https://example.com/blog/my-post/
     *   wp varnish purge --tag=mysite
     *
     * @when after_wp_load
     */
    public function purge(array $args, array $assoc_args): void {
        $manager = new ClpVarnishCacheManager();

        if (!$manager->is_enabled()) {
            WP_CLI::error('Varnish Cache is not enabled. Enable it in Settings → CLP Varnish Cache.');
        }

        $all = \WP_CLI\Utils\get_flag_value($assoc_args, 'all', false);
        $url = \WP_CLI\Utils\get_flag_value($assoc_args, 'url', '');
        $tag = \WP_CLI\Utils\get_flag_value($assoc_args, 'tag', '');

        try {
            match (true) {
                (bool) $all        => $this->purge_all($manager),
                !empty($url)       => $this->purge_url($manager, $url),
                !empty($tag)       => $this->purge_tag($manager, $tag),
                default            => WP_CLI::error('Specify --all, --url=<url>, or --tag=<tag>.'),
            };
        } catch (\Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Display current Varnish Cache status and settings.
     *
     * ## EXAMPLES
     *
     *   wp varnish status
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assoc_args): void {
        $manager = new ClpVarnishCacheManager();

        if (empty($manager->get_cache_settings())) {
            WP_CLI::error('Settings file not found. Configure Varnish Cache in CloudPanel first.');
        }

        $rows = [
            ['Setting' => 'Enabled',         'Value' => $manager->is_enabled() ? 'Yes' : 'No'],
            ['Setting' => 'Server',           'Value' => $manager->get_server()],
            ['Setting' => 'Cache Lifetime',   'Value' => $manager->get_cache_lifetime() . 's'],
            ['Setting' => 'Cache Tag Prefix', 'Value' => $manager->get_cache_tag_prefix()],
            ['Setting' => 'Excluded Params',  'Value' => $manager->get_excluded_params() ?: '(none)'],
        ];

        \WP_CLI\Utils\format_items('table', $rows, ['Setting', 'Value']);
    }

    /**
     * Show the last 20 purge operations.
     *
     * ## EXAMPLES
     *
     *   wp varnish log
     *
     * @when after_wp_load
     */
    public function log(array $args, array $assoc_args): void {
        $entries = ClpVarnishCacheLogger::get_log();

        if (empty($entries)) {
            WP_CLI::line('No purge history recorded yet.');
            return;
        }

        $rows = array_map(
            static fn (array $entry): array => [
                'Time'    => $entry['time'],
                'Type'    => $entry['type'],
                'Target'  => $entry['target'],
                'Status'  => $entry['success'] ? 'OK' : 'FAILED',
                'Message' => $entry['message'],
            ],
            $entries
        );

        \WP_CLI\Utils\format_items('table', $rows, ['Time', 'Type', 'Target', 'Status', 'Message']);
    }

    /**
     * Clear the purge history log.
     *
     * ## EXAMPLES
     *
     *   wp varnish clear-log
     *
     * @when after_wp_load
     */
    public function clear_log(array $args, array $assoc_args): void {
        ClpVarnishCacheLogger::clear();
        WP_CLI::success('Purge log cleared.');
    }

    private function purge_all(ClpVarnishCacheManager $manager): void {
        $host   = wp_parse_url(home_url(), PHP_URL_HOST);
        $prefix = $manager->get_cache_tag_prefix();

        match (true) {
            !empty($host) && !empty($prefix) => $manager->purge_host_and_tag($host, $prefix),
            !empty($host)                    => $manager->purge_host($host),
            !empty($prefix)                  => $manager->purge_tag($prefix),
            default                          => null,
        };

        WP_CLI::success('Entire cache purged.');
    }

    private function purge_url(ClpVarnishCacheManager $manager, string $url): void {
        $manager->purge_url($url);
        WP_CLI::success(sprintf('Purged URL: %s', $url));
    }

    private function purge_tag(ClpVarnishCacheManager $manager, string $tag): void {
        $manager->purge_tag($tag);
        WP_CLI::success(sprintf('Purged tag: %s', $tag));
    }
}

WP_CLI::add_command('varnish', 'ClpVarnishCacheCLI');
