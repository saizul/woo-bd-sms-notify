# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Gateway failover: an optional backup gateway (Gateway tab or the
  `WOO_BD_SMS_FAILOVER_GATEWAY` constant) receives a message right away when the
  main gateway fails. Fails over on temporary errors by default, on any error in
  `any` mode, or on listed provider codes such as `1007` (BulkSMSBD balance).
- Circuit breaker: after a number of consecutive failures (default 3) the main
  gateway is skipped for a cooldown (default 300 s), then probed again.
- `wp bd-sms failover` shows the backup gateway and breaker state; `--reset`
  closes the breaker.
- `FailoverGateway`, `FailoverPolicy` and the `RoutedGateway` interface for code
  that builds its own sender.

### Changed

- The send log records the gateway that actually handled each attempt.
- When both gateways fail, the logged error contains both responses and the
  message is retried if either error was temporary.

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
