<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_admin();

$keyword = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT l.*, u.full_name
        FROM activity_logs l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE 1=1';
$params = [];

if ($keyword !== '') {
    $sql .= ' AND (l.action LIKE ? OR l.entity_type LIKE ? OR l.description LIKE ? OR u.full_name LIKE ?)';
    $search = '%' . $keyword . '%';
    $params = [$search, $search, $search, $search];
}
$sql .= ' ORDER BY l.id DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actionLabels = [
    'login' => 'Đăng nhập',
    'logout' => 'Đăng xuất',
    'product_create' => 'Thêm sản phẩm',
    'product_update' => 'Sửa sản phẩm',
    'product_activate' => 'Kích hoạt sản phẩm',
    'product_deactivate' => 'Ngừng bán sản phẩm',
    'invoice_create' => 'Tạo hóa đơn',
    'invoice_cancel' => 'Hủy hóa đơn',
];

$pageTitle = 'Lịch sử thao tác';
$activePage = 'logs';
require __DIR__ . '/partials/header.php';
?>
<div class="panel">
    <div class="panel-heading responsive-heading">
        <div>
            <h2>Nhật ký hệ thống</h2>
            <p>Hiển thị tối đa 200 thao tác gần nhất</p>
        </div>
        <form method="get" class="search-form">
            <input type="search" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="Tìm người dùng hoặc nội dung">
            <button class="btn btn-secondary" type="submit">Tìm kiếm</button>
            <a class="btn btn-ghost" href="<?= BASE_URL ?>/activity_logs.php">Đặt lại</a>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Thời gian</th>
                <th>Người thực hiện</th>
                <th>Thao tác</th>
                <th>Đối tượng</th>
                <th>Nội dung</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="5" class="empty-cell">Chưa có lịch sử thao tác.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                        <td><?= htmlspecialchars($log['full_name'] ?: 'Hệ thống') ?></td>
                        <td><span class="pill"><?= htmlspecialchars($actionLabels[$log['action']] ?? $log['action']) ?></span></td>
                        <td><?= htmlspecialchars($log['entity_type']) ?><?= $log['entity_id'] ? ' #' . (int) $log['entity_id'] : '' ?></td>
                        <td><?= htmlspecialchars((string) ($log['description'] ?: 'Không có mô tả')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
