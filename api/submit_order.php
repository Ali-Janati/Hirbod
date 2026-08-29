<?php
// ============================================================
// POST /api/submit_order.php
// ثبت سفارش (بدون کسر موجودی — فقط کاربر عادی)
// کسر موجودی فقط هنگام تحویل توسط ادمین انجام می‌شود
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();

if ($user['role'] !== 'user') {
    jsonError('فقط کاربران عادی می‌توانند سفارش ثبت کنند.', 403);
}

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$saltType     = trim($body['salt_type'] ?? '');
$quantity     = isset($body['quantity']) ? (int)$body['quantity'] : 0;
$deliveryDate = trim($body['delivery_date'] ?? '');

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

// بررسی ظرفیت: سفارشات تأیید شده تحویل نشده
$stmtPending = $pdo->prepare(
    'SELECT COALESCE(SUM(quantity), 0) AS pending_qty
     FROM orders
     WHERE salt_type = ? AND delivery_date = ? AND status = ?'
);
$stmtPending->execute([$saltType, $deliveryDate, 'confirmed']);
$pendingQty = (int)$stmtPending->fetch()['pending_qty'];

// تولید روزانه
$stmtProd = $pdo->prepare(
    'SELECT quantity FROM production WHERE stock_date = ? AND salt_type = ?'
);
$stmtProd->execute([$deliveryDate, $saltType]);
$prodRow = $stmtProd->fetch();
$dailyProduction = $prodRow ? (int)$prodRow['quantity'] : 0;

if ($dailyProduction > 0 && ($pendingQty + $quantity) > $dailyProduction) {
    jsonError("ظرفیت روز {$deliveryDate} برای {$saltType} کافی نیست. موجود: {$dailyProduction}، در انتظار: {$pendingQty}.");
}

// ثبت سفارش (کسر موجودی هنگام تحویل)
$stmtOrder = $pdo->prepare(
    'INSERT INTO orders (user_id, salt_type, quantity, unit_price, total_price, delivery_date, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmtOrder->execute([$user['id'], $saltType, $quantity, $unitPrice, $totalPrice, $deliveryDate, 'confirmed']);
$orderId = $pdo->lastInsertId();

jsonOk([
    'order_id'      => (int)$orderId,
    'salt_type'     => $saltType,
    'quantity'      => $quantity,
    'unit_price'    => $unitPrice,
    'total_price'   => $totalPrice,
    'delivery_date' => $deliveryDate,
    'status'        => 'confirmed',
], "سفارش {$quantity} عدد {$saltType} ثبت شد.");
