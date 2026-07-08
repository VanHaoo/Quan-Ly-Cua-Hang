<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$user = current_user();
$userId = (int) ($user['id'] ?? 0);
$role = (string) ($user['role'] ?? '');
$isAdmin = is_admin();
$isWarehouseOnly = $role === 'warehouse';

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

function dashboard_money_short(float $value): string
{
    return money($value);
}

/* =========================
   DASHBOARD RIÊNG CHO NHÂN VIÊN KHO
   ========================= */
if ($isWarehouseOnly) {
    $productCount = (int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM products WHERE status=1");
    $totalStockQty = (int) dashboard_scalar($pdo, "SELECT COALESCE(SUM(stock),0) FROM products WHERE status=1");
    $lowStockCount = (int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM products WHERE status=1 AND stock<=min_stock");
    $outOfStockCount = (int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM products WHERE status=1 AND stock<=0");

    $todayImportCount = (int) dashboard_scalar(
        $pdo,
        "SELECT COUNT(*) FROM stock_imports WHERE DATE(created_at)=CURDATE()"
    );

    $yesterdayImportCount = (int) dashboard_scalar(
        $pdo,
        "SELECT COUNT(*) FROM stock_imports WHERE DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
    );

    $todayImportValue = (float) dashboard_scalar(
        $pdo,
        "SELECT COALESCE(SUM(total_amount),0) FROM stock_imports WHERE DATE(created_at)=CURDATE()"
    );

    $todayImportedQty = (int) dashboard_scalar(
        $pdo,
        "SELECT COALESCE(SUM(d.quantity),0)
         FROM stock_import_details d
         JOIN stock_imports si ON si.id=d.stock_import_id
         WHERE DATE(si.created_at)=CURDATE()"
    );

    $monthImportValue = (float) dashboard_scalar(
        $pdo,
        "SELECT COALESCE(SUM(total_amount),0)
         FROM stock_imports
         WHERE YEAR(created_at)=YEAR(CURDATE())
         AND MONTH(created_at)=MONTH(CURDATE())"
    );

    $importCompare = dashboard_compare((float) $todayImportCount, (float) $yesterdayImportCount);
    $notificationCount = $lowStockCount;

    $lowStock = $pdo
        ->query("SELECT id,code,name,stock,min_stock,unit
                 FROM products
                 WHERE status=1 AND stock<=min_stock
                 ORDER BY stock ASC, name ASC
                 LIMIT 8")
        ->fetchAll();

    $recentImports = $pdo
        ->query("SELECT si.*, u.full_name
                 FROM stock_imports si
                 LEFT JOIN users u ON u.id=si.user_id
                 ORDER BY si.id DESC
                 LIMIT 8")
        ->fetchAll();

    $recentImportItems = $pdo
        ->query("SELECT d.product_code,d.product_name,d.quantity,d.cost_price,d.subtotal,si.import_code,si.created_at
                 FROM stock_import_details d
                 JOIN stock_imports si ON si.id=d.stock_import_id
                 ORDER BY d.id DESC
                 LIMIT 8")
        ->fetchAll();

    $chartRows = $pdo
        ->query("SELECT DATE(si.created_at) AS import_date,
                        COALESCE(SUM(d.quantity),0) AS total_qty,
                        COALESCE(SUM(d.subtotal),0) AS total_amount
                 FROM stock_imports si
                 LEFT JOIN stock_import_details d ON d.stock_import_id=si.id
                 WHERE si.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                 GROUP BY DATE(si.created_at)
                 ORDER BY import_date ASC")
        ->fetchAll();

    $chartMap = [];
    foreach ($chartRows as $row) {
        $chartMap[(string) $row['import_date']] = [
            'qty' => (int) $row['total_qty'],
            'amount' => (float) $row['total_amount'],
        ];
    }

    $chartData = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $chartData[] = [
            'date' => $date,
            'label' => date('d/m', strtotime($date)),
            'qty' => $chartMap[$date]['qty'] ?? 0,
            'amount' => $chartMap[$date]['amount'] ?? 0,
        ];
    }

    $maxChartQty = max(1, max(array_column($chartData, 'qty')));

    render_header('Bảng điều khiển kho', 'dashboard');
    ?>

    <style>
        .warehouse-hero {
            min-height: 150px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 26px 30px;
            border-radius: 24px;
            background:
                radial-gradient(circle at 92% 8%, rgba(56,189,248,0.18), transparent 28%),
                linear-gradient(135deg, rgba(255,255,255,0.98), rgba(239,246,255,0.94));
        }

        .warehouse-hero h2 {
            max-width: 780px;
            margin: 8px 0;
            color: #0f172a;
            font-size: 28px;
            line-height: 1.2;
        }

        .warehouse-hero p {
            max-width: 760px;
            margin: 0;
            color: #64748b;
            line-height: 1.55;
        }

        .warehouse-actions {
            min-width: 190px;
            display: grid;
            gap: 10px;
        }

        .warehouse-stats-grid {
            grid-template-columns: repeat(6, minmax(135px, 1fr));
        }

        .warehouse-main-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(340px, 0.85fr);
            gap: 18px;
            align-items: start;
            margin-bottom: 18px;
        }

        .warehouse-main-grid .panel {
            margin-bottom: 0;
        }

        .warehouse-chart {
            height: 250px;
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 14px;
            align-items: end;
            padding: 14px 4px 4px;
        }

        .warehouse-chart-item {
            height: 100%;
            display: grid;
            grid-template-rows: 25px 1fr 24px;
            gap: 8px;
            text-align: center;
            min-width: 0;
        }

        .warehouse-chart-value {
            color: #334155;
            font-size: 13px;
            font-weight: 850;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .warehouse-chart-track {
            height: 100%;
            min-height: 150px;
            display: flex;
            align-items: end;
            justify-content: center;
            padding: 0 6px;
            border-radius: 16px;
            background: linear-gradient(180deg, #f8fbff, #eff6ff);
            border: 1px solid #dbeafe;
        }

        .warehouse-chart-bar {
            width: 100%;
            max-width: 52px;
            min-height: 6px;
            border-radius: 12px 12px 4px 4px;
            background: linear-gradient(180deg, #38bdf8, #2563eb);
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.25);
        }

        .warehouse-chart-item.is-zero .warehouse-chart-bar {
            background: #cbd5e1;
            box-shadow: none;
            opacity: 0.7;
        }

        .warehouse-chart-label {
            color: #475569;
            font-size: 13px;
            font-weight: 850;
        }

        .warehouse-notice-list {
            display: grid;
            gap: 10px;
        }

        .warehouse-notice {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 13px;
            border-radius: 16px;
            background: #f8fbff;
            border: 1px solid #e2e8f0;
        }

        .warehouse-notice strong,
        .warehouse-notice span {
            display: block;
        }

        .warehouse-notice span {
            margin-top: 3px;
            color: #64748b;
            font-size: 13px;
        }

        .warehouse-notice.warning {
            background: #fff7ed;
            border-color: #fed7aa;
        }

        .import-code-pill {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: 850;
            font-size: 12px;
        }

        @media (max-width: 1250px) {
            .warehouse-stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .warehouse-main-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 800px) {
            .warehouse-hero {
                display: block;
                padding: 22px;
            }

            .warehouse-actions {
                margin-top: 16px;
            }

            .warehouse-stats-grid {
                grid-template-columns: 1fr;
            }

            .warehouse-chart {
                overflow-x: auto;
                grid-template-columns: repeat(7, minmax(82px, 1fr));
            }
        }
    </style>

    <section class="panel warehouse-hero">
        <div>
            <span class="eyebrow">Tổng quan kho</span>
            <h2>Theo dõi tồn kho, phiếu nhập và các sản phẩm cần bổ sung hàng.</h2>
            <p>Dashboard này chỉ hiển thị những thông tin phục vụ nghiệp vụ kho, tránh lẫn với doanh thu và hóa đơn của bộ phận bán hàng.</p>
        </div>

        <div class="warehouse-actions">
            <span class="notification-pill">⚠️ <?= $notificationCount ?> cảnh báo kho</span>
            <a class="btn primary" href="<?= e(url('stock_import.php')) ?>">Tạo phiếu nhập</a>
            <a class="btn secondary" href="<?= e(url('inventory.php')) ?>">Kiểm tra tồn kho</a>
        </div>
    </section>

    <div class="stats-grid manager-stats-grid warehouse-stats-grid">
        <article class="stat-card">
            <span>Tổng sản phẩm</span>
            <strong><?= $productCount ?></strong>
            <small>Sản phẩm đang theo dõi</small>
        </article>

        <article class="stat-card">
            <span>Tổng tồn kho</span>
            <strong><?= number_format($totalStockQty, 0, ',', '.') ?></strong>
            <small>Số lượng hiện có</small>
        </article>

        <article class="stat-card warning">
            <span>Sản phẩm sắp hết</span>
            <strong><?= $lowStockCount ?></strong>
            <small>Cần nhập bổ sung</small>
        </article>

        <article class="stat-card warning">
            <span>Hết hàng</span>
            <strong><?= $outOfStockCount ?></strong>
            <small>Cần xử lý gấp</small>
        </article>

        <article class="stat-card">
            <span>Phiếu nhập hôm nay</span>
            <strong><?= $todayImportCount ?></strong>
            <small class="trend-badge <?= e($importCompare['class']) ?>"><?= e($importCompare['text']) ?></small>
        </article>

        <article class="stat-card">
            <span>Hàng nhập hôm nay</span>
            <strong><?= number_format($todayImportedQty, 0, ',', '.') ?></strong>
            <small><?= money($todayImportValue) ?></small>
        </article>
    </div>

    <div class="warehouse-main-grid">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Lượng hàng nhập 7 ngày</h2>
                    <p>Theo dõi nhanh số lượng sản phẩm đã nhập kho trong tuần.</p>
                </div>
                <a class="btn secondary" href="<?= e(url('stock_import.php')) ?>">Xem phiếu nhập</a>
            </div>

            <div class="warehouse-chart">
                <?php foreach ($chartData as $item): ?>
                    <?php
                        $qty = (int) $item['qty'];
                        $barHeight = $qty > 0 ? max(16, (int) round(($qty / $maxChartQty) * 100)) : 4;
                    ?>

                    <div class="warehouse-chart-item <?= $qty > 0 ? 'has-value' : 'is-zero' ?>" title="<?= e($item['label'] . ' - ' . $qty . ' sản phẩm') ?>">
                        <div class="warehouse-chart-value"><?= $qty ?></div>
                        <div class="warehouse-chart-track">
                            <div class="warehouse-chart-bar" style="height: <?= $barHeight ?>%;"></div>
                        </div>
                        <div class="warehouse-chart-label"><?= e($item['label']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Thông báo kho</h2>
                    <p>Các việc cần ưu tiên xử lý trong ngày.</p>
                </div>
            </div>

            <div class="warehouse-notice-list">
                <a class="warehouse-notice warning" href="<?= e(url('inventory.php')) ?>">
                    <div>
                        <strong>⚠️ <?= $lowStockCount ?> sản phẩm sắp hết</strong>
                        <span>Kiểm tra tồn kho và lập phiếu nhập kịp thời.</span>
                    </div>
                    <span>›</span>
                </a>

                <a class="warehouse-notice warning" href="<?= e(url('inventory.php')) ?>">
                    <div>
                        <strong>⛔ <?= $outOfStockCount ?> sản phẩm hết hàng</strong>
                        <span>Cần ưu tiên nhập lại để tránh gián đoạn bán hàng.</span>
                    </div>
                    <span>›</span>
                </a>

                <a class="warehouse-notice" href="<?= e(url('stock_import.php')) ?>">
                    <div>
                        <strong>📥 <?= $todayImportCount ?> phiếu nhập hôm nay</strong>
                        <span>Tổng giá trị nhập: <?= money($todayImportValue) ?></span>
                    </div>
                    <span>›</span>
                </a>

                <div class="warehouse-notice">
                    <div>
                        <strong>📦 Giá trị nhập tháng này</strong>
                        <span><?= money($monthImportValue) ?></span>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <div class="warehouse-main-grid">
        <section class="panel priority-panel">
            <div class="panel-heading">
                <div>
                    <h2>Cảnh báo tồn kho</h2>
                    <p>Danh sách sản phẩm đã chạm mức cảnh báo hoặc đã hết hàng.</p>
                </div>
                <a class="btn secondary" href="<?= e(url('stock_import.php')) ?>">Nhập hàng</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Mã</th>
                            <th>Sản phẩm</th>
                            <th class="right">Tồn</th>
                            <th class="right">Mức cảnh báo</th>
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
                                    <span class="stock <?= (int) $row['stock'] <= 0 ? 'low' : '' ?>">
                                        <?= (int) $row['stock'] ?> <?= e($row['unit']) ?>
                                    </span>
                                </td>
                                <td class="right"><?= (int) $row['min_stock'] ?></td>
                                <td class="right">
                                    <a class="btn small secondary quick-stock-btn" href="<?= e(url('stock_import.php')) ?>">Nhập hàng</a>
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
                    <h2>Phiếu nhập gần đây</h2>
                    <p>Các lần nhập hàng mới nhất của kho.</p>
                </div>
                <a class="btn secondary" href="<?= e(url('stock_import.php')) ?>">Xem tất cả</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Mã phiếu</th>
                            <th>Nhà cung cấp</th>
                            <th>Người nhập</th>
                            <th class="right">Tổng tiền</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$recentImports): ?>
                            <tr>
                                <td colspan="4" class="empty compact-empty">Chưa có phiếu nhập.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($recentImports as $import): ?>
                            <tr>
                                <td>
                                    <a class="import-code-pill" href="<?= e(url('stock_import.php?id=' . $import['id'])) ?>">
                                        <?= e($import['import_code']) ?>
                                    </a>
                                    <small class="block muted"><?= date('d/m/Y H:i', strtotime($import['created_at'])) ?></small>
                                </td>
                                <td><?= e($import['supplier'] ?: 'Không ghi') ?></td>
                                <td><?= e($import['full_name'] ?: 'Hệ thống') ?></td>
                                <td class="right"><?= money((float) $import['total_amount']) ?></td>
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
                <h2>Sản phẩm vừa nhập</h2>
                <p>Theo dõi nhanh các mặt hàng vừa được bổ sung vào kho.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Mã phiếu</th>
                        <th>Sản phẩm</th>
                        <th class="right">Số lượng</th>
                        <th class="right">Giá nhập</th>
                        <th class="right">Thành tiền</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$recentImportItems): ?>
                        <tr>
                            <td colspan="5" class="empty compact-empty">Chưa có sản phẩm nhập gần đây.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($recentImportItems as $item): ?>
                        <tr>
                            <td>
                                <span class="import-code-pill"><?= e($item['import_code']) ?></span>
                                <small class="block muted"><?= date('d/m/Y H:i', strtotime($item['created_at'])) ?></small>
                            </td>
                            <td>
                                <strong><?= e($item['product_name']) ?></strong>
                                <small class="block muted"><?= e($item['product_code']) ?></small>
                            </td>
                            <td class="right"><?= (int) $item['quantity'] ?></td>
                            <td class="right"><?= money((float) $item['cost_price']) ?></td>
                            <td class="right"><?= money((float) $item['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php
    render_footer();
    exit;
}

/* =========================
   DASHBOARD CHO ADMIN / THU NGÂN
   ========================= */

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

$productCount = (int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM products WHERE status=1');
$lowStockCount = (int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM products WHERE status=1 AND stock<=min_stock');
$customerCount = (int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM customers WHERE status=1');

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

    .dashboard-insight-grid,
    .dashboard-main-grid {
        display: grid;
        gap: 18px;
        align-items: stretch;
        margin-bottom: 18px;
    }

    .dashboard-insight-grid {
        grid-template-columns: minmax(0, 1.6fr) minmax(300px, 0.8fr);
    }

    .dashboard-main-grid {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .dashboard-insight-grid .panel,
    .dashboard-main-grid .panel {
        margin-bottom: 0;
    }

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

    .notification-item span {
        color: #64748b;
        font-size: 13px;
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

        .simple-column-chart {
            overflow-x: auto;
            grid-template-columns: repeat(7, minmax(82px, 1fr));
        }
    }
</style>

<div class="hero-panel compact-hero">
    <div class="hero-content">
        <span class="eyebrow"><?= $isAdmin ? 'Tổng quan quản lý' : 'Tổng quan thu ngân' ?></span>
        <h2><?= $isAdmin ? 'Theo dõi doanh thu, tồn kho, giao dịch và hoạt động nhân viên.' : 'Theo dõi nhanh giao dịch bán hàng và hóa đơn trong ca làm việc.' ?></h2>
        <p><?= $isAdmin ? 'Dashboard hỗ trợ quản lý nhìn nhanh xu hướng kinh doanh và các cảnh báo cần xử lý trong ngày.' : 'Dashboard tập trung vào bán hàng, hóa đơn và các giao dịch do thu ngân thực hiện.' ?></p>
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
                <p>Biểu đồ giúp nhìn nhanh ngày nào bán tốt hoặc giảm doanh thu.</p>
            </div>
            <?php if ($isAdmin): ?>
                <a class="btn secondary" href="<?= e(url('statistics.php')) ?>">Xem báo cáo</a>
            <?php endif; ?>
        </div>

        <div class="simple-column-chart">
            <?php foreach ($chartData as $item): ?>
                <?php
                    $revenue = (float) $item['revenue'];
                    $barHeight = $revenue > 0 ? max(16, (int) round(($revenue / $maxChartRevenue) * 100)) : 4;
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
                <h2>Thông báo</h2>
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
