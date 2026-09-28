=== MONA Pay for WooCommerce ===
Contributors: themona
Tags: bank transfer, vietqr, woocommerce, vietnam, payment
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 0.3.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatic bank-transfer confirmation with VietQR, virtual accounts, and signed webhooks for WooCommerce stores in Vietnam.

== Description ==

MONA Pay for WooCommerce provides automatic bank-transfer confirmation using VietQR, virtual accounts, and HMAC-signed webhooks. Funds go straight to the merchant's bank account; MONA Pay never holds funds.

This plugin relies on the MONA Pay API, an external service operated by The MONA Group. See the "External services" section below for what is sent, when, and the links to its terms of service and privacy policy.

The plugin supports two payment experiences:

* Hosted checkout redirects the customer to `pay.monapay.vn`, keeps the order pending, and returns the customer to the store after payment.
* Inline VietQR displays a per-order QR code on the order confirmation page, order page, and customer email.

Payment returns are verified with HMAC-SHA256 and then reconciled against the MONA Pay API before an order is marked paid. Incoming webhooks are authenticated from the unmodified request body, checked against a five-minute timestamp window, and deduplicated by transaction code. The plugin validates the paid amount before calling WooCommerce payment completion.

Additional features include WooCommerce High-Performance Order Storage compatibility, an optional sandbox checkout mode, test webhook and transaction tools, and a self-contained QR renderer that does not send QR content to a third-party image service.

Existing stores upgrading from earlier releases keep their gateway settings and inline payment-mode preference. Legacy username/password API credentials remain available as a compatibility fallback when no Client ID has been saved.

Vietnamese summary: Plugin giúp cửa hàng WooCommerce nhận chuyển khoản VietQR và tự động xác nhận đơn. Tiền đi thẳng vào tài khoản ngân hàng của người bán; MONA Pay không giữ tiền.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate MONA Pay for WooCommerce.
3. Create an account at `https://my.monapay.vn/` and connect the supported merchant bank account.
4. In the MONA Pay dashboard, create a Client ID and Client Secret and configure the hosted payment profile.
5. Go to WooCommerce > Settings > Payments > MONA Pay VietQR.
6. Select hosted checkout or inline VietQR, enter the requested credentials, and save the settings.
7. Generate a webhook HMAC secret, configure the displayed webhook URL in the MONA Pay dashboard, and use the test button to verify delivery.

The store must use public HTTPS so MONA Pay can reach its webhook endpoint. Keep the server clock accurate because signed requests have a limited validity window.

== Frequently Asked Questions ==

= Does MONA Pay hold customer funds? =

No. Funds are transferred directly to the merchant's bank account. MONA Pay receives transaction information and sends a signed confirmation to WooCommerce.

= Which currencies are supported? =

The payment method is available for VND orders.

= What is the webhook URL? =

The URL has the form `https://example.com/wp-json/monapay/v1/webhook`. The exact URL is shown in the gateway settings.

= Why is the payment method missing at checkout? =

Confirm that the order currency is VND and that all required settings for the selected payment mode have been saved. Hosted checkout requires API credentials, a return-signature secret, and a webhook secret. Inline VietQR also requires the merchant's VietQR and virtual-account details.

= What happens when a customer cancels hosted checkout? =

The order remains pending. The plugin shows a not-paid notice, and the customer can reopen an active checkout from the order page.

= What happens when the transferred amount is too low? =

The plugin adds an order note and leaves the order unpaid. The event is also written to the WooCommerce log.

= Where can I find logs? =

Go to WooCommerce > Status > Logs and select the `mona-pay-for-woocommerce` source.

== Screenshots ==

1. Gateway settings for credentials, payment mode, webhook, and sandbox tools.
2. MONA Pay VietQR at WooCommerce checkout.
3. VietQR payment instructions on the order confirmation page.
4. WooCommerce logs for signed payment notifications.

== External services ==

