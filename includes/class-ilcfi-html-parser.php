<?php
/**
 * HTML, JSON-LD, and migration-evidence parsing.
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts crawler-facing document evidence without making policy decisions.
 */
final class ILCFI_HTML_Parser {
	const MAX_RESIDUE_EVIDENCE = 20;

	/** @var ILCFI_URL_Helper */
	private $urls;

	/**
	 * @param ILCFI_URL_Helper $urls URL helper.
	 */
	public function __construct( ILCFI_URL_Helper $urls ) {
		$this->urls = $urls;
	}

	/**
	 * Empty analysis shape used when HTML evidence is unavailable.
	 *
	 * @return array
	 */
	public function empty_analysis() {
		return array(
			'canonical_url'            => '',
			'meta_robots'              => '',
			'meta_robots_parts'        => array(),
			'effective_meta_robots'    => '',
			'effective_meta_robots_parts' => array(),
			'title_present'            => false,
			'meta_description_present' => false,
			'json_ld_count'            => 0,
			'json_ld_malformed'        => 0,
			'json_ld_types'            => array(),
			'duplicate_json_ld_ids'    => array(),
			'residue_evidence'         => array(),
			'parser_complete'          => true,
			'parser_messages'          => array(),
		);
	}

	/**
	 * Parse one complete HTML response.
	 *
	 * @param string $body HTML.
	 * @param string $final_url Final response URL.
	 * @param array  $old_domains Normalized administrator-supplied hostnames.
	 * @return array
	 */
	public function analyze( $body, $final_url, array $old_domains = array() ) {
		$analysis   = $this->empty_analysis();
		$candidates = array();
		$json       = $this->analyze_json_ld( $body );

		$analysis['json_ld_count']         = $json['count'];
		$analysis['json_ld_malformed']     = $json['malformed'];
		$analysis['json_ld_types']         = $json['types'];
		$analysis['duplicate_json_ld_ids'] = $json['duplicate_ids'];
		$candidates                        = array_merge( $candidates, $json['url_candidates'] );

		if ( '' === trim( $body ) ) {
			return $analysis;
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			$analysis['parser_complete'] = false;
			$analysis['parser_messages'][] = __( 'The DOM extension was unavailable; document attribute evidence is incomplete.', 'indexlane-crawl-fetch-inspector' );
			$analysis['residue_evidence'] = $this->build_residue_evidence( $candidates, $old_domains );
			return $analysis;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $body, LIBXML_NONET );

		if ( ! $loaded ) {
			$analysis['parser_complete'] = false;
			$analysis['parser_messages'][] = __( 'The HTML document could not be parsed; document attribute evidence is incomplete.', 'indexlane-crawl-fetch-inspector' );
		} else {
			$xpath = new DOMXPath( $dom );

			foreach ( $xpath->query( '//title' ) as $title ) {
				if ( '' !== trim( (string) $title->textContent ) ) {
					$analysis['title_present'] = true;
					break;
				}
			}

			foreach ( $xpath->query( '//*[@href]' ) as $node ) {
				$value   = trim( (string) $node->getAttribute( 'href' ) );
				$tag     = strtolower( $node->nodeName );
				$context = $tag . '[href]';

				if ( 'link' === $tag && preg_match( '/(^|\s)canonical(\s|$)/i', (string) $node->getAttribute( 'rel' ) ) ) {
					$context   = 'canonical[href]';
					$canonical = $this->urls->make_absolute( $value, $final_url );
					if ( '' !== $value ) {
						$analysis['canonical_url'] = is_wp_error( $canonical ) ? esc_url_raw( $value ) : esc_url_raw( $canonical );
					}
				}

				$this->add_candidate( $candidates, $value, $context );
			}

			foreach ( $xpath->query( '//*[@src]' ) as $node ) {
				$this->add_candidate(
					$candidates,
					trim( (string) $node->getAttribute( 'src' ) ),
					strtolower( $node->nodeName ) . '[src]'
				);
			}

			foreach ( $xpath->query( '//meta' ) as $meta ) {
				$name     = strtolower( trim( (string) $meta->getAttribute( 'name' ) ) );
				$property = strtolower( trim( (string) $meta->getAttribute( 'property' ) ) );
				$content  = trim( (string) $meta->getAttribute( 'content' ) );

				if ( 'description' === $name && '' !== $content ) {
					$analysis['meta_description_present'] = true;
				}

				if ( in_array( $name, array( 'robots', 'googlebot', 'googlebot-news' ), true ) && '' !== $content ) {
					$analysis['meta_robots_parts'][] = $name . ': ' . $content;
				}

				if ( in_array( $name, array( 'robots', 'googlebot' ), true ) && '' !== $content ) {
					$analysis['effective_meta_robots_parts'][] = $name . ': ' . $content;
				}

				$open_graph_key = 0 === strpos( $property, 'og:' ) ? $property : ( 0 === strpos( $name, 'og:' ) ? $name : '' );
				if ( '' !== $open_graph_key && '' !== $content ) {
					$this->add_candidate( $candidates, $content, 'meta[' . $open_graph_key . ']' );
				}
			}

			foreach ( $xpath->query( '//*[@style]' ) as $node ) {
				$this->add_css_candidates(
					$candidates,
					(string) $node->getAttribute( 'style' ),
					strtolower( $node->nodeName ) . '[style]'
				);
			}

			foreach ( $xpath->query( '//style' ) as $style ) {
				$this->add_css_candidates( $candidates, (string) $style->textContent, 'style element' );
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! empty( $analysis['meta_robots_parts'] ) ) {
			$analysis['meta_robots'] = implode( '; ', $analysis['meta_robots_parts'] );
		}
		if ( ! empty( $analysis['effective_meta_robots_parts'] ) ) {
			$analysis['effective_meta_robots'] = implode( '; ', $analysis['effective_meta_robots_parts'] );
		}

		unset( $analysis['meta_robots_parts'] );
		unset( $analysis['effective_meta_robots_parts'] );
		$analysis['residue_evidence'] = $this->build_residue_evidence( $candidates, $old_domains );

		return $analysis;
	}

	/**
	 * Analyze raw JSON-LD script bodies. HTML entities are deliberately not decoded.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	private function analyze_json_ld( $html ) {
		$blocks = array();
		$found  = preg_match_all(
			'#<script\b[^>]*\btype\s*=\s*([\'\"]?)application/ld\+json(?:\s*;[^\'\">]*)?\1[^>]*>(.*?)</script>#is',
			$html,
			$matches
		);

		if ( false !== $found && $found > 0 ) {
			$blocks = $matches[2];
		}

		$types      = array();
		$ids        = array();
		$candidates = array();
		$malformed  = 0;

		foreach ( $blocks as $index => $block ) {
			$source = trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $block ) );

			if ( '' === $source ) {
				$malformed++;
				continue;
			}

			$decoded = json_decode( $source, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$malformed++;
				continue;
			}

			$this->walk_json( $decoded, '$', $types, $ids, $candidates, $index + 1 );
		}

		$types = array_values( array_unique( array_filter( $types ) ) );
		natcasesort( $types );
		$duplicate_ids = array();

		foreach ( $ids as $id => $count ) {
			if ( $count > 1 ) {
				$duplicate_ids[] = $id;
			}
		}

		natcasesort( $duplicate_ids );

		return array(
			'count'          => count( $blocks ),
			'malformed'      => $malformed,
			'types'          => array_values( $types ),
			'duplicate_ids'  => array_values( $duplicate_ids ),
			'url_candidates' => $candidates,
		);
	}

	/**
	 * Walk decoded JSON-LD for @type, duplicate @id, and URL-valued properties.
	 *
	 * @param mixed  $node Node.
	 * @param string $path JSON path.
	 * @param array  $types Types.
	 * @param array  $ids ID counts.
	 * @param array  $candidates URL candidates.
	 * @param int    $block_number Block number.
	 */
	private function walk_json( $node, $path, array &$types, array &$ids, array &$candidates, $block_number ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		foreach ( $node as $key => $value ) {
			$key_name  = is_string( $key ) ? $key : (string) $key;
			$next_path = $path . ( is_string( $key ) ? '.' . $key : '[' . $key . ']' );

			if ( is_string( $key ) && '@type' === strtolower( $key ) ) {
				$this->collect_types( $value, $types );
			}

			if ( is_string( $key ) && '@id' === strtolower( $key ) && is_string( $value ) && '' !== trim( $value ) ) {
				$id         = trim( $value );
				$ids[ $id ] = isset( $ids[ $id ] ) ? $ids[ $id ] + 1 : 1;
			}

			if ( is_string( $value ) && $this->looks_like_absolute_url( $value ) ) {
				$this->add_candidate( $candidates, $value, 'JSON-LD block ' . $block_number . ' ' . $next_path );
			}

			if ( is_array( $value ) ) {
				$this->walk_json( $value, $next_path, $types, $ids, $candidates, $block_number );
			}
		}
	}

	/**
	 * Collect scalar or list @type values.
	 *
	 * @param mixed $value Value.
	 * @param array $types Types.
	 */
	private function collect_types( $value, array &$types ) {
		$values = is_array( $value ) ? $value : array( $value );

		foreach ( $values as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}

			$type = trim( preg_replace( '#^https?://schema\.org/#i', '', trim( $type ) ), " \t\n\r\0\x0B/#" );
			if ( '' !== $type ) {
				$types[] = $type;
			}
		}
	}

