<?php
// ============================================================
// POST /api/deliver_order.php
// تحویل سفارش توسط ادمین — کسر هوشمند موجودی + تغییر وضعیت
// اگر کیلوگرم کم باشه، از بسته‌ها هم کم می‌شه
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
        'SELECT o.id, o.salt_type, o.quantity, o.delivery_date, o.status,
                p.unit, p.weight_per_package
         FROM orders o
         JOIN products p ON p.name = o.salt_type
         WHERE o.id = ? FOR UPDATE'
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

    $saltType          = $order['salt_type'];
    $quantity          = (int)$order['quantity'];
    $deliveryDate      = $order['delivery_date'];
    $unit              = $order['unit'];
    $weightPerPackage  = (float)$order['weight_per_package'];

    // ─── محاسبه مقدار سفارش به کیلوگرم ───
    $orderKg = ($unit === 'کیلوگرم') ? (float)$quantity : ($weightPerPackage > 0 ? $weightPerPackage * $quantity : (float)$quantity);

    // ─── دریافت موجودی تولید با قفل ───
    $stmtProd = $pdo->prepare(
        'SELECT quantity_kg, quantity_pkg FROM production WHERE stock_date = ? AND salt_type = ? FOR UPDATE'
    );
    $stmtProd->execute([$deliveryDate, $saltType]);
    $prodRow = $stmtProd->fetch();
    $availableKg  = $prodRow ? (float)$prodRow['quantity_kg'] : 0;
    $availablePkg = $prodRow ? (int)$prodRow['quantity_pkg'] : 0;

    // ─── کسر هوشمند ───
    $remainingKg = $orderKg;

    // اول از کیلوگرم آزاد کم کن
    if ($availableKg >= $remainingKg) {
        $availableKg -= $remainingKg;
        $remainingKg = 0;
    } else {
        $remainingKg -= $availableKg;
        $availableKg = 0;
    }

    // بعد اگه هنوز کم داری، از بسته‌ها کم کن
    $deductPkg = 0;
    if ($remainingKg > 0 && $weightPerPackage > 0) {
        $deductPkg = (int)ceil($remainingKg / $weightPerPackage);
        if ($availablePkg >= $deductPkg) {
            $availablePkg -= $deductPkg;
            $remainingKg = 0;
        } else {
            $pdo->rollBack();
            $totalKg = $availableKg + ($weightPerPackage > 0 ? $availablePkg * $weightPerPackage : 0);
            jsonError("موجودی تولید کافی نیست. موجود: {$totalKg} کیلو، نیاز: {$orderKg} کیلو.");
        }
    } elseif ($remainingKg > 0) {
        $pdo->rollBack();
        jsonError("موجودی تولید کافی نیست.");
    }

    // ─── آپدیت موجودی ───
    $stmtDeduct = $pdo->prepare(
        'UPDATE production SET quantity_kg = ?, quantity_pkg = ? WHERE stock_date = ? AND salt_type = ?'
    );
    $stmtDeduct->execute([$availableKg, $availablePkg, $deliveryDate, $saltType]);

    // ─── تغییر وضعیت سفارش ───
    $stmtUpdate = $pdo->prepare(
        'UPDATE orders SET status = ?, delivered_at = NOW() WHERE id = ?'
    );
    $stmtUpdate->execute(['delivered', $orderId]);

    $pdo->commit();

    $deductInfo = "کسر شد: {$orderKg} کیلو";
    if ($deductPkg > 0) {
        $deductInfo .= " (شامل {$deductPkg} بسته)";
    }

    jsonOk([
        'order_id'      => $orderId,
        'status'        => 'delivered',
        'deducted_kg'   => $orderKg,
        'deducted_pkg'  => $deductPkg,
        'remaining_kg'  => $availableKg,
        'remaining_pkg' => $availablePkg,
    ], "سفارش {$orderId} تحویل داده شد. {$deductInfo}.");

} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('خطای سرور.', 500);
}
