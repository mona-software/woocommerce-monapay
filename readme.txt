=== MONA Pay for WooCommerce ===
Contributors: themonagroup
Tags: monapay, vietqr, bank transfer, payment gateway, woocommerce
Requires at least: 6.2
Tested up to: 6.8
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tạo VietQR theo đơn hàng và tự xác nhận chuyển khoản WooCommerce bằng webhook HMAC của MONA Pay.

== Description ==

MONA Pay for WooCommerce creates order-specific VietQR payments and automatically confirms bank transfers through signed webhooks. Funds go directly to the merchant's bank account; MONA Pay does not hold customer funds.

MONA Pay là API ngân hàng cho doanh nghiệp Việt Nam nhận và xác nhận chuyển khoản theo thời gian thực qua tài khoản ảo (VA), VietQR và webhook. Plugin cung cấp phương thức “Chuyển khoản VietQR (tự xác nhận)” cho WooCommerce:

* Tạo VietQR động theo đúng số tiền và mã đơn.
* Đưa đơn sang trạng thái Tạm giữ (`on-hold`) sau khi tạo QR.
* Hiển thị ảnh QR trên trang cảm ơn, trang xem đơn và email HTML của khách.
* Xác minh webhook HMAC-SHA256 trên raw body, giới hạn timestamp 300 giây.
* So khớp đơn bằng nội dung `DH{id}` hoặc số tài khoản ảo.
* Kiểm tra số tiền trước khi gọi `payment_complete()`.
* Chống xử lý trùng bằng `transaction_code`.
* Có nút bắn webhook thử và tạo giao dịch sandbox 10.000 VND trong cài đặt.
* Hỗ trợ WooCommerce High-Performance Order Storage (HPOS).

Plugin dùng Client ID và Client Secret lấy tại my.monapay.vn, không cần lưu mật khẩu tài khoản MONA Pay. Khi nâng cấp từ 0.1.0, thông tin đăng nhập cũ tiếp tục hoạt động ở chế độ fallback ẩn cho tới khi anh chị lưu Client ID mới.

Dưới ảnh QR ở trang cảm ơn và trang xem đơn, plugin có thể hiển thị dòng “Xác nhận thanh toán tự động bởi MONA Pay” liên kết tới monapay.vn. Tuỳ chọn nhận diện này mặc định bật và có thể tắt trong cài đặt gateway. Plugin không thêm tracker vào liên kết hay giao diện cửa hàng.

Plugin không tải thư viện từ CDN và không gửi chuỗi thanh toán tới dịch vụ dựng QR bên thứ ba. Bộ dựng PNG QR Code chế độ byte, mức sửa lỗi M được phát hành cùng plugin theo GPLv2 hoặc mới hơn.

Tài liệu: https://monapay.vn/docs | Hotline: 1900 636 648 | Email: info@themona.global

== Installation ==

1. Đăng ký tài khoản tại https://my.monapay.vn/ và cài, kích hoạt plugin cùng WooCommerce.
2. Trong my.monapay.vn, vào API Keys > Tạo key; lưu Client ID và Client Secret được hiển thị một lần.
3. Nối tài khoản ACB trong dashboard và xác thực bằng OTP theo hướng dẫn.
4. Vào WooCommerce > Cài đặt > Thanh toán > MONA Pay VietQR; dán Client ID, Client Secret, đầu số VA và các thông tin VietQR được cấp.
5. Tạo Secret HMAC, lưu cài đặt, cấu hình URL webhook đang hiển thị trên MONA Pay rồi bấm “Bắn webhook thử”.

Website phải dùng HTTPS công khai để MONA Pay gọi được webhook. Đồng hồ máy chủ cần chính xác để kiểm tra cửa sổ chữ ký 300 giây. Muốn thử luồng giao dịch không chuyển tiền thật, nhập số VA đầy đủ vào ô sandbox, lưu cài đặt rồi bấm “Tạo giao dịch thử (sandbox)”.

== Frequently Asked Questions ==

= Tiền của khách có đi qua MONA Pay không? =

