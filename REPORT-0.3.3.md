# Release report — MONA Pay for WooCommerce 0.3.3

Date: 2026-09-26  
PHP: 8.5.11

## Result

Release 0.3.3 was updated according to `BRIEF-WPORG-0.3.3.md` and the findings in `PLUGIN-CHECK-0.3.2.csv`.

The release archive is:

`dist/mona-pay-for-woocommerce-0.3.3.zip`

SHA-256:

`f67df5013912a4cd61014527c519e1a5f960f1274e195a96c56aff6b4193d6bd`

## Changes completed

- Changed the distribution slug, main file, text domain, POT filename, and ZIP root folder to `mona-pay-for-woocommerce`.
- Updated the plugin version, stable tag, and changelog to 0.3.3.
- Preserved the existing gateway ID `monapay_vietqr` and option key `woocommerce_monapay_vietqr_settings` so existing store settings remain compatible.
- Rewrote `readme.txt` in English with the required contributor, WordPress version, service description, external-service data disclosure, Terms link, and Privacy link.
- Removed `load_plugin_textdomain()`.
- Added explicit `ABSPATH` direct-access guards to every distributed PHP file.
- Updated every translation call to use the `mona-pay-for-woocommerce` text domain and regenerated the POT file.
- Sanitized public query parameters and documented intentional nonce exceptions on the exact access lines.
- Kept paid hosted-checkout returns protected by HMAC verification.
- Kept QR image requests protected by the WooCommerce order key.
- Strengthened unsigned cancellation returns to require and verify the WooCommerce order ID, order key, and stored checkout ID.
- Resolved the exception-message escape findings reported for `class-monapay-api.php`.

## Automated tests

Command: `php tests/*.php` (executed as each matching test file with PHP 8.5.11).

| Test file | Result |
| --- | --- |
| `tests/test-checkout-sandbox.php` | 4 tests, 0 failures |
| `tests/test-oauth.php` | 20 tests, 0 failures |
| `tests/test-qr.php` | PASS; QR version 8, 1003-byte PNG |
| `tests/test-return-signature.php` | 11 tests, 0 failures |
| `tests/test-signature.php` | 14 tests, 0 failures |

Total: 50 checks passed, 0 failures.

## Static and packaging verification

- PHP syntax check on all repository PHP files: passed, 0 syntax errors.
- Plugin Check 2.1.0 bundled PHPCS code-analysis ruleset on the main file and `includes/`: passed with 0 errors and 0 warnings.
- `git diff --check`: passed.
- Readme headers verified: `Contributors: themona`, `Tested up to: 7.1`, `Stable tag: 0.3.3`.
- Plugin headers verified: version 0.3.3 and text domain `mona-pay-for-woocommerce`.
- Archive contains one root folder only: `mona-pay-for-woocommerce/`.
- Archive excludes `.gitignore`, tests, `dist`, WordPress.org artwork, build scripts, reports, briefs, CSV findings, and other internal documentation.

## Release archive contents

- `LICENSE`
- `readme.txt`
- `mona-pay-for-woocommerce.php`
- `includes/*.php`
- `assets/js/admin-settings.js`
- `languages/mona-pay-for-woocommerce.pot`

No push or WordPress.org submission was performed.
