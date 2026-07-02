<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_roles('admin','cashier');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?? '';
            if ($name === '' || !preg_match('/^[0-9]{9,11}$/', $phone)) throw new RuntimeException('Nhập tên và số điện thoại hợp lệ.');
            if ($id > 0) {
                $pdo->prepare('UPDATE customers SET name=?, phone=? WHERE id=?')->execute([$name,$phone,$id]);
                log_activity('customer_update', 'Cập nhật khách hàng ' . $name);
                flash('success', 'Đã cập nhật khách hàng.');
            } else {
                $pdo->prepare('INSERT INTO customers(name,phone) VALUES(?,?)')->execute([$name,$phone]);
                log_activity('customer_create', 'Thêm khách hàng ' . $name);
                flash('success', 'Đã thêm khách hàng.');
            }
        }
        if ($action === 'toggle' && is_admin()) {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT name,status FROM customers WHERE id=?');
            $stmt->execute([$id]);
            $customer = $stmt->fetch();
            if (!$customer) throw new RuntimeException('Không tìm thấy khách hàng.');
            $newStatus = (int)$customer['status'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE customers SET status=? WHERE id=?')->execute([$newStatus,$id]);
            log_activity('customer_status', ($newStatus?'Kích hoạt':'Ngừng') . ' khách hàng ' . $customer['name']);
            flash('success', 'Đã cập nhật trạng thái khách hàng.');
        }
    } catch (PDOException $e) {
        flash('error', $e->getCode()==='23000' ? 'Số điện thoại đã tồn tại.' : 'Không thể lưu khách hàng.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('customers.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
$q = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT c.*, COUNT(i.id) AS invoice_count FROM customers c LEFT JOIN invoices i ON i.customer_id=c.id AND i.status="paid" WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (c.name LIKE ? OR c.phone LIKE ?)';
    $s = '%' . $q . '%';
    $params = [$s,$s];
}
$sql .= ' GROUP BY c.id ORDER BY c.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();
render_header('Khách hàng - tích điểm', 'customers');
?>
<div class="two-column">
    <section class="panel form-panel">
        <div class="panel-heading"><div><h2><?= $edit ? 'Cập nhật khách hàng' : 'Thêm khách hàng' ?></h2><p>Hỗ trợ tra cứu, tích điểm và voucher khi mua hàng</p></div></div>
        <form method="post" class="form-grid one-column">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <label>Họ tên khách hàng<input name="name" value="<?= e($edit['name'] ?? '') ?>" required></label>
            <label>Số điện thoại<input name="phone" value="<?= e($edit['phone'] ?? '') ?>" required maxlength="11"></label>
            <button class="btn primary"><?= $edit ? 'Lưu thay đổi' : 'Thêm khách hàng' ?></button>
            <?php if ($edit): ?><a class="btn ghost" href="customers.php">Hủy sửa</a><?php endif; ?>
        </form>
    </section>
    <section class="panel">
        <div class="panel-heading responsive"><div><h2>Danh sách khách hàng</h2><p><?= count($customers) ?> khách hàng</p></div><form class="filter-form"><input name="q" value="<?= e($q) ?>" placeholder="Tìm tên hoặc số điện thoại"><button class="btn secondary">Lọc</button></form></div>
        <div class="table-wrap"><table><thead><tr><th>Khách hàng</th><th>SĐT</th><th class="right">Điểm</th><th class="right">Tổng chi tiêu</th><th class="right">HĐ</th><th>Thao tác</th></tr></thead><tbody>
        <?php if (!$customers): ?><tr><td colspan="6" class="empty">Chưa có khách hàng.</td></tr><?php endif; ?>
        <?php foreach ($customers as $c): ?><tr>
            <td><strong><?= e($c['name']) ?></strong><small class="block muted"><?= (int)$c['status']===1?'Đang hoạt động':'Ngừng theo dõi' ?></small></td>
            <td><?= e($c['phone']) ?></td><td class="right"><?= (int)$c['points'] ?></td><td class="right"><?= money($c['total_spent']) ?></td><td class="right"><?= (int)$c['invoice_count'] ?></td>
            <td class="actions"><a class="btn small ghost" href="?edit=<?= (int)$c['id'] ?>">Sửa</a><?php if (is_admin()): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn small <?= (int)$c['status']===1?'danger':'secondary' ?>" data-confirm="Đổi trạng thái khách hàng?"><?= (int)$c['status']===1?'Ngừng':'Kích hoạt' ?></button></form><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody></table></div>
    </section>
</div>
<?php render_footer(); ?>