Không. Tiền chuyển thẳng vào tài khoản ngân hàng của anh chị. MONA Pay nhận thông báo giao dịch từ ngân hàng và gửi webhook có chữ ký về WooCommerce để xác nhận đơn.

= Plugin hỗ trợ ngân hàng nào? =

ACB đang hoạt động. Tụi em sẽ bổ sung nhiều ngân hàng theo lộ trình sản phẩm.

= Phí sử dụng là bao nhiêu? =

Gói khởi đầu miễn phí 500 giao dịch mỗi tháng. Khách hàng MONA được miễn phí theo chính sách hiện hành của MONA Pay.

= Plugin dùng URL webhook nào? =

URL có dạng `https://ten-mien-cua-ban.vn/wp-json/monapay/v1/webhook`. URL chính xác được hiển thị trong cài đặt gateway.

= Vì sao phương thức không xuất hiện ở checkout? =

Plugin chỉ hiện với tiền tệ VND và khi Client ID, Client Secret, thông tin VietQR cùng Secret HMAC đã được lưu. Bản nâng cấp từ 0.1.0 vẫn dùng được username/password cũ khi Client ID còn trống.

= Khách chuyển thiếu tiền thì sao? =

Plugin ghi chú vào đơn, ghi WooCommerce log và giữ nguyên trạng thái. Webhook hợp lệ vẫn được xác nhận đã nhận để tránh gửi lại vô ích.

= Tôi tìm log ở đâu? =

Vào WooCommerce > Trạng thái > Logs và chọn source `woocommerce-monapay`.

== Screenshots ==

1. Cài đặt gateway với Client ID, Client Secret, VietQR, webhook và công cụ sandbox.
2. Checkout hiển thị phương thức Chuyển khoản VietQR tự xác nhận.
3. Trang cảm ơn hiển thị QR, số tiền, VA và dòng nhận diện MONA Pay có thể tắt.
4. Nhật ký webhook trong WooCommerce giúp kiểm tra giao dịch đã xác nhận.

== External services ==

Plugin kết nối tới dịch vụ MONA Pay tại `https://api.monapay.vn` khi quản trị viên cấu hình gateway và khách chọn thanh toán. Dịch vụ được dùng để cấp access token, tạo VietQR, bắn webhook thử và tạo giao dịch sandbox theo yêu cầu của quản trị viên.

Khi tạo VietQR, plugin gửi mã đơn, số tiền và thông tin tài khoản nhận do quản trị viên cấu hình. Khi dùng công cụ thử, plugin gửi URL webhook, cấu hình HMAC hoặc số VA sandbox tương ứng. Plugin không tự gửi dữ liệu phân tích, dữ liệu quảng cáo hay dữ liệu theo dõi.

Chính sách bảo mật: https://monapay.vn/chinh-sach-bao-mat

Điều khoản dịch vụ: https://monapay.vn/dieu-khoan

== Privacy ==

Plugin lưu ID QR, chuỗi QR, số tài khoản ảo và mã giao dịch đã xử lý trong meta riêng của đơn hàng. Client Secret và Secret HMAC được lưu trong cài đặt WordPress của gateway, chỉ dùng phía máy chủ. Front-end và email không nhận các secret này. Plugin không có tracker và không tải tài nguyên từ CDN.

== Changelog ==

= 0.2.0 =

* Chuyển xác thực mặc định sang OAuth client credentials bằng Client ID và Client Secret, cache token theo `expires_in`, tự làm mới một lần khi gặp HTTP 401.
* Giữ fallback ẩn username/password cho website nâng cấp từ 0.1.0.
* Thêm công cụ tạo giao dịch sandbox 10.000 VND cho VA đã cấu hình.
* Thêm dòng nhận diện MONA Pay tuỳ chọn dưới ảnh QR trên trang cảm ơn và trang xem đơn.
* Bổ sung khai báo dịch vụ ngoài, tài sản WordPress.org, test OAuth và script đóng gói phát hành.

= 0.1.0 =

* Phát hành đầu tiên với VietQR động, ảnh QR nội bộ, webhook HMAC, chống trùng, gửi thử webhook và HPOS.
