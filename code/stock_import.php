<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_roles('admin', 'warehouse');

$pdo = db();

/*
 * Bảng này dùng để ẩn nhà cung cấp khỏi dropdown chọn nhà cung cấp.
 * Không xóa phiếu nhập cũ để tránh sai lịch sử kho.
 * Chỉ định COLLATE để tránh lỗi Illegal mix of collations khi JOIN với view stock_imports.
 */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS hidden_suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
        hidden_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    ALTER TABLE hidden_suppliers
    CONVERT TO CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci
");

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
        $action = (string) ($_POST['action'] ?? 'create_import');

        if ($action === 'hide_supplier') {
            $supplierToHide = trim((string) ($_POST['supplier_name'] ?? ''));

            if ($supplierToHide === '') {
                throw new RuntimeException('Không tìm thấy nhà cung cấp cần xóa.');
            }

            $stmt = $pdo->prepare('INSERT IGNORE INTO hidden_suppliers(supplier, hidden_by) VALUES(?, ?)');
            $stmt->execute([$supplierToHide, (int) current_user()['id']]);

            log_activity('supplier_hide', 'Ẩn nhà cung cấp ' . $supplierToHide . ' khỏi danh sách nhập hàng');
            flash('success', 'Đã xóa nhà cung cấp "' . $supplierToHide . '" khỏi danh sách chọn.');

            redirect('stock_import.php');
            exit;
        }

        if ($action !== 'create_import') {
            throw new RuntimeException('Thao tác không hợp lệ.');
        }

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
    exit;
}

$detailId = (int) ($_GET['id'] ?? 0);

