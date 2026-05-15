<?php
/**
 * Automatic cache purge triggers for posts, comments, plugins, themes, and WooCommerce.
 *
 * @package CLP_Varnish_Cache
 */

/**
 * Hooks into WordPress lifecycle events and dispatches Varnish purge requests automatically.
 */
class ClpVarnishCacheAutoPurge {

	/**
	 * Guard flag to ensure a full purge is dispatched at most once per request.
	 *
	 * @var bool
	 */
	private static bool $purged = false;

	/**
	 * Registers all auto-purge action hooks.
	 */
	public function __construct() {
		add_action( 'upgrader_process_complete', $this->auto_purge( ... ), 100, 2 );
		add_action( 'deactivated_plugin', $this->auto_purge( ... ), 100 );
		add_action( 'activated_plugin', $this->auto_purge( ... ), 100 );
		add_action( 'switch_theme', $this->auto_purge( ... ), 100 );

		add_action( 'save_post', $this->purge_post( ... ), 100 );
		add_action( 'delete_post', $this->purge_post( ... ), 100 );
		add_action( 'transition_comment_status', $this->purge_post_from_comment_transition( ... ), 100, 3 );

		add_action( 'woocommerce_product_set_stock', $this->purge_woo_product( ... ), 100 );
		add_action( 'woocommerce_variation_set_stock', $this->purge_woo_product( ... ), 100 );
		add_action( 'woocommerce_product_set_stock_status', $this->purge_woo_product_by_id( ... ), 100, 2 );
	}

	/**
	 * Purges the entire cache when a plugin, theme, or core update completes.
	 *
	 * @param mixed $upgrader_or_plugin Upgrader instance or plugin file (varies by hook).
	 * @param mixed $options            Hook-specific extra data.
	 */
	public function auto_purge( mixed $upgrader_or_plugin = null, mixed $options = null ): void {
		if ( 'upgrader_process_complete' === current_action() ) {
			if ( empty( $options['action'] ) || 'update' !== $options['action'] ) {
				return;
			}
			if ( empty( $options['type'] ) || ! in_array( $options['type'], array( 'plugin', 'theme', 'core' ), strict: true ) ) {
				return;
			}
		}

		if ( self::$purged ) {
			return;
		}

		$manager = new ClpVarnishCacheManager();
		if ( ! $manager->is_enabled() ) {
			return;
		}

		try {
			$manager->purge_everything();
			self::$purged = true;
		} catch ( \Exception $e ) {
			error_log( sprintf( 'CLP Varnish Cache: auto_purge failed — %s', $e->getMessage() ) );
		}
	}

	/**
	 * Purges the cache for a single post by tag and by all associated URLs.
	 *
	 * @param int $post_id Post ID to purge.
	 */
	public function purge_post( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		$manager = new ClpVarnishCacheManager();
		if ( ! $manager->is_enabled() ) {
			return;
		}

		// Tag-based purge for the post itself — matches VCL ban rules that use
		// X-Cache-Tags: {prefix}-post-{ID} (same format as the original CLP plugin).
		$prefix = $manager->get_cache_tag_prefix();
		if ( ! empty( $prefix ) ) {
			try {
				$manager->purge_tag( $prefix . '-post-' . $post_id );
			} catch ( \Exception $e ) {
				error_log( sprintf( 'CLP Varnish Cache: failed to purge tag for post %d — %s', $post_id, $e->getMessage() ) );
			}
		}

		// URL-based purges for surrounding pages (homepage, feed, taxonomy archives,
		// author page) that don't have per-post cache tags.
		$urls = apply_filters( 'clp_varnish_purge_urls', $this->get_purge_urls_for_post( $post_id ), $post_id, get_post( $post_id ) );

		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			try {
				$manager->purge_url( $url );
			} catch ( \Exception $e ) {
				error_log( sprintf( 'CLP Varnish Cache: failed to purge %s — %s', $url, $e->getMessage() ) );
			}
		}
	}

	/**
	 * Purges the parent post's cache when a comment transitions to or from approved.
	 *
	 * @param string      $new_status New comment status.
	 * @param string      $old_status Previous comment status.
	 * @param \WP_Comment $comment   Comment object.
	 */
	public function purge_post_from_comment_transition( string $new_status, string $old_status, \WP_Comment $comment ): void {
		if ( 'approved' !== $new_status && 'approved' !== $old_status ) {
			return;
		}
		$post_id = (int) $comment->comment_post_ID;
		if ( $post_id > 0 ) {
			$this->purge_post( $post_id );
		}
	}

	/**
	 * Purges a WooCommerce product by extracting its ID from the product object.
	 *
	 * @param mixed $product WC_Product instance.
	 */
	public function purge_woo_product( mixed $product ): void {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$this->purge_post( (int) $product->get_id() );
		}
	}

	/**
	 * Purges a WooCommerce product directly by its post ID.
	 *
	 * @param int    $product_id    Product post ID.
	 * @param string $_stock_status New stock status (unused; present to match hook signature).
	 */
	public function purge_woo_product_by_id( int $product_id, string $_stock_status ): void {
		$this->purge_post( $product_id );
	}

	/**
	 * Collects all URLs that should be purged when a specific post changes.
	 *
	 * @param int $post_id Post ID.
	 * @return string[] Array of fully-qualified URLs.
	 */
	private function get_purge_urls_for_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$urls = array_filter(
			array(
				get_permalink( $post_id ),
				home_url( '/' ),
				get_feed_link(),
			)
		);

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$link = get_term_link( $term, $taxonomy );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		if ( ! empty( $post->post_author ) ) {
			$author_url = get_author_posts_url( (int) $post->post_author );
			if ( $author_url ) {
				$urls[] = $author_url;
			}
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop_url = wc_get_page_permalink( 'shop' );
			if ( $shop_url ) {
				$urls[] = $shop_url;
			}
		}

		return $urls;
	}
}
