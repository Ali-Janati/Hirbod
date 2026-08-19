<?php
// ============================================================
// POST /api/set_stock.php
// تنظیم موجودی یک نوع محصول برای امروز — فقط مدیر
// Body: { "token": "...", "salt_type": "صورتی", "quantity": 30 }
// Response: { success, message, data: { salt_type, quantity, date } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$saltType  = trim($body['salt_type'] ?? '');
$quantity  = isset($body['quantity']) ? (int)$body['quantity'] : -1;

// اعتبارسنجی از روی جدول محصولات فعال (به‌جای آرایه ثابت قبلی)
if (!in_array($saltType, getActiveProductNames(), true)) {
    jsonError('نوع محصول نامعتبر یا غیرفعال است.');
}
if ($quantity < 0) {
    jsonError('تعداد نمی‌تواند منفی باشد.');
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    'INSERT INTO stock (stock_date, salt_type, quantity)
     VALUES (CURDATE(), ?, ?)
     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
);
$stmt->execute([$saltType, $quantity]);

jsonOk([
    'salt_type' => $saltType,
    'quantity'  => $quantity,
    'date'      => date('Y-m-d'),
], "موجودی {$saltType} به {$quantity} تنظیم شد.");
