<?php
/**
 * Googlebot robots.txt evaluation.
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches robots.txt through the shared redirect engine and applies group/rule precedence.
 */
final class ILCFI_Robots_Evaluator {
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
	 * Fetch and parse the origin's robots.txt.
	 *
	 * @param string $reference_url Page URL.
	 * @return array
	 */
	public function get_context( $reference_url ) {
		$origin     = $this->urls->origin( $reference_url );
		$robots_url = '' !== $origin ? $origin . '/robots.txt' : home_url( '/robots.txt' );
		$fetch      = $this->redirects->fetch( $robots_url, 'text/plain,*/*;q=0.1' );

		if ( is_wp_error( $fetch ) ) {
			return array(
				'status'  => 'unknown',
				'url'     => $robots_url,
				'groups'  => array(),
				'message' => sprintf(
					/* translators: %s is a fetch error. */
					__( 'robots.txt fetch failed: %s', 'indexlane-crawl-fetch-inspector' ),
					$fetch->get_error_message()
				),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $fetch['response'] );

		if ( 404 === $status || 410 === $status ) {
			return array(
				'status'  => 'not_found',
				'url'     => $robots_url,
				'groups'  => array(),
				'message' => __( 'No robots.txt file was found.', 'indexlane-crawl-fetch-inspector' ),
			);
		}

		if ( $status < 200 || $status >= 300 || ! empty( $fetch['body_truncated'] ) || ! empty( $fetch['redirect_left_site'] ) || ! empty( $fetch['redirect_loop'] ) || ! empty( $fetch['redirect_limit_reached'] ) ) {
			return array(
				'status'  => 'unknown',
				'url'     => $robots_url,
				'groups'  => array(),
				'message' => sprintf(
					/* translators: %d is an HTTP status code. */
					__( 'robots.txt evidence was incomplete (HTTP %d or an unfinished redirect/body).', 'indexlane-crawl-fetch-inspector' ),
					$status
				),
			);
		}

		return array(
			'status'  => 'ok',
			'url'     => $robots_url,
			'groups'  => $this->parse( (string) wp_remote_retrieve_body( $fetch['response'] ) ),
			'message' => '',
		);
	}

	/**
	 * Parse crawler groups while preserving repeated groups.
	 *
	 * @param string $robots_txt Body.
	 * @return array
	 */
	public function parse( $robots_txt ) {
		$lines          = preg_split( '/\r\n|\r|\n/', (string) $robots_txt );
		$groups         = array();
		$current_agents = array();
		$current_rules  = array();
		$has_rules      = false;

		if ( ! is_array( $lines ) ) {
			return array();
		}

		$flush = static function () use ( &$groups, &$current_agents, &$current_rules, &$has_rules ) {
			if ( ! empty( $current_agents ) ) {
				$groups[] = array(
					'agents' => array_values( array_unique( $current_agents ) ),
					'rules'  => $current_rules,
				);
			}

			$current_agents = array();
			$current_rules  = array();
			$has_rules      = false;
		};

		foreach ( $lines as $line ) {
			$line = trim( (string) preg_replace( '/#.*/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );

			if ( 'user-agent' === $field ) {
				if ( $has_rules ) {
					$flush();
				}

				if ( '' !== $value ) {
					$current_agents[] = strtolower( $value );
				}
				continue;
			}

			if ( in_array( $field, array( 'allow', 'disallow' ), true ) && ! empty( $current_agents ) ) {
				$current_rules[] = array(
					'type'    => $field,
					'pattern' => $value,
				);
				$has_rules = true;
			}
		}

		$flush();

		return $groups;
	}

	/**
	 * Evaluate a URL for the Googlebot product token.
	 *
	 * @param string $url Page URL.
	 * @param array  $context Parsed robots context.
	 * @return array
	 */
	public function evaluate_googlebot( $url, array $context ) {
		if ( 'unknown' === $context['status'] ) {
			return array(
				'state'   => 'Unknown',
				'agent'   => 'Googlebot',
				'rule'    => '',
				'detail'  => isset( $context['message'] ) ? $context['message'] : __( 'robots.txt evidence was unavailable.', 'indexlane-crawl-fetch-inspector' ),
				'complete'=> false,
			);
		}

		if ( 'not_found' === $context['status'] ) {
			return array(
				'state'    => 'Allowed',
				'agent'    => 'Googlebot',
				'rule'     => '',
				'detail'   => __( 'Allowed because no robots.txt file was found.', 'indexlane-crawl-fetch-inspector' ),
				'complete' => true,
			);
		}

		$selected    = array();
		$best_length = -1;
		$agent_label = '*';

		foreach ( $context['groups'] as $group ) {
			$group_length = -1;
			$group_agent  = '';

			foreach ( $group['agents'] as $agent ) {
				$length = $this->agent_match_length( $agent, 'googlebot' );
				if ( $length > $group_length ) {
					$group_length = $length;
					$group_agent  = $agent;
				}
			}

			if ( $group_length < 0 ) {
				continue;
			}

			if ( $group_length > $best_length ) {
				$best_length = $group_length;
				$selected    = $group['rules'];
				$agent_label = $group_agent;
			} elseif ( $group_length === $best_length ) {
				$selected = array_merge( $selected, $group['rules'] );
			}
		}

		$parts      = wp_parse_url( $url );
		$path_query = is_array( $parts ) && ! empty( $parts['path'] ) ? $parts['path'] : '/';
		if ( is_array( $parts ) && isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$path_query .= '?' . $parts['query'];
		}

		$best_rule        = null;
		$best_rule_length = -1;

		foreach ( $selected as $rule ) {
			if ( 'disallow' === $rule['type'] && '' === $rule['pattern'] ) {
				continue;
			}

			if ( ! $this->pattern_matches( $rule['pattern'], $path_query ) ) {
				continue;
			}

			$length = strlen( str_replace( array( '*', '$' ), '', $rule['pattern'] ) );
			if ( $length > $best_rule_length || ( $length === $best_rule_length && 'allow' === $rule['type'] ) ) {
				$best_rule        = $rule;
				$best_rule_length = $length;
			}
		}

		if ( null === $best_rule || 'allow' === $best_rule['type'] ) {
			return array(
				'state'    => 'Allowed',
				'agent'    => $agent_label,
				'rule'     => null === $best_rule ? '' : 'Allow: ' . $best_rule['pattern'],
				'detail'   => null === $best_rule ? __( 'No matching crawl block for Googlebot.', 'indexlane-crawl-fetch-inspector' ) : __( 'The longest matching rule allows Googlebot.', 'indexlane-crawl-fetch-inspector' ),
				'complete' => true,
			);
		}

		return array(
			'state'    => 'Crawl blocked',
			'agent'    => $agent_label,
			'rule'     => 'Disallow: ' . $best_rule['pattern'],
			'detail'   => __( 'The longest matching rule blocks Googlebot from crawling this URL.', 'indexlane-crawl-fetch-inspector' ),
			'complete' => true,
		);
	}

	/**
	 * Product token match specificity.
	 *
	 * @param string $agent Group product token.
	 * @param string $crawler Crawler token.
	 * @return int
	 */
	private function agent_match_length( $agent, $crawler ) {
		$agent = strtolower( trim( $agent ) );
		if ( '*' === $agent ) {
			return 0;
		}

		return false !== strpos( strtolower( $crawler ), $agent ) ? strlen( $agent ) : -1;
	}

	/**
	 * Match wildcard and optional end-anchored robots patterns.
	 *
	 * @param string $pattern Pattern.
	 * @param string $path_query URL path/query.
	 * @return bool
	 */
	private function pattern_matches( $pattern, $path_query ) {
		if ( '' === $pattern ) {
			return false;
		}

		$end_anchor = '$' === substr( $pattern, -1 );
		if ( $end_anchor ) {
			$pattern = substr( $pattern, 0, -1 );
		}

		$regex = str_replace( '\\*', '.*', preg_quote( $pattern, '#' ) );

		return 1 === preg_match( '#^' . $regex . ( $end_anchor ? '$' : '' ) . '#', $path_query );
	}
}
