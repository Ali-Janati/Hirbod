<?php
// ============================================================
// POST /api/update_order_status.php
// تایید یا رد سفارش — فقط مدیر
// Body: { "order_id": 1, "status": "confirmed" }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$orderId = isset($body['order_id']) ? (int)$body['order_id'] : 0;
$newStatus = trim($body['status'] ?? '');

if ($orderId <= 0) {
    jsonError('شناسه سفارش نامعتبر است.');
}

$allowedStatuses = ['confirmed', 'rejected'];
if (!in_array($newStatus, $allowedStatuses, true)) {
    jsonError('وضعیت نامعتبر است. مجاز: confirmed, rejected');
}

$pdo = getDB();

// Check order exists
$stmt = $pdo->prepare('SELECT id, status, salt_type, quantity, user_id FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    jsonError('سفارش یافت نشد.');
}

if ($order['status'] === 'delivered') {
    jsonError('سفارش تحویل داده شده و قابل تغییر نیست.');
}

if ($order['status'] === $newStatus) {
    jsonError('سفارش قبلاً ' . ($newStatus === 'confirmed' ? 'تایید' : 'رد') . ' شده است.');
}

// Update status
$stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
$stmt->execute([$newStatus, $orderId]);

$statusLabel = $newStatus === 'confirmed' ? 'تایید شد' : 'رد شد';
jsonOk([
    'order_id' => $orderId,
    'old_status' => $order['status'],
    'new_status' => $newStatus,
    'salt_type' => $order['salt_type'],
    'quantity' => $order['quantity'],
], "سفارش {$orderId} {$statusLabel}.");
