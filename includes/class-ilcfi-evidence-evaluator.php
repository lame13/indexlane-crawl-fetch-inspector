<?php
/**
 * Evidence completeness and finding labels.
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies conservative labels after fetchers and parsers have gathered evidence.
 */
final class ILCFI_Evidence_Evaluator {
	/**
	 * Whether rendered/header directives include noindex or none.
	 *
	 * @param string $meta_robots Meta robots evidence.
	 * @param string $x_robots X-Robots-Tag evidence.
	 * @return bool
	 */
	public function has_noindex( $meta_robots, $x_robots ) {
		$value = strtolower( (string) $meta_robots );

		return (bool) preg_match( '/(^|[\s,;:])(?:noindex|none)($|[\s,;])/', $value ) || $this->x_robots_has_noindex_for_googlebot( $x_robots );
	}

	/**
	 * Classify one assembled row.
	 *
	 * @param int   $status HTTP status.
	 * @param array $fetch Fetch evidence.
	 * @param array $analysis Parsed evidence.
	 * @param array $robots Effective robots result.
	 * @param string $membership Sitemap membership state.
	 * @param bool  $sitemap_checked Whether membership was requested.
	 * @param bool  $sitemap_complete Whether requested sitemap evidence completed.
	 * @param string $x_robots X-Robots-Tag.
	 * @param bool  $is_html Whether the terminal response is HTML.
	 * @return array
	 */
	public function evaluate( $status, array $fetch, array $analysis, array $robots, $membership, $sitemap_checked, $sitemap_complete, $x_robots, $is_html ) {
		$review   = array();
		$warnings = array();
		$partial  = array();

		if ( ! empty( $fetch['details'] ) ) {
			$partial = array_merge( $partial, $fetch['details'] );
		}

		if ( ! empty( $fetch['redirect_loop'] ) ) {
			$partial[] = __( 'Redirect loop detected.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( ! empty( $fetch['redirect_limit_reached'] ) ) {
			$partial[] = __( 'The redirect safety limit was reached.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( ! empty( $fetch['redirect_left_site'] ) ) {
			$partial[] = __( 'Redirect leaves site; the external target was not fetched.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( ! empty( $fetch['body_truncated'] ) ) {
			$partial[] = __( 'Response body reached the 2 MB safety limit or ended before its declared length; HTML signals were not checked.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( ! $is_html ) {
			$partial[] = __( 'The terminal response did not look like HTML.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( $status >= 300 && $status < 400 ) {
			$partial[] = sprintf(
				/* translators: %d is an HTTP status code. */
				__( 'Crawler-facing fetch ended with HTTP %d instead of a final page response.', 'indexlane-crawl-fetch-inspector' ),
				$status
			);
		}

		if ( empty( $analysis['parser_complete'] ) ) {
			$partial = array_merge( $partial, isset( $analysis['parser_messages'] ) ? $analysis['parser_messages'] : array() );
		}

		if ( empty( $robots['complete'] ) ) {
			$partial[] = $robots['detail'];
		}

		if ( $sitemap_checked && ! $sitemap_complete ) {
			$partial[] = 'Unknown—incomplete' === $membership
				? __( 'Sitemap membership is unknown because sitemap evidence was incomplete.', 'indexlane-crawl-fetch-inspector' )
				: __( 'Sitemap membership was observed, but the overall sitemap evidence set was incomplete.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( $status >= 500 || in_array( $status, array( 404, 410 ), true ) ) {
			$review[] = sprintf(
				/* translators: %d is an HTTP status code. */
				__( 'Crawler-facing response returned HTTP %d.', 'indexlane-crawl-fetch-inspector' ),
				$status
			);
		}

		if ( in_array( $status, array( 401, 403, 407 ), true ) ) {
			$review[] = sprintf(
				/* translators: %d is an HTTP status code. */
				__( 'HTTP %d blocked access to the page response.', 'indexlane-crawl-fetch-inspector' ),
				$status
			);
		}

		if ( 'Crawl blocked' === $robots['state'] ) {
			$review[] = $robots['detail'];
		}

		if ( $this->has_noindex( isset( $analysis['effective_meta_robots'] ) ? $analysis['effective_meta_robots'] : '', $x_robots ) ) {
			$review[] = __( 'Robots directives contain noindex or none.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( ! empty( $analysis['canonical_differs'] ) ) {
			$review[] = __( 'The canonical URL differs from the final response URL.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( ! empty( $analysis['json_ld_malformed'] ) ) {
			$review[] = __( 'One or more JSON-LD blocks are malformed.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( ! empty( $analysis['duplicate_json_ld_ids'] ) ) {
			$review[] = __( 'Duplicate JSON-LD @id values were found.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( ! empty( $analysis['residue_evidence'] ) ) {
			$review[] = __( 'Old or staging-domain evidence was found in URL-bearing page fields.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( ! empty( $fetch['redirect_count'] ) ) {
			$warnings[] = $fetch['redirect_count'] > 1
				? __( 'The redirect chain has more than one hop.', 'indexlane-crawl-fetch-inspector' )
				: __( 'The input URL redirects before its final response.', 'indexlane-crawl-fetch-inspector' );
		}

		if ( $sitemap_checked && 'No' === $membership ) {
			$warnings[] = __( 'The final URL was not found in the complete bounded sitemap evidence.', 'indexlane-crawl-fetch-inspector' );
		}

		$response_time = isset( $fetch['response_time'] ) ? (int) round( $fetch['response_time'] * 1000 ) : 0;
		if ( $response_time > 3000 ) {
			$warnings[] = __( 'Response time was over 3000 ms.', 'indexlane-crawl-fetch-inspector' );
		}

		$completeness = empty( $partial ) ? 'Complete' : 'Partial';
		$detail       = implode( ' ', array_unique( array_merge( $review, $warnings, $partial ) ) );

		if ( 'Crawl blocked' === $robots['state'] ) {
			$label = 'Crawl blocked';
		} elseif ( $status >= 500 || in_array( $status, array( 404, 410 ), true ) ) {
			$label = 'Error';
		} elseif ( ! empty( $review ) || ! empty( $partial ) || $status >= 400 ) {
			$label = 'Needs review';
		} elseif ( ! empty( $warnings ) ) {
			$label = 'Warning';
		} else {
			$label = 'OK';
		}

		$labels = array(
			'Crawl blocked' => __( 'Crawl blocked', 'indexlane-crawl-fetch-inspector' ),
			'Error'         => __( 'Error', 'indexlane-crawl-fetch-inspector' ),
			'Needs review'  => __( 'Needs review', 'indexlane-crawl-fetch-inspector' ),
			'Warning'       => __( 'Warning', 'indexlane-crawl-fetch-inspector' ),
			'OK'            => __( 'OK', 'indexlane-crawl-fetch-inspector' ),
		);

		$completeness_labels = array(
			'Complete' => __( 'Complete', 'indexlane-crawl-fetch-inspector' ),
			'Partial'  => __( 'Partial', 'indexlane-crawl-fetch-inspector' ),
		);

		return array(
			'label'             => $labels[ $label ],
			'detail'            => $detail,
			'completeness'      => $completeness_labels[ $completeness ],
			'completeness_note' => implode( ' ', array_unique( $partial ) ),
		);
	}

	/**
	 * Evaluate generic or Googlebot-targeted X-Robots-Tag segments.
	 *
	 * @param string $header Header value.
	 * @return bool
	 */
	private function x_robots_has_noindex_for_googlebot( $header ) {
		$scope = '*';
		foreach ( explode( ',', strtolower( (string) $header ) ) as $segment ) {
			$segment = trim( $segment );
			if ( preg_match( '/^([a-z0-9_-]+)\s*:\s*(.*)$/', $segment, $matches ) ) {
				$scope   = $matches[1];
				$segment = $matches[2];
			}

			$scope_applies = '*' === $scope || false !== strpos( 'googlebot', $scope );
			if ( $scope_applies && preg_match( '/(^|[\s;])(?:noindex|none)($|[\s;])/', $segment ) ) {
				return true;
			}
		}

		return false;
	}
}
