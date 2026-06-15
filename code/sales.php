<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$_SESSION['cart'] ??= [];
$_SESSION['checkout_token'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $id = (int) ($_POST['product_id'] ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        $stmt = $pdo->prepare('SELECT id, code, name, price, stock FROM products WHERE id=? AND status=1');
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product || (int) $product['stock'] < $quantity) {
            flash('error', 'Sản phẩm không tồn tại hoặc không đủ hàng.');
        } else {
            $current = (int) ($_SESSION['cart'][$id]['quantity'] ?? 0);
            if ($current + $quantity > (int) $product['stock']) {
                flash('error', 'Số lượng trong giỏ vượt quá tồn kho.');
            } else {
                $_SESSION['cart'][$id] = [
                    'id' => (int) $product['id'],
                    'code' => $product['code'],
                    'name' => $product['name'],
                    'price' => (float) $product['price'],
                    'quantity' => $current + $quantity,
                ];
                flash('success', 'Đã thêm sản phẩm vào giỏ hàng.');
            }
        }
        redirect('sales.php');
    }

    if ($action === 'remove') {
        unset($_SESSION['cart'][(int) ($_POST['product_id'] ?? 0)]);
        redirect('sales.php');
    }

    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        $_SESSION['checkout_token'] = bin2hex(random_bytes(32));
        redirect('sales.php');
    }

    if ($action === 'checkout') {
        $cart = $_SESSION['cart'];
        $customerName = trim((string) ($_POST['customer_name'] ?? '')) ?: 'Khách lẻ';
        $customerMoney = filter_var($_POST['customer_money'] ?? null, FILTER_VALIDATE_FLOAT);
        $postedToken = (string) ($_POST['checkout_token'] ?? '');

        if (!$cart) {
            flash('error', 'Giỏ hàng đang trống.');
            redirect('sales.php');
        }
        if ($postedToken === '' || !hash_equals((string) $_SESSION['checkout_token'], $postedToken)) {
            flash('error', 'Giao dịch đã được xử lý hoặc phiên thanh toán không hợp lệ.');
            redirect('sales.php');
        }

        try {
            $pdo->beginTransaction();
            $items = [];
            $total = 0.0;

            foreach ($cart as $cartItem) {
                $stmt = $pdo->prepare('SELECT id, code, name, price, stock, status FROM products WHERE id=? FOR UPDATE');
                $stmt->execute([(int) $cartItem['id']]);
                $product = $stmt->fetch();
                $quantity = (int) $cartItem['quantity'];

                if (!$product || (int) $product['status'] !== 1 || (int) $product['stock'] < $quantity) {
                    throw new RuntimeException('Sản phẩm ' . ($cartItem['name'] ?? '') . ' không còn đủ số lượng.');
                }

                $subtotal = (float) $product['price'] * $quantity;
                $total += $subtotal;
                $items[] = [$product, $quantity, $subtotal];
            }

            if ($customerMoney === false || $customerMoney < $total) {
                throw new RuntimeException('Tiền khách đưa chưa đủ để thanh toán.');
            }

            $invoiceCode = 'HD' . date('YmdHis') . random_int(10, 99);
            $stmt = $pdo->prepare('INSERT INTO invoices(invoice_code,checkout_token,user_id,customer_name,total_amount,customer_money,change_money,status) VALUES(?,?,?,?,?,?,?,\'paid\')');
            $stmt->execute([$invoiceCode, $postedToken, (int) current_user()['id'], $customerName, $total, $customerMoney, $customerMoney - $total]);
            $invoiceId = (int) $pdo->lastInsertId();

            $detail = $pdo->prepare('INSERT INTO invoice_details(invoice_id,product_id,product_code,product_name,quantity,price,subtotal) VALUES(?,?,?,?,?,?,?)');
            $updateStock = $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?');
            foreach ($items as [$product, $quantity, $subtotal]) {
                $detail->execute([$invoiceId, $product['id'], $product['code'], $product['name'], $quantity, $product['price'], $subtotal]);
                $updateStock->execute([$quantity, $product['id']]);
            }

            $pdo->commit();
            log_activity('invoice_create', 'Tạo hóa đơn ' . $invoiceCode . ' trị giá ' . money($total));
            $_SESSION['cart'] = [];
            $_SESSION['checkout_token'] = bin2hex(random_bytes(32));
            flash('success', 'Thanh toán thành công. Hóa đơn ' . $invoiceCode . ' đã được tạo.');
            redirect('invoices.php?id=' . $invoiceId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Không thể hoàn tất thanh toán.');
            redirect('sales.php');
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT * FROM products WHERE status=1 AND stock>0';
$params = [];
if ($q !== '') {
    $sql .= ' AND (code LIKE ? OR name LIKE ? OR category LIKE ?)';
    $s = '%' . $q . '%';
    $params = [$s, $s, $s];
}
$sql .= ' ORDER BY name LIMIT 50';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
$cart = $_SESSION['cart'];
$total = array_sum(array_map(fn(array $item): float => $item['price'] * $item['quantity'], $cart));

render_header('Bán hàng tại quầy', 'sales');
?>
<div class="sales-layout">
    <section class="panel">
        <div class="panel-heading responsive"><div><h2>Chọn sản phẩm</h2><p>Tìm kiếm và thêm sản phẩm vào giỏ</p></div><form method="get" class="filter-form"><input name="q" value="<?= e($q) ?>" placeholder="Nhập mã hoặc tên sản phẩm"><button class="btn secondary">Tìm</button></form></div>
        <div class="product-grid">
            <?php if (!$products): ?><p class="empty">Không tìm thấy sản phẩm còn hàng.</p><?php endif; ?>
            <?php foreach ($products as $product): ?>
                <article class="product-card">
                    <span class="product-code"><?= e($product['code']) ?></span>
                    <h3><?= e($product['name']) ?></h3>
                    <p><?= e($product['category']) ?> · Còn <?= (int) $product['stock'] ?> <?= e($product['unit']) ?></p>
                    <strong><?= money($product['price']) ?></strong>
                    <form method="post" class="add-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><input type="number" name="quantity" min="1" max="<?= (int) $product['stock'] ?>" value="1"><button class="btn primary">Thêm</button></form>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="panel cart-panel">
        <div class="panel-heading"><div><h2>Giỏ hàng</h2><p><?= count($cart) ?> loại sản phẩm</p></div></div>
        <div class="cart-list">
            <?php if (!$cart): ?><p class="empty">Chưa có sản phẩm trong giỏ.</p><?php endif; ?>
            <?php foreach ($cart as $item): ?>
                <div class="cart-item"><div><strong><?= e($item['name']) ?></strong><small><?= (int) $item['quantity'] ?> × <?= money($item['price']) ?></small></div><span><?= money($item['price'] * $item['quantity']) ?></span><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>"><button class="icon-button" title="Xóa">×</button></form></div>
            <?php endforeach; ?>
        </div>
        <div class="cart-total"><span>Tổng tiền</span><strong><?= money($total) ?></strong></div>
        <form method="post" class="form-grid one-column" id="checkout-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="checkout"><input type="hidden" name="checkout_token" value="<?= e($_SESSION['checkout_token']) ?>">
            <label>Tên khách hàng<input name="customer_name" placeholder="Khách lẻ"></label>
            <label>Tiền khách đưa<input type="number" name="customer_money" min="<?= (int) ceil($total) ?>" step="1000" required></label>
            <button class="btn primary full" type="submit" <?= !$cart ? 'disabled' : '' ?>>Thanh toán</button>
        </form>
        <?php if ($cart): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="clear"><button class="btn danger full" data-confirm="Xóa toàn bộ giỏ hàng?">Xóa giỏ hàng</button></form><?php endif; ?>
    </aside>
</div>
<script>
    document.getElementById('checkout-form')?.addEventListener('submit', function () {
        const button = this.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Đang xử lý...';
    });
</script>
<?php render_footer(); ?>
