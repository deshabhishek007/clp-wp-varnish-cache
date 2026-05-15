<?php
/**
 * Core cache management: settings I/O, validation, and Varnish PURGE requests.
 *
 * @package CLP_Varnish_Cache
 */

/**
 * Reads and writes the Varnish settings file, validates configuration,
 * and dispatches PURGE HTTP requests to the Varnish server.
 */
class ClpVarnishCacheManager {

	/**
	 * Absolute path to the settings.json file.
	 *
	 * @var string
	 */
	private readonly string $settings_file;

	/**
	 * In-process settings cache; avoids repeated file reads within a single request.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache_settings = array();

	/**
	 * Resolves the settings file path from the home directory.
	 */
	public function __construct() {
		$home = getenv( 'HOME' );
		if ( empty( $home ) ) {
			$home = posix_getpwuid( posix_getuid() )['dir'] ?? '';
		}
		$this->settings_file = sprintf( '%s/.varnish-cache/settings.json', rtrim( (string) $home, '/' ) );
	}

	/**
	 * Returns true when Varnish caching is enabled in the settings file.
	 */
	public function is_enabled(): bool {
		$settings = $this->get_cache_settings();
		return isset( $settings['enabled'] ) && true === $settings['enabled'];
	}

	/**
	 * Returns the configured Varnish server address (e.g. 127.0.0.1:6081).
	 */
	public function get_server(): string {
		return $this->get_cache_settings()['server'] ?? '';
	}

	/**
	 * Returns the configured cache lifetime in seconds.
	 */
	public function get_cache_lifetime(): string {
		return $this->get_cache_settings()['cacheLifetime'] ?? '';
	}

	/**
	 * Returns the configured cache tag prefix used for X-Cache-Tags headers.
	 */
	public function get_cache_tag_prefix(): string {
		return $this->get_cache_settings()['cacheTagPrefix'] ?? '';
	}

	/**
	 * Returns excluded query-string parameters as a comma-separated string.
	 */
	public function get_excluded_params(): string {
		$params = (array) ( $this->get_cache_settings()['excludedParams'] ?? array() );
		return implode( ',', $params );
	}

	/**
	 * Returns excluded URL patterns as a newline-separated string.
	 */
	public function get_excludes(): string {
		$excludes = (array) ( $this->get_cache_settings()['excludes'] ?? array() );
		return implode( PHP_EOL, $excludes );
	}

