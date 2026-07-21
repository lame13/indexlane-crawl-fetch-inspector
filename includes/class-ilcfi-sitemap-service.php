<?php
/**
 * Bounded sitemap discovery, parsing, sampling, and membership evidence.
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes sitemap indexes incrementally so work can resume between requests.
 */
final class ILCFI_Sitemap_Service {
	const MAX_FILES            = 25;
	const MAX_MEMBERSHIP_URLS  = 25000;
	const MAX_CANDIDATES_FILE  = 50;

	/** @var ILCFI_Redirect_Follower */
	private $redirects;

	/** @var ILCFI_URL_Helper */
	private $urls;

	/**
	 * @param ILCFI_Redirect_Follower $redirects Redirect engine.
	 * @param ILCFI_URL_Helper        $urls URL helper.
	 */
	public function __construct( ILCFI_Redirect_Follower $redirects, ILCFI_URL_Helper $urls ) {
		$this->redirects = $redirects;
		$this->urls      = $urls;
	}

	/**
	 * Create serializable discovery state.
	 *
	 * @param string $sitemap_url Sitemap URL, or blank to skip membership.
	 * @param int    $sample_limit Target count.
	 * @return array|WP_Error
	 */
	public function create_state( $sitemap_url, $sample_limit ) {
		$sitemap_url = trim( (string) $sitemap_url );

		if ( '' === $sitemap_url ) {
			return array(
				'checked'        => false,
				'status'         => 'ready',
				'queue'          => array(),
				'visited'        => array(),
				'urls'           => array(),
				'sample_sources' => array(),
				'sample_targets' => array(),
				'sample_limit'   => max( 1, min( 50, (int) $sample_limit ) ),
				'files_checked'  => 0,
				'files_failed'   => array(),
				'truncated'      => array(),
				'omitted_files'  => 0,
				'omitted_urls'   => 0,
				'completeness'   => 'not_checked',
			);
		}

		$prepared = $this->urls->prepare_same_site_url( $sitemap_url );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		return array(
			'checked'        => true,
			'status'         => 'discovering',
			'queue'          => array( $prepared ),
			'visited'        => array(),
			'urls'           => array(),
			'sample_sources' => array(),
			'sample_targets' => array(),
			'sample_limit'   => max( 1, min( 50, (int) $sample_limit ) ),
			'files_checked'  => 0,
			'files_failed'   => array(),
			'truncated'      => array(),
			'omitted_files'  => 0,
			'omitted_urls'   => 0,
			'completeness'   => 'complete',
		);
	}

	/**
	 * Fetch a bounded number of sitemap files and update state.
	 *
	 * @param array $state State.
	 * @param int   $batch_size Files per request.
	 * @return array
	 */
	public function process_batch( array $state, $batch_size = 3 ) {
		if ( 'discovering' !== $state['status'] ) {
			return $state;
		}

		$processed = 0;
		while ( ! empty( $state['queue'] ) && $processed < max( 1, (int) $batch_size ) && $state['files_checked'] < self::MAX_FILES ) {
			$current = array_shift( $state['queue'] );
			$key     = $this->urls->normalize_for_compare( $current );

			if ( isset( $state['visited'][ $key ] ) ) {
				continue;
			}

			$state['visited'][ $key ] = true;
			$state['files_checked']++;
			$processed++;
			$fetch = $this->redirects->fetch( $current, 'application/xml,text/xml,*/*;q=0.1' );

			if ( is_wp_error( $fetch ) ) {
				$state['files_failed'][] = $current . ': ' . $fetch->get_error_message();
				$state['completeness']   = 'partial';
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $fetch['response'] );
			if ( $status < 200 || $status >= 300 || ! empty( $fetch['redirect_left_site'] ) || ! empty( $fetch['redirect_loop'] ) || ! empty( $fetch['redirect_limit_reached'] ) ) {
				$state['files_failed'][] = $current . ' (HTTP ' . $status . ')';
				$state['completeness']   = 'partial';
				continue;
			}

			if ( ! empty( $fetch['body_truncated'] ) ) {
				$state['truncated'][]  = $current;
				$state['completeness'] = 'partial';
				continue;
			}

			$parsed = $this->parse( (string) wp_remote_retrieve_body( $fetch['response'] ) );
			if ( 'invalid' === $parsed['type'] ) {
				$state['files_failed'][] = $current . ' (invalid sitemap XML)';
				$state['completeness']   = 'partial';
				continue;
			}

			if ( 'sitemapindex' === $parsed['type'] ) {
				foreach ( $parsed['locs'] as $child ) {
					$prepared = $this->urls->prepare_same_site_url( $child );
					if ( is_wp_error( $prepared ) ) {
						$state['files_failed'][] = $child . ' (invalid or off-site child sitemap)';
						$state['completeness']   = 'partial';
						continue;
					}

					$child_key = $this->urls->normalize_for_compare( $prepared );
					if ( isset( $state['visited'][ $child_key ] ) || $this->queue_contains( $state['queue'], $child_key ) ) {
						continue;
					}

					if ( $state['files_checked'] + count( $state['queue'] ) >= self::MAX_FILES ) {
						$state['omitted_files']++;
						$state['completeness'] = 'partial';
						continue;
					}

					$state['queue'][] = $prepared;
				}
				continue;
			}

			$valid_urls = array();
			foreach ( $parsed['locs'] as $loc ) {
				$prepared = $this->urls->prepare_same_site_url( $loc );
				if ( is_wp_error( $prepared ) ) {
					continue;
				}

				$valid_urls[] = $prepared;
				$key          = $this->urls->normalize_for_compare( $prepared );
				if ( count( $state['urls'] ) < self::MAX_MEMBERSHIP_URLS ) {
					$state['urls'][ $key ] = true;
				} elseif ( ! isset( $state['urls'][ $key ] ) ) {
					$state['omitted_urls']++;
					$state['completeness'] = 'partial';
				}
			}

			if ( ! empty( $valid_urls ) ) {
				$state['sample_sources'][] = $this->even_sample( $valid_urls, min( self::MAX_CANDIDATES_FILE, $state['sample_limit'] ) );
			}
		}

		if ( $state['files_checked'] >= self::MAX_FILES && ! empty( $state['queue'] ) ) {
			$state['omitted_files'] += count( $state['queue'] );
			$state['queue']          = array();
			$state['completeness']   = 'partial';
		}

		if ( empty( $state['queue'] ) ) {
			$state['status']         = 'ready';
			$state['sample_targets'] = $this->round_robin_samples( $state['sample_sources'], $state['sample_limit'] );
		}

		return $state;
	}

