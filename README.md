# SMS Notify for WooCommerce (Bangladesh)

[![CI](https://github.com/saizul/woo-bd-sms-notify/actions/workflows/ci.yml/badge.svg)](https://github.com/saizul/woo-bd-sms-notify/actions/workflows/ci.yml)
[![Release zip](https://github.com/saizul/woo-bd-sms-notify/actions/workflows/release-zip.yml/badge.svg)](https://github.com/saizul/woo-bd-sms-notify/actions/workflows/release-zip.yml)
![PHP](https://img.shields.io/badge/PHP-7.4%20to%208.4-777bb4)
![WooCommerce](https://img.shields.io/badge/WooCommerce-HPOS%20compatible-96588a)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

Order status SMS for Bangladeshi WooCommerce stores. Send through **BulkSMSBD**,
**SSL Wireless (ISMS Plus)**, **Alpha SMS (sms.net.bd)** or any HTTP SMS API, with
per-status templates, an admin copy, correct Bangla (Unicode) segment counting,
phone number normalisation, a send log, automatic retries with Action Scheduler,
rate limiting and optional one-time-code verification for cash-on-delivery orders.

Plugin slug: `woo-bd-sms-notify` - display name: *SMS Notify for WooCommerce (Bangladesh)*.

## Features

- **Four gateways behind one interface**: BulkSMSBD, SSL Wireless ISMS Plus v3,
  Alpha SMS and a generic HTTP gateway (GET/POST, form or JSON body, custom
  headers, placeholders, configurable success rule).
- **Per-status templates**: a customer SMS and an admin copy for every order
  status, including custom statuses registered by other plugins.
- **Placeholders**: `{order_number}`, `{total}`, `{name}`, `{tracking}` and 20 more,
  with fallbacks (`{tracking|not yet available}`).
- **Bangla aware**: GSM-7 vs UCS-2 detection and segment counting (160/70 single,
  153/67 per part), extension characters counted as two septets, emoji as two
  UTF-16 units, parts never split inside a character. Template previews show the
  number of SMS each template costs.
- **Plain punctuation**: curly quotes and dashes pasted from a word processor are
  replaced so English messages stay GSM-7 (Bangla text is never changed).
- **Phone normalisation**: `+880 1711-234567`, `8801711234567`, `1711234567`, Bangla
  digits; invalid numbers are logged as skipped instead of wasting credit.
- **Send log** in a custom table with filters, retry buttons, retention and an SMS
  box on the order screen (log + custom message).
- **Retries**: temporary failures (timeouts, HTTP 5xx/429, documented "internal
  error" codes) are retried with exponential back-off through Action Scheduler.
- **Gateway failover**: a backup gateway takes over when the main one is down
  (or out of balance, if you choose), with a circuit breaker that skips a failing
  provider for a cooldown period.
- **Rate limiting**: per number and store-wide windows; limited messages are
  deferred to the next window instead of dropped.
- **COD OTP verification** (classic checkout): HMAC-hashed codes bound to phone
  and session, expiry, attempt limit, resend cooldown and send limit.
- **Background sending**: status changes queue an async action, so payment
  callbacks are not slowed down by the SMS gateway.
- **HPOS compatible**, orders read and written only through the CRUD API.
- **WP-CLI**: `wp bd-sms test|send|log|count|retry|failover`.
- Secrets can live in `wp-config.php` constants and are then never stored.

## Gateways and how they were verified

| Gateway | Request | Source |
| ------- | ------- | ------ |
| BulkSMSBD | `POST https://bulksmsbd.net/api/smsapi` with `api_key`, `type=text`, `number` (`8801XXXXXXXXX`), `senderid`, `message`. Code `202` = submitted; `1002`-`1032` errors. | [bulksmsbd.com API page](https://bulksmsbd.com/bulksms-api-bangladesh.php) |
| SSL Wireless ISMS Plus | `POST https://smsplus.sslwireless.com/api/v3/send-sms`, JSON `{api_token, sid, msisdn, sms, csms_id}`. Success: `status=SUCCESS`, `status_code=200`, `smsinfo[0].sms_status=SUCCESS`. | [ISMS Plus API documentation](https://ismsplus.sslwireless.com/api-documentation) |
| Alpha SMS (sms.net.bd) | `POST https://api.sms.net.bd/sendsms` with `api_key`, `msg`, `to`, optional `sender_id`. Success: `error=0`, `data.request_id`. Balance: `GET /user/balance/`. | [sms.net.bd API](https://sms.net.bd/api) |
| Generic HTTP | Anything you configure. | - |

Notes:

- The BulkSMSBD documentation lists the result codes but not the response body
  format. The adapter reads the code from a JSON `response_code` field and falls
  back to a bare number in the body. If your account returns something else,
  use the generic gateway or open an issue with a (redacted) sample response.
- SSL Wireless requires your server IP to be whitelisted in the ISMS Plus portal
  (error `4003` otherwise). Each attempt sends a unique `csms_id`.
- All three providers publish their API, so no provider is shipped as a preset
  of the generic gateway. The generic gateway offers shape presets (GET query,
  JSON with bearer token, form POST with JSON status) as starting points, and
  `woo_bd_sms_generic_presets` lets you add your own.
- The adapters were written against the documentation and tested with recorded
  responses; they have not been run against live accounts for this release. Send
  a test SMS (Test & tools tab or `wp bd-sms test`) before going live.

### Generic gateway placeholders

URL, parameters and headers can use `{to}` (`8801XXXXXXXXX`), `{to_local}`
(`01XXXXXXXXX`), `{to_e164}` (`+8801XXXXXXXXX`), `{message}`, `{sender_id}`,
`{api_key}`, `{api_secret}`, `{reference}`, `{unicode}` (`1`/`0`) and `{type}`
(`unicode`/`text`).

Success rules: HTTP 2xx, body contains / does not contain, regular expression,
or JSON conditions such as `status=SUCCESS && smsinfo.0.sms_status=SUCCESS`,
`error!=1`, `data.id` (present).

## Requirements

- WordPress 6.0+, WooCommerce 7.1+ (HPOS or legacy storage)
- PHP 7.4 to 8.4

## Installation

1. Download `woo-bd-sms-notify.zip` from the [releases](https://github.com/saizul/woo-bd-sms-notify/releases)
   and upload it in *Plugins > Add New > Upload Plugin*.
2. Activate it (WooCommerce must be active). The log table is created on activation.
3. Open *WooCommerce > SMS Notify*, pick a gateway, enter its credentials and send
   a test SMS from *Test & tools*.
4. Enable the templates you want on the *Templates* tab.

### Credentials in wp-config.php

```php
define( 'WOO_BD_SMS_GATEWAY', 'bulksmsbd' );            // optional: force the gateway
define( 'WOO_BD_SMS_FAILOVER_GATEWAY', 'alpha_sms' );   // optional: backup gateway ('' = off)
define( 'WOO_BD_SMS_BULKSMSBD_API_KEY', 'your-api-key' );
define( 'WOO_BD_SMS_BULKSMSBD_SENDER_ID', '8809600000000' );
define( 'WOO_BD_SMS_SSL_WIRELESS_API_TOKEN', 'your-token' );
define( 'WOO_BD_SMS_SSL_WIRELESS_SID', 'YOURSID' );
define( 'WOO_BD_SMS_ALPHA_SMS_API_KEY', 'your-api-key' );
define( 'WOO_BD_SMS_GENERIC_API_KEY', 'your-api-key' );
```

Any gateway field can be set this way: `WOO_BD_SMS_{GATEWAY}_{FIELD}`. A defined
constant wins over the settings screen, is shown masked and is never written to
the database. `WOO_BD_SMS_OTP_SECRET` adds extra key material to the OTP hash
(the site salts are always used).

## Templates and placeholders

| Placeholder | Value |
| ----------- | ----- |
| `{order_id}`, `{order_number}` | Order ID / order number shown to the customer |
| `{status}`, `{previous_status}` | Status labels |
| `{total}`, `{currency}`, `{currency_symbol}` | `1,250`, `BDT`, `৳` |
| `{name}`, `{first_name}`, `{last_name}` | Billing name |
| `{phone}`, `{email}`, `{city}` | Billing details (phone normalised) |
| `{payment_method}`, `{shipping_method}` | Method titles |
| `{item_count}`, `{items}` | Quantity sum, item names |
| `{tracking}`, `{tracking_url}`, `{courier}` | Shipment tracking (see below) |
| `{date}`, `{note}` | Order date, customer note |
| `{site_name}`, `{site_url}` | Store |

`{name|Customer}` uses a fallback when the value is empty; `{{` and `}}` print
literal braces; unknown placeholders are removed (and reported when you save).

Tracking numbers are read from extra meta keys you configure, then `_tracking_number`,
`tracking_number`, `_tracking_code`, and the `_wc_shipment_tracking_items` list used
by Shipment Tracking compatible plugins. A link template such as
`https://courier.example/track/{tracking}` builds `{tracking_url}` when no link is stored.

### SMS length

| Encoding | One SMS | Per part when longer |
| -------- | ------- | -------------------- |
| GSM-7 (English) | 160 characters | 153 |
| UCS-2 (Bangla, emoji, any non-GSM character) | 70 | 67 |

`€ [ ] { } ~ ^ | \` count as two GSM-7 characters. Messages longer than the
"Longest SMS" setting (default 6 segments) are skipped and logged.
`wp bd-sms count "<text>"` shows the numbers for any text.

## COD phone verification

When enabled, customers who pick one of the selected payment methods (default:
cash on delivery) above an optional minimum total must confirm their billing phone
with a code before the order can be placed.

- Codes come from `random_int()` and are stored only as HMAC-SHA256 (keyed with the
  site salts), bound to the phone number and a random per-session context.
- Expiry (default 5 minutes), wrong-code limit (5), resend cooldown (60 s) and
  send limit (3 per hour) are configurable. Codes are cleared on success and the
  log only keeps a masked copy of the message.
- The verified number is stored on the order as `_woo_bd_sms_otp_verified`.

Supported on the classic (shortcode) checkout. The block-based Checkout is not
supported in 0.1.0.

## Retries and rate limits

- Attempts per SMS (default 3) with delays of 60 s, 240 s, ... (factor 4, max 1 hour).
- Only temporary errors are retried: transport errors, HTTP 408/429/5xx, BulkSMSBD
  `1005`, SSL Wireless `4029`/`4031`/`5000`, Alpha SMS `409`. Balance, sender ID and
  credential errors fail immediately.
- Per-number limit (default 10/hour) and store limit (default 120/minute). A
  limited order SMS is deferred to the next window; OTP codes fail instead.
- Counters use transients (and your object cache if installed). Under very high
  concurrency counts are approximate.
- Failed entries can be retried from the log or with `wp bd-sms retry <id>`.

## Gateway failover

Pick a **Backup gateway** on the Gateway tab (or define
`WOO_BD_SMS_FAILOVER_GATEWAY`) and configure its credentials as usual. Every SMS
still goes to the main gateway first; when that attempt fails, the same message is
sent through the backup straight away, before any retry is scheduled.

| Setting | Default | Meaning |
| --- | --- | --- |
| Fail over on | Temporary errors | `temporary`: transport errors, HTTP 408/429/5xx and the retryable provider codes listed above. `any`: every failure, including balance, credential and sender ID errors. |
| Also fail over on codes | empty | Extra provider codes that fail over in `temporary` mode, e.g. `1007` (BulkSMSBD balance) or `417` (Alpha SMS balance). |
| Skip the main gateway after | 3 failures | Consecutive failed attempts before the main gateway is skipped (circuit breaker). `0` always tries it first. |
| Skip it for | 300 s | Cooldown. The first SMS after it probes the main gateway again: success closes the breaker, a failure reopens it at once. |

- Invalid numbers and rejected texts are not sent twice: in `temporary` mode only
  provider-side failures move to the backup.
- The log's gateway column shows the provider that handled each attempt. When both
  fail, the error lists both responses and the message is retried (main gateway
  first) if either error was temporary.
- `wp bd-sms test --gateway=<id>` always talks to one gateway directly, so each
  provider can be checked on its own.
- `wp bd-sms failover` shows the breaker state; `wp bd-sms failover --reset` closes it.
- Breaker state lives in transients next to the rate-limit counters and is removed on uninstall.

## WP-CLI

```bash
wp bd-sms test 01711234567 --message="Test"           # send through the active gateway
wp bd-sms test 01711234567 --dry-run                   # print the request, secrets redacted
wp bd-sms send 1024                                    # templates for the order's status
wp bd-sms send 1024 --message="Hi {first_name}, parcel {tracking} is on the way"
wp bd-sms log --status=failed --limit=50 --format=json
wp bd-sms count "আপনার অর্ডার পাঠানো হয়েছে"
wp bd-sms retry 42
wp bd-sms failover                                     # backup gateway and breaker state
wp bd-sms failover --reset                             # try the main gateway again now
```

## Hooks

Filters:

| Filter | Purpose |
| ------ | ------- |
| `woo_bd_sms_capability` | Capability for settings, log and manual sends (default `manage_woocommerce`) |
| `woo_bd_sms_gateways` | Add or remove gateway classes (`id => class` extending `AbstractGateway`) |
| `woo_bd_sms_generic_presets` | Presets for the generic gateway |
| `woo_bd_sms_order_vars` | Placeholder values of an order |
| `woo_bd_sms_order_messages` | SMS about to be sent for a status change |
| `woo_bd_sms_send_async` | Send status SMS in the background (default `true`) |
| `woo_bd_sms_otp_required` | Whether checkout needs phone verification |
| `woo_bd_sms_http_args` | Arguments for `wp_remote_request()` |

Actions: `woo_bd_sms_loaded` (services ready), `woo_bd_sms_order_sms_processed`
(after each order SMS), plus the scheduled `woo_bd_sms_retry`,
`woo_bd_sms_order_status` and daily `woo_bd_sms_purge_log`.

### Adding a gateway

```php
final class My_Gateway extends \Saizul\BdSms\Gateway\AbstractGateway {
	public function id(): string { return 'my_gateway'; }
	public function label(): string { return 'My gateway'; }
	public static function fields(): array {
		return array( new \Saizul\BdSms\Gateway\FieldDefinition( 'api_key', 'API key', 'secret', true ) );
	}
	protected function build_request( \Saizul\BdSms\Gateway\Message $m ): \Saizul\BdSms\Http\Request {
		return \Saizul\BdSms\Http\Request::json( 'https://sms.example/send', array( 'to' => $m->to(), 'text' => $m->text(), 'key' => $this->config( 'api_key' ) ) );
	}
	protected function interpret( \Saizul\BdSms\Http\Response $r ): \Saizul\BdSms\Gateway\SendResult {
		return $r->is_success() ? \Saizul\BdSms\Gateway\SendResult::sent( '' ) : $this->unexpected( $r );
	}
}
add_filter( 'woo_bd_sms_gateways', function ( $classes ) {
	$classes['my_gateway'] = My_Gateway::class;
	return $classes;
} );
```

## Privacy and data

The log stores phone numbers, message texts and gateway responses (with
credentials removed) in `{prefix}woo_bd_sms_log`. Entries older than the retention
setting (default 30 days) are deleted daily. Phone numbers are masked in the admin
screens and CLI output (`--unmask` shows them). Message text and phone numbers
are sent to the SMS provider you choose.

## Uninstall

Deleting the plugin removes its scheduled actions. With *Delete settings and the
log when the plugin is deleted* enabled it also drops the log table and removes
the settings and transients. Order meta (sent markers, OTP verification) stays
with the orders.

## Development

```bash
composer install
composer test    # PHPUnit, no WordPress needed
composer phpcs   # WordPress Coding Standards + PHPCompatibilityWP
composer lint    # php -l
php bin/make-pot.php
```

`src/` is a WordPress-free library (gateways, segment counting, OTP, rate
limiting, retries, templates); `includes/` wires it into WordPress and
WooCommerce. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL-2.0-or-later. Copyright (c) 2026 Saizul Amin - <https://saizul.com>