if ($detailId > 0) {
    $stmt = $pdo->prepare('SELECT si.*,u.full_name FROM stock_imports si JOIN users u ON u.id=si.user_id WHERE si.id=?');
    $stmt->execute([$detailId]);
    $import = $stmt->fetch();

    if (!$import) {
        flash('error', 'Không tìm thấy phiếu nhập.');
        redirect('stock_import.php');
        exit;
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
                    <?php if (!$details): ?>
                        <tr>
                            <td colspan="4" class="empty">Phiếu nhập này chưa có chi tiết sản phẩm.</td>
                        </tr>
                    <?php endif; ?>

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
    ->query("
        SELECT DISTINCT si.supplier
        FROM stock_imports si
        LEFT JOIN hidden_suppliers hs
            ON hs.supplier COLLATE utf8mb4_unicode_ci = si.supplier COLLATE utf8mb4_unicode_ci
        WHERE si.supplier IS NOT NULL
        AND si.supplier <> ''
        AND hs.id IS NULL
        ORDER BY si.supplier
    ")
    ->fetchAll(PDO::FETCH_COLUMN);

$hasCreatedAt = stock_import_has_column($pdo, 'stock_imports', 'created_at');
$monthLabel = 'tháng ' . date('n');

$importQuery = trim((string) ($_GET['q'] ?? ''));
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 8;
$offset = ($page - 1) * $perPage;

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
    'total_count' => 0,
    'total_amount' => 0,
    'month_count' => 0,
    'top_supplier' => 'Chưa có',
];

$summary['total_count'] = (int) $pdo
    ->query('SELECT COUNT(*) FROM stock_imports')
    ->fetchColumn();

$summary['total_amount'] = (float) $pdo
    ->query('SELECT COALESCE(SUM(total_amount),0) FROM stock_imports')
    ->fetchColumn();

if ($hasCreatedAt) {
    $summary['month_count'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM stock_imports WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")
        ->fetchColumn();
}

$topSupplier = $pdo
    ->query("
        SELECT si.supplier
        FROM stock_imports si
        LEFT JOIN hidden_suppliers hs
            ON hs.supplier COLLATE utf8mb4_unicode_ci = si.supplier COLLATE utf8mb4_unicode_ci
        WHERE si.supplier IS NOT NULL
        AND si.supplier <> ''
        AND hs.id IS NULL
        GROUP BY si.supplier
        ORDER BY COUNT(*) DESC, SUM(si.total_amount) DESC
        LIMIT 1
    ")
    ->fetchColumn();

if ($topSupplier) {
    $summary['top_supplier'] = (string) $topSupplier;
}

$countSql = "
    SELECT COUNT(*)
    FROM stock_imports si
    JOIN users u ON u.id=si.user_id
    WHERE {$whereSql}
";

$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$countStmt->execute();

$totalImports = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalImports / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$importsSql = "
    SELECT si.*,u.full_name
    FROM stock_imports si
    JOIN users u ON u.id=si.user_id
    WHERE {$whereSql}
    ORDER BY si.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($importsSql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$imports = $stmt->fetchAll();

$paginationParams = [
    'q' => $importQuery,
    'from' => $fromDate,
    'to' => $toDate,
];

$paginationParams = array_filter($paginationParams, fn($value) => $value !== '');

function stock_import_page_url(array $baseParams, int $page): string
{
    $baseParams['page'] = $page;
    return 'stock_import.php?' . http_build_query($baseParams);
}

render_header('Nhập hàng', 'stock_import');
?>

<form method="post" id="hide-supplier-form" hidden>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="hide_supplier">
    <input type="hidden" name="supplier_name" id="hide-supplier-name">
</form>

<div class="stock-import-page airy-stock-page">
    <section class="stock-import-hero panel">
        <div>
            <span class="eyebrow">QUẢN LÝ KHO</span>
            <h2>Nhập hàng nhanh, dễ kiểm tra và ít rối mắt hơn.</h2>
            <p>Tạo phiếu nhập theo dạng bảng, thêm nhiều dòng sản phẩm và tự tính tổng tiền trước khi lưu.</p>
        </div>

        <div class="hero-total-card">
            <span>Tổng tiền nhập lũy kế</span>
            <strong><?= money((float) $summary['total_amount']) ?></strong>
        </div>
    </section>

    <div class="stats-grid import-summary-grid airy-summary-grid">
        <article class="mini-stat">
            <span>Tổng phiếu nhập</span>
            <strong><?= (int) $summary['total_count'] ?></strong>
            <small>Lũy kế toàn thời gian</small>
        </article>

        <article class="mini-stat">
            <span>Tổng tiền nhập</span>
            <strong><?= money((float) $summary['total_amount']) ?></strong>
            <small>Lũy kế toàn thời gian</small>
        </article>

        <article class="mini-stat">
            <span>Phiếu nhập <?= e($monthLabel) ?></span>
            <strong><?= (int) $summary['month_count'] ?></strong>
            <small>Trong tháng hiện tại</small>
        </article>

        <article class="mini-stat">
            <span>NCC giao dịch nhiều nhất</span>
            <strong><?= e($summary['top_supplier']) ?></strong>
            <small>Toàn thời gian</small>
        </article>
    </div>

    <section class="panel stock-import-create-wide">
        <div class="panel-heading relaxed-heading">
            <div>
                <h2>Tạo phiếu nhập hàng</h2>
                <p>Chọn nhà cung cấp, thêm các dòng sản phẩm rồi lưu phiếu nhập.</p>
            </div>

            <button class="btn secondary" type="button" id="add-import-row">+ Thêm dòng</button>
        </div>

        <form method="post" class="stock-import-form airy-import-form" id="stock-import-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_import">

            <div class="import-meta-grid">
                <div class="supplier-field">
                    <label class="supplier-label" for="supplier-select">Nhà cung cấp</label>

                    <div class="supplier-select-row compact">
                        <select name="supplier" id="supplier-select" required>
                            <option value="">-- Chọn nhà cung cấp --</option>

                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= e($supplier) ?>"><?= e($supplier) ?></option>
                            <?php endforeach; ?>

                            <option value="__new">+ Thêm nhà cung cấp mới</option>
                        </select>

                        <button
                            type="button"
                            class="supplier-delete-icon"
                            id="hide-supplier-btn"
                            title="Xóa nhà cung cấp khỏi danh sách"
                            aria-label="Xóa nhà cung cấp khỏi danh sách"
                            disabled
                        >
                            🗑
                        </button>
                    </div>

                    <small class="muted">Ẩn khỏi danh sách chọn, không xóa lịch sử phiếu nhập.</small>
                </div>

                <label id="new-supplier-field" hidden>
                    Nhà cung cấp mới
                    <input name="new_supplier" id="new-supplier-input" placeholder="Nhập tên nhà cung cấp mới">
                </label>

                <label>
                    Ghi chú
                    <textarea name="note" rows="2" placeholder="Ví dụ: nhập đợt đầu tháng, hàng bổ sung..."></textarea>
                </label>
            </div>

            <div class="import-table-card airy-table-card">
                <div class="import-items-head">
                    <div>
                        <strong>Danh sách sản phẩm nhập</strong>
                        <small>Mỗi dòng gồm sản phẩm, số lượng, giá nhập và thành tiền.</small>
                    </div>
                </div>

                <div class="table-wrap import-items-table-wrap">
                    <table class="import-items-table airy-import-table">
                        <thead>
                            <tr>
                                <th>Sản phẩm</th>
                                <th class="right">SL</th>
                                <th class="right">Giá nhập</th>
                                <th class="right">Thành tiền</th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody id="import-item-rows">
                            <tr class="import-item-row">
                                <td>
                                    <select name="product_id[]" required>
                                        <option value="">-- Chọn sản phẩm --</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?= (int) $product['id'] ?>">
                                                <?= e($product['code'] . ' - ' . $product['name'] . ' (tồn ' . $product['stock'] . ' ' . $product['unit'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <td>
                                    <input class="import-qty" type="number" name="quantity[]" min="1" required>
                                    <small class="field-error"></small>
                                </td>

                                <td>
                                    <input class="import-price" type="number" name="cost_price[]" min="1" step="1" required>
                                    <small class="field-error"></small>
                                </td>

                                <td>
                                    <input class="import-subtotal" type="text" value="0 đ" readonly>
                                </td>

                                <td class="right">
                                    <button type="button" class="line-remove-btn remove-import-row" title="Xóa dòng" aria-label="Xóa dòng">×</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="import-submit-bar">
                <div class="import-total-box accent-total">
                    <span>Tổng tiền phiếu nhập</span>
                    <strong id="import-total">0 đ</strong>
                </div>

                <button class="btn primary import-save-btn">Lưu phiếu nhập</button>
            </div>
        </form>
    </section>

    <section class="panel stock-import-list-panel airy-list-panel">
        <div class="panel-heading relaxed-heading">
            <div>
                <h2>Phiếu nhập gần đây</h2>
                <p>Theo dõi các lần nhập kho, lọc theo mã phiếu, nhà cung cấp hoặc ngày nhập.</p>
            </div>
        </div>

        <form class="filter-form stock-import-filter-form airy-filter-form">
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
                                <a class="btn small outline" href="<?= e(url('stock_import.php?id=' . (int) $import['id'])) ?>">
                                    Chi tiết
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= e(stock_import_page_url($paginationParams, $page - 1)) ?>">« Trước</a>
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
                        <a href="<?= e(stock_import_page_url($paginationParams, $i)) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(stock_import_page_url($paginationParams, $page + 1)) ?>">Sau »</a>
                <?php else: ?>
                    <span class="disabled">Sau »</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</div>

<script>
(() => {
    const supplierSelect = document.getElementById('supplier-select');
    const newSupplierField = document.getElementById('new-supplier-field');
    const newSupplierInput = document.getElementById('new-supplier-input');
    const hideSupplierButton = document.getElementById('hide-supplier-btn');
    const hideSupplierForm = document.getElementById('hide-supplier-form');
    const hideSupplierName = document.getElementById('hide-supplier-name');

    const rowsBox = document.getElementById('import-item-rows');
    const addRowButton = document.getElementById('add-import-row');
    const totalEl = document.getElementById('import-total');
    const form = document.getElementById('stock-import-form');

    const formatMoney = value => new Intl.NumberFormat('vi-VN').format(Math.max(0, Math.round(value))) + ' đ';

    function toggleSupplierInput() {
        if (!supplierSelect) return;

        const isNew = supplierSelect.value === '__new';
        const canHide = supplierSelect.value !== '' && supplierSelect.value !== '__new';

        if (newSupplierField) {
            newSupplierField.hidden = !isNew;
        }

        if (newSupplierInput) {
            newSupplierInput.required = isNew;

            if (isNew) {
                newSupplierInput.focus();
            } else {
                newSupplierInput.value = '';
            }
        }

        if (hideSupplierButton) {
            hideSupplierButton.disabled = !canHide;
        }
    }

    hideSupplierButton?.addEventListener('click', () => {
        const supplier = supplierSelect.value;

        if (!supplier || supplier === '__new') {
            alert('Vui lòng chọn nhà cung cấp cần xóa.');
            return;
        }

        const ok = confirm(`Xóa nhà cung cấp "${supplier}" khỏi danh sách chọn? Lịch sử phiếu nhập cũ vẫn được giữ lại.`);

        if (!ok) return;

        hideSupplierName.value = supplier;
        hideSupplierForm.submit();
    });

    function validateNumber(input) {
        const value = Number(input.value || 0);
        const error = input.closest('td')?.querySelector('.field-error');

        if (value <= 0) {
            if (error) error.textContent = 'Phải > 0';
            input.classList.add('invalid');
            return false;
        }

        if (error) error.textContent = '';
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
        newRow.querySelector('select')?.focus();
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
