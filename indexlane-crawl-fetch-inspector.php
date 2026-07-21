<?php
/**
 * Plugin Name: IndexLane Crawl Fetch Inspector
 * Plugin URI: https://indexlane.dev/plugins/crawl-fetch-inspector/
 * Description: Inspect crawler-facing HTTP, indexability, sitemap, structured-data and migration evidence from inside WordPress.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: IndexLane
 * Author URI: https://indexlane.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: indexlane-crawl-fetch-inspector
 * Update URI: https://indexlane.dev/plugins/crawl-fetch-inspector/
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-ilcfi-url-helper.php';
require_once __DIR__ . '/includes/class-ilcfi-fetch-client.php';
require_once __DIR__ . '/includes/class-ilcfi-redirect-follower.php';
require_once __DIR__ . '/includes/class-ilcfi-html-parser.php';
require_once __DIR__ . '/includes/class-ilcfi-robots-evaluator.php';
require_once __DIR__ . '/includes/class-ilcfi-sitemap-service.php';
require_once __DIR__ . '/includes/class-ilcfi-evidence-evaluator.php';
require_once __DIR__ . '/includes/class-ilcfi-target-collector.php';
require_once __DIR__ . '/includes/class-ilcfi-report-builder.php';
require_once __DIR__ . '/includes/class-ilcfi-csv-exporter.php';

if ( ! class_exists( 'IndexLane_Crawl_Fetch_Inspector' ) ) {
	/**
	 * Admin controller and backward-compatible plugin facade.
	 */
	final class IndexLane_Crawl_Fetch_Inspector {
		const VERSION            = '0.2.0';
		const SLUG               = 'indexlane-crawl-fetch-inspector';
		const NONCE_ACTION       = 'ilcfi_scan_request';
		const NONCE_FIELD        = 'ilcfi_nonce';
		const AJAX_NONCE_ACTION  = 'ilcfi_ajax_scan';
		const MAX_URLS           = 50;
		const MAX_REDIRECTS      = 5;
		const MAX_RESPONSE_BYTES = 2097152;
		const JOB_TTL            = 3600;
		const REPORT_BATCH_SIZE  = 3;
		const SITEMAP_BATCH_SIZE = 3;

		/** @var ILCFI_URL_Helper */
		private $urls;

		/** @var ILCFI_Fetch_Client */
		private $fetch_client;

		/** @var ILCFI_Redirect_Follower */
		private $redirects;

		/** @var ILCFI_HTML_Parser */
		private $parser;

		/** @var ILCFI_Robots_Evaluator */
		private $robots;

		/** @var ILCFI_Sitemap_Service */
		private $sitemaps;

		/** @var ILCFI_Target_Collector */
		private $targets;

		/** @var ILCFI_Report_Builder */
		private $reports;

		/** @var ILCFI_CSV_Exporter */
		private $exporter;

		/**
		 * Compose services around the shared safe fetch engine.
		 */
		public function __construct() {
			$this->urls         = new ILCFI_URL_Helper();
			$this->fetch_client = new ILCFI_Fetch_Client( self::VERSION, self::MAX_RESPONSE_BYTES );
			$this->redirects    = new ILCFI_Redirect_Follower( $this->fetch_client, $this->urls, self::MAX_REDIRECTS );
			$this->parser       = new ILCFI_HTML_Parser( $this->urls );
			$this->robots       = new ILCFI_Robots_Evaluator( $this->redirects, $this->urls );
			$this->sitemaps     = new ILCFI_Sitemap_Service( $this->redirects, $this->urls );
			$this->targets      = new ILCFI_Target_Collector( $this->urls, self::MAX_URLS );
			$this->reports      = new ILCFI_Report_Builder(
				$this->redirects,
				$this->parser,
				$this->robots,
				$this->sitemaps,
				new ILCFI_Evidence_Evaluator(),
				$this->fetch_client,
				$this->urls
			);
			$this->exporter = new ILCFI_CSV_Exporter();
		}

		/**
		 * Register WordPress hooks.
		 */
		public static function init() {
			$plugin = new self();

			add_action( 'admin_menu', array( $plugin, 'add_admin_page' ) );
			add_action( 'admin_enqueue_scripts', array( $plugin, 'enqueue_admin_assets' ) );
			add_action( 'admin_init', array( $plugin, 'maybe_export_csv' ) );
			add_action( 'wp_ajax_ilcfi_start_scan', array( $plugin, 'ajax_start_scan' ) );
			add_action( 'wp_ajax_ilcfi_scan_batch', array( $plugin, 'ajax_scan_batch' ) );
		}

		/** Add the Tools screen. */
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
		 * Load only this screen's JS/CSS.
		 *
		 * @param string $hook_suffix Admin hook.
		 */
		public function enqueue_admin_assets( $hook_suffix ) {
			if ( 'tools_page_' . self::SLUG !== $hook_suffix ) {
				return;
			}

			wp_enqueue_style(
				'ilcfi-admin',
				plugins_url( 'assets/admin.css', __FILE__ ),
				array(),
				self::VERSION
			);
			wp_enqueue_script(
				'ilcfi-admin',
				plugins_url( 'assets/admin.js', __FILE__ ),
				array(),
				self::VERSION,
				true
			);
			wp_localize_script(
				'ilcfi-admin',
				'ILCFI_SCAN',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( self::AJAX_NONCE_ACTION ),
					'resumeKey' => 'ilcfi-active-job-v020',
					'strings'   => array(
						'starting'   => __( 'Starting scan…', 'indexlane-crawl-fetch-inspector' ),
						'resuming'   => __( 'Resuming the last scan…', 'indexlane-crawl-fetch-inspector' ),
						'complete'   => __( 'Scan complete.', 'indexlane-crawl-fetch-inspector' ),
						'failed'     => __( 'The scan could not continue.', 'indexlane-crawl-fetch-inspector' ),
						'noResults'  => __( 'No page targets were available after sitemap discovery.', 'indexlane-crawl-fetch-inspector' ),
						'evidence'   => __( 'Evidence details', 'indexlane-crawl-fetch-inspector' ),
					),
				)
			);
		}

		/**
		 * Initialize a resumable transient-backed scan job.
		 */
		public function ajax_start_scan() {
			$this->authorize_ajax();
			$request = $this->targets->from_array( $_POST );

			if ( 'manual' === $request['mode'] && empty( $request['items'] ) ) {
				wp_send_json_error( array( 'message' => implode( ' ', $request['messages'] ) ), 400 );
			}

			if ( in_array( $request['mode'], array( 'sitemap', 'launch' ), true ) && '' === $request['sitemap_url'] ) {
				wp_send_json_error( array( 'message' => __( 'A sitemap URL is required for this input mode.', 'indexlane-crawl-fetch-inspector' ) ), 400 );
			}

			$sitemap = $this->sitemaps->create_state( $request['sitemap_url'], $request['sample_limit'] );
			if ( is_wp_error( $sitemap ) ) {
				wp_send_json_error( array( 'message' => $sitemap->get_error_message() ), 400 );
			}

			$token = wp_generate_password( 32, false, false );
			$job   = array(
				'owner'          => get_current_user_id(),
				'created'        => time(),
				'mode'           => $request['mode'],
				'request'        => $request,
				'sitemap'        => $sitemap,
				'phase'          => 'discovering' === $sitemap['status'] ? 'sitemap' : 'inspect',
				'targets'        => 'discovering' === $sitemap['status'] ? array() : $this->prepare_job_targets( $request, $sitemap ),
				'next_index'     => 0,
				'rows'           => array(),
				'robots_contexts' => array(),
			);

			if ( ! $this->save_job( $token, $job, false ) ) {
				wp_send_json_error( array( 'message' => __( 'The scan job could not be saved. No requests were started.', 'indexlane-crawl-fetch-inspector' ) ), 500 );
			}

			wp_send_json_success(
				array(
					'token'    => $token,
					'messages' => $request['messages'],
					'progress' => $this->job_progress( $job ),
				)
			);
		}

		/**
		 * Advance sitemap discovery or inspect a bounded page batch.
		 */
		public function ajax_scan_batch() {
			$this->authorize_ajax();
			$token = isset( $_POST['token'] ) && ! is_array( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			$job   = '' !== $token ? get_transient( $this->job_key( $token ) ) : false;

			if ( ! is_array( $job ) || (int) $job['owner'] !== get_current_user_id() ) {
				wp_send_json_error( array( 'message' => __( 'This scan job expired or does not belong to the current administrator.', 'indexlane-crawl-fetch-inspector' ) ), 404 );
			}
			if ( ! isset( $job['robots_contexts'] ) || ! is_array( $job['robots_contexts'] ) ) {
				$job['robots_contexts'] = array();
			}

			$new_rows = array();

			if ( 'sitemap' === $job['phase'] ) {
				$job['sitemap'] = $this->sitemaps->process_batch( $job['sitemap'], self::SITEMAP_BATCH_SIZE );

				if ( 'ready' === $job['sitemap']['status'] ) {
					$job['targets'] = $this->prepare_job_targets( $job['request'], $job['sitemap'] );
					$job['phase']   = empty( $job['targets'] ) ? 'complete' : 'inspect';
				}
			}

			if ( 'inspect' === $job['phase'] ) {
				$stop = min( count( $job['targets'] ), $job['next_index'] + self::REPORT_BATCH_SIZE );
				while ( $job['next_index'] < $stop ) {
					$item = $job['targets'][ $job['next_index'] ];
					if ( isset( $item['error'] ) ) {
						$row = $this->reports->error_row( $item['input_url'], $item['error'] );
					} else {
						$origin = $this->urls->origin( $item['url'] );
						if ( ! isset( $job['robots_contexts'][ $origin ] ) ) {
							$job['robots_contexts'][ $origin ] = $this->reports->get_robots_context( $item['url'] );
						}
						$row = $this->reports->inspect(
							$item['input_url'],
							$item['url'],
							array(
								'old_domains'    => $job['request']['old_domains'],
								'sitemap_state'  => $job['sitemap'],
								'robots_context' => $job['robots_contexts'][ $origin ],
							)
						);
					}

					$job['rows'][] = $row;
					$new_rows[]    = $row;
					$job['next_index']++;
				}

				if ( $job['next_index'] >= count( $job['targets'] ) ) {
					$job['phase'] = 'complete';
				}
			}

			if ( ! $this->save_job( $token, $job, true ) ) {
				wp_send_json_error( array( 'message' => __( 'The updated scan job could not be saved; processing stopped safely.', 'indexlane-crawl-fetch-inspector' ) ), 500 );
			}

			wp_send_json_success(
				array(
					'token'     => $token,
					'phase'     => $job['phase'],
					'complete'  => 'complete' === $job['phase'],
					'rows'      => $new_rows,
					'allRows'   => 'complete' === $job['phase'] ? $job['rows'] : array(),
					'progress'  => $this->job_progress( $job ),
					'sitemap'   => $this->sitemap_manifest( $job['sitemap'] ),
				)
			);
		}

		/**
		 * Export a completed job using the dedicated exporter.
		 */
		public function maybe_export_csv() {
			if ( ! is_admin() || ! isset( $_GET['page'], $_POST['ilcfi_action'] ) || is_array( $_GET['page'] ) || is_array( $_POST['ilcfi_action'] ) ) {
				return;
			}

			$page   = sanitize_key( wp_unslash( $_GET['page'] ) );
			$action = sanitize_key( wp_unslash( $_POST['ilcfi_action'] ) );
			if ( self::SLUG !== $page || 'export_job' !== $action ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to export crawl evidence.', 'indexlane-crawl-fetch-inspector' ) );
			}

			check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
			$token = isset( $_POST['ilcfi_job_token'] ) && ! is_array( $_POST['ilcfi_job_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ilcfi_job_token'] ) ) : '';
			$job   = '' !== $token ? get_transient( $this->job_key( $token ) ) : false;

			if ( ! is_array( $job ) || (int) $job['owner'] !== get_current_user_id() || 'complete' !== $job['phase'] ) {
				wp_die( esc_html__( 'The completed scan could not be found. It may have expired.', 'indexlane-crawl-fetch-inspector' ) );
			}

			$this->exporter->send( $job['rows'] );
		}

		/**
		 * Render the interactive screen.
		 */
		public function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to run crawl fetch checks.', 'indexlane-crawl-fetch-inspector' ) );
			}

			$request    = $this->targets->defaults();
			$post_types = $this->targets->available_post_types();
			$action_url = admin_url( 'tools.php?page=' . self::SLUG );
			?>
			<div class="wrap ilcfi-wrap">
				<h1><?php esc_html_e( 'IndexLane Crawl Fetch Inspector', 'indexlane-crawl-fetch-inspector' ); ?></h1>
				<p class="description ilcfi-positioning"><?php esc_html_e( 'Inspect crawler-facing HTTP, indexability, sitemap, structured-data and migration evidence from inside WordPress.', 'indexlane-crawl-fetch-inspector' ); ?></p>

				<form id="ilcfi-scan-form" class="ilcfi-panel">
					<fieldset class="ilcfi-mode-picker">
						<legend><?php esc_html_e( 'Input mode', 'indexlane-crawl-fetch-inspector' ); ?></legend>
						<label><input type="radio" name="ilcfi_mode" value="manual" checked> <strong><?php esc_html_e( 'Manual / recent URLs', 'indexlane-crawl-fetch-inspector' ); ?></strong><span><?php esc_html_e( 'Inspect selected WordPress URLs.', 'indexlane-crawl-fetch-inspector' ); ?></span></label>
						<label><input type="radio" name="ilcfi_mode" value="sitemap"> <strong><?php esc_html_e( 'Sitemap sample', 'indexlane-crawl-fetch-inspector' ); ?></strong><span><?php esc_html_e( 'Expand child sitemaps and sample across them.', 'indexlane-crawl-fetch-inspector' ); ?></span></label>
						<label><input type="radio" name="ilcfi_mode" value="launch"> <strong><?php esc_html_e( 'Launch / migration check', 'indexlane-crawl-fetch-inspector' ); ?></strong><span><?php esc_html_e( 'Combine home, recent content, a sitemap sample, and old-domain evidence.', 'indexlane-crawl-fetch-inspector' ); ?></span></label>
					</fieldset>

					<table class="form-table" role="presentation">
						<tbody>
							<tr data-ilcfi-modes="manual">
								<th scope="row"><label for="ilcfi_urls"><?php esc_html_e( 'Manual URLs', 'indexlane-crawl-fetch-inspector' ); ?></label></th>
								<td><textarea name="ilcfi_urls" id="ilcfi_urls" class="large-text code" rows="6" placeholder="<?php echo esc_attr( home_url( "/\n/about/" ) ); ?>"></textarea><p class="description"><?php esc_html_e( 'One same-site absolute URL or root-relative path per line.', 'indexlane-crawl-fetch-inspector' ); ?></p></td>
							</tr>
							<tr data-ilcfi-modes="manual sitemap launch">
								<th scope="row"><?php esc_html_e( 'Recent content', 'indexlane-crawl-fetch-inspector' ); ?></th>
								<td>
									<?php foreach ( $post_types as $post_type => $label ) : ?>
										<label class="ilcfi-inline-check"><input type="checkbox" name="ilcfi_post_types[]" value="<?php echo esc_attr( $post_type ); ?>"> <?php echo esc_html( $label ); ?></label>
									<?php endforeach; ?>
									<label class="ilcfi-limit"><?php esc_html_e( 'Limit per type', 'indexlane-crawl-fetch-inspector' ); ?> <input type="number" name="ilcfi_recent_limit" min="1" max="50" value="10"></label>
								</td>
							</tr>
							<tr data-ilcfi-modes="manual sitemap launch">
								<th scope="row"><label for="ilcfi_sitemap_url"><?php esc_html_e( 'Sitemap URL', 'indexlane-crawl-fetch-inspector' ); ?></label></th>
								<td><input type="url" class="regular-text code" id="ilcfi_sitemap_url" name="ilcfi_sitemap_url" value="<?php echo esc_attr( $request['sitemap_url'] ); ?>"><p class="description"><?php esc_html_e( 'Optional for manual mode. Membership becomes Unknown—incomplete if any bounded sitemap evidence is missing.', 'indexlane-crawl-fetch-inspector' ); ?></p></td>
							</tr>
							<tr data-ilcfi-modes="sitemap launch">
								<th scope="row"><label for="ilcfi_sample_limit"><?php esc_html_e( 'Sample size', 'indexlane-crawl-fetch-inspector' ); ?></label></th>
								<td><input type="number" id="ilcfi_sample_limit" name="ilcfi_sample_limit" min="1" max="50" value="20"><p class="description"><?php esc_html_e( 'Samples are distributed across discovered child sitemaps, up to the safety limits.', 'indexlane-crawl-fetch-inspector' ); ?></p></td>
							</tr>
							<tr data-ilcfi-modes="manual launch">
								<th scope="row"><label for="ilcfi_old_domains"><?php esc_html_e( 'Old domains', 'indexlane-crawl-fetch-inspector' ); ?></label></th>
								<td><textarea name="ilcfi_old_domains" id="ilcfi_old_domains" class="large-text code" rows="3" placeholder="old-example.com"></textarea><p class="description"><?php esc_html_e( 'Optional, one hostname per line. Only URL-bearing fields are inspected; ordinary page copy is not keyword-scanned.', 'indexlane-crawl-fetch-inspector' ); ?></p></td>
							</tr>
						</tbody>
					</table>

					<p class="submit"><button type="submit" class="button button-primary" id="ilcfi-run"><?php esc_html_e( 'Start inspection', 'indexlane-crawl-fetch-inspector' ); ?></button></p>
					<noscript><p class="notice notice-error inline"><?php esc_html_e( 'JavaScript is required for safe batched and resumable scans.', 'indexlane-crawl-fetch-inspector' ); ?></p></noscript>
				</form>

				<section id="ilcfi-progress" class="ilcfi-progress" hidden aria-live="polite">
					<h2><?php esc_html_e( 'Scan progress', 'indexlane-crawl-fetch-inspector' ); ?></h2>
					<progress id="ilcfi-progress-bar" max="100" value="0"></progress>
					<p id="ilcfi-progress-text"></p>
					<div id="ilcfi-messages"></div>
				</section>

				<section id="ilcfi-results-section" hidden>
					<div class="ilcfi-results-heading"><h2><?php esc_html_e( 'Crawler-facing evidence report', 'indexlane-crawl-fetch-inspector' ); ?></h2>
						<form method="post" action="<?php echo esc_url( $action_url ); ?>" id="ilcfi-export-form" hidden>
							<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
							<input type="hidden" name="ilcfi_action" value="export_job">
							<input type="hidden" name="ilcfi_job_token" id="ilcfi-job-token" value="">
							<button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'indexlane-crawl-fetch-inspector' ); ?></button>
						</form>
					</div>
					<div id="ilcfi-sitemap-manifest"></div>
					<div id="ilcfi-results"></div>
				</section>

				<p class="ilcfi-scope"><?php esc_html_e( 'Same-site, read-only checks only. Results describe observed evidence and never claim that analytics fired, a page ranks, or a crawler indexed it.', 'indexlane-crawl-fetch-inspector' ); ?></p>
			</div>
			<?php
		}

		/** Authorize an AJAX scan mutation. */
		private function authorize_ajax() {
			check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to run this inspection.', 'indexlane-crawl-fetch-inspector' ) ), 403 );
			}
		}

		/** @param string $token Opaque token. @return string */
		private function job_key( $token ) {
			return 'ilcfi_job_' . hash( 'sha256', (string) $token );
		}

		/**
		 * Save a job, accepting WordPress's false no-change response only when the
		 * stored value is demonstrably identical.
		 *
		 * @param string $token Job token.
		 * @param array  $job Job state.
		 * @param bool   $allow_identical Whether an identical existing value is valid.
		 * @return bool
		 */
		private function save_job( $token, array $job, $allow_identical ) {
			$key = $this->job_key( $token );
			if ( set_transient( $key, $job, self::JOB_TTL ) ) {
				return true;
			}

			return $allow_identical && get_transient( $key ) === $job;
		}

		/**
		 * Combine direct and sitemap targets for the selected mode.
		 *
		 * @param array $request Request.
		 * @param array $sitemap Sitemap state.
		 * @return array
		 */
		private function prepare_job_targets( array $request, array $sitemap ) {
			$items = in_array( $request['mode'], array( 'manual', 'launch' ), true ) ? $request['items'] : array();
			if ( in_array( $request['mode'], array( 'sitemap', 'launch' ), true ) ) {
				foreach ( $sitemap['sample_targets'] as $url ) {
					$items[] = array( 'input_url' => $url, 'url' => $url, 'source' => 'sitemap' );
				}
			}

			$output = array();
			$seen   = array();
			foreach ( $items as $item ) {
				$key = empty( $item['url'] ) ? 'error:' . $item['input_url'] : $this->urls->normalize_for_compare( $item['url'] );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$output[]     = $item;
				if ( count( $output ) >= self::MAX_URLS ) {
					break;
				}
			}

			return $output;
		}

		/** @param array $job Job. @return array */
		private function job_progress( array $job ) {
			if ( 'sitemap' === $job['phase'] ) {
				return array(
					'percent' => min( 45, 5 + (int) $job['sitemap']['files_checked'] * 2 ),
					'text'    => sprintf(
						/* translators: 1: checked sitemap files, 2: queued files. */
						__( 'Discovering sitemaps: %1$d checked, %2$d queued.', 'indexlane-crawl-fetch-inspector' ),
						$job['sitemap']['files_checked'],
						count( $job['sitemap']['queue'] )
					),
				);
			}

			$total   = count( $job['targets'] );
			$current = (int) $job['next_index'];
			$percent = 100;
			if ( $total > 0 && 'complete' !== $job['phase'] ) {
				$percent = 45 + (int) floor( 55 * $current / $total );
			}

			return array(
				'percent' => $percent,
				'text'    => sprintf(
					/* translators: 1: inspected pages, 2: total pages. */
					__( 'Inspected %1$d of %2$d page targets.', 'indexlane-crawl-fetch-inspector' ),
					$current,
					$total
				),
			);
		}

		/** @param array $state Sitemap state. @return array */
		private function sitemap_manifest( array $state ) {
			return array(
				'checked'       => ! empty( $state['checked'] ),
				'completeness'  => $state['completeness'],
				'filesChecked'  => $state['files_checked'],
				'filesFailed'   => count( $state['files_failed'] ),
				'truncated'     => count( $state['truncated'] ),
				'omittedFiles'  => $state['omitted_files'],
				'omittedUrls'   => $state['omitted_urls'],
				'urlsObserved'  => count( $state['urls'] ),
			);
		}

		/* Compatibility delegates retained for the v0.1 behavioral contract. */
		private function fetch_url( $url ) {
			return $this->redirects->fetch( $url );
		}

		private function normalize_url_for_compare( $url ) {
			return $this->urls->normalize_for_compare( $url );
		}

		private function inspect_url( $input_url, $url ) {
			return $this->reports->inspect( $input_url, $url );
		}

		private function analyze_html( $body, $final_url ) {
			return $this->parser->analyze( $body, $final_url );
		}

		private function response_body_was_truncated( array $response ) {
			return $this->fetch_client->was_truncated( $response );
		}
	}

	IndexLane_Crawl_Fetch_Inspector::init();
}
