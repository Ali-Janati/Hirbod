<?php
// ============================================================
// POST /api/submit_order.php
// ثبت سفارش (بدون کسر موجودی — فقط کاربر عادی)
// کسر موجودی فقط هنگام تحویل توسط ادمین انجام می‌شود
// محاسبه هوشمند: اگر کیلوگرم کم باشه، از بسته‌ها هم حساب می‌شه
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

$unit              = $product['unit'];           // 'بسته' or 'کیلوگرم'
$weightPerPackage  = (float)$product['weight_per_package'];
$unitPrice         = (int)$product['price'];
$totalPrice        = $unitPrice * $quantity;

$pdo = getDB();

// ─── محاسبه سفارش بر اساس واحد ───
if ($unit === 'کیلوگرم') {
    $orderKg = (float)$quantity;
} else {
    // بسته: هر بسته چند کیلو؟
    $orderKg = $weightPerPackage > 0 ? $weightPerPackage * $quantity : (float)$quantity;
}

// ─── بررسی ظرفیت ───
// سفارشات تأیید شده تحویل نشده (تبدیل به کیلو)
$stmtPending = $pdo->prepare(
    'SELECT o.salt_type, o.quantity, p.unit, p.weight_per_package
     FROM orders o
     JOIN products p ON p.name = o.salt_type
     WHERE o.salt_type = ? AND o.delivery_date = ? AND o.status = ?'
);
$stmtPending->execute([$saltType, $deliveryDate, 'confirmed']);
$pendingRows = $stmtPending->fetchAll();
$pendingKg = 0;
foreach ($pendingRows as $pr) {
    if ($pr['unit'] === 'کیلوگرم') {
        $pendingKg += (float)$pr['quantity'];
    } else {
        $wpp = (float)$pr['weight_per_package'];
        $pendingKg += ($wpp > 0 ? $wpp * $pr['quantity'] : (float)$pr['quantity']);
    }
}

// تولید روزانه (کیلو + بسته)
$stmtProd = $pdo->prepare(
    'SELECT quantity_kg, quantity_pkg FROM production WHERE stock_date = ? AND salt_type = ?'
);
$stmtProd->execute([$deliveryDate, $saltType]);
$prodRow = $stmtProd->fetch();
$availableKg  = $prodRow ? (float)$prodRow['quantity_kg'] : 0;
$availablePkg = $prodRow ? (int)$prodRow['quantity_pkg'] : 0;

// ظرفیت کل به کیلوگرم
$totalCapacityKg = $availableKg + ($weightPerPackage > 0 ? $availablePkg * $weightPerPackage : 0);

// مجموع سفارشات در انتظار + سفارش جدید
$totalPendingKg = $pendingKg + $orderKg;

if ($totalCapacityKg > 0 && $totalPendingKg > $totalCapacityKg) {
    $remainingKg = max(0, $totalCapacityKg - $pendingKg);
    jsonError(
        "ظرفیت روز {$deliveryDate} برای {$saltType} کافی نیست.\n" .
        "ظرفیت کل: " . round($totalCapacityKg, 1) . " کیلو ({$availableKg} کیلو آزاد + {$availablePkg} بسته).\n" .
        "در انتظار: " . round($pendingKg, 1) . " کیلو.\n" .
        "موجود برای سفارش جدید: " . round($remainingKg, 1) . " کیلو.\n" .
        "سفارش شما: " . round($orderKg, 1) . " کیلو."
    );
}

// ثبت سفارش (کسر موجودی هنگام تحویل)
$stmtOrder = $pdo->prepare(
    'INSERT INTO orders (user_id, salt_type, quantity, unit_price, total_price, delivery_date, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmtOrder->execute([$user['id'], $saltType, $quantity, $unitPrice, $totalPrice, $deliveryDate, 'pending']);
$orderId = $pdo->lastInsertId();

jsonOk([
    'order_id'      => (int)$orderId,
    'salt_type'     => $saltType,
    'quantity'      => $quantity,
    'unit'          => $unit,
    'unit_price'    => $unitPrice,
    'total_price'   => $totalPrice,
    'delivery_date' => $deliveryDate,
    'status'        => 'pending',
    'approx_kg'     => round($orderKg, 1),
], "سفارش {$quantity} {$unit} {$saltType} ثبت شد (≈{$orderKg} کیلو).");
