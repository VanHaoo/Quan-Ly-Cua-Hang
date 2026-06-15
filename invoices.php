<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_login();

$pdo = db();
$user = current_user();
$isAdmin = ($user['role'] ?? '') === 'admin';
$keyword = trim((string) ($_GET['q'] ?? ''));
$date = trim((string) ($_GET['date'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'paid', 'cancelled'], true)) {
    $status = 'all';
}

$sql = 'SELECT i.id, i.invoice_code, i.customer_name, i.total_amount, i.customer_money,
               i.change_money, i.status, i.created_at, u.full_name
        FROM invoices i
        JOIN users u ON u.id = i.user_id
        WHERE 1=1';
$params = [];

if (!$isAdmin) {
    $sql .= ' AND i.user_id = ?';
    $params[] = $user['id'];
}
if ($keyword !== '') {
    $sql .= ' AND (i.invoice_code LIKE ? OR i.customer_name LIKE ? OR u.full_name LIKE ?)';
    $search = '%' . $keyword . '%';
    array_push($params, $search, $search, $search);
}
if ($date !== '') {
    $sql .= ' AND DATE(i.created_at) = ?';
    $params[] = $date;
}
if ($status !== 'all') {
    $sql .= ' AND i.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY i.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$pageTitle = 'Danh sách hóa đơn';
$activePage = 'invoices';
require __DIR__ . '/partials/header.php';
?>
<div class="panel">
    <div class="panel-heading responsive-heading">
        <div>
            <h2>Lịch sử giao dịch</h2>
            <p>Hóa đơn đã hủy vẫn được lưu để đối chiếu</p>
        </div>
        <form method="get" class="filter-form">
            <input type="search" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="Mã hóa đơn hoặc khách hàng">
            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
            <select name="status">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Tất cả trạng thái</option>
                <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Đã thanh toán</option>
                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Đã hủy</option>
            </select>
            <button class="btn btn-secondary" type="submit">Lọc dữ liệu</button>
            <a class="btn btn-ghost" href="<?= BASE_URL ?>/invoices.php">Đặt lại</a>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Mã hóa đơn</th>
                <th>Khách hàng</th>
                <th>Nhân viên</th>
                <th>Ngày lập</th>
                <th>Trạng thái</th>
                <th class="text-right">Tổng tiền</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$invoices): ?>
                <tr><td colspan="7" class="empty-cell">Không có hóa đơn phù hợp.</td></tr>
            <?php else: ?>
                <?php foreach ($invoices as $invoice): ?>
                    <tr class="<?= $invoice['status'] === 'cancelled' ? 'row-muted' : '' ?>">
                        <td><span class="pill"><?= htmlspecialchars($invoice['invoice_code']) ?></span></td>
                        <td><?= htmlspecialchars($invoice['customer_name'] ?: 'Khách lẻ') ?></td>
                        <td><?= htmlspecialchars($invoice['full_name']) ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></td>
                        <td><span class="status-badge <?= $invoice['status'] === 'cancelled' ? 'status-cancelled' : 'status-paid' ?>"><?= invoice_status_label($invoice['status']) ?></span></td>
                        <td class="text-right"><strong><?= format_money($invoice['total_amount']) ?></strong></td>
                        <td><a class="btn btn-small btn-secondary" href="<?= BASE_URL ?>/invoice_detail.php?id=<?= (int) $invoice['id'] ?>">Chi tiết</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
