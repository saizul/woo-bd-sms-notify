# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Gateway interface with adapters for BulkSMSBD, SSL Wireless ISMS Plus v3 and
  Alpha SMS (sms.net.bd), and a generic HTTP gateway (GET/POST, form or JSON,
  headers, placeholders, success rules, presets).
- Per-status customer templates and admin copies with 24 placeholders and fallbacks.
- GSM-7 / UCS-2 detection and segment counting (160/153, 70/67), punctuation
  clean-up, template previews and a "longest SMS" guard.
- Bangladeshi phone normalisation for recipients and admin numbers.
- Send log table with admin screen, filters, retry, retention and an order screen box.
- Retries with exponential back-off through Action Scheduler; background sending
  of status SMS.
- Per-number and store-wide rate limits.
- COD phone verification on the classic checkout: HMAC-hashed codes, expiry,
  attempt limit, resend cooldown and send limit.
- Credentials from `wp-config.php` constants, never stored when defined.
- WP-CLI `wp bd-sms test|send|log|count|retry`, `.pot` file, `uninstall.php`,
  HPOS compatibility declaration.
- PHPUnit suite running without WordPress, WordPress Coding Standards config and
  GitHub workflows for CI and release zips.

[Unreleased]: https://github.com/saizul/woo-bd-sms-notify/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/saizul/woo-bd-sms-notify/releases/tag/v0.1.0
