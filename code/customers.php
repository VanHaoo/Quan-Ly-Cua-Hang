<?php

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_roles('admin', 'cashier');

$pdo = db();

function customer_has_column(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (!isset($cache[$column])) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'customers'
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$column]);
        $cache[$column] = (int) $stmt->fetchColumn() > 0;
    }

    return $cache[$column];
}

function normalize_customer_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function customer_tier(array $customer): array
{
    $points = (int) ($customer['points'] ?? 0);
    $spent = (float) ($customer['total_spent'] ?? 0);

    /*
     * Cấu hình hạng thành viên tại đây.
     * Sau này nếu có trang quản trị cấu hình riêng, có thể chuyển các ngưỡng này vào database.
     */
    $tiers = [
        'diamond' => [
            'label' => 'Kim cương',
            'class' => 'diamond',
            'min_spent' => 5000000,
            'min_points' => 500,
        ],
        'gold' => [
            'label' => 'Vàng',
            'class' => 'gold',
            'min_spent' => 2000000,
            'min_points' => 250,
        ],
        'silver' => [
            'label' => 'Bạc',
            'class' => 'silver',
            'min_spent' => 800000,
            'min_points' => 100,
        ],
        'bronze' => [
            'label' => 'Đồng',
            'class' => 'bronze',
            'min_spent' => 0,
            'min_points' => 0,
        ],
    ];

    foreach ($tiers as $tier) {
        if ($spent >= $tier['min_spent'] || $points >= $tier['min_points']) {
            return $tier;
        }
    }

    return $tiers['bronze'];
}

