<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_roles('admin', 'warehouse');

$pdo = db();

function stock_import_has_column(PDO $pdo, string $table, string $column): bool
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $costPrices = $_POST['cost_price'] ?? [];
        $supplierChoice = trim((string) ($_POST['supplier'] ?? ''));
        $newSupplier = trim((string) ($_POST['new_supplier'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));

        if (!is_array($productIds) || !is_array($quantities) || !is_array($costPrices)) {
            throw new RuntimeException('Dữ liệu phiếu nhập không hợp lệ.');
        }

        $supplier = $supplierChoice === '__new' ? $newSupplier : $supplierChoice;

        if ($supplier === '') {
            throw new RuntimeException('Vui lòng chọn hoặc nhập nhà cung cấp.');
        }

        $items = [];
        $totalAmount = 0.0;

        $pdo->beginTransaction();

        foreach ($productIds as $index => $rawProductId) {
            $productId = (int) $rawProductId;
            $quantity = filter_var($quantities[$index] ?? null, FILTER_VALIDATE_INT);
            $costPrice = filter_var($costPrices[$index] ?? null, FILTER_VALIDATE_FLOAT);

            if ($productId <= 0 && ($quantity === false || $quantity === null) && ($costPrice === false || $costPrice === null)) {
                continue;
            }

            if ($productId <= 0 || $quantity === false || $quantity <= 0 || $costPrice === false || $costPrice <= 0) {
                throw new RuntimeException('Mỗi dòng nhập hàng phải có sản phẩm, số lượng > 0 và giá nhập > 0.');
            }

            $stmt = $pdo->prepare('SELECT id,code,name FROM products WHERE id=? FOR UPDATE');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new RuntimeException('Không tìm thấy một sản phẩm trong phiếu nhập.');
            }

            $subtotal = $quantity * $costPrice;
            $totalAmount += $subtotal;

            $items[] = [
                'product' => $product,
                'quantity' => $quantity,
                'cost_price' => $costPrice,
                'subtotal' => $subtotal,
            ];
        }

        if (!$items) {
            throw new RuntimeException('Vui lòng thêm ít nhất một sản phẩm vào phiếu nhập.');
        }

        $importCode = 'PN' . date('YmdHis') . random_int(10, 99);

        $stmt = $pdo->prepare('INSERT INTO stock_imports(import_code,user_id,supplier,note,total_amount) VALUES(?,?,?,?,?)');
        $stmt->execute([$importCode, (int) current_user()['id'], $supplier, $note, $totalAmount]);
        $importId = (int) $pdo->lastInsertId();

        $detailStmt = $pdo->prepare('INSERT INTO stock_import_details(stock_import_id,product_id,product_code,product_name,quantity,cost_price,subtotal) VALUES(?,?,?,?,?,?,?)');
        $updateStock = $pdo->prepare('UPDATE products SET stock=stock+?, updated_at=NOW() WHERE id=?');

        foreach ($items as $item) {
            $product = $item['product'];

            $detailStmt->execute([
                $importId,
                $product['id'],
                $product['code'],
                $product['name'],
                $item['quantity'],
                $item['cost_price'],
                $item['subtotal'],
            ]);

            $updateStock->execute([$item['quantity'], $product['id']]);
        }

        $pdo->commit();

        log_activity(
            'stock_import',
            'Nhập hàng ' . $importCode . ' gồm ' . count($items) . ' sản phẩm, tổng tiền ' . money($totalAmount)
        );

        flash('success', 'Đã tạo phiếu nhập ' . $importCode . ' và cập nhật tồn kho.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Không thể nhập hàng.');
    }

    redirect('stock_import.php');
}

$detailId = (int) ($_GET['id'] ?? 0);

