<?php
// ============================================================
// POST /api/submit_order.php
// ثبت سفارش با کسر خودکار موجودی — فقط کاربر عادی
// Body: { "token": "...", "salt_type": "صورتی", "quantity": 5, "delivery_date": "2025-08-20" }
// Response: { success, message, data: { order_id, ... } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();

// مدیر و ناظر نمی‌توانند سفارش بدهند
if ($user['role'] !== 'user') {
    jsonError('فقط کاربران عادی می‌توانند سفارش ثبت کنند.', 403);
}

$body         = getJsonBody();
$saltType     = trim($body['salt_type'] ?? '');
$quantity     = isset($body['quantity']) ? (int)$body['quantity'] : 0;
$deliveryDate = trim($body['delivery_date'] ?? '');

// اعتبارسنجی
$validTypes = ['صورتی', 'آبی', 'سفید', 'دریایی'];
if (!in_array($saltType, $validTypes, true)) {
    jsonError('نوع نمک نامعتبر است.');
}
if ($quantity < 1) {
    jsonError('تعداد باید حداقل ۱ باشد.');
}
if (empty($deliveryDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
    jsonError('تاریخ دریافت نامعتبر است.');
}
if ($deliveryDate < date('Y-m-d')) {
    jsonError('تاریخ دریافت نمی‌تواند در گذشته باشد.');
}

$pdo = getDB();

// ============================================================
// تراکنش: بررسی موجودی + کسر + ثبت سفارش — همه یا هیچ
// ============================================================
$pdo->beginTransaction();

try {
    // قفل ردیف موجودی برای جلوگیری از race condition
    $stmtStock = $pdo->prepare(
        'SELECT quantity FROM stock
         WHERE stock_date = ? AND salt_type = ?
         FOR UPDATE'
    );
    $stmtStock->execute([$deliveryDate, $saltType]);
    $stockRow = $stmtStock->fetch();

    // اگر ردیف موجودی وجود نداشت، ۰ در نظر بگیر (یا از پیش‌بینی بخوان)
    $available = $stockRow ? (int)$stockRow['quantity'] : 0;

    if ($available < $quantity) {
        $pdo->rollBack();
        jsonError('موجودی کافی نیست. لطفاً تاریخ یا تعداد را تغییر دهید.');
    }

    // کسر موجودی (با شرط quantity >= برای جلوگیری از race condition)
    $stmtDeduct = $pdo->prepare(
        'UPDATE stock SET quantity = quantity - ?
         WHERE stock_date = ? AND salt_type = ? AND quantity >= ?'
    );
    $stmtDeduct->execute([$quantity, $deliveryDate, $saltType, $quantity]);

    if ($stmtDeduct->rowCount() === 0) {
        $pdo->rollBack();
        jsonError('موجودی کافی نیست. لطفاً تاریخ یا تعداد را تغییر دهید.');
    }

    // ثبت سفارش
    $stmtOrder = $pdo->prepare(
        'INSERT INTO orders (user_id, salt_type, quantity, delivery_date, status)
         VALUES (?, ?, ?, ?, "confirmed")'
    );
    $stmtOrder->execute([$user['id'], $saltType, $quantity, $deliveryDate]);
    $orderId = $pdo->lastInsertId();

    $pdo->commit();

    jsonOk([
        'order_id'      => (int)$orderId,
        'salt_type'     => $saltType,
        'quantity'      => $quantity,
        'delivery_date' => $deliveryDate,
        'status'        => 'confirmed',
    ], "سفارش {$quantity} عدد {$saltType} ثبت شد.");

} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('خطای سرور. لطفاً دوباره تلاش کنید.', 500);
}
