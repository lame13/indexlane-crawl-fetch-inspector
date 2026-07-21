<?php
/**
 * Explicit redirect handling.
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Follows bounded same-site redirect chains and records every observed hop.
 */
final class ILCFI_Redirect_Follower {
	/** @var ILCFI_Fetch_Client */
	private $client;

	/** @var ILCFI_URL_Helper */
	private $urls;

	/** @var int */
	private $max_redirects;

	/**
	 * @param ILCFI_Fetch_Client $client Fetch client.
	 * @param ILCFI_URL_Helper   $urls URL helper.
	 * @param int                $max_redirects Redirect limit.
	 */
	public function __construct( ILCFI_Fetch_Client $client, ILCFI_URL_Helper $urls, $max_redirects ) {
		$this->client        = $client;
		$this->urls          = $urls;
		$this->max_redirects = (int) $max_redirects;
	}

	/**
	 * Follow redirects while the destination remains on-site.
	 *
	 * @param string $url URL.
	 * @param string $accept Accept header.
	 * @return array|WP_Error
	 */
	public function fetch( $url, $accept = 'text/html,application/xhtml+xml' ) {
		$started                = microtime( true );
		$current_url            = $url;
		$redirect_count         = 0;
		$last_response          = null;
		$details                = array();
		$chain                  = array();
		$seen                   = array();
		$redirect_left_site     = false;
		$redirect_loop          = false;
		$redirect_limit_reached = false;

		for ( $step = 0; $step <= $this->max_redirects; $step++ ) {
			$seen[ $this->urls->normalize_for_compare( $current_url ) ] = true;
			$response = $this->client->get( $current_url, $accept );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$last_response = $response;
			$status        = (int) wp_remote_retrieve_response_code( $response );
			$location      = $this->client->header_string( wp_remote_retrieve_header( $response, 'location' ) );
			$chain[]       = array(
				'url'      => $current_url,
				'status'   => $status,
				'location' => $location,
			);

			if ( $status < 300 || $status >= 400 ) {
				break;
			}

			if ( '' === $location ) {
				$details[] = sprintf(
					/* translators: %d is an HTTP status code. */
					__( 'HTTP %d response did not provide a redirect target, so no final page was fetched.', 'indexlane-crawl-fetch-inspector' ),
					$status
				);
				break;
			}

			$target = $this->urls->make_absolute( $location, $current_url );
			if ( is_wp_error( $target ) ) {
				$details[] = $target->get_error_message();
				break;
			}

			$redirect_count++;

			if ( ! $this->urls->is_same_site( $target ) ) {
				$current_url        = $target;
				$redirect_left_site = true;
				break;
			}

			$target_key = $this->urls->normalize_for_compare( $target );
			if ( isset( $seen[ $target_key ] ) ) {
				$current_url   = $target;
				$redirect_loop = true;
				break;
			}

			if ( $redirect_count >= $this->max_redirects ) {
				$current_url            = $target;
				$redirect_limit_reached = true;
				break;
			}

			$current_url = $target;
		}

		if ( ! $last_response ) {
			return new WP_Error( 'ilcfi_no_response', __( 'No HTTP response was returned.', 'indexlane-crawl-fetch-inspector' ) );
		}

		return array(
			'response'               => $last_response,
			'final_url'              => $current_url,
			'redirect_count'         => $redirect_count,
			'redirect_chain'         => $chain,
			'response_time'          => microtime( true ) - $started,
			'details'                => $details,
			'redirect_left_site'     => $redirect_left_site,
			'redirect_loop'          => $redirect_loop,
			'redirect_limit_reached' => $redirect_limit_reached,
			'body_truncated'          => $this->client->was_truncated( $last_response ),
		);
	}
}
