<?php
// ============================================================
// POST /api/delete_product.php
// حذف محصول — فقط مدیر
// Body: { "token": "...", "id": 3 }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = isset($body['id']) ? (int)$body['id'] : 0;

if ($id < 1) {
    jsonError('شناسه محصول نامعتبر است.');
}

$pdo = getDB();

// بررسی وجود محصول
$check = $pdo->prepare('SELECT id, name FROM products WHERE id = ?');
$check->execute([$id]);
$product = $check->fetch();

if (!$product) {
    jsonError('محصول یافت نشد.', 404);
}

// بررسی اینکه آیا سفارشی با این محصول وجود دارد
$ordersCheck = $pdo->prepare('SELECT COUNT(*) as cnt FROM orders WHERE salt_type = ?');
$ordersCheck->execute([$product['name']]);
$ordersCount = $ordersCheck->fetch()['cnt'];

if ($ordersCount > 0) {
    // اگر سفارش وجود دارد، فقط غیرفعال کن
    $stmt = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
    $stmt->execute([$id]);
    jsonOk([
        'id' => $id,
        'name' => $product['name'],
        'action' => 'deactivated',
        'orders_count' => (int)$ordersCount,
    ], "محصول «{$product['name']}» غیرفعال شد ({$ordersCount} سفارش موجود).");
} else {
    // اگر سفارشی نیست، حذف کامل
    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$id]);
    jsonOk([
        'id' => $id,
        'name' => $product['name'],
        'action' => 'deleted',
    ], "محصول «{$product['name']}» حذف شد.");
}
