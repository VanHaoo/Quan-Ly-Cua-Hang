<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/services/sales_service.php';

require_login();

$pdo = db();
$_SESSION['cart'] ??= [];
$_SESSION['checkout_token'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

        $stmt = $pdo->prepare(
            'SELECT id, code, name, price, stock
             FROM products
             WHERE id = ? AND status = 1'
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product || (int) $product['stock'] < $quantity) {
            flash('error', 'Sản phẩm không tồn tại hoặc không đủ hàng.');
        } else {
            $currentQuantity = (int) ($_SESSION['cart'][$productId]['quantity'] ?? 0);

            if ($currentQuantity + $quantity > (int) $product['stock']) {
                flash('error', 'Số lượng trong giỏ vượt quá tồn kho.');
            } else {
                $_SESSION['cart'][$productId] = [
                    'id' => (int) $product['id'],
                    'code' => (string) $product['code'],
                    'name' => (string) $product['name'],
                    'price' => (float) $product['price'],
                    'quantity' => $currentQuantity + $quantity,
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
        try {
            $result = checkout_order(
                $pdo,
                $_SESSION['cart'],
                $_POST,
                (int) current_user()['id'],
                (string) $_SESSION['checkout_token']
            );

            log_activity(
                'invoice_create',
                'Tạo hóa đơn '
                . $result['invoice_code']
                . ' trị giá '
                . money($result['total_amount'])
            );

            $_SESSION['cart'] = [];
            $_SESSION['checkout_token'] = bin2hex(random_bytes(32));
            flash('success', $result['message']);
            redirect('invoices.php?id=' . $result['invoice_id']);
        } catch (Throwable $exception) {
            flash(
                'error',
                $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Không thể hoàn tất thanh toán.'
            );
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
$subtotalAmount = array_sum(array_map(fn(array $item): float => $item['price'] * $item['quantity'], $cart));

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

        <div class="cart-summary">
            <p><span>Tạm tính</span><strong><?= money($subtotalAmount) ?></strong></p>
            <p id="discount-row" hidden><span>Giảm giá</span><strong id="discount-value">0 đ</strong></p>
            <p class="payable"><span>Khách cần trả</span><strong id="payable-value"><?= money($subtotalAmount) ?></strong></p>
        </div>

        <form method="post" class="form-grid one-column" id="checkout-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="checkout"><input type="hidden" name="checkout_token" value="<?= e($_SESSION['checkout_token']) ?>">

            <div class="member-section">
                <div class="member-title"><strong>Thông tin khách hàng</strong><small>Để trống số điện thoại nếu là khách lẻ</small></div>
                <label>Số điện thoại thành viên
                    <div class="inline-field"><input id="customer-phone" name="customer_phone" inputmode="numeric" maxlength="11" placeholder="Ví dụ 0901234567"><button type="button" class="btn secondary" id="lookup-customer">Kiểm tra</button></div>
                </label>
                <label>Tên khách hàng<input id="customer-name" name="customer_name" placeholder="Khách lẻ"></label>
                <div id="customer-info" class="customer-info muted">Khách thành viên được cộng 1 điểm cho mỗi 10.000 đ. Đủ 100 điểm sẽ nhận voucher giảm 20.000 đ.</div>
                <label>Mã voucher
                    <input id="voucher-code" name="voucher_code" list="voucher-list" placeholder="Không bắt buộc" autocomplete="off">
                    <datalist id="voucher-list"></datalist>
                </label>
            </div>

            <label>Tiền khách đưa<input id="customer-money" type="number" name="customer_money" min="<?= (int) ceil($subtotalAmount) ?>" step="1000" required></label>
            <div class="change-preview"><span>Tiền thừa dự kiến</span><strong id="change-value">0 đ</strong></div>
            <button class="btn primary full" type="submit" <?= !$cart ? 'disabled' : '' ?>>Thanh toán</button>
        </form>
        <?php if ($cart): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="clear"><button class="btn danger full" data-confirm="Xóa toàn bộ giỏ hàng?">Xóa giỏ hàng</button></form><?php endif; ?>
    </aside>
</div>
<script>
(() => {
    const subtotal = <?= json_encode((float) $subtotalAmount) ?>;
    const phoneInput = document.getElementById('customer-phone');
    const nameInput = document.getElementById('customer-name');
    const voucherInput = document.getElementById('voucher-code');
    const voucherList = document.getElementById('voucher-list');
    const customerInfo = document.getElementById('customer-info');
    const discountRow = document.getElementById('discount-row');
    const discountValue = document.getElementById('discount-value');
    const payableValue = document.getElementById('payable-value');
    const moneyInput = document.getElementById('customer-money');
    const changeValue = document.getElementById('change-value');
    const lookupButton = document.getElementById('lookup-customer');
    const vouchers = new Map();
    let payable = subtotal;

    const formatMoney = value => new Intl.NumberFormat('vi-VN').format(Math.max(0, Math.round(value))) + ' đ';

    function refreshPayment() {
        const code = voucherInput.value.trim().toUpperCase();
        const voucher = vouchers.get(code);
        let discount = 0;

        if (voucher && subtotal >= Number(voucher.min_order)) {
            discount = voucher.discount_type === 'percent'
                ? subtotal * Number(voucher.discount_value) / 100
                : Number(voucher.discount_value);
        }
        discount = Math.min(discount, subtotal);
        payable = Math.max(0, subtotal - discount);

        discountRow.hidden = discount <= 0;
        discountValue.textContent = '- ' + formatMoney(discount);
        payableValue.textContent = formatMoney(payable);
        moneyInput.min = String(Math.ceil(payable));

        const given = Number(moneyInput.value || 0);
        changeValue.textContent = formatMoney(Math.max(0, given - payable));
    }

    async function lookupCustomer() {
        const phone = phoneInput.value.replace(/\D/g, '');
        phoneInput.value = phone;
        vouchers.clear();
        voucherList.innerHTML = '';
        voucherInput.value = '';
        refreshPayment();

        if (!phone) {
            customerInfo.textContent = 'Khách lẻ không tích điểm và không sử dụng voucher.';
            return;
        }

        customerInfo.textContent = 'Đang kiểm tra khách hàng...';
        try {
            const response = await fetch('api/customer_lookup.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-Token': <?= json_encode(csrf_token()) ?>
                },
                body: new URLSearchParams({phone})
            });
            const data = await response.json();

            if (!data.found) {
                customerInfo.textContent = data.message;
                nameInput.value = '';
                return;
            }

            nameInput.value = data.customer.name;
            customerInfo.textContent = `${data.customer.name} · ${data.customer.points} điểm · Tổng chi tiêu ${formatMoney(data.customer.total_spent)}`;

            data.vouchers.forEach(voucher => {
                vouchers.set(voucher.code.toUpperCase(), voucher);
                const option = document.createElement('option');
                const valueText = voucher.discount_type === 'percent'
                    ? `${Number(voucher.discount_value)}%`
                    : formatMoney(voucher.discount_value);
                option.value = voucher.code;
                option.label = `${voucher.name} · Giảm ${valueText} · Đơn từ ${formatMoney(voucher.min_order)}`;
                voucherList.appendChild(option);
            });

            if (!data.vouchers.length) {
                customerInfo.textContent += ' · Chưa có voucher khả dụng';
            }
        } catch (error) {
            customerInfo.textContent = 'Không thể tra cứu khách hàng. Bạn vẫn có thể tiếp tục thanh toán.';
        }
    }

    lookupButton?.addEventListener('click', lookupCustomer);
    phoneInput?.addEventListener('blur', lookupCustomer);
    voucherInput?.addEventListener('input', refreshPayment);
    moneyInput?.addEventListener('input', refreshPayment);
    refreshPayment();

    document.getElementById('checkout-form')?.addEventListener('submit', function () {
        const button = this.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Đang xử lý...';
    });
})();
</script>
<?php render_footer(); ?>
