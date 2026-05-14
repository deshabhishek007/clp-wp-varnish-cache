<?php

class ClpVarnishCacheManager {

    private $cache_settings = [];
    private $settings_file;

    public function __construct() {
        // Cache the path once — avoids repeated getenv() syscalls
        $this->settings_file = sprintf('%s/.varnish-cache/settings.json', rtrim(getenv('HOME'), '/'));
    }

    public function is_enabled() {
        $settings = $this->get_cache_settings();
        return isset($settings['enabled']) && true === $settings['enabled'];
    }

    public function get_server() {
        $settings = $this->get_cache_settings();
        return isset($settings['server']) ? $settings['server'] : '';
    }

    public function get_cache_lifetime() {
        $settings = $this->get_cache_settings();
        return isset($settings['cacheLifetime']) ? $settings['cacheLifetime'] : '';
    }

    public function get_cache_tag_prefix() {
        $settings = $this->get_cache_settings();
        return isset($settings['cacheTagPrefix']) ? $settings['cacheTagPrefix'] : '';
    }

    public function get_excluded_params() {
        $settings = $this->get_cache_settings();
        $excluded_params = isset($settings['excludedParams']) ? (array) $settings['excludedParams'] : [];
        return implode(',', $excluded_params);
    }

    public function get_excludes() {
        $settings = $this->get_cache_settings();
        $excludes = isset($settings['excludes']) ? (array) $settings['excludes'] : [];
        return implode(PHP_EOL, $excludes);
    }

    public function get_cache_settings() {
        if (!empty($this->cache_settings)) {
            return $this->cache_settings;
        }

        // Share settings across multiple ClpVarnishCacheManager instances in the same request
        $cached = wp_cache_get('clp_varnish_settings', 'clp_varnish');
        if (false !== $cached) {
            $this->cache_settings = $cached;
            return $this->cache_settings;
        }

        if (file_exists($this->settings_file)) {
            $json           = file_get_contents($this->settings_file);
            $cache_settings = json_decode($json, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                error_log(sprintf('CLP Varnish Cache: failed to parse settings.json — %s', json_last_error_msg()));
            } elseif (!empty($cache_settings)) {
                $this->cache_settings = $cache_settings;
                wp_cache_set('clp_varnish_settings', $cache_settings, 'clp_varnish');
            }
        }

        return $this->cache_settings;
    }

    public function write_cache_settings(array $settings) {
        $json   = json_encode($settings, JSON_PRETTY_PRINT);
        $result = file_put_contents($this->settings_file, $json);
        if (false === $result) {
            throw new \RuntimeException(
                sprintf('CLP Varnish Cache: failed to write settings to %s', $this->settings_file)
            );
        }
        wp_cache_delete('clp_varnish_settings', 'clp_varnish');
    }

    public function reset_cache_settings() {
        $this->cache_settings = [];
        wp_cache_delete('clp_varnish_settings', 'clp_varnish');
    }

    public function purge_host($host): void {
        $this->purge(['Host' => $host]);
    }

    public function purge_tag($tag): void {
        $this->purge_tags([$tag]);
    }

    public function purge_tags(array $tags): void {
        $this->purge(['X-Cache-Tags' => implode(',', $tags)]);
    }

    // Send a single PURGE request carrying both Host and X-Cache-Tags headers
    public function purge_host_and_tag($host, $tag): void {
        $this->purge([
            'Host'        => $host,
            'X-Cache-Tags' => $tag,
        ]);
    }

    public function purge_url($url): void {
        $parsed_url = parse_url($url);

        if (!isset($parsed_url['host'])) {
            throw new \InvalidArgumentException(sprintf('Not a valid url: %s', $url));
        }

        // Prevent purging URLs for hosts other than the current site
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($parsed_url['host'] !== $site_host) {
            throw new \InvalidArgumentException(
                sprintf('URL host "%s" does not match site host "%s"', $parsed_url['host'], $site_host)
            );
        }

        $request_url = $this->get_server();

        if (isset($parsed_url['path'])) {
            $path        = $parsed_url['path'];
            $request_url = sprintf('%s/%s', $request_url, ('/' === $path ? '' : ltrim($path, '/')));
        }

        // Reuse the already-parsed query instead of calling parse_url() a second time
        if (!empty($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
            if (!empty($query_params)) {
                $request_url = sprintf('%s?%s', $request_url, http_build_query($query_params));
            }
        }

        $this->purge(['Host' => $parsed_url['host']], $request_url);
    }

    private function purge(array $headers, $request_url = null): void {
        if (is_null($request_url)) {
            $request_url = $this->get_server();
        }

        // Strip any existing scheme before prepending http:// to avoid double-protocol
        $request_url = 'http://' . preg_replace('#^https?://#', '', $request_url);

        $response = wp_remote_request($request_url, [
            'sslverify' => false,
            'method'    => 'PURGE',
            'timeout'   => 2,
            'headers'   => $headers,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                sprintf('Varnish purge request failed: %s', $response->get_error_message())
            );
        }

        $http_status_code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $http_status_code) {
            throw new \RuntimeException(sprintf('Varnish purge returned HTTP %d', $http_status_code));
        }
    }
}
