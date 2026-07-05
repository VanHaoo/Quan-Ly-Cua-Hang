<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_admin();

function valid_date_input(string $value): ?string
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    $hasErrors = is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0);

    return (!$date || $hasErrors || $date->format('Y-m-d') !== $value) ? null : $value;
}

$pdo = db();

$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-d');

$from = valid_date_input((string) ($_GET['from'] ?? $defaultFrom)) ?? $defaultFrom;
$to = valid_date_input((string) ($_GET['to'] ?? $defaultTo)) ?? $defaultTo;

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS invoice_count,
        COALESCE(SUM(total_amount),0) AS revenue,
        COALESCE(AVG(total_amount),0) AS average_value
    FROM invoices
    WHERE status='paid'
    AND DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$from, $to]);
$summary = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(d.quantity),0)
    FROM invoice_details d
    JOIN invoices i ON i.id=d.invoice_id
    WHERE i.status='paid'
    AND DATE(i.created_at) BETWEEN ? AND ?
");
$stmt->execute([$from, $to]);
$soldQuantity = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT
        DATE(created_at) AS sale_date,
        COUNT(*) AS invoice_count,
        SUM(total_amount) AS revenue
    FROM invoices
    WHERE status='paid'
    AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY sale_date DESC
");
$stmt->execute([$from, $to]);
$daily = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        d.product_code,
        d.product_name,
        SUM(d.quantity) AS sold_quantity,
        SUM(d.subtotal) AS revenue
    FROM invoice_details d
    JOIN invoices i ON i.id=d.invoice_id
    WHERE i.status='paid'
    AND DATE(i.created_at) BETWEEN ? AND ?
    GROUP BY d.product_id,d.product_code,d.product_name
    ORDER BY sold_quantity DESC
    LIMIT 10
");
$stmt->execute([$from, $to]);
$topProducts = $stmt->fetchAll();

$lowStock = $pdo
    ->query('SELECT code,name,stock,min_stock,unit FROM products WHERE status=1 AND stock<=min_stock ORDER BY stock ASC')
    ->fetchAll();

$daysCount = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
$averagePerDay = (float) $summary['revenue'] / $daysCount;

render_header('Báo cáo doanh thu', 'statistics');
?>

<div class="statistics-page unified-page">
    <section class="panel unified-hero statistics-hero">
        <div>
            <span class="eyebrow">BÁO CÁO DOANH THU</span>
            <h2>Theo dõi doanh thu, hóa đơn và sản phẩm bán chạy.</h2>
            <p>Dữ liệu được tính theo các hóa đơn đã thanh toán trong khoảng thời gian bạn chọn.</p>
        </div>

        <form method="get" class="statistics-range-form">
            <label>
                Từ ngày
                <input type="date" name="from" value="<?= e($from) ?>">
            </label>

            <label>
                Đến ngày
                <input type="date" name="to" value="<?= e($to) ?>">
            </label>

            <button class="btn primary">Xem thống kê</button>
        </form>
    </section>

    <div class="stats-grid statistics-summary-grid">
        <article class="mini-stat">
            <span>Tổng doanh thu</span>
            <strong><?= money($summary['revenue']) ?></strong>
            <small>Từ <?= date('d/m/Y', strtotime($from)) ?> đến <?= date('d/m/Y', strtotime($to)) ?></small>
        </article>

        <article class="mini-stat">
            <span>Số hóa đơn</span>
            <strong><?= (int) $summary['invoice_count'] ?></strong>
            <small>Hóa đơn đã thanh toán</small>
        </article>

        <article class="mini-stat">
            <span>Giá trị trung bình</span>
            <strong><?= money($summary['average_value']) ?></strong>
            <small>Trung bình mỗi hóa đơn</small>
        </article>

        <article class="mini-stat">
            <span>Sản phẩm đã bán</span>
            <strong><?= $soldQuantity ?></strong>
            <small>Tổng số lượng bán ra</small>
        </article>
    </div>

    <section class="panel statistics-insight-panel">
        <div class="insight-grid">
            <article>
                <span>Doanh thu bình quân/ngày</span>
                <strong><?= money($averagePerDay) ?></strong>
                <small>Dựa trên <?= $daysCount ?> ngày trong bộ lọc</small>
            </article>

            <article>
                <span>Ngày có dữ liệu bán hàng</span>
                <strong><?= count($daily) ?></strong>
                <small>Số ngày phát sinh hóa đơn</small>
            </article>

            <article>
                <span>Sản phẩm cần nhập thêm</span>
                <strong><?= count($lowStock) ?></strong>
                <small>Sản phẩm dưới mức cảnh báo</small>
            </article>
        </div>
    </section>

    <div class="statistics-grid">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Doanh thu theo ngày</h2>
                    <p>Chi tiết số hóa đơn và doanh thu từng ngày</p>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Ngày</th>
                            <th class="right">Hóa đơn</th>
                            <th class="right">Doanh thu</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$daily): ?>
                            <tr>
                                <td colspan="3" class="empty">Chưa có dữ liệu.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($daily as $row): ?>
                            <tr>
                                <td><strong><?= date('d/m/Y', strtotime($row['sale_date'])) ?></strong></td>
                                <td class="right"><?= (int) $row['invoice_count'] ?></td>
                                <td class="right"><?= money($row['revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Sản phẩm bán chạy</h2>
                    <p>Top 10 theo số lượng đã bán</p>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Sản phẩm</th>
                            <th class="right">Đã bán</th>
                            <th class="right">Doanh thu</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$topProducts): ?>
                            <tr>
                                <td colspan="3" class="empty">Chưa có dữ liệu.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($topProducts as $index => $row): ?>
                            <tr>
                                <td>
                                    <div class="rank-product-cell">
                                        <span><?= $index + 1 ?></span>
                                        <div>
                                            <strong><?= e($row['product_name']) ?></strong>
                                            <small class="block muted"><?= e($row['product_code']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td class="right"><?= (int) $row['sold_quantity'] ?></td>
                                <td class="right"><?= money($row['revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Sản phẩm sắp hết hàng</h2>
                <p>Cảnh báo theo mức tồn tối thiểu</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Sản phẩm</th>
                        <th class="right">Còn lại</th>
                        <th class="right">Mức cảnh báo</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$lowStock): ?>
                        <tr>
                            <td colspan="4" class="empty">Không có sản phẩm sắp hết.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($lowStock as $row): ?>
                        <tr>
                            <td><strong><?= e($row['code']) ?></strong></td>
                            <td><?= e($row['name']) ?></td>
                            <td class="right"><span class="stock low"><?= (int) $row['stock'] ?> <?= e($row['unit']) ?></span></td>
                            <td class="right"><?= (int) $row['min_stock'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php render_footer(); ?>
