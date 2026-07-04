-- Optional update for POS Mini UI sweep.
-- Run this only if you want customer email/birthday fields to be stored and invoice status/payment to be flexible.
USE quan_ly_ban_hang;

ALTER TABLE KhachHang
  ADD COLUMN IF NOT EXISTS email VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS birthday DATE NULL;

ALTER TABLE HoaDon
  MODIFY COLUMN hinhThucTT VARCHAR(30) NOT NULL DEFAULT 'cash',
  MODIFY COLUMN trangThai VARCHAR(20) NOT NULL DEFAULT 'paid';

DROP VIEW IF EXISTS customers;
CREATE VIEW customers AS
SELECT maKH AS id, tenKH AS name, sdt AS phone, diemTichLuy AS points,
       tongChiTieu AS total_spent, trangThai AS status, ngayTao AS created_at,
       email AS email, birthday AS birthday
FROM KhachHang;
