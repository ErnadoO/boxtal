# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-10

First public release. The API may still change before 1.0.

### Added

- `BoxtalClient`: quotes (v1 API, `GET /cotation`), shipping orders (create, read, cancel), shipping documents,
  tracking, parcel points by network or by offer, content categories and webhook subscriptions (v3 API). Basic auth
  with the keys of an API v3 application; `sandbox: true` for the test environment.
- `WebhookParser`: signature check (`x-bxt-signature`, HMAC SHA-256) and parsing of `DOCUMENT_CREATED` and
  `TRACKING_CHANGED` events.
- `ParcelPoint::$openingHours` is null when the network gives no hours at all (Colissimo), so unknown hours are not
  read as closed days; `hasOpeningHours()`.
- Checked end to end against the Boxtal test environment on 2026-10-10 (`examples/sandbox.php`), except the delivery
  of a real webhook request.

[Unreleased]: https://github.com/ErnadoO/boxtal/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/ErnadoO/boxtal/releases/tag/v0.1.0
