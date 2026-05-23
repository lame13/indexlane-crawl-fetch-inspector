# IndexLane Crawl Fetch Inspector

A small free WordPress diagnostic plugin for checking crawler-facing HTTP status, redirects, canonicals, robots directives, schema blocks, and basic HTML SEO signals.

## What it does

Check how important WordPress URLs respond at the crawler-facing HTML layer from inside wp-admin.

The plugin adds:

- Tools -> Crawl Fetch Inspector admin screen
- Manual URL input, one URL per line
- Optional recent post, page, and product selectors
- HTTP status, redirect count, final URL, canonical URL, robots signals, title and meta description presence, JSON-LD count, possible dev/staging residue, response time, and conservative result labels
- CSV export

## Local test environment

Tested with WordPress 7.0 and PHP 8.4 during local verification.

## CI

GitHub Actions lint the plugin across PHP 7.4 through 8.4 and run WordPress activation smoke tests against WordPress 6.0 on PHP 7.4 plus the latest WordPress release on PHP 8.3 and 8.4.

## Data handling

This tool checks same-site URLs selected or entered by the administrator. Results are generated for the current run and can be exported as CSV.

## Limits

This is a quick diagnostic helper, not a replacement for Google Search Console, Screaming Frog, Sitebulb, server logs, or a full technical SEO audit.

Result labels are conservative. The plugin reports evidence from crawler-facing HTTP and HTML responses; it does not claim ranking impact and does not auto-fix anything.

## Installation

1. Copy the `indexlane-crawl-fetch-inspector` folder into `wp-content/plugins/`.
2. Activate "IndexLane Crawl Fetch Inspector" in WordPress admin.
3. Open Tools -> Crawl Fetch Inspector.
4. Enter URLs or select recent content, then run checks.

## v0.1 Scope

Included:

- Admin-only access using `manage_options`
- Nonce-protected form actions
- Same-site WordPress URL checks only
- External manual URLs skipped
- Same-site redirects that leave the site are not followed
- Sanitized input and escaped output
- CSV formula-injection protection
- Read-only diagnostics
- No scheduled jobs
- No database storage
- CSV export

Not included:

- Auto-fixes
- Google Search Console integration
- Rank tracking
- Scheduled scans
- External URL fetching
- Stored scan history

## Repository Layout

```text
indexlane-crawl-fetch-inspector/
  .github/workflows/
    php-compatibility.yml
    wordpress-activation.yml
  indexlane-crawl-fetch-inspector.php
  readme.txt
  README.md
  assets/
    screenshot-1.png
    screenshot-2.png
    demo.gif
  docs/
    sample-report.csv
    changelog.md
```
