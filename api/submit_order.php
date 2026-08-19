<?php
// ============================================================
// POST /api/submit_order.php
// ثبت سفارش با کسر خودکار موجودی — فقط کاربر عادی
// Body: { "token": "...", "salt_type": "صورتی", "quantity": 5, "delivery_date": "2025-08-20" }
// Response: { success, message, data: { order_id, unit_price, total_price, ... } }
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

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$saltType     = trim($body['salt_type'] ?? '');
$quantity     = isset($body['quantity']) ? (int)$body['quantity'] : 0;
$deliveryDate = trim($body['delivery_date'] ?? '');

// اعتبارسنجی نوع محصول از روی جدول محصولات فعال (به‌جای آرایه ثابت قبلی)
$product = getActiveProductByName($saltType);
if (!$product) {
    jsonError('نوع محصول نامعتبر یا غیرفعال است.');
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

$unitPrice  = (int)$product['price'];
$totalPrice = $unitPrice * $quantity;

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

    // اگر موجودی برای آن روز تنظیم نشده، ۰ در نظر بگیر
    $available = $stockRow ? (int)$stockRow['quantity'] : 0;

    if ($available < $quantity) {
        $pdo->rollBack();
        jsonError('مشکلی پیش آمده، با پشتیبانی تماس بگیرید.');
    }

    // کسر موجودی
    $stmtDeduct = $pdo->prepare(
        'UPDATE stock SET quantity = quantity - ?
         WHERE stock_date = ? AND salt_type = ?'
    );
    $stmtDeduct->execute([$quantity, $deliveryDate, $saltType]);

    // ثبت سفارش با ثبت قیمت لحظه سفارش (تغییر بعدی قیمت روی سفارشات قبلی اثر نمی‌گذارد)
    $stmtOrder = $pdo->prepare(
        'INSERT INTO orders (user_id, salt_type, quantity, unit_price, total_price, delivery_date, status)
         VALUES (?, ?, ?, ?, ?, ?, "confirmed")'
    );
    $stmtOrder->execute([$user['id'], $saltType, $quantity, $unitPrice, $totalPrice, $deliveryDate]);
    $orderId = $pdo->lastInsertId();

    $pdo->commit();

    jsonOk([
        'order_id'      => (int)$orderId,
        'salt_type'     => $saltType,
        'quantity'      => $quantity,
        'unit_price'    => $unitPrice,
        'total_price'   => $totalPrice,
        'delivery_date' => $deliveryDate,
        'status'        => 'confirmed',
    ], "سفارش {$quantity} عدد {$saltType} به مبلغ {$totalPrice} تومان ثبت شد.");

} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('خطای سرور. لطفاً دوباره تلاش کنید.', 500);
}
