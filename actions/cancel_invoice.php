<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/invoices.php');
    exit;
}

verify_csrf();

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$reason = trim((string) ($_POST['cancel_reason'] ?? ''));

if ($invoiceId <= 0) {
    $_SESSION['error'] = 'Hóa đơn không hợp lệ.';
    header('Location: ' . BASE_URL . '/invoices.php');
    exit;
}
if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
    $_SESSION['error'] = 'Vui lòng nhập lý do hủy hóa đơn từ 3 đến 255 ký tự.';
    header('Location: ' . BASE_URL . '/invoice_detail.php?id=' . $invoiceId);
    exit;
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $invoiceStmt = $pdo->prepare('SELECT id, invoice_code, status FROM invoices WHERE id = ? FOR UPDATE');
    $invoiceStmt->execute([$invoiceId]);
    $invoice = $invoiceStmt->fetch();

    if (!$invoice) {
        throw new DomainException('Không tìm thấy hóa đơn.');
    }
    if ($invoice['status'] === 'cancelled') {
        throw new DomainException('Hóa đơn đã được hủy trước đó.');
    }

    $detailStmt = $pdo->prepare('SELECT product_id, quantity FROM invoice_details WHERE invoice_id = ?');
    $detailStmt->execute([$invoiceId]);
    $details = $detailStmt->fetchAll();

    $restoreStmt = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
    foreach ($details as $detail) {
        $restoreStmt->execute([(int) $detail['quantity'], (int) $detail['product_id']]);
        if ($restoreStmt->rowCount() !== 1) {
            throw new DomainException('Không thể hoàn trả tồn kho cho một sản phẩm trong hóa đơn.');
        }
    }

    $updateStmt = $pdo->prepare(
        "UPDATE invoices
         SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?
         WHERE id = ? AND status = 'paid'"
    );
    $updateStmt->execute([(int) current_user()['id'], $reason, $invoiceId]);

    if ($updateStmt->rowCount() !== 1) {
        throw new DomainException('Không thể hủy hóa đơn ở trạng thái hiện tại.');
    }

    log_activity(
        $pdo,
        'invoice_cancel',
        'invoice',
        $invoiceId,
        'Hủy hóa đơn ' . $invoice['invoice_code'] . '. Lý do: ' . $reason
    );

    $pdo->commit();
    $_SESSION['success'] = 'Đã hủy hóa đơn và hoàn trả số lượng sản phẩm vào tồn kho.';
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['error'] = $exception instanceof DomainException
        ? $exception->getMessage()
        : 'Không thể hủy hóa đơn. Vui lòng thử lại.';
}

header('Location: ' . BASE_URL . '/invoice_detail.php?id=' . $invoiceId);
exit;
