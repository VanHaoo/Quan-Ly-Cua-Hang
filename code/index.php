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
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css?v=6')) ?>">
</head>
<body class="login-page">
<div class="login-split">
    <section class="login-showcase">
        <div class="showcase-content">
            <div class="showcase-logo">🛒</div>
            <span class="eyebrow">POS MINI</span>
            <h2>Quản lý bán hàng tại quầy gọn gàng hơn.</h2>
            <p>Theo dõi doanh thu, kiểm tra tồn kho và phân quyền nhân viên trong một giao diện đơn giản, dễ dùng cho cửa hàng bán lẻ.</p>

            <div class="showcase-visual">
                <div class="visual-top">
                    <div>
                        <strong>Doanh thu hôm nay</strong>
                        <span>Cập nhật theo hóa đơn đã thanh toán</span>
                    </div>
                    <span class="visual-badge">+12%</span>
                </div>

                <div class="visual-list">
                    <div class="visual-item">
                        <span class="visual-icon">🧾</span>
                        <div>
                            <strong>Hóa đơn nhanh</strong>
                            <span>Lập, xem và in hóa đơn tại quầy</span>
                        </div>
                    </div>

                    <div class="visual-item">
                        <span class="visual-icon">📦</span>
                        <div>
                            <strong>Cảnh báo tồn kho</strong>
                            <span>Nhận biết sản phẩm sắp hết hàng</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="feature-cards">
                <div class="feature-card">
                    <span>📈</span>
                    <b>Doanh thu</b>
                    <small>Theo dõi kết quả bán hàng.</small>
                </div>
                <div class="feature-card">
                    <span>📦</span>
                    <b>Tồn kho</b>
                    <small>Kiểm soát số lượng hàng.</small>
                </div>
                <div class="feature-card">
                    <span>🔐</span>
                    <b>Phân quyền</b>
                    <small>Quản lý, thu ngân, kho.</small>
                </div>
            </div>
        </div>
    </section>

    <main class="login-form-side">
        <div class="login-card">
            <div class="login-mobile-brand">
                <span>🛒</span>
                <strong>POS Mini</strong>
            </div>

            <h1>Đăng nhập hệ thống</h1>
            <p>Đăng nhập theo vai trò: quản lý, thu ngân hoặc nhân viên kho.</p>

            <?php if ($success): ?>
                <div class="alert success"><?= e($success) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error login-alert">
                    <span class="ti-alert-circle login-alert-icon" aria-hidden="true">⚠️</span>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" class="form-grid one-column login-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label>
                    Tên đăng nhập
                    <span class="login-field">
                        <span class="field-icon" aria-hidden="true">👤</span>
                        <input name="username" autocomplete="username" required <?= $isLocked ? 'disabled' : '' ?>>
                    </span>
                </label>

                <label>
                    Mật khẩu
                    <span class="login-field password-field">
                        <span class="field-icon" aria-hidden="true">🔒</span>
                        <input id="login-password" type="password" name="password" autocomplete="current-password" required <?= $isLocked ? 'disabled' : '' ?>>
                        <button class="password-toggle" type="button" id="toggle-password" aria-label="Hiện mật khẩu" <?= $isLocked ? 'disabled' : '' ?>>👁</button>
                    </span>
                </label>

                <div class="login-options">
                    <label class="remember-check">
                        <input type="checkbox" name="remember" value="1" <?= $isLocked ? 'disabled' : '' ?>>
                        Ghi nhớ đăng nhập
                    </label>
                    <a class="forgot-link" href="#" onclick="return false;">Quên mật khẩu?</a>
                </div>

                <button class="btn primary full" type="submit" <?= $isLocked ? 'disabled' : '' ?>>
                    <?= $isLocked ? 'Đang tạm khóa' : 'Đăng nhập' ?>
                </button>
            </form>

            <details class="demo-account">
                <summary>Xem tài khoản thử nghiệm</summary>
                <table class="demo-table">
                    <thead>
                        <tr>
                            <th>Vai trò</th>
                            <th>Tài khoản / mật khẩu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Quản lý</td>
                            <td><code>admin</code> / <code>123456</code></td>
                        </tr>
                        <tr>
                            <td>Thu ngân</td>
                            <td><code>nhanvien</code> / <code>123456</code></td>
                        </tr>
                        <tr>
                            <td>Nhân viên kho</td>
                            <td><code>kho</code> / <code>123456</code></td>
                        </tr>
                    </tbody>
                </table>
            </details>
        </div>
    </main>
</div>

<script>
    const togglePasswordButton = document.getElementById('toggle-password');
    const passwordInput = document.getElementById('login-password');

    togglePasswordButton?.addEventListener('click', function () {
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';
        this.textContent = isPassword ? '🙈' : '👁';
        this.setAttribute('aria-label', isPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
    });
</script>
</body>
</html>
