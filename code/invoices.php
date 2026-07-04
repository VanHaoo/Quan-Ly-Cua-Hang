<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();

function invoice_status_label(string $status): string
{
    return [
        'paid' => 'Đã thanh toán',
        'cancelled' => 'Đã hủy',
        'pending' => 'Chờ xử lý',
    ][$status] ?? 'Chờ xử lý';
}

function invoice_status_badge(string $status): string
{
    return in_array($status, ['paid', 'cancelled', 'pending'], true) ? $status : 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    require_admin();
    verify_csrf();

    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if ($reason === '') {
        flash('error', 'Vui lòng nhập lý do hủy hóa đơn.');
        redirect('invoices.php?id=' . $invoiceId);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id=? FOR UPDATE');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();

        if (!$invoice || $invoice['status'] !== 'paid') {
            throw new RuntimeException('Hóa đơn không tồn tại hoặc đã bị hủy.');
        }

        if (!empty($invoice['customer_id'])) {
            $rewardStmt = $pdo->prepare('SELECT id,status,points_cost FROM vouchers WHERE source_invoice_id=? FOR UPDATE');
            $rewardStmt->execute([$invoiceId]);

            foreach ($rewardStmt->fetchAll() as $rv) {
                if ($rv['status'] === 'used') {
                    throw new RuntimeException('Không thể hủy vì voucher thưởng từ hóa đơn này đã được sử dụng.');
                }
            }
        }

        $details = $pdo->prepare('SELECT product_id,quantity FROM invoice_details WHERE invoice_id=?');
        $details->execute([$invoiceId]);

        $restore = $pdo->prepare('UPDATE products SET stock=stock+?, updated_at=NOW() WHERE id=?');
        foreach ($details->fetchAll() as $d) {
            $restore->execute([(int) $d['quantity'], (int) $d['product_id']]);
        }

        if (!empty($invoice['voucher_id'])) {
            $pdo->prepare("UPDATE vouchers SET status=IF(expires_at>=NOW(),'available','expired'), used_at=NULL, used_invoice_id=NULL WHERE id=? AND used_invoice_id=?")
                ->execute([(int) $invoice['voucher_id'], $invoiceId]);
        }

        if (!empty($invoice['customer_id'])) {
            $rewardStmt = $pdo->prepare("SELECT COALESCE(SUM(points_cost),0) FROM vouchers WHERE source_invoice_id=? AND status<>'used'");
            $rewardStmt->execute([$invoiceId]);
            $returnedPoints = (int) $rewardStmt->fetchColumn();

            $pdo->prepare("UPDATE vouchers SET status='cancelled' WHERE source_invoice_id=? AND status<>'used'")
                ->execute([$invoiceId]);

            $pdo->prepare('UPDATE customers SET points=GREATEST(0,points-?+?), total_spent=GREATEST(0,total_spent-?) WHERE id=?')
                ->execute([(int) $invoice['points_earned'], $returnedPoints, (float) $invoice['total_amount'], (int) $invoice['customer_id']]);
        }

        $pdo->prepare("UPDATE invoices SET status='cancelled', cancelled_at=NOW(), cancelled_by=?, cancel_reason=? WHERE id=?")
            ->execute([(int) current_user()['id'], $reason, $invoiceId]);

        $pdo->commit();

        log_activity('invoice_cancel', 'Hủy hóa đơn ' . $invoice['invoice_code'] . '. Lý do: ' . $reason);
        flash('success', 'Đã hủy hóa đơn, hoàn trả tồn kho và điều chỉnh điểm/voucher.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Không thể hủy hóa đơn.');
    }

    redirect('invoices.php?id=' . $invoiceId);
}

$id = (int) ($_GET['id'] ?? 0);