if ($detailId > 0) {
    $stmt = $pdo->prepare('SELECT si.*,u.full_name FROM stock_imports si JOIN users u ON u.id=si.user_id WHERE si.id=?');
    $stmt->execute([$detailId]);
    $import = $stmt->fetch();

    if (!$import) {
        flash('error', 'Không tìm thấy phiếu nhập.');
        redirect('stock_import.php');
    }

    $detailStmt = $pdo->prepare('SELECT * FROM stock_import_details WHERE stock_import_id=? ORDER BY id');
    $detailStmt->execute([$detailId]);
    $details = $detailStmt->fetchAll();

    render_header('Chi tiết phiếu nhập', 'stock_import');
    ?>
    <section class="panel">
        <div class="panel-heading responsive">
            <div>
                <h2>Phiếu nhập <?= e($import['import_code']) ?></h2>
                <p><?= e($import['supplier']) ?> · <?= date('d/m/Y H:i', strtotime($import['created_at'])) ?></p>
            </div>

            <a class="btn ghost" href="<?= e(url('stock_import.php')) ?>">Quay lại</a>
        </div>

        <div class="invoice-info">
            <p><strong>Người nhập:</strong> <?= e($import['full_name']) ?></p>
            <p><strong>Nhà cung cấp:</strong> <?= e($import['supplier']) ?></p>
            <p><strong>Tổng tiền:</strong> <?= money((float) $import['total_amount']) ?></p>
            <p><strong>Ghi chú:</strong> <?= e($import['note'] ?: 'Không có') ?></p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sản phẩm</th>
                        <th class="right">Số lượng</th>
                        <th class="right">Giá nhập</th>
                        <th class="right">Thành tiền</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($details as $detail): ?>
                        <tr>
                            <td>
                                <strong><?= e($detail['product_name']) ?></strong>
                                <small class="block muted"><?= e($detail['product_code']) ?></small>
                            </td>
                            <td class="right"><?= (int) $detail['quantity'] ?></td>
                            <td class="right"><?= money((float) $detail['cost_price']) ?></td>
                            <td class="right"><?= money((float) $detail['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
    render_footer();
    exit;
}

$products = $pdo
    ->query('SELECT id,code,name,stock,unit FROM products WHERE status=1 ORDER BY name')
    ->fetchAll();

$suppliers = $pdo
    ->query("SELECT DISTINCT supplier FROM stock_imports WHERE supplier IS NOT NULL AND supplier <> '' ORDER BY supplier")
    ->fetchAll(PDO::FETCH_COLUMN);

$hasCreatedAt = stock_import_has_column($pdo, 'stock_imports', 'created_at');

$importQuery = trim((string) ($_GET['q'] ?? ''));
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));

$where = ['1=1'];
$params = [];

if ($importQuery !== '') {
    $where[] = '(si.import_code LIKE :q OR si.supplier LIKE :q OR u.full_name LIKE :q)';
    $params[':q'] = '%' . $importQuery . '%';
}

if ($fromDate !== '') {
    $where[] = 'DATE(si.created_at) >= :from_date';
    $params[':from_date'] = $fromDate;
}

if ($toDate !== '') {
    $where[] = 'DATE(si.created_at) <= :to_date';
    $params[':to_date'] = $toDate;
}

$whereSql = implode(' AND ', $where);

$summary = [
    'month_count' => 0,
    'month_amount' => 0,
    'top_supplier' => 'Chưa có',
];

