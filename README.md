# MONA Pay for WooCommerce

WordPress plugin that lets WooCommerce stores take VND bank transfers through VietQR and mark orders paid automatically when a signed MONA Pay webhook confirms the transfer.

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later
- WooCommerce 8.0 or later, with the store currency set to `VND`
- A public HTTPS site with an accurate server clock (signed requests are only valid for five minutes)
- A MONA Pay account with an API Client ID and Client Secret

## Install

Install from WordPress.org: in **Plugins → Add New**, search for "MONA Pay for WooCommerce", or download it from [wordpress.org/plugins/mona-pay-for-woocommerce](https://wordpress.org/plugins/mona-pay-for-woocommerce/) (slug `mona-pay-for-woocommerce`). Activate WooCommerce first, then activate the plugin.

## Configuration

Go to **WooCommerce → Settings → Payments → MONA Pay VietQR** (gateway ID `monapay_vietqr`).

1. Choose the **Payment flow**:
   - **Redirect to the MONA Pay payment page** (default): the customer pays on the hosted checkout at `pay.monapay.vn` and returns to the store. The return is verified with HMAC-SHA256 and reconciled against the MONA Pay API before the order is marked paid.
   - **Show the QR in your store**: a per-order VietQR is shown on the order confirmation page, the order page and the customer email.
2. Enter the **Client ID** and **Client Secret** created in the MONA Pay dashboard under API Keys. **Base URL** defaults to `https://api.monapay.vn`.
3. For the redirect flow, enter the **Return signature secret** from the MONA Pay dashboard (Settings → Payment page).
4. For the in-store QR flow, enter the VietQR details: virtual account prefix, receiving account number, account holder type (`ORG` or `PER`), Merchant ID, Terminal ID and beneficiary name.
5. Generate the **Secret HMAC webhook**, create an `HMAC_SHA256` webhook in MONA Pay with the same secret and the URL shown in the settings (`https://example.com/wp-json/monapay/v1/webhook`), then use the test button to check delivery.

Optional settings: **Test mode (sandbox)** for hosted-checkout testing, a sandbox virtual account for the test-transaction button, **Complete orders automatically**, and an opt-in MONA Pay credit line on the order page.

The payment method only appears at checkout when the currency is VND and every required setting for the selected flow is saved. Logs are under **WooCommerce → Status → Logs**, source `mona-pay-for-woocommerce`.

## Usage

- Incoming webhooks (`TRANSACTION_IN` and `CHECKOUT_PAID`) are verified on the unmodified request body, checked against a five-minute timestamp window and deduplicated by transaction code.
- The paid amount is validated before WooCommerce payment completion is called. Underpayments add an order note and leave the order unpaid.
- If a customer cancels hosted checkout, the order stays pending and the customer can reopen the active checkout from the order page.
- QR images are rendered by the plugin itself; QR content is not sent to a third-party image service.
- The plugin supports WooCommerce High-Performance Order Storage and the Cart and Checkout blocks.

See [readme.txt](readme.txt) for the data sent to the MONA Pay API and when, and [monapay.vn/docs](https://monapay.vn/docs) for the API reference.

## Development

The tests are standalone PHP scripts with no WordPress or PHPUnit dependency:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/test-signature.php
php tests/test-return-signature.php
php tests/test-checkout-sandbox.php
php tests/test-oauth.php
php tests/test-qr.php /tmp/monapay-test-qr.png
node --check assets/js/admin-settings.js
node --check assets/js/blocks-checkout.js
```

CI runs the same steps on PHP 8.2 (`.github/workflows/test.yml`). Report security issues as described in [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

**MONA Pay is part of MONA Cloud by The MONA Group.**
