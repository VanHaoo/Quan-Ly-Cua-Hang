<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$userId = (int) current_user()['id'];
$isAdmin = is_admin();

function dashboard_scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function dashboard_compare(float $current, float $previous): array
{
    if ($previous > 0) {
        $percent = (($current - $previous) / $previous) * 100;
    } elseif ($current > 0) {
        $percent = 100;
    } else {
        $percent = 0;
    }

    if ($percent > 0) {
        return [
            'class' => 'up',
            'text' => '↑ ' . number_format(abs($percent), 1, ',', '.') . '% so với hôm qua',
        ];
    }

    if ($percent < 0) {
        return [
            'class' => 'down',
            'text' => '↓ ' . number_format(abs($percent), 1, ',', '.') . '% so với hôm qua',
        ];
    }

    return [
        'class' => 'same',
        'text' => 'Không đổi so với hôm qua',
    ];
}

$userFilter = $isAdmin ? '' : ' AND user_id = ?';
$userParams = $isAdmin ? [] : [$userId];

$todayRevenue = (float) dashboard_scalar(
    $pdo,
    "SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE status='paid' AND DATE(created_at)=CURDATE()" . $userFilter,
    $userParams
);

$yesterdayRevenue = (float) dashboard_scalar(
    $pdo,
    "SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE status='paid' AND DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)" . $userFilter,
    $userParams
);

$todayInvoices = (int) dashboard_scalar(
    $pdo,
    "SELECT COUNT(*) FROM invoices WHERE status='paid' AND DATE(created_at)=CURDATE()" . $userFilter,
    $userParams
);

$yesterdayInvoices = (int) dashboard_scalar(
    $pdo,
    "SELECT COUNT(*) FROM invoices WHERE status='paid' AND DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)" . $userFilter,
    $userParams
);

$monthRevenue = (float) dashboard_scalar(
    $pdo,
    "SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE status='paid' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())" . $userFilter,
    $userParams
);

$canceledToday = (int) dashboard_scalar(
    $pdo,
    "SELECT COUNT(*) FROM invoices WHERE status='cancelled' AND DATE(created_at)=CURDATE()" . $userFilter,
    $userParams
);

$productCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status=1')->fetchColumn();
$lowStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status=1 AND stock<=min_stock')->fetchColumn();
$customerCount = (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE status=1')->fetchColumn();

$revenueCompare = dashboard_compare($todayRevenue, $yesterdayRevenue);
$invoiceCompare = dashboard_compare((float) $todayInvoices, (float) $yesterdayInvoices);
$notificationCount = $lowStockCount + $canceledToday;

$chartSql = "SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total_amount),0) AS revenue
             FROM invoices
             WHERE status='paid' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)" . $userFilter . "
             GROUP BY DATE(created_at)
             ORDER BY sale_date ASC";
$stmt = $pdo->prepare($chartSql);
$stmt->execute($userParams);
$chartRows = $stmt->fetchAll();

$chartMap = [];
foreach ($chartRows as $row) {
    $chartMap[(string) $row['sale_date']] = (float) $row['revenue'];
}

$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $chartData[] = [
        'date' => $date,
        'label' => date('d/m', strtotime($date)),
        'revenue' => $chartMap[$date] ?? 0,
    ];
}
$maxChartRevenue = max(1, max(array_column($chartData, 'revenue')));

$sql = "SELECT i.id,i.invoice_code,i.customer_name,i.total_amount,i.status,i.created_at,u.full_name
        FROM invoices i
        JOIN users u ON u.id=i.user_id
        WHERE 1=1";
$params = [];
if (!$isAdmin) {
    $sql .= ' AND i.user_id=?';
    $params[] = $userId;
}
$sql .= ' ORDER BY i.id DESC LIMIT 8';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recentInvoices = $stmt->fetchAll();

