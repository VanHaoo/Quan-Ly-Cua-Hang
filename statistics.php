<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_admin();

$pdo = db();
$defaultFrom = date('Y-m-d', strtotime('-6 days'));
$defaultTo = date('Y-m-d');
$from = (string) ($_GET['from'] ?? $defaultFrom);
$to = (string) ($_GET['to'] ?? $defaultTo);
$dateError = null;

$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if (!$validDate($from) || !$validDate($to)) {
    $dateError = 'Khoảng thời gian không hợp lệ. Hệ thống đã hiển thị dữ liệu 7 ngày gần nhất.';
    $from = $defaultFrom;
    $to = $defaultTo;
} elseif ($from > $to) {
    $dateError = 'Ngày bắt đầu không được lớn hơn ngày kết thúc. Hệ thống đã hiển thị dữ liệu 7 ngày gần nhất.';
    $from = $defaultFrom;
    $to = $defaultTo;
}

$summaryStmt = $pdo->prepare("SELECT COUNT(*) AS invoice_count,
                                    COALESCE(SUM(total_amount), 0) AS revenue,
                                    COALESCE(AVG(total_amount), 0) AS average_invoice
                             FROM invoices
                             WHERE status = 'paid' AND DATE(created_at) BETWEEN ? AND ?");
$summaryStmt->execute([$from, $to]);
$summary = $summaryStmt->fetch();

$quantityStmt = $pdo->prepare("SELECT COALESCE(SUM(d.quantity), 0)
                               FROM invoice_details d
                               JOIN invoices i ON i.id = d.invoice_id
                               WHERE i.status = 'paid' AND DATE(i.created_at) BETWEEN ? AND ?");
$quantityStmt->execute([$from, $to]);
$totalQuantity = (int) $quantityStmt->fetchColumn();

$dailyStmt = $pdo->prepare("SELECT DATE(created_at) AS sale_date, COUNT(*) AS invoice_count, SUM(total_amount) AS revenue
                            FROM invoices
                            WHERE status = 'paid' AND DATE(created_at) BETWEEN ? AND ?
                            GROUP BY DATE(created_at)
                            ORDER BY sale_date");
$dailyStmt->execute([$from, $to]);
$dailyRows = $dailyStmt->fetchAll();

$topStmt = $pdo->prepare("SELECT d.product_code, d.product_name, SUM(d.quantity) AS sold_quantity, SUM(d.subtotal) AS revenue
                          FROM invoice_details d
                          JOIN invoices i ON i.id = d.invoice_id
                          WHERE i.status = 'paid' AND DATE(i.created_at) BETWEEN ? AND ?
                          GROUP BY d.product_code, d.product_name
                          ORDER BY sold_quantity DESC, revenue DESC
                          LIMIT 10");
$topStmt->execute([$from, $to]);
$topProducts = $topStmt->fetchAll();

$lowStock = $pdo->query(
    'SELECT code, name, stock, min_stock, unit
     FROM products
     WHERE status = 1 AND stock <= min_stock
     ORDER BY stock, name'
)->fetchAll();

$pageTitle = 'Thống kê doanh thu';
$activePage = 'statistics';
require __DIR__ . '/partials/header.php';
?>
<?php if ($dateError): ?>
    <div class="alert error"><?= htmlspecialchars($dateError) ?></div>
<?php endif; ?>

<div class="panel filter-panel">
    <form method="get" class="date-range-form">
        <label>Từ ngày <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" required></label>
        <label>Đến ngày <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" required></label>
        <button type="submit" class="btn btn-primary">Xem thống kê</button>
    </form>
</div>

<div class="stats-grid">
    <article class="stat-card">
        <span>Tổng doanh thu</span>
        <strong><?= format_money($summary['revenue']) ?></strong>
        <small>Không tính hóa đơn đã hủy</small>
    </article>
    <article class="stat-card">
        <span>Số hóa đơn</span>
        <strong><?= (int) $summary['invoice_count'] ?></strong>
        <small>Giao dịch thanh toán thành công</small>
    </article>
    <article class="stat-card">
        <span>Giá trị trung bình</span>
        <strong><?= format_money($summary['average_invoice']) ?></strong>
        <small>Giá trị trung bình mỗi hóa đơn</small>
    </article>
    <article class="stat-card">
        <span>Sản phẩm đã bán</span>
        <strong><?= $totalQuantity ?></strong>
        <small>Tổng số lượng sản phẩm</small>
    </article>
</div>

<div class="two-panel-grid">
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Doanh thu theo ngày</h2>
                <p>Theo dõi kết quả bán hàng từng ngày</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Ngày</th><th class="text-center">Hóa đơn</th><th class="text-right">Doanh thu</th></tr></thead>
                <tbody>
                <?php if (!$dailyRows): ?>
                    <tr><td colspan="3" class="empty-cell">Chưa có dữ liệu trong khoảng thời gian này.</td></tr>
                <?php else: ?>
                    <?php foreach ($dailyRows as $row): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($row['sale_date'])) ?></td>
                            <td class="text-center"><?= (int) $row['invoice_count'] ?></td>
                            <td class="text-right"><strong><?= format_money($row['revenue']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Sản phẩm bán chạy</h2>
                <p>Xếp hạng theo số lượng đã bán</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sản phẩm</th><th class="text-center">Đã bán</th><th class="text-right">Doanh thu</th></tr></thead>
                <tbody>
                <?php if (!$topProducts): ?>
                    <tr><td colspan="3" class="empty-cell">Chưa có dữ liệu sản phẩm.</td></tr>
                <?php else: ?>
                    <?php foreach ($topProducts as $product): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($product['product_name']) ?></strong><small class="muted-line"><?= htmlspecialchars($product['product_code']) ?></small></td>
                            <td class="text-center"><?= (int) $product['sold_quantity'] ?></td>
                            <td class="text-right"><?= format_money($product['revenue']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<section class="panel">
    <div class="panel-heading">
        <div>
            <h2>Sản phẩm sắp hết hàng</h2>
            <p>Cảnh báo theo mức tối thiểu được thiết lập cho từng sản phẩm</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Mã</th><th>Sản phẩm</th><th class="text-center">Số lượng còn</th><th class="text-center">Mức cảnh báo</th></tr></thead>
            <tbody>
            <?php if (!$lowStock): ?>
                <tr><td colspan="4" class="empty-cell">Không có sản phẩm nào sắp hết hàng.</td></tr>
            <?php else: ?>
                <?php foreach ($lowStock as $product): ?>
                    <tr>
                        <td><?= htmlspecialchars($product['code']) ?></td>
                        <td><?= htmlspecialchars($product['name']) ?></td>
                        <td class="text-center"><span class="stock-badge low"><?= (int) $product['stock'] ?> <?= htmlspecialchars($product['unit']) ?></span></td>
                        <td class="text-center"><?= (int) $product['min_stock'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
