<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$userId = (int) current_user()['id'];

if (is_admin()) {
    $todayRevenue = (float) $pdo->query(
        "SELECT COALESCE(SUM(total_amount), 0)
         FROM invoices
         WHERE status = 'paid'
           AND DATE(created_at) = CURDATE()"
    )->fetchColumn();

    $todayInvoices = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM invoices
         WHERE status = 'paid'
           AND DATE(created_at) = CURDATE()"
    )->fetchColumn();
} else {
    $revenueStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount), 0)
         FROM invoices
         WHERE status = 'paid'
           AND DATE(created_at) = CURDATE()
           AND user_id = ?"
    );
    $revenueStmt->execute([$userId]);
    $todayRevenue = (float) $revenueStmt->fetchColumn();

    $invoiceCountStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM invoices
         WHERE status = 'paid'
           AND DATE(created_at) = CURDATE()
           AND user_id = ?"
    );
    $invoiceCountStmt->execute([$userId]);
    $todayInvoices = (int) $invoiceCountStmt->fetchColumn();
}

$productCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM products WHERE status = 1'
)->fetchColumn();

$lowStockCount = (int) $pdo->query(
    'SELECT COUNT(*)
     FROM products
     WHERE status = 1
       AND stock <= min_stock'
)->fetchColumn();

$sql = "SELECT
            i.id,
            i.invoice_code,
            i.customer_name,
            i.total_amount,
            i.status,
            i.created_at,
            u.full_name
        FROM invoices i
        JOIN users u ON u.id = i.user_id
        WHERE 1 = 1";
$params = [];

if (!is_admin()) {
    $sql .= ' AND i.user_id = ?';
    $params[] = $userId;
}

$sql .= ' ORDER BY i.id DESC LIMIT 8';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recentInvoices = $stmt->fetchAll();

$recentLogs = [];
if (is_admin()) {
    $recentLogs = $pdo->query(
        'SELECT l.*, u.full_name
         FROM activity_logs l
         LEFT JOIN users u ON u.id = l.user_id
         ORDER BY l.id DESC
         LIMIT 6'
    )->fetchAll();
}

render_header('Tổng quan', 'dashboard');
?>
<div class="stats-grid">
    <article class="stat-card">
        <span>Doanh thu hôm nay</span>
        <strong><?= money($todayRevenue) ?></strong>
        <small>Không tính hóa đơn đã hủy</small>
    </article>
    <article class="stat-card">
        <span>Hóa đơn hôm nay</span>
        <strong><?= $todayInvoices ?></strong>
        <small>Giao dịch hoàn tất</small>
    </article>
    <article class="stat-card">
        <span>Sản phẩm đang bán</span>
        <strong><?= $productCount ?></strong>
        <small>Danh mục hiện có</small>
    </article>
    <article class="stat-card warning">
        <span>Sắp hết hàng</span>
        <strong><?= $lowStockCount ?></strong>
        <small>Cần kiểm tra nhập hàng</small>
    </article>
</div>

<div class="panel">
    <div class="panel-heading">
        <div>
            <h2>Giao dịch gần đây</h2>
            <p>Các hóa đơn mới nhất trong hệ thống</p>
        </div>
        <a class="btn secondary" href="<?= e(url('sales.php')) ?>">Tạo đơn bán hàng</a>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Mã hóa đơn</th>
                <th>Khách hàng</th>
                <th>Nhân viên</th>
                <th>Thời gian</th>
                <th>Trạng thái</th>
                <th class="right">Tổng tiền</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$recentInvoices): ?>
                <tr><td colspan="6" class="empty">Chưa có giao dịch.</td></tr>
            <?php endif; ?>

            <?php foreach ($recentInvoices as $invoice): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('invoices.php?id=' . $invoice['id'])) ?>">
                            <strong><?= e($invoice['invoice_code']) ?></strong>
                        </a>
                    </td>
                    <td><?= e($invoice['customer_name'] ?: 'Khách lẻ') ?></td>
                    <td><?= e($invoice['full_name']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></td>
                    <td>
                        <span class="status <?= e($invoice['status']) ?>">
                            <?= $invoice['status'] === 'paid' ? 'Đã thanh toán' : 'Đã hủy' ?>
                        </span>
                    </td>
                    <td class="right"><?= money($invoice['total_amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (is_admin()): ?>
    <div class="panel">
        <div class="panel-heading">
            <div>
                <h2>Hoạt động gần đây</h2>
                <p>Theo dõi thao tác quan trọng trong hệ thống</p>
            </div>
        </div>
        <div class="activity-list">
            <?php if (!$recentLogs): ?>
                <p class="empty">Chưa có lịch sử thao tác.</p>
            <?php endif; ?>

            <?php foreach ($recentLogs as $log): ?>
                <div>
                    <strong><?= e($log['full_name'] ?: 'Hệ thống') ?></strong>
                    <span><?= e($log['description']) ?></span>
                    <small><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
