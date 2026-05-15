<?php

class ClpVarnishCacheManager {

    private readonly string $settings_file;
    private array           $cache_settings = [];

    public function __construct() {
        $home = getenv('HOME');
        if (empty($home)) {
            $home = posix_getpwuid(posix_getuid())['dir'] ?? '';
        }
        $this->settings_file = sprintf('%s/.varnish-cache/settings.json', rtrim((string) $home, '/'));
    }

    public function is_enabled(): bool {
        $settings = $this->get_cache_settings();
        return isset($settings['enabled']) && true === $settings['enabled'];
    }

    public function get_server(): string {
        return $this->get_cache_settings()['server'] ?? '';
    }

    public function get_cache_lifetime(): string {
        return $this->get_cache_settings()['cacheLifetime'] ?? '';
    }

    public function get_cache_tag_prefix(): string {
        return $this->get_cache_settings()['cacheTagPrefix'] ?? '';
    }

    public function get_excluded_params(): string {
        $params = (array) ($this->get_cache_settings()['excludedParams'] ?? []);
        return implode(',', $params);
    }

    public function get_excludes(): string {
        $excludes = (array) ($this->get_cache_settings()['excludes'] ?? []);
        return implode(PHP_EOL, $excludes);
    }

    public function get_cache_settings(): array {
        if (!empty($this->cache_settings)) {
            return $this->cache_settings;
        }

        $cached = wp_cache_get('clp_varnish_settings', 'clp_varnish');
        if (false !== $cached) {
            $this->cache_settings = (array) $cached;
            return $this->cache_settings;
        }

        if (file_exists($this->settings_file)) {
            $json           = (string) file_get_contents($this->settings_file);
            $cache_settings = json_decode($json, associative: true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                error_log(sprintf('CLP Varnish Cache: failed to parse settings.json — %s', json_last_error_msg()));
            } elseif (!empty($cache_settings)) {
                $this->cache_settings = $cache_settings;
                wp_cache_set('clp_varnish_settings', $cache_settings, 'clp_varnish');
            }
        }

        return $this->cache_settings;
    }

    public function write_cache_settings(array $settings): void {
        $result = file_put_contents($this->settings_file, json_encode($settings, JSON_PRETTY_PRINT));
        if (false === $result) {
            throw new \RuntimeException(
                sprintf('CLP Varnish Cache: failed to write settings to %s', $this->settings_file)
            );
        }
        wp_cache_delete('clp_varnish_settings', 'clp_varnish');
    }

    public function reset_cache_settings(): void {
        $this->cache_settings = [];
        wp_cache_delete('clp_varnish_settings', 'clp_varnish');
    }

    /**
     * Validate settings before saving. Returns an array of translatable error strings (empty = valid).
     */
    public static function validate_settings(array $settings): array {
        $errors = [];

        $server = $settings['server'] ?? '';
        if (empty($server)) {
            $errors[] = __('Varnish Server is required.', 'clp-varnish-cache');
        } elseif (preg_match('/^\[([^\]]+)\](?::(\d{1,5}))?$/', $server, $m)) {
            // IPv6: [::1] or [::1]:6081
            if (!filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $errors[] = __('Varnish Server contains an invalid IPv6 address.', 'clp-varnish-cache');
            } elseif (isset($m[2]) && ((int) $m[2] < 1 || (int) $m[2] > 65535)) {
                $errors[] = __('Varnish Server port must be between 1 and 65535.', 'clp-varnish-cache');
            }
        } elseif (!preg_match('/^[a-zA-Z0-9.\-_]+(:\d{1,5})?$/', $server)) {
            $errors[] = __('Varnish Server must be in hostname:port, IP:port, or [IPv6]:port format (e.g. 127.0.0.1:6081).', 'clp-varnish-cache');
        } elseif (str_contains($server, ':')) {
            $port = (int) explode(':', $server, 2)[1];
            if ($port < 1 || $port > 65535) {
                $errors[] = __('Varnish Server port must be between 1 and 65535.', 'clp-varnish-cache');
            }
        }

        $lifetime = (string) ($settings['cacheLifetime'] ?? '');
        if ('' === $lifetime) {
            $errors[] = __('Cache Lifetime is required.', 'clp-varnish-cache');
        } elseif (!ctype_digit($lifetime) || (int) $lifetime <= 0) {
            $errors[] = __('Cache Lifetime must be a positive integer (seconds).', 'clp-varnish-cache');
        }

        $prefix = $settings['cacheTagPrefix'] ?? '';
        if (empty($prefix)) {
            $errors[] = __('Cache Tag Prefix is required.', 'clp-varnish-cache');
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $prefix)) {
            $errors[] = __('Cache Tag Prefix may only contain letters, numbers, hyphens, and underscores.', 'clp-varnish-cache');
        }

