=== SMS Notify for WooCommerce (Bangladesh) ===
Contributors: saizul
Tags: woocommerce, sms, bangladesh, otp, order notification
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Order status SMS for Bangladeshi stores via BulkSMSBD, SSL Wireless, Alpha SMS or any HTTP gateway, with Bangla segment counting and COD phone verification.

== Description ==

Keep customers informed with an SMS at every order status, and get an admin copy for new orders. Built for Bangladeshi stores and SMS providers.

**Gateways**

* BulkSMSBD
* SSL Wireless (ISMS Plus API v3)
* Alpha SMS (sms.net.bd)
* Generic HTTP gateway: any GET/POST API with form or JSON body, custom headers and a configurable success rule

**Templates**

A customer SMS and an admin copy for every order status, including custom statuses. Placeholders such as {order_number}, {total}, {name}, {tracking} and {tracking_url}, with fallbacks: {tracking|not yet available}.

**Bangla done right**

Unicode detection and SMS segment counting (160/70 characters, 153/67 per part when longer). Each template shows how many SMS it will cost. Curly quotes and dashes can be replaced so English messages are not sent as Unicode by accident.

**Reliable sending**

* Phone numbers normalised (+880, 880, Bangla digits); invalid numbers are skipped and logged.
* Send log with filters and a retry button, plus an SMS box on the order screen.
* Temporary gateway errors are retried with Action Scheduler.
* Per-number and store-wide rate limits.
* Status SMS are sent in the background so checkout stays fast.

**COD phone verification**

Optional one-time code on the classic checkout for cash-on-delivery (or any selected) payment methods: hashed codes, expiry, attempt limit, resend cooldown and send limit.

**For developers**

* WP-CLI: `wp bd-sms test`, `send`, `log`, `count`, `retry`.
* Credentials can be defined as constants in wp-config.php and are then never stored.
* Filters to add gateways, change placeholder values and messages.
* HPOS compatible.

Development happens on GitHub: https://github.com/saizul/woo-bd-sms-notify

== Installation ==

1. Upload the plugin zip in Plugins > Add New > Upload Plugin, or copy the `woo-bd-sms-notify` folder to `wp-content/plugins`.
2. Activate the plugin. WooCommerce must be active.
3. Open WooCommerce > SMS Notify, choose your gateway and enter the credentials from your SMS provider.
4. Send a test SMS from the Test & tools tab, then enable the templates you need.

== Frequently Asked Questions ==

= Which SMS providers are supported? =

BulkSMSBD, SSL Wireless ISMS Plus and Alpha SMS have built-in adapters written from their public API documentation. Any other provider with an HTTP API can be connected with the generic gateway.

= Why does my Bangla SMS cost two credits? =

Bangla is sent as Unicode: one SMS holds 70 characters, and longer messages are split into parts of 67. The template preview and `wp bd-sms count` show the exact number.

= SSL Wireless returns error 4003. =

Your server IP must be whitelisted in the ISMS Plus portal.

= Does the OTP work with the block checkout? =

Not in this version. Phone verification works on the classic (shortcode) checkout.

= Are OTP codes stored? =

Only as a keyed hash, for the few minutes they are valid. The log keeps a masked copy of the message.

= Who can change settings and send SMS manually? =

Users with the `manage_woocommerce` capability (filterable with `woo_bd_sms_capability`).

== Screenshots ==

1. Gateway settings.
2. Per-status templates with SMS count preview.
3. The send log.
4. Phone verification on the checkout.

== Changelog ==

= 0.1.0 =
* First release: BulkSMSBD, SSL Wireless, Alpha SMS and generic HTTP gateways, per-status templates with admin copy, Unicode segment counting, phone normalisation, send log, Action Scheduler retries, rate limiting, COD OTP verification, WP-CLI commands, HPOS support.

== Upgrade Notice ==

= 0.1.0 =
First release.
