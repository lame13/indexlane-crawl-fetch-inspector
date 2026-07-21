<?php
/**
 * CSV report export.
 *
 * @package IndexLane_Crawl_Fetch_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams report rows with formula-injection protection.
 */
final class ILCFI_CSV_Exporter {
	/**
	 * Export columns.
	 *
	 * @return array
	 */
	public function columns() {
		return array(
			'input_url'             => __( 'Input URL', 'indexlane-crawl-fetch-inspector' ),
			'http_status'           => __( 'HTTP Status', 'indexlane-crawl-fetch-inspector' ),
			'redirect_chain'        => __( 'Redirect Chain', 'indexlane-crawl-fetch-inspector' ),
			'final_url'             => __( 'Final URL', 'indexlane-crawl-fetch-inspector' ),
			'canonical_url'         => __( 'Canonical URL', 'indexlane-crawl-fetch-inspector' ),
			'robots_directives'     => __( 'Robots Directives', 'indexlane-crawl-fetch-inspector' ),
			'robots_txt'            => __( 'Effective robots.txt for Googlebot', 'indexlane-crawl-fetch-inspector' ),
			'sitemap_membership'    => __( 'Sitemap Membership', 'indexlane-crawl-fetch-inspector' ),
			'json_ld_count'         => __( 'JSON-LD Blocks', 'indexlane-crawl-fetch-inspector' ),
			'json_ld_validity'      => __( 'JSON-LD Validity', 'indexlane-crawl-fetch-inspector' ),
			'json_ld_types'         => __( 'JSON-LD Types', 'indexlane-crawl-fetch-inspector' ),
			'duplicate_json_ld_ids' => __( 'Duplicate JSON-LD @id Values', 'indexlane-crawl-fetch-inspector' ),
			'old_domain_evidence'   => __( 'Old/Staging-Domain Evidence', 'indexlane-crawl-fetch-inspector' ),
			'old_domain_evidence_details' => __( 'Old/Staging Evidence Details', 'indexlane-crawl-fetch-inspector' ),
			'evidence_completeness' => __( 'Evidence Completeness', 'indexlane-crawl-fetch-inspector' ),
			'completeness_detail'   => __( 'Completeness Detail', 'indexlane-crawl-fetch-inspector' ),
			'response_time'         => __( 'Response Time', 'indexlane-crawl-fetch-inspector' ),
			'result'                => __( 'Result', 'indexlane-crawl-fetch-inspector' ),
			'detail'                => __( 'Details', 'indexlane-crawl-fetch-inspector' ),
		);
	}

	/**
	 * Stream a CSV download.
	 *
	 * @param array $rows Rows.
	 */
	public function send( array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=indexlane-crawl-fetch-inspector-' . gmdate( 'Ymd-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			exit;
		}

		$columns = $this->columns();
		fputcsv( $output, array_map( array( $this, 'safe' ), array_values( $columns ) ), ',', '"', '' );

		foreach ( $rows as $row ) {
			$line = array();
			foreach ( array_keys( $columns ) as $key ) {
				$line[] = $this->safe( isset( $row[ $key ] ) ? (string) $row[ $key ] : '' );
			}
			fputcsv( $output, $line, ',', '"', '' );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Protect spreadsheet consumers from formulas.
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	public function safe( $value ) {
		$value = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
		if ( '' !== $value && preg_match( '/^[=+\-@\t]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
