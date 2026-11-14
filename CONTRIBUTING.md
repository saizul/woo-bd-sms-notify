# Contributing

Thanks for helping improve SMS Notify for WooCommerce. Bug reports, gateway
fixes, new adapters for providers with public API documentation and translations
are all welcome.

## Reporting a bug

Open an issue with:

- WordPress, WooCommerce and PHP versions, and whether HPOS is enabled
- the gateway you use and the log entry (WooCommerce > SMS Notify > Send log)
- for gateway problems: the response body with API keys, tokens and phone numbers
  removed (`wp bd-sms test <phone> --dry-run` prints the request with secrets redacted)

Please never post real API keys, customer names or phone numbers.

## Gateway adapters

Adapters live in `src/Gateway/` and extend `AbstractGateway`. Add one only for a
provider that publishes its API; link the documentation in the class comment and
the README table, and cover request building and every documented result code with
tests using `FakeHttpClient`. Providers without public documentation can be
connected with the generic gateway or a preset through `woo_bd_sms_generic_presets`.

## Development setup

```bash
git clone https://github.com/saizul/woo-bd-sms-notify.git
cd woo-bd-sms-notify
composer install
composer test     # PHPUnit
composer phpcs    # WordPress Coding Standards
composer lint     # php -l
```

PHP 7.4 or newer is required. The dependencies are resolved for PHP 7.4
(`config.platform`) so the same lock file works across the CI matrix.

## Guidelines

- Keep business logic in `src/` free of WordPress functions and cover it with tests.
  WordPress integration lives in `includes/` and is tested against the stubs in
  `tests/Stubs/`.
- Code must run on PHP 7.4: no union types, `match`, named arguments, constructor
  promotion, or argument unpacking with string keys.
- Follow the WordPress Coding Standards (`composer phpcbf` fixes most issues).
- Escape all output and check capabilities and nonces in every request handler.
- Wrap user-facing strings with the `woo-bd-sms-notify` text domain, add a
  `/* translators: */` comment for placeholders and run `php bin/make-pot.php`
  (CI checks the `.pot` file).
- New hooks start with `woo_bd_sms_` and are documented in the README.
- Add an entry under "Unreleased" in `CHANGELOG.md`.

## Releasing

1. Update the version in `woo-bd-sms-notify.php` (header and constant),
   `includes/Plugin.php`, `readme.txt` (Stable tag and changelog) and `CHANGELOG.md`.
2. Tag `vX.Y.Z` and publish a GitHub release. The `Release zip` workflow checks the
   versions and attaches `woo-bd-sms-notify.zip`.

By contributing you agree that your contribution is licensed under the
GPL-2.0-or-later license of this project.
