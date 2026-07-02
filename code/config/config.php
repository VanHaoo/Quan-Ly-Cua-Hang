<?php

declare(strict_types=1);

const DB_HOST = 'localhost';
const DB_NAME = 'quan_ly_ban_hang';
const DB_USER = 'root';
const DB_PASS = '';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException) {
        http_response_code(500);
        exit('Không thể kết nối cơ sở dữ liệu. Hãy bật MySQL và import file database/quan_ly_ban_hang.sql.');
    }

    return $pdo;
}

function url(string $path = ''): string
{
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = $base === '/' ? '' : rtrim($base, '/');

    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function asset(string $path): string
{
    $file = __DIR__ . '/../' . ltrim($path, '/');
    $version = file_exists($file) ? filemtime($file) : time();

    return url($path) . '?v=' . $version;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float|int|string $value): string
{
    return number_format((float) $value, 0, ',', '.') . ' đ';
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function has_role(string ...$roles): bool
{
    $role = current_user()['role'] ?? '';

    return in_array($role, $roles, true);
}

function is_admin(): bool
{
    return has_role('admin');
}

function user_role(): string
{
    return (string) (current_user()['role'] ?? '');
}

function is_cashier(): bool
{
    return has_role('cashier');
}

function is_warehouse(): bool
{
    return has_role('warehouse');
}

function role_label(): string
{
    return match (user_role()) {
        'admin' => 'Quản lý',
        'warehouse' => 'Nhân viên kho',
        'cashier' => 'Thu ngân',
        default => 'Người dùng',
    };
}

function role_name(?string $role = null): string
{
    $role ??= current_user()['role'] ?? '';

    return match ($role) {
        'admin' => 'Quản lý',
        'cashier' => 'Thu ngân',
        'warehouse' => 'Nhân viên kho',
        default => 'Người dùng',
    };
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('error', 'Vui lòng đăng nhập để tiếp tục.');
        redirect('index.php');
    }
}

function require_admin(): void
{
    require_roles('admin');
}

function require_roles(string ...$roles): void
{
    require_login();

    if (!has_role(...$roles)) {
        flash('error', 'Bạn không có quyền sử dụng chức năng này.');
        redirect('dashboard.php');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function verify_csrf_value(?string $token): void
{
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    if ($token === null || $token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        exit('Phiên làm việc không hợp lệ. Hãy tải lại trang và thử lại.');
    }
}

function verify_csrf(): void
{
    verify_csrf_value(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null);
}

function verify_csrf_header(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    verify_csrf_value(is_string($token) ? $token : null);
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);

    return is_string($value) ? $value : null;
}

function log_activity(string $action, string $description): void
{
    try {
        $stmt = db()->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
        $stmt->execute([(int) (current_user()['id'] ?? 0) ?: null, $action, $description]);
    } catch (Throwable) {
        // Nhật ký không làm gián đoạn thao tác chính.
    }
}

function active_class(string $active, string $name): string
{
    return $active === $name ? 'active' : '';
}

function render_header(string $title, string $active = ''): void
{
    $user = current_user();
    $success = flash('success');
    $error = flash('error');

    $role = user_role();
    $roleLabel = role_name($role);
    ?>
    <!doctype html>
    <html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | Quản lý bán hàng</title>
        <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    </head>

    <body>
    <div class="app-shell">

        <aside class="sidebar">
            <a class="brand" href="<?= e(url('dashboard.php')) ?>">
                <span class="brand-icon">🛒</span>
                <span>
                    <strong>POS Mini</strong>
                    <small>Hệ thống bán hàng tại quầy</small>
                </span>
            </a>

            <nav class="side-menu">
                <div class="menu-group">
                    <span class="menu-title">Tổng quan</span>
                    <a class="<?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= e(url('dashboard.php')) ?>">
                        <span>▦</span> Bảng điều khiển
                    </a>
                </div>

                <div class="menu-group">
                    <span class="menu-title">Bán hàng tại quầy</span>

                    <?php if (!is_warehouse()): ?>
                        <a class="<?= $active === 'sales' ? 'active' : '' ?>" href="<?= e(url('sales.php')) ?>">
                            <span>🛒</span> Bán hàng
                        </a>

                        <a class="<?= $active === 'invoices' ? 'active' : '' ?>" href="<?= e(url('invoices.php')) ?>">
                            <span>▤</span> Hóa đơn
                        </a>

                        <a class="<?= $active === 'customers' ? 'active' : '' ?>" href="<?= e(url('customers.php')) ?>">
                            <span>♡</span> Khách hàng - tích điểm
                        </a>
                    <?php endif; ?>
                </div>

                <div class="menu-group">
                    <span class="menu-title">Quản lý kho</span>

                    <a class="<?= $active === 'inventory' ? 'active' : '' ?>" href="<?= e(url('inventory.php')) ?>">
                        <span>▣</span> Kiểm tra tồn kho
                    </a>

                    <?php if (is_admin() || is_warehouse()): ?>
                        <a class="<?= $active === 'stock_import' ? 'active' : '' ?>" href="<?= e(url('stock_import.php')) ?>">
                            <span>▾</span> Nhập hàng
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (is_admin()): ?>
                    <div class="menu-group">
                        <span class="menu-title">Quản trị hệ thống</span>

                        <a class="<?= $active === 'products' ? 'active' : '' ?>" href="<?= e(url('products.php')) ?>">
                            <span>▣</span> Quản lý sản phẩm
                        </a>

                        <a class="<?= $active === 'employees' ? 'active' : '' ?>" href="<?= e(url('employees.php')) ?>">
                            <span>●</span> Nhân viên & phân quyền
                        </a>

                        <a class="<?= $active === 'statistics' ? 'active' : '' ?>" href="<?= e(url('statistics.php')) ?>">
                            <span>▥</span> Báo cáo doanh thu
                        </a>
                    </div>
                <?php endif; ?>
            </nav>

            <div class="sidebar-user">
                <strong><?= e($user['full_name'] ?? '') ?></strong>
                <small><?= e($roleLabel) ?></small>
                <a href="<?= e(url('index.php?action=logout')) ?>">Đăng xuất</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <div>
                    <h1><?= e($title) ?></h1>
                    <p>Phân tích - thiết kế theo quy trình bán hàng tại quầy</p>
                </div>
                <span class="role-badge"><?= e($roleLabel) ?></span>
            </header>

            <?php if ($success): ?>
                <div class="alert success"><?= e($success) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error"><?= e($error) ?></div>
            <?php endif; ?>
    <?php
}

function render_footer(): void
{
    ?>
        </main>
    </div>
    <script src="<?= e(asset('assets/js/app.js')) ?>"></script>
    </body>
    </html>
    <?php
}