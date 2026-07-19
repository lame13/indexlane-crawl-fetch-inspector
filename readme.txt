=== IndexLane Crawl Fetch Inspector ===
Contributors: indexlane
Tags: crawl, redirects, canonical, robots, seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A small free WordPress diagnostic plugin for checking crawler-facing HTTP status, redirects, canonicals, robots directives, schema blocks, and basic HTML SEO signals.

== Description ==

IndexLane Crawl Fetch Inspector helps site administrators check how important WordPress URLs respond at the crawler-facing HTML layer from inside wp-admin.

It can report:

* HTTP status
* Redirect count
* Final URL
* Canonical URL
* Meta robots
* X-Robots-Tag header
* Title presence
* Meta description presence
* JSON-LD block count
* Obvious staging/dev-domain residue
* Response time
* Conservative result labels
* CSV export

The plugin is read-only. It does not auto-fix content, store scan history, schedule scans, call external APIs, or add public frontend badges.

Tested with WordPress 7.0 and PHP 8.4.

== Data handling ==

This tool checks same-site URLs selected or entered by the administrator. Results are generated for the current run and can be exported as CSV.

== Installation ==

1. Upload the `indexlane-crawl-fetch-inspector` folder to `/wp-content/plugins/`.
2. Activate "IndexLane Crawl Fetch Inspector" through the Plugins screen in WordPress.
3. Go to Tools -> Crawl Fetch Inspector.
4. Enter URLs or select recent content, then run checks.

== Frequently Asked Questions ==

= Does this change my site? =

No. The plugin is read-only and does not auto-fix content or settings.

= Does it store scan history? =

No. Results are generated for the current run and can be exported as CSV.

= Does it use an external API? =

No. It uses WordPress HTTP requests to check same-site URLs.

= Does this replace Google Search Console or a crawler? =

No. It is a quick diagnostic helper for crawler-facing response evidence inside WordPress admin.

= Can it check external URLs? =

No. The v0.1 build checks same-site WordPress URLs only. External manual URLs are skipped, and same-site redirects that leave the site are not followed.

== Screenshots ==

1. Manual URL input, recent content selectors, and same-site scope note.
2. Results table with crawler-facing response evidence and conservative labels.

== Changelog ==

= 0.1.2 =
* Fixed ordinary trailing-slash and canonical-host redirects being reported as loops.
* Switched same-site requests to the WordPress safe HTTP API.
* Made terminal 3xx responses require review instead of treating redirect-body HTML as final-page evidence.
* Detects response bodies that reach the 2 MB limit or end before an unencoded declared length, and avoids claims from incomplete HTML.
* Added behavioral tests for redirects, out-of-scope targets, and response truncation.

= 0.1.1 =
* Added GitHub Actions PHP linting across PHP 7.4 through 8.4.
* Added WordPress activation smoke tests for supported WordPress/PHP combinations.

= 0.1.0 =
* Initial release.
