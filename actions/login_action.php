<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

verify_csrf();

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    $_SESSION['error'] = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.';
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$stmt = db()->prepare('SELECT id, username, password, full_name, role, status FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || (int) $user['status'] !== 1 || !password_verify($password, $user['password'])) {
    $_SESSION['error'] = 'Tên đăng nhập hoặc mật khẩu không đúng.';
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

session_regenerate_id(true);
unset($user['password'], $user['status']);
$_SESSION['user'] = $user;
$_SESSION['success'] = 'Đăng nhập thành công.';

log_activity(db(), 'login', 'user', (int) $user['id'], 'Đăng nhập vào hệ thống', (int) $user['id']);

header('Location: ' . BASE_URL . '/dashboard.php');
exit;
