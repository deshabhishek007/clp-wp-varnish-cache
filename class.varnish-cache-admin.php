<?php
/**
 * WordPress admin integration: menus, admin bar, notices, and settings page.
 *
 * @package CLP_Varnish_Cache
 */

/**
 * Registers admin menus, the admin bar shortcut, asset enqueueing,
 * and the AJAX endpoint for connection testing.
 */
class ClpVarnishCacheAdmin {

	/**
	 * Shared cache manager instance.
	 *
	 * @var ClpVarnishCacheManager
	 */
	private readonly ClpVarnishCacheManager $clp_varnish_cache_manager;

	/**
	 * Boots the admin class by instantiating the manager and registering hooks.
	 */
	public function __construct() {
		$this->clp_varnish_cache_manager = new ClpVarnishCacheManager();
		$this->init();
	}

	/**
	 * Registers all admin hooks and instantiates the Site Health integration.
	 */
	public function init(): void {
		add_action( 'admin_init', $this->check_entire_cache_purge( ... ), 100 );
		add_action( 'admin_init', $this->show_activation_notice( ... ) );
		add_action( 'admin_notices', $this->show_purge_notice( ... ) );
		add_action( 'network_admin_notices', $this->show_purge_notice( ... ) );
		add_action( 'admin_bar_menu', $this->add_adminbar( ... ), 100 );
		add_action( 'admin_menu', $this->add_admin_menu( ... ), 100 );
		add_action( 'network_admin_menu', $this->add_admin_menu( ... ), 100 );
		add_action( 'admin_enqueue_scripts', $this->enqueue_assets( ... ) );
		add_action( 'wp_ajax_clp_varnish_test_connection', $this->ajax_test_connection( ... ) );

		new ClpVarnishCacheHealth( $this->clp_varnish_cache_manager );
	}

