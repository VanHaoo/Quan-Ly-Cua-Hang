<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$userId = (int) current_user()['id'];
$isAdmin = is_admin();

if ($isAdmin) {
    $todayRevenue = (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE status='paid' AND DATE(created_at)=CURDATE()")->fetchColumn();
    $todayInvoices = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='paid' AND DATE(created_at)=CURDATE()")->fetchColumn();
} else {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE status='paid' AND DATE(created_at)=CURDATE() AND user_id=?");
    $stmt->execute([$userId]);
    $todayRevenue = (float) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE status='paid' AND DATE(created_at)=CURDATE() AND user_id=?");
    $stmt->execute([$userId]);
    $todayInvoices = (int) $stmt->fetchColumn();
}
$productCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status=1')->fetchColumn();
$lowStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status=1 AND stock<=min_stock')->fetchColumn();
$customerCount = (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE status=1')->fetchColumn();

$sql = "SELECT i.id,i.invoice_code,i.customer_name,i.total_amount,i.status,i.created_at,u.full_name FROM invoices i JOIN users u ON u.id=i.user_id WHERE 1=1";
$params = [];
if (!$isAdmin) {
    $sql .= ' AND i.user_id=?';
    $params[] = $userId;
}
$sql .= ' ORDER BY i.id DESC LIMIT 8';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recentInvoices = $stmt->fetchAll();

$lowStock = $pdo->query('SELECT code,name,stock,min_stock,unit FROM products WHERE status=1 AND stock<=min_stock ORDER BY stock ASC LIMIT 6')->fetchAll();
$recentLogs = [];
if ($isAdmin) {
    $recentLogs = $pdo->query('SELECT l.*,u.full_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 6')->fetchAll();
}

render_header('Bảng điều khiển', 'dashboard');
?>
<div class="hero-panel">
    <div>
        <span class="eyebrow">Hệ thống quản lý bán hàng tại quầy</span>
        <h2>Theo dõi nhanh bán hàng, hóa đơn, khách hàng và tồn kho.</h2>
        <p>Giao diện được chia theo đúng nhóm chức năng trong sơ đồ Use Case: thu ngân, quản lý, nhân viên kho và khách hàng.</p>
    </div>
    <div class="hero-actions">
        <?php if (has_role('admin','cashier')): ?><a class="btn primary" href="<?= e(url('sales.php')) ?>">Tạo đơn bán hàng</a><?php endif; ?>
        <?php if (has_role('admin','warehouse')): ?><a class="btn secondary" href="<?= e(url('stock_import.php')) ?>">Nhập hàng</a><?php endif; ?>
    </div>
</div>

<div class="stats-grid">
    <article class="stat-card"><span>Doanh thu hôm nay</span><strong><?= money($todayRevenue) ?></strong><small>Không tính hóa đơn đã hủy</small></article>
    <article class="stat-card"><span>Hóa đơn hôm nay</span><strong><?= $todayInvoices ?></strong><small>Giao dịch hoàn tất</small></article>
    <article class="stat-card"><span>Sản phẩm đang bán</span><strong><?= $productCount ?></strong><small>Danh mục hiện có</small></article>
    <article class="stat-card warning"><span>Sắp hết hàng</span><strong><?= $lowStockCount ?></strong><small>Cần kiểm tra kho</small></article>
    <article class="stat-card"><span>Khách thành viên</span><strong><?= $customerCount ?></strong><small>Phục vụ tích điểm</small></article>
</div>

<div class="two-column equal">
    <section class="panel">
        <div class="panel-heading"><div><h2>Giao dịch gần đây</h2><p>Các hóa đơn mới nhất</p></div><a class="btn secondary" href="<?= e(url('invoices.php')) ?>">Xem hóa đơn</a></div>
        <div class="table-wrap"><table><thead><tr><th>Mã</th><th>Khách hàng</th><th>Nhân viên</th><th>Trạng thái</th><th class="right">Tổng tiền</th></tr></thead><tbody>
        <?php if (!$recentInvoices): ?><tr><td colspan="5" class="empty">Chưa có giao dịch.</td></tr><?php endif; ?>
        <?php foreach ($recentInvoices as $invoice): ?><tr>
            <td><a href="<?= e(url('invoices.php?id=' . $invoice['id'])) ?>"><strong><?= e($invoice['invoice_code']) ?></strong></a><small class="block muted"><?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></small></td>
            <td><?= e($invoice['customer_name'] ?: 'Khách lẻ') ?></td>
            <td><?= e($invoice['full_name']) ?></td>
            <td><span class="status <?= e($invoice['status']) ?>"><?= $invoice['status']==='paid'?'Đã thanh toán':'Đã hủy' ?></span></td>
            <td class="right"><?= money($invoice['total_amount']) ?></td>
        </tr><?php endforeach; ?></tbody></table></div>
    </section>

    <section class="panel">
        <div class="panel-heading"><div><h2>Cảnh báo tồn kho</h2><p>Sản phẩm cần nhập thêm</p></div><a class="btn secondary" href="<?= e(url('inventory.php')) ?>">Kiểm tra kho</a></div>
        <div class="table-wrap"><table><thead><tr><th>Mã</th><th>Sản phẩm</th><th class="right">Tồn</th><th class="right">Mức cảnh báo</th></tr></thead><tbody>
        <?php if (!$lowStock): ?><tr><td colspan="4" class="empty">Không có sản phẩm sắp hết.</td></tr><?php endif; ?>
        <?php foreach ($lowStock as $row): ?><tr><td><?= e($row['code']) ?></td><td><?= e($row['name']) ?></td><td class="right"><span class="stock low"><?= (int)$row['stock'] ?> <?= e($row['unit']) ?></span></td><td class="right"><?= (int)$row['min_stock'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>

<?php if ($recentLogs): ?>
<section class="panel">
    <div class="panel-heading"><div><h2>Nhật ký hoạt động</h2><p>Theo dõi thao tác quan trọng trong hệ thống</p></div></div>
    <div class="activity-list">
        <?php foreach ($recentLogs as $log): ?><div><strong><?= e($log['full_name'] ?: 'Hệ thống') ?></strong><span><?= e($log['description']) ?></span><small><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></small></div><?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php render_footer(); ?>
