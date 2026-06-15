-- Nhập file này bằng phpMyAdmin.
-- Lưu ý: dữ liệu cũ trong database quan_ly_ban_hang sẽ bị xóa.

DROP DATABASE IF EXISTS quan_ly_ban_hang;
CREATE DATABASE quan_ly_ban_hang CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE quan_ly_ban_hang;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    role ENUM('admin','employee') NOT NULL DEFAULT 'employee',
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    min_stock INT NOT NULL DEFAULT 5,
    unit VARCHAR(30) NOT NULL DEFAULT 'Cái',
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_product_search (name, category),
    INDEX idx_product_stock (status, stock)
) ENGINE=InnoDB;

CREATE TABLE invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_code VARCHAR(30) NOT NULL UNIQUE,
    checkout_token CHAR(64) NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    customer_name VARCHAR(120),
    total_amount DECIMAL(12,2) NOT NULL,
    customer_money DECIMAL(12,2) NOT NULL,
    change_money DECIMAL(12,2) NOT NULL,
    status ENUM('paid','cancelled') NOT NULL DEFAULT 'paid',
    cancelled_at DATETIME,
    cancelled_by INT UNSIGNED,
    cancel_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoice_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_invoice_cancel_user FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_invoice_date (created_at),
    INDEX idx_invoice_status (status)
) ENGINE=InnoDB;

CREATE TABLE invoice_details (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    product_code VARCHAR(30) NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_detail_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_detail_product FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_detail_invoice (invoice_id)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED,
    action VARCHAR(60) NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_log_date (created_at)
) ENGINE=InnoDB;

-- Mật khẩu của hai tài khoản đều là 123456.
INSERT INTO users (username,password,full_name,role) VALUES
('admin','$2y$12$5YqWbaDwu35DRA/7IYJV2u5NeQGu1xssS5.2F4T0Mxe3WQtMP7kaC','Nguyễn Thế Anh','admin'),
('nhanvien','$2y$12$5YqWbaDwu35DRA/7IYJV2u5NeQGu1xssS5.2F4T0Mxe3WQtMP7kaC','Nhân viên bán hàng','employee');

INSERT INTO products (code,name,category,price,stock,min_stock,unit) VALUES
('SP001','Nước suối 500ml','Đồ uống',10000,50,10,'Chai'),
('SP002','Nước ngọt Coca Cola','Đồ uống',15000,36,10,'Lon'),
('SP003','Sữa tươi có đường','Đồ uống',12000,28,8,'Hộp'),
('SP004','Bánh quy bơ','Bánh kẹo',25000,22,6,'Gói'),
('SP005','Mì ăn liền','Thực phẩm',8000,60,15,'Gói'),
('SP006','Khăn giấy bỏ túi','Đồ dùng',7000,18,5,'Gói'),
('SP007','Bút bi xanh','Văn phòng phẩm',5000,40,10,'Cây'),
('SP008','Sổ tay nhỏ','Văn phòng phẩm',18000,15,5,'Quyển'),
('SP009','Kẹo bạc hà','Bánh kẹo',12000,4,5,'Gói'),
('SP010','Cà phê lon','Đồ uống',17000,5,8,'Lon');
