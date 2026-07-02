DROP DATABASE IF EXISTS quan_ly_ban_hang;
CREATE DATABASE quan_ly_ban_hang CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE quan_ly_ban_hang;
SET NAMES utf8mb4;


-- =====================================================
-- 1. NGƯỜI DÙNG VÀ PHÂN QUYỀN
-- =====================================================
CREATE TABLE NguoiDung (
  maTK INT AUTO_INCREMENT PRIMARY KEY,
  tenDangNhap VARCHAR(50) NOT NULL UNIQUE,
  matKhau VARCHAR(255) NOT NULL,
  hoTen VARCHAR(120) NOT NULL,
  vaiTro ENUM('admin','cashier','warehouse') NOT NULL DEFAULT 'cashier',
  trangThai TINYINT(1) NOT NULL DEFAULT 1,
  ngayTao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ThuNgan (
  maTK INT PRIMARY KEY,
  ghiChu VARCHAR(255) NULL,
  CONSTRAINT fk_thungan_nguoidung FOREIGN KEY (maTK) REFERENCES NguoiDung(maTK)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE QuanLy (
  maTK INT PRIMARY KEY,
  ghiChu VARCHAR(255) NULL,
  CONSTRAINT fk_quanly_nguoidung FOREIGN KEY (maTK) REFERENCES NguoiDung(maTK)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE NhanVienKho (
  maTK INT PRIMARY KEY,
  ghiChu VARCHAR(255) NULL,
  CONSTRAINT fk_nhanvienkho_nguoidung FOREIGN KEY (maTK) REFERENCES NguoiDung(maTK)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. SẢN PHẨM VÀ KHÁCH HÀNG
-- =====================================================
CREATE TABLE SanPham (
  maSP INT AUTO_INCREMENT PRIMARY KEY,
  maSanPham VARCHAR(30) NOT NULL UNIQUE,
  tenSP VARCHAR(150) NOT NULL,
  danhMuc VARCHAR(100) NOT NULL,
  donGia DECIMAL(15,2) NOT NULL DEFAULT 0,
  soLuongTon INT NOT NULL DEFAULT 0,
  mucCanhBao INT NOT NULL DEFAULT 5,
  donViTinh VARCHAR(30) NOT NULL DEFAULT 'Cái',
  trangThai TINYINT(1) NOT NULL DEFAULT 1,
  ngayTao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ngayCapNhat DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE KhachHang (
  maKH INT AUTO_INCREMENT PRIMARY KEY,
  tenKH VARCHAR(120) NOT NULL,
  sdt VARCHAR(20) NOT NULL UNIQUE,
  diemTichLuy INT NOT NULL DEFAULT 0,
  tongChiTieu DECIMAL(15,2) NOT NULL DEFAULT 0,
  trangThai TINYINT(1) NOT NULL DEFAULT 1,
  ngayTao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bảng hỗ trợ chức năng tích điểm/voucher trong giao diện
CREATE TABLE Voucher (
  maVoucher INT AUTO_INCREMENT PRIMARY KEY,
  maKH INT NOT NULL,
  maGiamGia VARCHAR(50) NOT NULL UNIQUE,
  tenVoucher VARCHAR(150) NOT NULL,
  loaiGiamGia ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  giaTriGiam DECIMAL(15,2) NOT NULL DEFAULT 0,
  donToiThieu DECIMAL(15,2) NOT NULL DEFAULT 0,
  hanSuDung DATETIME NOT NULL,
  trangThai ENUM('available','used','expired','cancelled') NOT NULL DEFAULT 'available',
  maHDNguon INT NULL,
  maHDDaDung INT NULL,
  diemQuyDoi INT NOT NULL DEFAULT 0,
  ngaySuDung DATETIME NULL,
  ngayTao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_voucher_khachhang (maKH),
  CONSTRAINT fk_voucher_khachhang FOREIGN KEY (maKH) REFERENCES KhachHang(maKH)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. HÓA ĐƠN BÁN HÀNG
-- =====================================================
CREATE TABLE HoaDon (
  maHD INT AUTO_INCREMENT PRIMARY KEY,
  soHoaDon VARCHAR(50) NOT NULL UNIQUE,
  maGiaoDich VARCHAR(100) NOT NULL UNIQUE,
  maTK INT NOT NULL,
  maKH INT NULL,
  tenKhachHang VARCHAR(120) NOT NULL DEFAULT 'Khách lẻ',
  sdtKhachHang VARCHAR(20) NULL,
  tamTinh DECIMAL(15,2) NOT NULL DEFAULT 0,
  tienGiam DECIMAL(15,2) NOT NULL DEFAULT 0,
  tongTien DECIMAL(15,2) NOT NULL DEFAULT 0,
  maVoucher INT NULL,
  diemCong INT NOT NULL DEFAULT 0,
  tienKhachDua DECIMAL(15,2) NOT NULL DEFAULT 0,
  tienThua DECIMAL(15,2) NOT NULL DEFAULT 0,
  hinhThucTT ENUM('cash','transfer','qr') NOT NULL DEFAULT 'cash',
  trangThai ENUM('paid','cancelled') NOT NULL DEFAULT 'paid',
  thoiGianHuy DATETIME NULL,
  nguoiHuy INT NULL,
  lyDoHuy VARCHAR(255) NULL,
  ngayLap DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hoadon_nguoidung (maTK),
  INDEX idx_hoadon_khachhang (maKH),
  CONSTRAINT fk_hoadon_nguoidung FOREIGN KEY (maTK) REFERENCES NguoiDung(maTK),
  CONSTRAINT fk_hoadon_khachhang FOREIGN KEY (maKH) REFERENCES KhachHang(maKH),
  CONSTRAINT fk_hoadon_voucher FOREIGN KEY (maVoucher) REFERENCES Voucher(maVoucher),
  CONSTRAINT fk_hoadon_nguoihuy FOREIGN KEY (nguoiHuy) REFERENCES NguoiDung(maTK)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ChiTietHoaDon (
  maCTHD INT AUTO_INCREMENT PRIMARY KEY,
  maHD INT NOT NULL,
  maSP INT NOT NULL,
  maSanPham VARCHAR(30) NOT NULL,
  tenSP VARCHAR(150) NOT NULL,
  soLuong INT NOT NULL,
  donGia DECIMAL(15,2) NOT NULL,
  thanhTien DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_cthd_hoadon FOREIGN KEY (maHD) REFERENCES HoaDon(maHD),
  CONSTRAINT fk_cthd_sanpham FOREIGN KEY (maSP) REFERENCES SanPham(maSP)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. PHIẾU NHẬP KHO
-- =====================================================
CREATE TABLE PhieuNhap (
  maPN INT AUTO_INCREMENT PRIMARY KEY,
  soPhieuNhap VARCHAR(50) NOT NULL UNIQUE,
  maTK INT NOT NULL,
  nhaCungCap VARCHAR(150) NOT NULL DEFAULT 'Không ghi rõ',
  ghiChu TEXT NULL,
  tongTienNhap DECIMAL(15,2) NOT NULL DEFAULT 0,
  ngayNhap DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_phieunhap_nguoidung FOREIGN KEY (maTK) REFERENCES NguoiDung(maTK)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ChiTietPhieuNhap (
  maCTPN INT AUTO_INCREMENT PRIMARY KEY,
  maPN INT NOT NULL,
  maSP INT NOT NULL,
  maSanPham VARCHAR(30) NOT NULL,
  tenSP VARCHAR(150) NOT NULL,
  soLuong INT NOT NULL,
  donGiaNhap DECIMAL(15,2) NOT NULL DEFAULT 0,
  thanhTien DECIMAL(15,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_ctpn_phieunhap FOREIGN KEY (maPN) REFERENCES PhieuNhap(maPN),
  CONSTRAINT fk_ctpn_sanpham FOREIGN KEY (maSP) REFERENCES SanPham(maSP)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bảng phụ ghi nhật ký thao tác
CREATE TABLE NhatKyHoatDong (
  maNK INT AUTO_INCREMENT PRIMARY KEY,
  maTK INT NULL,
  hanhDong VARCHAR(80) NOT NULL,
  moTa VARCHAR(255) NOT NULL,
  ngayTao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_nhatky_nguoidung FOREIGN KEY (maTK) REFERENCES NguoiDung(maTK)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- VIEW TƯƠNG THÍCH VỚI CODE PHP HIỆN TẠI
-- Code vẫn chạy, nhưng bảng thật trong phpMyAdmin là bảng theo thiết kế.
-- =====================================================
CREATE VIEW users AS
SELECT maTK AS id, tenDangNhap AS username, matKhau AS password, hoTen AS full_name,
       vaiTro AS role, trangThai AS status, ngayTao AS created_at
FROM NguoiDung;

CREATE VIEW products AS
SELECT maSP AS id, maSanPham AS code, tenSP AS name, danhMuc AS category,
       donGia AS price, soLuongTon AS stock, mucCanhBao AS min_stock,
       donViTinh AS unit, trangThai AS status, ngayTao AS created_at, ngayCapNhat AS updated_at
FROM SanPham;

CREATE VIEW customers AS
SELECT maKH AS id, tenKH AS name, sdt AS phone, diemTichLuy AS points,
       tongChiTieu AS total_spent, trangThai AS status, ngayTao AS created_at
FROM KhachHang;

CREATE VIEW vouchers AS
SELECT maVoucher AS id, maKH AS customer_id, maGiamGia AS code, tenVoucher AS name,
       loaiGiamGia AS discount_type, giaTriGiam AS discount_value, donToiThieu AS min_order,
       hanSuDung AS expires_at, trangThai AS status, maHDNguon AS source_invoice_id,
       maHDDaDung AS used_invoice_id, diemQuyDoi AS points_cost, ngaySuDung AS used_at, ngayTao AS created_at
FROM Voucher;

CREATE VIEW invoices AS
SELECT maHD AS id, soHoaDon AS invoice_code, maGiaoDich AS checkout_token, maTK AS user_id,
       maKH AS customer_id, tenKhachHang AS customer_name, sdtKhachHang AS customer_phone,
       tamTinh AS subtotal_amount, tienGiam AS discount_amount, tongTien AS total_amount,
       maVoucher AS voucher_id, diemCong AS points_earned, tienKhachDua AS customer_money,
       tienThua AS change_money, hinhThucTT AS payment_method, trangThai AS status,
       thoiGianHuy AS cancelled_at, nguoiHuy AS cancelled_by, lyDoHuy AS cancel_reason,
       ngayLap AS created_at
FROM HoaDon;

CREATE VIEW invoice_details AS
SELECT maCTHD AS id, maHD AS invoice_id, maSP AS product_id, maSanPham AS product_code,
       tenSP AS product_name, soLuong AS quantity, donGia AS price, thanhTien AS subtotal
FROM ChiTietHoaDon;

CREATE VIEW stock_imports AS
SELECT maPN AS id, soPhieuNhap AS import_code, maTK AS user_id, nhaCungCap AS supplier,
       ghiChu AS note, tongTienNhap AS total_amount, ngayNhap AS created_at
FROM PhieuNhap;

CREATE VIEW stock_import_details AS
SELECT maCTPN AS id, maPN AS stock_import_id, maSP AS product_id, maSanPham AS product_code,
       tenSP AS product_name, soLuong AS quantity, donGiaNhap AS cost_price, thanhTien AS subtotal
FROM ChiTietPhieuNhap;

CREATE VIEW activity_logs AS
SELECT maNK AS id, maTK AS user_id, hanhDong AS action, moTa AS description, ngayTao AS created_at
FROM NhatKyHoatDong;

-- =====================================================
-- DỮ LIỆU MẪU
-- Mật khẩu của tất cả tài khoản: 123456
-- =====================================================
INSERT INTO NguoiDung(tenDangNhap, matKhau, hoTen, vaiTro, trangThai) VALUES
('admin', '$2y$12$yA4HuTE5YtV.oV8aJr43mu/N.4vJyjCo7Otn3023.55HWIjeOFFZa', 'Quản lý cửa hàng', 'admin', 1),
('nhanvien', '$2y$12$yA4HuTE5YtV.oV8aJr43mu/N.4vJyjCo7Otn3023.55HWIjeOFFZa', 'Nhân viên thu ngân', 'cashier', 1),
('kho', '$2y$12$yA4HuTE5YtV.oV8aJr43mu/N.4vJyjCo7Otn3023.55HWIjeOFFZa', 'Nhân viên kho', 'warehouse', 1);

INSERT INTO QuanLy(maTK, ghiChu) VALUES (1, 'Tài khoản quản lý hệ thống');
INSERT INTO ThuNgan(maTK, ghiChu) VALUES (2, 'Tài khoản thu ngân bán hàng');
INSERT INTO NhanVienKho(maTK, ghiChu) VALUES (3, 'Tài khoản nhân viên kho');

INSERT INTO SanPham(maSanPham, tenSP, danhMuc, donGia, soLuongTon, mucCanhBao, donViTinh, trangThai) VALUES
('SP001','Nước suối 500ml','Đồ uống',5000,120,20,'Chai',1),
('SP002','Mì ly hải sản','Thực phẩm',12000,65,15,'Ly',1),
('SP003','Bánh quy bơ','Bánh kẹo',25000,40,10,'Hộp',1),
('SP004','Sữa tươi có đường','Đồ uống',9000,18,20,'Hộp',1),
('SP005','Khăn giấy bỏ túi','Gia dụng',7000,85,15,'Gói',1),
('SP006','Cà phê lon','Đồ uống',13000,30,8,'Lon',1),
('SP007','Snack khoai tây','Bánh kẹo',15000,9,10,'Gói',1);

INSERT INTO KhachHang(tenKH, sdt, diemTichLuy, tongChiTieu, trangThai) VALUES
('Nguyễn Văn An','0901234567',35,350000,1),
('Trần Thị Bình','0912345678',80,820000,1);

INSERT INTO Voucher(maKH, maGiamGia, tenVoucher, loaiGiamGia, giaTriGiam, donToiThieu, hanSuDung, trangThai, diemQuyDoi) VALUES
(2,'TVDEMO20K','Voucher thành viên giảm 20.000 đ','fixed',20000,100000,DATE_ADD(NOW(), INTERVAL 30 DAY),'available',100);

INSERT INTO NhatKyHoatDong(maTK, hanhDong, moTa) VALUES
(1,'init','Khởi tạo dữ liệu mẫu cho hệ thống theo sơ đồ thiết kế');
