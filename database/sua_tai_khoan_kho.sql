USE quan_ly_ban_hang;

INSERT INTO NguoiDung(tenDangNhap, matKhau, hoTen, vaiTro, trangThai)
VALUES ('kho', '$2y$12$yA4HuTE5YtV.oV8aJr43mu/N.4vJyjCo7Otn3023.55HWIjeOFFZa', 'Nhân viên kho', 'warehouse', 1)
ON DUPLICATE KEY UPDATE
  matKhau = VALUES(matKhau),
  hoTen = VALUES(hoTen),
  vaiTro = VALUES(vaiTro),
  trangThai = 1;

INSERT INTO NhanVienKho(maTK, ghiChu)
SELECT maTK, 'Tài khoản nhân viên kho'
FROM NguoiDung
WHERE tenDangNhap = 'kho'
ON DUPLICATE KEY UPDATE ghiChu = VALUES(ghiChu);