$lowStock = $pdo->query('SELECT id,code,name,stock,min_stock,unit FROM products WHERE status=1 AND stock<=min_stock ORDER BY stock ASC LIMIT 6')->fetchAll();

$recentLogs = [];
$staffPerformance = [];

if ($isAdmin) {
    $recentLogs = $pdo->query('SELECT l.*,u.full_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 8')->fetchAll();

    $staffPerformance = $pdo->query("
        SELECT u.full_name, COUNT(i.id) AS invoice_count, COALESCE(SUM(i.total_amount),0) AS revenue
        FROM invoices i
        JOIN users u ON u.id = i.user_id
        WHERE i.status='paid' AND i.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY u.id, u.full_name
        ORDER BY revenue DESC
        LIMIT 4
    ")->fetchAll();
}

render_header('Bảng điều khiển', 'dashboard');
?>

<style>
    .dashboard-toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .notification-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        border-radius: 999px;
        background: #fff7ed;
        border: 1px solid #fed7aa;
        color: #9a3412;
        font-weight: 850;
    }

    .manager-stats-grid {
        grid-template-columns: repeat(6, minmax(130px, 1fr));
    }

    .trend-badge {
        display: inline-flex;
        margin-top: 6px;
        padding: 5px 8px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 850;
    }

    .trend-badge.up {
        background: #dcfce7;
        color: #166534;
    }

    .trend-badge.down {
        background: #fee2e2;
        color: #991b1b;
    }

    .trend-badge.same {
        background: #f1f5f9;
        color: #475569;
    }

    .dashboard-insight-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.6fr) minmax(300px, 0.8fr);
        gap: 18px;
        align-items: stretch;
        margin-bottom: 18px;
    }

    .dashboard-insight-grid .panel {
        margin-bottom: 0;
    }

    .revenue-chart {
        height: 230px;
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 12px;
        align-items: end;
        padding-top: 12px;
    }

    .chart-item {
        height: 100%;
        display: grid;
        grid-template-rows: auto 1fr auto;
        gap: 8px;
        text-align: center;
        min-width: 0;
    }

    .chart-value {
        color: #64748b;
        font-size: 11px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .chart-bar-wrap {
        height: 100%;
        display: flex;
        align-items: end;
        justify-content: center;
        border-radius: 999px;
        background: #eff6ff;
        overflow: hidden;
    }

    .chart-bar {
        width: 100%;
        min-height: 6px;
        border-radius: 999px 999px 0 0;
        background: linear-gradient(180deg, #38bdf8, #2563eb);
    }

    .chart-label {
        font-size: 12px;
        color: #475569;
        font-weight: 750;
    }

    .notification-list {
        display: grid;
        gap: 10px;
    }

    .notification-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px;
        border-radius: 15px;
        background: #f8fbff;
        border: 1px solid #e2e8f0;
    }

    .notification-item strong {
        color: #0f172a;
    }

    .notification-item span {
        color: #64748b;
        font-size: 13px;
    }

    .dashboard-main-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 18px;
        align-items: stretch;
        margin-bottom: 18px;
    }

    .dashboard-main-grid > .panel {
        height: 100%;
        margin-bottom: 0;
        display: flex;
        flex-direction: column;
    }

    .dashboard-main-grid .table-wrap {
        flex: 1;
    }

    .quick-stock-btn {
        white-space: nowrap;
    }

    .staff-grid {
        display: grid;
        gap: 10px;
    }

    .staff-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        padding: 12px;
        border-radius: 15px;
        background: #f8fbff;
        border: 1px solid #e2e8f0;
    }

    .staff-row small {
        display: block;
        color: #64748b;
        margin-top: 4px;
    }

    .activity-list.compact div {
        grid-template-columns: 170px minmax(0, 1fr) auto;
    }

    @media (max-width: 1250px) {
        .manager-stats-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .dashboard-insight-grid,
        .dashboard-main-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 800px) {
        .manager-stats-grid {
            grid-template-columns: 1fr;
        }

        .revenue-chart {
            overflow-x: auto;
            grid-template-columns: repeat(7, minmax(80px, 1fr));
        }

        .activity-list.compact div {
            grid-template-columns: 1fr;
        }
    }
