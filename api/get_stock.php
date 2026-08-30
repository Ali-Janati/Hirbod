<?php
// ============================================================
// GET /api/get_stock.php?token=XXX
// تولید روزانه امروز — شامل kg و بسته
// ============================================================

require_once __DIR__ . '/../config/db.php';

$user = requireAuth();

if ($user['role'] === 'user') {
    jsonError('دسترسی ندارید.', 403);
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    'SELECT salt_type, quantity_kg, quantity_pkg FROM production WHERE stock_date = CURDATE()'
);
$stmt->execute();
$rows = $stmt->fetchAll();

$products = getActiveProducts();
$production = [];
foreach ($products as $p) {
    $production[$p['name']] = [
        'quantity_kg'  => 0,
        'quantity_pkg' => 0,
        'unit'         => $p['unit'],
        'weight_per_package' => $p['weight_per_package'],
    ];
}

foreach ($rows as $row) {
    if (array_key_exists($row['salt_type'], $production)) {
        $production[$row['salt_type']]['quantity_kg']  = (float)$row['quantity_kg'];
        $production[$row['salt_type']]['quantity_pkg'] = (int)$row['quantity_pkg'];
    }
}

jsonOk($production);
