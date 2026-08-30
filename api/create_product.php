<?php
// ============================================================
// POST /api/create_product.php
// افزودن محصول جدید — فقط مدیر
// Body: { "name": "...", "price": 15000, "unit": "بسته", "weight_per_package": 5 }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$body               = json_decode(file_get_contents('php://input'), true) ?? [];
$name               = trim($body['name'] ?? '');
$price              = isset($body['price']) ? (int)$body['price'] : -1;
$unit               = trim($body['unit'] ?? 'بسته');
$weightPerPackage   = isset($body['weight_per_package']) ? (float)$body['weight_per_package'] : 0;

if (empty($name) || mb_strlen($name) > 50) {
    jsonError('نام محصول نامعتبر است.');
}
if ($price < 0) {
    jsonError('قیمت نمی‌تواند منفی باشد.');
}
if (!in_array($unit, ['بسته', 'کیلوگرم'], true)) {
    jsonError('واحد نامعتبر است. فقط «بسته» یا «کیلوگرم» قبول می‌شود.');
}
if ($unit === 'بسته' && $weightPerPackage <= 0) {
    jsonError('برای محصول «بسته‌ای» وزن هر بسته (کیلوگرم) الزامی است.');
}

$pdo = getDB();

// بررسی تکراری نبودن نام
$check = $pdo->prepare('SELECT id FROM products WHERE name = ?');
$check->execute([$name]);
if ($check->fetch()) {
    jsonError('محصولی با این نام قبلاً ثبت شده است.');
}

$stmt = $pdo->prepare('INSERT INTO products (name, price, is_active, unit, weight_per_package) VALUES (?, ?, 1, ?, ?)');
$stmt->execute([$name, $price, $unit, $weightPerPackage]);
$id = $pdo->lastInsertId();

jsonOk([
    'id'                  => (int)$id,
    'name'                => $name,
    'price'               => $price,
    'unit'                => $unit,
    'weight_per_package'  => $weightPerPackage,
], "محصول «{$name}» اضافه شد.");
