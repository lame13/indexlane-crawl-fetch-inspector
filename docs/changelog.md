# Changelog

## 0.1.1

- Added GitHub Actions PHP linting across PHP 7.4 through 8.4.
- Added WordPress activation smoke tests for supported WordPress/PHP combinations.
- Bumped the plugin header, runtime version constant, and WordPress.org stable tag to 0.1.1 as a patch release.

## 0.1.0

- Initial GitHub-ready scaffold.
- Added Tools -> Crawl Fetch Inspector admin screen.
- Added manual URL checks and optional recent post, page, and product selectors.
- Added HTTP status, redirect count, final URL, canonical URL, robots signals, title/meta description presence, JSON-LD count, possible dev/staging residue, response time, and conservative result labels.
- Added CSV export.
- Kept v0.1 read-only and storage-free.
- Removed external URL fetching from v0.1; checks are same-site only.
- Added CSV formula-injection protection.
- Adjusted result labels for 5xx/404/410 errors, access blocks, redirects, and slow responses.
- Replaced screenshots with captures from the local WordPress 7.0 / PHP 8.4 Herd test install.