$hasEmail = customer_has_column($pdo, 'email');
$hasBirthday = customer_has_column($pdo, 'birthday') || customer_has_column($pdo, 'birthdate');
$birthdayColumn = customer_has_column($pdo, 'birthday') ? 'birthday' : (customer_has_column($pdo, 'birthdate') ? 'birthdate' : null);
$hasCreatedAt = customer_has_column($pdo, 'created_at');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $phone = normalize_customer_phone((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $birthday = trim((string) ($_POST['birthday'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Vui lòng nhập họ tên khách hàng.');
            }

            if (!preg_match('/^[0-9]{10}$/', $phone)) {
                throw new RuntimeException('Số điện thoại phải gồm đúng 10 chữ số.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Email không đúng định dạng.');
            }

            if ($birthday !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
                throw new RuntimeException('Ngày sinh không đúng định dạng.');
            }

            $fields = ['name' => $name, 'phone' => $phone];

            if ($hasEmail) {
                $fields['email'] = $email !== '' ? $email : null;
            }

            if ($birthdayColumn !== null) {
                $fields[$birthdayColumn] = $birthday !== '' ? $birthday : null;
            }

            if ($id > 0) {
                $sets = [];
                $values = [];

                foreach ($fields as $column => $value) {
                    $sets[] = "{$column}=?";
                    $values[] = $value;
                }

                $values[] = $id;

                $pdo->prepare('UPDATE customers SET ' . implode(',', $sets) . ' WHERE id=?')->execute($values);

                log_activity('customer_update', 'Cập nhật khách hàng ' . $name);
                flash('success', 'Đã cập nhật thông tin khách hàng.');
            } else {
                $columns = array_keys($fields);
                $placeholders = implode(',', array_fill(0, count($columns), '?'));

                $pdo->prepare('INSERT INTO customers(' . implode(',', $columns) . ') VALUES(' . $placeholders . ')')
                    ->execute(array_values($fields));

                log_activity('customer_create', 'Thêm khách hàng ' . $name);
                flash('success', 'Đã thêm khách hàng mới.');
            }
        }

        if ($action === 'toggle' && is_admin()) {
            $id = (int) ($_POST['id'] ?? 0);

            $stmt = $pdo->prepare('SELECT name,status FROM customers WHERE id=?');
            $stmt->execute([$id]);
            $customer = $stmt->fetch();

            if (!$customer) {
                throw new RuntimeException('Không tìm thấy khách hàng.');
            }

            $newStatus = (int) $customer['status'] === 1 ? 0 : 1;

            $pdo->prepare('UPDATE customers SET status=? WHERE id=?')->execute([$newStatus, $id]);

            log_activity('customer_status', ($newStatus ? 'Kích hoạt' : 'Ngừng') . ' khách hàng ' . $customer['name']);
            flash('success', 'Đã cập nhật trạng thái khách hàng.');
        }
    } catch (PDOException $e) {
        flash('error', $e->getCode() === '23000' ? 'Số điện thoại đã tồn tại.' : 'Không thể lưu khách hàng.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }

    redirect('customers.php');
}

$detailId = (int) ($_GET['id'] ?? 0);

if ($detailId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id=?');
    $stmt->execute([$detailId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        flash('error', 'Không tìm thấy khách hàng.');
        redirect('customers.php');
    }

    $invoiceStmt = $pdo->prepare("
        SELECT id, invoice_code, total_amount, points_earned, payment_method, status, created_at
        FROM invoices
        WHERE customer_id = ?
        OR (customer_phone IS NOT NULL AND customer_phone <> '' AND customer_phone = ?)
        ORDER BY id DESC
        LIMIT 50
    ");
    $invoiceStmt->execute([$detailId, $customer['phone']]);
    $historyInvoices = $invoiceStmt->fetchAll();

    $voucherStmt = $pdo->prepare("
        SELECT code, name, discount_type, discount_value, min_order, status, expires_at, created_at
        FROM vouchers
        WHERE customer_id = ?
        ORDER BY id DESC
        LIMIT 30
    ");
    $voucherStmt->execute([$detailId]);
    $vouchers = $voucherStmt->fetchAll();

    $tier = customer_tier($customer);

    render_header('Chi tiết khách hàng', 'customers');
    ?>
    <section class="panel customer-detail-panel">
        <div class="panel-heading responsive">
            <div>
                <h2><?= e($customer['name']) ?></h2>
                <p><?= e($customer['phone']) ?> · <?= e((int) $customer['status'] === 1 ? 'Đang hoạt động' : 'Ngừng theo dõi') ?></p>
            </div>

            <a class="btn ghost" href="<?= e(url('customers.php')) ?>">Quay lại</a>
        </div>

        <div class="customer-detail-grid">
            <article class="customer-detail-card">
                <span>Hạng thành viên</span>
                <strong><span class="tier-badge <?= e($tier['class']) ?>"><?= e($tier['label']) ?></span></strong>
                <small>Dựa trên điểm tích lũy hoặc tổng chi tiêu</small>
            </article>

            <article class="customer-detail-card">
                <span>Điểm hiện có</span>
                <strong><?= (int) $customer['points'] ?></strong>
                <small><?= max(0, 100 - (int) $customer['points']) ?> điểm nữa đến mốc đổi voucher 100 điểm</small>
            </article>

            <article class="customer-detail-card">
                <span>Tổng chi tiêu</span>
                <strong><?= money((float) $customer['total_spent']) ?></strong>
                <small>Lũy kế từ các hóa đơn đã thanh toán</small>
            </article>
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Lịch sử mua hàng</h2>
                <p>Các hóa đơn và điểm đã tích lũy</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Mã hóa đơn</th>
                        <th>Thời gian</th>
                        <th>Trạng thái</th>
                        <th class="right">Tổng tiền</th>
                        <th class="right">Điểm cộng</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$historyInvoices): ?>
                        <tr>
                            <td colspan="5" class="empty">Chưa có lịch sử mua hàng.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($historyInvoices as $invoice): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('invoices.php?id=' . (int) $invoice['id'])) ?>">
                                    <strong><?= e($invoice['invoice_code']) ?></strong>
                                </a>
                            </td>
                            <td><?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></td>
                            <td>
                                <span class="status <?= e($invoice['status']) ?>">
                                    <?= $invoice['status'] === 'paid' ? 'Đã thanh toán' : ($invoice['status'] === 'cancelled' ? 'Đã hủy' : 'Chờ xử lý') ?>
                                </span>
                            </td>
                            <td class="right"><?= money((float) $invoice['total_amount']) ?></td>
                            <td class="right"><?= (int) $invoice['points_earned'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Lịch sử voucher</h2>
                <p>Các voucher được phát hành từ điểm thành viên</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Mã voucher</th>
                        <th>Tên voucher</th>
                        <th>Trạng thái</th>
                        <th class="right">Giá trị</th>
                        <th>Hạn dùng</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$vouchers): ?>
                        <tr>
                            <td colspan="5" class="empty">Khách hàng chưa có voucher.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($vouchers as $voucher): ?>
                        <tr>
                            <td><strong><?= e($voucher['code']) ?></strong></td>
                            <td><?= e($voucher['name']) ?></td>
                            <td><span class="status <?= e($voucher['status']) ?>"><?= e($voucher['status']) ?></span></td>
                            <td class="right">
                                <?= $voucher['discount_type'] === 'percent'
                                    ? e((string) (float) $voucher['discount_value']) . '%'
                                    : money((float) $voucher['discount_value']) ?>
                            </td>
                            <td><?= date('d/m/Y', strtotime($voucher['expires_at'])) ?></td>
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

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id=?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(c.name LIKE :q OR c.phone LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE {$whereSql}");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$countStmt->execute();
$totalFilteredCustomers = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalFilteredCustomers / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$summary = [
    'total_customers' => (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
    'total_points' => (int) $pdo->query('SELECT COALESCE(SUM(points),0) FROM customers')->fetchColumn(),
    'near_voucher' => (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE points >= 80 AND points < 100')->fetchColumn(),
    'new_this_month' => 0,
];

if ($hasCreatedAt) {
    $summary['new_this_month'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM customers WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")
        ->fetchColumn();
}

$sql = "
    SELECT
        c.*,
        COUNT(DISTINCT i.id) AS invoice_count
    FROM customers c
    LEFT JOIN invoices i
        ON (
            i.customer_id = c.id
            OR (i.customer_phone IS NOT NULL AND i.customer_phone <> '' AND i.customer_phone = c.phone)
        )
        AND i.status = 'paid'
    WHERE {$whereSql}
    GROUP BY c.id
    ORDER BY c.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$customers = $stmt->fetchAll();

function customer_page_url(string $q, int $page): string
{
    $query = array_filter([
        'q' => $q,
        'page' => $page,
    ], fn($value) => $value !== '' && $value !== 1);

    return 'customers.php' . ($query ? '?' . http_build_query($query) : '');
}

render_header('Khách hàng - tích điểm', 'customers');
?>

<div class="stats-grid customer-summary-grid">
    <article class="stat-card">
        <span>Tổng số khách hàng</span>
        <strong><?= (int) $summary['total_customers'] ?></strong>
        <small>Toàn hệ thống</small>
    </article>

    <article class="stat-card">
        <span>Khách mới tháng này</span>
        <strong><?= (int) $summary['new_this_month'] ?></strong>
        <small><?= $hasCreatedAt ? 'Theo ngày tạo khách hàng' : 'Cần cột created_at để thống kê' ?></small>
    </article>

    <article class="stat-card">
        <span>Tổng điểm tích lũy</span>
        <strong><?= (int) $summary['total_points'] ?></strong>
        <small>Điểm còn lại của khách hàng</small>
    </article>

    <article class="stat-card warning">
        <span>Sắp đủ đổi voucher</span>
        <strong><?= (int) $summary['near_voucher'] ?></strong>
        <small>Từ 80 đến dưới 100 điểm</small>
    </article>
</div>

<div class="two-column customer-page-layout">
    <section class="panel form-panel">
        <div class="panel-heading">
            <div>
                <h2><?= $edit ? 'Cập nhật khách hàng' : 'Thêm khách hàng' ?></h2>
                <p>Hỗ trợ tra cứu, tích điểm và voucher khi mua hàng</p>
            </div>
        </div>

        <form method="post" class="form-grid one-column customer-form" id="customer-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

            <label>
                Họ tên khách hàng
                <input name="name" value="<?= e($edit['name'] ?? '') ?>" required>
            </label>

            <label>
                Số điện thoại
                <input
                    id="customer-phone-input"
                    name="phone"
                    value="<?= e($edit['phone'] ?? '') ?>"
                    required
                    maxlength="10"
                    inputmode="numeric"
                    pattern="[0-9]{10}"
                    placeholder="Ví dụ 0901234567"
                >
                <small class="field-error" id="phone-error"></small>
            </label>

            <label>
                Email <small class="muted">Tùy chọn<?= $hasEmail ? '' : ' · cần thêm cột email để lưu' ?></small>
                <input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>" placeholder="vidu@email.com">
            </label>

            <label>
                Ngày sinh <small class="muted">Tùy chọn<?= $birthdayColumn ? '' : ' · cần thêm cột birthday để lưu' ?></small>
                <input type="date" name="birthday" value="<?= e($edit[$birthdayColumn] ?? '') ?>">
            </label>

            <button class="btn primary"><?= $edit ? 'Lưu thay đổi' : 'Thêm khách hàng' ?></button>

            <?php if ($edit): ?>
                <a class="btn ghost" href="customers.php">Hủy sửa</a>
            <?php endif; ?>
        </form>
    </section>

    <section class="panel customer-list-panel">
        <div class="panel-heading responsive">
            <div>
                <h2>Danh sách khách hàng</h2>
                <p><?= $totalFilteredCustomers ?> khách hàng phù hợp · Trang <?= $page ?>/<?= $totalPages ?></p>
            </div>

            <form method="get" class="filter-form customer-search-form" id="customer-search-form">
                <label>
                    Tìm kiếm
                    <input id="customer-search-input" name="q" value="<?= e($q) ?>" placeholder="Tìm tên hoặc số điện thoại" autocomplete="off">
                </label>
                <button class="btn secondary">Lọc</button>
            </form>
        </div>

        <div class="table-wrap">
            <table class="customer-table">
                <thead>
                    <tr>
                        <th>Khách hàng</th>
                        <th>SĐT</th>
                        <th class="right">Điểm</th>
                        <th class="right">Tổng chi tiêu</th>
                        <th class="right">HĐ</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$customers): ?>
                        <tr>
                            <td colspan="6" class="empty">Chưa có khách hàng.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($customers as $customer): ?>
                        <?php $tier = customer_tier($customer); ?>
                        <tr>
                            <td>
                                <div class="customer-name-cell">
                                    <div>
                                        <strong><?= e($customer['name']) ?></strong>
                                        <small class="block muted"><?= (int) $customer['status'] === 1 ? 'Đang hoạt động' : 'Ngừng theo dõi' ?></small>
                                    </div>
                                    <span class="tier-badge <?= e($tier['class']) ?>"><?= e($tier['label']) ?></span>
                                </div>
                            </td>
                            <td><?= e($customer['phone']) ?></td>
                            <td class="right"><?= (int) $customer['points'] ?></td>
                            <td class="right"><?= money((float) $customer['total_spent']) ?></td>
                            <td class="right"><?= (int) $customer['invoice_count'] ?></td>
                            <td class="actions customer-actions">
                                <a class="btn icon-action" href="?id=<?= (int) $customer['id'] ?>" title="Chi tiết" aria-label="Chi tiết">👁️</a>

                                <a class="btn icon-action" href="?edit=<?= (int) $customer['id'] ?>" title="Chỉnh sửa" aria-label="Chỉnh sửa">✏️</a>

                                <?php if (is_admin()): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
                                        <button
                                            class="btn icon-action <?= (int) $customer['status'] === 1 ? 'danger-soft' : 'success-soft' ?>"
                                            title="<?= (int) $customer['status'] === 1 ? 'Ngừng hoạt động' : 'Kích hoạt lại' ?>"
                                            aria-label="<?= (int) $customer['status'] === 1 ? 'Ngừng hoạt động' : 'Kích hoạt lại' ?>"
                                            data-confirm="<?= (int) $customer['status'] === 1 ? 'Ngừng hoạt động khách hàng này?' : 'Kích hoạt lại khách hàng này?' ?>"
                                        >
                                            <?= (int) $customer['status'] === 1 ? '🔒' : '🔓' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalFilteredCustomers > $perPage): ?>
            <nav class="pagination customer-pagination" aria-label="Phân trang khách hàng">
                <?php if ($page > 1): ?>
                    <a href="<?= e(customer_page_url($q, $page - 1)) ?>">« Trước</a>
                <?php else: ?>
                    <span class="disabled">« Trước</span>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a class="<?= $p === $page ? 'active' : '' ?>" href="<?= e(customer_page_url($q, $p)) ?>"><?= $p ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(customer_page_url($q, $page + 1)) ?>">Sau »</a>
                <?php else: ?>
                    <span class="disabled">Sau »</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</div>

<script>
(() => {
    const searchForm = document.getElementById('customer-search-form');
    const searchInput = document.getElementById('customer-search-input');
    const phoneInput = document.getElementById('customer-phone-input');
    const phoneError = document.getElementById('phone-error');
    const customerForm = document.getElementById('customer-form');

    let searchTimer = null;

    searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            searchForm?.submit();
        }, 450);
    });

    function validatePhone() {
        if (!phoneInput) return true;

        phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, 10);

        if (phoneInput.value.length === 0) {
            phoneError.textContent = '';
            phoneInput.classList.remove('invalid');
            return false;
        }

        if (!/^[0-9]{10}$/.test(phoneInput.value)) {
            phoneError.textContent = 'Số điện thoại phải gồm đúng 10 chữ số.';
            phoneInput.classList.add('invalid');
            return false;
        }

        phoneError.textContent = '';
        phoneInput.classList.remove('invalid');
        return true;
    }

    phoneInput?.addEventListener('input', validatePhone);
    phoneInput?.addEventListener('blur', validatePhone);

    customerForm?.addEventListener('submit', event => {
        if (!validatePhone()) {
            event.preventDefault();
            phoneInput?.focus();
        }
    });
})();
</script>

<?php render_footer(); ?>
