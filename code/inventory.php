<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_roles('admin', 'warehouse');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $id = (int) ($_POST['id'] ?? 0);
        $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
        $minStock = filter_var($_POST['min_stock'] ?? null, FILTER_VALIDATE_INT);

        if ($id <= 0 || $stock === false || $minStock === false || $stock < 0 || $minStock < 0) {
            throw new RuntimeException('Số lượng tồn và mức cảnh báo không hợp lệ.');
        }

        $stmt = $pdo->prepare('SELECT id,code,name,stock,min_stock FROM products WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new RuntimeException('Không tìm thấy sản phẩm.');
        }

        $oldStock = (int) $product['stock'];
        $oldMinStock = (int) $product['min_stock'];

        $pdo->prepare('UPDATE products SET stock=?, min_stock=?, updated_at=NOW() WHERE id=?')
            ->execute([$stock, $minStock, $id]);

        log_activity(
            'inventory_update',
            'Cập nhật tồn kho ' . $product['code'] . ' - ' . $product['name']
            . ': tồn ' . $oldStock . ' → ' . $stock
            . ', cảnh báo ' . $oldMinStock . ' → ' . $minStock
        );

        flash('success', 'Đã cập nhật tồn kho và ghi lại lịch sử điều chỉnh.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }

    redirect('inventory.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$filter = (string) ($_GET['filter'] ?? 'all');
$category = trim((string) ($_GET['category'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$categories = $pdo
    ->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category")
    ->fetchAll(PDO::FETCH_COLUMN);

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(code LIKE :q OR name LIKE :q OR category LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if ($category !== '') {
    $where[] = 'category = :category';
    $params[':category'] = $category;
}

if ($filter === 'low') {
    $where[] = 'status=1 AND stock<=min_stock';
} elseif ($filter === 'out') {
    $where[] = 'status=1 AND stock=0';
} else {
    $filter = 'all';
}

$whereSql = implode(' AND ', $where);

$summary = [
    'active_sku' => (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status=1')->fetchColumn(),
    'low_stock' => (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status=1 AND stock<=min_stock')->fetchColumn(),
    'out_stock' => (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status=1 AND stock=0')->fetchColumn(),
];

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE {$whereSql}");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$countStmt->execute();
$totalProducts = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalProducts / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "
    SELECT *
    FROM products
    WHERE {$whereSql}
    ORDER BY stock ASC, name ASC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

function inventory_page_url(string $q, string $category, string $filter, int $page): string
{
    $query = array_filter([
        'q' => $q,
        'category' => $category,
        'filter' => $filter !== 'all' ? $filter : '',
        'page' => $page,
    ], fn($value) => $value !== '' && $value !== 1);

    return 'inventory.php' . ($query ? '?' . http_build_query($query) : '');
}

render_header('Kiểm tra tồn kho', 'inventory');
?>

<div class="stats-grid inventory-summary-grid">
    <article class="stat-card">
        <span>Tổng số SKU đang bán</span>
        <strong><?= $summary['active_sku'] ?></strong>
        <small>Sản phẩm đang hoạt động</small>
    </article>

    <article class="stat-card warning">
        <span>Dưới mức cảnh báo</span>
        <strong><?= $summary['low_stock'] ?></strong>
        <small>Cần kiểm tra nhập hàng</small>
    </article>

    <article class="stat-card warning">
        <span>Đã hết hàng</span>
        <strong><?= $summary['out_stock'] ?></strong>
        <small>Tồn kho bằng 0</small>
    </article>
</div>

<section class="panel inventory-list-panel">
    <div class="panel-heading responsive">
        <div>
            <h2>Tồn kho sản phẩm</h2>
            <p><?= $totalProducts ?> sản phẩm phù hợp · Trang <?= $page ?>/<?= $totalPages ?></p>
        </div>

        <form class="filter-form inventory-filter-form">
            <label>
                Tìm sản phẩm
                <input name="q" value="<?= e($q) ?>" placeholder="Mã, tên hoặc danh mục">
            </label>

            <label>
                Danh mục
                <select name="category">
                    <option value="">Tất cả</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Tình trạng
                <select name="filter">
                    <option value="all">Tất cả</option>
                    <option value="low" <?= $filter === 'low' ? 'selected' : '' ?>>Sắp hết</option>
                    <option value="out" <?= $filter === 'out' ? 'selected' : '' ?>>Hết hàng</option>
                </select>
            </label>

            <button class="btn secondary">Lọc</button>
        </form>
    </div>

    <div class="table-wrap">
        <table class="inventory-table">
            <thead>
                <tr>
                    <th>Mã</th>
                    <th>Sản phẩm</th>
                    <th class="right">Tồn đang ghi nhận</th>
                    <th class="right">Mức cảnh báo</th>
                    <th class="right">Tồn hiện tại</th>
                    <th class="right">Mức cảnh báo mới</th>
                    <th class="right">Thao tác</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$products): ?>
                    <tr>
                        <td colspan="7" class="empty">Không có sản phẩm phù hợp.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($products as $product): ?>
                    <?php
                        $stock = (int) $product['stock'];
                        $minStock = (int) $product['min_stock'];
                        $isLow = $stock <= $minStock;
                    ?>
                    <tr>
                        <td><strong><?= e($product['code']) ?></strong></td>
                        <td>
                            <?= e($product['name']) ?>
                            <small class="block muted"><?= e($product['category']) ?> · <?= e($product['unit']) ?></small>
                        </td>
                        <td class="right">
                            <span class="stock <?= $isLow ? 'low' : '' ?>"><?= $stock ?></span>
                        </td>
                        <td class="right"><?= $minStock ?></td>
                        <td class="right">
                            <form method="post" class="inventory-inline-update">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                <label class="sr-only" for="stock-<?= (int) $product['id'] ?>">Tồn hiện tại</label>
                                <input id="stock-<?= (int) $product['id'] ?>" type="number" name="stock" min="0" value="<?= $stock ?>">
                        </td>
                        <td class="right">
                                <label class="sr-only" for="min-stock-<?= (int) $product['id'] ?>">Mức cảnh báo</label>
                                <input id="min-stock-<?= (int) $product['id'] ?>" type="number" name="min_stock" min="0" value="<?= $minStock ?>">
                        </td>
                        <td class="right">
                                <button class="btn small primary">Lưu</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalProducts > $perPage): ?>
        <nav class="pagination" aria-label="Phân trang tồn kho">
            <?php if ($page > 1): ?>
                <a href="<?= e(inventory_page_url($q, $category, $filter, $page - 1)) ?>">« Trước</a>
            <?php else: ?>
                <span class="disabled">« Trước</span>
            <?php endif; ?>

            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="<?= $p === $page ? 'active' : '' ?>" href="<?= e(inventory_page_url($q, $category, $filter, $p)) ?>"><?= $p ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= e(inventory_page_url($q, $category, $filter, $page + 1)) ?>">Sau »</a>
            <?php else: ?>
                <span class="disabled">Sau »</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
