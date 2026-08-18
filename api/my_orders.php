<?php
// ============================================================
// GET /api/my_orders.php?token=XXX
// سفارشات کاربر جاری
// Response: { success, data: [ { id, salt_type, quantity, delivery_date, status, order_date } ] }
// ============================================================

require_once __DIR__ . '/../config/db.php';

$user = requireAuth();

if ($user['role'] !== 'user') {
    jsonError('فقط کاربران عادی می‌توانند سفارشات خود را ببینند.', 403);
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    'SELECT id, salt_type, quantity, delivery_date, status,
            DATE_FORMAT(order_date, "%Y-%m-%d") AS order_date
     FROM orders
     WHERE user_id = ?
     ORDER BY order_date DESC, id DESC'
);
$stmt->execute([$user['id']]);
$orders = $stmt->fetchAll();

// تبدیل نوع‌ها به عدد
foreach ($orders as &$o) {
    $o['id']       = (int)$o['id'];
    $o['quantity'] = (int)$o['quantity'];
}
unset($o);

jsonOk($orders);
