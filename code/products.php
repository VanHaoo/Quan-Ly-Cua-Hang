<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    try {
        if ($action === 'toggle') {
            $stmt = $pdo->prepare('SELECT code, status FROM products WHERE id = ?');
            $stmt->execute([$id]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new RuntimeException('Không tìm thấy sản phẩm.');
            }

            $status = (int) $product['status'] === 1 ? 0 : 1;

            $pdo->prepare('UPDATE products SET status = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$status, $id]);

            log_activity(
                'product_status',
                ($status ? 'Kích hoạt' : 'Ngừng bán') . ' sản phẩm ' . $product['code']
            );

            flash('success', 'Đã cập nhật trạng thái sản phẩm.');
            redirect('products.php');
        }

        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
        $minStock = filter_var($_POST['min_stock'] ?? null, FILTER_VALIDATE_INT);

        if (
            $code === '' ||
            $name === '' ||
            $category === '' ||
            $unit === '' ||
            $price === false ||
            $stock === false ||
            $minStock === false
        ) {
            throw new RuntimeException('Vui lòng nhập đầy đủ và đúng định dạng.');
        }

        if ($price <= 0 || $stock < 0 || $minStock < 0) {
            throw new RuntimeException('Giá phải lớn hơn 0, số lượng không được âm.');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO products(code, name, category, price, stock, min_stock, unit, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([$code, $name, $category, $price, $stock, $minStock, $unit]);

            log_activity('product_create', 'Thêm sản phẩm ' . $code . ' - ' . $name);
            flash('success', 'Đã thêm sản phẩm mới.');
        } elseif ($action === 'update' && $id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE products
                 SET code = ?, name = ?, category = ?, price = ?, stock = ?, min_stock = ?, unit = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([$code, $name, $category, $price, $stock, $minStock, $unit, $id]);

            log_activity('product_update', 'Cập nhật sản phẩm ' . $code . ' - ' . $name);
            flash('success', 'Đã cập nhật sản phẩm.');
        } else {
            throw new RuntimeException('Thao tác không hợp lệ.');
        }
    } catch (PDOException $exception) {
        flash('error', $exception->getCode() === '23000' ? 'Mã sản phẩm đã tồn tại.' : 'Không thể lưu sản phẩm.');
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
    }

    redirect('products.php');
}

$edit = null;

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');

$sql = 'SELECT * FROM products WHERE 1 = 1';
$params = [];

if ($q !== '') {
    $sql .= ' AND (code LIKE ? OR name LIKE ? OR category LIKE ?)';
    $search = '%' . $q . '%';
    $params = [$search, $search, $search];
}

if ($status === 'active') {
    $sql .= ' AND status = 1';
} elseif ($status === 'inactive') {
    $sql .= ' AND status = 0';
}

$sql .= ' ORDER BY id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$activeProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = 1')->fetchColumn();
$lowStockProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = 1 AND stock <= min_stock')->fetchColumn();
$totalStockValue = (float) $pdo->query('SELECT COALESCE(SUM(price * stock), 0) FROM products WHERE status = 1')->fetchColumn();

render_header('Quản lý sản phẩm', 'products');
?>

<div class="page-summary-grid">
    <article class="mini-stat">
        <span>Tổng sản phẩm</span>
        <strong><?= $totalProducts ?></strong>
        <small>Toàn bộ danh mục</small>
    </article>

    <article class="mini-stat">
        <span>Đang bán</span>
        <strong><?= $activeProducts ?></strong>
        <small>Sản phẩm còn hoạt động</small>
    </article>

    <article class="mini-stat warning">
        <span>Sắp hết hàng</span>
        <strong><?= $lowStockProducts ?></strong>
        <small>Cần kiểm tra tồn kho</small>
    </article>

    <article class="mini-stat">
        <span>Giá trị tồn kho</span>
        <strong><?= money($totalStockValue) ?></strong>
        <small>Tính theo giá bán</small>
    </article>
</div>

