<?php
// ============================================================
// GET /api/get_stock.php?token=XXX
// موجودی امروز — فقط مدیر و ناظر
// Response: { success, data: { "صورتی": 30, "آبی": 20, ... } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

$user = requireAuth();

// مشتری‌های عادی حق دیدن موجودی ندارند
if ($user['role'] === 'user') {
    jsonError('دسترسی ندارید.', 403);
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    'SELECT salt_type, quantity FROM stock WHERE stock_date = CURDATE()'
);
$stmt->execute();
$rows = $stmt->fetchAll();

// تبدیل به آبجکت ساده: { "صورتی": 30, ... }
$stock = [];
$saltTypes = ['صورتی', 'آبی', 'سفید', 'دریایی'];

// مقدار پیش‌فرض صفر برای همه نمک‌ها
foreach ($saltTypes as $type) {
    $stock[$type] = 0;
}

foreach ($rows as $row) {
    $stock[$row['salt_type']] = (int)$row['quantity'];
}

jsonOk($stock);