	/**
	 * Add url(...) values from CSS without scanning ordinary copy.
	 *
	 * @param array  $candidates Candidates.
	 * @param string $css CSS.
	 * @param string $context Source context.
	 */
	private function add_css_candidates( array &$candidates, $css, $context ) {
		if ( ! preg_match_all( '#url\(\s*([\'\"]?)(.*?)\1\s*\)#is', $css, $matches ) ) {
			return;
		}

		foreach ( $matches[2] as $value ) {
			$this->add_candidate( $candidates, trim( $value ), $context . ' url()' );
		}
	}

	/**
	 * Add a non-empty evidence candidate.
	 *
	 * @param array  $candidates Candidates.
	 * @param string $value Exact source value.
	 * @param string $context Source context.
	 */
	private function add_candidate( array &$candidates, $value, $context ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return;
		}

		$candidates[] = array(
			'value'   => $value,
			'context' => $context,
		);
	}

	/**
	 * Turn URL candidates into bounded, deduplicated staging/old-host evidence.
	 *
	 * @param array $candidates Candidates.
	 * @param array $old_domains Old hosts.
	 * @return array
	 */
	private function build_residue_evidence( array $candidates, array $old_domains ) {
		$evidence = array();
		$seen     = array();

		foreach ( $candidates as $candidate ) {
			$host = $this->host_from_candidate( $candidate['value'] );
			if ( '' === $host ) {
				continue;
			}

			$reason = $this->residue_reason( $host, $old_domains );
			if ( '' === $reason ) {
				continue;
			}

			$key = strtolower( $candidate['context'] . '|' . $candidate['value'] . '|' . $reason );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$evidence[]    = array(
				'matched_value' => $candidate['value'],
				'matched_host'  => $host,
				'reason'        => $reason,
				'context'       => $candidate['context'],
				'snippet'       => $this->snippet( $candidate['value'] ),
			);

			if ( count( $evidence ) >= self::MAX_RESIDUE_EVIDENCE ) {
				break;
			}
		}

		return $evidence;
	}

	/**
	 * Extract a host only from a properly formed absolute or protocol-relative URL.
	 *
	 * @param string $value Candidate.
	 * @return string
	 */
	private function host_from_candidate( $value ) {
		$value = trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );

		if ( 0 === strpos( $value, '//' ) ) {
			$value = 'https:' . $value;
		}

		if ( ! preg_match( '#^https?://#i', $value ) ) {
			return '';
		}

		$host = wp_parse_url( $value, PHP_URL_HOST );
		$host = is_string( $host ) ? trim( strtolower( $host ), '.' ) : '';

		return $this->urls->is_valid_hostname( $host ) ? $host : '';
	}

	/**
	 * Explain why a host is migration evidence.
	 *
	 * @param string $host Host.
	 * @param array  $old_domains Old hosts.
	 * @return string
	 */
	private function residue_reason( $host, array $old_domains ) {
		foreach ( $old_domains as $old_domain ) {
			$host_without_www = preg_replace( '/^www\./', '', $host );
			$old_without_www  = preg_replace( '/^www\./', '', $old_domain );
			if ( $this->urls->hosts_match( $host, $old_domain ) || substr( $host_without_www, -strlen( '.' . $old_without_www ) ) === '.' . $old_without_www ) {
				return __( 'Administrator-supplied old domain', 'indexlane-crawl-fetch-inspector' );
			}
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '0.0.0.0' ), true ) ) {
			return __( 'Local development host', 'indexlane-crawl-fetch-inspector' );
		}

		$suffixes = array(
			'pantheonsite.io',
			'kinsta.cloud',
			'myftpupload.com',
			'flywheelstaging.com',
			'flywheelsites.com',
			'cloudwaysapps.com',
			'wpenginepowered.com',
			'ngrok.io',
			'ngrok-free.app',
			'trycloudflare.com',
		);

		foreach ( $suffixes as $suffix ) {
			if ( $host === $suffix || substr( $host, -strlen( '.' . $suffix ) ) === '.' . $suffix ) {
				return __( 'Known temporary or managed staging host', 'indexlane-crawl-fetch-inspector' );
			}
		}

		$labels = explode( '.', $host );
		if ( in_array( end( $labels ), array( 'local', 'test', 'invalid' ), true ) ) {
			return __( 'Development-only hostname', 'indexlane-crawl-fetch-inspector' );
		}

		foreach ( $labels as $label ) {
			if ( preg_match( '/(?:^|-)(?:staging|stage|dev|development|preview|temp|temporary)(?:-|$)/', $label ) ) {
				return __( 'Staging or temporary hostname', 'indexlane-crawl-fetch-inspector' );
			}
		}

		return '';
	}

	/**
	 * Whether a scalar begins with an absolute web URL.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function looks_like_absolute_url( $value ) {
		return (bool) preg_match( '#^(?:https?:)?//#i', trim( (string) $value ) );
	}

	/**
	 * Bounded display snippet.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function snippet( $value ) {
		$value = preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( (string) $value ) ) );

		if ( strlen( $value ) <= 180 ) {
			return $value;
		}

		return substr( $value, 0, 177 ) . '...';
	}
}
