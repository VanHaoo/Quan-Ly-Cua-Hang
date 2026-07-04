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

                if (strlen($password) < 6) {
                    throw new RuntimeException('Mật khẩu phải có ít nhất 6 ký tự.');
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

            $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$newStatus, $id]);

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
}

$q = trim((string) ($_GET['q'] ?? ''));
$roleFilter = (string) ($_GET['role'] ?? 'all');
$statusFilter = (string) ($_GET['status'] ?? 'all');

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

$sql = 'SELECT * FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY role, id DESC';
$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}

$stmt->execute();
$users = $stmt->fetchAll();

$summary = [
    'total' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status=1')->fetchColumn(),
    'locked' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status=0')->fetchColumn(),
];

render_header('Nhân viên & phân quyền', 'employees');
?>

<div class="stats-grid employee-summary-grid">
    <article class="stat-card">
        <span>Tổng nhân viên</span>
        <strong><?= $summary['total'] ?></strong>
        <small>Toàn bộ tài khoản hệ thống</small>
    </article>

    <article class="stat-card">
        <span>Đang hoạt động</span>
        <strong><?= $summary['active'] ?></strong>
        <small>Có thể đăng nhập và thao tác</small>
    </article>

    <article class="stat-card warning">
        <span>Đã khóa tài khoản</span>
        <strong><?= $summary['locked'] ?></strong>
        <small>Không thể đăng nhập hệ thống</small>
    </article>
</div>

<div class="two-column employee-page-layout">
    <section class="panel form-panel">
        <div class="panel-heading">
            <div>
                <h2><?= $edit ? 'Cập nhật nhân viên' : 'Thêm nhân viên' ?></h2>
                <p>Phân quyền theo vai trò trong sơ đồ Use Case</p>
            </div>
        </div>

        <form method="post" class="form-grid one-column employee-form" id="employee-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

            <label>
                Tên đăng nhập
                <input name="username" value="<?= e($edit['username'] ?? '') ?>" required>
            </label>

            <label>
                Họ tên
                <input name="full_name" value="<?= e($edit['full_name'] ?? '') ?>" required>
            </label>

            <label>
                Vai trò
                <select name="role">
                    <option value="admin" <?= ($edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Quản lý</option>
                    <option value="cashier" <?= ($edit['role'] ?? '') === 'cashier' ? 'selected' : '' ?>>Thu ngân</option>
                    <option value="warehouse" <?= ($edit['role'] ?? '') === 'warehouse' ? 'selected' : '' ?>>Nhân viên kho</option>
                </select>
            </label>

            <label>
                Mật khẩu
                <div class="password-field">
                    <input
                        id="employee-password"
                        type="password"
                        name="password"
                        minlength="6"
                        placeholder="<?= $edit ? 'Để trống nếu không đổi' : 'Tối thiểu 6 ký tự' ?>"
                        <?= $edit ? '' : 'required' ?>
                    >
                    <button type="button" class="password-toggle" id="toggle-password" aria-label="Hiện hoặc ẩn mật khẩu">👁️</button>
                </div>
                <small class="field-error" id="password-error"></small>
            </label>

            <button class="btn primary"><?= $edit ? 'Lưu thay đổi' : 'Thêm nhân viên' ?></button>

            <?php if ($edit): ?>
                <a class="btn ghost" href="employees.php">Hủy sửa</a>
            <?php endif; ?>
        </form>
    </section>

    <section class="panel employee-list-panel">
        <div class="panel-heading responsive">
            <div>
                <h2>Danh sách tài khoản</h2>
                <p><?= count($users) ?> nhân viên phù hợp</p>
            </div>

            <form method="get" class="filter-form employee-filter-form">
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
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tài khoản</th>
                        <th>Họ tên</th>
                        <th>Vai trò</th>
                        <th>Trạng thái</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$users): ?>
                        <tr>
                            <td colspan="5" class="empty">Không có nhân viên phù hợp.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($users as $user): ?>
                        <?php $isActive = (int) $user['status'] === 1; ?>

                        <tr>
                            <td><strong><?= e($user['username']) ?></strong></td>
                            <td><?= e($user['full_name']) ?></td>
                            <td><?= e(employee_role_label((string) $user['role'])) ?></td>
                            <td>
                                <span class="status <?= $isActive ? 'paid' : 'cancelled' ?>">
                                    <?= $isActive ? 'Đang hoạt động' : 'Đã khóa' ?>
                                </span>
                            </td>
                            <td class="actions employee-actions">
                                <a class="btn small outline" href="?edit=<?= (int) $user['id'] ?>">Sửa</a>

                                <?php if ((int) $user['id'] !== (int) current_user()['id']): ?>
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
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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