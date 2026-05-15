<?php
/**
 * WordPress Site Health integration for CLP Varnish Cache.
 *
 * @package CLP_Varnish_Cache
 */

/**
 * Registers Site Health checks that verify settings and server connectivity.
 */
final class ClpVarnishCacheHealth {

	/**
	 * Stores the cache manager and registers the site_status_tests filter.
	 *
	 * @param ClpVarnishCacheManager $manager Cache manager instance.
	 */
	public function __construct(
		private readonly ClpVarnishCacheManager $manager
	) {
		add_filter( 'site_status_tests', $this->register_tests( ... ) );
	}

	/**
	 * Registers the Varnish health checks with the Site Health API.
	 *
	 * @param array<string, mixed> $tests Existing tests array.
	 * @return array<string, mixed>
	 */
	public function register_tests( array $tests ): array {
		$tests['direct']['clp_varnish_settings']   = array(
			'label' => __( 'Varnish Cache settings are valid', 'clp-varnish-cache' ),
			'test'  => $this->test_settings( ... ),
		);
		$tests['direct']['clp_varnish_connection'] = array(
			'label' => __( 'Varnish Cache server is reachable', 'clp-varnish-cache' ),
			'test'  => $this->test_connection( ... ),
		);
		return $tests;
	}

	/**
	 * Checks that the settings file exists and passes validation.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_settings(): array {
		$settings_url = admin_url( 'options-general.php?page=clp-varnish-cache' );
		$result       = array(
			'label'       => __( 'Varnish Cache is configured correctly', 'clp-varnish-cache' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'clp-varnish-cache' ),
				'color' => 'blue',
			),
			'description' => '<p>' . __( 'Varnish Cache settings are present and valid.', 'clp-varnish-cache' ) . '</p>',
			'actions'     => sprintf( '<a href="%s">%s</a>', esc_url( $settings_url ), __( 'Review settings', 'clp-varnish-cache' ) ),
			'test'        => 'clp_varnish_settings',
		);

		if ( empty( $this->manager->get_cache_settings() ) ) {
			return array_merge(
				$result,
				array(
					'status'      => 'critical',
					'label'       => __( 'Varnish Cache settings file not found', 'clp-varnish-cache' ),
					'description' => '<p>' . __( 'The settings.json file is missing. Configure Varnish Cache in CloudPanel first.', 'clp-varnish-cache' ) . '</p>',
				)
			);
		}

		$errors = ClpVarnishCacheManager::validate_settings(
			array(
				'server'         => $this->manager->get_server(),
				'cacheLifetime'  => $this->manager->get_cache_lifetime(),
				'cacheTagPrefix' => $this->manager->get_cache_tag_prefix(),
			)
		);

		if ( ! empty( $errors ) ) {
			return array_merge(
				$result,
				array(
					'status'      => 'critical',
					'label'       => __( 'Varnish Cache has invalid settings', 'clp-varnish-cache' ),
					'description' => '<ul><li>' . implode( '</li><li>', array_map( esc_html( ... ), $errors ) ) . '</li></ul>',
				)
			);
		}

		return $result;
	}

	/**
	 * Sends a test PURGE request to verify the Varnish server is reachable.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_connection(): array {
		$settings_url = admin_url( 'options-general.php?page=clp-varnish-cache' );
		$result       = array(
			'label'       => __( 'Varnish Cache server is reachable', 'clp-varnish-cache' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'clp-varnish-cache' ),
				'color' => 'blue',
			),
			'description' => '<p>' . __( 'WordPress can reach the Varnish server and send PURGE requests successfully.', 'clp-varnish-cache' ) . '</p>',
			'actions'     => sprintf( '<a href="%s">%s</a>', esc_url( $settings_url ), __( 'Review settings', 'clp-varnish-cache' ) ),
			'test'        => 'clp_varnish_connection',
		);

		if ( ! $this->manager->is_enabled() ) {
			return array_merge(
				$result,
				array(
					'status'      => 'recommended',
					'label'       => __( 'Varnish Cache is disabled', 'clp-varnish-cache' ),
					'description' => '<p>' . __( 'Enable Varnish Cache in Settings → CLP Varnish Cache to improve site performance.', 'clp-varnish-cache' ) . '</p>',
				)
			);
		}

		$outcome = $this->manager->test_connection( $this->manager->get_server() );
		if ( true !== $outcome ) {
			return array_merge(
				$result,
				array(
					'status'      => 'critical',
					'label'       => __( 'Cannot reach the Varnish Cache server', 'clp-varnish-cache' ),
					'description' => '<p>' . sprintf(
						/* translators: %s: error detail */
						__( 'Connection to Varnish failed: %s', 'clp-varnish-cache' ),
						esc_html( $outcome )
					) . '</p>',
				)
			);
		}

		return $result;
	}
}
