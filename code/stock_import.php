<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_roles('admin','warehouse');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $costPrice = filter_var($_POST['cost_price'] ?? null, FILTER_VALIDATE_FLOAT);
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($productId <= 0 || $quantity === false || $quantity <= 0 || $costPrice === false || $costPrice < 0) throw new RuntimeException('Vui lòng chọn sản phẩm, nhập số lượng và giá nhập hợp lệ.');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id,code,name FROM products WHERE id=? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) throw new RuntimeException('Không tìm thấy sản phẩm.');
        $subtotal = $quantity * $costPrice;
        $importCode = 'PN' . date('YmdHis') . random_int(10,99);
        $stmt = $pdo->prepare('INSERT INTO stock_imports(import_code,user_id,supplier,note,total_amount) VALUES(?,?,?,?,?)');
        $stmt->execute([$importCode,(int)current_user()['id'],$supplier ?: 'Không ghi rõ',$note,$subtotal]);
        $importId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO stock_import_details(stock_import_id,product_id,product_code,product_name,quantity,cost_price,subtotal) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([$importId,$product['id'],$product['code'],$product['name'],$quantity,$costPrice,$subtotal]);
        $pdo->prepare('UPDATE products SET stock=stock+?, updated_at=NOW() WHERE id=?')->execute([$quantity,$productId]);
        $pdo->commit();
        log_activity('stock_import', 'Nhập hàng ' . $importCode . ' - ' . $product['code'] . ' số lượng ' . $quantity);
        flash('success', 'Đã tạo phiếu nhập và cập nhật tồn kho.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Không thể nhập hàng.');
    }
    redirect('stock_import.php');
}
$products = $pdo->query('SELECT id,code,name,stock,unit FROM products WHERE status=1 ORDER BY name')->fetchAll();
$imports = $pdo->query('SELECT si.*,u.full_name FROM stock_imports si JOIN users u ON u.id=si.user_id ORDER BY si.id DESC LIMIT 20')->fetchAll();
render_header('Nhập hàng', 'stock_import');
?>
<div class="two-column">
    <section class="panel form-panel">
        <div class="panel-heading"><div><h2>Tạo phiếu nhập hàng</h2><p>Nhập hàng sẽ tự động cộng vào tồn kho</p></div></div>
        <form method="post" class="form-grid one-column">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label>Sản phẩm<select name="product_id" required><option value="">-- Chọn sản phẩm --</option><?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['code'].' - '.$p['name'].' (tồn '.$p['stock'].' '.$p['unit'].')') ?></option><?php endforeach; ?></select></label>
            <label>Số lượng nhập<input type="number" name="quantity" min="1" required></label>
            <label>Giá nhập<input type="number" name="cost_price" min="0" step="1000" value="0" required></label>
            <label>Nhà cung cấp<input name="supplier" placeholder="Ví dụ: Nhà cung cấp A"></label>
            <label>Ghi chú<textarea name="note" rows="3" placeholder="Không bắt buộc"></textarea></label>
            <button class="btn primary">Lưu phiếu nhập</button>
        </form>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><h2>Phiếu nhập gần đây</h2><p>Theo dõi các lần nhập kho</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Mã phiếu</th><th>Người nhập</th><th>Nhà cung cấp</th><th>Thời gian</th><th class="right">Tổng tiền</th></tr></thead><tbody>
        <?php if (!$imports): ?><tr><td colspan="5" class="empty">Chưa có phiếu nhập.</td></tr><?php endif; ?>
        <?php foreach ($imports as $im): ?><tr><td><strong><?= e($im['import_code']) ?></strong></td><td><?= e($im['full_name']) ?></td><td><?= e($im['supplier']) ?></td><td><?= date('d/m/Y H:i', strtotime($im['created_at'])) ?></td><td class="right"><?= money($im['total_amount']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php render_footer(); ?>
