<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_roles('admin','warehouse');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $id = (int) ($_POST['id'] ?? 0);
        $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
        $minStock = filter_var($_POST['min_stock'] ?? null, FILTER_VALIDATE_INT);
        if ($id <= 0 || $stock === false || $minStock === false || $stock < 0 || $minStock < 0) throw new RuntimeException('Số lượng tồn và mức cảnh báo không hợp lệ.');
        $stmt = $pdo->prepare('SELECT code,name FROM products WHERE id=?');
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) throw new RuntimeException('Không tìm thấy sản phẩm.');
        $pdo->prepare('UPDATE products SET stock=?, min_stock=?, updated_at=NOW() WHERE id=?')->execute([$stock,$minStock,$id]);
        log_activity('inventory_update', 'Cập nhật tồn kho ' . $product['code'] . ' - ' . $product['name']);
        flash('success', 'Đã cập nhật tồn kho.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('inventory.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$filter = (string) ($_GET['filter'] ?? 'all');
$sql = 'SELECT * FROM products WHERE 1=1';
$params = [];
if ($q !== '') { $sql .= ' AND (code LIKE ? OR name LIKE ? OR category LIKE ?)'; $s='%'.$q.'%'; $params=[$s,$s,$s]; }
if ($filter === 'low') $sql .= ' AND status=1 AND stock<=min_stock';
if ($filter === 'out') $sql .= ' AND status=1 AND stock=0';
$sql .= ' ORDER BY stock ASC, name ASC';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $products = $stmt->fetchAll();
render_header('Kiểm tra tồn kho', 'inventory');
?>
<section class="panel">
    <div class="panel-heading responsive"><div><h2>Tồn kho sản phẩm</h2><p>Cập nhật số lượng tồn và mức cảnh báo</p></div><form class="filter-form"><input name="q" value="<?= e($q) ?>" placeholder="Tìm sản phẩm"><select name="filter"><option value="all">Tất cả</option><option value="low" <?= $filter==='low'?'selected':'' ?>>Sắp hết</option><option value="out" <?= $filter==='out'?'selected':'' ?>>Hết hàng</option></select><button class="btn secondary">Lọc</button></form></div>
    <div class="table-wrap"><table><thead><tr><th>Mã</th><th>Sản phẩm</th><th class="right">Tồn hiện tại</th><th class="right">Mức cảnh báo</th><th>Cập nhật nhanh</th></tr></thead><tbody>
    <?php if (!$products): ?><tr><td colspan="5" class="empty">Không có sản phẩm phù hợp.</td></tr><?php endif; ?>
    <?php foreach ($products as $p): ?><tr>
        <td><strong><?= e($p['code']) ?></strong></td><td><?= e($p['name']) ?><small class="block muted"><?= e($p['category']) ?> · <?= e($p['unit']) ?></small></td>
        <td class="right"><span class="stock <?= (int)$p['stock'] <= (int)$p['min_stock'] ? 'low' : '' ?>"><?= (int)$p['stock'] ?></span></td><td class="right"><?= (int)$p['min_stock'] ?></td>
        <td><form method="post" class="inline-update"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="number" name="stock" min="0" value="<?= (int)$p['stock'] ?>"><input type="number" name="min_stock" min="0" value="<?= (int)$p['min_stock'] ?>"><button class="btn small primary">Lưu</button></form></td>
    </tr><?php endforeach; ?></tbody></table></div>
</section>
<?php render_footer(); ?>
