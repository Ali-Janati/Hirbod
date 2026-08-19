<?php
// ============================================================
// POST /api/create_product.php
// افزودن محصول جدید — فقط مدیر
// Body: { "token": "...", "name": "نمک لیمویی", "price": 15000 }
// Response: { success, message, data: { id, name, price } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$name  = trim($body['name'] ?? '');
$price = isset($body['price']) ? (int)$body['price'] : -1;

if (empty($name) || mb_strlen($name) > 50) {
    jsonError('نام محصول نامعتبر است.');
}
if ($price < 0) {
    jsonError('قیمت نمی‌تواند منفی باشد.');
}

$pdo = getDB();

// بررسی تکراری نبودن نام
$check = $pdo->prepare('SELECT id FROM products WHERE name = ?');
$check->execute([$name]);
if ($check->fetch()) {
    jsonError('محصولی با این نام قبلاً ثبت شده است.');
}

$stmt = $pdo->prepare('INSERT INTO products (name, price, is_active) VALUES (?, ?, 1)');
$stmt->execute([$name, $price]);
$id = $pdo->lastInsertId();

jsonOk([
    'id'    => (int)$id,
    'name'  => $name,
    'price' => $price,
], "محصول «{$name}» اضافه شد.");
