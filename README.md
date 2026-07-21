# IndexLane Crawl Fetch Inspector

Inspect crawler-facing HTTP, indexability, sitemap, structured-data and migration evidence from inside WordPress.

Project website: [indexlane.dev](https://indexlane.dev)

## What it does

The plugin adds **Tools → Crawl Fetch Inspector** for read-only, same-site diagnostics using three input modes:

- **Manual / recent WordPress URLs** — enter paths or URLs and optionally include recently modified posts, pages, or products.
- **Sitemap sample** — expand a sitemap index within safety limits and sample across child sitemaps.
- **Launch / migration check** — combine the home page, recent content, a distributed sitemap sample, and administrator-supplied old domains.

Each page report is organized into:

- HTTP status and the complete observed redirect chain
- canonical and HTML/HTTP robots directives
- effective `robots.txt` result for Googlebot
- sitemap membership (`Yes`, `No`, or `Unknown—incomplete`)
- JSON-LD block count, validity, `@type` inventory, and duplicate `@id` values
- old/staging-domain evidence with the exact value, snippet, and source context
- evidence completeness (`Complete`, `Partial`, or `Failed`)

CSV export is available when a scan completes.

## Evidence policy

Results are intentionally conservative:

- a failed `robots.txt`, sitemap, or page fetch is `Unknown`, never positive evidence;
- `robots.txt` evaluation selects crawler-specific groups for Googlebot and applies longest-match `Allow`/`Disallow` behavior, with `Allow` winning equal-length ties;
- a missing sitemap entry is `No` only when all bounded sitemap evidence completed;
- truncated responses do not produce absence claims;
- JSON-LD is decoded from the raw script body without HTML entity decoding;
- ordinary words such as “staging” in page copy are ignored;
- migration evidence is limited to properly formed hosts in `href`, `src`, canonical/Open Graph URL fields, CSS `url(...)`, and JSON-LD URL values.

## Batching and safety

Sitemap discovery and page inspection run through nonce-protected wp-admin AJAX batches. Job state is stored in a per-user transient for one hour, and the browser resumes the active job after a page reload.

Safety boundaries include:

- same-site fetches only
- WordPress safe HTTP requests
- manual redirect tracking with a five-hop limit
- external redirect destinations recorded but not fetched
- response bodies limited to 2 MB
- up to 25 sitemap files and 25,000 membership URLs
- up to 50 page targets per scan

## Installation

1. Copy `indexlane-crawl-fetch-inspector` into `wp-content/plugins/`.
2. Activate **IndexLane Crawl Fetch Inspector**.
3. Open **Tools → Crawl Fetch Inspector**.
4. Choose an input mode and start an inspection.

## Development and verification

Run the behavioral suite:

```sh
php tests/behavioral.php
```

GitHub Actions lint PHP 7.4 through 8.4, run the behavioral fixtures, and smoke-test activation against the oldest supported and current WordPress/PHP combinations.

## Repository layout

```text
indexlane-crawl-fetch-inspector/
  indexlane-crawl-fetch-inspector.php
  includes/
    class-ilcfi-fetch-client.php
    class-ilcfi-redirect-follower.php
    class-ilcfi-html-parser.php
    class-ilcfi-robots-evaluator.php
    class-ilcfi-sitemap-service.php
    class-ilcfi-evidence-evaluator.php
    class-ilcfi-target-collector.php
    class-ilcfi-report-builder.php
    class-ilcfi-csv-exporter.php
    class-ilcfi-url-helper.php
  assets/
    admin.css
    admin.js
  tests/
    behavioral.php
    fixtures/
```

The plugin does not auto-fix pages, fetch external redirect targets, call external APIs, claim indexation, or replace Search Console, server logs, or a full crawler.

Licensed under GPL-2.0-or-later. See [LICENSE](LICENSE).