This plugin connects to MONA Pay, a bank-transfer confirmation service operated by The MONA Group (Ho Chi Minh City, Vietnam). The service is required for the plugin to work: it issues checkout sessions and VietQR payment data, and it notifies the store when a transfer arrives. The plugin does not work without a MONA Pay merchant account.

**1. MONA Pay API (`https://api.monapay.vn`)**

What it is used for: obtaining an API access token, creating and reading hosted checkout sessions, generating VietQR payment data for an order, and running the optional test tools on the settings screen.

What data is sent and when:

* When an administrator saves or tests the gateway settings: the API credentials (Client ID and secret, or legacy username and password) to obtain an access token; when the "send test webhook" button is used, the store's webhook URL and HMAC configuration; when the "create sandbox transaction" button is used, the configured virtual-account number, a test amount and a test order description.
* When a customer places an order with MONA Pay in hosted-checkout mode: the order amount, currency (VND), the merchant order code, a payment description, the return URL and cancellation URL of the store, the customer's billing name and billing email when available, the WooCommerce order ID as metadata, and a sandbox flag when sandbox mode is enabled.
* When a customer places an order in inline VietQR mode: the merchant order code, the order amount and the merchant-configured receiving-account details.
* When a payment return or webhook is received: the checkout ID or transaction code is sent back to the API to confirm the payment status before the order is marked paid.

No data is sent on the storefront when the payment method is not used, and the plugin sends no analytics, advertising or tracking data.

The "Base URL" setting defaults to `https://api.monapay.vn` and exists only so that MONA Pay can point a merchant to a staging endpoint that MONA Pay operates; it is not intended for third-party services.

**2. MONA Pay hosted checkout page (`https://pay.monapay.vn`)**

In hosted-checkout mode the customer's browser is redirected to this page to review the order and complete the bank transfer. The page shows the checkout session created through the API above; the plugin itself sends no additional data to it.

**3. MONA Pay merchant portal (`https://my.monapay.vn`)**

The settings screen links to this portal so administrators can create API keys. The plugin does not send any data to it.

Service provider: MONA Pay, The MONA Group.

* Terms of service: https://monapay.vn/dieu-khoan
* Privacy policy: https://monapay.vn/chinh-sach-bao-mat

== Privacy ==

The plugin stores checkout IDs, tokens, URLs, status, QR data, virtual-account details, sandbox state, and processed transaction codes in private WooCommerce order metadata. API credentials, return-signature secrets, and webhook HMAC secrets are stored in the WordPress gateway settings and used only on the server. Secrets are not included in customer-facing pages or email. The plugin does not load resources from a CDN and does not include a tracker.

== Changelog ==

= 0.3.4 =

* Documented every MONA Pay external service (API, hosted checkout page, merchant portal) with the data sent, the triggering events, and links to the terms of service and privacy policy.
* Limited the WooCommerce dependency notice to the Dashboard, Plugins and WooCommerce screens and made it dismissible.

= 0.3.3 =

* Renamed the WordPress.org slug, main plugin file, and text domain to `mona-pay-for-woocommerce` while preserving the existing gateway ID and option keys.
* Rewrote the plugin readme in English and documented all MONA Pay external-service data flows.
* Added explicit direct-access protection to every PHP file.
* Documented public redirect and image requests, sanitized their query parameters, and authenticated them with HMAC signatures or WooCommerce order keys.
* Removed the obsolete manual translation-loading call and resolved Plugin Check output-escaping findings.

= 0.3.2 =

* Added an optional hosted-checkout sandbox mode and sandbox order indicators.
* Added an order action that creates a correctly valued sandbox transaction for webhook testing.

= 0.3.1 =

* Made the optional MONA Pay attribution link opt-in.

= 0.3.0 =

* Added hosted checkout, signed payment returns, checkout reconciliation, and `CHECKOUT_PAID` webhook support.
* Preserved inline VietQR mode for stores upgrading from version 0.2.0.

= 0.2.0 =

* Added OAuth client credentials, sandbox tools, WordPress.org assets, and automated tests.

= 0.1.0 =

* Initial release with dynamic VietQR, local QR rendering, HMAC webhooks, deduplication, and HPOS support.