if ($id > 0) {
    $sql = 'SELECT i.*,u.full_name,c.full_name AS cancelled_name,v.code AS voucher_code,v.name AS voucher_name
            FROM invoices i
            JOIN users u ON u.id=i.user_id
            LEFT JOIN users c ON c.id=i.cancelled_by
            LEFT JOIN vouchers v ON v.id=i.voucher_id
            WHERE i.id=?';

    $params = [$id];

    if (!is_admin()) {
        $sql .= ' AND i.user_id=?';
        $params[] = (int) current_user()['id'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        flash('error', 'Không tìm thấy hóa đơn.');
        redirect('invoices.php');
    }

    $detailsStmt = $pdo->prepare('SELECT * FROM invoice_details WHERE invoice_id=? ORDER BY id');
    $detailsStmt->execute([$id]);
    $details = $detailsStmt->fetchAll();

    $methodName = [
        'cash' => 'Tiền mặt',
        'transfer' => 'Chuyển khoản/QR',
        'qr' => 'Chuyển khoản/QR',
    ][$invoice['payment_method']] ?? 'Tiền mặt';

    render_header('Chi tiết hóa đơn', 'invoices');
    ?>
    <div class="invoice-paper">
        <div class="invoice-head">
            <div>
                <h2>HÓA ĐƠN BÁN HÀNG</h2>
                <p>Mã <?= e($invoice['invoice_code']) ?></p>
            </div>

            <span class="status <?= e(invoice_status_badge((string) $invoice['status'])) ?>">
                <?= e(invoice_status_label((string) $invoice['status'])) ?>
            </span>
        </div>

        <div class="invoice-info">
            <p><strong>Khách hàng:</strong> <?= e($invoice['customer_name'] ?: 'Khách lẻ') ?></p>
            <p><strong>Số điện thoại:</strong> <?= e($invoice['customer_phone'] ?: 'Không có') ?></p>
            <p><strong>Nhân viên:</strong> <?= e($invoice['full_name']) ?></p>
            <p><strong>Thời gian:</strong> <?= date('d/m/Y H:i:s', strtotime($invoice['created_at'])) ?></p>
            <p><strong>Thanh toán:</strong> <?= e($methodName) ?></p>
            <p><strong>Điểm cộng:</strong> <?= (int) $invoice['points_earned'] ?> điểm</p>
            <p><strong>Voucher:</strong> <?= e($invoice['voucher_code'] ?: 'Không sử dụng') ?></p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sản phẩm</th>
                        <th class="right">Đơn giá</th>
                        <th class="right">Số lượng</th>
                        <th class="right">Thành tiền</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($details as $d): ?>
                        <tr>
                            <td>
                                <?= e($d['product_name']) ?>
                                <small class="block muted"><?= e($d['product_code']) ?></small>
                            </td>
                            <td class="right"><?= money($d['price']) ?></td>
                            <td class="right"><?= (int) $d['quantity'] ?></td>
                            <td class="right"><?= money($d['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="invoice-summary">
            <p><span>Tạm tính</span><strong><?= money($invoice['subtotal_amount']) ?></strong></p>

            <?php if ((float) $invoice['discount_amount'] > 0): ?>
                <p><span>Giảm giá</span><strong>- <?= money($invoice['discount_amount']) ?></strong></p>
            <?php endif; ?>

            <p class="final-total"><span>Khách cần trả</span><strong><?= money($invoice['total_amount']) ?></strong></p>
            <p><span>Tiền khách đưa</span><strong><?= money($invoice['customer_money']) ?></strong></p>
            <p><span>Tiền thừa</span><strong><?= money($invoice['change_money']) ?></strong></p>
        </div>

        <?php if ($invoice['status'] === 'cancelled'): ?>
            <div class="cancel-box">
                <strong>Hóa đơn đã hủy</strong>
                <p>Lý do: <?= e($invoice['cancel_reason']) ?></p>
                <small><?= e($invoice['cancelled_name']) ?> · <?= date('d/m/Y H:i', strtotime($invoice['cancelled_at'])) ?></small>
            </div>
        <?php endif; ?>

        <div class="invoice-actions">
            <a class="btn ghost" href="invoices.php">Quay lại</a>
            <button class="btn secondary" onclick="window.print()">In hóa đơn</button>
        </div>

        <?php if (is_admin() && $invoice['status'] === 'paid'): ?>
            <form method="post" class="cancel-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="invoice_id" value="<?= $id ?>">

                <label>
                    Lý do hủy
                    <input name="reason" required placeholder="Nhập lý do hủy hóa đơn">
                </label>

                <button class="btn danger" data-confirm="Hủy hóa đơn, hoàn trả hàng và điều chỉnh điểm/voucher?">Hủy hóa đơn</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    render_footer();
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');
$date = trim((string) ($_GET['date'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if (!is_admin()) {
    $where[] = 'i.user_id=:user_id';
    $params[':user_id'] = (int) current_user()['id'];
}

if ($q !== '') {
    $where[] = '(i.invoice_code LIKE :q OR i.customer_name LIKE :q OR i.customer_phone LIKE :q OR u.full_name LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$validStatuses = ['paid', 'cancelled', 'pending'];
if (in_array($status, $validStatuses, true)) {
    $where[] = 'i.status=:status';
    $params[':status'] = $status;
} else {
    $status = 'all';
}

if ($date !== '') {
    $where[] = 'DATE(i.created_at)=:date';
    $params[':date'] = $date;
}

$whereSql = implode(' AND ', $where);

$summarySql = "
    SELECT
        COUNT(*) AS total_invoices,
        COALESCE(SUM(CASE WHEN i.status='paid' THEN i.total_amount ELSE 0 END),0) AS total_revenue,
        COALESCE(SUM(CASE WHEN i.status='cancelled' THEN 1 ELSE 0 END),0) AS cancelled_count,
        COALESCE(SUM(CASE WHEN i.status='pending' THEN 1 ELSE 0 END),0) AS pending_count
    FROM invoices i
    JOIN users u ON u.id=i.user_id
    WHERE {$whereSql}
";

$summaryStmt = $pdo->prepare($summarySql);
foreach ($params as $key => $value) {
    $summaryStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$summaryStmt->execute();
$summary = $summaryStmt->fetch() ?: [
    'total_invoices' => 0,
    'total_revenue' => 0,
    'cancelled_count' => 0,
    'pending_count' => 0,
];

$totalInvoices = (int) $summary['total_invoices'];
$totalPages = max(1, (int) ceil($totalInvoices / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listSql = "
    SELECT i.*,u.full_name
    FROM invoices i
    JOIN users u ON u.id=i.user_id
    WHERE {$whereSql}
    ORDER BY i.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$invoices = $stmt->fetchAll();

$queryBase = [
    'q' => $q,
    'date' => $date,
    'status' => $status,
];

function invoice_page_url(array $base, int $page): string
{
    $query = array_filter($base, fn($value) => $value !== '' && $value !== 'all');
    $query['page'] = $page;
    return 'invoices.php?' . http_build_query($query);
}

render_header('Quản lý hóa đơn', 'invoices');
?>

<div class="stats-grid invoice-summary-grid">
    <article class="stat-card">
        <span>Tổng số hóa đơn</span>
        <strong><?= $totalInvoices ?></strong>
        <small>Theo bộ lọc hiện tại</small>
    </article>

    <article class="stat-card">
        <span>Tổng doanh thu</span>
        <strong><?= money((float) $summary['total_revenue']) ?></strong>
        <small>Chỉ tính hóa đơn đã thanh toán</small>
    </article>

    <article class="stat-card warning">
        <span>Hóa đơn đã hủy</span>
        <strong><?= (int) $summary['cancelled_count'] ?></strong>
        <small>Cần theo dõi lý do hủy</small>
    </article>

    <article class="stat-card">
        <span>Chờ xử lý</span>
        <strong><?= (int) $summary['pending_count'] ?></strong>
        <small>Hóa đơn chưa hoàn tất</small>
    </article>
</div>

<section class="panel invoice-list-panel">
    <div class="panel-heading responsive invoice-heading">
        <div>
            <h2>Danh sách hóa đơn</h2>
            <p><?= $totalInvoices ?> giao dịch phù hợp · Trang <?= $page ?>/<?= $totalPages ?></p>
        </div>

        <form method="get" class="filter-form invoice-filter-form">
            <label>
                Tìm kiếm
                <input name="q" value="<?= e($q) ?>" placeholder="Mã hóa đơn, tên hoặc SĐT">
            </label>

            <label>
                Ngày lập
                <input type="date" name="date" value="<?= e($date) ?>">
            </label>

            <label>
                Trạng thái
                <select name="status">
                    <option value="all">Tất cả</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Đã thanh toán</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Đã hủy</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Chờ xử lý</option>
                </select>
            </label>

            <button class="btn primary">Lọc</button>
        </form>
    </div>

    <div class="invoice-table-toolbar">
        <div>
            <strong>Hóa đơn</strong>
            <small id="selected-invoice-count">Chưa chọn dòng nào</small>
        </div>

        <div class="table-actions">
            <button type="button" class="btn secondary" id="export-invoices">Xuất Excel</button>
            <button type="button" class="btn outline" id="print-invoices">In danh sách</button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="invoice-table" id="invoice-table">
            <thead>
                <tr>
                    <th class="select-col"><input type="checkbox" id="select-all-invoices" aria-label="Chọn tất cả hóa đơn"></th>
                    <th>Mã hóa đơn</th>
                    <th>Khách hàng</th>
                    <th>Nhân viên</th>
                    <th>Thời gian</th>
                    <th>Trạng thái</th>
                    <th class="right">Tổng tiền</th>
                    <th class="action-col"></th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$invoices): ?>
                    <tr>
                        <td colspan="8" class="empty">Không có hóa đơn.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td class="select-col">
                            <input type="checkbox" class="invoice-row-check" value="<?= (int) $invoice['id'] ?>" aria-label="Chọn hóa đơn <?= e($invoice['invoice_code']) ?>">
                        </td>
                        <td><strong><?= e($invoice['invoice_code']) ?></strong></td>
                        <td><?= e($invoice['customer_name'] ?: 'Khách lẻ') ?></td>
                        <td><?= e($invoice['full_name']) ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></td>
                        <td>
                            <span class="status <?= e(invoice_status_badge((string) $invoice['status'])) ?>">
                                <?= e(invoice_status_label((string) $invoice['status'])) ?>
                            </span>
                        </td>
                        <td class="right"><?= money($invoice['total_amount']) ?></td>
                        <td class="action-col">
                            <a class="btn small outline invoice-detail-btn" href="?id=<?= (int) $invoice['id'] ?>">Chi tiết</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalInvoices > $perPage): ?>
        <nav class="pagination" aria-label="Phân trang hóa đơn">
            <?php if ($page > 1): ?>
                <a href="<?= e(invoice_page_url($queryBase, $page - 1)) ?>">« Trước</a>
            <?php else: ?>
                <span class="disabled">« Trước</span>
            <?php endif; ?>

            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="<?= $p === $page ? 'active' : '' ?>" href="<?= e(invoice_page_url($queryBase, $p)) ?>"><?= $p ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= e(invoice_page_url($queryBase, $page + 1)) ?>">Sau »</a>
            <?php else: ?>
                <span class="disabled">Sau »</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<script>
(() => {
    const table = document.getElementById('invoice-table');
    const selectAll = document.getElementById('select-all-invoices');
    const selectedCount = document.getElementById('selected-invoice-count');
    const exportButton = document.getElementById('export-invoices');
    const printButton = document.getElementById('print-invoices');

    const getRowChecks = () => Array.from(document.querySelectorAll('.invoice-row-check'));

    function updateSelectedCount() {
        const selected = getRowChecks().filter(input => input.checked).length;
        const total = getRowChecks().length;

        if (selectedCount) {
            selectedCount.textContent = selected > 0
                ? `Đã chọn ${selected}/${total} hóa đơn`
                : 'Chưa chọn dòng nào';
        }

        if (selectAll) {
            selectAll.checked = total > 0 && selected === total;
            selectAll.indeterminate = selected > 0 && selected < total;
        }
    }

    function getExportRows() {
        const checks = getRowChecks();
        const selectedChecks = checks.filter(input => input.checked);
        const activeChecks = selectedChecks.length ? selectedChecks : checks;

        return activeChecks
            .map(input => input.closest('tr'))
            .filter(Boolean);
    }

    function buildPlainTable(rows) {
        const headers = Array.from(table.querySelectorAll('thead th'))
            .slice(1, -1)
            .map(th => `<th>${th.textContent.trim()}</th>`)
            .join('');

        const body = rows.map(row => {
            const cells = Array.from(row.children)
                .slice(1, -1)
                .map(td => `<td>${td.textContent.trim()}</td>`)
                .join('');

            return `<tr>${cells}</tr>`;
        }).join('');

        return `<table border="1" cellspacing="0" cellpadding="6"><thead><tr>${headers}</tr></thead><tbody>${body}</tbody></table>`;
    }

    selectAll?.addEventListener('change', () => {
        getRowChecks().forEach(input => {
            input.checked = selectAll.checked;
        });
        updateSelectedCount();
    });

    document.addEventListener('change', event => {
        if (event.target.classList.contains('invoice-row-check')) {
            updateSelectedCount();
        }
    });

    exportButton?.addEventListener('click', () => {
        const rows = getExportRows();

        if (!rows.length) {
            alert('Không có hóa đơn để xuất.');
            return;
        }

        const html = '\ufeff' + `
            <html>
            <head><meta charset="utf-8"></head>
            <body>${buildPlainTable(rows)}</body>
            </html>
        `;

        const blob = new Blob([html], {
            type: 'application/vnd.ms-excel;charset=utf-8;',
        });

        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'danh-sach-hoa-don.xls';
        link.click();
        URL.revokeObjectURL(link.href);
    });

    printButton?.addEventListener('click', () => {
        const rows = getExportRows();

        if (!rows.length) {
            alert('Không có hóa đơn để in.');
            return;
        }

        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>In danh sách hóa đơn</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 24px; color: #111827; }
                    h2 { margin: 0 0 16px; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { border: 1px solid #dbe7f5; padding: 9px; text-align: left; }
                    th { background: #f8fbff; }
                    .right { text-align: right; }
                </style>
            </head>
            <body>
                <h2>Danh sách hóa đơn</h2>
                ${buildPlainTable(rows)}
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    });

    updateSelectedCount();
})();
</script>

<?php render_footer(); ?>
