# STATUS — WooCommerce MONA Pay 0.1.0

## Trạng thái

Mã nguồn theo brief đã hoàn tất trong `woocommerce-monapay/`. Plugin chưa được gọi API production, chưa tạo VA/QR thật và chưa được nộp lên WordPress.org.

Gate PHP runtime bắt buộc **chưa thể chạy trong sandbox hiện tại** vì không có PHP CLI (`zsh: command not found: php`). Docker CLI có mặt nhưng socket Docker bị sandbox từ chối; không cài thêm dependency theo yêu cầu đề bài.

## Đã làm

- Plugin header/readme chuẩn WordPress.org; version và Stable tag `0.1.0`, text domain `woocommerce-monapay`, PHP tối thiểu 7.4.
- Gateway `WC_Gateway_MonaPay`, ID `monapay_vietqr`, mặc định “Chuyển khoản VietQR (tự xác nhận)”; chỉ hiện cho VND khi cấu hình đủ.
- Cấu hình Base URL, tài khoản/mật khẩu MONA Pay, Client Secret, đầu số VA, `ownerNumber`, `ownerType`, `merchantId`, `terminalId`, `beneficiaryName`, HMAC secret và tự hoàn tất đơn.
- API client đăng nhập `/api/v1/client/login`, cache Bearer theo hạn token, gửi `X-Client-Secret`, tự làm mới một lần khi gặp 401.
- Checkout gọi `/api/v1/acb/qr-payment/generate` đúng field; `orderId` là ID nội bộ WooCommerce, `description` là `DH{id}`, số tiền VND nguyên; lưu ID QR, chuỗi QR, VA vào order meta và đưa đơn sang `on-hold`.
- Bộ dựng QR PNG nội bộ, không CDN/dịch vụ QR thứ ba; ảnh được phục vụ qua `admin-post.php` sau khi xác minh `order_key`, dùng được ở thank-you, view-order và email HTML.
- REST webhook `POST /wp-json/monapay/v1/webhook`: ký trên raw body bằng `HMAC-SHA256(secret, "<timestamp>.<raw_body>")`, tolerance 300 giây, `hash_equals`, payload validation, nhận gói thử `DUMMY123` mà không sửa đơn.
- Khớp đơn qua `DH{id}` hoặc VA, kiểm `amount >= total`, gọi `payment_complete(transaction_code)`, chống trùng bằng `_monapay_txn_codes`, log qua WooCommerce logger; có tuỳ chọn chuyển thẳng `completed`.
- Admin có nút sinh secret bằng Web Crypto và nút gọi `POST /api/v1/client-webhooks/test`; AJAX có capability + nonce.
- Tiếng Việt là ngôn ngữ nguồn; POT có đủ 70 chuỗi. Có `readme.txt`, FAQ/privacy/changelog, license, test thuần PHP và banner placeholder.
- Khai báo tương thích WooCommerce HPOS.

## Kết quả gate

- `php tests/test-signature.php`: **CHƯA CHẠY — BLOCKED ENV**, máy không có `php` CLI. File có 14 assertion cho vector HMAC đã biết, raw-body mutation, chữ ký/timestamp sai và parse `DH{id}`.
- PHP syntax-tree parser có sẵn trong máy: **PASS**, 9 file PHP, 0 lỗi/0 missing node.
- Gate phát hiện cú pháp/helper chỉ có ở PHP 8: **PASS**; mã giữ tương thích PHP 7.4.
- `node --check assets/js/admin-settings.js`: **PASS**.
- `msgfmt --check --check-format` và so POT với source: **PASS**, 70/70 msgid.
- Gate invariant brief/bảo mật: **PASS**, 18/18.
- Đối chiếu bảng Reed-Solomon level M và công thức alignment QR với reference cục bộ: **PASS**, version 1–40.
- Vector HMAC độc lập bằng OpenSSL: **PASS**, `c7b09ff9e0e8eaee7d31e9c35f08fb41222543b8d6e793f1c4eed5b23008d28a`.
- Không có tên nhà cung cấp bị cấm, tài khoản test production, trailing whitespace hoặc `.DS_Store`: **PASS**.

Chạy lại gate bắt buộc trên máy đã có PHP 7.4+ trước khi phát hành:

```bash
cd woocommerce-monapay
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/test-signature.php
php tests/test-qr.php /tmp/monapay-test-qr.png
```

Sau đó cài trên một site staging có WooCommerce để smoke test: lưu settings, đặt đơn VND, kiểm trạng thái `on-hold`, ảnh QR ở thank-you/email, bấm “Gửi webhook thử”, gửi lại cùng `transaction_code`, thử giao dịch thiếu tiền và kiểm WooCommerce log source `woocommerce-monapay`.

## Cách nộp WordPress.org cho Mon

1. Chạy đủ PHP gate và smoke test staging ở trên. Chỉ tăng `Tested up to`/`WC tested up to` sau khi đã kiểm đúng phiên bản đó.
2. Thay `assets/banner-placeholder.txt` bằng banner thật `banner-772x250.png` và `banner-1544x500.png`. Khi dùng SVN WordPress.org, banner đặt trong thư mục `assets/` ở root SVN, không nằm trong `trunk/`.
3. Rà lại không có credential/log/đơn test. Tạo ZIP nộp duyệt ban đầu, không kèm tài liệu handoff và test:

```bash
cd /Users/themon/MONApay/plugins
zip -r woocommerce-monapay-0.1.0.zip woocommerce-monapay \
  -x 'woocommerce-monapay/STATUS.md' \
     'woocommerce-monapay/tests/*' \
     'woocommerce-monapay/assets/banner-placeholder.txt'
```

4. Mon đăng nhập tài khoản developer tại `wordpress.org/plugins/developers/add/`, nộp ZIP và đăng ký slug `woocommerce-monapay`. Không đổi slug/text domain sau khi duyệt.
5. Khi WordPress.org cấp SVN: đưa runtime/readme vào `trunk/`, banner/icon vào root `assets/`, tạo `tags/0.1.0/` từ đúng nội dung release; kiểm `Stable tag: 0.1.0`, rồi commit bằng tài khoản WordPress.org của Mon.
6. Sau khi publish, cài bản tải trực tiếp từ WordPress.org lên staging lần cuối và lặp lại checkout + webhook smoke test trước khi bật trên shop thật.

