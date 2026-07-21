<?php
/**
 * Bounded same-site HTTP client.
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs one safe request. Redirect policy lives in ILCFI_Redirect_Follower.
 */
final class ILCFI_Fetch_Client {
	/** @var int */
	private $max_response_bytes;

	/** @var string */
	private $version;

	/**
	 * @param string $version Plugin version.
	 * @param int    $max_response_bytes Response body bound.
	 */
	public function __construct( $version, $max_response_bytes ) {
		$this->version            = (string) $version;
		$this->max_response_bytes = (int) $max_response_bytes;
	}

	/**
	 * Fetch one URL without automatic redirects.
	 *
	 * @param string $url URL.
	 * @param string $accept Accept header.
	 * @return array|WP_Error
	 */
	public function get( $url, $accept = 'text/html,application/xhtml+xml' ) {
		return wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => $this->max_response_bytes,
				'user-agent'          => 'IndexLane Crawl Fetch Inspector/' . $this->version . '; ' . home_url( '/' ),
				'headers'             => array( 'Accept' => $accept ),
			)
		);
	}

	/**
	 * Whether a response may have been truncated by the safety boundary.
	 *
	 * @param array $response WordPress HTTP response.
	 * @return bool
	 */
	public function was_truncated( array $response ) {
		$body_length = strlen( (string) wp_remote_retrieve_body( $response ) );

		if ( $body_length >= $this->max_response_bytes ) {
			return true;
		}

		$content_encoding = strtolower( $this->header_string( wp_remote_retrieve_header( $response, 'content-encoding' ) ) );
		if ( '' !== $content_encoding && 'identity' !== $content_encoding ) {
			return false;
		}

		$content_length = $this->header_string( wp_remote_retrieve_header( $response, 'content-length' ) );
		if ( ! preg_match( '/^\d+$/', $content_length ) ) {
			return false;
		}

		return (int) $content_length > $body_length;
	}

	/**
	 * Flatten a possibly multi-value header.
	 *
	 * @param mixed $header Header value.
	 * @return string
	 */
	public function header_string( $header ) {
		if ( is_array( $header ) ) {
			$header = implode( ', ', array_map( 'trim', $header ) );
		}

		return trim( (string) $header );
	}
}
