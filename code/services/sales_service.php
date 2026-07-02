<?php

declare(strict_types=1);

function normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function new_voucher_code(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = 'TV' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM vouchers WHERE code=?');
        $stmt->execute([$code]);
        if ((int) $stmt->fetchColumn() === 0) return $code;
    }
    throw new RuntimeException('Không thể tạo mã voucher mới.');
}

function checkout_order(PDO $pdo, array &$cart, array $input, int $userId, string $expectedToken): array
{
    $customerNameInput = trim((string) ($input['customer_name'] ?? ''));
    $customerPhone = normalize_phone((string) ($input['customer_phone'] ?? ''));
    $voucherCode = strtoupper(trim((string) ($input['voucher_code'] ?? '')));
    $customerMoney = filter_var($input['customer_money'] ?? null, FILTER_VALIDATE_FLOAT);
    $paymentMethod = (string) ($input['payment_method'] ?? 'cash');
    $postedToken = (string) ($input['checkout_token'] ?? '');

    if (!$cart) throw new RuntimeException('Giỏ hàng đang trống.');
    if ($postedToken === '' || !hash_equals($expectedToken, $postedToken)) throw new RuntimeException('Giao dịch đã được xử lý hoặc phiên thanh toán không hợp lệ.');
    if ($customerPhone !== '' && !preg_match('/^[0-9]{9,11}$/', $customerPhone)) throw new RuntimeException('Số điện thoại phải gồm từ 9 đến 11 chữ số.');
    if (!in_array($paymentMethod, ['cash','transfer','qr'], true)) $paymentMethod = 'cash';

    $pdo->beginTransaction();
    try {
        $items = [];
        $subtotalAmount = 0.0;
        $priceChanges = [];
        foreach ($cart as $cartItem) {
            $stmt = $pdo->prepare('SELECT id,code,name,price,stock,status FROM products WHERE id=? FOR UPDATE');
            $stmt->execute([(int) $cartItem['id']]);
            $product = $stmt->fetch();
            $quantity = (int) $cartItem['quantity'];
            if (!$product || (int) $product['status'] !== 1 || (int) $product['stock'] < $quantity) {
                throw new RuntimeException('Sản phẩm ' . ($cartItem['name'] ?? '') . ' không còn đủ số lượng.');
            }
            $oldPrice = (float) $cartItem['price'];
            $currentPrice = (float) $product['price'];
            if (abs($oldPrice - $currentPrice) > 0.001) {
                $productId = (int) $product['id'];
                $cart[$productId]['price'] = $currentPrice;
                $priceChanges[] = $product['name'] . ': ' . money($oldPrice) . ' → ' . money($currentPrice);
            }
            $subtotal = $currentPrice * $quantity;
            $subtotalAmount += $subtotal;
            $items[] = [$product, $quantity, $subtotal];
        }
        if ($priceChanges) {
            throw new RuntimeException('Giá sản phẩm vừa thay đổi: ' . implode('; ', $priceChanges) . '. Vui lòng kiểm tra lại.');
        }

        $customer = null;
        $customerId = null;
        $customerName = $customerNameInput !== '' ? $customerNameInput : 'Khách lẻ';
        if ($customerPhone !== '') {
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE phone=? FOR UPDATE');
            $stmt->execute([$customerPhone]);
            $customer = $stmt->fetch();
            if ($customer) {
                $customerId = (int) $customer['id'];
                $customerName = $customerNameInput !== '' ? $customerNameInput : (string) $customer['name'];
                if ($customerNameInput !== '' && $customerNameInput !== $customer['name']) {
                    $pdo->prepare('UPDATE customers SET name=? WHERE id=?')->execute([$customerNameInput, $customerId]);
                }
            } else {
                if ($customerNameInput === '') throw new RuntimeException('Khách hàng mới cần nhập họ tên.');
                $pdo->prepare('INSERT INTO customers(name,phone) VALUES(?,?)')->execute([$customerNameInput, $customerPhone]);
                $customerId = (int) $pdo->lastInsertId();
                $customer = ['id'=>$customerId,'name'=>$customerNameInput,'phone'=>$customerPhone,'points'=>0,'total_spent'=>0];
                $customerName = $customerNameInput;
            }
        } elseif ($voucherCode !== '') {
            throw new RuntimeException('Cần nhập số điện thoại thành viên để sử dụng voucher.');
        }

        $voucherId = null;
        $discountAmount = 0.0;
        if ($voucherCode !== '') {
            $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE code=? AND customer_id=? FOR UPDATE');
            $stmt->execute([$voucherCode, $customerId]);
            $voucher = $stmt->fetch();
            if (!$voucher) throw new RuntimeException('Voucher không tồn tại hoặc không thuộc khách hàng này.');
            if ($voucher['status'] !== 'available') throw new RuntimeException('Voucher đã được sử dụng hoặc không còn hiệu lực.');
            if (strtotime((string) $voucher['expires_at']) < time()) throw new RuntimeException('Voucher đã hết hạn.');
            if ($subtotalAmount < (float) $voucher['min_order']) throw new RuntimeException('Hóa đơn chưa đạt giá trị tối thiểu để dùng voucher.');
            $discountAmount = $voucher['discount_type'] === 'percent'
                ? $subtotalAmount * ((float) $voucher['discount_value'] / 100)
                : (float) $voucher['discount_value'];
            $discountAmount = min($discountAmount, $subtotalAmount);
            $voucherId = (int) $voucher['id'];
        }

        $totalAmount = max(0, $subtotalAmount - $discountAmount);
        if ($customerMoney === false || $customerMoney < $totalAmount) throw new RuntimeException('Tiền khách đưa chưa đủ để thanh toán.');
        $pointsEarned = $customerId !== null ? (int) floor($totalAmount / 10000) : 0;
        $invoiceCode = 'HD' . date('YmdHis') . random_int(10, 99);

        $stmt = $pdo->prepare("INSERT INTO invoices(invoice_code,checkout_token,user_id,customer_id,customer_name,customer_phone,subtotal_amount,discount_amount,total_amount,voucher_id,points_earned,customer_money,change_money,payment_method,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'paid')");
        $stmt->execute([$invoiceCode,$postedToken,$userId,$customerId,$customerName,$customerPhone!==''?$customerPhone:null,$subtotalAmount,$discountAmount,$totalAmount,$voucherId,$pointsEarned,$customerMoney,$customerMoney-$totalAmount,$paymentMethod]);
        $invoiceId = (int) $pdo->lastInsertId();

        $detail = $pdo->prepare('INSERT INTO invoice_details(invoice_id,product_id,product_code,product_name,quantity,price,subtotal) VALUES(?,?,?,?,?,?,?)');
        $updateStock = $pdo->prepare('UPDATE products SET stock=stock-?, updated_at=NOW() WHERE id=?');
        foreach ($items as [$product, $quantity, $subtotal]) {
            $detail->execute([$invoiceId,$product['id'],$product['code'],$product['name'],$quantity,$product['price'],$subtotal]);
            $updateStock->execute([$quantity,$product['id']]);
        }

        if ($voucherId !== null) {
            $pdo->prepare("UPDATE vouchers SET status='used', used_at=NOW(), used_invoice_id=? WHERE id=?")->execute([$invoiceId,$voucherId]);
        }

        $issuedVouchers = [];
        if ($customerId !== null && $customer) {
            $newPoints = (int) $customer['points'] + $pointsEarned;
            $voucherCount = intdiv($newPoints, 100);
            $remainingPoints = $newPoints % 100;
            $newTotalSpent = (float) $customer['total_spent'] + $totalAmount;
            $pdo->prepare('UPDATE customers SET points=?, total_spent=? WHERE id=?')->execute([$remainingPoints,$newTotalSpent,$customerId]);
            for ($index=0; $index<$voucherCount; $index++) {
                $rewardCode = new_voucher_code($pdo);
                $stmt = $pdo->prepare("INSERT INTO vouchers(customer_id,code,name,discount_type,discount_value,min_order,expires_at,status,source_invoice_id,points_cost) VALUES(?,?,?,'fixed',20000,100000,DATE_ADD(NOW(), INTERVAL 30 DAY),'available',?,100)");
                $stmt->execute([$customerId,$rewardCode,'Voucher thành viên giảm 20.000 đ',$invoiceId]);
                $issuedVouchers[] = $rewardCode;
            }
        }

        $pdo->commit();
        $message = 'Thanh toán thành công. Hóa đơn ' . $invoiceCode . ' đã được tạo.';
        if ($pointsEarned > 0) $message .= ' Khách hàng được cộng ' . $pointsEarned . ' điểm.';
        if ($issuedVouchers) $message .= ' Voucher mới: ' . implode(', ', $issuedVouchers) . '.';
        return ['invoice_id'=>$invoiceId,'invoice_code'=>$invoiceCode,'total_amount'=>$totalAmount,'message'=>$message];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
