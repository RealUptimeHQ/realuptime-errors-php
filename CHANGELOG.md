# Changelog

## 0.1.1 (2026-11-13)


### Bug Fixes

* **errors:** scrub credential values in name=value text and query strings (REA-1260)

## Changelog

## Unreleased

No version has been published to Packagist or tagged yet. The package carries version 0.1.0 in its source. The first release will be listed here.

### Features

* Error capture for PHP 8.1 and newer and Laravel, with no runtime dependencies beyond `ext-json` and `ext-curl`.
* Scrub-by-default for sensitive values before an event leaves the process, including the key-name denylist and the AWS access key, Stripe webhook secret and URL userinfo patterns.
* Laravel exception handler, service provider and request-context middleware.
* Bounded buffering with honest drop counters.