	/**
	 * Loads settings from the object cache, then the JSON file, with an in-process guard.
	 *
	 * @return array<string, mixed>
	 */
	public function get_cache_settings(): array {
		if ( ! empty( $this->cache_settings ) ) {
			return $this->cache_settings;
		}

		$cached = wp_cache_get( 'clp_varnish_settings', 'clp_varnish' );
		if ( false !== $cached ) {
			$this->cache_settings = (array) $cached;
			return $this->cache_settings;
		}

		if ( file_exists( $this->settings_file ) ) {
			$json           = (string) file_get_contents( $this->settings_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$cache_settings = json_decode( $json, associative: true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				error_log( sprintf( 'CLP Varnish Cache: failed to parse settings.json — %s', json_last_error_msg() ) );
			} elseif ( ! empty( $cache_settings ) ) {
				$this->cache_settings = $cache_settings;
				wp_cache_set( 'clp_varnish_settings', $cache_settings, 'clp_varnish' );
			}
		}

		return $this->cache_settings;
	}

	/**
	 * Serialises the given settings array to the JSON file and busts the object cache.
	 *
	 * @param array<string, mixed> $settings Settings to persist.
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function write_cache_settings( array $settings ): void {
		$result = file_put_contents( $this->settings_file, wp_json_encode( $settings, JSON_PRETTY_PRINT ) );
		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf( 'CLP Varnish Cache: failed to write settings to %s', $this->settings_file )
			);
		}
		wp_cache_delete( 'clp_varnish_settings', 'clp_varnish' );
	}

	/**
	 * Clears the in-process and object cache so the next read reloads from disk.
	 */
	public function reset_cache_settings(): void {
		$this->cache_settings = array();
		wp_cache_delete( 'clp_varnish_settings', 'clp_varnish' );
	}

	/**
	 * Validate settings before saving. Returns an array of translatable error strings (empty = valid).
	 *
	 * @param array<string, mixed> $settings Settings to validate.
	 * @return string[] Array of error messages; empty on success.
	 */
	public static function validate_settings( array $settings ): array {
		$errors = array();

		$server = $settings['server'] ?? '';
		if ( empty( $server ) ) {
			$errors[] = __( 'Varnish Server is required.', 'clp-varnish-cache' );
		} elseif ( preg_match( '/^\[([^\]]+)\](?::(\d{1,5}))?$/', $server, $m ) ) {
			// IPv6: [::1] or [::1]:6081.
			if ( ! filter_var( $m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				$errors[] = __( 'Varnish Server contains an invalid IPv6 address.', 'clp-varnish-cache' );
			} elseif ( isset( $m[2] ) && ( (int) $m[2] < 1 || (int) $m[2] > 65535 ) ) {
				$errors[] = __( 'Varnish Server port must be between 1 and 65535.', 'clp-varnish-cache' );
			}
		} elseif ( ! preg_match( '/^[a-zA-Z0-9.\-_]+(:\d{1,5})?$/', $server ) ) {
			$errors[] = __( 'Varnish Server must be in hostname:port, IP:port, or [IPv6]:port format (e.g. 127.0.0.1:6081).', 'clp-varnish-cache' );
		} elseif ( str_contains( $server, ':' ) ) {
			$port = (int) explode( ':', $server, 2 )[1];
			if ( $port < 1 || $port > 65535 ) {
				$errors[] = __( 'Varnish Server port must be between 1 and 65535.', 'clp-varnish-cache' );
			}
		}

		$lifetime = (string) ( $settings['cacheLifetime'] ?? '' );
		if ( '' === $lifetime ) {
			$errors[] = __( 'Cache Lifetime is required.', 'clp-varnish-cache' );
		} elseif ( ! ctype_digit( $lifetime ) || (int) $lifetime <= 0 ) {
			$errors[] = __( 'Cache Lifetime must be a positive integer (seconds).', 'clp-varnish-cache' );
		}

		$prefix = $settings['cacheTagPrefix'] ?? '';
		if ( empty( $prefix ) ) {
			$errors[] = __( 'Cache Tag Prefix is required.', 'clp-varnish-cache' );
		} elseif ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $prefix ) ) {
			$errors[] = __( 'Cache Tag Prefix may only contain letters, numbers, hyphens, and underscores.', 'clp-varnish-cache' );
		}

		return $errors;
	}

