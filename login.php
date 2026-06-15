<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = flash('error');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập hệ thống</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-page">
<div class="login-shell">
    <section class="login-intro">
        <div class="intro-badge">POS</div>
        <h1>Hệ thống quản lý bán hàng tại quầy</h1>
        <p>Quản lý sản phẩm, lập hóa đơn, cập nhật tồn kho và theo dõi doanh thu trên cùng một hệ thống.</p>
        <div class="role-preview">
            <article>
                <strong>Quản lý</strong>
                <span>Theo dõi sản phẩm và doanh thu</span>
            </article>
            <article>
                <strong>Nhân viên</strong>
                <span>Bán hàng và lập hóa đơn</span>
            </article>
        </div>
    </section>

    <section class="login-card">
        <div class="login-heading">
            <span class="brand-mark large">BH</span>
            <div>
                <h2>Đăng nhập</h2>
                <p>Nhập tài khoản để bắt đầu làm việc</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= BASE_URL ?>/actions/login_action.php" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <label>
                Tên đăng nhập
                <input type="text" name="username" autocomplete="username" required autofocus placeholder="Ví dụ admin">
            </label>

            <label>
                Mật khẩu
                <input type="password" name="password" autocomplete="current-password" required placeholder="Nhập mật khẩu">
            </label>

            <button type="submit" class="btn btn-primary btn-block">Đăng nhập</button>
        </form>

        <div class="demo-account">
            <p><strong>Tài khoản dùng thử</strong></p>
            <p>Quản lý: admin / 123456</p>
            <p>Nhân viên: nhanvien / 123456</p>
        </div>
    </section>
</div>
</body>
</html>
