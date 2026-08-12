# TGS HTsoft Excel API Queue

Plugin nhận file Excel từ BTauto, xử lý tuần tự và nhập các nghiệp vụ:

- `PNK_`: phiếu nhập kho.
- `PBH_`: phiếu bán hàng.
- `HTL_`: phiếu hàng trả lại.

## API và kết quả cho BTauto

Endpoint: `POST /wp-json/tgs-excel-import/v1/files`

Request dùng `multipart/form-data`, field `files[]`, kèm header `X-API-Key`.

Response thành công có dạng:

```json
{
  "success": true,
  "accepted": true,
  "request_id": "uuid",
  "status": "completed",
  "message": "File đã được tiếp nhận hoặc xử lý; BTauto không cần gửi lại."
}
```

`success = true` khi file đã xử lý hợp lệ, đã được nhập trước đó hoặc các phiếu chỉ bị bỏ qua. BTauto phải lưu tên file và không gửi lại. Chỉ `failed` và `partial` là lỗi thực sự, trả `success = false`.

## Kiến trúc lưu log

Từ phiên bản 1.7.0, lịch sử chi tiết được lưu thành một file JSON Lines cho mỗi ngày trong chính thư mục plugin:

```text
logs/YYYY/MM/excel-api-YYYY-MM-DD.jsonl.php
```

Dòng đầu luôn là `<?php exit; ?>`; mỗi dòng tiếp theo là một JSON object hoàn chỉnh gồm:

- metadata của request, không chứa API key và không chứa nội dung nhị phân Excel;
- response đã trả cho BTauto;
- danh sách file và thống kê xử lý;
- log chi tiết từng phiếu.

File được ghi bằng khóa độc quyền để tránh hai request ghi xen nhau. `request_uuid` được dùng để chống ghi trùng khi hệ thống phục hồi sau gián đoạn. Log quá 90 ngày được tự động xóa.

Thư mục `logs` có `.htaccess`, `web.config` và file log mang đuôi `.php` để hạn chế truy cập trực tiếp. Quyền ghi PHP vào thư mục này là bắt buộc.

## Vai trò của MySQL

Ba bảng global vẫn tồn tại:

- `{base_prefix}global_htsoft_excel_api_request`: trạng thái request và cờ archive.
- `{base_prefix}global_htsoft_excel_api_file`: queue và idempotency theo tên file. Bảng này phải được giữ để file đã nhận không bị gửi/import lại.
- `{base_prefix}global_htsoft_excel_api_voucher_log`: staging chi tiết phiếu trong lúc xử lý.

Khi request kết thúc, plugin ghi record tổng hợp vào JSONL trước. Chỉ sau khi ghi và đánh dấu archive thành công, plugin mới xóa voucher staging và làm rỗng các cột JSON/lỗi lớn trong DB. Nếu ghi file thất bại, dữ liệu DB vẫn còn và hệ thống tự thử lại ở các request WordPress sau. Màn quản trị sẽ hiện cảnh báo lỗi ghi log.

Dữ liệu DB có từ trước phiên bản 1.7.0 được giữ nguyên, không tự migrate sang JSONL.

## Màn quản trị

Màn `Nhập Excel tự động qua API` mặc định đọc file log của ngày hiện tại. Dùng ô `Ngày log` để xem một ngày khác trong phạm vi lưu trữ 90 ngày.

Mỗi bảng mặc định cao tương ứng 10 dòng và có thanh cuộn. Có thể chọn 20, 50 hoặc 100 dòng. Nội dung lỗi dài được rút gọn; bấm nút `…` để xem đầy đủ trong modal. Request và response cũng được xem trong modal riêng.

## Deploy và sao lưu

Khi cập nhật hoặc đồng bộ plugin, phải bảo toàn thư mục `logs/`. Không triển khai theo cách xóa toàn bộ thư mục plugin rồi chép lại nếu chưa sao lưu log.

Nên đưa `logs/` vào lịch sao lưu cùng database. Các file runtime dưới `logs/YYYY/MM/` đã được `.gitignore`; chỉ các file bảo vệ thư mục được commit vào mã nguồn.

## Nâng cấp schema

Phiên bản DB 1.3.0 thêm các cột sau vào bảng request:

- `log_archive_required`
- `log_file_date`
- `log_archived_at`

`dbDelta()` tự chạy khi plugin được tải sau cập nhật. Nếu server không cho phép sửa schema hoặc không cho PHP ghi vào `logs/`, kiểm tra cảnh báo trên màn quản trị và quyền filesystem.
