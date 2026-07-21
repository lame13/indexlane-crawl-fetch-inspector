<?php
/**
 * URL validation and comparison helpers.
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps URL policy in one place for fetches, redirects, sitemaps, and evidence.
 */
final class ILCFI_URL_Helper {
	/**
	 * Normalize administrator input into a safe same-site URL.
	 *
	 * @param string $value URL or root-relative path.
	 * @return string|WP_Error
	 */
	public function prepare_same_site_url( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return new WP_Error( 'ilcfi_empty_url', __( 'The URL was empty.', 'indexlane-crawl-fetch-inspector' ) );
		}

		if ( preg_match( '/[\r\n]/', $value ) ) {
			return new WP_Error( 'ilcfi_invalid_url', __( 'The URL contained an invalid line break.', 'indexlane-crawl-fetch-inspector' ) );
		}

		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
			$value = home_url( '/' . ltrim( $value, '/' ) );
		}

		$value = esc_url_raw( $value );
		$parts = wp_parse_url( $value );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'ilcfi_invalid_url', __( 'Enter a valid absolute URL or same-site path.', 'indexlane-crawl-fetch-inspector' ) );
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'ilcfi_invalid_scheme', __( 'Only http and https URLs can be checked.', 'indexlane-crawl-fetch-inspector' ) );
		}

		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return new WP_Error( 'ilcfi_url_credentials', __( 'URLs containing credentials are not checked.', 'indexlane-crawl-fetch-inspector' ) );
		}

		if ( ! $this->is_same_site( $value ) ) {
			return new WP_Error( 'ilcfi_external_url', __( 'Skipped. Only URLs on this WordPress site can be fetched.', 'indexlane-crawl-fetch-inspector' ) );
		}

		return $this->remove_fragment( $value );
	}

	/**
	 * Resolve a Location header or document-relative URL.
	 *
	 * @param string $location Relative or absolute URL.
	 * @param string $base_url Base URL.
	 * @return string|WP_Error
	 */
	public function make_absolute( $location, $base_url ) {
		$location = trim( (string) $location );

		if ( '' === $location || preg_match( '/[\r\n]/', $location ) ) {
			return new WP_Error( 'ilcfi_invalid_location', __( 'Redirect target was empty or invalid.', 'indexlane-crawl-fetch-inspector' ) );
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $location ) ) {
			$url = $location;
		} else {
			$base = wp_parse_url( $base_url );

			if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
				return new WP_Error( 'ilcfi_invalid_base', __( 'Could not resolve a relative URL.', 'indexlane-crawl-fetch-inspector' ) );
			}

			$origin = strtolower( $base['scheme'] ) . '://' . $base['host'];
			if ( ! empty( $base['port'] ) ) {
				$origin .= ':' . (int) $base['port'];
			}

			if ( 0 === strpos( $location, '//' ) ) {
				$url = strtolower( $base['scheme'] ) . ':' . $location;
			} elseif ( 0 === strpos( $location, '/' ) ) {
				$url = $origin . $location;
			} elseif ( 0 === strpos( $location, '?' ) ) {
				$url = $origin . ( isset( $base['path'] ) ? $base['path'] : '/' ) . $location;
			} elseif ( 0 === strpos( $location, '#' ) ) {
				$url = $origin . ( isset( $base['path'] ) ? $base['path'] : '/' ) . $location;
			} else {
				$base_path = isset( $base['path'] ) ? $base['path'] : '/';
				$directory = preg_replace( '#/[^/]*$#', '/', $base_path );
				$url       = $origin . $this->normalize_path( $directory . $location );
			}
		}

		$url   = esc_url_raw( $url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'ilcfi_invalid_resolved_url', __( 'Resolved URL was not a valid http or https URL.', 'indexlane-crawl-fetch-inspector' ) );
		}

		return $this->remove_fragment( $url );
	}

	/**
	 * Whether a URL belongs to the WordPress home or site host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_same_site( $url ) {
		$url_parts = wp_parse_url( $url );
		if ( ! is_array( $url_parts ) || empty( $url_parts['host'] ) || empty( $url_parts['scheme'] ) ) {
			return false;
		}

		$site_urls = array( home_url( '/' ) );

		if ( function_exists( 'site_url' ) ) {
			$site_urls[] = site_url( '/' );
		}

		$allowed_ports = array( 80, 443 );
		$host_matches  = false;
		foreach ( $site_urls as $site_url_value ) {
			$site_parts = wp_parse_url( $site_url_value );
			if ( ! is_array( $site_parts ) || empty( $site_parts['host'] ) ) {
				continue;
			}

			if ( ! empty( $site_parts['port'] ) ) {
				$allowed_ports[] = (int) $site_parts['port'];
			}

			if ( $this->hosts_match( $site_parts['host'], $url_parts['host'] ) ) {
				$host_matches = true;
			}
		}

		$url_port = ! empty( $url_parts['port'] ) ? (int) $url_parts['port'] : ( 'https' === strtolower( $url_parts['scheme'] ) ? 443 : 80 );

		return $host_matches && in_array( $url_port, array_unique( $allowed_ports ), true );
	}

	/**
	 * Normalize URL identity without collapsing meaningful trailing slashes.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function normalize_for_compare( $url ) {
		$url   = $this->remove_fragment( (string) $url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( trim( $parts['host'], '.' ) );
		$port   = empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'];
		$path   = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * Origin of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function origin( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		return $origin;
	}

	/**
	 * Normalize an administrator-supplied hostname or URL.
	 *
	 * @param string $value Hostname or URL.
	 * @return string
	 */
	public function normalize_hostname( $value ) {
		$value = trim( strtolower( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
			$value = 'https://' . $value;
		}

		$host = wp_parse_url( $value, PHP_URL_HOST );
		$host = is_string( $host ) ? trim( strtolower( $host ), '.' ) : '';

		if ( ! $this->is_valid_hostname( $host ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * Validate a hostname, IPv4 address, localhost, or development TLD.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public function is_valid_hostname( $host ) {
		$host = trim( strtolower( (string) $host ), '.' );

		if ( '' === $host || strlen( $host ) > 253 ) {
			return false;
		}

		if ( 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		if ( false === strpos( $host, '.' ) ) {
			return false;
		}

		foreach ( explode( '.', $host ) as $label ) {
			if ( '' === $label || strlen( $label ) > 63 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $label ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Host comparison with a conservative www alias.
	 *
	 * @param string $left Left host.
	 * @param string $right Right host.
	 * @return bool
	 */
	public function hosts_match( $left, $right ) {
		$left  = strtolower( trim( (string) $left, '.' ) );
		$right = strtolower( trim( (string) $right, '.' ) );

		if ( '' === $left || '' === $right ) {
			return false;
		}

		return $left === $right || preg_replace( '/^www\./', '', $left ) === preg_replace( '/^www\./', '', $right );
	}

	/**
	 * Remove a URL fragment because it is not sent to the server.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function remove_fragment( $url ) {
		$position = strpos( $url, '#' );

		return false === $position ? $url : substr( $url, 0, $position );
	}

	/**
	 * Normalize dot segments in a relative path.
	 *
	 * @param string $path Path and optional query.
	 * @return string
	 */
	private function normalize_path( $path ) {
		$query = '';

		if ( false !== strpos( $path, '?' ) ) {
			list( $path, $query_value ) = explode( '?', $path, 2 );
			$query = '?' . $query_value;
		}

		$output = array();
		foreach ( explode( '/', $path ) as $segment ) {
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
}
