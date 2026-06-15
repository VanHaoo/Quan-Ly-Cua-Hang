<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();

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
            $rewardVouchers = $rewardStmt->fetchAll();

            foreach ($rewardVouchers as $rewardVoucher) {
                if ($rewardVoucher['status'] === 'used') {
                    throw new RuntimeException('Không thể hủy vì voucher thưởng từ hóa đơn này đã được sử dụng.');
                }
            }
        }

        $details = $pdo->prepare('SELECT product_id, quantity FROM invoice_details WHERE invoice_id=?');
        $details->execute([$invoiceId]);
        $restore = $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?');
        foreach ($details->fetchAll() as $detail) {
            $restore->execute([(int) $detail['quantity'], (int) $detail['product_id']]);
        }

        if (!empty($invoice['voucher_id'])) {
            $restoreVoucher = $pdo->prepare("UPDATE vouchers
                SET status=IF(expires_at>=NOW(),'available','expired'),used_at=NULL,used_invoice_id=NULL
                WHERE id=? AND used_invoice_id=?");
            $restoreVoucher->execute([(int) $invoice['voucher_id'], $invoiceId]);
        }

        if (!empty($invoice['customer_id'])) {
            $rewardStmt = $pdo->prepare('SELECT COALESCE(SUM(points_cost),0) FROM vouchers WHERE source_invoice_id=? AND status<>\'used\'');
            $rewardStmt->execute([$invoiceId]);
            $returnedPoints = (int) $rewardStmt->fetchColumn();

            $cancelRewards = $pdo->prepare("UPDATE vouchers SET status='cancelled' WHERE source_invoice_id=? AND status<>'used'");
            $cancelRewards->execute([$invoiceId]);

            $updateCustomer = $pdo->prepare('UPDATE customers
                SET points=GREATEST(0,points-?+?),total_spent=GREATEST(0,total_spent-?)
                WHERE id=?');
            $updateCustomer->execute([
                (int) $invoice['points_earned'],
                $returnedPoints,
                (float) $invoice['total_amount'],
                (int) $invoice['customer_id'],
            ]);
        }

        $update = $pdo->prepare("UPDATE invoices SET status='cancelled',cancelled_at=NOW(),cancelled_by=?,cancel_reason=? WHERE id=?");
        $update->execute([(int) current_user()['id'], $reason, $invoiceId]);
        $pdo->commit();
        log_activity('invoice_cancel', 'Hủy hóa đơn ' . $invoice['invoice_code'] . '. Lý do: ' . $reason);
        flash('success', 'Đã hủy hóa đơn, hoàn trả tồn kho và điều chỉnh ưu đãi khách hàng.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Không thể hủy hóa đơn.');
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

    render_header('Chi tiết hóa đơn', 'invoices');
    ?>
    <div class="invoice-paper">
        <div class="invoice-head"><div><h2>HÓA ĐƠN BÁN HÀNG</h2><p>Mã <?= e($invoice['invoice_code']) ?></p></div><span class="status <?= e($invoice['status']) ?>"><?= $invoice['status'] === 'paid' ? 'Đã thanh toán' : 'Đã hủy' ?></span></div>
        <div class="invoice-info">
            <p><strong>Khách hàng:</strong> <?= e($invoice['customer_name'] ?: 'Khách lẻ') ?></p>
            <p><strong>Số điện thoại:</strong> <?= e($invoice['customer_phone'] ?: 'Không có') ?></p>
            <p><strong>Nhân viên:</strong> <?= e($invoice['full_name']) ?></p>
            <p><strong>Thời gian:</strong> <?= date('d/m/Y H:i:s', strtotime($invoice['created_at'])) ?></p>
            <p><strong>Điểm được cộng:</strong> <?= (int) $invoice['points_earned'] ?> điểm</p>
            <p><strong>Voucher:</strong> <?= e($invoice['voucher_code'] ?: 'Không sử dụng') ?></p>
        </div>
        <div class="table-wrap"><table><thead><tr><th>Sản phẩm</th><th class="right">Đơn giá</th><th class="right">Số lượng</th><th class="right">Thành tiền</th></tr></thead><tbody><?php foreach ($details as $detail): ?><tr><td><?= e($detail['product_name']) ?><small class="block muted"><?= e($detail['product_code']) ?></small></td><td class="right"><?= money($detail['price']) ?></td><td class="right"><?= (int) $detail['quantity'] ?></td><td class="right"><?= money($detail['subtotal']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="invoice-summary">
            <p><span>Tạm tính</span><strong><?= money($invoice['subtotal_amount']) ?></strong></p>
            <?php if ((float) $invoice['discount_amount'] > 0): ?><p><span>Giảm giá</span><strong>- <?= money($invoice['discount_amount']) ?></strong></p><?php endif; ?>
            <p class="final-total"><span>Khách cần trả</span><strong><?= money($invoice['total_amount']) ?></strong></p>
            <p><span>Tiền khách đưa</span><strong><?= money($invoice['customer_money']) ?></strong></p>
            <p><span>Tiền thừa</span><strong><?= money($invoice['change_money']) ?></strong></p>
        </div>
        <?php if ($invoice['status'] === 'cancelled'): ?><div class="cancel-box"><strong>Hóa đơn đã hủy</strong><p>Lý do: <?= e($invoice['cancel_reason']) ?></p><small><?= e($invoice['cancelled_name']) ?> · <?= date('d/m/Y H:i', strtotime($invoice['cancelled_at'])) ?></small></div><?php endif; ?>
        <div class="invoice-actions"><a class="btn ghost" href="invoices.php">Quay lại</a><button class="btn secondary" onclick="window.print()">In hóa đơn</button></div>
        <?php if (is_admin() && $invoice['status'] === 'paid'): ?><form method="post" class="cancel-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="invoice_id" value="<?= $id ?>"><label>Lý do hủy<input name="reason" required placeholder="Nhập lý do hủy hóa đơn"></label><button class="btn danger" data-confirm="Hủy hóa đơn, hoàn trả hàng và điều chỉnh điểm/voucher?">Hủy hóa đơn</button></form><?php endif; ?>
    </div>
    <?php render_footer(); exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');
$date = trim((string) ($_GET['date'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

if ($date !== '') {
    $parsedDate = DateTime::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTime::getLastErrors();
    $hasDateErrors = is_array($dateErrors)
        && ((int) $dateErrors['warning_count'] > 0 || (int) $dateErrors['error_count'] > 0);

    if (!$parsedDate || $hasDateErrors || $parsedDate->format('Y-m-d') !== $date) {
        $date = '';
        flash('error', 'Ngày lọc hóa đơn không hợp lệ.');
    }
}

$where = ['1 = 1'];
$params = [];

if (!is_admin()) {
    $where[] = 'i.user_id = :user_id';
    $params[':user_id'] = (int) current_user()['id'];
}

if ($q !== '') {
    $where[] = '(
        i.invoice_code LIKE :q_code
        OR i.customer_name LIKE :q_name
        OR i.customer_phone LIKE :q_phone
        OR u.full_name LIKE :q_user
    )';
    $search = '%' . $q . '%';
    $params[':q_code'] = $search;
    $params[':q_name'] = $search;
    $params[':q_phone'] = $search;
    $params[':q_user'] = $search;
}

if (in_array($status, ['paid', 'cancelled'], true)) {
    $where[] = 'i.status = :status';
    $params[':status'] = $status;
} else {
    $status = 'all';
}

if ($date !== '') {
    $where[] = 'DATE(i.created_at) = :invoice_date';
    $params[':invoice_date'] = $date;
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM invoices i
     JOIN users u ON u.id = i.user_id
     WHERE ' . $whereSql
);

foreach ($params as $name => $value) {
    $countStmt->bindValue(
        $name,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$countStmt->execute();
$totalInvoices = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalInvoices / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    'SELECT i.*, u.full_name
     FROM invoices i
     JOIN users u ON u.id = i.user_id
     WHERE ' . $whereSql . '
     ORDER BY i.id DESC
     LIMIT :limit OFFSET :offset'
);

foreach ($params as $name => $value) {
    $listStmt->bindValue(
        $name,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$invoices = $listStmt->fetchAll();

function invoice_page_url(int $targetPage): string
{
    $query = $_GET;
    $query['page'] = $targetPage;
    unset($query['id']);

    return '?' . http_build_query($query);
}

render_header('Quản lý hóa đơn', 'invoices');
?>
<div class="panel">
    <div class="panel-heading responsive">
        <div>
            <h2>Danh sách hóa đơn</h2>
            <p><?= $totalInvoices ?> giao dịch phù hợp</p>
        </div>

        <form method="get" class="filter-form">
            <input name="q" value="<?= e($q) ?>" placeholder="Mã hóa đơn, tên hoặc SĐT">
            <input type="date" name="date" value="<?= e($date) ?>">
            <select name="status">
                <option value="all">Tất cả</option>
                <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Đã thanh toán</option>
                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Đã hủy</option>
            </select>
            <button class="btn secondary">Lọc</button>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Mã hóa đơn</th>
                <th>Khách hàng</th>
                <th>Số điện thoại</th>
                <th>Nhân viên</th>
                <th>Thời gian</th>
                <th>Trạng thái</th>
                <th class="right">Tổng tiền</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$invoices): ?>
                <tr><td colspan="7" class="empty">Chưa có hóa đơn phù hợp.</td></tr>
            <?php endif; ?>

            <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td>
                        <a href="?id=<?= (int) $invoice['id'] ?>">
                            <strong><?= e($invoice['invoice_code']) ?></strong>
                        </a>
                    </td>
                    <td><?= e($invoice['customer_name'] ?: 'Khách lẻ') ?></td>
                    <td><?= e($invoice['customer_phone'] ?: '—') ?></td>
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

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Phân trang hóa đơn">
            <a
                class="pagination-link <?= $page <= 1 ? 'disabled' : '' ?>"
                href="<?= $page > 1 ? e(invoice_page_url($page - 1)) : '#' ?>"
            >
                Trước
            </a>

            <span>Trang <?= $page ?> / <?= $totalPages ?></span>

            <a
                class="pagination-link <?= $page >= $totalPages ? 'disabled' : '' ?>"
                href="<?= $page < $totalPages ? e(invoice_page_url($page + 1)) : '#' ?>"
            >
                Sau
            </a>
        </nav>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
