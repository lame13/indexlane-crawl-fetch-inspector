<?php
/**
 * Plugin Name: IndexLane Crawl Fetch Inspector
 * Plugin URI: https://indexlane.dev/plugins/crawl-fetch-inspector/
 * Description: A small free WordPress diagnostic plugin for checking crawler-facing HTTP status, redirects, canonicals, robots directives, schema blocks, and basic HTML SEO signals.
 * Version: 0.1.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: IndexLane
 * Author URI: https://indexlane.dev
 * License: GPLv2 or later
 * Text Domain: indexlane-crawl-fetch-inspector
 * Update URI: https://indexlane.dev/plugins/crawl-fetch-inspector/
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'IndexLane_Crawl_Fetch_Inspector' ) ) {
	/**
	 * Admin-only crawler-facing fetch diagnostics.
	 */
	final class IndexLane_Crawl_Fetch_Inspector {
		const VERSION            = '0.1.2';
		const SLUG               = 'indexlane-crawl-fetch-inspector';
		const NONCE_ACTION       = 'ilcfi_scan_request';
		const NONCE_FIELD        = 'ilcfi_nonce';
		const MAX_URLS           = 50;
		const MAX_REDIRECTS      = 5;
		const MAX_RESPONSE_BYTES = 2097152;

		/**
		 * Register hooks.
		 */
		public static function init() {
			$plugin = new self();

			add_action( 'admin_menu', array( $plugin, 'add_admin_page' ) );
			add_action( 'admin_init', array( $plugin, 'maybe_export_csv' ) );
		}

		/**
		 * Add Tools screen.
		 */
		public function add_admin_page() {
			add_management_page(
				__( 'Crawl Fetch Inspector', 'indexlane-crawl-fetch-inspector' ),
				__( 'Crawl Fetch Inspector', 'indexlane-crawl-fetch-inspector' ),
				'manage_options',
				self::SLUG,
				array( $this, 'render_admin_page' )
			);
		}

		/**
		 * Stream CSV export before admin output starts.
		 */
		public function maybe_export_csv() {
			if ( ! is_admin() || ! isset( $_GET['page'], $_POST['ilcfi_action'] ) ) {
				return;
			}

			$page   = is_array( $_GET['page'] ) ? '' : sanitize_key( wp_unslash( $_GET['page'] ) );
			$action = is_array( $_POST['ilcfi_action'] ) ? '' : sanitize_key( wp_unslash( $_POST['ilcfi_action'] ) );

			if ( self::SLUG !== $page || 'export' !== $action ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to export crawl fetch checks.', 'indexlane-crawl-fetch-inspector' ) );
			}

			check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

			$request = $this->get_request_from_post();
			$rows    = empty( $request['items'] ) ? array() : $this->run_scan( $request );

			$this->send_csv( $rows );
		}

		/**
		 * Render admin screen.
		 */
		public function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to run crawl fetch checks.', 'indexlane-crawl-fetch-inspector' ) );
			}

			$request = $this->get_default_request();
			$rows    = array();
			$did_run = false;

			$action = isset( $_POST['ilcfi_action'] ) && ! is_array( $_POST['ilcfi_action'] ) ? sanitize_key( wp_unslash( $_POST['ilcfi_action'] ) ) : '';

			if ( 'run' === $action ) {
				check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

				$request = $this->get_request_from_post();
				$did_run = true;

				if ( ! empty( $request['items'] ) ) {
					$rows = $this->run_scan( $request );
				}
			}

			$this->render_screen( $request, $rows, $did_run );
		}

		/**
		 * Default request state.
		 *
		 * @return array
		 */
		private function get_default_request() {
			return array(
				'raw_urls'       => '',
				'post_types'     => array(),
				'recent_limit'   => 10,
				'items'          => array(),
				'messages'       => array(),
			);
		}

		/**
		 * Build scan request from sanitized POST data.
		 *
		 * @return array
		 */
		private function get_request_from_post() {
			$request = $this->get_default_request();

			$raw_urls       = isset( $_POST['ilcfi_urls'] ) && ! is_array( $_POST['ilcfi_urls'] ) ? wp_unslash( $_POST['ilcfi_urls'] ) : '';
			$raw_limit      = isset( $_POST['ilcfi_recent_limit'] ) && ! is_array( $_POST['ilcfi_recent_limit'] ) ? wp_unslash( $_POST['ilcfi_recent_limit'] ) : '10';
			$raw_post_types = isset( $_POST['ilcfi_post_types'] ) ? wp_unslash( $_POST['ilcfi_post_types'] ) : array();

			$request['raw_urls']     = sanitize_textarea_field( $raw_urls );
			$request['recent_limit'] = min( 50, max( 1, absint( $raw_limit ) ) );

			$available_post_types = $this->get_available_post_types();
			$selected_post_types  = is_array( $raw_post_types ) ? $raw_post_types : array( $raw_post_types );

			foreach ( $selected_post_types as $post_type ) {
				if ( ! is_scalar( $post_type ) ) {
					continue;
				}

				$post_type = sanitize_key( $post_type );

				if ( isset( $available_post_types[ $post_type ] ) ) {
					$request['post_types'][] = $post_type;
				}
			}

			$seen = array();

			$this->add_manual_items( $request, $seen );
			$this->add_recent_content_items( $request, $seen );

			if ( empty( $request['items'] ) ) {
				$request['messages'][] = __( 'Add at least one URL or select recent content to run a check.', 'indexlane-crawl-fetch-inspector' );
			}

			return $request;
		}

		/**
		 * Add manually entered URL rows.
		 *
		 * @param array $request Request data.
		 * @param array $seen    Seen normalized URLs.
		 */
		private function add_manual_items( array &$request, array &$seen ) {
			if ( '' === trim( $request['raw_urls'] ) ) {
				return;
			}

			$lines = preg_split( '/\R+/', $request['raw_urls'] );

			foreach ( $lines as $line ) {
				$line = trim( $line );

				if ( '' === $line ) {
					continue;
				}

				if ( count( $request['items'] ) >= self::MAX_URLS ) {
					$request['messages'][] = sprintf(
						/* translators: %d is the maximum number of URLs checked per run. */
						__( 'Only the first %d URLs were included in this run.', 'indexlane-crawl-fetch-inspector' ),
						self::MAX_URLS
					);
					break;
				}

				$normalized = $this->normalize_input_url( $line );

				if ( is_wp_error( $normalized ) ) {
					$request['items'][] = array(
						'input_url' => $line,
						'error'     => $normalized->get_error_message(),
					);
					continue;
				}

				$this->add_item(
					$request,
					$seen,
					array(
						'input_url' => $line,
						'url'       => $normalized,
						'source'    => 'manual',
					)
				);
			}
		}

		/**
		 * Add URLs from selected recent content.
		 *
		 * @param array $request Request data.
		 * @param array $seen    Seen normalized URLs.
		 */
		private function add_recent_content_items( array &$request, array &$seen ) {
			if ( empty( $request['post_types'] ) ) {
				return;
			}

			foreach ( $request['post_types'] as $post_type ) {
				if ( count( $request['items'] ) >= self::MAX_URLS ) {
					$request['messages'][] = sprintf(
						/* translators: %d is the maximum number of URLs checked per run. */
						__( 'Only the first %d URLs were included in this run.', 'indexlane-crawl-fetch-inspector' ),
						self::MAX_URLS
					);
					break;
				}

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
					if ( count( $request['items'] ) >= self::MAX_URLS ) {
						break;
					}

					$permalink = get_permalink( $post_id );

					if ( ! $permalink ) {
						continue;
					}

					$this->add_item(
						$request,
						$seen,
						array(
							'input_url' => $permalink,
							'url'       => esc_url_raw( $permalink ),
							'source'    => $post_type,
						)
					);
				}
			}
		}

		/**
		 * Add a deduplicated item.
		 *
		 * @param array $request Request data.
		 * @param array $seen    Seen normalized URLs.
		 * @param array $item    Scan item.
		 */
		private function add_item( array &$request, array &$seen, array $item ) {
			if ( empty( $item['url'] ) ) {
				$request['items'][] = $item;
				return;
			}

			$key = $this->normalize_url_for_compare( $item['url'] );

			if ( isset( $seen[ $key ] ) ) {
				return;
			}

			$seen[ $key ]      = true;
			$request['items'][] = $item;
		}

		/**
		 * Run all checks.
		 *
		 * @param array $request Request data.
		 * @return array
		 */
		private function run_scan( array $request ) {
			$rows = array();

			foreach ( $request['items'] as $item ) {
				if ( isset( $item['error'] ) ) {
					$rows[] = $this->build_error_row( $item['input_url'], $item['error'] );
					continue;
				}

				$rows[] = $this->inspect_url( $item['input_url'], $item['url'] );
			}

			return $rows;
		}

		/**
		 * Inspect one URL.
		 *
		 * @param string $input_url      Original input URL.
		 * @param string $url            Normalized fetch URL.
		 * @return array
		 */
		private function inspect_url( $input_url, $url ) {
			$fetch = $this->fetch_url( $url );

			if ( is_wp_error( $fetch ) ) {
				return $this->build_error_row( $input_url, $fetch->get_error_message(), $url );
			}

			$headers        = $fetch['response'];
			$status         = (int) wp_remote_retrieve_response_code( $headers );
			$body           = (string) wp_remote_retrieve_body( $headers );
			$final_url      = $fetch['final_url'];
			$response_time  = (int) round( $fetch['response_time'] * 1000 );
			$x_robots_tag   = $this->header_to_string( wp_remote_retrieve_header( $headers, 'x-robots-tag' ) );
			$content_type   = strtolower( $this->header_to_string( wp_remote_retrieve_header( $headers, 'content-type' ) ) );
			$is_html        = ( false !== strpos( $content_type, 'text/html' ) || false !== strpos( $content_type, 'application/xhtml+xml' ) || '' === $content_type );
			$html_skipped   = ! $is_html || ! empty( $fetch['redirect_left_site'] ) || ! empty( $fetch['redirect_loop'] ) || ! empty( $fetch['redirect_limit_reached'] ) || ! empty( $fetch['body_truncated'] ) || ( $status >= 300 && $status < 400 );
			$html_analysis  = ( $is_html && ! $html_skipped ) ? $this->analyze_html( $body, $final_url ) : $this->empty_html_analysis();
			$classification = $this->classify_result( $status, $url, $final_url, $fetch, $html_analysis, $x_robots_tag, $is_html );

			$title_state = $html_analysis['title_present']
				? __( 'Present', 'indexlane-crawl-fetch-inspector' )
				: __( 'Missing', 'indexlane-crawl-fetch-inspector' );

			$meta_description_state = $html_analysis['meta_description_present']
				? __( 'Present', 'indexlane-crawl-fetch-inspector' )
				: __( 'Missing', 'indexlane-crawl-fetch-inspector' );
			$json_ld_state          = (string) $html_analysis['json_ld_count'];
			$dev_residue_state      = empty( $html_analysis['dev_residue_terms'] ) ? __( 'Not found', 'indexlane-crawl-fetch-inspector' ) : sprintf(
				/* translators: %s is a comma-separated list of detected dev/staging residue patterns. */
				__( 'Found: %s', 'indexlane-crawl-fetch-inspector' ),
				implode( ', ', $html_analysis['dev_residue_terms'] )
			);

			if ( $html_skipped ) {
				$title_state            = __( 'Not checked', 'indexlane-crawl-fetch-inspector' );
				$meta_description_state = __( 'Not checked', 'indexlane-crawl-fetch-inspector' );
				$json_ld_state           = __( 'Not checked', 'indexlane-crawl-fetch-inspector' );
				$dev_residue_state       = __( 'Not checked', 'indexlane-crawl-fetch-inspector' );
			}

			return array(
				'input_url'        => $input_url,
				'http_status'      => $status ? (string) $status : '',
				'redirects'        => (string) $fetch['redirect_count'],
				'final_url'        => $final_url,
				'canonical_url'    => $html_analysis['canonical_url'],
				'meta_robots'      => $html_analysis['meta_robots'],
				'x_robots_tag'     => $x_robots_tag,
				'title'            => $title_state,
				'meta_description' => $meta_description_state,
				'json_ld_count'    => $json_ld_state,
				'dev_residue'      => $dev_residue_state,
				'response_time'    => $response_time ? sprintf(
					/* translators: %d is response time in milliseconds. */
					__( '%d ms', 'indexlane-crawl-fetch-inspector' ),
					$response_time
				) : '',
				'result'           => $classification['label'],
				'detail'           => $classification['detail'],
			);
		}

		/**
		 * Fetch URL and manually track redirects.
		 *
		 * @param string $url            URL to fetch.
		 * @return array|WP_Error
		 */
		private function fetch_url( $url ) {
			$started                = microtime( true );
			$current_url            = $url;
			$redirect_count         = 0;
			$last_response          = null;
			$details                = array();
			$seen                   = array();
			$redirect_left_site     = false;
			$redirect_loop          = false;
			$redirect_limit_reached = false;

			for ( $step = 0; $step <= self::MAX_REDIRECTS; $step++ ) {
				$seen[ $this->normalize_url_for_compare( $current_url ) ] = true;

				$response = wp_safe_remote_get(
					$current_url,
					array(
						'timeout'             => 15,
						'redirection'         => 0,
						'limit_response_size' => self::MAX_RESPONSE_BYTES,
						'user-agent'          => 'IndexLane Crawl Fetch Inspector/' . self::VERSION . '; ' . home_url( '/' ),
						'headers'             => array(
							'Accept' => 'text/html,application/xhtml+xml',
						),
					)
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$last_response = $response;
				$status        = (int) wp_remote_retrieve_response_code( $response );
				$location      = $this->header_to_string( wp_remote_retrieve_header( $response, 'location' ) );

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

				$target = $this->make_absolute_url( $location, $current_url );

				if ( is_wp_error( $target ) ) {
					$details[] = $target->get_error_message();
					break;
				}

				$redirect_count++;

				if ( ! $this->is_same_site_url( $target ) ) {
					$current_url        = $target;
					$redirect_left_site = true;
					break;
				}

				$target_key = $this->normalize_url_for_compare( $target );

				if ( isset( $seen[ $target_key ] ) ) {
					$current_url   = $target;
					$redirect_loop = true;
					break;
				}

				if ( $redirect_count >= self::MAX_REDIRECTS ) {
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
				'response_time'          => microtime( true ) - $started,
				'details'                => $details,
				'redirect_left_site'     => $redirect_left_site,
				'redirect_loop'          => $redirect_loop,
				'redirect_limit_reached' => $redirect_limit_reached,
				'body_truncated'          => $this->response_body_was_truncated( $last_response ),
			);
		}

		/**
		 * Whether the response body reached the request limit or is shorter than
		 * an unencoded Content-Length header promised.
		 *
		 * @param array $response WordPress HTTP response.
		 * @return bool
		 */
		private function response_body_was_truncated( array $response ) {
			$body_length = strlen( (string) wp_remote_retrieve_body( $response ) );

			if ( $body_length >= self::MAX_RESPONSE_BYTES ) {
				return true;
			}

			$content_encoding = strtolower( $this->header_to_string( wp_remote_retrieve_header( $response, 'content-encoding' ) ) );

			if ( '' !== $content_encoding && 'identity' !== $content_encoding ) {
				return false;
			}

			$content_length = $this->header_to_string( wp_remote_retrieve_header( $response, 'content-length' ) );

			if ( ! preg_match( '/^\d+$/', $content_length ) ) {
				return false;
			}

			return (int) $content_length > $body_length;
		}

		/**
		 * Analyze HTML response body.
		 *
		 * @param string $body      HTML body.
		 * @param string $final_url Final response URL.
		 * @return array
		 */
		private function analyze_html( $body, $final_url ) {
			$analysis = $this->empty_html_analysis();

			if ( '' === trim( $body ) || ! class_exists( 'DOMDocument' ) ) {
				$analysis['dev_residue_terms'] = $this->detect_dev_residue( $final_url . "\n" . $body );
				return $analysis;
			}

			$previous = libxml_use_internal_errors( true );
			$dom      = new DOMDocument();
			$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $body );

			if ( $loaded ) {
				$xpath = new DOMXPath( $dom );

				foreach ( $xpath->query( '//link' ) as $link ) {
					$rel = strtolower( (string) $link->getAttribute( 'rel' ) );

					if ( preg_match( '/(^|\s)canonical(\s|$)/', $rel ) ) {
						$href = trim( (string) $link->getAttribute( 'href' ) );

						if ( '' !== $href ) {
							$canonical = $this->make_absolute_url( $href, $final_url );

							$analysis['canonical_url'] = is_wp_error( $canonical ) ? esc_url_raw( $href ) : esc_url_raw( $canonical );
							break;
						}
					}
				}

				foreach ( $xpath->query( '//title' ) as $title ) {
					if ( '' !== trim( (string) $title->textContent ) ) {
						$analysis['title_present'] = true;
						break;
					}
				}

				foreach ( $xpath->query( '//meta' ) as $meta ) {
					$name    = strtolower( trim( (string) $meta->getAttribute( 'name' ) ) );
					$content = trim( (string) $meta->getAttribute( 'content' ) );

					if ( 'description' === $name && '' !== $content ) {
						$analysis['meta_description_present'] = true;
					}

					if ( in_array( $name, array( 'robots', 'googlebot', 'bingbot', 'slurp', 'yandex' ), true ) && '' !== $content ) {
						$analysis['meta_robots_parts'][] = $name . ': ' . $content;
					}
				}

				foreach ( $xpath->query( '//script' ) as $script ) {
					$type = strtolower( trim( (string) $script->getAttribute( 'type' ) ) );

					if ( preg_match( '/^application\/ld\+json(\s*;|$)/', $type ) ) {
						$analysis['json_ld_count']++;
					}
				}
			}

			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( ! empty( $analysis['meta_robots_parts'] ) ) {
				$analysis['meta_robots'] = implode( '; ', $analysis['meta_robots_parts'] );
			}

			unset( $analysis['meta_robots_parts'] );

			$analysis['dev_residue_terms'] = $this->detect_dev_residue(
				$final_url . "\n" . $analysis['canonical_url'] . "\n" . $body
			);

			return $analysis;
		}

		/**
		 * Empty HTML analysis shape.
		 *
		 * @return array
		 */
		private function empty_html_analysis() {
			return array(
				'canonical_url'             => '',
				'meta_robots'               => '',
				'meta_robots_parts'         => array(),
				'title_present'             => false,
				'meta_description_present'  => false,
				'json_ld_count'             => 0,
				'dev_residue_terms'         => array(),
			);
		}

		/**
		 * Classify evidence conservatively.
		 *
		 * @param int    $status        HTTP status.
		 * @param string $input_url     Normalized input URL.
		 * @param string $final_url     Final URL.
		 * @param array  $fetch         Fetch metadata.
		 * @param array  $analysis      HTML analysis.
		 * @param string $x_robots_tag  X-Robots-Tag header.
		 * @param bool   $is_html       Whether response looked like HTML.
		 * @return array
		 */
		private function classify_result( $status, $input_url, $final_url, array $fetch, array $analysis, $x_robots_tag, $is_html ) {
			$review_details  = array();
			$warning_details = array();
			$response_time   = isset( $fetch['response_time'] ) ? (int) round( $fetch['response_time'] * 1000 ) : 0;

			if ( ! empty( $fetch['details'] ) ) {
				$review_details = array_merge( $review_details, $fetch['details'] );
			}

			if ( ! empty( $fetch['redirect_limit_reached'] ) ) {
				$review_details[] = sprintf(
					/* translators: %d is the maximum number of redirects followed per URL. */
					__( 'Stopped after %d redirects.', 'indexlane-crawl-fetch-inspector' ),
					self::MAX_REDIRECTS
				);
			}

			if ( ! empty( $fetch['redirect_loop'] ) ) {
				$review_details[] = __( 'Redirect loop detected.', 'indexlane-crawl-fetch-inspector' );
			}

			if ( ! empty( $fetch['body_truncated'] ) ) {
				$review_details[] = __( 'Response body reached the 2 MB safety limit or ended before its declared length; HTML signals were not checked.', 'indexlane-crawl-fetch-inspector' );
			}

			if ( ! $is_html ) {
				$review_details[] = __( 'Response did not look like HTML.', 'indexlane-crawl-fetch-inspector' );
			}

			if ( $status >= 500 ) {
				$review_details[] = sprintf(
					/* translators: %d is an HTTP status code. */
					__( 'Crawler-facing response returned HTTP %d.', 'indexlane-crawl-fetch-inspector' ),
					$status
				);

				return array(
					'label'  => __( 'Error', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( $review_details ) ),
				);
			}

			if ( in_array( $status, array( 401, 403, 407 ), true ) ) {
				$review_details[] = sprintf(
					/* translators: %d is an HTTP status code. */
					__( 'Crawler-facing response returned HTTP %d.', 'indexlane-crawl-fetch-inspector' ),
					$status
				);

				return array(
					'label'  => __( 'Blocked', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( $review_details ) ),
				);
			}

			if ( in_array( $status, array( 404, 410 ), true ) ) {
				$review_details[] = sprintf(
					/* translators: %d is an HTTP status code. */
					__( 'Crawler-facing response returned HTTP %d.', 'indexlane-crawl-fetch-inspector' ),
					$status
				);

				return array(
					'label'  => __( 'Error', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( $review_details ) ),
				);
			}

			if ( $status >= 400 ) {
				$review_details[] = sprintf(
					/* translators: %d is an HTTP status code. */
					__( 'Crawler-facing response returned HTTP %d.', 'indexlane-crawl-fetch-inspector' ),
					$status
				);

				return array(
					'label'  => __( 'Needs review', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( $review_details ) ),
				);
			}

			if ( ! empty( $fetch['redirect_left_site'] ) ) {
				$warning_details[] = __( 'Redirect leaves site; external target was not fetched.', 'indexlane-crawl-fetch-inspector' );

				return array(
					'label'  => __( 'Warning', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( $warning_details ) ),
				);
			}

			if ( ! empty( $fetch['details'] ) || ! empty( $fetch['redirect_limit_reached'] ) || ! empty( $fetch['redirect_loop'] ) ) {
				return array(
					'label'  => __( 'Needs review', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( $review_details ) ),
				);
			}

			if ( $status >= 300 && $status < 400 ) {
				$review_details[] = sprintf(
					/* translators: %d is an HTTP status code. */
					__( 'Crawler-facing fetch ended with HTTP %d instead of a final non-redirect response.', 'indexlane-crawl-fetch-inspector' ),
					$status
				);

				return array(
					'label'  => __( 'Needs review', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( $review_details ) ),
				);
			}

			if ( ! empty( $fetch['body_truncated'] ) ) {
				return array(
					'label'  => __( 'Needs review', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( $review_details ) ),
				);
			}

			if ( $this->has_blocking_robots_directive( $analysis['meta_robots'], $x_robots_tag ) ) {
				$review_details[] = __( 'Robots directives include noindex or none.', 'indexlane-crawl-fetch-inspector' );

				return array(
					'label'  => __( 'Blocked', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( $review_details ) ),
				);
			}

			if ( ! empty( $analysis['canonical_url'] ) && $this->normalize_url_for_compare( $analysis['canonical_url'] ) !== $this->normalize_url_for_compare( $final_url ) ) {
				$review_details[] = __( 'Canonical URL differs from the final URL.', 'indexlane-crawl-fetch-inspector' );
			}

			if ( empty( $analysis['title_present'] ) ) {
				$review_details[] = __( 'Title element was missing or empty.', 'indexlane-crawl-fetch-inspector' );
			}

			if ( empty( $analysis['meta_description_present'] ) ) {
				$warning_details[] = __( 'Meta description was missing or empty.', 'indexlane-crawl-fetch-inspector' );
			}

			if ( ! empty( $analysis['dev_residue_terms'] ) ) {
				$review_details[] = __( 'Possible dev or staging residue was found.', 'indexlane-crawl-fetch-inspector' );
			}

			if ( $fetch['redirect_count'] > 1 ) {
				$warning_details[] = __( 'Redirect chain has more than one hop.', 'indexlane-crawl-fetch-inspector' );
			} elseif ( $fetch['redirect_count'] > 0 ) {
				$warning_details[] = __( 'URL redirects before the final response.', 'indexlane-crawl-fetch-inspector' );
			}

			if ( $response_time > 3000 ) {
				$warning_details[] = __( 'Response time was over 3000 ms.', 'indexlane-crawl-fetch-inspector' );
			}

			if ( ! empty( $review_details ) ) {
				return array(
					'label'  => __( 'Needs review', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( array_merge( $review_details, $warning_details ) ) ),
				);
			}

			if ( ! empty( $warning_details ) ) {
				return array(
					'label'  => __( 'Warning', 'indexlane-crawl-fetch-inspector' ),
					'detail' => implode( ' ', array_unique( $warning_details ) ),
				);
			}

			return array(
				'label'  => __( 'OK', 'indexlane-crawl-fetch-inspector' ),
				'detail' => '',
			);
		}

		/**
		 * Invalid or failed row.
		 *
		 * @param string $input_url Input URL.
		 * @param string $message   Error message.
		 * @param string $final_url Optional final URL.
		 * @return array
		 */
		private function build_error_row( $input_url, $message, $final_url = '' ) {
			return array(
				'input_url'        => $input_url,
				'http_status'      => '',
				'redirects'        => '',
				'final_url'        => $final_url,
				'canonical_url'    => '',
				'meta_robots'      => '',
				'x_robots_tag'     => '',
				'title'            => '',
				'meta_description' => '',
				'json_ld_count'    => '',
				'dev_residue'      => '',
				'response_time'    => '',
				'result'           => __( 'Error', 'indexlane-crawl-fetch-inspector' ),
				'detail'           => $message,
			);
		}

		/**
		 * Normalize a submitted URL.
		 *
		 * @param string $value          Raw URL.
		 * @return string|WP_Error
		 */
		private function normalize_input_url( $value ) {
			$value = trim( wp_strip_all_tags( $value ) );
			$value = trim( $value, "<> \t\n\r\0\x0B" );

			if ( '' === $value ) {
				return new WP_Error( 'ilcfi_empty_url', __( 'URL is empty.', 'indexlane-crawl-fetch-inspector' ) );
			}

			if ( 0 === strpos( $value, '/' ) && 0 !== strpos( $value, '//' ) ) {
				$value = home_url( $value );
			} elseif ( 0 === strpos( $value, '//' ) ) {
				$value = ( wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ? wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) : 'https' ) . ':' . $value;
			} elseif ( ! preg_match( '#^https?://#i', $value ) && preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}([\/?#]|$)/i', $value ) ) {
				$value = ( wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ? wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) : 'https' ) . '://' . $value;
			}

			$value = esc_url_raw( $value );
			$parts = wp_parse_url( $value );

			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
				return new WP_Error( 'ilcfi_invalid_url', __( 'Only valid http and https URLs can be checked.', 'indexlane-crawl-fetch-inspector' ) );
			}

			if ( ! $this->is_same_site_url( $value ) ) {
				return new WP_Error( 'ilcfi_external_url', __( 'External URL skipped. This v0.1 build checks same-site WordPress URLs only.', 'indexlane-crawl-fetch-inspector' ) );
			}

			return $this->remove_fragment( $value );
		}

		/**
		 * Resolve a redirect or canonical URL against a base URL.
		 *
		 * @param string $location Location or href.
		 * @param string $base_url Base URL.
		 * @return string|WP_Error
		 */
		private function make_absolute_url( $location, $base_url ) {
			$location = trim( html_entity_decode( $location, ENT_QUOTES, get_bloginfo( 'charset' ) ) );

			if ( '' === $location ) {
				return new WP_Error( 'ilcfi_empty_location', __( 'Redirect location was empty.', 'indexlane-crawl-fetch-inspector' ) );
			}

			if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $location ) && ! preg_match( '#^https?://#i', $location ) ) {
				return new WP_Error( 'ilcfi_unsupported_location_scheme', __( 'Resolved URL was not a valid http or https URL.', 'indexlane-crawl-fetch-inspector' ) );
			}

			if ( preg_match( '#^https?://#i', $location ) ) {
				$url = $location;
			} else {
				$base = wp_parse_url( $base_url );

				if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
					return new WP_Error( 'ilcfi_invalid_base', __( 'Could not resolve relative URL.', 'indexlane-crawl-fetch-inspector' ) );
				}

				$origin = $base['scheme'] . '://' . $base['host'];

				if ( ! empty( $base['port'] ) ) {
					$origin .= ':' . (int) $base['port'];
				}

				if ( 0 === strpos( $location, '//' ) ) {
					$url = $base['scheme'] . ':' . $location;
				} elseif ( 0 === strpos( $location, '/' ) ) {
					$url = $origin . $location;
				} elseif ( 0 === strpos( $location, '?' ) ) {
					$url = $origin . ( isset( $base['path'] ) ? $base['path'] : '/' ) . $location;
				} elseif ( 0 === strpos( $location, '#' ) ) {
					$url = $origin . ( isset( $base['path'] ) ? $base['path'] : '/' ) . $location;
				} else {
					$base_path = isset( $base['path'] ) ? $base['path'] : '/';
					$dir       = preg_replace( '#/[^/]*$#', '/', $base_path );
					$url       = $origin . $this->normalize_path( $dir . $location );
				}
			}

			$url   = esc_url_raw( $url );
			$parts = wp_parse_url( $url );

			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
				return new WP_Error( 'ilcfi_invalid_resolved_url', __( 'Resolved URL was not a valid http or https URL.', 'indexlane-crawl-fetch-inspector' ) );
			}

			return $this->remove_fragment( $url );
		}

		/**
		 * Normalize a path while preserving query strings.
		 *
		 * @param string $path Path and optional query.
		 * @return string
		 */
		private function normalize_path( $path ) {
			$query = '';

			if ( false !== strpos( $path, '?' ) ) {
				list( $path, $query ) = explode( '?', $path, 2 );
				$query               = '?' . $query;
			}

			$segments = explode( '/', $path );
			$output   = array();

			foreach ( $segments as $segment ) {
				if ( '' === $segment || '.' === $segment ) {
					continue;
				}

				if ( '..' === $segment ) {
					array_pop( $output );
					continue;
				}

				$output[] = $segment;
			}

			return '/' . implode( '/', $output ) . $query;
		}

		/**
		 * Strip URL fragment because it is not sent in HTTP requests.
		 *
		 * @param string $url URL.
		 * @return string
		 */
		private function remove_fragment( $url ) {
			$fragment_position = strpos( $url, '#' );

			return false === $fragment_position ? $url : substr( $url, 0, $fragment_position );
		}

		/**
		 * Compare whether a URL belongs to this site.
		 *
		 * @param string $url URL.
		 * @return bool
		 */
		private function is_same_site_url( $url ) {
			$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$url_host  = wp_parse_url( $url, PHP_URL_HOST );

			return $this->hosts_match( $home_host, $url_host );
		}

		/**
		 * Host comparison with www normalization.
		 *
		 * @param string $left  Host.
		 * @param string $right Host.
		 * @return bool
		 */
		private function hosts_match( $left, $right ) {
			$left  = strtolower( trim( (string) $left, '.' ) );
			$right = strtolower( trim( (string) $right, '.' ) );

			if ( '' === $left || '' === $right ) {
				return false;
			}

			return $left === $right || preg_replace( '/^www\./', '', $left ) === preg_replace( '/^www\./', '', $right );
		}

		/**
		 * Normalize URL for dedupe and comparison.
		 *
		 * @param string $url URL.
		 * @return string
		 */
		private function normalize_url_for_compare( $url ) {
			$url   = $this->remove_fragment( $url );
			$parts = wp_parse_url( $url );

			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return $url;
			}

			$scheme = strtolower( $parts['scheme'] );
			$host   = strtolower( $parts['host'] );
			$port   = empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'];
			$path   = isset( $parts['path'] ) ? $parts['path'] : '';
			$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

			if ( '' === $path ) {
				$path = '/';
			}

			return $scheme . '://' . $host . $port . $path . $query;
		}

		/**
		 * Header to display string.
		 *
		 * @param mixed $header Header value.
		 * @return string
		 */
		private function header_to_string( $header ) {
			if ( is_array( $header ) ) {
				$header = implode( ', ', array_map( 'trim', $header ) );
			}

			return trim( (string) $header );
		}

		/**
		 * Detect obvious dev/staging residue patterns.
		 *
		 * @param string $haystack Text to scan.
		 * @return array
		 */
		private function detect_dev_residue( $haystack ) {
			$haystack = strtolower( $haystack );
			$patterns = array(
				'localhost'          => 'localhost',
				'127.0.0.1'          => '127.0.0.1',
				'staging.'           => 'staging',
				'.staging.'          => 'staging',
				'-staging'           => 'staging',
				'dev.'               => 'dev',
				'.dev/'              => 'dev',
				'.test/'             => 'test',
				'.local/'            => 'local',
				'pantheonsite.io'    => 'pantheonsite.io',
				'kinsta.cloud'       => 'kinsta.cloud',
				'myftpupload.com'    => 'myftpupload.com',
				'flywheelstaging.com' => 'flywheelstaging.com',
				'flywheelsites.com'  => 'flywheelsites.com',
				'cloudwaysapps.com'  => 'cloudwaysapps.com',
				'ngrok.io'           => 'ngrok.io',
				'ngrok-free.app'     => 'ngrok-free.app',
			);

			$found = array();

			foreach ( $patterns as $needle => $label ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					$found[ $label ] = $label;
				}
			}

			return array_values( $found );
		}

		/**
		 * Whether robots values contain blocking directives.
		 *
		 * @param string $meta_robots Meta robots values.
		 * @param string $x_robots    X-Robots-Tag header.
		 * @return bool
		 */
		private function has_blocking_robots_directive( $meta_robots, $x_robots ) {
			$value = strtolower( $meta_robots . ' ' . $x_robots );

			return (bool) preg_match( '/(^|[\s,;:])(?:noindex|none)($|[\s,;])/', $value );
		}

		/**
		 * Supported post type selectors.
		 *
		 * @return array
		 */
		private function get_available_post_types() {
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

		/**
		 * Render screen markup.
		 *
		 * @param array $request Request data.
		 * @param array $rows    Result rows.
		 * @param bool  $did_run Whether a run was requested.
		 */
		private function render_screen( array $request, array $rows, $did_run ) {
			$available_post_types = $this->get_available_post_types();
			$action_url           = admin_url( 'tools.php?page=' . self::SLUG );
			?>
			<div class="wrap ilcfi-wrap">
				<h1><?php esc_html_e( 'IndexLane Crawl Fetch Inspector', 'indexlane-crawl-fetch-inspector' ); ?></h1>
				<p>
					<?php esc_html_e( 'Check how important WordPress URLs respond at the crawler-facing HTML layer from inside wp-admin.', 'indexlane-crawl-fetch-inspector' ); ?>
				</p>

				<?php $this->render_inline_styles(); ?>
				<?php $this->render_messages( $request, $did_run ); ?>

				<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="ilcfi-panel">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="ilcfi_urls"><?php esc_html_e( 'Manual URLs', 'indexlane-crawl-fetch-inspector' ); ?></label>
								</th>
								<td>
									<textarea name="ilcfi_urls" id="ilcfi_urls" class="large-text code" rows="8" placeholder="<?php esc_attr_e( "https://example.com/\nhttps://example.com/important-page/", 'indexlane-crawl-fetch-inspector' ); ?>"><?php echo esc_textarea( $request['raw_urls'] ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Enter one URL per line. Root-relative same-site paths such as /about/ are also accepted.', 'indexlane-crawl-fetch-inspector' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Recent content', 'indexlane-crawl-fetch-inspector' ); ?></th>
								<td>
									<?php foreach ( $available_post_types as $post_type => $label ) : ?>
										<label class="ilcfi-inline-check">
											<input type="checkbox" name="ilcfi_post_types[]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $request['post_types'], true ) ); ?>>
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
									<label class="ilcfi-limit">
										<?php esc_html_e( 'Limit per type', 'indexlane-crawl-fetch-inspector' ); ?>
										<input type="number" name="ilcfi_recent_limit" min="1" max="50" value="<?php echo esc_attr( (string) $request['recent_limit'] ); ?>">
									</label>
									<p class="description"><?php esc_html_e( 'Select recent published posts, pages, or products if available.', 'indexlane-crawl-fetch-inspector' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Scope', 'indexlane-crawl-fetch-inspector' ); ?></th>
								<td>
									<p><?php esc_html_e( 'Same-site WordPress URLs only.', 'indexlane-crawl-fetch-inspector' ); ?></p>
									<p class="description"><?php esc_html_e( 'External manual URLs are skipped, and same-site redirects that leave the site are not followed.', 'indexlane-crawl-fetch-inspector' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit ilcfi-actions">
						<button type="submit" name="ilcfi_action" value="run" class="button button-primary"><?php esc_html_e( 'Run checks', 'indexlane-crawl-fetch-inspector' ); ?></button>
						<button type="submit" name="ilcfi_action" value="export" class="button"><?php esc_html_e( 'Export CSV', 'indexlane-crawl-fetch-inspector' ); ?></button>
					</p>
				</form>

				<?php $this->render_results( $rows, $did_run ); ?>

				<div class="ilcfi-scope">
					<p>
						<strong><?php esc_html_e( 'Scope:', 'indexlane-crawl-fetch-inspector' ); ?></strong>
						<?php esc_html_e( 'Same-site URLs only. Checks run on demand from wp-admin; results are shown on this page or exported as CSV.', 'indexlane-crawl-fetch-inspector' ); ?>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * Render notices.
		 *
		 * @param array $request Request data.
		 * @param bool  $did_run Whether a run was requested.
		 */
		private function render_messages( array $request, $did_run ) {
			if ( ! $did_run || empty( $request['messages'] ) ) {
				return;
			}

			foreach ( $request['messages'] as $message ) {
				?>
				<div class="notice notice-warning inline">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
				<?php
			}
		}

		/**
		 * Render result table.
		 *
		 * @param array $rows    Result rows.
		 * @param bool  $did_run Whether a run was requested.
		 */
		private function render_results( array $rows, $did_run ) {
			if ( ! $did_run ) {
				return;
			}

			if ( empty( $rows ) ) {
				?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No checks were run.', 'indexlane-crawl-fetch-inspector' ); ?></p>
				</div>
				<?php
				return;
			}

			$columns = $this->get_result_columns();
			?>
			<h2><?php esc_html_e( 'Results', 'indexlane-crawl-fetch-inspector' ); ?></h2>
			<div class="ilcfi-table-scroll">
				<table class="widefat fixed striped ilcfi-results">
					<thead>
						<tr>
							<?php foreach ( $columns as $key => $label ) : ?>
								<th scope="col" class="<?php echo esc_attr( 'ilcfi-col-' . $key ); ?>"><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<?php foreach ( $columns as $key => $label ) : ?>
									<td class="<?php echo esc_attr( 'ilcfi-col-' . $key ); ?>">
										<?php $this->render_result_cell( $key, $row ); ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		}

		/**
		 * Render one result cell.
		 *
		 * @param string $key Column key.
		 * @param array  $row Row data.
		 */
		private function render_result_cell( $key, array $row ) {
			$value = isset( $row[ $key ] ) ? $row[ $key ] : '';

			if ( in_array( $key, array( 'input_url', 'final_url', 'canonical_url' ), true ) && '' !== $value ) {
				?>
				<a href="<?php echo esc_url( $value ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $value ); ?></a>
				<?php
				return;
			}

			if ( 'result' === $key ) {
				$class = 'ilcfi-result-' . sanitize_html_class( strtolower( str_replace( ' ', '-', $value ) ) );
				?>
				<span class="ilcfi-result <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $value ); ?></span>
				<?php if ( ! empty( $row['detail'] ) ) : ?>
					<div class="ilcfi-detail"><?php echo esc_html( $row['detail'] ); ?></div>
				<?php endif; ?>
				<?php
				return;
			}

			echo '' === $value ? '&mdash;' : esc_html( $value );
		}

		/**
		 * Result columns.
		 *
		 * @return array
		 */
		private function get_result_columns() {
			return array(
				'input_url'        => __( 'Input URL', 'indexlane-crawl-fetch-inspector' ),
				'http_status'      => __( 'HTTP Status', 'indexlane-crawl-fetch-inspector' ),
				'redirects'        => __( 'Redirects', 'indexlane-crawl-fetch-inspector' ),
				'final_url'        => __( 'Final URL', 'indexlane-crawl-fetch-inspector' ),
				'canonical_url'    => __( 'Canonical URL', 'indexlane-crawl-fetch-inspector' ),
				'meta_robots'      => __( 'Meta Robots', 'indexlane-crawl-fetch-inspector' ),
				'x_robots_tag'     => __( 'X-Robots-Tag', 'indexlane-crawl-fetch-inspector' ),
				'title'            => __( 'Title', 'indexlane-crawl-fetch-inspector' ),
				'meta_description' => __( 'Meta Description', 'indexlane-crawl-fetch-inspector' ),
				'json_ld_count'    => __( 'JSON-LD Count', 'indexlane-crawl-fetch-inspector' ),
				'dev_residue'      => __( 'Dev/Staging Residue', 'indexlane-crawl-fetch-inspector' ),
				'response_time'    => __( 'Response Time', 'indexlane-crawl-fetch-inspector' ),
				'result'           => __( 'Result', 'indexlane-crawl-fetch-inspector' ),
			);
		}

		/**
		 * CSV columns include the detail text shown under result labels.
		 *
		 * @return array
		 */
		private function get_csv_columns() {
			$columns           = $this->get_result_columns();
			$columns['detail'] = __( 'Details', 'indexlane-crawl-fetch-inspector' );

			return $columns;
		}

		/**
		 * Stream CSV response.
		 *
		 * @param array $rows Result rows.
		 */
		private function send_csv( array $rows ) {
			nocache_headers();

			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=indexlane-crawl-fetch-inspector-' . gmdate( 'Ymd-His' ) . '.csv' );

			$output = fopen( 'php://output', 'w' );

			if ( ! $output ) {
				exit;
			}

			$columns = $this->get_csv_columns();
			fputcsv( $output, array_map( array( $this, 'csv_safe' ), array_values( $columns ) ), ',', '"', '' );

			foreach ( $rows as $row ) {
				$line = array();

				foreach ( array_keys( $columns ) as $key ) {
					$line[] = $this->csv_safe( isset( $row[ $key ] ) ? (string) $row[ $key ] : '' );
				}

				fputcsv( $output, $line, ',', '"', '' );
			}

			fclose( $output );
			exit;
		}

		/**
		 * Avoid spreadsheet formula execution on CSV open.
		 *
		 * @param string $value CSV cell value.
		 * @return string
		 */
		private function csv_safe( string $value ): string {
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

			if ( '' !== $value && preg_match( '/^[=+\-@\t]/', $value ) ) {
				return "'" . $value;
			}

			return $value;
		}

		/**
		 * Inline admin-only CSS.
		 */
		private function render_inline_styles() {
			?>
			<style>
				.ilcfi-panel {
					background: #fff;
					border: 1px solid #dcdcde;
					margin: 16px 0 20px;
					padding: 0 16px 6px;
				}
				.ilcfi-inline-check {
					display: inline-block;
					margin: 0 18px 8px 0;
				}
				.ilcfi-limit {
					display: inline-flex;
					align-items: center;
					gap: 8px;
					margin: 0 0 8px;
				}
				.ilcfi-limit input {
					width: 72px;
				}
				.ilcfi-actions {
					display: flex;
					gap: 8px;
				}
				.ilcfi-table-scroll {
					overflow-x: auto;
				}
				.ilcfi-results {
					min-width: 1400px;
				}
				.ilcfi-results th,
				.ilcfi-results td {
					vertical-align: top;
					white-space: normal;
					word-break: break-word;
				}
				.ilcfi-results .ilcfi-col-http_status,
				.ilcfi-results .ilcfi-col-redirects,
				.ilcfi-results .ilcfi-col-title,
				.ilcfi-results .ilcfi-col-meta_description,
				.ilcfi-results .ilcfi-col-json_ld_count,
				.ilcfi-results .ilcfi-col-response_time {
					width: 90px;
				}
				.ilcfi-result {
					border-radius: 3px;
					display: inline-block;
					font-weight: 600;
					line-height: 1.6;
					padding: 1px 7px;
				}
				.ilcfi-result-ok {
					background: #d1e7dd;
					color: #0f5132;
				}
				.ilcfi-result-warning,
				.ilcfi-result-needs-review {
					background: #fff3cd;
					color: #664d03;
				}
				.ilcfi-result-blocked,
				.ilcfi-result-error {
					background: #f8d7da;
					color: #842029;
				}
				.ilcfi-detail {
					color: #646970;
					font-size: 12px;
					margin-top: 4px;
				}
				.ilcfi-scope {
					color: #50575e;
					max-width: 980px;
				}
			</style>
			<?php
		}
	}

	IndexLane_Crawl_Fetch_Inspector::init();
}
