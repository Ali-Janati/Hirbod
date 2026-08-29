<?php
// ============================================================
// POST /api/deliver_order.php
// تحویل سفارش توسط ادمین — کسر موجودی + تغییر وضعیت
// Body: { "token": "...", "order_id": 123 }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$orderId = isset($body['order_id']) ? (int)$body['order_id'] : 0;

if ($orderId < 1) {
    jsonError('شناسه سفارش نامعتبر است.');
}

$pdo = getDB();
$pdo->beginTransaction();

try {
    // دریافت سفارش با قفل
    $stmt = $pdo->prepare(
        'SELECT id, salt_type, quantity, delivery_date, status
         FROM orders WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        jsonError('سفارش یافت نشد.');
    }
    if ($order['status'] !== 'confirmed') {
        $pdo->rollBack();
        jsonError('فقط سفارشات تأیید شده قابل تحویل هستند.');
    }

    // بررسی موجودی تولید
    $stmtProd = $pdo->prepare(
        'SELECT quantity FROM production WHERE stock_date = ? AND salt_type = ? FOR UPDATE'
    );
    $stmtProd->execute([$order['delivery_date'], $order['salt_type']]);
    $prodRow = $stmtProd->fetch();
    $available = $prodRow ? (int)$prodRow['quantity'] : 0;

    if ($available < $order['quantity']) {
        $pdo->rollBack();
        jsonError("موجودی تولید کافی نیست. موجود: {$available}، سفارش: {$order['quantity']}.");
    }

    // کسر موجودی
    $stmtDeduct = $pdo->prepare(
        'UPDATE production SET quantity = quantity - ? WHERE stock_date = ? AND salt_type = ?'
    );
    $stmtDeduct->execute([$order['quantity'], $order['delivery_date'], $order['salt_type']]);

    // تغییر وضعیت سفارش
    $stmtUpdate = $pdo->prepare(
        'UPDATE orders SET status = ?, delivered_at = NOW() WHERE id = ?'
    );
    $stmtUpdate->execute(['delivered', $orderId]);

    $pdo->commit();

    jsonOk([
        'order_id'  => $orderId,
        'status'    => 'delivered',
    ], "سفارش {$orderId} تحویل داده شد و موجودی کسر شد.");

} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('خطای سرور.', 500);
}
