<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_admin();

$pdo = db();

function product_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;

    if (!isset($cache[$key])) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    }

    return $cache[$key];
}

/*
 * Trong CSDL hiện tại, `products` là VIEW, bảng thật là `SanPham`.
 * Vì vậy muốn thêm cột hình ảnh phải ALTER TABLE SanPham, sau đó tạo lại VIEW products.
 */
if (!product_table_has_column($pdo, 'SanPham', 'hinhAnh')) {
    $pdo->exec("ALTER TABLE SanPham ADD hinhAnh VARCHAR(255) NULL AFTER donViTinh");
}

$pdo->exec("
    CREATE OR REPLACE VIEW products AS
    SELECT
        maSP AS id,
        maSanPham AS code,
        tenSP AS name,
        danhMuc AS category,
        donGia AS price,
        soLuongTon AS stock,
        mucCanhBao AS min_stock,
        donViTinh AS unit,
        hinhAnh AS image,
        trangThai AS status,
        ngayTao AS created_at,
        ngayCapNhat AS updated_at
    FROM SanPham
");

function product_upload_image(string $fieldName, ?string $currentImage = null): ?string
{
    if (!isset($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentImage;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Không thể tải hình sản phẩm lên.');
    }

    $maxSize = 2 * 1024 * 1024;

    if ((int) $_FILES[$fieldName]['size'] > $maxSize) {
        throw new RuntimeException('Hình sản phẩm không được vượt quá 2MB.');
    }

    $tmpPath = (string) $_FILES[$fieldName]['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Chỉ hỗ trợ hình JPG, PNG hoặc WEBP.');
    }

    $uploadDir = __DIR__ . '/uploads/products';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $fileName = 'product_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Không thể lưu hình sản phẩm.');
    }

    if ($currentImage && str_starts_with($currentImage, 'uploads/products/')) {
        $oldPath = __DIR__ . '/' . $currentImage;

        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    return 'uploads/products/' . $fileName;
}

function product_image_html(array $product, string $fallbackIcon): string
{
    $image = trim((string) ($product['image'] ?? ''));

    if ($image !== '') {
        return '<img src="' . e(url($image)) . '" alt="' . e((string) $product['name']) . '">';
    }

    return e($fallbackIcon);
}

function product_page_icon(string $name, string $category): string
{
    $text = strtolower($name . ' ' . $category);

    if (str_contains($text, 'sữa') || str_contains($text, 'sua')) return '🥛';
    if (str_contains($text, 'nước') || str_contains($text, 'nuoc') || str_contains($text, 'đồ uống') || str_contains($text, 'do uong') || str_contains($text, 'cà phê')) return '🥤';
    if (str_contains($text, 'bánh') || str_contains($text, 'banh') || str_contains($text, 'kẹo')) return '🍪';
    if (str_contains($text, 'gia dụng') || str_contains($text, 'gia dung') || str_contains($text, 'khăn')) return '🧴';
    if (str_contains($text, 'thực phẩm') || str_contains($text, 'thuc pham') || str_contains($text, 'mì')) return '🍱';
    if (str_contains($text, 'văn phòng') || str_contains($text, 'van phong') || str_contains($text, 'bút') || str_contains($text, 'sách')) return '📚';
    if (str_contains($text, 'đông lạnh') || str_contains($text, 'dong lanh')) return '🧊';

    return '🛒';
}

