<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/sales.php');
    exit;
}

verify_csrf();

$checkoutToken = (string) ($_POST['checkout_token'] ?? '');
$sessionCheckoutToken = (string) ($_SESSION['checkout_token'] ?? '');

if ($checkoutToken === '' || $sessionCheckoutToken === '' || !hash_equals($sessionCheckoutToken, $checkoutToken)) {
    $_SESSION['error'] = 'Phiên thanh toán không hợp lệ hoặc hóa đơn đã được xử lý. Vui lòng tạo lại hóa đơn.';
    header('Location: ' . BASE_URL . '/sales.php');
    exit;
}

$cartJson = (string) ($_POST['cart_json'] ?? '[]');
$cart = json_decode($cartJson, true);
$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$customerMoneyRaw = $_POST['customer_money'] ?? null;

if (!is_array($cart) || $cart === []) {
    $_SESSION['error'] = 'Hóa đơn chưa có sản phẩm.';
    header('Location: ' . BASE_URL . '/sales.php');
    exit;
}
if (count($cart) > 100) {
    $_SESSION['error'] = 'Hóa đơn có quá nhiều dòng sản phẩm.';
    header('Location: ' . BASE_URL . '/sales.php');
    exit;
}
if (!is_numeric($customerMoneyRaw)) {
    $_SESSION['error'] = 'Tiền khách đưa không hợp lệ.';
    header('Location: ' . BASE_URL . '/sales.php');
    exit;
}

$customerMoney = (float) $customerMoneyRaw;
if ($customerMoney < 0) {
    $_SESSION['error'] = 'Tiền khách đưa không được âm.';
    header('Location: ' . BASE_URL . '/sales.php');
    exit;
}

// Gộp các dòng trùng sản phẩm để tránh sửa dữ liệu giỏ hàng từ trình duyệt.
$quantities = [];
foreach ($cart as $row) {
    $productId = (int) ($row['id'] ?? 0);
    $quantity = (int) ($row['quantity'] ?? 0);

    if ($productId <= 0 || $quantity <= 0 || $quantity > 10000) {
        $_SESSION['error'] = 'Dữ liệu giỏ hàng không hợp lệ.';
        header('Location: ' . BASE_URL . '/sales.php');
        exit;
    }

    $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
}

$pdo = db();

try {
    $pdo->beginTransaction();
    $items = [];
    $total = 0.0;

    $productStmt = $pdo->prepare(
        'SELECT id, code, name, price, stock FROM products WHERE id = ? AND status = 1 FOR UPDATE'
    );

    foreach ($quantities as $productId => $quantity) {
        $productStmt->execute([(int) $productId]);
        $product = $productStmt->fetch();

        if (!$product) {
            throw new DomainException('Có sản phẩm đã ngừng bán hoặc không còn tồn tại trên hệ thống.');
        }
        if ((int) $product['stock'] < $quantity) {
            throw new DomainException(
                'Sản phẩm ' . $product['name'] . ' chỉ còn ' . (int) $product['stock'] . ' sản phẩm. Vui lòng điều chỉnh giỏ hàng.'
            );
        }

        $subtotal = (float) $product['price'] * $quantity;
        $total += $subtotal;
        $items[] = [
            'product' => $product,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
        ];
    }

    if ($customerMoney < $total) {
        throw new DomainException('Tiền khách đưa chưa đủ để thanh toán hóa đơn.');
    }

    $invoiceCode = 'HD' . date('YmdHis') . random_int(100, 999);
    $changeMoney = $customerMoney - $total;

    $invoiceStmt = $pdo->prepare(
        "INSERT INTO invoices (invoice_code, checkout_token, user_id, customer_name, total_amount, customer_money, change_money, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'paid')"
    );
    $invoiceStmt->execute([
        $invoiceCode,
        $checkoutToken,
        (int) current_user()['id'],
        $customerName !== '' ? $customerName : null,
        $total,
        $customerMoney,
        $changeMoney,
    ]);
    $invoiceId = (int) $pdo->lastInsertId();

    $detailStmt = $pdo->prepare(
        'INSERT INTO invoice_details (invoice_id, product_id, product_code, product_name, quantity, price, subtotal)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');

    foreach ($items as $item) {
        $product = $item['product'];
        $detailStmt->execute([
            $invoiceId,
            $product['id'],
            $product['code'],
            $product['name'],
            $item['quantity'],
            $product['price'],
            $item['subtotal'],
        ]);

        $stockStmt->execute([$item['quantity'], $product['id'], $item['quantity']]);
        if ($stockStmt->rowCount() !== 1) {
            throw new DomainException('Tồn kho vừa thay đổi. Vui lòng kiểm tra lại hóa đơn.');
        }
    }

    log_activity(
        $pdo,
        'invoice_create',
        'invoice',
        $invoiceId,
        'Tạo hóa đơn ' . $invoiceCode . ' trị giá ' . format_money($total)
    );

    $pdo->commit();
    unset($_SESSION['checkout_token']);

    $_SESSION['success'] = 'Thanh toán thành công. Hóa đơn ' . $invoiceCode . ' đã được lưu.';
    header('Location: ' . BASE_URL . '/invoice_detail.php?id=' . $invoiceId);
    exit;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($exception instanceof DomainException) {
        $_SESSION['error'] = $exception->getMessage();
    } elseif ($exception instanceof PDOException && $exception->getCode() === '23000') {
        $_SESSION['error'] = 'Hóa đơn này đã được xử lý. Hệ thống đã ngăn tạo hóa đơn trùng.';
    } else {
        $_SESSION['error'] = 'Không thể hoàn tất giao dịch. Vui lòng thử lại.';
    }

    header('Location: ' . BASE_URL . '/sales.php');
    exit;
}
