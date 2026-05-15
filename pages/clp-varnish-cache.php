<?php

if (!current_user_can('manage_options')) {
    wp_die(__('You do not have permission to access this page.', 'clp-varnish-cache'));
}

global $clp_varnish_cache_admin;
$is_network      = is_multisite() && is_network_admin();
$successNotice   = null;
$errorNotice     = null;
$validationErrors = [];
$host            = wp_parse_url(home_url(), PHP_URL_HOST);

$get_post_value = static function (string $key): string {
    return isset($_POST[$key]) ? sanitize_text_field($_POST[$key]) : '';
};

$clp_cache_manager = $clp_varnish_cache_admin->get_clp_cache_manager();

// ── Save Settings ──────────────────────────────────────────────────────────
if (isset($_POST['action']) && 'save-settings' === sanitize_text_field($_POST['action'])) {
    check_admin_referer('clp-save-settings');

    $old_cache_tag_prefix = $clp_cache_manager->get_cache_tag_prefix();
    $enabled              = 1 == $get_post_value('enabled');
    $server               = $get_post_value('server');
    $cache_lifetime       = $get_post_value('cache-lifetime');
    $cache_tag_prefix     = $get_post_value('cache-tag-prefix');
    $excluded_params      = array_map(trim(...), array_filter(explode(',', $get_post_value('excluded-params'))));
    $excludes             = isset($_POST['excludes']) ? sanitize_textarea_field($_POST['excludes']) : '';
    $excludes             = array_map(trim(...), array_filter(explode(PHP_EOL, $excludes)));

    $validationErrors = ClpVarnishCacheManager::validate_settings([
        'server'         => $server,
        'cacheLifetime'  => $cache_lifetime,
        'cacheTagPrefix' => $cache_tag_prefix,
    ]);

    if (empty($validationErrors)) {
        $cache_settings = [
            'enabled'        => $enabled,
            'server'         => $server,
            'cacheTagPrefix' => $cache_tag_prefix,
            'cacheLifetime'  => $cache_lifetime,
            'excludes'       => $excludes,
            'excludedParams' => $excluded_params,
        ];
        try {
            $clp_cache_manager->write_cache_settings($cache_settings);
            $clp_cache_manager->reset_cache_settings();
            if (!$enabled) {
                if (!empty($old_cache_tag_prefix)) $clp_cache_manager->purge_tag($old_cache_tag_prefix);
                if (!empty($host)) $clp_cache_manager->purge_host($host);
            }
            $successNotice = __('Settings have been saved.', 'clp-varnish-cache');
        } catch (\Exception $e) {
            $errorNotice = $e->getMessage();
        }
    }
}

// ── Purge Cache ────────────────────────────────────────────────────────────
if (isset($_POST['action']) && 'purge-cache' === sanitize_text_field($_POST['action'])) {
    check_admin_referer('clp-purge-cache');
    $purge_values = array_map(trim(...), array_filter(explode(',', $get_post_value('purge-value'))));
    if (!empty($purge_values)) {
        try {
            foreach ($purge_values as $purge_value) {
                if (empty($purge_value)) continue;
                str_starts_with($purge_value, 'http')
                    ? $clp_cache_manager->purge_url($purge_value)
                    : $clp_cache_manager->purge_tag($purge_value);
            }
            $successNotice = __('Varnish Cache has been purged.', 'clp-varnish-cache');
        } catch (\Exception $e) {
            $errorNotice = $e->getMessage();
        }
    }
}

// ── Purge Entire Cache (GET) ───────────────────────────────────────────────
if (isset($_GET['action']) && 'purge-entire-cache' === sanitize_text_field($_GET['action'])) {
    check_admin_referer('clp-purge-entire-cache');
    try {
        $prefix = $clp_cache_manager->get_cache_tag_prefix();
        if (!empty($host) && !empty($prefix)) {
            $clp_cache_manager->purge_host_and_tag($host, $prefix);
        } elseif (!empty($host)) {
            $clp_cache_manager->purge_host($host);
        } elseif (!empty($prefix)) {
            $clp_cache_manager->purge_tag($prefix);
        }
        ClpVarnishCacheAdmin::set_purge_notice('success', __('Varnish Cache has been purged.', 'clp-varnish-cache'));
    } catch (\Exception $e) {
        ClpVarnishCacheAdmin::set_purge_notice('error', $e->getMessage());
    }
    wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
    exit();
}

$clp_cache_settings    = $clp_cache_manager->get_cache_settings();
$is_enabled            = $clp_cache_manager->is_enabled();
$server                = $clp_cache_manager->get_server();
$cache_lifetime        = $clp_cache_manager->get_cache_lifetime();
$cache_tag_prefix      = $clp_cache_manager->get_cache_tag_prefix();
$excluded_params       = $clp_cache_manager->get_excluded_params();
$excludes              = $clp_cache_manager->get_excludes();

