<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    require_admin(); verify_csrf(); $invoiceId = (int)($_POST['invoice_id'] ?? 0); $reason = trim((string)($_POST['reason'] ?? ''));
    if ($reason === '') { flash('error','Vui lòng nhập lý do hủy hóa đơn.'); redirect('invoices.php?id='.$invoiceId); }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id=? FOR UPDATE'); $stmt->execute([$invoiceId]); $invoice = $stmt->fetch();
        if (!$invoice || $invoice['status'] !== 'paid') throw new RuntimeException('Hóa đơn không tồn tại hoặc đã bị hủy.');
        if (!empty($invoice['customer_id'])) {
            $rewardStmt = $pdo->prepare('SELECT id,status,points_cost FROM vouchers WHERE source_invoice_id=? FOR UPDATE'); $rewardStmt->execute([$invoiceId]);
            foreach ($rewardStmt->fetchAll() as $rv) { if ($rv['status'] === 'used') throw new RuntimeException('Không thể hủy vì voucher thưởng từ hóa đơn này đã được sử dụng.'); }
        }
        $details = $pdo->prepare('SELECT product_id,quantity FROM invoice_details WHERE invoice_id=?'); $details->execute([$invoiceId]);
        $restore = $pdo->prepare('UPDATE products SET stock=stock+?, updated_at=NOW() WHERE id=?');
        foreach ($details->fetchAll() as $d) $restore->execute([(int)$d['quantity'],(int)$d['product_id']]);
        if (!empty($invoice['voucher_id'])) $pdo->prepare("UPDATE vouchers SET status=IF(expires_at>=NOW(),'available','expired'), used_at=NULL, used_invoice_id=NULL WHERE id=? AND used_invoice_id=?")->execute([(int)$invoice['voucher_id'],$invoiceId]);
        if (!empty($invoice['customer_id'])) {
            $rewardStmt = $pdo->prepare("SELECT COALESCE(SUM(points_cost),0) FROM vouchers WHERE source_invoice_id=? AND status<>'used'"); $rewardStmt->execute([$invoiceId]); $returnedPoints=(int)$rewardStmt->fetchColumn();
            $pdo->prepare("UPDATE vouchers SET status='cancelled' WHERE source_invoice_id=? AND status<>'used'")->execute([$invoiceId]);
            $pdo->prepare('UPDATE customers SET points=GREATEST(0,points-?+?), total_spent=GREATEST(0,total_spent-?) WHERE id=?')->execute([(int)$invoice['points_earned'],$returnedPoints,(float)$invoice['total_amount'],(int)$invoice['customer_id']]);
        }
        $pdo->prepare("UPDATE invoices SET status='cancelled', cancelled_at=NOW(), cancelled_by=?, cancel_reason=? WHERE id=?")->execute([(int)current_user()['id'],$reason,$invoiceId]);
        $pdo->commit(); log_activity('invoice_cancel','Hủy hóa đơn '.$invoice['invoice_code'].'. Lý do: '.$reason); flash('success','Đã hủy hóa đơn, hoàn trả tồn kho và điều chỉnh điểm/voucher.');
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('error',$e instanceof RuntimeException ? $e->getMessage() : 'Không thể hủy hóa đơn.'); }
    redirect('invoices.php?id='.$invoiceId);
}

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $sql = 'SELECT i.*,u.full_name,c.full_name AS cancelled_name,v.code AS voucher_code,v.name AS voucher_name FROM invoices i JOIN users u ON u.id=i.user_id LEFT JOIN users c ON c.id=i.cancelled_by LEFT JOIN vouchers v ON v.id=i.voucher_id WHERE i.id=?';
    $params = [$id]; if (!is_admin()) { $sql .= ' AND i.user_id=?'; $params[]=(int)current_user()['id']; }
    $stmt=$pdo->prepare($sql); $stmt->execute($params); $invoice=$stmt->fetch();
    if (!$invoice) { flash('error','Không tìm thấy hóa đơn.'); redirect('invoices.php'); }
    $detailsStmt=$pdo->prepare('SELECT * FROM invoice_details WHERE invoice_id=? ORDER BY id'); $detailsStmt->execute([$id]); $details=$detailsStmt->fetchAll();
    $methodName = ['cash'=>'Tiền mặt','transfer'=>'Chuyển khoản','qr'=>'QR'][$invoice['payment_method']] ?? 'Tiền mặt';
    render_header('Chi tiết hóa đơn','invoices'); ?>
    <div class="invoice-paper"><div class="invoice-head"><div><h2>HÓA ĐƠN BÁN HÀNG</h2><p>Mã <?= e($invoice['invoice_code']) ?></p></div><span class="status <?= e($invoice['status']) ?>"><?= $invoice['status']==='paid'?'Đã thanh toán':'Đã hủy' ?></span></div>
    <div class="invoice-info"><p><strong>Khách hàng:</strong> <?= e($invoice['customer_name'] ?: 'Khách lẻ') ?></p><p><strong>Số điện thoại:</strong> <?= e($invoice['customer_phone'] ?: 'Không có') ?></p><p><strong>Nhân viên:</strong> <?= e($invoice['full_name']) ?></p><p><strong>Thời gian:</strong> <?= date('d/m/Y H:i:s', strtotime($invoice['created_at'])) ?></p><p><strong>Thanh toán:</strong> <?= e($methodName) ?></p><p><strong>Điểm cộng:</strong> <?= (int)$invoice['points_earned'] ?> điểm</p><p><strong>Voucher:</strong> <?= e($invoice['voucher_code'] ?: 'Không sử dụng') ?></p></div>
    <div class="table-wrap"><table><thead><tr><th>Sản phẩm</th><th class="right">Đơn giá</th><th class="right">Số lượng</th><th class="right">Thành tiền</th></tr></thead><tbody><?php foreach ($details as $d): ?><tr><td><?= e($d['product_name']) ?><small class="block muted"><?= e($d['product_code']) ?></small></td><td class="right"><?= money($d['price']) ?></td><td class="right"><?= (int)$d['quantity'] ?></td><td class="right"><?= money($d['subtotal']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="invoice-summary"><p><span>Tạm tính</span><strong><?= money($invoice['subtotal_amount']) ?></strong></p><?php if ((float)$invoice['discount_amount']>0): ?><p><span>Giảm giá</span><strong>- <?= money($invoice['discount_amount']) ?></strong></p><?php endif; ?><p class="final-total"><span>Khách cần trả</span><strong><?= money($invoice['total_amount']) ?></strong></p><p><span>Tiền khách đưa</span><strong><?= money($invoice['customer_money']) ?></strong></p><p><span>Tiền thừa</span><strong><?= money($invoice['change_money']) ?></strong></p></div>
    <?php if ($invoice['status']==='cancelled'): ?><div class="cancel-box"><strong>Hóa đơn đã hủy</strong><p>Lý do: <?= e($invoice['cancel_reason']) ?></p><small><?= e($invoice['cancelled_name']) ?> · <?= date('d/m/Y H:i', strtotime($invoice['cancelled_at'])) ?></small></div><?php endif; ?>
    <div class="invoice-actions"><a class="btn ghost" href="invoices.php">Quay lại</a><button class="btn secondary" onclick="window.print()">In hóa đơn</button></div>
    <?php if (is_admin() && $invoice['status']==='paid'): ?><form method="post" class="cancel-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="invoice_id" value="<?= $id ?>"><label>Lý do hủy<input name="reason" required placeholder="Nhập lý do hủy hóa đơn"></label><button class="btn danger" data-confirm="Hủy hóa đơn, hoàn trả hàng và điều chỉnh điểm/voucher?">Hủy hóa đơn</button></form><?php endif; ?></div>
    <?php render_footer(); exit;
}

