=== IndexLane Crawl Fetch Inspector ===
Contributors: indexlane
Tags: crawl, robots, sitemap, schema, migration
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inspect crawler-facing HTTP, indexability, sitemap, structured-data and migration evidence from inside WordPress.

== Description ==

IndexLane Crawl Fetch Inspector is a read-only wp-admin diagnostic for ordinary site owners preparing, checking, or migrating a WordPress site.

Choose one of three input modes:

* Manual or recent WordPress URLs
* A sample distributed across child sitemaps
* A launch or migration check combining home, recent, sitemap, and old-domain evidence

Each page report includes:

* HTTP status and redirect chain
* Canonical and robots directives
* Effective robots.txt result for Googlebot
* Sitemap membership with Yes, No, and Unknown—incomplete states
* JSON-LD block count, validity, @type inventory, and duplicate @id evidence
* Old/staging-domain matches with their exact value, snippet, and source context
* Complete, Partial, or Failed evidence state

The plugin expands child sitemaps within limits, samples across them, and processes sitemap/page work in resumable AJAX batches. Failed fetches remain Unknown; incomplete evidence never becomes a positive claim.

Migration evidence is limited to URL-bearing href/src attributes, canonical and Open Graph fields, CSS URLs, and JSON-LD URL values. It does not flag generic words such as "staging" in ordinary page copy.

The plugin is read-only. It does not auto-fix content, schedule scans, call external APIs, fetch external redirect destinations, or add public frontend output. Active scan jobs use per-user transients that expire after one hour.

Learn more at [IndexLane](https://indexlane.dev).

== Installation ==

1. Upload the `indexlane-crawl-fetch-inspector` folder to `/wp-content/plugins/`.
2. Activate "IndexLane Crawl Fetch Inspector" through the Plugins screen.
3. Go to Tools -> Crawl Fetch Inspector.
4. Choose an input mode and start an inspection.

== Frequently Asked Questions ==

= Does this change my site? =

No. Checks are read-only. Temporary scan state is retained for up to one hour only so batched scans can resume.

= How is robots.txt evaluated? =

The plugin selects the most specific applicable Googlebot groups, combines equally specific groups, applies wildcard/end-anchor patterns, and uses the longest matching Allow or Disallow rule. Allow wins an equal-length tie. Fetch failure is Unknown.

= When does sitemap membership say No? =

Only when the bounded sitemap set completed and the URL was not observed. A failed, truncated, or omitted sitemap makes an absent URL Unknown—incomplete.

= Does old-domain scanning search normal page copy? =

No. It checks properly formed hostnames in URL-bearing HTML, CSS, Open Graph, canonical, and JSON-LD fields. Generic words in normal page copy are ignored.

= Can it check external URLs? =

No. Checks are same-site only. A redirect leaving the site is recorded, but its external destination is not fetched.

= Does this prove that a page is indexed? =

No. It reports crawler-facing evidence available to the plugin. It does not prove indexation, ranking, analytics delivery, or consent behavior.

== Changelog ==

= 0.2.0 =
* Split the original monolith into fetch, redirect, parsing, robots, sitemap, evaluation, target collection, report, and export services.
* Added manual/recent, sitemap sample, and launch/migration input modes.
* Added crawler-specific Googlebot robots.txt groups and longest-match Allow/Disallow behavior.
* Added bounded sitemap-index expansion, distributed child-sitemap sampling, and resumable AJAX batches.
* Added Yes, No, and Unknown—incomplete sitemap membership states.
* Added raw JSON-LD validity, @type inventory, and duplicate @id evidence.
* Added administrator-supplied old domains and URL-context-only migration evidence with exact values and snippets.
* Added Complete, Partial, and Failed evidence states.
* Added behavioral fixtures for redirects, truncation, partial sitemap evidence, robots precedence, JSON-LD, and migration residue.
* Added a repository-root GPL-2.0-or-later license and release documentation.

= 0.1.2 =
* Fixed redirect identity, safe same-site fetching, terminal 3xx handling, and response truncation evidence.

= 0.1.1 =
* Added compatibility and activation CI workflows.

= 0.1.0 =
* Initial release.
