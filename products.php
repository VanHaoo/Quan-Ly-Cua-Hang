<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_admin();

$pdo = db();
$keyword = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'active');
$allowedStatuses = ['active', 'inactive', 'all'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'active';
}

$editId = (int) ($_GET['edit'] ?? 0);
$editProduct = null;

if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editProduct = $stmt->fetch() ?: null;
}

$sql = 'SELECT * FROM products WHERE 1=1';
$params = [];
if ($statusFilter === 'active') {
    $sql .= ' AND status = 1';
} elseif ($statusFilter === 'inactive') {
    $sql .= ' AND status = 0';
}
if ($keyword !== '') {
    $sql .= ' AND (code LIKE ? OR name LIKE ? OR category LIKE ?)';
    $search = '%' . $keyword . '%';
    $params = [$search, $search, $search];
}
$sql .= ' ORDER BY status DESC, id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle = 'Quản lý sản phẩm';
$activePage = 'products';
require __DIR__ . '/partials/header.php';
?>
<div class="split-layout product-layout">
    <div class="panel form-panel">
        <div class="panel-heading">
            <div>
                <h2><?= $editProduct ? 'Cập nhật sản phẩm' : 'Thêm sản phẩm' ?></h2>
                <p>Thiết lập thông tin và mức cảnh báo tồn kho</p>
            </div>
        </div>

        <form method="post" action="<?= BASE_URL ?>/actions/product_action.php" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="<?= $editProduct ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= (int) ($editProduct['id'] ?? 0) ?>">

            <div class="form-grid two-cols">
                <label>
                    Mã sản phẩm
                    <input type="text" name="code" maxlength="30" required value="<?= htmlspecialchars((string) ($editProduct['code'] ?? '')) ?>" placeholder="SP001">
                </label>
                <label>
                    Đơn vị tính
                    <input type="text" name="unit" maxlength="30" required value="<?= htmlspecialchars((string) ($editProduct['unit'] ?? 'Cái')) ?>" placeholder="Cái, chai, hộp">
                </label>
            </div>

            <label>
                Tên sản phẩm
                <input type="text" name="name" maxlength="150" required value="<?= htmlspecialchars((string) ($editProduct['name'] ?? '')) ?>" placeholder="Nhập tên sản phẩm">
            </label>

            <label>
                Danh mục
                <input type="text" name="category" maxlength="100" required value="<?= htmlspecialchars((string) ($editProduct['category'] ?? '')) ?>" placeholder="Đồ uống, thực phẩm">
            </label>

            <div class="form-grid two-cols">
                <label>
                    Giá bán
                    <input type="number" name="price" min="1" step="1000" required value="<?= htmlspecialchars((string) ($editProduct['price'] ?? '')) ?>" placeholder="10000">
                </label>
                <label>
                    Số lượng tồn
                    <input type="number" name="stock" min="0" required value="<?= htmlspecialchars((string) ($editProduct['stock'] ?? '0')) ?>">
                </label>
            </div>

            <label>
                Mức cảnh báo tồn kho
                <input type="number" name="min_stock" min="0" required value="<?= htmlspecialchars((string) ($editProduct['min_stock'] ?? '5')) ?>">
                <small class="field-help">Hệ thống cảnh báo khi tồn kho nhỏ hơn hoặc bằng mức này</small>
            </label>

            <div class="button-row">
                <button type="submit" class="btn btn-primary"><?= $editProduct ? 'Lưu thay đổi' : 'Thêm sản phẩm' ?></button>
                <?php if ($editProduct): ?>
                    <a class="btn btn-secondary" href="<?= BASE_URL ?>/products.php?status=<?= urlencode($statusFilter) ?>">Hủy chỉnh sửa</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel grow-panel">
        <div class="panel-heading responsive-heading">
            <div>
                <h2>Danh sách sản phẩm</h2>
                <p>Có <?= count($products) ?> sản phẩm đang hiển thị</p>
            </div>
            <form method="get" class="filter-form">
                <input type="search" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="Tìm mã, tên hoặc danh mục">
                <select name="status">
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Đang kinh doanh</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Ngừng kinh doanh</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Tất cả trạng thái</option>
                </select>
                <button class="btn btn-secondary" type="submit">Lọc dữ liệu</button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Mã</th>
                    <th>Sản phẩm</th>
                    <th>Danh mục</th>
                    <th class="text-right">Giá bán</th>
                    <th class="text-center">Tồn kho</th>
                    <th class="text-center">Cảnh báo</th>
                    <th>Trạng thái</th>
                    <th>Thao tác</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="8" class="empty-cell">Không tìm thấy sản phẩm phù hợp.</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <?php $isLow = (int) $product['stock'] <= (int) $product['min_stock']; ?>
                        <tr class="<?= (int) $product['status'] === 0 ? 'row-muted' : '' ?>">
                            <td><span class="pill"><?= htmlspecialchars($product['code']) ?></span></td>
                            <td>
                                <strong><?= htmlspecialchars($product['name']) ?></strong>
                                <small class="muted-line"><?= htmlspecialchars($product['unit']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($product['category']) ?></td>
                            <td class="text-right"><?= format_money($product['price']) ?></td>
                            <td class="text-center">
                                <span class="stock-badge <?= $isLow ? 'low' : '' ?>"><?= (int) $product['stock'] ?></span>
                            </td>
                            <td class="text-center"><?= (int) $product['min_stock'] ?></td>
                            <td>
                                <span class="status-badge <?= (int) $product['status'] === 1 ? 'status-paid' : 'status-cancelled' ?>">
                                    <?= (int) $product['status'] === 1 ? 'Đang kinh doanh' : 'Ngừng kinh doanh' ?>
                                </span>
                            </td>
                            <td>
                                <div class="inline-actions">
                                    <a class="btn btn-small btn-secondary" href="<?= BASE_URL ?>/products.php?edit=<?= (int) $product['id'] ?>&status=<?= urlencode($statusFilter) ?>">Sửa</a>
                                    <form method="post" action="<?= BASE_URL ?>/actions/product_action.php" data-confirm="<?= (int) $product['status'] === 1 ? 'Chuyển sản phẩm sang trạng thái ngừng kinh doanh?' : 'Đưa sản phẩm trở lại danh sách bán hàng?' ?>">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                        <button type="submit" class="btn btn-small <?= (int) $product['status'] === 1 ? 'btn-danger' : 'btn-success' ?>">
                                            <?= (int) $product['status'] === 1 ? 'Ngừng bán' : 'Kích hoạt' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