	/**
	 * Test connectivity to a Varnish server. Returns true on success or an error string.
	 *
	 * @param string $server Server address to test (e.g. 127.0.0.1:6081).
	 * @return true|string True on success; an error message string on failure.
	 */
	public function test_connection( string $server ): true|string {
		if ( empty( $server ) ) {
			return __( 'No server address configured.', 'clp-varnish-cache' );
		}

		$response = wp_remote_request(
			'http://' . preg_replace( '#^https?://#', '', $server ),
			array(
				'method'    => 'PURGE',
				'timeout'   => 3,
				'sslverify' => false,
				'headers'   => array( 'Host' => wp_parse_url( home_url(), PHP_URL_HOST ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return match ( true ) {
			200 === $code, 204 === $code => true,
			default                     => sprintf(
				/* translators: %d: HTTP status code returned by Varnish */
				__( 'Unexpected HTTP %d response from Varnish server.', 'clp-varnish-cache' ),
				$code
			),
		};
	}

	/**
	 * Purges all cached objects for a given hostname.
	 *
	 * @param string $host Hostname whose cached objects should be purged.
	 */
	public function purge_host( string $host ): void {
		$this->purge( array( 'Host' => $host ), null, PurgeType::Host, $host );
	}

	/**
	 * Purges objects matching a single cache tag.
	 *
	 * @param string $tag Cache tag value to purge.
	 */
	public function purge_tag( string $tag ): void {
		$this->purge_tags( array( $tag ) );
	}

	/**
	 * Purges objects matching any of the given cache tags in a single request.
	 *
	 * @param string[] $tags Cache tag values to purge.
	 */
	public function purge_tags( array $tags ): void {
		$target = implode( ',', $tags );
		$this->purge( array( 'X-Cache-Tags' => $target ), null, PurgeType::Tag, $target );
	}

	/**
	 * Purges objects matching both a hostname and a cache tag.
	 *
	 * @param string $host Hostname filter.
	 * @param string $tag  Cache tag filter.
	 */
	public function purge_host_and_tag( string $host, string $tag ): void {
		$this->purge(
			array(
				'Host'         => $host,
				'X-Cache-Tags' => $tag,
			),
			null,
			PurgeType::HostTag,
			"$host / $tag"
		);
	}

	/**
	 * Sends X-Purge-All: true to trigger a VCL wildcard ban covering all cached objects.
	 */
	public function purge_everything(): void {
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$headers = array( 'X-Purge-All' => 'true' );
		if ( ! empty( $host ) ) {
			$headers['Host'] = $host;
		}
		$this->purge( $headers, null, PurgeType::All, '*' );
	}

	/**
	 * Purges a specific URL from the cache.
	 *
	 * @param string $url Fully-qualified URL to purge.
	 * @throws \InvalidArgumentException When the URL is invalid or belongs to a different host.
	 */
	public function purge_url( string $url ): void {
		$parsed = wp_parse_url( $url );

		if ( ! isset( $parsed['host'] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Not a valid URL: %s', $url )
			);
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $parsed['host'] !== $site_host ) {
			throw new \InvalidArgumentException(
				sprintf( 'URL host "%s" does not match site host "%s"', $parsed['host'], $site_host )
			);
		}

		$request_url = $this->get_server();
		if ( isset( $parsed['path'] ) ) {
			$request_url = sprintf( '%s/%s', $request_url, '/' === $parsed['path'] ? '' : ltrim( $parsed['path'], '/' ) );
		}
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query_params );
			if ( ! empty( $query_params ) ) {
				$request_url = sprintf( '%s?%s', $request_url, http_build_query( $query_params ) );
			}
		}

		$this->purge( array( 'Host' => $parsed['host'] ), $request_url, PurgeType::Url, $url );
	}

	/**
	 * Dispatches a PURGE HTTP request to the Varnish server and logs the result.
	 *
	 * @param array<string, string> $headers    Headers to send with the PURGE request.
	 * @param string|null           $request_url Override URL; defaults to the configured server.
	 * @param PurgeType             $type        Purge type for log classification.
	 * @param string                $target      Human-readable purge target for the log.
	 * @throws \RuntimeException When the HTTP request fails or returns a non-200 status.
	 */
	private function purge( array $headers, ?string $request_url, PurgeType $type, string $target ): void {
		$url      = 'http://' . preg_replace( '#^https?://#', '', $request_url ?? $this->get_server() );
		$response = wp_remote_request(
			$url,
			array(
				'method'    => 'PURGE',
				'timeout'   => 2,
				'sslverify' => false,
				'headers'   => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			ClpVarnishCacheLogger::log( $type, $target, false, $message );
			throw new \RuntimeException(
				sprintf( 'Varnish purge request failed: %s', $message )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$message = sprintf( 'HTTP %d', $code );
			ClpVarnishCacheLogger::log( $type, $target, false, $message );
			throw new \RuntimeException(
				sprintf( 'Varnish purge returned HTTP %d', $code )
			);
		}

		ClpVarnishCacheLogger::log( $type, $target, true );
	}
}
