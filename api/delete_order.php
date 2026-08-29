<?php
// ============================================================
// POST /api/delete_order.php
// حذف سفارش — مدیر و کاربر (فقط سفارشات خود)
// Body: { "token": "...", "id": 5 }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = isset($body['id']) ? (int)$body['id'] : 0;

if ($id < 1) {
    jsonError('شناسه سفارش نامعتبر است.');
}

$pdo = getDB();

$check = $pdo->prepare('SELECT id, user_id, salt_type, quantity, status, delivery_date FROM orders WHERE id = ?');
$check->execute([$id]);
$order = $check->fetch();

if (!$order) {
    jsonError('سفارش یافت نشد.', 404);
}

// کاربر عادی فقط سفارشات خودش
if ($user['role'] === 'user' && (int)$order['user_id'] !== (int)$user['id']) {
    jsonError('شما فقط سفارشات خود را می‌توانید حذف کنید.', 403);
}

// برگرداندن موجودی فقط اگر سفارش تحویل داده شده باشد
$stockReturned = false;
if ($order['status'] === 'delivered') {
    $prodCheck = $pdo->prepare(
        'SELECT quantity FROM production WHERE stock_date = ? AND salt_type = ?'
    );
    $prodCheck->execute([$order['delivery_date'], $order['salt_type']]);
    $prodRow = $prodCheck->fetch();

    $newQty = $prodRow ? (int)$prodRow['quantity'] + (int)$order['quantity'] : (int)$order['quantity'];

    if ($prodRow) {
        $stmt = $pdo->prepare(
            'UPDATE production SET quantity = ? WHERE stock_date = ? AND salt_type = ?'
        );
        $stmt->execute([$newQty, $order['delivery_date'], $order['salt_type']]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO production (stock_date, salt_type, quantity) VALUES (?, ?, ?)'
        );
        $stmt->execute([$order['delivery_date'], $order['salt_type'], $order['quantity']]);
    }
    $stockReturned = true;
}

// حذف سفارش
$stmt = $pdo->prepare('DELETE FROM orders WHERE id = ?');
$stmt->execute([$id]);

jsonOk([
    'id'              => $id,
    'salt_type'       => $order['salt_type'],
    'quantity'        => (int)$order['quantity'],
    'stock_returned'  => $stockReturned,
], "سفارش «{$order['salt_type']}» حذف شد.");