<div class="two-column product-page-layout">
    <section class="panel form-panel">
        <div class="panel-heading">
            <div>
                <h2><?= $edit ? 'Cập nhật sản phẩm' : 'Thêm sản phẩm mới' ?></h2>
                <p>Quản lý thông tin sản phẩm, giá bán và mức cảnh báo tồn kho.</p>
            </div>
        </div>

        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

            <label>
                Mã sản phẩm
                <input name="code" value="<?= e($edit['code'] ?? '') ?>" placeholder="Ví dụ SP008" required>
            </label>

            <label>
                Tên sản phẩm
                <input name="name" value="<?= e($edit['name'] ?? '') ?>" placeholder="Nhập tên sản phẩm" required>
            </label>

            <label>
                Danh mục
                <input name="category" value="<?= e($edit['category'] ?? '') ?>" placeholder="Đồ uống, bánh kẹo..." required>
            </label>

            <label>
                Đơn vị tính
                <input name="unit" value="<?= e($edit['unit'] ?? 'Cái') ?>" placeholder="Cái, hộp, gói..." required>
            </label>

            <label>
                Giá bán
                <input type="number" name="price" min="1" step="1000" value="<?= e($edit['price'] ?? '') ?>" placeholder="Ví dụ 15000" required>
            </label>

            <label>
                Số lượng tồn
                <input type="number" name="stock" min="0" value="<?= e($edit['stock'] ?? 0) ?>" required>
            </label>

            <label>
                Mức cảnh báo
                <input type="number" name="min_stock" min="0" value="<?= e($edit['min_stock'] ?? 5) ?>" required>
            </label>

            <div class="form-actions">
                <button class="btn primary" type="submit">
                    <?= $edit ? 'Lưu thay đổi' : 'Thêm sản phẩm' ?>
                </button>

                <?php if ($edit): ?>
                    <a class="btn ghost" href="products.php">Hủy sửa</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel product-list-panel">
        <div class="panel-heading responsive">
            <div>
                <h2>Danh sách sản phẩm</h2>
                <p><?= count($products) ?> sản phẩm được tìm thấy</p>
            </div>

            <form method="get" class="filter-form">
                <input name="q" value="<?= e($q) ?>" placeholder="Tìm mã, tên, danh mục">

                <select name="status">
                    <option value="all">Tất cả trạng thái</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Đang bán</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Ngừng bán</option>
                </select>

                <button class="btn secondary">Lọc</button>
            </form>
        </div>

        <div class="table-wrap product-table">
            <table>
                <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Sản phẩm</th>
                        <th>Giá</th>
                        <th>Tồn</th>
                        <th>Trạng thái</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$products): ?>
                        <tr>
                            <td colspan="6" class="empty">Không có sản phẩm phù hợp.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($products as $product): ?>
                        <?php
                            $isLowStock = (int) $product['stock'] <= (int) $product['min_stock'];
                            $isActive = (int) $product['status'] === 1;
                        ?>

                        <tr>
                            <td>
                                <span class="product-code-pill"><?= e($product['code']) ?></span>
                            </td>

                            <td>
                                <div class="product-name-cell">
                                    <span class="product-icon">
                                        <?= str_contains(strtolower((string) $product['name']), 'sua') || str_contains(strtolower((string) $product['name']), 'sữa') ? '🥛' : '📦' ?>
                                    </span>

                                    <div>
                                        <strong><?= e($product['name']) ?></strong>
                                        <small class="block muted">
                                            <?= e($product['category']) ?> · <?= e($product['unit']) ?>
                                        </small>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <strong><?= money($product['price']) ?></strong>
                            </td>

                            <td>
                                <span class="stock <?= $isLowStock ? 'low' : '' ?>">
                                    <?= (int) $product['stock'] ?>
                                </span>

                                <?php if ($isLowStock): ?>
                                    <small class="block low-note">Dưới mức cảnh báo</small>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="status <?= $isActive ? 'paid' : 'cancelled' ?>">
                                    <?= $isActive ? 'Đang bán' : 'Ngừng bán' ?>
                                </span>
                            </td>

                            <td class="actions">
                                <a class="btn small ghost" href="?edit=<?= (int) $product['id'] ?>">Sửa</a>

                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">

                                    <button
                                        class="btn small <?= $isActive ? 'danger' : 'secondary' ?>"
                                        data-confirm="Xác nhận thay đổi trạng thái sản phẩm?"
                                    >
                                        <?= $isActive ? 'Ngừng bán' : 'Kích hoạt' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php render_footer(); ?>