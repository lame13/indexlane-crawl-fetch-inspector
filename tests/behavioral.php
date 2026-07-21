<?php
/**
 * Lightweight behavioral coverage for redirect and response-boundary handling.
 *
 * Run with: php tests/behavioral.php
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function add_action() {}

function __( $text ) {
	return $text;
}

function _n( $single, $plural, $number ) {
	return 1 === (int) $number ? $single : $plural;
}

function home_url( $path = '/' ) {
	return 'https://example.test/' . ltrim( $path, '/' );
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}

function esc_url_raw( $value ) {
	return $value;
}

function get_bloginfo( $key ) {
	return 'charset' === $key ? 'UTF-8' : '';
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $name ) {
	$name = strtolower( $name );

	foreach ( isset( $response['headers'] ) ? $response['headers'] : array() as $header_name => $value ) {
		if ( strtolower( $header_name ) === $name ) {
			return $value;
		}
	}

	return '';
}

function wp_safe_remote_get( $url, array $args ) {
	$GLOBALS['ilcfi_http_calls'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	if ( empty( $GLOBALS['ilcfi_http_responses'][ $url ] ) ) {
		return new WP_Error( 'unexpected_request', 'Unexpected request: ' . $url );
	}

	return array_shift( $GLOBALS['ilcfi_http_responses'][ $url ] );
}

require dirname( __DIR__ ) . '/indexlane-crawl-fetch-inspector.php';

function ilcfi_response( $status, array $headers = array(), $body = '' ) {
	return array(
		'response' => array( 'code' => $status ),
		'headers'  => $headers,
		'body'     => $body,
	);
}

function ilcfi_queue_responses( array $responses ) {
	$GLOBALS['ilcfi_http_calls']     = array();
	$GLOBALS['ilcfi_http_responses'] = $responses;
}

function ilcfi_invoke( $object, $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );

	return $reflection->invokeArgs( $object, $arguments );
}

function ilcfi_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
		);
	}
}

function ilcfi_assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function ilcfi_assert_contains( $needle, $haystack, $message ) {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new RuntimeException( $message . '\nMissing: ' . $needle . '\nActual: ' . $haystack );
	}
}

$tests = array(
	'ordinary trailing-slash redirects reach the destination' => function () {
		$plugin = new IndexLane_Crawl_Fetch_Inspector();

		ilcfi_queue_responses(
			array(
				'https://example.test/foo'  => array(
					ilcfi_response( 301, array( 'Location' => '/foo/' ) ),
				),
				'https://example.test/foo/' => array(
					ilcfi_response( 200, array( 'Content-Type' => 'text/html' ), '<title>Foo</title>' ),
				),
			)
		);

		$fetch = ilcfi_invoke( $plugin, 'fetch_url', array( 'https://example.test/foo' ) );

		ilcfi_assert_same( false, $fetch['redirect_loop'], 'A trailing-slash redirect must not be called a loop.' );
		ilcfi_assert_same( 1, $fetch['redirect_count'], 'The redirect hop should be counted.' );
		ilcfi_assert_same( 'https://example.test/foo/', $fetch['final_url'], 'The slash destination should be fetched and reported.' );
		ilcfi_assert_same( 200, wp_remote_retrieve_response_code( $fetch['response'] ), 'The destination response should be returned.' );
		ilcfi_assert_same( 2, count( $GLOBALS['ilcfi_http_calls'] ), 'Both redirect source and destination should be fetched.' );
		ilcfi_assert_same( 0, $GLOBALS['ilcfi_http_calls'][0]['args']['redirection'], 'WordPress automatic redirects must stay disabled.' );
		ilcfi_assert_same( IndexLane_Crawl_Fetch_Inspector::MAX_RESPONSE_BYTES, $GLOBALS['ilcfi_http_calls'][0]['args']['limit_response_size'], 'Every request should be bounded.' );
	},
	'/foo and /foo/ remain distinct URL identities' => function () {
		$plugin   = new IndexLane_Crawl_Fetch_Inspector();
		$without = ilcfi_invoke( $plugin, 'normalize_url_for_compare', array( 'https://example.test/foo' ) );
		$with    = ilcfi_invoke( $plugin, 'normalize_url_for_compare', array( 'https://example.test/foo/' ) );

		ilcfi_assert_true( $without !== $with, 'Trailing-slash variants must not be deduplicated.' );
	},
	'same-site URL policy rejects unexpected service ports' => function () {
		$urls = new ILCFI_URL_Helper();

		ilcfi_assert_same( true, $urls->is_same_site( 'http://example.test/page/' ), 'The ordinary HTTP port should remain available for HTTP-to-HTTPS checks.' );
		ilcfi_assert_same( false, $urls->is_same_site( 'https://example.test:3306/page/' ), 'An unrelated port on the site hostname must not enter the HTTP fetch scope.' );
	},
	'www canonical-host redirects reach the destination' => function () {
		$plugin = new IndexLane_Crawl_Fetch_Inspector();

		ilcfi_queue_responses(
			array(
				'https://example.test/page'     => array(
					ilcfi_response( 301, array( 'Location' => 'https://www.example.test/page' ) ),
				),
				'https://www.example.test/page' => array(
					ilcfi_response( 200, array( 'Content-Type' => 'text/html' ), '<title>Canonical host</title>' ),
				),
			)
		);

		$fetch = ilcfi_invoke( $plugin, 'fetch_url', array( 'https://example.test/page' ) );

		ilcfi_assert_same( false, $fetch['redirect_loop'], 'A www canonical-host redirect must not be called a loop.' );
		ilcfi_assert_same( 'https://www.example.test/page', $fetch['final_url'], 'The canonical-host destination should be fetched.' );
		ilcfi_assert_same( 2, count( $GLOBALS['ilcfi_http_calls'] ), 'Both host variants should be fetched once.' );
	},
	'genuine redirect loops still stop before refetching' => function () {
		$plugin = new IndexLane_Crawl_Fetch_Inspector();

		ilcfi_queue_responses(
			array(
				'https://example.test/a' => array(
					ilcfi_response( 302, array( 'Location' => '/b' ) ),
				),
				'https://example.test/b' => array(
					ilcfi_response( 302, array( 'Location' => '/a' ) ),
				),
			)
		);

		$fetch = ilcfi_invoke( $plugin, 'fetch_url', array( 'https://example.test/a' ) );

		ilcfi_assert_same( true, $fetch['redirect_loop'], 'A repeated exact URL should still be detected as a loop.' );
		ilcfi_assert_same( 2, $fetch['redirect_count'], 'Both loop hops should be counted.' );
		ilcfi_assert_same( 2, count( $GLOBALS['ilcfi_http_calls'] ), 'The repeated URL must not be fetched again.' );
	},
	'terminal 3xx responses cannot be classified as OK' => function () {
		$plugin = new IndexLane_Crawl_Fetch_Inspector();

		ilcfi_queue_responses(
			array(
				'https://example.test/no-location' => array(
					ilcfi_response( 301, array( 'Content-Type' => 'text/html' ), '<title>Misleading redirect body</title>' ),
				),
			)
		);

		$row = ilcfi_invoke( $plugin, 'inspect_url', array( 'https://example.test/no-location', 'https://example.test/no-location' ) );

		ilcfi_assert_same( '301', $row['http_status'], 'The terminal redirect status should be preserved.' );
		ilcfi_assert_same( 'Needs review', $row['result'], 'A redirect without a destination must require review.' );
		ilcfi_assert_same( 'Not checked', $row['title'], 'HTML signals from a redirect response must not be treated as final-page evidence.' );
		ilcfi_assert_contains( 'did not provide a redirect target', $row['detail'], 'The missing redirect destination should be explained.' );
	},
	'external redirect targets are never fetched' => function () {
		$plugin = new IndexLane_Crawl_Fetch_Inspector();

		ilcfi_queue_responses(
			array(
				'https://example.test/leaves-site' => array(
					ilcfi_response( 302, array( 'Location' => 'https://external.test/landing' ) ),
				),
			)
		);

		$fetch = ilcfi_invoke( $plugin, 'fetch_url', array( 'https://example.test/leaves-site' ) );

		ilcfi_assert_same( true, $fetch['redirect_left_site'], 'The external target should be marked as out of scope.' );
		ilcfi_assert_same( 1, count( $GLOBALS['ilcfi_http_calls'] ), 'Only the same-site source should be fetched.' );
	},
	'bodies at the response limit are reported as incomplete evidence' => function () {
		$plugin = new IndexLane_Crawl_Fetch_Inspector();
		$body   = str_repeat( 'x', IndexLane_Crawl_Fetch_Inspector::MAX_RESPONSE_BYTES );

		ilcfi_queue_responses(
			array(
				'https://example.test/large' => array(
					ilcfi_response( 200, array( 'Content-Type' => 'text/html' ), $body ),
				),
			)
		);

		$row = ilcfi_invoke( $plugin, 'inspect_url', array( 'https://example.test/large', 'https://example.test/large' ) );

		ilcfi_assert_same( 'Needs review', $row['result'], 'A body that reaches the fetch limit must not be classified as complete.' );
		ilcfi_assert_same( 'Not checked', $row['title'], 'Incomplete HTML must not produce absence claims.' );
		ilcfi_assert_same( 'Not checked', $row['json_ld_count'], 'Incomplete HTML must not claim that no JSON-LD was found.' );
		ilcfi_assert_same( 'Not checked', $row['dev_residue'], 'Incomplete HTML must not claim that no dev residue was found.' );
		ilcfi_assert_contains( 'Unknown—not checked', $row['robots_directives'], 'Incomplete HTML must not claim that no HTML robots directive was found.' );
		ilcfi_assert_contains( '2 MB safety limit', $row['detail'], 'The response bound should be visible to the user.' );
	},
	'unencoded short bodies are checked against Content-Length' => function () {
		$plugin = new IndexLane_Crawl_Fetch_Inspector();

		ilcfi_queue_responses(
			array(
				'https://example.test/short' => array(
					ilcfi_response( 200, array( 'Content-Length' => '100' ), 'short' ),
				),
			)
		);

		$fetch = ilcfi_invoke( $plugin, 'fetch_url', array( 'https://example.test/short' ) );

		ilcfi_assert_same( true, $fetch['body_truncated'], 'A body shorter than an unencoded declared length should be marked incomplete.' );
	},
	'compressed Content-Length is not compared to decompressed body size' => function () {
		$plugin = new IndexLane_Crawl_Fetch_Inspector();

		ilcfi_queue_responses(
			array(
				'https://example.test/compressed' => array(
					ilcfi_response(
						200,
						array(
							'Content-Encoding' => 'gzip',
							'Content-Length'   => '100',
						),
						'short decompressed body'
					),
				),
			)
		);

		$fetch = ilcfi_invoke( $plugin, 'fetch_url', array( 'https://example.test/compressed' ) );

		ilcfi_assert_same( false, $fetch['body_truncated'], 'Compressed wire length must not be compared with a decompressed body.' );
	},
	'Googlebot-specific robots groups override wildcard groups' => function () {
		$urls       = new ILCFI_URL_Helper();
		$client     = new ILCFI_Fetch_Client( 'test', IndexLane_Crawl_Fetch_Inspector::MAX_RESPONSE_BYTES );
		$redirects  = new ILCFI_Redirect_Follower( $client, $urls, IndexLane_Crawl_Fetch_Inspector::MAX_REDIRECTS );
		$robots     = new ILCFI_Robots_Evaluator( $redirects, $urls );
		$groups     = $robots->parse( file_get_contents( __DIR__ . '/fixtures/robots-googlebot.txt' ) );
		$context    = array( 'status' => 'ok', 'groups' => $groups );
		$ordinary   = $robots->evaluate_googlebot( 'https://example.test/ordinary/', $context );
		$private    = $robots->evaluate_googlebot( 'https://example.test/private/file/', $context );
		$public     = $robots->evaluate_googlebot( 'https://example.test/private/public/item/', $context );
		$reports    = $robots->evaluate_googlebot( 'https://example.test/private/public/reports/today/', $context );

		ilcfi_assert_same( 'Allowed', $ordinary['state'], 'A specific Googlebot group must take precedence over the wildcard group.' );
		ilcfi_assert_same( 'Crawl blocked', $private['state'], 'The matching Googlebot disallow must be reported as a crawl block.' );
		ilcfi_assert_same( 'Allowed', $public['state'], 'The longer Allow rule must win.' );
		ilcfi_assert_same( 'Crawl blocked', $reports['state'], 'An even longer Disallow rule must win for its subtree.' );
		ilcfi_assert_same( 'googlebot', $private['agent'], 'The effective crawler-specific group should be reported.' );
	},
	'robots fetch failures remain Unknown' => function () {
		$urls       = new ILCFI_URL_Helper();
		$client     = new ILCFI_Fetch_Client( 'test', IndexLane_Crawl_Fetch_Inspector::MAX_RESPONSE_BYTES );
		$redirects  = new ILCFI_Redirect_Follower( $client, $urls, IndexLane_Crawl_Fetch_Inspector::MAX_REDIRECTS );
		$robots     = new ILCFI_Robots_Evaluator( $redirects, $urls );

		ilcfi_queue_responses( array() );
		$context = $robots->get_context( 'https://example.test/page/' );
		$result  = $robots->evaluate_googlebot( 'https://example.test/page/', $context );

		ilcfi_assert_same( 'Unknown', $result['state'], 'A robots.txt fetch failure must never be treated as allowed or OK.' );
		ilcfi_assert_same( false, $result['complete'], 'Failed robots evidence must be incomplete.' );
	},
	'crawler-targeted HTML and HTTP robots directives do not leak across agents' => function () {
		$parser    = new ILCFI_HTML_Parser( new ILCFI_URL_Helper() );
		$evaluator = new ILCFI_Evidence_Evaluator();
		$analysis  = $parser->analyze(
			'<html><head><meta name="robots" content="index,follow"><meta name="googlebot-news" content="noindex"></head><body></body></html>',
			'https://example.test/news/'
		);

		ilcfi_assert_same( 'robots: index,follow', $analysis['effective_meta_robots'], 'Googlebot-News directives must not be applied to general Googlebot.' );
		ilcfi_assert_same( false, $evaluator->has_noindex( $analysis['effective_meta_robots'], 'otherbot: noindex' ), 'Another crawler\'s X-Robots-Tag must not block Googlebot.' );
		ilcfi_assert_same( true, $evaluator->has_noindex( $analysis['effective_meta_robots'], 'googlebot: noindex' ), 'A Googlebot-targeted X-Robots-Tag must apply.' );
	},
	'sitemap indexes expand and samples alternate across children' => function () {
		$urls       = new ILCFI_URL_Helper();
		$client     = new ILCFI_Fetch_Client( 'test', IndexLane_Crawl_Fetch_Inspector::MAX_RESPONSE_BYTES );
		$redirects  = new ILCFI_Redirect_Follower( $client, $urls, IndexLane_Crawl_Fetch_Inspector::MAX_REDIRECTS );
		$sitemaps   = new ILCFI_Sitemap_Service( $redirects, $urls );

		ilcfi_queue_responses(
			array(
				'https://example.test/sitemap.xml' => array( ilcfi_response( 200, array( 'Content-Type' => 'application/xml' ), file_get_contents( __DIR__ . '/fixtures/sitemap-index.xml' ) ) ),
				'https://example.test/posts-sitemap.xml' => array( ilcfi_response( 200, array( 'Content-Type' => 'application/xml' ), file_get_contents( __DIR__ . '/fixtures/posts-sitemap.xml' ) ) ),
				'https://example.test/pages-sitemap.xml' => array( ilcfi_response( 200, array( 'Content-Type' => 'application/xml' ), file_get_contents( __DIR__ . '/fixtures/pages-sitemap.xml' ) ) ),
			)
		);

		$state = $sitemaps->create_state( 'https://example.test/sitemap.xml', 4 );
		while ( 'discovering' === $state['status'] ) {
			$state = $sitemaps->process_batch( $state, 1 );
		}

		ilcfi_assert_same( 3, $state['files_checked'], 'The index and both child sitemaps should be expanded automatically.' );
		ilcfi_assert_same( 'complete', $state['completeness'], 'All sitemap evidence should be complete.' );
		ilcfi_assert_same(
			array(
				'https://example.test/post-one/',
				'https://example.test/about/',
				'https://example.test/post-two/',
				'https://example.test/contact/',
			),
			$state['sample_targets'],
			'The sample should round-robin across child sitemaps.'
		);
		ilcfi_assert_same( 'Yes', $sitemaps->membership( 'https://example.test/privacy/', $state ), 'An observed URL should have Yes membership.' );
		ilcfi_assert_same( 'No', $sitemaps->membership( 'https://example.test/missing/', $state ), 'A complete sitemap set can support a No membership result.' );
	},
	'incomplete child sitemap evidence never produces No membership' => function () {
		$urls       = new ILCFI_URL_Helper();
		$client     = new ILCFI_Fetch_Client( 'test', IndexLane_Crawl_Fetch_Inspector::MAX_RESPONSE_BYTES );
		$redirects  = new ILCFI_Redirect_Follower( $client, $urls, IndexLane_Crawl_Fetch_Inspector::MAX_REDIRECTS );
		$sitemaps   = new ILCFI_Sitemap_Service( $redirects, $urls );

		ilcfi_queue_responses(
			array(
				'https://example.test/sitemap.xml' => array( ilcfi_response( 200, array(), file_get_contents( __DIR__ . '/fixtures/sitemap-index.xml' ) ) ),
				'https://example.test/posts-sitemap.xml' => array( ilcfi_response( 200, array(), file_get_contents( __DIR__ . '/fixtures/posts-sitemap.xml' ) ) ),
			)
		);

		$state = $sitemaps->create_state( 'https://example.test/sitemap.xml', 4 );
		while ( 'discovering' === $state['status'] ) {
			$state = $sitemaps->process_batch( $state, 3 );
		}

		ilcfi_assert_same( 'partial', $state['completeness'], 'A failed child must make the sitemap manifest partial.' );
		ilcfi_assert_same( 'Yes', $sitemaps->membership( 'https://example.test/post-one/', $state ), 'Positive observed membership remains usable.' );
		ilcfi_assert_same( 'Unknown—incomplete', $sitemaps->membership( 'https://example.test/unseen/', $state ), 'Missing membership must remain Unknown when any child evidence failed.' );
	},
	'JSON-LD and migration evidence preserve exact URL contexts' => function () {
		$parser   = new ILCFI_HTML_Parser( new ILCFI_URL_Helper() );
		$analysis = $parser->analyze(
			file_get_contents( __DIR__ . '/fixtures/schema-migration-evidence.html' ),
			'https://example.test/product/',
			array( 'old.example' )
		);

		ilcfi_assert_same( 2, $analysis['json_ld_count'], 'Both JSON-LD blocks should be counted.' );
		ilcfi_assert_same( 1, $analysis['json_ld_malformed'], 'HTML entity decoding must not make the malformed block valid.' );
		ilcfi_assert_same( array( 'Offer', 'Product', 'Thing' ), $analysis['json_ld_types'], 'All unique @type values should be inventoried without duplicate-type warnings.' );
		ilcfi_assert_same( array( 'https://old.example/#product' ), $analysis['duplicate_json_ld_ids'], 'Duplicate @id values should be detected.' );

		$contexts = array();
		$values   = array();
		foreach ( $analysis['residue_evidence'] as $evidence ) {
			$contexts[] = $evidence['context'];
			$values[]   = $evidence['matched_value'];
		}

		ilcfi_assert_true( in_array( 'canonical[href]', $contexts, true ), 'Canonical URL context should be preserved.' );
		ilcfi_assert_true( in_array( 'meta[og:url]', $contexts, true ), 'Open Graph URL context should be preserved.' );
		ilcfi_assert_true( in_array( 'style element url()', $contexts, true ), 'Inline CSS URL context should be preserved.' );
		ilcfi_assert_true( in_array( 'a[href]', $contexts, true ), 'href evidence should be preserved.' );
		ilcfi_assert_true( in_array( 'https://old.example/support/', $values, true ), 'The exact matched old-domain value should be preserved.' );
		ilcfi_assert_true( false === strpos( implode( ' ', $values ), 'ordinary page copy' ), 'Generic staging words in page copy must not be scanned as evidence.' );
	},
	'page fetch failures produce Failed evidence and an Unknown result' => function () {
		$plugin = new IndexLane_Crawl_Fetch_Inspector();
		ilcfi_queue_responses( array() );

		$row = ilcfi_invoke( $plugin, 'inspect_url', array( 'https://example.test/failure/', 'https://example.test/failure/' ) );

		ilcfi_assert_same( 'Unknown', $row['result'], 'A page fetch failure must never be classified as OK.' );
		ilcfi_assert_same( 'Failed', $row['evidence_completeness'], 'No page response means evidence failed.' );
	},
	'assembled reports expose every requested evidence section' => function () {
		$urls       = new ILCFI_URL_Helper();
		$client     = new ILCFI_Fetch_Client( 'test', IndexLane_Crawl_Fetch_Inspector::MAX_RESPONSE_BYTES );
		$redirects  = new ILCFI_Redirect_Follower( $client, $urls, IndexLane_Crawl_Fetch_Inspector::MAX_REDIRECTS );
		$robots     = new ILCFI_Robots_Evaluator( $redirects, $urls );
		$sitemaps   = new ILCFI_Sitemap_Service( $redirects, $urls );
		$reports    = new ILCFI_Report_Builder( $redirects, new ILCFI_HTML_Parser( $urls ), $robots, $sitemaps, new ILCFI_Evidence_Evaluator(), $client, $urls );
		$page_url   = 'https://example.test/product/';
		$robots_context = array(
			'status' => 'ok',
			'groups' => $robots->parse( file_get_contents( __DIR__ . '/fixtures/robots-googlebot.txt' ) ),
		);
		$sitemap_state = array(
			'checked'      => true,
			'status'       => 'ready',
			'completeness' => 'complete',
			'urls'         => array( $urls->normalize_for_compare( $page_url ) => true ),
		);

		ilcfi_queue_responses(
			array(
				$page_url => array(
					ilcfi_response( 200, array( 'Content-Type' => 'text/html', 'X-Robots-Tag' => 'index, follow' ), file_get_contents( __DIR__ . '/fixtures/schema-migration-evidence.html' ) ),
				),
			)
		);

		$row = $reports->inspect(
			$page_url,
			$page_url,
			array(
				'old_domains'    => array( 'old.example' ),
				'robots_context' => $robots_context,
				'sitemap_state'  => $sitemap_state,
			)
		);

		ilcfi_assert_same( '200', $row['http_status'], 'HTTP evidence should be present.' );
		ilcfi_assert_contains( '[200]', $row['redirect_chain'], 'The observed chain should include the terminal response.' );
		ilcfi_assert_contains( 'staging.example.net', $row['canonical_url'], 'Canonical evidence should be present.' );
		ilcfi_assert_contains( 'Allowed', $row['robots_txt'], 'Effective Googlebot robots evidence should be present.' );
		ilcfi_assert_same( 'Yes', $row['sitemap_membership'], 'Sitemap membership should be present.' );
		ilcfi_assert_same( '1 malformed of 2 blocks', $row['json_ld_validity'], 'JSON-LD validity should be present.' );
		ilcfi_assert_contains( 'Product', $row['json_ld_types'], 'JSON-LD type inventory should be present.' );
		ilcfi_assert_contains( 'old.example', $row['old_domain_evidence'], 'Migration evidence should be present.' );
		ilcfi_assert_same( 'Complete', $row['evidence_completeness'], 'Complete evidence can still contain review findings.' );
		ilcfi_assert_same( 'Needs review', $row['result'], 'Canonical, malformed schema, and migration findings should require review.' );
	},
);

$failures = 0;

foreach ( $tests as $name => $test ) {
	try {
		$test();
		echo 'PASS: ' . $name . PHP_EOL;
	} catch ( Throwable $error ) {
		$failures++;
		fwrite( STDERR, 'FAIL: ' . $name . PHP_EOL . $error->getMessage() . PHP_EOL );
	}
}

if ( $failures > 0 ) {
	exit( 1 );
}

echo count( $tests ) . ' behavioral tests passed.' . PHP_EOL;
