<div align="center">

# 🛒 HỆ THỐNG QUẢN LÝ BÁN HÀNG TẠI QUẦY

Đồ án môn **Hệ thống thông tin quản lý**

</div>

## Giới thiệu

Website mô phỏng quy trình bán hàng tại cửa hàng bán lẻ, hỗ trợ quản lý sản phẩm, bán hàng, thanh toán, hóa đơn, tồn kho và thống kê doanh thu.

## Cấu trúc dự án

- `code` chứa toàn bộ mã nguồn website
- `database` chứa file SQL dùng để import vào phpMyAdmin
- `README.md` hướng dẫn cài đặt và sử dụng

## Chức năng chính

- Đăng nhập và phân quyền
- Quản lý sản phẩm và tồn kho
- Bán hàng, giỏ hàng và thanh toán
- Lập, xem, in và hủy hóa đơn
- Hoàn trả tồn kho khi hủy hóa đơn
- Cảnh báo hàng sắp hết
- Thống kê doanh thu và sản phẩm bán chạy

## Công nghệ sử dụng

PHP, MySQL, HTML, CSS, JavaScript và XAMPP.

## Cài đặt

1. Chép thư mục `quan-ly-ban-hang` vào `C:\xampp\htdocs`
2. Bật Apache và MySQL trong XAMPP
3. Mở `http://localhost/phpmyadmin`
4. Import file `database/quan_ly_ban_hang.sql`
5. Truy cập `http://localhost/quan-ly-ban-hang/code`

## Tài khoản thử nghiệm

| Vai trò | Tài khoản | Mật khẩu |
|---|---|---|
| Quản lý | admin | 123456 |
| Nhân viên | nhanvien | 123456 |
