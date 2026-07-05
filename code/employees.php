<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_admin();

$pdo = db();

function employee_role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Quản lý',
        'cashier' => 'Thu ngân',
        'warehouse' => 'Nhân viên kho',
        default => 'Người dùng',
    };
}

function employee_role_icon(string $role): string
{
    return match ($role) {
        'admin' => '👑',
        'cashier' => '🧾',
        'warehouse' => '📦',
        default => '👤',
    };
}

function employee_role_class(string $role): string
{
    return in_array($role, ['admin', 'cashier', 'warehouse'], true) ? $role : 'user';
}

function employee_role_note(string $role): string
{
    return match ($role) {
        'admin' => 'Toàn quyền quản trị hệ thống',
        'cashier' => 'Xử lý bán hàng tại quầy',
        'warehouse' => 'Theo dõi tồn kho và nhập hàng',
        default => 'Tài khoản hệ thống',
    };
}

function employee_page_url(array $baseParams, int $page): string
{
    $baseParams['page'] = $page;
    return 'employees.php?' . http_build_query($baseParams);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $username = trim((string) ($_POST['username'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $role = (string) ($_POST['role'] ?? 'cashier');
            $password = (string) ($_POST['password'] ?? '');

            if ($username === '' || $fullName === '' || !in_array($role, ['admin', 'cashier', 'warehouse'], true)) {
                throw new RuntimeException('Thông tin nhân viên không hợp lệ.');
            }

            if ($password !== '' && strlen($password) < 6) {
                throw new RuntimeException('Mật khẩu phải có ít nhất 6 ký tự.');
            }

            if ($id > 0) {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET username=?, full_name=?, role=?, password=? WHERE id=?');
                    $stmt->execute([$username, $fullName, $role, password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET username=?, full_name=?, role=? WHERE id=?');
                    $stmt->execute([$username, $fullName, $role, $id]);
                }

                log_activity('user_update', 'Cập nhật nhân viên ' . $fullName);
                flash('success', 'Đã cập nhật nhân viên.');
            } else {
                if ($password === '') {
                    throw new RuntimeException('Nhân viên mới cần nhập mật khẩu.');
                }

                $stmt = $pdo->prepare('INSERT INTO users(username,password,full_name,role,status) VALUES(?,?,?,?,1)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role]);

                log_activity('user_create', 'Thêm nhân viên ' . $fullName);
                flash('success', 'Đã thêm nhân viên.');
            }
        }

        if ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id === (int) current_user()['id']) {
                throw new RuntimeException('Không thể khóa chính tài khoản đang đăng nhập.');
            }

            $stmt = $pdo->prepare('SELECT full_name,status FROM users WHERE id=?');
            $stmt->execute([$id]);
            $user = $stmt->fetch();

            if (!$user) {
                throw new RuntimeException('Không tìm thấy nhân viên.');
            }

            $newStatus = (int) $user['status'] === 1 ? 0 : 1;

            $pdo->prepare('UPDATE users SET status=? WHERE id=?')
                ->execute([$newStatus, $id]);

            log_activity('user_status', ($newStatus ? 'Kích hoạt' : 'Khóa') . ' tài khoản ' . $user['full_name']);
            flash('success', 'Đã cập nhật trạng thái tài khoản.');
        }
    } catch (PDOException $e) {
        flash('error', $e->getCode() === '23000' ? 'Tên đăng nhập đã tồn tại.' : 'Không thể lưu nhân viên.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }

    redirect('employees.php');
}

$edit = null;

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;

    if (!$edit) {
        flash('error', 'Không tìm thấy nhân viên cần sửa.');
        redirect('employees.php');
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$roleFilter = (string) ($_GET['role'] ?? 'all');
$statusFilter = (string) ($_GET['status'] ?? 'all');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(username LIKE :q OR full_name LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if (in_array($roleFilter, ['admin', 'cashier', 'warehouse'], true)) {
    $where[] = 'role = :role';
    $params[':role'] = $roleFilter;
} else {
    $roleFilter = 'all';
}

if ($statusFilter === 'active') {
    $where[] = 'status = 1';
} elseif ($statusFilter === 'locked') {
    $where[] = 'status = 0';
} else {
    $statusFilter = 'all';
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE {$whereSql}");

foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, PDO::PARAM_STR);
}

$countStmt->execute();

$totalUsers = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsers / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "SELECT * FROM users WHERE {$whereSql} ORDER BY FIELD(role,'admin','cashier','warehouse'), id DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$users = $stmt->fetchAll();

$roleCounts = [
    'admin' => 0,
    'cashier' => 0,
    'warehouse' => 0,
];

foreach ($pdo->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role')->fetchAll() as $row) {
    if (isset($roleCounts[$row['role']])) {
        $roleCounts[$row['role']] = (int) $row['total'];
    }
}

$summary = [
    'total' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status=1')->fetchColumn(),
    'locked' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status=0')->fetchColumn(),
    'roles' => count(array_filter($roleCounts, fn($count) => $count > 0)),
];

$permissionCards = [
    [
        'role' => 'admin',
        'title' => 'Quản lý',
        'icon' => '👑',
        'note' => 'Toàn quyền quản trị, cấu hình và xem báo cáo.',
        'items' => ['Bảng điều khiển', 'Bán hàng & hóa đơn', 'Kho & nhập hàng', 'Sản phẩm', 'Nhân viên', 'Báo cáo doanh thu'],
    ],
    [
        'role' => 'cashier',
        'title' => 'Thu ngân',
        'icon' => '🧾',
        'note' => 'Tập trung vào thao tác bán hàng và chăm sóc khách hàng.',
        'items' => ['Bảng điều khiển', 'Bán hàng tại quầy', 'Hóa đơn', 'Khách hàng - tích điểm'],
    ],
    [
        'role' => 'warehouse',
        'title' => 'Nhân viên kho',
        'icon' => '📦',
        'note' => 'Theo dõi tồn kho và lập phiếu nhập hàng.',
        'items' => ['Bảng điều khiển', 'Kiểm tra tồn kho', 'Nhập hàng'],
    ],
];

$paginationParams = array_filter([
    'q' => $q,
    'role' => $roleFilter !== 'all' ? $roleFilter : '',
    'status' => $statusFilter !== 'all' ? $statusFilter : '',
], fn($value) => $value !== '');

render_header('Nhân viên & phân quyền', 'employees');
?>

<div class="employee-manager-page">
    <section class="panel employee-hero-panel">
        <div>
            <span class="eyebrow">QUẢN TRỊ HỆ THỐNG</span>
            <h2>Quản lý nhân viên và phân quyền theo vai trò.</h2>
            <p>Tạo tài khoản đăng nhập, đổi vai trò, khóa/mở tài khoản và xem nhanh quyền truy cập của từng nhóm nhân viên.</p>
        </div>

        <a class="btn primary" href="#employee-form-card">
            <?= $edit ? 'Đang sửa nhân viên' : '+ Thêm nhân viên' ?>
        </a>
    </section>

    <div class="stats-grid employee-summary-grid modern-employee-summary">
        <article class="mini-stat">
            <span>Tổng nhân viên</span>
            <strong><?= (int) $summary['total'] ?></strong>
            <small>Toàn bộ tài khoản hệ thống</small>
        </article>

        <article class="mini-stat">
            <span>Đang hoạt động</span>
            <strong><?= (int) $summary['active'] ?></strong>
            <small>Có thể đăng nhập và thao tác</small>
        </article>

        <article class="mini-stat warning">
            <span>Đã khóa</span>
            <strong><?= (int) $summary['locked'] ?></strong>
            <small>Không thể đăng nhập hệ thống</small>
        </article>

        <article class="mini-stat">
            <span>Nhóm quyền</span>
            <strong><?= (int) $summary['roles'] ?>/3</strong>
            <small>Quản lý, thu ngân, kho</small>
        </article>
    </div>

    <section class="panel role-permission-panel">
        <div class="panel-heading relaxed-heading">
            <div>
                <h2>Phân quyền theo vai trò</h2>
                <p>Quyền được áp dụng theo menu chức năng trong hệ thống POS Mini.</p>
            </div>
        </div>

        <div class="permission-card-grid">
            <?php foreach ($permissionCards as $card): ?>
                <article class="permission-card <?= e($card['role']) ?>">
                    <div class="permission-card-head">
                        <span><?= e($card['icon']) ?></span>
                        <div>
                            <strong><?= e($card['title']) ?></strong>
                            <small><?= e($card['note']) ?></small>
                        </div>
                    </div>

                    <div class="permission-list">
                        <?php foreach ($card['items'] as $item): ?>
                            <span>✓ <?= e($item) ?></span>
                        <?php endforeach; ?>
                    </div>

                    <div class="permission-count">
                        <?= (int) $roleCounts[$card['role']] ?> tài khoản
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel employee-form-card" id="employee-form-card">
        <div class="panel-heading employee-form-heading">
            <div>
                <h2><?= $edit ? 'Cập nhật nhân viên' : 'Thêm nhân viên mới' ?></h2>
                <p><?= $edit ? 'Bạn đang chỉnh sửa tài khoản ' . e((string) $edit['username']) : 'Nhập thông tin đăng nhập và chọn vai trò phù hợp.' ?></p>
            </div>

            <?php if ($edit): ?>
                <a class="btn ghost" href="<?= e(url('employees.php')) ?>">Hủy sửa</a>
            <?php endif; ?>
        </div>

        <form method="post" class="employee-form-grid" id="employee-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

            <label>
                Tên đăng nhập
                <input name="username" value="<?= e($edit['username'] ?? '') ?>" placeholder="VD: thungan01" required>
            </label>

            <label>
                Họ tên
                <input name="full_name" value="<?= e($edit['full_name'] ?? '') ?>" placeholder="VD: Nguyễn Văn A" required>
            </label>

            <label>
                Vai trò
                <select name="role">
                    <option value="admin" <?= ($edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Quản lý</option>
                    <option value="cashier" <?= ($edit['role'] ?? 'cashier') === 'cashier' ? 'selected' : '' ?>>Thu ngân</option>
                    <option value="warehouse" <?= ($edit['role'] ?? '') === 'warehouse' ? 'selected' : '' ?>>Nhân viên kho</option>
                </select>
            </label>

            <label>
                Mật khẩu
                <span class="employee-password-box">
                    <input
                        id="employee-password"
                        type="password"
                        name="password"
                        minlength="6"
                        placeholder="<?= $edit ? 'Để trống nếu không đổi' : 'Tối thiểu 6 ký tự' ?>"
                        <?= $edit ? '' : 'required' ?>
                    >
                    <button type="button" class="employee-password-toggle" id="toggle-password" aria-label="Hiện hoặc ẩn mật khẩu">👁️</button>
                </span>
                <small class="field-error" id="password-error"></small>
            </label>

            <div class="employee-form-actions">
                <button class="btn primary"><?= $edit ? 'Lưu thay đổi' : 'Thêm nhân viên' ?></button>

                <?php if (!$edit): ?>
                    <button class="btn ghost" type="reset">Làm mới</button>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel employee-list-card">
        <div class="panel-heading employee-list-heading">
            <div>
                <h2>Danh sách tài khoản</h2>
                <p><?= $totalUsers ?> nhân viên phù hợp với bộ lọc hiện tại</p>
            </div>
        </div>

        <form method="get" class="filter-form employee-filter-form modern-employee-filter">
            <label>
                Tìm kiếm
                <input name="q" value="<?= e($q) ?>" placeholder="Tên đăng nhập hoặc họ tên">
            </label>

            <label>
                Vai trò
                <select name="role">
                    <option value="all">Tất cả</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Quản lý</option>
                    <option value="cashier" <?= $roleFilter === 'cashier' ? 'selected' : '' ?>>Thu ngân</option>
                    <option value="warehouse" <?= $roleFilter === 'warehouse' ? 'selected' : '' ?>>Nhân viên kho</option>
                </select>
            </label>

            <label>
                Trạng thái
                <select name="status">
                    <option value="all">Tất cả</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Đang hoạt động</option>
                    <option value="locked" <?= $statusFilter === 'locked' ? 'selected' : '' ?>>Đã khóa</option>
                </select>
            </label>

            <button class="btn secondary">Lọc</button>

            <?php if ($q !== '' || $roleFilter !== 'all' || $statusFilter !== 'all'): ?>
                <a class="btn ghost" href="<?= e(url('employees.php')) ?>">Xóa lọc</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap employee-table-wrap">
            <table class="employee-table-modern">
                <thead>
                    <tr>
                        <th>Nhân viên</th>
                        <th>Tên đăng nhập</th>
                        <th>Vai trò</th>
                        <th>Trạng thái</th>
                        <th class="right">Thao tác</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$users): ?>
                        <tr>
                            <td colspan="5" class="empty">Không có nhân viên phù hợp.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($users as $user): ?>
                        <?php
                            $isActive = (int) $user['status'] === 1;
                            $role = (string) $user['role'];
                            $isCurrentUser = (int) $user['id'] === (int) current_user()['id'];
                        ?>

                        <tr>
                            <td>
                                <div class="employee-name-cell">
                                    <span class="employee-avatar <?= e(employee_role_class($role)) ?>">
                                        <?= e(employee_role_icon($role)) ?>
                                    </span>

                                    <div>
                                        <strong><?= e($user['full_name']) ?></strong>
                                        <small class="block muted"><?= e(employee_role_note($role)) ?></small>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <strong class="username-pill"><?= e($user['username']) ?></strong>
                            </td>

                            <td>
                                <span class="role-chip <?= e(employee_role_class($role)) ?>">
                                    <?= e(employee_role_label($role)) ?>
                                </span>
                            </td>

                            <td>
                                <span class="status <?= $isActive ? 'paid' : 'cancelled' ?>">
                                    <?= $isActive ? 'Đang hoạt động' : 'Đã khóa' ?>
                                </span>

                                <?php if ($isCurrentUser): ?>
                                    <small class="block muted">Tài khoản hiện tại</small>
                                <?php endif; ?>
                            </td>

                            <td class="right">
                                <div class="table-actions employee-actions">
                                    <a class="btn small outline" href="<?= e(url('employees.php?edit=' . (int) $user['id'])) ?>">Sửa</a>

                                    <?php if (!$isCurrentUser): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">

                                            <button
                                                class="btn small <?= $isActive ? 'danger' : 'secondary' ?>"
                                                data-confirm="<?= $isActive ? 'Bạn chắc chắn muốn khóa tài khoản này?' : 'Bạn muốn mở lại tài khoản này?' ?>"
                                            >
                                                <?= $isActive ? 'Khóa' : 'Mở' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination employee-pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= e(employee_page_url($paginationParams, $page - 1)) ?>">« Trước</a>
                <?php else: ?>
                    <span class="disabled">« Trước</span>
                <?php endif; ?>

                <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= e(employee_page_url($paginationParams, $i)) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(employee_page_url($paginationParams, $page + 1)) ?>">Sau »</a>
                <?php else: ?>
                    <span class="disabled">Sau »</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</div>

<script>
(() => {
    const passwordInput = document.getElementById('employee-password');
    const toggleButton = document.getElementById('toggle-password');
    const passwordError = document.getElementById('password-error');
    const form = document.getElementById('employee-form');

    toggleButton?.addEventListener('click', () => {
        passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
        toggleButton.textContent = passwordInput.type === 'password' ? '👁️' : '🙈';
    });

    function validatePassword() {
        if (!passwordInput) return true;

        const value = passwordInput.value;

        if (passwordInput.required && value.length === 0) {
            passwordError.textContent = 'Vui lòng nhập mật khẩu.';
            passwordInput.classList.add('invalid');
            return false;
        }

        if (value.length > 0 && value.length < 6) {
            passwordError.textContent = 'Mật khẩu phải có ít nhất 6 ký tự.';
            passwordInput.classList.add('invalid');
            return false;
        }

        passwordError.textContent = '';
        passwordInput.classList.remove('invalid');
        return true;
    }

    passwordInput?.addEventListener('input', validatePassword);

    form?.addEventListener('submit', event => {
        if (!validatePassword()) {
            event.preventDefault();
            passwordInput.focus();
        }
    });
})();
</script>

<?php render_footer(); ?>
