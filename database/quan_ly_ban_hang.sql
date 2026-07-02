CREATE DATABASE IF NOT EXISTS quan_ly_ban_hang CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE quan_ly_ban_hang;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS stock_import_details;
DROP TABLE IF EXISTS stock_imports;
DROP TABLE IF EXISTS invoice_details;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS vouchers;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  role ENUM('admin','cashier','warehouse') NOT NULL DEFAULT 'cashier',
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  category VARCHAR(100) NOT NULL,
  price DECIMAL(15,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  min_stock INT NOT NULL DEFAULT 5,
  unit VARCHAR(30) NOT NULL DEFAULT 'Cái',
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL UNIQUE,
  points INT NOT NULL DEFAULT 0,
  total_spent DECIMAL(15,2) NOT NULL DEFAULT 0,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vouchers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  discount_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  min_order DECIMAL(15,2) NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  status ENUM('available','used','expired','cancelled') NOT NULL DEFAULT 'available',
  source_invoice_id INT NULL,
  used_invoice_id INT NULL,
  points_cost INT NOT NULL DEFAULT 0,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vouchers_customer (customer_id),
  CONSTRAINT fk_vouchers_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_code VARCHAR(50) NOT NULL UNIQUE,
  checkout_token VARCHAR(100) NOT NULL UNIQUE,
  user_id INT NOT NULL,
  customer_id INT NULL,
  customer_name VARCHAR(120) NOT NULL DEFAULT 'Khách lẻ',
  customer_phone VARCHAR(20) NULL,
  subtotal_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  voucher_id INT NULL,
  points_earned INT NOT NULL DEFAULT 0,
  customer_money DECIMAL(15,2) NOT NULL DEFAULT 0,
  change_money DECIMAL(15,2) NOT NULL DEFAULT 0,
  payment_method ENUM('cash','transfer','qr') NOT NULL DEFAULT 'cash',
  status ENUM('paid','cancelled') NOT NULL DEFAULT 'paid',
  cancelled_at DATETIME NULL,
  cancelled_by INT NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_invoices_user (user_id),
  INDEX idx_invoices_customer (customer_id),
  CONSTRAINT fk_invoices_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_invoices_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE invoice_details (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  product_id INT NOT NULL,
  product_code VARCHAR(30) NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  quantity INT NOT NULL,
  price DECIMAL(15,2) NOT NULL,
  subtotal DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_detail_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  CONSTRAINT fk_detail_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_imports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  import_code VARCHAR(50) NOT NULL UNIQUE,
  user_id INT NOT NULL,
  supplier VARCHAR(150) NOT NULL DEFAULT 'Không ghi rõ',
  note TEXT NULL,
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_import_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_import_details (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stock_import_id INT NOT NULL,
  product_id INT NOT NULL,
  product_code VARCHAR(30) NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  quantity INT NOT NULL,
  cost_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_stock_import_detail_import FOREIGN KEY (stock_import_id) REFERENCES stock_imports(id),
  CONSTRAINT fk_stock_import_detail_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users(username,password,full_name,role,status) VALUES
('admin', '$2y$12$yA4HuTE5YtV.oV8aJr43mu/N.4vJyjCo7Otn3023.55HWIjeOFFZa', 'Quản lý cửa hàng', 'admin', 1),
('nhanvien', '$2y$12$yA4HuTE5YtV.oV8aJr43mu/N.4vJyjCo7Otn3023.55HWIjeOFFZa', 'Nhân viên thu ngân', 'cashier', 1),
('kho', '$2y$12$yA4HuTE5YtV.oV8aJr43mu/N.4vJyjCo7Otn3023.55HWIjeOFFZa', 'Nhân viên kho', 'warehouse', 1);

INSERT INTO products(code,name,category,price,stock,min_stock,unit,status) VALUES
('SP001','Nước suối 500ml','Đồ uống',5000,120,20,'Chai',1),
('SP002','Mì ly hải sản','Thực phẩm',12000,65,15,'Ly',1),
('SP003','Bánh quy bơ','Bánh kẹo',25000,40,10,'Hộp',1),
('SP004','Sữa tươi có đường','Đồ uống',9000,18,20,'Hộp',1),
('SP005','Khăn giấy bỏ túi','Gia dụng',7000,85,15,'Gói',1),
('SP006','Cà phê lon','Đồ uống',13000,30,8,'Lon',1),
('SP007','Snack khoai tây','Bánh kẹo',15000,9,10,'Gói',1);

INSERT INTO customers(name,phone,points,total_spent,status) VALUES
('Nguyễn Văn An','0901234567',35,350000,1),
('Trần Thị Bình','0912345678',80,820000,1);

INSERT INTO vouchers(customer_id,code,name,discount_type,discount_value,min_order,expires_at,status,points_cost) VALUES
(2,'TVDEMO20K','Voucher thành viên giảm 20.000 đ','fixed',20000,100000,DATE_ADD(NOW(), INTERVAL 30 DAY),'available',100);

INSERT INTO activity_logs(user_id,action,description) VALUES
(1,'init','Khởi tạo dữ liệu mẫu cho hệ thống');
