<?php
// ============================================================
// GET /api/get_stock.php?token=XXX
// تولید روزانه امروز — فقط مدیر و ناظر
// ============================================================

require_once __DIR__ . '/../config/db.php';

$user = requireAuth();

if ($user['role'] === 'user') {
    jsonError('دسترسی ندارید.', 403);
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    'SELECT salt_type, quantity FROM production WHERE stock_date = CURDATE()'
);
$stmt->execute();
$rows = $stmt->fetchAll();

$production = array_fill_keys(getActiveProductNames(), 0);

foreach ($rows as $row) {
    if (array_key_exists($row['salt_type'], $production)) {
        $production[$row['salt_type']] = (int)$row['quantity'];
    }
}

jsonOk($production);
