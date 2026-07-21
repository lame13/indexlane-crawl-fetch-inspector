<?php
/**
 * Crawl evidence report assembly.
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates shared fetch, parsing, robots, sitemap, and evaluation services.
 */
final class ILCFI_Report_Builder {
	/** @var ILCFI_Redirect_Follower */
	private $redirects;

	/** @var ILCFI_HTML_Parser */
	private $parser;

	/** @var ILCFI_Robots_Evaluator */
	private $robots;

	/** @var ILCFI_Sitemap_Service */
	private $sitemaps;

	/** @var ILCFI_Evidence_Evaluator */
	private $evaluator;

	/** @var ILCFI_Fetch_Client */
	private $client;

	/** @var ILCFI_URL_Helper */
	private $urls;

	/** @var array */
	private $robots_cache = array();

	/**
	 * @param ILCFI_Redirect_Follower   $redirects Redirect engine.
	 * @param ILCFI_HTML_Parser         $parser Parser.
	 * @param ILCFI_Robots_Evaluator    $robots Robots evaluator.
	 * @param ILCFI_Sitemap_Service     $sitemaps Sitemap service.
	 * @param ILCFI_Evidence_Evaluator  $evaluator Evidence evaluator.
	 * @param ILCFI_Fetch_Client        $client Fetch client.
	 * @param ILCFI_URL_Helper          $urls URL helper.
	 */
	public function __construct( ILCFI_Redirect_Follower $redirects, ILCFI_HTML_Parser $parser, ILCFI_Robots_Evaluator $robots, ILCFI_Sitemap_Service $sitemaps, ILCFI_Evidence_Evaluator $evaluator, ILCFI_Fetch_Client $client, ILCFI_URL_Helper $urls ) {
		$this->redirects = $redirects;
		$this->parser    = $parser;
		$this->robots    = $robots;
		$this->sitemaps  = $sitemaps;
		$this->evaluator = $evaluator;
		$this->client    = $client;
		$this->urls      = $urls;
	}

