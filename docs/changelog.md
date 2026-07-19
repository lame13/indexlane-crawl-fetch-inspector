# Changelog

## 0.1.2

- Fixed ordinary trailing-slash and canonical-host redirects being reported as loops, while preserving genuine loop detection.
- Switched same-site requests to `wp_safe_remote_get()` while retaining manual, same-site-only redirect handling and a five-hop bound.
- Made terminal 3xx responses require review and stopped treating redirect-body HTML as final-page evidence.
- Added explicit detection for bodies that reach the 2 MB fetch limit or end before an unencoded `Content-Length`, and avoided HTML absence claims when the evidence is incomplete.
- Added lightweight behavioral coverage for redirect identity, genuine loops, terminal redirects, external targets, and bounded bodies.
- Bumped the plugin header, runtime version constant, and WordPress.org stable tag to 0.1.2.

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
