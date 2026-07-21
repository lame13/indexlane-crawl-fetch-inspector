# Crawl Fetch Inspector 0.2.0

This release rebuilds Crawl Fetch Inspector around one bounded, same-site WordPress fetch engine and expands the plugin into a broader crawler-facing evidence tool.

## Highlights

- Three scan modes: manual/recent URLs, distributed sitemap samples, and launch/migration checks.
- Googlebot-specific `robots.txt` evaluation with crawler group precedence and longest-match `Allow`/`Disallow` behavior.
- Automatic child-sitemap expansion, sampling across child sitemaps, resumable AJAX batches, and conservative `Yes`, `No`, or `Unknown—incomplete` membership.
- JSON-LD block counts, raw JSON validity, `@type` inventory, and duplicate `@id` evidence.
- Administrator-supplied old domains plus exact URL evidence from `href`, `src`, canonical/Open Graph fields, CSS URLs, and JSON-LD properties.
- `Complete`, `Partial`, and `Failed` evidence states, sectioned reports, and expanded CSV export.
- Behavioral fixtures for redirects, response truncation, robots precedence, sitemap partial evidence, JSON-LD, and migration residue.

The plugin remains read-only and same-site. External redirect destinations are recorded but not fetched.

Project website: https://indexlane.dev
