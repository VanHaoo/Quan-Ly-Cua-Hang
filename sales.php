<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_login();

$stmt = db()->query(
    'SELECT id, code, name, category, price, stock, min_stock, unit
     FROM products
     WHERE status = 1 AND stock > 0
     ORDER BY name'
);
$products = $stmt->fetchAll();
$checkoutToken = issue_checkout_token();

$pageTitle = 'Bán hàng tại quầy';
$activePage = 'sales';
require __DIR__ . '/partials/header.php';
?>
<form method="post" action="<?= BASE_URL ?>/actions/sale_action.php" id="saleForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="checkout_token" value="<?= htmlspecialchars($checkoutToken) ?>">
    <input type="hidden" name="cart_json" id="cartJson" value="[]">

    <div class="sales-layout">
        <section class="panel product-browser">
            <div class="panel-heading responsive-heading">
                <div>
                    <h2>Chọn sản phẩm</h2>
                    <p>Hệ thống kiểm tra lại tồn kho khi thanh toán</p>
                </div>
                <input id="productSearch" class="search-input" type="search" placeholder="Tìm tên, mã hoặc danh mục">
            </div>

            <div class="product-grid" id="productGrid">
                <?php if (!$products): ?>
                    <div class="empty-state">Chưa có sản phẩm còn hàng.</div>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <?php $isLow = (int) $product['stock'] <= (int) $product['min_stock']; ?>
                        <article class="product-card" data-search="<?= htmlspecialchars(mb_strtolower($product['code'] . ' ' . $product['name'] . ' ' . $product['category'])) ?>">
                            <div class="product-card-top">
                                <span class="pill"><?= htmlspecialchars($product['code']) ?></span>
                                <span class="stock-text <?= $isLow ? 'low-stock-text' : '' ?>">
                                    Còn <?= (int) $product['stock'] ?> <?= htmlspecialchars($product['unit']) ?>
                                </span>
                            </div>
                            <h3><?= htmlspecialchars($product['name']) ?></h3>
                            <p><?= htmlspecialchars($product['category']) ?></p>
                            <div class="product-card-bottom">
                                <strong><?= format_money($product['price']) ?></strong>
                                <button
                                    class="btn btn-small btn-primary add-product"
                                    type="button"
                                    data-id="<?= (int) $product['id'] ?>"
                                    data-code="<?= htmlspecialchars($product['code']) ?>"
                                    data-name="<?= htmlspecialchars($product['name']) ?>"
                                    data-price="<?= (float) $product['price'] ?>"
                                    data-stock="<?= (int) $product['stock'] ?>"
                                >Thêm</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <aside class="panel cart-panel">
            <div class="panel-heading">
                <div>
                    <h2>Hóa đơn hiện tại</h2>
                    <p id="cartCount">Chưa có sản phẩm</p>
                </div>
            </div>

            <div class="cart-table-wrap">
                <table class="cart-table">
                    <thead>
                    <tr>
                        <th>Sản phẩm</th>
                        <th class="text-center">SL</th>
                        <th class="text-right">Thành tiền</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody id="cartBody">
                    <tr id="emptyCartRow"><td colspan="4" class="empty-cell">Chọn sản phẩm để bắt đầu.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="checkout-box">
                <label>
                    Tên khách hàng
                    <input type="text" name="customer_name" maxlength="120" placeholder="Để trống nếu là khách lẻ">
                </label>

                <div class="summary-line total-line">
                    <span>Tổng tiền</span>
                    <strong id="totalDisplay">0 đ</strong>
                </div>

                <label>
                    Tiền khách đưa
                    <input type="number" min="0" step="1000" name="customer_money" id="customerMoney" required placeholder="Nhập số tiền nhận từ khách">
                </label>

                <div class="summary-line">
                    <span>Tiền thừa</span>
                    <strong id="changeDisplay">0 đ</strong>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="checkoutButton" disabled>Thanh toán và lưu hóa đơn</button>
                <button type="button" class="btn btn-secondary btn-block" id="clearCartButton">Xóa toàn bộ giỏ hàng</button>
            </div>
        </aside>
    </div>
</form>
<script>
window.SALES_PAGE = true;
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