$settings_url          = $is_network
    ? network_admin_url('settings.php?page=clp-varnish-cache')
    : admin_url('options-general.php?page=clp-varnish-cache');
$purge_entire_cache_url = wp_nonce_url(
    add_query_arg('action', 'purge-entire-cache', $settings_url),
    'clp-purge-entire-cache'
);
$purge_log             = ClpVarnishCacheLogger::get_log();

?>
<h1 id="clp-varnish-cache"><?php esc_html_e('CLP Varnish Cache', 'clp-varnish-cache'); ?></h1>

<div class="clp-varnish-cache-container">
  <?php if (!empty($clp_cache_settings)): ?>

    <?php if (!is_null($successNotice)): ?>
      <div id="notice" class="notice notice-success fade is-dismissible">
        <p><strong><?php echo esc_html($successNotice); ?></strong></p>
      </div>
    <?php endif; ?>

    <?php if (!is_null($errorNotice)): ?>
      <div id="notice" class="notice notice-error fade is-dismissible">
        <p><strong><?php echo esc_html($errorNotice); ?></strong></p>
      </div>
    <?php endif; ?>

    <?php if (!empty($validationErrors)): ?>
      <div id="notice" class="notice notice-error fade is-dismissible">
        <ul>
          <?php foreach ($validationErrors as $err): ?>
            <li><strong><?php echo esc_html($err); ?></strong></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="clp-varnish-cache-block-container">

      <!-- Settings form -->
      <form action="<?php echo esc_url($settings_url); ?>" method="post">
        <?php wp_nonce_field('clp-save-settings'); ?>
        <div class="clp-varnish-cache-block">
          <div class="clp-varnish-cache-block-header">
            <h3><?php esc_html_e('Settings', 'clp-varnish-cache'); ?></h3>
          </div>
          <div class="clp-varnish-cache-block-content clp-varnish-cache-block-settings">
            <table class="form-table">
              <tbody>
                <tr>
                  <td class="field-name"><?php esc_html_e('Enable Varnish Cache', 'clp-varnish-cache'); ?>:</td>
                  <td>
                    <input type="checkbox" name="enabled" <?php checked($is_enabled); ?> value="1" />
                  </td>
                </tr>
                <tr>
                  <td class="field-name"><?php esc_html_e('Varnish Server', 'clp-varnish-cache'); ?>:</td>
                  <td>
                    <input type="text" name="server" required="required" value="<?php echo esc_attr($server); ?>" placeholder="127.0.0.1:6081" />
                    <button type="button" id="clp-varnish-test-connection" class="button" style="margin-left:8px;">
                      <?php esc_html_e('Test Connection', 'clp-varnish-cache'); ?>
                    </button>
                    <span id="clp-varnish-test-result" style="margin-left:8px;vertical-align:middle;"></span>
                    <p class="description"><?php esc_html_e('Format: hostname:port or IP:port (e.g. 127.0.0.1:6081)', 'clp-varnish-cache'); ?></p>
                  </td>
                </tr>
                <tr>
                  <td class="field-name"><?php esc_html_e('Cache Lifetime', 'clp-varnish-cache'); ?>:</td>
                  <td>
                    <input type="number" name="cache-lifetime" required="required" min="1" value="<?php echo esc_attr($cache_lifetime); ?>" />
                    <p class="description"><?php esc_html_e('Seconds before cached content is refreshed.', 'clp-varnish-cache'); ?></p>
                  </td>
                </tr>
                <tr>
                  <td class="field-name"><?php esc_html_e('Cache Tag Prefix', 'clp-varnish-cache'); ?>:</td>
                  <td>
                    <input type="text" name="cache-tag-prefix" required="required" value="<?php echo esc_attr($cache_tag_prefix); ?>" pattern="[a-zA-Z0-9_\-]+" />
                    <p class="description"><?php esc_html_e('Letters, numbers, hyphens, and underscores only.', 'clp-varnish-cache'); ?></p>
                  </td>
                </tr>
                <tr>
                  <td class="field-name"><?php esc_html_e('Excluded Params', 'clp-varnish-cache'); ?>:</td>
                  <td>
                    <input type="text" name="excluded-params" value="<?php echo esc_attr($excluded_params); ?>" />
                    <p class="description"><?php esc_html_e('GET parameters that bypass caching, comma-separated.', 'clp-varnish-cache'); ?></p>
                  </td>
                </tr>
                <tr>
                  <td class="field-name"><?php esc_html_e('Excludes', 'clp-varnish-cache'); ?>:</td>
                  <td>
                    <textarea name="excludes" rows="6"><?php echo esc_textarea($excludes); ?></textarea>
                    <p class="description"><?php esc_html_e('URLs and paths that Varnish should not cache, one per line.', 'clp-varnish-cache'); ?></p>
                  </td>
                </tr>
              </tbody>
            </table>
            <input type="hidden" name="action" value="save-settings" />
            <input type="submit" class="button action" value="<?php esc_attr_e('Save', 'clp-varnish-cache'); ?>" />
          </div>
        </div>
      </form>

      <!-- Purge Cache form -->
      <form action="<?php echo esc_url($settings_url); ?>" method="post">
        <?php wp_nonce_field('clp-purge-cache'); ?>
        <div class="clp-varnish-cache-block">
          <div class="clp-varnish-cache-block-header">
            <h3><?php esc_html_e('Purge Cache', 'clp-varnish-cache'); ?></h3>
            <a class="button button-primary" href="<?php echo esc_url($purge_entire_cache_url); ?>">
              <?php esc_html_e('Purge Entire Cache', 'clp-varnish-cache'); ?>
            </a>
          </div>
          <div class="clp-varnish-cache-block-content clp-varnish-cache-block-purge-cache">
            <table class="form-table">
              <tbody>
                <tr>
                  <td>
                    <input type="text" name="purge-value" required="required" class="purge-value" placeholder="https://www.domain.com/page/ or cache-tag" />
                    <p class="description"><?php esc_html_e('Purge a URL (starting with http) or a cache tag. Separate multiple values with a comma.', 'clp-varnish-cache'); ?></p>
                  </td>
                </tr>
              </tbody>
            </table>
            <input type="hidden" name="action" value="purge-cache" />
            <input type="submit" class="button action" value="<?php esc_attr_e('Purge Cache', 'clp-varnish-cache'); ?>" />
          </div>
        </div>
      </form>

      <!-- Purge History Log -->
      <?php if (!empty($purge_log)): ?>
      <div class="clp-varnish-cache-block">
        <div class="clp-varnish-cache-block-header">
          <h3><?php esc_html_e('Purge History', 'clp-varnish-cache'); ?></h3>
        </div>
        <div class="clp-varnish-cache-block-content">
          <table class="wp-list-table widefat fixed striped">
            <thead>
              <tr>
                <th><?php esc_html_e('Time', 'clp-varnish-cache'); ?></th>
                <th><?php esc_html_e('Type', 'clp-varnish-cache'); ?></th>
                <th><?php esc_html_e('Target', 'clp-varnish-cache'); ?></th>
                <th><?php esc_html_e('Status', 'clp-varnish-cache'); ?></th>
                <th><?php esc_html_e('Message', 'clp-varnish-cache'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($purge_log as $entry): ?>
              <tr>
                <td><?php echo esc_html($entry['time']); ?></td>
                <td><?php echo esc_html($entry['type']); ?></td>
                <td style="word-break:break-all;"><?php echo esc_html($entry['target']); ?></td>
                <td>
                  <?php if ($entry['success']): ?>
                    <span class="clp-log-ok">&#10003; OK</span>
                  <?php else: ?>
                    <span class="clp-log-fail">&#10007; Failed</span>
                  <?php endif; ?>
                </td>
                <td><?php echo esc_html($entry['message']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="description" style="margin-top:8px;">
            <?php printf(
                /* translators: %d: number of entries */
                esc_html__('Showing last %d purge operations. Use WP-CLI %s to clear.', 'clp-varnish-cache'),
                count($purge_log),
                '<code>wp varnish clear-log</code>'
            ); ?>
          </p>
        </div>
      </div>
      <?php endif; ?>

      <!-- Support -->
      <div class="clp-varnish-cache-block">
        <div class="clp-varnish-cache-block-header">
          <h3><?php esc_html_e('Support', 'clp-varnish-cache'); ?></h3>
        </div>
        <div class="clp-varnish-cache-block-content">
          <table class="form-table">
            <tbody>
              <tr>
                <td class="field-name"><?php esc_html_e('Documentation', 'clp-varnish-cache'); ?>:</td>
                <td><a target="_blank" href="https://www.cloudpanel.io/docs/v2/frontend-area/varnish-cache/wordpress/plugin/">https://www.cloudpanel.io/docs/v2/frontend-area/varnish-cache/wordpress/plugin/</a></td>
              </tr>
              <tr>
                <td class="field-name"><?php esc_html_e('Discord', 'clp-varnish-cache'); ?>:</td>
                <td><a target="_blank" href="https://discord.cloudpanel.io/">https://discord.cloudpanel.io/</a></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  <?php else: ?>
    <div id="notice" class="notice notice-error fade is-dismissible">
      <p><strong><?php esc_html_e('Settings File Not Found!', 'clp-varnish-cache'); ?></strong></p>
    </div>
  <?php endif; ?>
</div>
