<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/products.php');
    exit;
}

verify_csrf();
$action = (string) ($_POST['action'] ?? '');
$id = (int) ($_POST['id'] ?? 0);
$pdo = db();

try {
    if ($action === 'toggle_status') {
        if ($id <= 0) {
            throw new RuntimeException('Sản phẩm không hợp lệ.');
        }

        $stmt = $pdo->prepare('SELECT id, code, name, status FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new RuntimeException('Không tìm thấy sản phẩm.');
        }

        $newStatus = (int) $product['status'] === 1 ? 0 : 1;
        $update = $pdo->prepare('UPDATE products SET status = ? WHERE id = ?');
        $update->execute([$newStatus, $id]);

        $actionText = $newStatus === 1 ? 'Kích hoạt lại' : 'Ngừng bán';
        log_activity($pdo, $newStatus === 1 ? 'product_activate' : 'product_deactivate', 'product', $id, $actionText . ' sản phẩm ' . $product['code']);
        $_SESSION['success'] = $newStatus === 1
            ? 'Đã đưa sản phẩm trở lại danh sách bán hàng.'
            : 'Đã chuyển sản phẩm sang trạng thái ngừng kinh doanh.';

        header('Location: ' . BASE_URL . '/products.php?status=all');
        exit;
    }

    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $name = trim((string) ($_POST['name'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $unit = trim((string) ($_POST['unit'] ?? ''));
    $priceRaw = $_POST['price'] ?? null;
    $stockRaw = $_POST['stock'] ?? null;
    $minStockRaw = $_POST['min_stock'] ?? null;

    if ($code === '' || $name === '' || $category === '' || $unit === '') {
        throw new RuntimeException('Vui lòng nhập đầy đủ thông tin sản phẩm.');
    }
    if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $code)) {
        throw new RuntimeException('Mã sản phẩm chỉ gồm chữ in hoa, số, dấu gạch dưới hoặc gạch ngang.');
    }
    if (!is_numeric($priceRaw) || !is_numeric($stockRaw) || !is_numeric($minStockRaw)) {
        throw new RuntimeException('Giá bán, tồn kho và mức cảnh báo phải là số hợp lệ.');
    }

    $price = (float) $priceRaw;
    $stock = (int) $stockRaw;
    $minStock = (int) $minStockRaw;

    if ($price <= 0) {
        throw new RuntimeException('Giá bán phải lớn hơn 0.');
    }
    if ($stock < 0 || $minStock < 0) {
        throw new RuntimeException('Số lượng tồn và mức cảnh báo không được âm.');
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare(
            'INSERT INTO products (code, name, category, price, stock, min_stock, unit, status) VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$code, $name, $category, $price, $stock, $minStock, $unit]);
        $id = (int) $pdo->lastInsertId();
        log_activity($pdo, 'product_create', 'product', $id, 'Thêm sản phẩm ' . $code . ' - ' . $name);
        $_SESSION['success'] = 'Đã thêm sản phẩm mới.';
    } elseif ($action === 'update' && $id > 0) {
        $check = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
        $check->execute([$id]);
        if (!$check->fetch()) {
            throw new RuntimeException('Không tìm thấy sản phẩm cần cập nhật.');
        }

        $stmt = $pdo->prepare(
            'UPDATE products SET code = ?, name = ?, category = ?, price = ?, stock = ?, min_stock = ?, unit = ? WHERE id = ?'
        );
        $stmt->execute([$code, $name, $category, $price, $stock, $minStock, $unit, $id]);
        log_activity($pdo, 'product_update', 'product', $id, 'Cập nhật sản phẩm ' . $code . ' - ' . $name);
        $_SESSION['success'] = 'Đã cập nhật thông tin sản phẩm.';
    } else {
        throw new RuntimeException('Thao tác không hợp lệ.');
    }
} catch (PDOException $exception) {
    $_SESSION['error'] = $exception->getCode() === '23000'
        ? 'Mã sản phẩm đã tồn tại. Vui lòng sử dụng mã khác.'
        : 'Không thể lưu sản phẩm. Vui lòng thử lại.';
} catch (RuntimeException $exception) {
    $_SESSION['error'] = $exception->getMessage();
}

header('Location: ' . BASE_URL . '/products.php' . ($id > 0 && $action === 'update' ? '?edit=' . $id : ''));
exit;
