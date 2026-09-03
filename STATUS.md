# STATUS: WooCommerce MONA Pay 0.3.0

## Trạng thái

Đã triển khai brief 0.3.0 trong working tree. Chưa commit hoặc push.

## Hoàn tất

- Hai chế độ thanh toán: hosted checkout `redirect` mặc định cho cài mới và VietQR `inline` tương thích 0.2.0.
- Tự chuyển cấu hình cũ chưa có `payment_mode` sang `inline` để không đổi hành vi khi nâng cấp.
- Tạo checkout qua `POST /api/v1/checkouts`, Idempotency-Key `wc-<order_id>-<attempt>`, lưu id/token/url, giữ đơn `pending` và chuyển khách sang `checkout_url`.
- Endpoint `woocommerce_api_monapay_return` xác minh HMAC-SHA256 trong 10 phút, đối chiếu `GET /api/v1/checkouts/{id}`, số tiền và mã đơn trước `payment_complete()`.
- Huỷ hosted checkout không huỷ đơn WooCommerce; plugin giữ đơn chờ và hiện thông báo “Chưa thanh toán”.
- Webhook nhận `CHECKOUT_PAID` và `TRANSACTION_IN`, dùng chung hàm kiểm tiền và chống trùng `_monapay_txn_codes`.
- Thank-you, view-order và email hiển thị trạng thái cùng nút mở lại trang thanh toán khi đơn còn chờ.
- Version, readme, POT, test chữ ký return, test API và script build đã lên 0.3.0.
- ZIP phát hành: `dist/woocommerce-monapay-0.3.0.zip`.

## Gate tại máy hiện tại

- `sh -n build-zip.sh`: PASS.
- `node --check assets/js/admin-settings.js`: PASS.
- Parse YAML workflow bằng Ruby: PASS.
- `msgfmt --check --check-format`: PASS, chỉ có warning placeholder chuẩn của POT.
- Quét cân bằng delimiter PHP cho 12 file: PASS.
- `git diff --check`: PASS.
- Kiểm tra version/readme/POT: PASS.
- Kiểm tra nội dung và danh sách loại trừ ZIP: PASS.
- PHP lint và test PHP chưa chạy cục bộ vì máy không có PHP CLI; workflow CI đã chạy các lệnh này khi có PHP 8.2.

Chi tiết nằm trong `REPORT-WOO-PLUGIN-0.3.0.md`.
