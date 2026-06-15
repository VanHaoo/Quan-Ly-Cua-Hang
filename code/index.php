<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

if (($_GET['action'] ?? '') === 'logout') {
    if (is_logged_in()) {
        log_activity('logout', 'Đăng xuất khỏi hệ thống');
    }
    $_SESSION = [];
    session_destroy();
    session_start();
    flash('success', 'Đã đăng xuất khỏi hệ thống.');
    redirect('index.php');
}

if (is_logged_in()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        flash('error', 'Vui lòng nhập đầy đủ tài khoản và mật khẩu.');
    } else {
        $stmt = db()->prepare('SELECT id, username, password, full_name, role, status FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && (int) $user['status'] === 1 && password_verify($password, $user['password'])) {
            unset($user['password'], $user['status']);
            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            log_activity('login', 'Đăng nhập vào hệ thống');
            redirect('dashboard.php');
        }
        flash('error', 'Tên đăng nhập hoặc mật khẩu không đúng.');
    }
}

$error = flash('error');
$success = flash('success');
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập | Quản lý bán hàng</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="login-page">
<div class="login-card">
    <div class="login-logo">🛒</div>
    <h1>Quản lý bán hàng</h1>
    <p>Đăng nhập để bắt đầu phiên làm việc</p>

    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <form method="post" class="form-grid one-column">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Tên đăng nhập<input name="username" autocomplete="username" required></label>
        <label>Mật khẩu<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="btn primary full" type="submit">Đăng nhập</button>
    </form>
    <div class="demo-account">
        <strong>Tài khoản thử nghiệm</strong>
        <span>admin / 123456</span>
        <span>nhanvien / 123456</span>
    </div>
</div>
</body>
</html>
