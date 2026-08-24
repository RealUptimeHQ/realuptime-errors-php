# realuptime/errors (PHP/Laravel SDK)

Error tracking SDK for PHP and Laravel, zero runtime dependencies beyond
`ext-json` and `ext-curl`: part of
[RealUptime Errors](https://realuptime.io/error-tracking).

This is a **mirror**. It is published from `packages/errors-php` in the
[realuptime monorepo](https://github.com/RealUptimeHQ/realuptime) by
`scripts/publish-sdk-mirrors.mjs` and is not edited directly; open issues
and PRs against this repo, but expect source changes to land here after
they merge upstream.

## Install

Installs straight from GitHub today. npm, PyPI, RubyGems, and Packagist packages are coming; this note disappears the day they ship.

```bash
composer require realuptime/errors:dev-main (add {"type": "vcs", "url": "https://github.com/RealUptimeHQ/realuptime-errors-php"} to "repositories" first)
```

## Usage

```php
\RealUptime\Errors\Client::init(
    dsn: 'https://realuptime.io/api/errors/v1/ingest/rue_...', // from your project's dashboard
    release: 'v2.4.1',
    environment: 'production',
);

try {
    riskyThing();
} catch (\Throwable $e) {
    \RealUptime\Errors\Client::instance()?->captureException($e);
}
```

### Laravel

The service provider auto-registers via Composer package discovery. Set
`REALUPTIME_ERRORS_DSN` in `.env` and every exception `report()` already
sees, plus every failed queue job, is captured automatically. See
`config/realuptime-errors.php` and `src/Laravel/` for the full
integration (exception handler decoration, the request-context
middleware, and the queue `JobFailed` hook).

## What this SDK actually does

- **Never throws out of a public method.** Every entry point catches
  everything; a broken SDK logs once to stderr (prefixed
  `[realuptime-errors]`) and goes quiet. An error tracker that crashes the
  app it watches is worse than none.
- **Scrubs PII by default, client-side**, before anything serializes:
  `Authorization`/`Proxy-Authorization`/`Cookie`/`Set-Cookie` headers are
  replaced whole; card-shaped digit runs (Luhn-checked), JWTs, prefixed API
  keys, and long hex/base64 runs are pattern-scrubbed anywhere they appear
  in the message, request context, or breadcrumbs. Opt one field back in
  with `allowFields: ['user.email']`; there is no global "disable
  scrubbing" switch, by design.
- **Never silently drops.** The in-memory buffer (200 events) evicts the
  oldest event when full, counts every eviction, and reports the count on
  the next successful batch, so drops show up on your dashboard instead of
  disappearing.
- **Zero runtime dependencies.** `composer.json`'s `require` is always
  `php`/`ext-json`/`ext-curl` and nothing else, checked by a test in this
  repo, not just claimed in prose.

## Version

This mirror tracks SDK_VERSION `0.1.0` in `src/WireContract.php`, the
string every event actually carries on the wire.

## License

MIT, see [LICENSE](./LICENSE).
