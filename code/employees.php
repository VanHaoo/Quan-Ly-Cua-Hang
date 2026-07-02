<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_admin();
$pdo = db();

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
            if ($username === '' || $fullName === '' || !in_array($role, ['admin','cashier','warehouse'], true)) throw new RuntimeException('Thông tin nhân viên không hợp lệ.');
            if ($id > 0) {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET username=?, full_name=?, role=?, password=? WHERE id=?');
                    $stmt->execute([$username,$fullName,$role,password_hash($password, PASSWORD_DEFAULT),$id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET username=?, full_name=?, role=? WHERE id=?');
                    $stmt->execute([$username,$fullName,$role,$id]);
                }
                log_activity('user_update', 'Cập nhật nhân viên ' . $fullName);
                flash('success', 'Đã cập nhật nhân viên.');
            } else {
                if ($password === '') throw new RuntimeException('Nhân viên mới cần nhập mật khẩu.');
                $stmt = $pdo->prepare('INSERT INTO users(username,password,full_name,role,status) VALUES(?,?,?,?,1)');
                $stmt->execute([$username,password_hash($password, PASSWORD_DEFAULT),$fullName,$role]);
                log_activity('user_create', 'Thêm nhân viên ' . $fullName);
                flash('success', 'Đã thêm nhân viên.');
            }
        }
        if ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) current_user()['id']) throw new RuntimeException('Không thể khóa chính tài khoản đang đăng nhập.');
            $stmt = $pdo->prepare('SELECT full_name,status FROM users WHERE id=?');
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if (!$u) throw new RuntimeException('Không tìm thấy nhân viên.');
            $newStatus = (int)$u['status'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$newStatus,$id]);
            log_activity('user_status', ($newStatus?'Kích hoạt':'Khóa') . ' tài khoản ' . $u['full_name']);
            flash('success', 'Đã cập nhật trạng thái tài khoản.');
        }
    } catch (PDOException $e) {
        flash('error', $e->getCode()==='23000' ? 'Tên đăng nhập đã tồn tại.' : 'Không thể lưu nhân viên.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('employees.php');
}
$edit = null;
if (isset($_GET['edit'])) { $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit = $stmt->fetch() ?: null; }
$users = $pdo->query('SELECT * FROM users ORDER BY role, id DESC')->fetchAll();
render_header('Nhân viên & phân quyền', 'employees');
?>
<div class="two-column">
    <section class="panel form-panel"><div class="panel-heading"><div><h2><?= $edit?'Cập nhật nhân viên':'Thêm nhân viên' ?></h2><p>Phân quyền theo vai trò trong sơ đồ Use Case</p></div></div>
        <form method="post" class="form-grid one-column"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <label>Tên đăng nhập<input name="username" value="<?= e($edit['username'] ?? '') ?>" required></label>
            <label>Họ tên<input name="full_name" value="<?= e($edit['full_name'] ?? '') ?>" required></label>
            <label>Vai trò<select name="role"><option value="cashier" <?= ($edit['role'] ?? '')==='cashier'?'selected':'' ?>>Thu ngân</option><option value="warehouse" <?= ($edit['role'] ?? '')==='warehouse'?'selected':'' ?>>Nhân viên kho</option><option value="admin" <?= ($edit['role'] ?? '')==='admin'?'selected':'' ?>>Quản lý</option></select></label>
            <label>Mật khẩu<input type="password" name="password" placeholder="<?= $edit ? 'Để trống nếu không đổi' : 'Bắt buộc' ?>"></label>
            <button class="btn primary"><?= $edit?'Lưu thay đổi':'Thêm nhân viên' ?></button><?php if ($edit): ?><a class="btn ghost" href="employees.php">Hủy sửa</a><?php endif; ?>
        </form>
    </section>
    <section class="panel"><div class="panel-heading"><div><h2>Danh sách tài khoản</h2><p>Quản lý nhân viên và phân quyền người dùng</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Tài khoản</th><th>Họ tên</th><th>Vai trò</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
        <?php foreach ($users as $u): ?><tr><td><strong><?= e($u['username']) ?></strong></td><td><?= e($u['full_name']) ?></td><td><?= e(role_name($u['role'])) ?></td><td><span class="status <?= (int)$u['status']===1?'paid':'cancelled' ?>"><?= (int)$u['status']===1?'Đang dùng':'Đã khóa' ?></span></td><td class="actions"><a class="btn small ghost" href="?edit=<?= (int)$u['id'] ?>">Sửa</a><?php if ((int)$u['id'] !== (int)current_user()['id']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn small <?= (int)$u['status']===1?'danger':'secondary' ?>" data-confirm="Đổi trạng thái tài khoản?"><?= (int)$u['status']===1?'Khóa':'Mở' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php render_footer(); ?>
