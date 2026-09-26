# Brief 0.3.3 — qua sạch Plugin Check để nộp WordPress.org (26/09/2026)

Kết quả Plugin Check bản 0.3.2: `PLUGIN-CHECK-0.3.2.csv` (cùng thư mục). Sửa để Plugin Check ra 0 ERROR, WARNING chỉ còn loại có lý do chính đáng (ghi chú phpcs:ignore kèm giải thích).

1. **Đổi slug/thư mục/file chính** sang `mona-pay-for-woocommerce` (Plugin Name giữ "MONA Pay for WooCommerce"; file chính `mona-pay-for-woocommerce.php`; Text Domain `mona-pay-for-woocommerce`; cập nhật mọi `__()`/`esc_html__()`… text domain; build-zip.sh ra `dist/mona-pay-for-woocommerce-0.3.3.zip` với thư mục gốc `mona-pay-for-woocommerce/`). Giữ tương thích dữ liệu: option key/gateway id cũ GIỮ NGUYÊN (không làm mất cấu hình người đang dùng).
2. **readme.txt viết lại bằng tiếng Anh chuẩn** (short description, description, installation, FAQ, changelog, mục "External services" nêu rõ gọi api.monapay.vn / pay.monapay.vn, dữ liệu gửi đi, link Terms https://monapay.vn/dieu-khoan và Privacy https://monapay.vn/chinh-sach-bao-mat). `Contributors: themona`. `Tested up to: 7.1`. `Stable tag: 0.3.3`. Có thể giữ một đoạn ngắn tiếng Việt ở cuối description sau phần tiếng Anh. Mô tả đúng: "automatic bank-transfer confirmation (VietQR, virtual accounts, HMAC webhooks); funds go straight to the merchant's bank account, MONA Pay never holds funds". Không gọi là payment gateway giữ tiền; tag có thể giữ "bank transfer, vietqr, woocommerce, vietnam, payment".
3. Thêm `if ( ! defined( 'ABSPATH' ) ) { exit; }` cho mọi file PHP thiếu (includes/class-monapay-qr-code.php, includes/monapay-functions.php và mọi file khác).
4. Nonce warnings ở return/QR endpoint/gateway: các tham số GET đến từ redirect của pay.monapay.vn/đường dẫn công khai nên không có nonce → xác thực bằng chữ ký/khoá đơn (order key) nếu chưa có, sanitize bằng `sanitize_text_field( wp_unslash( … ) )`, rồi thêm `// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- <lý do>` đúng dòng.
5. Bỏ `load_plugin_textdomain()`.
6. Version header + Stable tag + changelog = 0.3.3. Không đưa `.gitignore`, tests/, tài liệu nội bộ vào zip.
7. Chạy lại test có sẵn (`php tests/*.php`) — máy đã có PHP 8.5 (`php`), ghi kết quả vào REPORT-0.3.3.md.
Không push, không nộp.
