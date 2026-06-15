HỆ THỐNG QUẢN LÝ BÁN HÀNG TẠI QUẦY
PHIÊN BẢN CẢI TIẾN

CÁCH CÀI ĐẶT

1. Giải nén và chép thư mục quan-ly-ban-hang vào:
   C:\xampp\htdocs\

2. Mở XAMPP và bật Apache, MySQL.

3. Truy cập phpMyAdmin:
   http://localhost/phpmyadmin

4. Chọn Import và nhập file:
   database\quan_ly_ban_hang.sql

   Lưu ý: nhập lại file này sẽ xóa dữ liệu cũ trong database quan_ly_ban_hang
   và tạo lại dữ liệu mẫu từ đầu.

5. Mở website:
   http://localhost/quan-ly-ban-hang/

TÀI KHOẢN MẪU

Quản lý
Tên đăng nhập: admin
Mật khẩu: 123456

Nhân viên
Tên đăng nhập: nhanvien
Mật khẩu: 123456

CẤU HÌNH

Nếu đổi tên thư mục dự án, mở config\database.php và sửa BASE_URL.
Nếu MySQL có mật khẩu tài khoản root, sửa DB_PASS trong config\database.php.
Tên database mặc định là quan_ly_ban_hang.

CÁC CHỨC NĂNG ĐÃ HOÀN THIỆN

Đăng nhập và phân quyền quản lý, nhân viên.
Quản lý sản phẩm, mức cảnh báo tồn kho và trạng thái kinh doanh.
Bán hàng tại quầy, giỏ hàng, tính tổng tiền và tiền thừa.
Kiểm tra lại tồn kho tại thời điểm thanh toán.
Sử dụng transaction để lưu hóa đơn và trừ tồn kho đồng bộ.
Ngăn tạo hóa đơn trùng khi nhấn thanh toán nhiều lần.
Hủy hóa đơn, hoàn trả tồn kho và lưu lý do hủy.
Thống kê doanh thu không tính hóa đơn đã hủy.
Cảnh báo sản phẩm sắp hết theo mức riêng của từng sản phẩm.
Lưu lịch sử đăng nhập, sản phẩm, hóa đơn và thao tác hủy.
