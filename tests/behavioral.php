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
