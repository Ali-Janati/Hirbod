<?php
// ============================================================
// GET /api/all_orders.php?token=XXX[&date=2025-08-20][&salt_type=صورتی]
// همه سفارشات — فقط مدیر و ناظر
// فیلترهای اختیاری: date, salt_type
// Response: { success, data: [ { id, user_name, salt_type, quantity, delivery_date, status, order_date } ] }
// ============================================================

require_once __DIR__ . '/../config/db.php';

$user = requireAuth();

if ($user['role'] === 'user') {
    jsonError('دسترسی ندارید.', 403);
}

// فیلترهای اختیاری
$filterDate     = trim($_GET['date'] ?? '');
$filterSaltType = trim($_GET['salt_type'] ?? '');

$sql    = 'SELECT o.id, u.name AS user_name, o.salt_type, o.quantity,
                  o.delivery_date, o.status,
                  DATE_FORMAT(o.order_date, "%Y-%m-%d") AS order_date
           FROM orders o
           JOIN users u ON o.user_id = u.id
           WHERE 1=1';
$params = [];

if (!empty($filterDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $sql    .= ' AND o.delivery_date = ?';
    $params[] = $filterDate;
}
if (!empty($filterSaltType)) {
    $sql    .= ' AND o.salt_type = ?';
    $params[] = $filterSaltType;
}

$sql .= ' ORDER BY o.delivery_date ASC, o.id DESC';

$pdo  = getDB();
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

foreach ($orders as &$o) {
    $o['id']       = (int)$o['id'];
    $o['quantity'] = (int)$o['quantity'];
}
unset($o);

jsonOk($orders);
