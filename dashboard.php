<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_login();

$pdo = db();
$user = current_user();
$isAdmin = ($user['role'] ?? '') === 'admin';

$whereUser = $isAdmin ? '' : ' AND i.user_id = :user_id';
$params = [];
if (!$isAdmin) {
    $params['user_id'] = $user['id'];
}

$stmt = $pdo->prepare("SELECT COUNT(*) AS invoice_count, COALESCE(SUM(i.total_amount), 0) AS revenue
                      FROM invoices i
                      WHERE DATE(i.created_at) = CURDATE()
                        AND i.status = 'paid' {$whereUser}");
$stmt->execute($params);
$today = $stmt->fetch();

$productCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = 1')->fetchColumn();
$lowStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = 1 AND stock <= min_stock')->fetchColumn();

$recentSql = "SELECT i.id, i.invoice_code, i.customer_name, i.total_amount, i.created_at, i.status, u.full_name
              FROM invoices i
              JOIN users u ON u.id = i.user_id
              WHERE 1=1 {$whereUser}
              ORDER BY i.id DESC
              LIMIT 7";
$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute($params);
$recentInvoices = $recentStmt->fetchAll();

$pageTitle = 'Tổng quan';
$activePage = 'dashboard';
require __DIR__ . '/partials/header.php';
?>
<div class="welcome-panel">
    <div>
        <span class="eyebrow">Xin chào</span>
        <h2><?= htmlspecialchars((string) $user['full_name']) ?></h2>
        <p><?= $isAdmin ? 'Theo dõi tình hình bán hàng, tồn kho và các giao dịch của cửa hàng.' : 'Bắt đầu giao dịch mới hoặc kiểm tra các hóa đơn đã lập.' ?></p>
    </div>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/sales.php">Tạo đơn bán hàng</a>
</div>

<div class="stats-grid">
    <article class="stat-card">
        <span>Doanh thu hôm nay</span>
        <strong><?= format_money($today['revenue']) ?></strong>
        <small>Không tính các hóa đơn đã hủy</small>
    </article>
    <article class="stat-card">
        <span>Hóa đơn hôm nay</span>
        <strong><?= (int) $today['invoice_count'] ?></strong>
        <small>Số giao dịch thanh toán thành công</small>
    </article>
    <article class="stat-card">
        <span>Sản phẩm đang bán</span>
        <strong><?= $productCount ?></strong>
        <small>Sản phẩm còn hoạt động trên hệ thống</small>
    </article>
    <article class="stat-card warning-card">
        <span>Sắp hết hàng</span>
        <strong><?= $lowStockCount ?></strong>
        <small>Dựa trên mức cảnh báo của từng sản phẩm</small>
    </article>
</div>

<div class="panel">
    <div class="panel-heading">
        <div>
            <h2>Giao dịch gần đây</h2>
            <p>Các hóa đơn mới nhất được ghi nhận</p>
        </div>
        <a href="<?= BASE_URL ?>/invoices.php" class="text-link">Xem tất cả</a>
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
                <th class="text-right">Tổng tiền</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$recentInvoices): ?>
                <tr><td colspan="6" class="empty-cell">Chưa có giao dịch nào.</td></tr>
            <?php else: ?>
                <?php foreach ($recentInvoices as $invoice): ?>
                    <tr class="<?= $invoice['status'] === 'cancelled' ? 'row-muted' : '' ?>">
                        <td><a class="code-link" href="<?= BASE_URL ?>/invoice_detail.php?id=<?= (int) $invoice['id'] ?>"><?= htmlspecialchars($invoice['invoice_code']) ?></a></td>
                        <td><?= htmlspecialchars($invoice['customer_name'] ?: 'Khách lẻ') ?></td>
                        <td><?= htmlspecialchars($invoice['full_name']) ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></td>
                        <td><span class="status-badge <?= $invoice['status'] === 'cancelled' ? 'status-cancelled' : 'status-paid' ?>"><?= invoice_status_label($invoice['status']) ?></span></td>
                        <td class="text-right"><strong><?= format_money($invoice['total_amount']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
