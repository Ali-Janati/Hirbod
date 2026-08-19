<?php
// ============================================================
// GET /api/list_products.php?token=XXX[&all=1]
// لیست محصولات — بدون &all=1 فقط محصولات فعال (برای کاربر عادی)
// با &all=1 همه محصولات شامل غیرفعال (فقط مدیر)
// Response: { success, data: [ { id, name, price, is_active } ] }
// ============================================================

require_once __DIR__ . '/../config/db.php';

$user = requireAuth();

$showAll = isset($_GET['all']) && $_GET['all'] == '1';

if ($showAll) {
    requireAdmin($user);
    $pdo  = getDB();
    $stmt = $pdo->query('SELECT id, name, price, is_active FROM products ORDER BY id ASC');
} else {
    $pdo  = getDB();
    $stmt = $pdo->query('SELECT id, name, price, is_active FROM products WHERE is_active = 1 ORDER BY id ASC');
}

$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['id']        = (int)$r['id'];
    $r['price']     = (int)$r['price'];
    $r['is_active'] = (int)$r['is_active'];
}
unset($r);

jsonOk($rows);
