<?php
// ============================================================
// POST /api/set_stock.php
// تنظیم تولید روزانه — فقط مدیر
// Body: { "salt_type": "صورتی", "quantity_kg": 100, "quantity_pkg": 20 }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$saltType  = trim($body['salt_type'] ?? '');
$quantityKg   = isset($body['quantity_kg']) ? (float)$body['quantity_kg'] : -1;
$quantityPkg  = isset($body['quantity_pkg']) ? (int)$body['quantity_pkg'] : -1;

if (!in_array($saltType, getActiveProductNames(), true)) {
    jsonError('نوع محصول نامعتبر یا غیرفعال است.');
}
if ($quantityKg < 0 || $quantityPkg < 0) {
    jsonError('مقدار نمی‌تواند منفی باشد.');
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    'INSERT INTO production (stock_date, salt_type, quantity, quantity_kg, quantity_pkg)
     VALUES (CURDATE(), ?, 0, ?, ?)
     ON DUPLICATE KEY UPDATE quantity_kg = VALUES(quantity_kg), quantity_pkg = VALUES(quantity_pkg)'
);
$stmt->execute([$saltType, $quantityKg, $quantityPkg]);

jsonOk([
    'salt_type'     => $saltType,
    'quantity_kg'   => $quantityKg,
    'quantity_pkg'  => $quantityPkg,
    'date'          => date('Y-m-d'),
], "تولید روزانه {$saltType}: {$quantityKg} کیلو + {$quantityPkg} بسته تنظیم شد.");
