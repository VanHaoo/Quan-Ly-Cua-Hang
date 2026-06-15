<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$user = current_user();
$isAdmin = ($user['role'] ?? '') === 'admin';

$sql = 'SELECT i.*, u.full_name, cu.full_name AS cancelled_by_name
        FROM invoices i
        JOIN users u ON u.id = i.user_id
        LEFT JOIN users cu ON cu.id = i.cancelled_by
        WHERE i.id = ?';
$params = [$id];
if (!$isAdmin) {
    $sql .= ' AND i.user_id = ?';
    $params[] = $user['id'];
}
$sql .= ' LIMIT 1';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    exit('Không tìm thấy hóa đơn hoặc bạn không có quyền xem hóa đơn này.');
}

$detailStmt = db()->prepare('SELECT * FROM invoice_details WHERE invoice_id = ? ORDER BY id');
$detailStmt->execute([$id]);
$details = $detailStmt->fetchAll();

$pageTitle = 'Chi tiết hóa đơn';
$activePage = 'invoices';
require __DIR__ . '/partials/header.php';
?>
<div class="invoice-actions no-print">
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/invoices.php">Quay lại</a>
    <button class="btn btn-primary" type="button" onclick="window.print()">In hóa đơn</button>
</div>

<?php if ($invoice['status'] === 'cancelled'): ?>
    <div class="cancelled-notice">
        <strong>Hóa đơn đã hủy</strong>
        <span>Lý do: <?= htmlspecialchars((string) $invoice['cancel_reason']) ?></span>
        <span>Người hủy: <?= htmlspecialchars((string) ($invoice['cancelled_by_name'] ?: 'Không xác định')) ?> lúc <?= $invoice['cancelled_at'] ? date('d/m/Y H:i', strtotime($invoice['cancelled_at'])) : '' ?></span>
    </div>
<?php endif; ?>

<div class="invoice-sheet <?= $invoice['status'] === 'cancelled' ? 'invoice-cancelled' : '' ?>">
    <div class="invoice-header">
        <div>
            <span class="brand-mark large">BH</span>
            <h2>CỬA HÀNG BÁN LẺ</h2>
            <p>Hệ thống quản lý bán hàng tại quầy</p>
        </div>
        <div class="invoice-meta">
            <h1><?= $invoice['status'] === 'cancelled' ? 'HÓA ĐƠN ĐÃ HỦY' : 'HÓA ĐƠN BÁN HÀNG' ?></h1>
            <p><strong><?= htmlspecialchars($invoice['invoice_code']) ?></strong></p>
            <p><?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></p>
            <span class="status-badge <?= $invoice['status'] === 'cancelled' ? 'status-cancelled' : 'status-paid' ?>"><?= invoice_status_label($invoice['status']) ?></span>
        </div>
    </div>

    <div class="invoice-info-grid">
        <p><span>Khách hàng</span><strong><?= htmlspecialchars($invoice['customer_name'] ?: 'Khách lẻ') ?></strong></p>
        <p><span>Nhân viên</span><strong><?= htmlspecialchars($invoice['full_name']) ?></strong></p>
    </div>

    <table class="invoice-table">
        <thead>
        <tr>
            <th>STT</th>
            <th>Mã sản phẩm</th>
            <th>Tên sản phẩm</th>
            <th class="text-center">Số lượng</th>
            <th class="text-right">Đơn giá</th>
            <th class="text-right">Thành tiền</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($details as $index => $detail): ?>
            <tr>
                <td><?= $index + 1 ?></td>
                <td><?= htmlspecialchars($detail['product_code']) ?></td>
                <td><?= htmlspecialchars($detail['product_name']) ?></td>
                <td class="text-center"><?= (int) $detail['quantity'] ?></td>
                <td class="text-right"><?= format_money($detail['price']) ?></td>
                <td class="text-right"><?= format_money($detail['subtotal']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="invoice-totals">
        <p><span>Tổng tiền</span><strong><?= format_money($invoice['total_amount']) ?></strong></p>
        <p><span>Tiền khách đưa</span><strong><?= format_money($invoice['customer_money']) ?></strong></p>
        <p class="grand-total"><span>Tiền thừa</span><strong><?= format_money($invoice['change_money']) ?></strong></p>
    </div>

    <div class="invoice-thanks">Cảm ơn quý khách và hẹn gặp lại</div>
</div>

<?php if ($isAdmin && $invoice['status'] === 'paid'): ?>
    <section class="panel cancel-panel no-print">
        <div class="panel-heading">
            <div>
                <h2>Hủy hóa đơn</h2>
                <p>Khi hủy, hệ thống hoàn trả sản phẩm vào tồn kho và loại hóa đơn khỏi doanh thu</p>
            </div>
        </div>
        <form method="post" action="<?= BASE_URL ?>/actions/cancel_invoice.php" class="form-stack" data-confirm="Bạn chắc chắn muốn hủy hóa đơn này?">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
            <label>
                Lý do hủy hóa đơn
                <textarea name="cancel_reason" rows="3" minlength="3" maxlength="255" required placeholder="Ví dụ nhân viên nhập sai sản phẩm"></textarea>
            </label>
            <div class="button-row">
                <button type="submit" class="btn btn-danger">Xác nhận hủy hóa đơn</button>
            </div>
        </form>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
