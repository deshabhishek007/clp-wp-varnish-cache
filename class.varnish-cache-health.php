<?php

class ClpVarnishCacheHealth {

    private ClpVarnishCacheManager $manager;

    public function __construct(ClpVarnishCacheManager $manager) {
        $this->manager = $manager;
        add_filter('site_status_tests', [$this, 'register_tests']);
    }

    public function register_tests(array $tests): array {
        $tests['direct']['clp_varnish_settings'] = [
            'label' => __('Varnish Cache settings are valid', 'clp-varnish-cache'),
            'test'  => [$this, 'test_settings'],
        ];
        $tests['direct']['clp_varnish_connection'] = [
            'label' => __('Varnish Cache server is reachable', 'clp-varnish-cache'),
            'test'  => [$this, 'test_connection'],
        ];
        return $tests;
    }

    public function test_settings(): array {
        $settings_url = admin_url('options-general.php?page=clp-varnish-cache');
        $result       = [
            'label'       => __('Varnish Cache is configured correctly', 'clp-varnish-cache'),
            'status'      => 'good',
            'badge'       => ['label' => __('Performance', 'clp-varnish-cache'), 'color' => 'blue'],
            'description' => '<p>' . __('Varnish Cache settings are present and valid.', 'clp-varnish-cache') . '</p>',
            'actions'     => sprintf('<a href="%s">%s</a>', esc_url($settings_url), __('Review settings', 'clp-varnish-cache')),
            'test'        => 'clp_varnish_settings',
        ];

        if (empty($this->manager->get_cache_settings())) {
            $result['status']      = 'critical';
            $result['label']       = __('Varnish Cache settings file not found', 'clp-varnish-cache');
            $result['description'] = '<p>' . __('The settings.json file is missing. Configure Varnish Cache in CloudPanel first.', 'clp-varnish-cache') . '</p>';
            return $result;
        }

        $errors = ClpVarnishCacheManager::validate_settings([
            'server'         => $this->manager->get_server(),
            'cacheLifetime'  => $this->manager->get_cache_lifetime(),
            'cacheTagPrefix' => $this->manager->get_cache_tag_prefix(),
        ]);

        if (!empty($errors)) {
            $result['status']      = 'critical';
            $result['label']       = __('Varnish Cache has invalid settings', 'clp-varnish-cache');
            $result['description'] = '<ul><li>' . implode('</li><li>', array_map('esc_html', $errors)) . '</li></ul>';
        }

        return $result;
    }

    public function test_connection(): array {
        $settings_url = admin_url('options-general.php?page=clp-varnish-cache');
        $result       = [
            'label'       => __('Varnish Cache server is reachable', 'clp-varnish-cache'),
            'status'      => 'good',
            'badge'       => ['label' => __('Performance', 'clp-varnish-cache'), 'color' => 'blue'],
            'description' => '<p>' . __('WordPress can reach the Varnish server and send PURGE requests successfully.', 'clp-varnish-cache') . '</p>',
            'actions'     => sprintf('<a href="%s">%s</a>', esc_url($settings_url), __('Review settings', 'clp-varnish-cache')),
            'test'        => 'clp_varnish_connection',
        ];

        if (!$this->manager->is_enabled()) {
            $result['status']      = 'recommended';
            $result['label']       = __('Varnish Cache is disabled', 'clp-varnish-cache');
            $result['description'] = '<p>' . __('Enable Varnish Cache in Settings → CLP Varnish Cache to improve site performance.', 'clp-varnish-cache') . '</p>';
            return $result;
        }

        $outcome = $this->manager->test_connection($this->manager->get_server());
        if (true !== $outcome) {
            $result['status']      = 'critical';
            $result['label']       = __('Cannot reach the Varnish Cache server', 'clp-varnish-cache');
            $result['description'] = '<p>' . sprintf(
                /* translators: %s: error detail */
                __('Connection to Varnish failed: %s', 'clp-varnish-cache'),
                esc_html($outcome)
            ) . '</p>';
        }

        return $result;
    }
}