function products_page_url(array $baseParams, int $page): string
{
    $baseParams['page'] = $page;
    return 'products.php?' . http_build_query($baseParams);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    try {
        if ($action === 'toggle') {
            $stmt = $pdo->prepare('SELECT code,status FROM products WHERE id=?');
            $stmt->execute([$id]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new RuntimeException('Không tìm thấy sản phẩm.');
            }

            $status = (int) $product['status'] === 1 ? 0 : 1;

            $pdo->prepare('UPDATE products SET status=?, updated_at=NOW() WHERE id=?')
                ->execute([$status, $id]);

            log_activity('product_status', ($status ? 'Kích hoạt' : 'Ngừng bán') . ' sản phẩm ' . $product['code']);
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
        $currentImage = null;

        if ($action === 'update' && $id > 0) {
            $imageStmt = $pdo->prepare('SELECT image FROM products WHERE id=?');
            $imageStmt->execute([$id]);
            $currentImage = (string) ($imageStmt->fetchColumn() ?: '');
        }

        $image = product_upload_image('image', $currentImage);

        if ($code === '' || $name === '' || $category === '' || $unit === '' || $price === false || $stock === false || $minStock === false) {
            throw new RuntimeException('Vui lòng nhập đầy đủ và đúng định dạng.');
        }

        if ($price <= 0 || $stock < 0 || $minStock < 0) {
            throw new RuntimeException('Giá phải lớn hơn 0, số lượng không được âm.');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO products(code,name,category,price,stock,min_stock,unit,image,status) VALUES(?,?,?,?,?,?,?,?,1)');
            $stmt->execute([$code, $name, $category, $price, $stock, $minStock, $unit, $image]);

            log_activity('product_create', 'Thêm sản phẩm ' . $code . ' - ' . $name);
            flash('success', 'Đã thêm sản phẩm mới.');
        } elseif ($action === 'update' && $id > 0) {
            $stmt = $pdo->prepare('UPDATE products SET code=?,name=?,category=?,price=?,stock=?,min_stock=?,unit=?,image=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([$code, $name, $category, $price, $stock, $minStock, $unit, $image, $id]);

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
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id=?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;

    if (!$edit) {
        flash('error', 'Không tìm thấy sản phẩm cần sửa.');
        redirect('products.php');
    }
}

$categories = $pdo
    ->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category<>'' ORDER BY category")
    ->fetchAll(PDO::FETCH_COLUMN);

$summary = [
    'total' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'active' => (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status=1')->fetchColumn(),
    'low_stock' => (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status=1 AND stock<=min_stock')->fetchColumn(),
    'inventory_value' => (float) $pdo->query('SELECT COALESCE(SUM(price*stock),0) FROM products WHERE status=1')->fetchColumn(),
];

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(code LIKE :q OR name LIKE :q OR category LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if ($categoryFilter !== '') {
    $where[] = 'category = :category';
    $params[':category'] = $categoryFilter;
}

if ($status === 'active') {
    $where[] = 'status=1';
} elseif ($status === 'inactive') {
    $where[] = 'status=0';
} elseif ($status === 'low') {
    $where[] = 'status=1 AND stock<=min_stock';
} else {
    $status = 'all';
}

$whereSql = implode(' AND ', $where);

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

$sql = "SELECT * FROM products WHERE {$whereSql} ORDER BY id DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$products = $stmt->fetchAll();

$paginationParams = array_filter([
    'q' => $q,
    'status' => $status !== 'all' ? $status : '',
    'category' => $categoryFilter,
], fn($value) => $value !== '');

render_header('Quản lý sản phẩm', 'products');
?>

<div class="product-manager-page">
    <section class="panel product-hero-panel">
        <div>
            <span class="eyebrow">DANH MỤC HÀNG HÓA</span>
            <h2>Quản lý sản phẩm, giá bán và tồn kho trong một màn hình.</h2>
            <p>Thêm sản phẩm mới, cập nhật tồn kho, đặt mức cảnh báo và theo dõi trạng thái kinh doanh.</p>
        </div>

        <a class="btn primary" href="#product-form-card">
            <?= $edit ? 'Đang sửa sản phẩm' : '+ Thêm sản phẩm' ?>
        </a>
    </section>

    <div class="stats-grid product-summary-grid">
        <article class="mini-stat">
            <span>Tổng sản phẩm</span>
            <strong><?= (int) $summary['total'] ?></strong>
            <small>Toàn bộ danh mục</small>
        </article>

        <article class="mini-stat">
            <span>Đang bán</span>
            <strong><?= (int) $summary['active'] ?></strong>
            <small>Sản phẩm còn hoạt động</small>
        </article>

        <article class="mini-stat warning">
            <span>Sắp hết hàng</span>
            <strong><?= (int) $summary['low_stock'] ?></strong>
            <small>Tồn kho ≤ mức cảnh báo</small>
        </article>

        <article class="mini-stat">
            <span>Giá trị tồn kho</span>
            <strong><?= money((float) $summary['inventory_value']) ?></strong>
            <small>Tính theo giá bán hiện tại</small>
        </article>
    </div>

    <section class="panel product-form-card" id="product-form-card">
        <div class="panel-heading product-form-heading">
            <div>
                <h2><?= $edit ? 'Cập nhật sản phẩm' : 'Thêm sản phẩm mới' ?></h2>
                <p><?= $edit ? 'Bạn đang chỉnh sửa mã ' . e((string) $edit['code']) : 'Nhập thông tin hàng hóa, giá bán và mức cảnh báo tồn kho.' ?></p>
            </div>

            <?php if ($edit): ?>
                <a class="btn ghost" href="<?= e(url('products.php')) ?>">Hủy sửa</a>
            <?php endif; ?>
        </div>

        <form method="post" class="product-form-grid product-form-with-image" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

            <label>
                Mã sản phẩm
                <input name="code" value="<?= e($edit['code'] ?? '') ?>" placeholder="VD: SP001" required>
            </label>

            <label class="wide-field">
                Tên sản phẩm
                <input name="name" value="<?= e($edit['name'] ?? '') ?>" placeholder="VD: Nước suối 500ml" required>
            </label>

            <label>
                Danh mục
                <input name="category" list="category-list" value="<?= e($edit['category'] ?? '') ?>" placeholder="VD: Đồ uống" required>
                <datalist id="category-list">
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </label>

            <label>
                Đơn vị tính
                <input name="unit" value="<?= e($edit['unit'] ?? 'Cái') ?>" placeholder="Cái, chai, hộp..." required>
            </label>

            <label>
                Giá bán
                <input type="number" name="price" min="1" step="1" value="<?= e($edit['price'] ?? '') ?>" required>
            </label>

            <label>
                Số lượng tồn
                <input type="number" name="stock" min="0" value="<?= e($edit['stock'] ?? 0) ?>" required>
            </label>

            <label>
                Mức cảnh báo
                <input type="number" name="min_stock" min="0" value="<?= e($edit['min_stock'] ?? 5) ?>" required>
            </label>

            <label class="product-image-field">
                Hình sản phẩm
                <span class="product-image-upload-card">
                    <?php if (!empty($edit['image'])): ?>
                        <img src="<?= e(url((string) $edit['image'])) ?>" alt="Hình sản phẩm hiện tại">
                    <?php else: ?>
                        <span class="image-placeholder">🖼️</span>
                    <?php endif; ?>

                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
                </span>
                <small class="muted">JPG, PNG hoặc WEBP · tối đa 2MB</small>
            </label>

            <div class="product-form-actions">
                <button class="btn primary" type="submit"><?= $edit ? 'Lưu thay đổi' : 'Thêm sản phẩm' ?></button>

                <?php if (!$edit): ?>
                    <button class="btn ghost" type="reset">Làm mới</button>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel product-list-card">
        <div class="panel-heading product-list-heading">
            <div>
                <h2>Danh sách sản phẩm</h2>
                <p><?= $totalProducts ?> sản phẩm phù hợp với bộ lọc hiện tại</p>
            </div>
        </div>

        <form method="get" class="filter-form product-filter-form">
            <label>
                Tìm kiếm
                <input name="q" value="<?= e($q) ?>" placeholder="Tìm mã, tên, danh mục">
            </label>

            <label>
                Danh mục
                <select name="category">
                    <option value="">Tất cả danh mục</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>>
                            <?= e($category) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Trạng thái
                <select name="status">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Tất cả</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Đang bán</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Ngừng bán</option>
                    <option value="low" <?= $status === 'low' ? 'selected' : '' ?>>Sắp hết hàng</option>
                </select>
            </label>

            <button class="btn secondary">Lọc</button>

            <?php if ($q !== '' || $status !== 'all' || $categoryFilter !== ''): ?>
                <a class="btn ghost" href="<?= e(url('products.php')) ?>">Xóa lọc</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap product-table-wrap">
            <table class="product-table-modern">
                <thead>
                    <tr>
                        <th>Sản phẩm</th>
                        <th>Danh mục</th>
                        <th class="right">Giá bán</th>
                        <th class="right">Tồn kho</th>
                        <th>Trạng thái</th>
                        <th class="right">Thao tác</th>
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
                            $productId = (int) $product['id'];
                            $isActive = (int) $product['status'] === 1;
                            $isLowStock = (int) $product['stock'] <= (int) $product['min_stock'];
                            $icon = product_page_icon((string) $product['name'], (string) $product['category']);
                        ?>

                        <tr class="<?= $isLowStock && $isActive ? 'low-stock-row' : '' ?>">
                            <td>
                                <div class="product-name-cell modern-name-cell">
                                    <span class="product-icon product-photo"><?= product_image_html($product, $icon) ?></span>
                                    <div>
                                        <strong><?= e($product['name']) ?></strong>
                                        <small class="block muted"><?= e($product['code']) ?> · <?= e($product['unit']) ?></small>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <span class="category-pill"><?= e($product['category']) ?></span>
                            </td>

                            <td class="right">
                                <strong class="price-text"><?= money($product['price']) ?></strong>
                            </td>

                            <td class="right">
                                <span class="stock-pill <?= $isLowStock ? 'low' : '' ?>">
                                    <?= (int) $product['stock'] ?>
                                </span>
                                <small class="block muted">Cảnh báo: <?= (int) $product['min_stock'] ?></small>
                            </td>

                            <td>
                                <span class="status <?= $isActive ? 'paid' : 'cancelled' ?>">
                                    <?= $isActive ? 'Đang bán' : 'Ngừng bán' ?>
                                </span>
                            </td>

                            <td class="right">
                                <div class="table-actions product-actions">
                                    <a class="btn small ghost" href="<?= e(url('products.php?edit=' . $productId)) ?>">Sửa</a>

                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $productId ?>">
                                        <button
                                            class="btn small <?= $isActive ? 'danger' : 'secondary' ?>"
                                            data-confirm="Xác nhận thay đổi trạng thái sản phẩm?"
                                        >
                                            <?= $isActive ? 'Ngừng bán' : 'Kích hoạt' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination product-pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= e(products_page_url($paginationParams, $page - 1)) ?>">« Trước</a>
                <?php else: ?>
                    <span class="disabled">« Trước</span>
                <?php endif; ?>

                <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= e(products_page_url($paginationParams, $i)) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(products_page_url($paginationParams, $page + 1)) ?>">Sau »</a>
                <?php else: ?>
                    <span class="disabled">Sau »</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</div>

<?php render_footer(); ?>