        return $errors;
    }

    /**
     * Test connectivity to a Varnish server. Returns true on success or an error string.
     */
    public function test_connection(string $server): true|string {
        if (empty($server)) {
            return __('No server address configured.', 'clp-varnish-cache');
        }

        $response = wp_remote_request(
            'http://' . preg_replace('#^https?://#', '', $server),
            [
                'method'    => 'PURGE',
                'timeout'   => 3,
                'sslverify' => false,
                'headers'   => ['Host' => wp_parse_url(home_url(), PHP_URL_HOST)],
            ]
        );

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        return match (true) {
            200 === $code, 204 === $code => true,
            default                     => sprintf(__('Unexpected HTTP %d response from Varnish server.', 'clp-varnish-cache'), $code),
        };
    }

    public function purge_host(string $host): void {
        $this->purge(['Host' => $host], null, PurgeType::Host, $host);
    }

    public function purge_tag(string $tag): void {
        $this->purge_tags([$tag]);
    }

    public function purge_tags(array $tags): void {
        $target = implode(',', $tags);
        $this->purge(['X-Cache-Tags' => $target], null, PurgeType::Tag, $target);
    }

    public function purge_host_and_tag(string $host, string $tag): void {
        $this->purge(['Host' => $host, 'X-Cache-Tags' => $tag], null, PurgeType::HostTag, "$host / $tag");
    }

    public function purge_everything(): void {
        $host    = wp_parse_url(home_url(), PHP_URL_HOST);
        $headers = ['X-Purge-All' => 'true'];
        if (!empty($host)) {
            $headers['Host'] = $host;
        }
        $this->purge($headers, null, PurgeType::All, '*');
    }

    public function purge_url(string $url): void {
        $parsed = parse_url($url);

        if (!isset($parsed['host'])) {
            throw new \InvalidArgumentException(sprintf('Not a valid URL: %s', $url));
        }

        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($parsed['host'] !== $site_host) {
            throw new \InvalidArgumentException(
                sprintf('URL host "%s" does not match site host "%s"', $parsed['host'], $site_host)
            );
        }

        $request_url = $this->get_server();
        if (isset($parsed['path'])) {
            $request_url = sprintf('%s/%s', $request_url, '/' === $parsed['path'] ? '' : ltrim($parsed['path'], '/'));
        }
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query_params);
            if (!empty($query_params)) {
                $request_url = sprintf('%s?%s', $request_url, http_build_query($query_params));
            }
        }

        $this->purge(['Host' => $parsed['host']], $request_url, PurgeType::Url, $url);
    }

    private function purge(array $headers, ?string $request_url, PurgeType $type, string $target): void {
        $url      = 'http://' . preg_replace('#^https?://#', '', $request_url ?? $this->get_server());
        $response = wp_remote_request(
            $url,
            [
                'method'    => 'PURGE',
                'timeout'   => 2,
                'sslverify' => false,
                'headers'   => $headers,
            ]
        );

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            ClpVarnishCacheLogger::log($type, $target, false, $message);
            throw new \RuntimeException(sprintf('Varnish purge request failed: %s', $message));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            $message = sprintf('HTTP %d', $code);
            ClpVarnishCacheLogger::log($type, $target, false, $message);
            throw new \RuntimeException(sprintf('Varnish purge returned HTTP %d', $code));
        }

        ClpVarnishCacheLogger::log($type, $target, true);
    }
}
