<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/sales_service.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['found'=>false,'message'=>'Phương thức không được hỗ trợ.'], JSON_UNESCAPED_UNICODE);
    exit;
}
verify_csrf_header();
$phone = normalize_phone((string) ($_POST['phone'] ?? ''));
if ($phone === '' || !preg_match('/^[0-9]{9,11}$/', $phone)) {
    http_response_code(422);
    echo json_encode(['found'=>false,'message'=>'Số điện thoại phải gồm từ 9 đến 11 chữ số.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$pdo = db();
$stmt = $pdo->prepare('SELECT id,name,phone,points,total_spent FROM customers WHERE phone=? AND status=1');
$stmt->execute([$phone]);
$customer = $stmt->fetch();
if (!$customer) {
    echo json_encode(['found'=>false,'message'=>'Chưa có khách hàng này. Nhập tên để tạo thành viên khi thanh toán.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$voucherStmt = $pdo->prepare("SELECT code,name,discount_type,discount_value,min_order,expires_at FROM vouchers WHERE customer_id=? AND status='available' AND expires_at>=NOW() ORDER BY expires_at ASC");
$voucherStmt->execute([(int)$customer['id']]);
echo json_encode(['found'=>true,'customer'=>['name'=>$customer['name'],'phone'=>$customer['phone'],'points'=>(int)$customer['points'],'total_spent'=>(float)$customer['total_spent']],'vouchers'=>$voucherStmt->fetchAll()], JSON_UNESCAPED_UNICODE);
