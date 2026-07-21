<?php
/**
 * Scan target collection.
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects manual/recent targets and sanitizes sitemap/launch inputs.
 */
final class ILCFI_Target_Collector {
	/** @var ILCFI_URL_Helper */
	private $urls;

	/** @var int */
	private $max_urls;

	/**
	 * @param ILCFI_URL_Helper $urls URL helper.
	 * @param int              $max_urls Maximum scan targets.
	 */
	public function __construct( ILCFI_URL_Helper $urls, $max_urls ) {
		$this->urls     = $urls;
		$this->max_urls = (int) $max_urls;
	}

	/**
	 * Default form and job request.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'mode'            => 'manual',
			'raw_urls'        => '',
			'post_types'      => array(),
			'recent_limit'    => 10,
			'sitemap_url'     => home_url( '/wp-sitemap.xml' ),
			'sample_limit'    => 20,
			'raw_old_domains' => '',
			'old_domains'     => array(),
			'items'           => array(),
			'messages'        => array(),
		);
	}

	/**
	 * Build a request from POST-like input.
	 *
	 * @param array $source Input.
	 * @return array
	 */
	public function from_array( array $source ) {
		$request = $this->defaults();
		$mode    = $this->scalar( $source, 'ilcfi_mode' );
		$mode    = sanitize_key( $mode );

		if ( in_array( $mode, array( 'manual', 'sitemap', 'launch' ), true ) ) {
			$request['mode'] = $mode;
		}

		$request['raw_urls']        = sanitize_textarea_field( $this->scalar( $source, 'ilcfi_urls' ) );
		$request['recent_limit']    = min( 50, max( 1, absint( $this->scalar( $source, 'ilcfi_recent_limit', '10' ) ) ) );
		$request['sample_limit']    = min( $this->max_urls, max( 1, absint( $this->scalar( $source, 'ilcfi_sample_limit', '20' ) ) ) );
		$request['sitemap_url']     = trim( sanitize_text_field( $this->scalar( $source, 'ilcfi_sitemap_url', home_url( '/wp-sitemap.xml' ) ) ) );
		$request['raw_old_domains'] = sanitize_textarea_field( $this->scalar( $source, 'ilcfi_old_domains' ) );

		$available = $this->available_post_types();
		$selected  = isset( $source['ilcfi_post_types'] ) ? $source['ilcfi_post_types'] : array();
		$selected  = is_array( $selected ) ? $selected : array( $selected );

		foreach ( $selected as $post_type ) {
			if ( ! is_scalar( $post_type ) ) {
				continue;
			}

			$post_type = sanitize_key( wp_unslash( $post_type ) );
			if ( isset( $available[ $post_type ] ) ) {
				$request['post_types'][] = $post_type;
			}
		}

		$this->collect_old_domains( $request );
		$seen = array();

		if ( 'manual' === $request['mode'] ) {
			$this->add_manual_items( $request, $seen );
			$this->add_recent_items( $request, $seen );
			if ( empty( $request['items'] ) ) {
				$request['messages'][] = __( 'Add at least one URL or select recent content.', 'indexlane-crawl-fetch-inspector' );
			}
		} elseif ( 'launch' === $request['mode'] ) {
			$this->add_item(
				$request,
				$seen,
				array(
					'input_url' => home_url( '/' ),
					'url'       => esc_url_raw( home_url( '/' ) ),
					'source'    => 'home',
				)
			);
			$this->add_recent_items( $request, $seen );
		}

		if ( in_array( $request['mode'], array( 'sitemap', 'launch' ), true ) && '' === $request['sitemap_url'] ) {
			$request['messages'][] = __( 'A sitemap URL is required for this input mode.', 'indexlane-crawl-fetch-inspector' );
		}

		return $request;
	}

	/**
	 * Supported post type selectors.
	 *
	 * @return array
	 */
	public function available_post_types() {
		$post_types = array();

		foreach ( array( 'post', 'page', 'product' ) as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			$object = get_post_type_object( $post_type );
			$label  = $object && ! empty( $object->labels->name ) ? $object->labels->name : ucfirst( $post_type );
			$post_types[ $post_type ] = $label;
		}

		return $post_types;
	}

	/** @param array $source Source. @param string $key Key. @param string $default Default. @return string */
	private function scalar( array $source, $key, $default = '' ) {
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return $default;
		}

		return (string) wp_unslash( $source[ $key ] );
	}

	/** @param array $request Request. */
	private function collect_old_domains( array &$request ) {
		$entries = preg_split( '/[\r\n,]+/', $request['raw_old_domains'] );
		$seen    = array();

		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}

			$host = $this->urls->normalize_hostname( $entry );
			if ( '' === $host ) {
				$request['messages'][] = sprintf(
					/* translators: %s is invalid hostname input. */
					__( 'Ignored invalid old-domain value: %s', 'indexlane-crawl-fetch-inspector' ),
					$entry
				);
				continue;
			}

			if ( ! isset( $seen[ $host ] ) ) {
				$seen[ $host ]             = true;
				$request['old_domains'][] = $host;
			}
		}
	}

	/** @param array $request Request. @param array $seen Seen URLs. */
	private function add_manual_items( array &$request, array &$seen ) {
		$lines = preg_split( '/\R+/', $request['raw_urls'] );

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( count( $request['items'] ) >= $this->max_urls ) {
				$request['messages'][] = sprintf( __( 'Only the first %d URLs were included.', 'indexlane-crawl-fetch-inspector' ), $this->max_urls );
				break;
			}

			$prepared = $this->urls->prepare_same_site_url( $line );
			if ( is_wp_error( $prepared ) ) {
				$request['items'][] = array( 'input_url' => $line, 'error' => $prepared->get_error_message() );
				continue;
			}

			$this->add_item( $request, $seen, array( 'input_url' => $line, 'url' => $prepared, 'source' => 'manual' ) );
		}
	}

	/** @param array $request Request. @param array $seen Seen URLs. */
	private function add_recent_items( array &$request, array &$seen ) {
		foreach ( $request['post_types'] as $post_type ) {
			$post_ids = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => $request['recent_limit'],
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $post_ids as $post_id ) {
				if ( count( $request['items'] ) >= $this->max_urls ) {
					break 2;
				}

				$permalink = get_permalink( $post_id );
				if ( $permalink ) {
					$this->add_item( $request, $seen, array( 'input_url' => $permalink, 'url' => esc_url_raw( $permalink ), 'source' => $post_type ) );
				}
			}
		}
	}

	/** @param array $request Request. @param array $seen Seen URLs. @param array $item Item. */
	private function add_item( array &$request, array &$seen, array $item ) {
		if ( empty( $item['url'] ) ) {
			$request['items'][] = $item;
			return;
		}

		$key = $this->urls->normalize_for_compare( $item['url'] );
		if ( isset( $seen[ $key ] ) ) {
			return;
		}

		$seen[ $key ]       = true;
		$request['items'][] = $item;
	}
}