	/**
	 * Fetch a reusable robots context for a page origin.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	public function get_robots_context( $url ) {
		$origin = $this->urls->origin( $url );
		if ( ! isset( $this->robots_cache[ $origin ] ) ) {
			$this->robots_cache[ $origin ] = $this->robots->get_context( $url );
		}

		return $this->robots_cache[ $origin ];
	}

	/**
	 * Inspect one URL.
	 *
	 * @param string $input_url Original input.
	 * @param string $url Fetch URL.
	 * @param array  $options Report options.
	 * @return array
	 */
	public function inspect( $input_url, $url, array $options = array() ) {
		$fetch = $this->redirects->fetch( $url );
		if ( is_wp_error( $fetch ) ) {
			return $this->error_row( $input_url, $fetch->get_error_message(), $url );
		}

		$response       = $fetch['response'];
		$status         = (int) wp_remote_retrieve_response_code( $response );
		$body           = (string) wp_remote_retrieve_body( $response );
		$final_url      = $fetch['final_url'];
		$x_robots       = $this->client->header_string( wp_remote_retrieve_header( $response, 'x-robots-tag' ) );
		$content_type   = strtolower( $this->client->header_string( wp_remote_retrieve_header( $response, 'content-type' ) ) );
		$is_html        = false !== strpos( $content_type, 'text/html' ) || false !== strpos( $content_type, 'application/xhtml+xml' ) || '' === $content_type;
		$html_complete  = $is_html && empty( $fetch['redirect_left_site'] ) && empty( $fetch['redirect_loop'] ) && empty( $fetch['redirect_limit_reached'] ) && empty( $fetch['body_truncated'] ) && ! ( $status >= 300 && $status < 400 );
		$old_domains    = isset( $options['old_domains'] ) && is_array( $options['old_domains'] ) ? $options['old_domains'] : array();
		$analysis       = $html_complete ? $this->parser->analyze( $body, $final_url, $old_domains ) : $this->parser->empty_analysis();
		$analysis['canonical_differs'] = $html_complete && '' !== $analysis['canonical_url'] && $this->urls->normalize_for_compare( $analysis['canonical_url'] ) !== $this->urls->normalize_for_compare( $final_url );
		$robots_context = isset( $options['robots_context'] ) && is_array( $options['robots_context'] ) ? $options['robots_context'] : $this->get_robots_context( $url );
		$robots_result  = $this->robots->evaluate_googlebot( $url, $robots_context );
		$sitemap_state  = isset( $options['sitemap_state'] ) && is_array( $options['sitemap_state'] ) ? $options['sitemap_state'] : array( 'checked' => false );
		$membership     = $this->sitemaps->membership( $final_url, $sitemap_state );
		$sitemap_complete = empty( $sitemap_state['checked'] ) || ( isset( $sitemap_state['completeness'] ) && 'complete' === $sitemap_state['completeness'] );
		$evaluation       = $this->evaluator->evaluate( $status, $fetch, $analysis, $robots_result, $membership, ! empty( $sitemap_state['checked'] ), $sitemap_complete, $x_robots, $is_html );

		$title_state       = $analysis['title_present'] ? __( 'Present', 'indexlane-crawl-fetch-inspector' ) : __( 'Missing', 'indexlane-crawl-fetch-inspector' );
		$description_state = $analysis['meta_description_present'] ? __( 'Present', 'indexlane-crawl-fetch-inspector' ) : __( 'Missing', 'indexlane-crawl-fetch-inspector' );
		$json_count        = (string) $analysis['json_ld_count'];
		$residue_summary   = $this->residue_summary( $analysis['residue_evidence'] );
		$residue_details   = $this->residue_details( $analysis['residue_evidence'] );
		$canonical_state   = $analysis['canonical_url'];
		$meta_state        = $analysis['meta_robots'];
		$effective_meta    = $analysis['effective_meta_robots'];
		$directives_state  = $this->robots_directives_summary( $analysis['meta_robots'], $x_robots );

		if ( ! $html_complete ) {
			$title_state       = __( 'Not checked', 'indexlane-crawl-fetch-inspector' );
			$description_state = __( 'Not checked', 'indexlane-crawl-fetch-inspector' );
			$json_count        = __( 'Not checked', 'indexlane-crawl-fetch-inspector' );
			$residue_summary   = __( 'Not checked', 'indexlane-crawl-fetch-inspector' );
			$residue_details   = __( 'Not checked', 'indexlane-crawl-fetch-inspector' );
			$canonical_state   = __( 'Unknown—not checked', 'indexlane-crawl-fetch-inspector' );
			$meta_state        = __( 'Unknown—not checked', 'indexlane-crawl-fetch-inspector' );
			$effective_meta    = __( 'Unknown—not checked', 'indexlane-crawl-fetch-inspector' );
			$directives_state  = __( 'HTML: Unknown—not checked', 'indexlane-crawl-fetch-inspector' );
			if ( '' !== $x_robots ) {
				$directives_state .= '; HTTP: ' . $x_robots;
			}
		}

		return array(
			'input_url'             => $input_url,
			'http_status'           => $status ? (string) $status : '',
			'redirects'             => (string) $fetch['redirect_count'],
			'redirect_chain'        => $this->format_redirect_chain( $fetch['redirect_chain'], $final_url ),
			'final_url'             => $final_url,
			'canonical_url'         => $canonical_state,
			'meta_robots'           => $meta_state,
			'effective_meta_robots' => $effective_meta,
			'x_robots_tag'          => $x_robots,
			'robots_directives'     => $directives_state,
			'robots_txt'            => $this->robots_summary( $robots_result ),
			'sitemap_membership'    => $membership,
			'json_ld_count'         => $json_count,
			'json_ld_validity'      => $html_complete ? $this->json_validity( $analysis ) : __( 'Unknown—not checked', 'indexlane-crawl-fetch-inspector' ),
			'json_ld_types'         => $html_complete && ! empty( $analysis['json_ld_types'] ) ? implode( ', ', $analysis['json_ld_types'] ) : ( $html_complete ? __( 'None found', 'indexlane-crawl-fetch-inspector' ) : __( 'Unknown—not checked', 'indexlane-crawl-fetch-inspector' ) ),
			'duplicate_json_ld_ids' => $html_complete && ! empty( $analysis['duplicate_json_ld_ids'] ) ? implode( ', ', $analysis['duplicate_json_ld_ids'] ) : ( $html_complete ? __( 'None found', 'indexlane-crawl-fetch-inspector' ) : __( 'Unknown—not checked', 'indexlane-crawl-fetch-inspector' ) ),
			'old_domain_evidence'   => $residue_summary,
			'old_domain_evidence_details' => $residue_details,
			'residue_evidence'      => $analysis['residue_evidence'],
			'evidence_completeness' => $evaluation['completeness'],
			'completeness_detail'   => $evaluation['completeness_note'],
			'title'                 => $title_state,
			'meta_description'      => $description_state,
			'dev_residue'           => $residue_summary,
			'response_time'         => sprintf(
				/* translators: %d is response time in milliseconds. */
				__( '%d ms', 'indexlane-crawl-fetch-inspector' ),
				(int) round( $fetch['response_time'] * 1000 )
			),
			'result'                => $evaluation['label'],
			'detail'                => $evaluation['detail'],
		);
	}

