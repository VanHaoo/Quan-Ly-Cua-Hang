<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 300;

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

$_SESSION['login_attempts'] ??= 0;
$_SESSION['login_lock_until'] ??= 0;
$lockUntil = (int) $_SESSION['login_lock_until'];
if ($lockUntil > 0 && $lockUntil <= time()) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_lock_until'] = 0;
    $lockUntil = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ((int) $_SESSION['login_lock_until'] > time()) {
        flash('error', 'Bạn nhập sai quá nhiều lần. Vui lòng thử lại sau ít phút.');
    } else {
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
                unset($_SESSION['login_attempts'], $_SESSION['login_lock_until']);
                session_regenerate_id(true);
                $_SESSION['user'] = $user;
                log_activity('login', 'Đăng nhập vào hệ thống');
                redirect('dashboard.php');
            }
            usleep(250000);
            $_SESSION['login_attempts'] = (int) $_SESSION['login_attempts'] + 1;
            if ((int) $_SESSION['login_attempts'] >= LOGIN_MAX_ATTEMPTS) {
                $_SESSION['login_lock_until'] = time() + LOGIN_LOCK_SECONDS;
                $_SESSION['login_attempts'] = 0;
                flash('error', 'Sai quá nhiều lần. Tạm khóa đăng nhập trong 5 phút.');
            } else {
                $remain = LOGIN_MAX_ATTEMPTS - (int) $_SESSION['login_attempts'];
                flash('error', 'Tên đăng nhập hoặc mật khẩu không đúng. Còn ' . $remain . ' lần thử.');
            }
        }
    }
}
$error = flash('error');
$success = flash('success');
$isLocked = (int) ($_SESSION['login_lock_until'] ?? 0) > time();
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
    <h1>Quản lý bán hàng tại quầy</h1>
    <p>Đăng nhập theo vai trò: quản lý, thu ngân hoặc nhân viên kho</p>
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-grid one-column">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Tên đăng nhập<input name="username" autocomplete="username" required <?= $isLocked ? 'disabled' : '' ?>></label>
        <label>Mật khẩu<input type="password" name="password" autocomplete="current-password" required <?= $isLocked ? 'disabled' : '' ?>></label>
        <button class="btn primary full" type="submit" <?= $isLocked ? 'disabled' : '' ?>><?= $isLocked ? 'Đang tạm khóa' : 'Đăng nhập' ?></button>
    </form>
    <div class="demo-account">
        <strong>Tài khoản thử nghiệm</strong>
        <span>admin / 123456</span>
        <span>nhanvien / 123456</span>
        <span>kho / 123456</span>
    </div>
</div>
</body>
</html>