/* ===== DASHBOARD REVENUE CHART FIX ===== */

.clean-bar-chart {
    height: 230px;
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 12px;
    align-items: end;
    padding-top: 12px;
}

.clean-chart-item {
    height: 100%;
    min-width: 0;
    display: grid;
    grid-template-rows: auto 1fr auto;
    gap: 8px;
    text-align: center;
}

.clean-chart-value {
    min-height: 18px;
    color: #64748b;
    font-size: 12px;
    font-weight: 750;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.clean-chart-track {
    height: 100%;
    min-height: 150px;
    display: flex;
    align-items: end;
    justify-content: center;
    border-radius: 999px;
    background: #eff6ff;
    overflow: hidden;
    border: 1px solid #dbeafe;
}

.clean-chart-bar {
    width: 100%;
    height: var(--bar-height);
    min-height: 6px;
    border-radius: 999px 999px 0 0;
    background: linear-gradient(180deg, #38bdf8, #2563eb);
    box-shadow: 0 8px 18px rgba(37, 99, 235, 0.24);
}

.clean-chart-item.is-zero .clean-chart-bar {
    background: #cbd5e1;
    box-shadow: none;
    opacity: 0.65;
}

.clean-chart-item.has-value .clean-chart-value {
    color: #0f172a;
}

.clean-chart-label {
    color: #475569;
    font-size: 13px;
    font-weight: 750;
}

@media (max-width: 800px) {
    .clean-bar-chart {
        overflow-x: auto;
        grid-template-columns: repeat(7, minmax(80px, 1fr));
    }
}
/* ===== SIMPLE COLUMN REVENUE CHART ===== */

.simple-column-chart {
    height: 260px;
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 14px;
    align-items: end;
    padding: 14px 6px 4px;
}

.simple-column {
    height: 100%;
    min-width: 0;
    display: grid;
    grid-template-rows: 24px 1fr 24px;
    gap: 8px;
    text-align: center;
}

.simple-column-value {
    color: #334155;
    font-size: 13px;
    font-weight: 850;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.simple-column-track {
    height: 100%;
    min-height: 160px;
    display: flex;
    align-items: end;
    justify-content: center;
    padding: 0 6px;
    border-radius: 16px;
    background: linear-gradient(180deg, #f8fbff, #eff6ff);
    border: 1px solid #dbeafe;
}

.simple-column-bar {
    width: 100%;
    max-width: 52px;
    min-height: 6px;
    border-radius: 12px 12px 4px 4px;
    background: linear-gradient(180deg, #38bdf8, #2563eb);
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.25);
    transition: 0.2s ease;
}

.simple-column.has-value:hover .simple-column-bar {
    transform: translateY(-3px);
    box-shadow: 0 14px 26px rgba(37, 99, 235, 0.32);
}

.simple-column.is-zero .simple-column-value {
    color: #94a3b8;
}

.simple-column.is-zero .simple-column-bar {
    background: #cbd5e1;
    box-shadow: none;
    opacity: 0.7;
}

.simple-column-label {
    color: #475569;
    font-size: 13px;
    font-weight: 850;
}

@media (max-width: 800px) {
    .simple-column-chart {
        overflow-x: auto;
        grid-template-columns: repeat(7, minmax(82px, 1fr));
    }
}
</style>

<div class="hero-panel compact-hero" id="dashboardHero">
    <div class="hero-content">
        <span class="eyebrow">Tổng quan quản lý</span>
        <h2>Theo dõi doanh thu, tồn kho, giao dịch và hoạt động nhân viên.</h2>
        <p>Dashboard hỗ trợ quản lý nhìn nhanh xu hướng kinh doanh và các cảnh báo cần xử lý trong ngày.</p>
    </div>

    <div class="hero-actions">
        <div class="dashboard-toolbar">
            <span class="notification-pill">🔔 <?= $notificationCount ?> cảnh báo</span>
        </div>

        <?php if (has_role('admin','cashier')): ?>
            <a class="btn primary" href="<?= e(url('sales.php')) ?>">Tạo đơn bán hàng</a>
        <?php endif; ?>

        <?php if (has_role('admin','warehouse')): ?>
            <a class="btn secondary" href="<?= e(url('stock_import.php')) ?>">Nhập hàng</a>
        <?php endif; ?>
    </div>
</div>

<div class="stats-grid manager-stats-grid">
    <article class="stat-card">
        <span>Doanh thu hôm nay</span>
        <strong><?= money($todayRevenue) ?></strong>
        <small class="trend-badge <?= e($revenueCompare['class']) ?>"><?= e($revenueCompare['text']) ?></small>
    </article>

    <article class="stat-card">
        <span>Hóa đơn hôm nay</span>
        <strong><?= $todayInvoices ?></strong>
        <small class="trend-badge <?= e($invoiceCompare['class']) ?>"><?= e($invoiceCompare['text']) ?></small>
    </article>

    <article class="stat-card">
        <span>Doanh thu tháng này</span>
        <strong><?= money($monthRevenue) ?></strong>
        <small>Lũy kế hóa đơn đã thanh toán</small>
    </article>

    <article class="stat-card">
        <span>Sản phẩm đang bán</span>
        <strong><?= $productCount ?></strong>
        <small>Danh mục hiện có</small>
    </article>

    <article class="stat-card warning">
        <span>Sắp hết hàng</span>
        <strong><?= $lowStockCount ?></strong>
        <small>Cần xử lý nhập kho</small>
    </article>

    <article class="stat-card">
        <span>Khách thành viên</span>
        <strong><?= $customerCount ?></strong>
        <small>Phục vụ tích điểm</small>
    </article>
</div>

<div class="dashboard-insight-grid">
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Xu hướng doanh thu 7 ngày</h2>
                <p>Biểu đồ giúp quản lý nhìn nhanh ngày nào bán tốt hoặc giảm doanh thu.</p>
            </div>
            <a class="btn secondary" href="<?= e(url('statistics.php')) ?>">Xem báo cáo</a>
        </div>

        <div class="simple-column-chart">
            <?php foreach ($chartData as $item): ?>
                <?php
                    $revenue = (float) $item['revenue'];
                    $barHeight = $revenue > 0
                        ? max(16, (int) round(($revenue / $maxChartRevenue) * 100))
                        : 4;
                ?>

                <div class="simple-column <?= $revenue > 0 ? 'has-value' : 'is-zero' ?>" title="<?= e($item['label'] . ' - ' . money($revenue)) ?>">
                    <div class="simple-column-value"><?= money($revenue) ?></div>

                    <div class="simple-column-track">
                        <div class="simple-column-bar" style="height: <?= $barHeight ?>%;"></div>
                    </div>

                    <div class="simple-column-label"><?= e($item['label']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Thông báo quản lý</h2>
                <p>Tổng hợp nhanh các vấn đề cần chú ý.</p>
            </div>
        </div>

        <div class="notification-list">
            <a class="notification-item" href="<?= e(url('inventory.php')) ?>">
                <div>
                    <strong>⚠️ <?= $lowStockCount ?> sản phẩm sắp hết</strong>
                    <span>Cần kiểm tra tồn kho và nhập hàng kịp thời</span>
                </div>
                <span>›</span>
            </a>

            <a class="notification-item" href="<?= e(url('invoices.php')) ?>">
                <div>
                    <strong>↩️ <?= $canceledToday ?> hóa đơn hủy hôm nay</strong>
                    <span>Theo dõi giao dịch bất thường trong ngày</span>
                </div>
                <span>›</span>
            </a>

            <?php if ($isAdmin): ?>
                <a class="notification-item" href="<?= e(url('employees.php')) ?>">
                    <div>
                        <strong>👥 Theo dõi nhân viên</strong>
                        <span>Xem phân quyền và hiệu suất bán hàng</span>
                    </div>
                    <span>›</span>
                </a>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="dashboard-main-grid">
    <section class="panel priority-panel">
        <div class="panel-heading">
            <div>
                <h2>Cảnh báo tồn kho</h2>
                <p>Ưu tiên xử lý các sản phẩm đã chạm mức cảnh báo.</p>
            </div>
            <a class="btn secondary" href="<?= e(url('inventory.php')) ?>">Kiểm tra kho</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Sản phẩm</th>
                        <th class="right">Tồn</th>
                        <th class="right">Cảnh báo</th>
                        <th class="right">Thao tác</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$lowStock): ?>
                        <tr>
                            <td colspan="5" class="empty compact-empty">Không có sản phẩm sắp hết.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($lowStock as $row): ?>
                        <tr>
                            <td><strong><?= e($row['code']) ?></strong></td>
                            <td><?= e($row['name']) ?></td>
                            <td class="right">
                                <span class="stock low">
                                    <?= (int) $row['stock'] ?> <?= e($row['unit']) ?>
                                </span>
                            </td>
                            <td class="right"><?= (int) $row['min_stock'] ?></td>
                            <td class="right">
                                <?php if (has_role('admin','warehouse')): ?>
                                    <a class="btn small secondary quick-stock-btn" href="<?= e(url('stock_import.php')) ?>">Nhập hàng</a>
                                <?php else: ?>
                                    <span class="muted">Cần xử lý</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel recent-panel">
        <div class="panel-heading">
            <div>
                <h2>Giao dịch gần đây</h2>
                <p>Các hóa đơn mới nhất</p>
            </div>
            <a class="btn secondary" href="<?= e(url('invoices.php')) ?>">Xem hóa đơn</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Khách hàng</th>
                        <th>Trạng thái</th>
                        <th class="right">Tổng tiền</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$recentInvoices): ?>
                        <tr>
                            <td colspan="4" class="empty compact-empty">Chưa có giao dịch.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($recentInvoices as $invoice): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('invoices.php?id=' . $invoice['id'])) ?>">
                                    <strong><?= e($invoice['invoice_code']) ?></strong>
                                </a>
                                <small class="block muted">
                                    <?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?>
                                </small>
                            </td>
                            <td><?= e($invoice['customer_name'] ?: 'Khách lẻ') ?></td>
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
    </section>
</div>

<?php if ($isAdmin && $staffPerformance): ?>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Hiệu suất nhân viên 7 ngày</h2>
                <p>Theo dõi nhanh doanh thu và số hóa đơn theo từng nhân viên.</p>
            </div>
            <a class="btn secondary" href="<?= e(url('employees.php')) ?>">Xem nhân viên</a>
        </div>

        <div class="staff-grid">
            <?php foreach ($staffPerformance as $staff): ?>
                <div class="staff-row">
                    <div>
                        <strong><?= e($staff['full_name']) ?></strong>
                        <small><?= (int) $staff['invoice_count'] ?> hóa đơn hoàn tất</small>
                    </div>
                    <strong><?= money($staff['revenue']) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($recentLogs): ?>
    <section class="panel activity-compact dashboard-log-panel">
        <div class="panel-heading">
            <div>
                <h2>Nhật ký hoạt động</h2>
                <p>Giám sát ai đã thao tác gì và vào thời điểm nào.</p>
            </div>
        </div>

        <div class="activity-list compact">
            <?php foreach ($recentLogs as $log): ?>
                <div>
                    <strong><?= e($log['full_name'] ?: 'Hệ thống') ?></strong>
                    <span><?= e($log['description']) ?></span>
                    <small><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php render_footer(); ?>
