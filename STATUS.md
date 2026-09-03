# STATUS: WooCommerce MONA Pay 0.2.0

## Trạng thái

Đã triển khai brief 0.2.0 trong working tree. Chưa commit, push hoặc nộp WordPress.org.

## Hoàn tất

- OAuth client credentials qua `/api/v1/oauth/token`, cache theo `expires_in`, làm mới một lần khi HTTP 401 và giữ fallback ẩn username/password của 0.1.0.
- Cài đặt Client ID/Client Secret, công cụ bắn webhook thử và tạo giao dịch sandbox 10.000 VND theo số VA đầy đủ.
- Dòng nhận diện MONA Pay tùy chọn dưới QR ở thank-you/view-order, mặc định bật theo brief.
- `readme.txt` bản 0.2.0 với External services, Installation, FAQ, Screenshots và Changelog; plugin header có `Requires Plugins: woocommerce`.
- Bộ banner, icon và bốn screenshot mockup trong `assets/wporg/`.
- `tests/test-oauth.php`, POT cập nhật, workflow CI mở rộng và `build-zip.sh`.
- ZIP phát hành tại `dist/woocommerce-monapay-0.2.0.zip`, không chứa test, wporg assets, Git, status, report hay script build.

## Gate tại máy hiện tại

- `node --check assets/js/admin-settings.js`: PASS.
- `sh -n build-zip.sh`: PASS.
- `msgfmt --check --check-format`: PASS, chỉ có warning placeholder chuẩn của POT.
- `git diff --check`: PASS.
- Kích thước 8 PNG WordPress.org: PASS.
- Kiểm tra nội dung ZIP và danh sách loại trừ: PASS.
- PHP lint và ba test PHP: chưa chạy vì máy không có PHP CLI. CI đã được cấu hình chạy đủ.
- Chrome/Chromium headless: môi trường macOS sandbox từ chối Mach port. Bốn PNG mockup được dựng từ cùng layout bằng Pillow; cần thay bằng ảnh store thật trước khi nộp nếu có staging.

Chi tiết và lệnh xác minh nằm trong `REPORT-WOO-PLUGIN-0.2.0.md`.
