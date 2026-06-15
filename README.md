<div align="center">

# 🛒 HỆ THỐNG QUẢN LÝ BÁN HÀNG TẠI QUẦY

### Website hỗ trợ quản lý sản phẩm, bán hàng, hóa đơn, tồn kho và doanh thu

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge\&logo=php\&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?style=for-the-badge\&logo=mysql\&logoColor=white)
![HTML](https://img.shields.io/badge/HTML5-Frontend-E34F26?style=for-the-badge\&logo=html5\&logoColor=white)
![CSS](https://img.shields.io/badge/CSS3-Interface-1572B6?style=for-the-badge\&logo=css3\&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-Interaction-F7DF1E?style=for-the-badge\&logo=javascript\&logoColor=black)
![XAMPP](https://img.shields.io/badge/XAMPP-Local_Server-FB7A24?style=for-the-badge\&logo=xampp\&logoColor=white)

</div>

---

## 📖 Giới thiệu

Hệ thống quản lý bán hàng tại quầy là đồ án môn **Hệ thống thông tin quản lý**. Website được xây dựng nhằm mô phỏng quy trình bán hàng tại cửa hàng bán lẻ, giúp quản lý sản phẩm, thực hiện giao dịch, lập hóa đơn, cập nhật tồn kho và thống kê doanh thu.

Hệ thống gồm hai vai trò chính là **người quản lý** và **nhân viên bán hàng**. Mỗi vai trò được cung cấp các chức năng phù hợp với nhiệm vụ và quyền hạn.

---

## ✨ Chức năng chính

### 🔐 Đăng nhập và phân quyền

Hệ thống xác thực tài khoản trước khi cho phép truy cập. Người quản lý và nhân viên được sử dụng các chức năng khác nhau theo quyền hạn.

### 📦 Quản lý sản phẩm

Người quản lý có thể thêm, sửa, tìm kiếm và thay đổi trạng thái kinh doanh của sản phẩm. Hệ thống lưu thông tin mã sản phẩm, tên, danh mục, giá bán, số lượng tồn và mức cảnh báo.

### 🛍️ Bán hàng tại quầy

Nhân viên có thể tìm sản phẩm, thêm sản phẩm vào giỏ hàng, thay đổi số lượng và xóa sản phẩm. Tổng tiền được cập nhật tự động.

### 💵 Thanh toán

Hệ thống tính tổng tiền, tiền khách đưa và tiền thừa. Trước khi thanh toán, số lượng tồn kho được kiểm tra lại để tránh bán vượt số lượng hiện có.

### 🧾 Quản lý hóa đơn

Hệ thống lưu thông tin hóa đơn và chi tiết các sản phẩm đã bán. Người dùng có thể tìm kiếm, xem chi tiết và in hóa đơn.

### ↩️ Hủy hóa đơn

Người quản lý có thể hủy hóa đơn khi có sai sót. Sản phẩm trong hóa đơn được hoàn trả lại kho và hóa đơn đã hủy không được tính vào doanh thu.

### ⚠️ Cảnh báo tồn kho

Sản phẩm có số lượng thấp hơn mức cảnh báo sẽ được hiển thị trong danh sách sắp hết hàng, giúp người quản lý chủ động nhập thêm hàng.

### 📊 Thống kê doanh thu

Hệ thống thống kê tổng doanh thu, số hóa đơn, số lượng sản phẩm đã bán và giá trị trung bình của hóa đơn theo khoảng thời gian.

### 🏆 Sản phẩm bán chạy

Người quản lý có thể theo dõi những sản phẩm có số lượng bán cao để lập kế hoạch nhập hàng phù hợp.

### 🕒 Lịch sử thao tác

Hệ thống ghi lại các thao tác quan trọng như đăng nhập, thêm sản phẩm, sửa sản phẩm, tạo hóa đơn và hủy hóa đơn.

---

## 🖼️ Giao diện hệ thống

### Trang đăng nhập

<p align="center">
  <img src="assets/images/login.png" alt="Trang đăng nhập" width="800">
</p>

### Trang tổng quan

<p align="center">
  <img src="assets/images/dashboard.png" alt="Trang tổng quan" width="800">
</p>

### Trang bán hàng

<p align="center">
  <img src="assets/images/sales.png" alt="Trang bán hàng" width="800">
</p>

> Nếu chưa có hình ảnh, hãy chụp màn hình website và lưu đúng tên trong thư mục `assets/images`.

---

## 🛠️ Công nghệ sử dụng

| Công nghệ  | Mục đích                        |
| ---------- | ------------------------------- |
| PHP        | Xử lý chức năng phía máy chủ    |
| MySQL      | Lưu trữ và quản lý dữ liệu      |
| HTML       | Xây dựng cấu trúc giao diện     |
| CSS        | Thiết kế và định dạng giao diện |
| JavaScript | Xử lý tương tác trên trang web  |
| XAMPP      | Cung cấp Apache và MySQL        |
| phpMyAdmin | Quản lý và nhập cơ sở dữ liệu   |

---

## 📁 Cấu trúc thư mục

```text
quan-ly-ban-hang
│
├── actions
│   ├── cancel_invoice.php
│   ├── login_action.php
│   ├── product_action.php
│   └── sale_action.php
│
├── assets
│   ├── css
│   │   └── style.css
│   ├── images
│   └── js
│       └── main.js
│
├── config
│   ├── auth.php
│   └── database.php
│
├── database
│   └── quan_ly_ban_hang.sql
│
├── partials
│   ├── footer.php
│   └── header.php
│
├── activity_logs.php
├── dashboard.php
├── index.php
├── invoice_detail.php
├── invoices.php
├── login.php
├── logout.php
├── products.php
├── sales.php
├── statistics.php
└── README.md
```

---

## 🚀 Hướng dẫn cài đặt

### Bước 1 Sao chép dự án

Đặt thư mục dự án vào:

```text
C:\xampp\htdocs\quan-ly-ban-hang
```

### Bước 2 Khởi động XAMPP

Mở XAMPP Control Panel và bật hai dịch vụ:

```text
Apache
MySQL
```

### Bước 3 Nhập cơ sở dữ liệu

Truy cập:

```text
http://localhost/phpmyadmin
```

Chọn **Import** và nhập file:

```text
database/quan_ly_ban_hang.sql
```

### Bước 4 Mở website

Truy cập:

```text
http://localhost/quan-ly-ban-hang
```

---

## 👤 Tài khoản thử nghiệm

| Vai trò   | Tên đăng nhập | Mật khẩu |
| --------- | ------------- | -------- |
| Quản lý   | admin         | 123456   |
| Nhân viên | nhanvien      | 123456   |

> Các tài khoản trên chỉ dùng để chạy thử hệ thống.

---

## 🔄 Quy trình bán hàng

```text
Khách hàng chọn sản phẩm
          ↓
Nhân viên tìm kiếm sản phẩm
          ↓
Thêm sản phẩm vào giỏ hàng
          ↓
Hệ thống kiểm tra tồn kho
          ↓
Tính tổng tiền và tiền thừa
          ↓
Lưu hóa đơn
          ↓
Cập nhật số lượng tồn kho
          ↓
Hoàn tất giao dịch
```

---

## 🎯 Kết quả đạt được

Hệ thống đã mô phỏng được quy trình bán hàng cơ bản tại cửa hàng. Các chức năng quản lý sản phẩm, bán hàng, thanh toán, hóa đơn, tồn kho và thống kê được liên kết với nhau thông qua cơ sở dữ liệu.

Hệ thống giúp giảm thao tác tính toán thủ công, hạn chế sai sót khi bán hàng và cung cấp thông tin hỗ trợ người quản lý theo dõi hoạt động kinh doanh.

---

## 🔮 Hướng phát triển

Trong tương lai, hệ thống có thể bổ sung các chức năng quét mã vạch, thanh toán bằng mã QR, quản lý nhà cung cấp, quản lý nhập hàng, khách hàng thân thiết, xuất hóa đơn PDF và quản lý nhiều chi nhánh.

---

## 👥 Thành viên thực hiện

| STT | Họ và tên      | Mã số sinh viên      | Nhiệm vụ                       |
| --: | -------------- | -------------------- | ------------------------------ |
|   1 | Nguyễn Thế Anh | Điền mã số sinh viên | Phân tích và xây dựng hệ thống |
|   2 | Thành viên 2   | Điền mã số sinh viên | Điền nhiệm vụ                  |
|   3 | Thành viên 3   | Điền mã số sinh viên | Điền nhiệm vụ                  |

---

<div align="center">

### ⭐ Cảm ơn bạn đã xem dự án

Đồ án môn **Hệ thống thông tin quản lý**

</div>