if ($hasCreatedAt) {
    $summary['month_count'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM stock_imports WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")
        ->fetchColumn();

    $summary['month_amount'] = (float) $pdo
        ->query("SELECT COALESCE(SUM(total_amount),0) FROM stock_imports WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")
        ->fetchColumn();

    $topSupplier = $pdo
        ->query("
            SELECT supplier
            FROM stock_imports
            WHERE YEAR(created_at)=YEAR(CURDATE())
            AND MONTH(created_at)=MONTH(CURDATE())
            GROUP BY supplier
            ORDER BY COUNT(*) DESC, SUM(total_amount) DESC
            LIMIT 1
        ")
        ->fetchColumn();

    if ($topSupplier) {
        $summary['top_supplier'] = (string) $topSupplier;
    }
}

$importsSql = "
    SELECT si.*,u.full_name
    FROM stock_imports si
    JOIN users u ON u.id=si.user_id
    WHERE {$whereSql}
    ORDER BY si.id DESC
    LIMIT 20
";
$stmt = $pdo->prepare($importsSql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->execute();
$imports = $stmt->fetchAll();

render_header('Nhập hàng', 'stock_import');
?>

<div class="two-column stock-import-layout">
    <section class="panel form-panel">
        <div class="panel-heading">
            <div>
                <h2>Tạo phiếu nhập hàng</h2>
                <p>Thêm nhiều sản phẩm vào cùng một phiếu nhập</p>
            </div>
        </div>

        <form method="post" class="form-grid one-column stock-import-form" id="stock-import-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <label>
                Nhà cung cấp
                <select name="supplier" id="supplier-select" required>
                    <option value="">-- Chọn nhà cung cấp --</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= e($supplier) ?>"><?= e($supplier) ?></option>
                    <?php endforeach; ?>
                    <option value="__new">+ Thêm nhà cung cấp mới</option>
                </select>
            </label>

            <label id="new-supplier-field" hidden>
                Nhà cung cấp mới
                <input name="new_supplier" id="new-supplier-input" placeholder="Nhập tên nhà cung cấp mới">
            </label>

            <div class="import-items-box">
                <div class="import-items-head">
                    <strong>Danh sách sản phẩm nhập</strong>
                    <button class="btn small secondary" type="button" id="add-import-row">+ Thêm sản phẩm</button>
                </div>

                <div class="import-item-rows" id="import-item-rows">
                    <div class="import-item-row">
                        <label>
                            Sản phẩm
                            <select name="product_id[]" required>
                                <option value="">-- Chọn sản phẩm --</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= (int) $product['id'] ?>">
                                        <?= e($product['code'] . ' - ' . $product['name'] . ' (tồn ' . $product['stock'] . ' ' . $product['unit'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Số lượng
                            <input class="import-qty" type="number" name="quantity[]" min="1" required>
                            <small class="field-error"></small>
                        </label>

                        <label>
                            Giá nhập
                            <input class="import-price" type="number" name="cost_price[]" min="1" step="1" required>
                            <small class="field-error"></small>
                        </label>

                        <label>
                            Thành tiền
                            <input class="import-subtotal" type="text" value="0 đ" readonly>
                        </label>

                        <button type="button" class="icon-button remove-import-row" title="Xóa dòng">×</button>
                    </div>
                </div>
            </div>

            <div class="import-total-box">
                <span>Tổng tiền phiếu nhập</span>
                <strong id="import-total">0 đ</strong>
            </div>

            <label>
                Ghi chú
                <textarea name="note" rows="3" placeholder="Không bắt buộc"></textarea>
            </label>

            <button class="btn primary">Lưu phiếu nhập</button>
        </form>
    </section>

    <section class="panel stock-import-list-panel">
        <div class="panel-heading">
            <div>
                <h2>Phiếu nhập gần đây</h2>
                <p>Theo dõi các lần nhập kho</p>
            </div>
        </div>

        <div class="stats-grid import-summary-grid">
            <article class="mini-stat">
                <span>Phiếu nhập tháng này</span>
                <strong><?= (int) $summary['month_count'] ?></strong>
                <small>Tính theo ngày tạo phiếu</small>
            </article>

            <article class="mini-stat">
                <span>Tổng tiền tháng này</span>
                <strong><?= money((float) $summary['month_amount']) ?></strong>
                <small>Tổng giá trị nhập hàng</small>
            </article>

            <article class="mini-stat">
                <span>NCC giao dịch nhiều nhất</span>
                <strong><?= e($summary['top_supplier']) ?></strong>
                <small>Trong tháng hiện tại</small>
            </article>
        </div>

        <form class="filter-form stock-import-filter-form">
            <label>
                Tìm phiếu
                <input name="q" value="<?= e($importQuery) ?>" placeholder="Mã phiếu, nhà cung cấp">
            </label>

            <label>
                Từ ngày
                <input type="date" name="from" value="<?= e($fromDate) ?>">
            </label>

            <label>
                Đến ngày
                <input type="date" name="to" value="<?= e($toDate) ?>">
            </label>

            <button class="btn secondary">Lọc</button>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Mã phiếu</th>
                        <th>Người nhập</th>
                        <th>Nhà cung cấp</th>
                        <th>Thời gian</th>
                        <th class="right">Tổng tiền</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$imports): ?>
                        <tr>
                            <td colspan="6" class="empty">Chưa có phiếu nhập.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($imports as $import): ?>
                        <tr>
                            <td><strong><?= e($import['import_code']) ?></strong></td>
                            <td><?= e($import['full_name']) ?></td>
                            <td><?= e($import['supplier']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($import['created_at'])) ?></td>
                            <td class="right"><?= money((float) $import['total_amount']) ?></td>
                            <td class="right">
                                <a class="btn small outline" href="<?= e(url('stock_import.php?id=' . (int) $import['id'])) ?>">Chi tiết</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
(() => {
    const supplierSelect = document.getElementById('supplier-select');
    const newSupplierField = document.getElementById('new-supplier-field');
    const newSupplierInput = document.getElementById('new-supplier-input');
    const rowsBox = document.getElementById('import-item-rows');
    const addRowButton = document.getElementById('add-import-row');
    const totalEl = document.getElementById('import-total');
    const form = document.getElementById('stock-import-form');

    const formatMoney = value => new Intl.NumberFormat('vi-VN').format(Math.max(0, Math.round(value))) + ' đ';

    function toggleSupplierInput() {
        const isNew = supplierSelect.value === '__new';
        newSupplierField.hidden = !isNew;
        newSupplierInput.required = isNew;

        if (isNew) {
            newSupplierInput.focus();
        } else {
            newSupplierInput.value = '';
        }
    }

    function validateNumber(input) {
        const value = Number(input.value || 0);
        const error = input.parentElement.querySelector('.field-error');

        if (value <= 0) {
            error.textContent = 'Giá trị phải lớn hơn 0.';
            input.classList.add('invalid');
            return false;
        }

        error.textContent = '';
        input.classList.remove('invalid');
        return true;
    }

    function updateTotals() {
        let total = 0;

        rowsBox.querySelectorAll('.import-item-row').forEach(row => {
            const qtyInput = row.querySelector('.import-qty');
            const priceInput = row.querySelector('.import-price');
            const subtotalInput = row.querySelector('.import-subtotal');
            const qty = Number(qtyInput.value || 0);
            const price = Number(priceInput.value || 0);
            const subtotal = qty * price;

            subtotalInput.value = formatMoney(subtotal);
            total += subtotal;
        });

        totalEl.textContent = formatMoney(total);
    }

    function bindRow(row) {
        row.querySelectorAll('.import-qty, .import-price').forEach(input => {
            input.addEventListener('input', () => {
                validateNumber(input);
                updateTotals();
            });
        });

        row.querySelector('.remove-import-row')?.addEventListener('click', () => {
            const rows = rowsBox.querySelectorAll('.import-item-row');

            if (rows.length <= 1) {
                row.querySelectorAll('select, input').forEach(input => {
                    if (!input.readOnly) input.value = '';
                });
            } else {
                row.remove();
            }

            updateTotals();
        });
    }

    addRowButton?.addEventListener('click', () => {
        const firstRow = rowsBox.querySelector('.import-item-row');
        const newRow = firstRow.cloneNode(true);

        newRow.querySelectorAll('select, input').forEach(input => {
            if (!input.readOnly) input.value = '';
            if (input.readOnly) input.value = '0 đ';
            input.classList.remove('invalid');
        });

        newRow.querySelectorAll('.field-error').forEach(error => error.textContent = '');

        rowsBox.appendChild(newRow);
        bindRow(newRow);
    });

    supplierSelect?.addEventListener('change', toggleSupplierInput);

    form?.addEventListener('submit', event => {
        let valid = true;

        rowsBox.querySelectorAll('.import-item-row').forEach(row => {
            const product = row.querySelector('select[name="product_id[]"]');
            const qty = row.querySelector('.import-qty');
            const price = row.querySelector('.import-price');

            if (!product.value) valid = false;
            if (!validateNumber(qty)) valid = false;
            if (!validateNumber(price)) valid = false;
        });

        if (!valid) {
            event.preventDefault();
            alert('Vui lòng kiểm tra lại sản phẩm, số lượng và giá nhập.');
        }
    });

    rowsBox.querySelectorAll('.import-item-row').forEach(bindRow);
    toggleSupplierInput();
    updateTotals();
})();
</script>

<?php render_footer(); ?>