	/**
	 * Handles the admin-bar "Purge Entire Cache" GET action via Post-Redirect-Get.
	 */
	public function check_entire_cache_purge(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified on the next line.
		if ( ! isset( $_GET['clp-varnish-cache'] ) || 'purge-entire-cache' !== sanitize_text_field( wp_unslash( $_GET['clp-varnish-cache'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'purge-entire-cache' ) ) {
			return;
		}

		try {
			$this->clp_varnish_cache_manager->purge_everything();
			self::set_purge_notice( 'success', __( 'Varnish Cache has been purged.', 'clp-varnish-cache' ) );
		} catch ( \Exception $e ) {
			error_log( sprintf( 'CLP Varnish Cache: admin bar purge failed — %s', $e->getMessage() ) );
			self::set_purge_notice( 'error', $e->getMessage() );
		}

		wp_safe_redirect( remove_query_arg( array( 'clp-varnish-cache', '_wpnonce' ) ) );
		exit();
	}

	/**
	 * Stores a one-time admin notice for the current user via a 60-second transient.
	 *
	 * @param string $type    Notice type: 'success' or 'error'.
	 * @param string $message Notice message text.
	 */
	public static function set_purge_notice( string $type, string $message ): void {
		set_transient( 'clp_varnish_purge_notice_' . get_current_user_id(), compact( 'type', 'message' ), 60 ); // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	}

	/**
	 * Renders the one-time admin notice and deletes the transient.
	 */
	public function show_purge_notice(): void {
		$notice = get_transient( 'clp_varnish_purge_notice_' . get_current_user_id() );
		if ( empty( $notice ) ) {
			return;
		}
		delete_transient( 'clp_varnish_purge_notice_' . get_current_user_id() );

		$class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p><strong>'
			. esc_html( $notice['message'] )
			. '</strong></p></div>';
	}

	/**
	 * Displays a one-time warning when the settings file is missing after activation.
	 */
	public function show_activation_notice(): void {
		if ( ! get_transient( 'clp_varnish_activation_notice' ) ) {
			return;
		}
		delete_transient( 'clp_varnish_activation_notice' );
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>'
				. esc_html__( 'CLP Varnish Cache: settings file not found. Please configure Varnish Cache in CloudPanel first.', 'clp-varnish-cache' )
				. '</strong></p></div>';
			}
		);
	}

	/**
	 * Handles the AJAX connection test request from the settings page.
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'clp-test-connection', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'clp-varnish-cache' ) ) );
		}

		$server = sanitize_text_field( wp_unslash( $_POST['server'] ?? '' ) );
		if ( empty( $server ) ) {
			wp_send_json_error( array( 'message' => __( 'Server address is required.', 'clp-varnish-cache' ) ) );
		}

		$server_errors = array_filter(
			ClpVarnishCacheManager::validate_settings(
				array(
					'server'         => $server,
					'cacheLifetime'  => '86400',
					'cacheTagPrefix' => 'test',
				)
			),
			static fn ( string $e ): bool => str_contains( $e, 'Server' )
		);

		if ( ! empty( $server_errors ) ) {
			wp_send_json_error( array( 'message' => reset( $server_errors ) ) );
		}

		$result = $this->clp_varnish_cache_manager->test_connection( $server );
		match ( true ) {
			true === $result => wp_send_json_success( array( 'message' => __( 'Connection successful — Varnish responded with HTTP 200.', 'clp-varnish-cache' ) ) ),
			default          => wp_send_json_error( array( 'message' => $result ) ),
		};
	}

	/**
	 * Returns the shared cache manager instance.
	 */
	public function get_clp_cache_manager(): ClpVarnishCacheManager {
		return $this->clp_varnish_cache_manager;
	}

	/**
	 * Adds the Varnish Cache node and its children to the WordPress admin bar.
	 *
	 * @param \WP_Admin_Bar $adminbar Admin bar instance.
	 */
	public function add_adminbar( \WP_Admin_Bar $adminbar ): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $this->clp_varnish_cache_manager->get_cache_settings() ) ) {
			return;
		}

		$is_network   = is_multisite() && is_network_admin();
		$settings_url = $is_network
			? network_admin_url( 'settings.php?page=clp-varnish-cache' )
			: admin_url( 'options-general.php?page=clp-varnish-cache' );

		$nodes = array(
			array(
				'id'    => 'clp-varnish-cache',
				'title' => '<span class="ab-icon" style="background-image: url(' . self::get_svg_icon() . ') !important;"></span>'
						. '<span class="ab-label">' . __( 'CLP Varnish Cache', 'clp-varnish-cache' ) . '</span>',
				'meta'  => array( 'class' => 'clp-varnish-cache' ),
			),
			array(
				'parent' => 'clp-varnish-cache',
				'id'     => 'clp-varnish-cache-purge',
				'title'  => __( 'Purge', 'clp-varnish-cache' ),
				'meta'   => array( 'tabindex' => '0' ),
			),
			array(
				'parent' => 'clp-varnish-cache-purge',
				'id'     => 'clp-varnish-cache-purge-entire-cache',
				'title'  => __( 'Entire Cache', 'clp-varnish-cache' ),
				'href'   => wp_nonce_url( add_query_arg( 'clp-varnish-cache', 'purge-entire-cache' ), 'purge-entire-cache' ),
				'meta'   => array( 'title' => __( 'Entire Cache', 'clp-varnish-cache' ) ),
			),
			array(
				'parent' => 'clp-varnish-cache-purge',
				'id'     => 'clp-varnish-cache-purge-tags-urls',
				'title'  => __( 'Cache Tags and Urls', 'clp-varnish-cache' ),
				'href'   => $settings_url,
				'meta'   => array( 'title' => __( 'Cache Tags and Urls', 'clp-varnish-cache' ) ),
			),
			array(
				'parent' => 'clp-varnish-cache',
				'id'     => 'clp-varnish-cache-enable',
				'title'  => __( 'Settings', 'clp-varnish-cache' ),
				'href'   => $settings_url,
				'meta'   => array( 'tabindex' => '0' ),
			),
		);

		array_walk( $nodes, $adminbar->add_node( ... ) );
	}

	/**
	 * Registers the plugin settings page under Settings (and Network Settings on multisite).
	 */
	public function add_admin_menu(): void {
		$is_network = is_multisite() && is_network_admin();
		add_submenu_page(
			$is_network ? 'settings.php' : 'options-general.php',
			__( 'CLP Varnish Cache', 'clp-varnish-cache' ),
			__( 'CLP Varnish Cache', 'clp-varnish-cache' ),
			'manage_options',
			'clp-varnish-cache',
			$this->clp_varnish_cache_page( ... )
		);
	}

	/**
	 * Includes the settings page template.
	 */
	public function clp_varnish_cache_page(): void {
		include sprintf( '%s/pages/clp-varnish-cache.php', rtrim( plugin_dir_path( __FILE__ ), '/' ) );
	}

	/**
	 * Enqueues the admin stylesheet (on all admin pages) and the admin JS (settings page only).
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( is_user_logged_in() && is_admin_bar_showing() ) {
			wp_register_style( 'clp-varnish-cache', plugins_url( 'style.css', __FILE__ ), array(), CLP_VARNISH_VERSION );
			wp_enqueue_style( 'clp-varnish-cache' );
		}

		if ( ! in_array( $hook, array( 'settings_page_clp-varnish-cache', 'network_page_clp-varnish-cache' ), strict: true ) ) {
			return;
		}

		wp_enqueue_script(
			'clp-varnish-cache-admin',
			plugins_url( 'js/admin.js', __FILE__ ),
			array( 'jquery' ),
			CLP_VARNISH_VERSION,
			true
		);
		wp_localize_script(
			'clp-varnish-cache-admin',
			'clpVarnish',
			array(
				'nonce'         => wp_create_nonce( 'clp-test-connection' ),
				'test'          => __( 'Test Connection', 'clp-varnish-cache' ),
				'testing'       => __( 'Testing…', 'clp-varnish-cache' ),
				'emptyServer'   => __( 'Enter a server address first.', 'clp-varnish-cache' ),
				'requestFailed' => __( 'Request failed. Check your network.', 'clp-varnish-cache' ),
			)
		);
	}

	/**
	 * Returns the plugin SVG icon, optionally base64-encoded for use as a CSS background-image.
	 *
	 * @param bool $base64 When true (default) returns a data URI; otherwise returns raw SVG.
	 * @return string SVG data URI or raw SVG markup.
	 */
	public static function get_svg_icon( bool $base64 = true ): string {
		static $cache = array();
		$key          = $base64 ? 'b64' : 'raw';
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$svg          = '<svg width="100%" viewBox="0 5 20 20" xmlns="http://www.w3.org/2000/svg"><path fill="#006ad0" d="m15.676002,13.634a4.959,4.959 0 0 0 -2.363,-1.649l0,-0.06c0,-2.823 -2.208,-5.124 -4.93,-5.124c-2.724,0 -4.933,2.296 -4.933,5.125l0,0.07c-1.994,0.653 -3.45,2.595 -3.45,4.886c0,2.823 2.21,5.125 4.932,5.125a4.832,4.832 0 0 0 3.465,-1.475a4.817,4.817 0 0 0 3.461,1.475c2.717,0 4.933,-2.296 4.933,-5.125c0,-1.18 -0.4,-2.334 -1.115,-3.248zm-3.818,7.077c-2.031,0 -3.685,-1.718 -3.685,-3.83a0.637,0.637 0 0 0 -0.623,-0.646a0.634,0.634 0 0 0 -0.624,0.647c0,0.957 0.257,1.855 0.696,2.622a3.607,3.607 0 0 1 -2.69,1.213c-2.032,0 -3.687,-1.719 -3.687,-3.83c0,-2.11 1.655,-3.829 3.687,-3.829c0.44,0 0.868,0.082 1.278,0.234c0.005,0 0.009,0.005 0.014,0.005c0.142,0.05 0.342,0.147 0.404,0.201a0.6,0.6 0 0 0 0.874,-0.07a0.659,0.659 0 0 0 -0.068,-0.91c-0.272,-0.239 -0.696,-0.402 -0.8,-0.44a4.767,4.767 0 0 0 -1.697,-0.31c-0.079,0 -0.157,0 -0.236,0.005c0.084,-2.04 1.702,-3.671 3.687,-3.671c2.031,0 3.685,1.718 3.685,3.83a3.896,3.896 0 0 1 -1.55,3.122a0.663,0.663 0 0 0 -0.147,0.898c0.12,0.174 0.315,0.272 0.509,0.272c0.125,0 0.25,-0.038 0.361,-0.12a5.164,5.164 0 0 0 1.895,-2.812c1.424,0.549 2.413,1.979 2.413,3.595c-0.005,2.106 -1.659,3.824 -3.696,3.824z"/></svg>';
		$cache['raw'] = $svg;
		$cache['b64'] = 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return $cache[ $key ];
	}
}
