# Security policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 0.1.x   | yes       |

## Reporting a vulnerability

Please do **not** open a public issue. Use GitHub's private vulnerability reporting
("Security" tab, "Report a vulnerability") on
<https://github.com/saizul/woo-bd-sms-notify>, or contact the maintainer through
<https://saizul.com>.

Include the affected version, a description and steps to reproduce. You can expect
an acknowledgement within 7 days. Please give us reasonable time to release a fix
before disclosing the issue.

## What the plugin does to stay safe

- Settings, the log, test sends, retries and manual SMS require `manage_woocommerce`
  (filterable with `woo_bd_sms_capability`) and a nonce on every request.
- Gateway credentials can be defined as constants in `wp-config.php`; defined
  values are never written to the database. Stored secrets are never printed back
  into forms, and API keys are removed from logged responses and CLI dry runs.
- OTP codes are created with `random_int()`, stored only as HMAC-SHA256 keyed with
  the site salts, bound to phone number and session, expire, allow limited wrong
  guesses and are cleared after use. The send endpoints are nonce-protected and
  limited by cooldown, per-number send limits and the global rate limit.
- All admin output is escaped; settings are validated by a strict schema. Templates
  only substitute prepared values and cannot run code.
- Gateway requests use TLS certificate verification.

The send log contains customer phone numbers and message texts. Keep the retention
period as short as you need.
