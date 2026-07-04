<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/services/sales_service.php';
require_roles('admin', 'cashier');

$pdo = db();
$_SESSION['cart'] ??= [];
$_SESSION['checkout_token'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

        $stmt = $pdo->prepare('SELECT id,code,name,price,stock FROM products WHERE id=? AND status=1');
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

            log_activity('invoice_create', 'Tạo hóa đơn ' . $result['invoice_code'] . ' trị giá ' . money($result['total_amount']));

            $_SESSION['cart'] = [];
            $_SESSION['checkout_token'] = bin2hex(random_bytes(32));

            flash('success', $result['message']);
            redirect('invoices.php?id=' . $result['invoice_id']);
        } catch (Throwable $exception) {
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Không thể hoàn tất thanh toán.');
            redirect('sales.php');
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));

$categoryStmt = $pdo->query("
    SELECT DISTINCT category
    FROM products
    WHERE status=1
    AND category IS NOT NULL
    AND category <> ''
    ORDER BY category
");
$categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

$sql = 'SELECT * FROM products WHERE status=1 AND stock>0';
$params = [];

if ($q !== '') {
    $sql .= ' AND (code LIKE ? OR name LIKE ? OR category LIKE ?)';
    $s = '%' . $q . '%';
    $params = [$s, $s, $s];
}

if ($category !== '') {
    $sql .= ' AND category = ?';
    $params[] = $category;
}

$sql .= ' ORDER BY name LIMIT 60';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$cart = $_SESSION['cart'];
$cartItemCount = array_sum(array_map(fn(array $item): int => (int) $item['quantity'], $cart));
$subtotalAmount = array_sum(array_map(fn(array $item): float => $item['price'] * $item['quantity'], $cart));

render_header('Bán hàng tại quầy', 'sales');
?>

<div class="process-strip">
    <span class="done">1. Chọn sản phẩm</span>
    <span>2. Lập hóa đơn</span>
    <span>3. Thanh toán</span>
    <span>4. In hóa đơn</span>
</div>

<div class="sales-layout">
    <section class="panel sales-products-panel">
        <div class="panel-heading">
            <div>
                <h2>Chọn sản phẩm</h2>
                <p>Tìm kiếm, quét mã hoặc thêm sản phẩm vào giỏ</p>
            </div>
        </div>

        <form method="get" class="sales-search-bar">
            <div class="scan-field">
                <input
                    id="product-search-input"
                    name="q"
                    value="<?= e($q) ?>"
                    placeholder="Quét hoặc nhập mã, tên, danh mục sản phẩm"
                    autocomplete="off"
                    autofocus
                >
                <button type="button" class="btn secondary scan-btn" id="scan-focus-btn">📷 Quét mã</button>
            </div>

            <?php if ($category !== ''): ?>
                <input type="hidden" name="category" value="<?= e($category) ?>">
            <?php endif; ?>

            <button class="btn primary">Tìm</button>
        </form>

        <div class="category-tabs">
            <a class="<?= $category === '' ? 'active' : '' ?>" href="<?= e(url('sales.php' . ($q !== '' ? '?q=' . urlencode($q) : ''))) ?>">
                Tất cả
            </a>

            <?php foreach ($categories as $cat): ?>
                <?php
                    $query = http_build_query(array_filter([
                        'q' => $q,
                        'category' => $cat,
                    ], fn($value) => $value !== ''));
                ?>
                <a class="<?= $category === $cat ? 'active' : '' ?>" href="<?= e(url('sales.php' . ($query ? '?' . $query : ''))) ?>">
                    <?= e($cat) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="product-grid pos-product-grid">
            <?php if (!$products): ?>
                <p class="empty">Không tìm thấy sản phẩm còn hàng.</p>
            <?php endif; ?>

            <?php foreach ($products as $product): ?>
                <?php
                    $productId = (int) $product['id'];
                    $cartQty = (int) ($_SESSION['cart'][$productId]['quantity'] ?? 0);
                    $stock = (int) $product['stock'];
                    $minStock = (int) $product['min_stock'];
                    $isLowStock = $stock <= $minStock;
                    $lowerName = strtolower((string) $product['name']);
                    $lowerCategory = strtolower((string) $product['category']);

                    $icon = str_contains($lowerName, 'sữa') || str_contains($lowerName, 'sua') ? '🥛' :
                        (str_contains($lowerName, 'nước') || str_contains($lowerName, 'nuoc') || str_contains($lowerCategory, 'đồ uống') || str_contains($lowerCategory, 'do uong') ? '🥤' :
                        (str_contains($lowerName, 'bánh') || str_contains($lowerName, 'banh') || str_contains($lowerCategory, 'bánh') || str_contains($lowerCategory, 'banh') ? '🍪' :
                        (str_contains($lowerCategory, 'gia dụng') || str_contains($lowerCategory, 'gia dung') ? '🧴' :
                        (str_contains($lowerCategory, 'thực phẩm') || str_contains($lowerCategory, 'thuc pham') ? '🍱' : '🛒'))));
                ?>

                <article class="product-card pos-product-card <?= $cartQty > 0 ? 'in-cart' : '' ?> <?= $isLowStock ? 'low-stock-card' : '' ?>">
                    <?php if ($cartQty > 0): ?>
                        <span class="cart-badge">Đã chọn <?= $cartQty ?></span>
                    <?php endif; ?>

                    <?php if ($isLowStock): ?>
                        <span class="low-stock-badge">Sắp hết</span>
                    <?php endif; ?>

                    <div class="product-visual">
                        <span><?= $icon ?></span>
                    </div>

                    <div class="product-info">
                        <span class="product-code"><?= e($product['code']) ?></span>
                        <h3><?= e($product['name']) ?></h3>
                        <p class="product-category"><?= e($product['category']) ?></p>
                    </div>

                    <div class="product-meta-row">
                        <strong class="product-price"><?= money($product['price']) ?></strong>
                        <span class="stock-badge <?= $isLowStock ? 'warning' : '' ?>">
                            Còn <?= $stock ?> <?= e($product['unit']) ?>
                        </span>
                    </div>

                    <form method="post" class="add-form pos-add-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= $productId ?>">

                        <div class="qty-stepper">
                            <button type="button" class="qty-btn" data-step="-1">−</button>
                            <input type="number" name="quantity" min="1" max="<?= $stock ?>" value="1">
                            <button type="button" class="qty-btn" data-step="1">+</button>
                        </div>

                        <button class="btn primary add-cart-btn">Thêm</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="panel cart-panel pos-cart-panel">
        <div class="panel-heading">
            <div>
                <h2>Giỏ hàng & thanh toán</h2>
                <p><?= count($cart) ?> loại sản phẩm · <?= $cartItemCount ?> món</p>
            </div>
        </div>

        <div class="cart-section">
            <div class="cart-section-title">
                <strong>Sản phẩm đã chọn</strong>
                <small>Bắt buộc</small>
            </div>

            <div class="cart-list">
                <?php if (!$cart): ?>
                    <p class="empty">Chưa có sản phẩm trong giỏ.</p>
                <?php endif; ?>

                <?php foreach ($cart as $item): ?>
                    <div class="cart-item">
                        <div>
                            <strong><?= e($item['name']) ?></strong>
                            <small><?= (int) $item['quantity'] ?> × <?= money($item['price']) ?></small>
                        </div>
                        <span><?= money($item['price'] * $item['quantity']) ?></span>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                            <button class="icon-button" title="Xóa">×</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="post" class="form-grid one-column" id="checkout-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="checkout">
            <input type="hidden" name="checkout_token" value="<?= e($_SESSION['checkout_token']) ?>">

            <div class="cart-section">
                <div class="cart-section-title">
                    <strong>Khách hàng & ưu đãi</strong>
                    <small>Tùy chọn</small>
                </div>

                <div class="member-section">
                    <label>
                        Số điện thoại thành viên
                        <div class="inline-field">
                            <input id="customer-phone" name="customer_phone" inputmode="numeric" maxlength="11" placeholder="Ví dụ 0901234567">
                            <button type="button" class="btn secondary" id="lookup-customer">Kiểm tra</button>
                        </div>
                    </label>

                    <label>
                        Tên khách hàng
                        <input id="customer-name" name="customer_name" placeholder="Khách lẻ">
                    </label>

                    <div id="customer-info" class="customer-info muted">Nhập số điện thoại để kiểm tra điểm và voucher của khách hàng.</div>

                    <label>
                        Mã voucher
                        <input id="voucher-code" name="voucher_code" list="voucher-list" placeholder="Không bắt buộc" autocomplete="off">
                        <datalist id="voucher-list"></datalist>
                    </label>
                </div>
            </div>

            <div class="cart-section payment-method-grid">
                <div class="cart-section-title">
                    <strong>Thanh toán</strong>
                    <small>Bắt buộc</small>
                </div>

                <div class="cart-summary">
                    <p><span>Tạm tính</span><strong><?= money($subtotalAmount) ?></strong></p>
                    <p id="discount-row" hidden><span>Giảm giá</span><strong id="discount-value">0 đ</strong></p>
                    <p class="payable"><span>Khách cần trả</span><strong id="payable-value"><?= money($subtotalAmount) ?></strong></p>
                </div>

                <label>
                    Phương thức thanh toán
                    <select id="payment-method" name="payment_method">
                        <option value="cash">Tiền mặt</option>
                        <option value="transfer">Chuyển khoản/QR</option>
                    </select>
                </label>

                <div id="cash-payment-fields">
                    <label>
                        Tiền khách đưa
                        <input id="customer-money" type="number" name="customer_money" min="<?= (int) ceil($subtotalAmount) ?>" step="1000">
                    </label>

                    <div class="change-preview">
                        <span>Tiền thừa dự kiến</span>
                        <strong id="change-value">0 đ</strong>
                    </div>
                </div>

                <div id="non-cash-payment-box" class="non-cash-payment-box" hidden>
                    <div class="qr-preview">QR</div>

                    <div>
                        <strong>Thanh toán chuyển khoản/QR</strong>
                        <p>Khách chuyển hoặc quét QR đúng số tiền: <b id="non-cash-amount"><?= money($subtotalAmount) ?></b></p>
                        <small>Thu ngân kiểm tra giao dịch trước khi bấm lập hóa đơn.</small>
                    </div>
                </div>
            </div>

            <div class="payment-action-card">
                <div class="final-total">
                    <span>Tổng cần thu</span>
                    <strong id="checkout-total"><?= money($subtotalAmount) ?></strong>
                </div>
                <button class="btn primary full pay-btn" type="submit" <?= !$cart ? 'disabled' : '' ?>>Thanh toán và lập hóa đơn</button>
            </div>
        </form>

        <?php if ($cart): ?>
            <form method="post" class="clear-cart-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="clear">
                <button class="btn danger full" data-confirm="Xóa toàn bộ giỏ hàng?">Xóa giỏ hàng</button>
            </form>
        <?php endif; ?>
    </aside>
</div>

<script>
(() => {
    const subtotal = <?= json_encode((float) $subtotalAmount) ?>;
    const searchInput = document.getElementById('product-search-input');
    const scanFocusButton = document.getElementById('scan-focus-btn');
    const phoneInput = document.getElementById('customer-phone');
    const nameInput = document.getElementById('customer-name');
    const voucherInput = document.getElementById('voucher-code');
    const voucherList = document.getElementById('voucher-list');
    const customerInfo = document.getElementById('customer-info');
    const discountRow = document.getElementById('discount-row');
    const discountValue = document.getElementById('discount-value');
    const payableValue = document.getElementById('payable-value');
    const checkoutTotal = document.getElementById('checkout-total');
    const moneyInput = document.getElementById('customer-money');
    const changeValue = document.getElementById('change-value');
    const paymentMethod = document.getElementById('payment-method');
    const cashPaymentFields = document.getElementById('cash-payment-fields');
    const nonCashPaymentBox = document.getElementById('non-cash-payment-box');
    const nonCashAmount = document.getElementById('non-cash-amount');
    const lookupButton = document.getElementById('lookup-customer');
    const vouchers = new Map();
    let payable = subtotal;

    const formatMoney = value => new Intl.NumberFormat('vi-VN').format(Math.max(0, Math.round(value))) + ' đ';

    searchInput?.focus();

    scanFocusButton?.addEventListener('click', () => {
        searchInput?.focus();
        searchInput?.select();
    });

    document.addEventListener('click', event => {
        const button = event.target.closest('.qty-btn');
        if (!button) return;

        const stepper = button.closest('.qty-stepper');
        const input = stepper?.querySelector('input[type="number"]');
        if (!input) return;

        const step = Number(button.dataset.step || 0);
        const min = Number(input.min || 1);
        const max = Number(input.max || 9999);
        const current = Number(input.value || min);

        input.value = Math.min(max, Math.max(min, current + step));
    });

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
        checkoutTotal.textContent = formatMoney(payable);

        moneyInput.min = String(Math.ceil(payable));

        if (paymentMethod.value === 'cash') {
            cashPaymentFields.hidden = false;
            nonCashPaymentBox.hidden = true;

            moneyInput.disabled = false;
            moneyInput.required = true;

            const given = Number(moneyInput.value || 0);
            changeValue.textContent = formatMoney(Math.max(0, given - payable));
        } else {
            cashPaymentFields.hidden = true;
            nonCashPaymentBox.hidden = false;

            moneyInput.required = false;
            moneyInput.disabled = true;
            moneyInput.value = '';

            nonCashAmount.textContent = formatMoney(payable);
            changeValue.textContent = '0 đ';
        }
    }

    async function lookupCustomer() {
        const phone = phoneInput.value.replace(/\D/g, '');
        phoneInput.value = phone;

        vouchers.clear();
        voucherList.innerHTML = '';
        voucherInput.value = '';
        refreshPayment();

        if (!phone) {
            customerInfo.textContent = 'Nhập số điện thoại để kiểm tra điểm và voucher của khách hàng.';
            return;
        }

        customerInfo.textContent = 'Đang kiểm tra khách hàng...';

        try {
            const response = await fetch('api/customer_lookup.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-Token': <?= json_encode(csrf_token()) ?>,
                },
                body: new URLSearchParams({phone}),
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
    paymentMethod?.addEventListener('change', refreshPayment);

    refreshPayment();

    document.getElementById('checkout-form')?.addEventListener('submit', function () {
        const button = this.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Đang xử lý...';
    });

    document.querySelectorAll('.pos-add-form').forEach(form => {
        form.addEventListener('submit', () => {
            sessionStorage.setItem('sales_scroll_y', String(window.scrollY));
        });
    });

    const savedScroll = sessionStorage.getItem('sales_scroll_y');
    if (savedScroll !== null) {
        sessionStorage.removeItem('sales_scroll_y');
        setTimeout(() => {
            window.scrollTo(0, Number(savedScroll));
        }, 50);
    }
})();
</script>
<?php render_footer(); ?>
