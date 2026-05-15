<?php
/**
 * WP-CLI commands for managing the Varnish cache from the command line.
 *
 * @package CLP_Varnish_Cache
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
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
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 * @throws \InvalidArgumentException When no valid purge option is given.
	 *
	 * @when after_wp_load
	 */
	public function purge( array $args, array $assoc_args ): void {
		$manager = new ClpVarnishCacheManager();

		if ( ! $manager->is_enabled() ) {
			WP_CLI::error( 'Varnish Cache is not enabled. Enable it in Settings → CLP Varnish Cache.' );
		}

		$all = \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		$url = \WP_CLI\Utils\get_flag_value( $assoc_args, 'url', '' );
		$tag = \WP_CLI\Utils\get_flag_value( $assoc_args, 'tag', '' );

		try {
			match ( true ) {
				(bool) $all    => $this->purge_all( $manager ),
				! empty( $url ) => $this->purge_url( $manager, $url ),
				! empty( $tag ) => $this->purge_tag( $manager, $tag ),
				default        => throw new \InvalidArgumentException( 'Specify --all, --url=<url>, or --tag=<tag>.' ),
			};
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Display current Varnish Cache status and settings.
	 *
	 * ## EXAMPLES
	 *
	 *   wp varnish status
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments (unused).
	 *
	 * @when after_wp_load
	 */
	public function status( array $args, array $assoc_args ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$manager = new ClpVarnishCacheManager();

		if ( empty( $manager->get_cache_settings() ) ) {
			WP_CLI::error( 'Settings file not found. Configure Varnish Cache in CloudPanel first.' );
		}

		$excluded = $manager->get_excluded_params();
		$rows     = array(
			array(
				'Setting' => 'Enabled',
				'Value'   => $manager->is_enabled() ? 'Yes' : 'No',
			),
			array(
				'Setting' => 'Server',
				'Value'   => $manager->get_server(),
			),
			array(
				'Setting' => 'Cache Lifetime',
				'Value'   => $manager->get_cache_lifetime() . 's',
			),
			array(
				'Setting' => 'Cache Tag Prefix',
				'Value'   => $manager->get_cache_tag_prefix(),
			),
			array(
				'Setting' => 'Excluded Params',
				'Value'   => ! empty( $excluded ) ? $excluded : '(none)',
			),
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Setting', 'Value' ) );
	}

	/**
	 * Show the last 20 purge operations.
	 *
	 * ## EXAMPLES
	 *
	 *   wp varnish log
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments (unused).
	 *
	 * @when after_wp_load
	 */
	public function log( array $args, array $assoc_args ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$entries = ClpVarnishCacheLogger::get_log();

		if ( empty( $entries ) ) {
			WP_CLI::line( 'No purge history recorded yet.' );
			return;
		}

		$rows = array_map(
			static fn ( array $entry ): array => array(
				'Time'    => $entry['time'],
				'Type'    => $entry['type'],
				'Target'  => $entry['target'],
				'Status'  => $entry['success'] ? 'OK' : 'FAILED',
				'Message' => $entry['message'],
			),
			$entries
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Time', 'Type', 'Target', 'Status', 'Message' ) );
	}

	/**
	 * Clear the purge history log.
	 *
	 * ## EXAMPLES
	 *
	 *   wp varnish clear-log
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments (unused).
	 *
	 * @when after_wp_load
	 */
	public function clear_log( array $args, array $assoc_args ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		ClpVarnishCacheLogger::clear();
		WP_CLI::success( 'Purge log cleared.' );
	}

	/**
	 * Dispatches a full-cache purge and prints a success message.
	 *
	 * @param ClpVarnishCacheManager $manager Cache manager instance.
	 */
	private function purge_all( ClpVarnishCacheManager $manager ): void {
		$manager->purge_everything();
		WP_CLI::success( 'Entire cache purged.' );
	}

	/**
	 * Dispatches a URL purge and prints a success message.
	 *
	 * @param ClpVarnishCacheManager $manager Cache manager instance.
	 * @param string                 $url     URL to purge.
	 */
	private function purge_url( ClpVarnishCacheManager $manager, string $url ): void {
		$manager->purge_url( $url );
		WP_CLI::success( sprintf( 'Purged URL: %s', $url ) );
	}

	/**
	 * Dispatches a tag purge and prints a success message.
	 *
	 * @param ClpVarnishCacheManager $manager Cache manager instance.
	 * @param string                 $tag     Cache tag to purge.
	 */
	private function purge_tag( ClpVarnishCacheManager $manager, string $tag ): void {
		$manager->purge_tag( $tag );
		WP_CLI::success( sprintf( 'Purged tag: %s', $tag ) );
	}
}

WP_CLI::add_command( 'varnish', 'ClpVarnishCacheCLI' );