$q=trim((string)($_GET['q'] ?? '')); $status=(string)($_GET['status'] ?? 'all'); $date=trim((string)($_GET['date'] ?? ''));
$where=['1=1']; $params=[]; if (!is_admin()) { $where[]='i.user_id=:user_id'; $params[':user_id']=(int)current_user()['id']; }
if ($q!=='') { $where[]='(i.invoice_code LIKE :q OR i.customer_name LIKE :q OR i.customer_phone LIKE :q OR u.full_name LIKE :q)'; $params[':q']='%'.$q.'%'; }
if (in_array($status,['paid','cancelled'],true)) { $where[]='i.status=:status'; $params[':status']=$status; } else $status='all';
if ($date!=='') { $where[]='DATE(i.created_at)=:date'; $params[':date']=$date; }
$stmt=$pdo->prepare('SELECT i.*,u.full_name FROM invoices i JOIN users u ON u.id=i.user_id WHERE '.implode(' AND ',$where).' ORDER BY i.id DESC LIMIT 100');
foreach ($params as $k=>$v) $stmt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); $stmt->execute(); $invoices=$stmt->fetchAll();
render_header('Quản lý hóa đơn','invoices'); ?>
<section class="panel"><div class="panel-heading responsive"><div><h2>Danh sách hóa đơn</h2><p><?= count($invoices) ?> giao dịch phù hợp</p></div><form method="get" class="filter-form"><input name="q" value="<?= e($q) ?>" placeholder="Mã hóa đơn, tên hoặc SĐT"><input type="date" name="date" value="<?= e($date) ?>"><select name="status"><option value="all">Tất cả</option><option value="paid" <?= $status==='paid'?'selected':'' ?>>Đã thanh toán</option><option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Đã hủy</option></select><button class="btn secondary">Lọc</button></form></div>
<div class="table-wrap"><table><thead><tr><th>Mã hóa đơn</th><th>Khách hàng</th><th>Nhân viên</th><th>Thời gian</th><th>Trạng thái</th><th class="right">Tổng tiền</th><th></th></tr></thead><tbody><?php if (!$invoices): ?><tr><td colspan="7" class="empty">Không có hóa đơn.</td></tr><?php endif; ?><?php foreach ($invoices as $i): ?><tr><td><strong><?= e($i['invoice_code']) ?></strong></td><td><?= e($i['customer_name'] ?: 'Khách lẻ') ?></td><td><?= e($i['full_name']) ?></td><td><?= date('d/m/Y H:i', strtotime($i['created_at'])) ?></td><td><span class="status <?= e($i['status']) ?>"><?= $i['status']==='paid'?'Đã thanh toán':'Đã hủy' ?></span></td><td class="right"><?= money($i['total_amount']) ?></td><td><a class="btn small ghost" href="?id=<?= (int)$i['id'] ?>">Chi tiết</a></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php render_footer(); ?>
