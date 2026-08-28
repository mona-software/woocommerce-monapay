=== MONA Pay for WooCommerce ===
Contributors: themonagroup
Tags: monapay, vietqr, bank transfer, payment gateway, woocommerce
Requires at least: 6.2
Tested up to: 6.8
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tạo VietQR theo đơn hàng và tự xác nhận chuyển khoản WooCommerce bằng webhook HMAC của MONA Pay.

== Description ==

MONA Pay là cổng thanh toán và API ngân hàng của The MONA Group, giúp doanh nghiệp Việt Nam nhận và xác nhận tiền chuyển khoản theo thời gian thực qua tài khoản ảo (VA), VietQR, webhook và Telegram — thiết kế để cả lập trình viên lẫn AI agent tích hợp trong vài phút.

Plugin cung cấp phương thức “Chuyển khoản VietQR (tự xác nhận)” cho WooCommerce:

* Tạo VietQR động theo đúng số tiền và mã đơn.
* Đưa đơn sang trạng thái Tạm giữ (`on-hold`) sau khi tạo QR.
* Hiển thị ảnh QR trên trang cảm ơn, trang xem đơn và email HTML của khách.
* Xác minh webhook HMAC-SHA256 trên raw body, giới hạn timestamp 300 giây.
* So khớp đơn bằng nội dung `DH{id}` hoặc số tài khoản ảo.
* Kiểm tra số tiền trước khi gọi `payment_complete()`.
* Chống xử lý trùng bằng `transaction_code`.
* Có nút tự sinh secret và gửi webhook thử ngay trong cài đặt.
* Hỗ trợ WooCommerce High-Performance Order Storage (HPOS).

Plugin không tải thư viện từ CDN và không gửi chuỗi thanh toán tới dịch vụ dựng QR bên thứ ba. Bộ dựng PNG QR Code chế độ byte, mức sửa lỗi M được viết nội bộ, phát hành cùng plugin theo GPLv2 hoặc mới hơn.

MONA Pay miễn phí hoàn toàn. Tài liệu: https://monapay.vn/docs — Hotline: 1900 636 648 — Email: info@themona.global.

== Installation ==

1. Tải thư mục `woocommerce-monapay` lên `/wp-content/plugins/` hoặc đóng gói thành ZIP rồi cài trong WordPress.
2. Kích hoạt WooCommerce và “MONA Pay for WooCommerce”.
3. Vào WooCommerce > Cài đặt > Thanh toán > MONA Pay VietQR.
4. Nhập Base URL, tài khoản MONA Pay, Client Secret và các giá trị `ownerNumber`, `ownerType`, `merchantId`, `terminalId`, `virtualAccountPrefix`, `beneficiaryName`.
5. Bấm “Tự sinh secret”, lưu cài đặt.
6. Trong https://my.monapay.vn/, tạo webhook tới URL plugin hiển thị. Chọn `HMAC_SHA256`, payload `application/json`, rồi nhập cùng secret.
7. Quay lại WooCommerce và bấm “Gửi webhook thử”. Sau khi thành công, bật phương thức thanh toán.

Website phải dùng HTTPS công khai để MONA Pay gọi được webhook. Đồng hồ máy chủ cần chính xác để kiểm tra cửa sổ chữ ký 300 giây.

== Frequently Asked Questions ==

= Plugin dùng URL webhook nào? =

`https://ten-mien-cua-ban.vn/wp-json/monapay/v1/webhook`. URL chính xác được hiển thị trong cài đặt gateway.

= Vì sao phương thức không xuất hiện ở checkout? =

Plugin chỉ hiện với tiền tệ VND và khi toàn bộ trường API, VietQR, Client Secret và HMAC secret đã được lưu.

= Secret HMAC có xuất hiện ngoài trang quản trị không? =

Không. Secret chỉ được lưu trong option của gateway và dùng phía máy chủ để kiểm chữ ký. Front-end/email chỉ nhận URL ảnh có `order_key` riêng của đơn.

= Plugin xử lý webhook gửi lại thế nào? =

Mỗi `transaction_code` đã xử lý được lưu trong meta `_monapay_txn_codes`. Các lần gửi lại cùng mã trả HTTP 200 nhưng không gọi thanh toán lần nữa.

= Khách chuyển thiếu tiền thì sao? =

Plugin ghi chú vào đơn, ghi WooCommerce log và giữ nguyên trạng thái. Webhook hợp lệ vẫn được xác nhận đã nhận để tránh gửi lại vô ích.

= Tôi tìm log ở đâu? =

Vào WooCommerce > Trạng thái > Logs và chọn source `woocommerce-monapay`.

== Privacy ==

Khi khách chọn gateway, plugin gửi mã đơn, số tiền và thông tin tài khoản nhận do quản trị viên cấu hình tới `api.monapay.vn` để tạo VietQR. Plugin lưu ID QR, chuỗi QR và số tài khoản ảo trong meta riêng của đơn hàng. Không có tracker hoặc tài nguyên CDN.

== Changelog ==

= 0.1.0 =

* Phát hành đầu tiên: VietQR động, ảnh QR nội bộ, webhook HMAC, chống trùng, gửi thử webhook và HPOS.