	/**
	 * Complete report row for invalid input or fetch failure.
	 *
	 * @param string $input_url Input.
	 * @param string $message Error.
	 * @param string $final_url URL.
	 * @return array
	 */
	public function error_row( $input_url, $message, $final_url = '' ) {
		return array(
			'input_url'             => $input_url,
			'http_status'           => '',
			'redirects'             => '',
			'redirect_chain'        => '',
			'final_url'             => $final_url,
			'canonical_url'         => '',
			'meta_robots'           => 'Unknown—not checked',
			'effective_meta_robots' => 'Unknown—not checked',
			'x_robots_tag'          => 'Unknown—not checked',
			'robots_directives'     => 'Unknown—not checked',
			'robots_txt'            => 'Unknown',
			'sitemap_membership'    => 'Unknown—incomplete',
			'json_ld_count'         => 'Not checked',
			'json_ld_validity'      => 'Unknown—not checked',
			'json_ld_types'         => 'Unknown—not checked',
			'duplicate_json_ld_ids' => 'Unknown—not checked',
			'old_domain_evidence'   => 'Not checked',
			'old_domain_evidence_details' => 'Not checked',
			'residue_evidence'      => array(),
			'evidence_completeness' => 'Failed',
			'completeness_detail'   => $message,
			'title'                 => 'Not checked',
			'meta_description'      => 'Not checked',
			'dev_residue'           => 'Not checked',
			'response_time'         => '',
			'result'                => 'Unknown',
			'detail'                => $message,
		);
	}

	/** @param array $chain Redirect chain. @param string $final_url Final URL. @return string */
	private function format_redirect_chain( array $chain, $final_url ) {
		$parts = array();
		foreach ( $chain as $hop ) {
			$parts[] = $hop['url'] . ' [' . $hop['status'] . ']';
		}

		$last = end( $chain );
		if ( is_array( $last ) && $last['url'] !== $final_url ) {
			$parts[] = $final_url . ' [not fetched]';
		}

		return implode( ' → ', $parts );
	}

	/** @param array $result Robots result. @return string */
	private function robots_summary( array $result ) {
		$summary = $result['state'];
		if ( ! empty( $result['rule'] ) ) {
			$summary .= ' — ' . $result['rule'];
		}
		if ( ! empty( $result['agent'] ) ) {
			$summary .= ' (group: ' . $result['agent'] . ')';
		}

		return $summary;
	}

	/** @param string $meta Meta robots. @param string $header Header robots. @return string */
	private function robots_directives_summary( $meta, $header ) {
		$parts = array();
		if ( '' !== $meta ) {
			$parts[] = 'HTML: ' . $meta;
		}
		if ( '' !== $header ) {
			$parts[] = 'HTTP: ' . $header;
		}

		return empty( $parts ) ? __( 'None found', 'indexlane-crawl-fetch-inspector' ) : implode( '; ', $parts );
	}

	/** @param array $analysis Parsed evidence. @return string */
	private function json_validity( array $analysis ) {
		if ( 0 === (int) $analysis['json_ld_count'] ) {
			return __( 'No JSON-LD blocks found', 'indexlane-crawl-fetch-inspector' );
		}

		if ( 0 === (int) $analysis['json_ld_malformed'] ) {
			return __( 'Valid', 'indexlane-crawl-fetch-inspector' );
		}

		return sprintf(
			/* translators: 1: malformed count, 2: total JSON-LD block count. */
			__( '%1$d malformed of %2$d blocks', 'indexlane-crawl-fetch-inspector' ),
			(int) $analysis['json_ld_malformed'],
			(int) $analysis['json_ld_count']
		);
	}

	/** @param array $evidence Evidence rows. @return string */
	private function residue_summary( array $evidence ) {
		if ( empty( $evidence ) ) {
			return __( 'Not found', 'indexlane-crawl-fetch-inspector' );
		}

		$parts = array();
		foreach ( array_slice( $evidence, 0, 3 ) as $item ) {
			$parts[] = $item['matched_value'] . ' (' . $item['context'] . ')';
		}

		$summary = sprintf(
			/* translators: %d is an evidence count. */
			_n( '%d match: ', '%d matches: ', count( $evidence ), 'indexlane-crawl-fetch-inspector' ),
			count( $evidence )
		) . implode( '; ', $parts );

		if ( count( $evidence ) > 3 ) {
			$summary .= sprintf( __( '; plus %d more', 'indexlane-crawl-fetch-inspector' ), count( $evidence ) - 3 );
		}

		return $summary;
	}

	/** @param array $evidence Evidence rows. @return string */
	private function residue_details( array $evidence ) {
		$details = array();
		foreach ( $evidence as $item ) {
			$details[] = implode(
				' | ',
				array(
					$item['reason'],
					$item['context'],
					$item['matched_value'],
					$item['snippet'],
				)
			);
		}

		return implode( "\n", $details );
	}
}