	/**
	 * Parse a urlset or sitemap index into loc values.
	 *
	 * @param string $body XML.
	 * @return array
	 */
	public function parse( $body ) {
		if ( function_exists( 'simplexml_load_string' ) ) {
			$previous = libxml_use_internal_errors( true );
			$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( false !== $xml ) {
				$type = strtolower( $xml->getName() );
				if ( in_array( $type, array( 'urlset', 'sitemapindex' ), true ) ) {
					$locs  = array();
					$nodes = $xml->xpath( '//*[local-name()="loc"]' );
					if ( is_array( $nodes ) ) {
						foreach ( $nodes as $node ) {
							$loc = trim( (string) $node );
							if ( '' !== $loc ) {
								$locs[] = $loc;
							}
						}
					}

					return array( 'type' => $type, 'locs' => $locs );
				}
			}
		}

		$root_type = '';
		if ( preg_match( '#^\s*(?:<\?xml[^>]*>\s*)?(?:<!--.*?-->\s*)*<\s*(?:[a-z0-9_-]+:)?(urlset|sitemapindex)\b#is', $body, $root_match ) ) {
			$root_type = strtolower( $root_match[1] );
		}

		if ( '' !== $root_type ) {
			$locs = array();
			if ( preg_match_all( '#<(?:[a-z0-9_-]+:)?loc\b[^>]*>\s*(.*?)\s*</(?:[a-z0-9_-]+:)?loc>#is', $body, $matches ) ) {
				foreach ( $matches[1] as $loc ) {
					$loc = html_entity_decode( trim( wp_strip_all_tags( $loc ) ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
					if ( '' !== $loc ) {
						$locs[] = $loc;
					}
				}
			}

			return array( 'type' => $root_type, 'locs' => $locs );
		}

		return array( 'type' => 'invalid', 'locs' => array() );
	}

	/**
	 * Membership result with conservative incomplete state.
	 *
	 * @param string $url URL.
	 * @param array  $state Sitemap state.
	 * @return string
	 */
	public function membership( $url, array $state ) {
		if ( empty( $state['checked'] ) ) {
			return 'Not checked';
		}

		$key = $this->urls->normalize_for_compare( $url );
		if ( isset( $state['urls'][ $key ] ) ) {
			return 'Yes';
		}

		return 'complete' === $state['completeness'] && 'ready' === $state['status'] ? 'No' : 'Unknown—incomplete';
	}

	/**
	 * Spread a sample through a single child sitemap rather than taking its prefix.
	 *
	 * @param array $urls URLs.
	 * @param int   $limit Limit.
	 * @return array
	 */
	private function even_sample( array $urls, $limit ) {
		$count = count( $urls );
		$limit = min( max( 1, (int) $limit ), $count );

		if ( $limit >= $count ) {
			return array_values( $urls );
		}

		$sample = array();
		$step   = $count / $limit;
		for ( $index = 0; $index < $limit; $index++ ) {
			$sample[] = $urls[ min( $count - 1, (int) floor( $index * $step ) ) ];
		}

		return array_values( array_unique( $sample ) );
	}

	/**
	 * Round-robin across child sitemap samples.
	 *
	 * @param array $sources Per-file samples.
	 * @param int   $limit Limit.
	 * @return array
	 */
	private function round_robin_samples( array $sources, $limit ) {
		$output = array();
		$seen   = array();
		$offset = 0;
		$limit  = max( 1, (int) $limit );
		$sources = array_values( array_filter( $sources ) );

		if ( count( $sources ) > $limit ) {
			$distributed_sources = array();
			$step                = count( $sources ) / $limit;
			for ( $index = 0; $index < $limit; $index++ ) {
				$distributed_sources[] = $sources[ min( count( $sources ) - 1, (int) floor( $index * $step ) ) ];
			}
			$sources = $distributed_sources;
		}

		while ( count( $output ) < $limit ) {
			$added = false;
			foreach ( $sources as $source ) {
				if ( ! isset( $source[ $offset ] ) ) {
					continue;
				}

				$key = $this->urls->normalize_for_compare( $source[ $offset ] );
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$output[]     = $source[ $offset ];
					$added        = true;
				}

				if ( count( $output ) >= $limit ) {
					break;
				}
			}

			if ( ! $added ) {
				break;
			}

			$offset++;
		}

		return $output;
	}

	/** @param array $queue Pending URLs. @param string $key Normalized key. @return bool */
	private function queue_contains( array $queue, $key ) {
		foreach ( $queue as $queued_url ) {
			if ( $this->urls->normalize_for_compare( $queued_url ) === $key ) {
				return true;
			}
		}

		return false;
	}
}
