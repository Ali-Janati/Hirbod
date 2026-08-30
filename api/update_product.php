<?php
// ============================================================
// POST /api/update_product.php
// ویرایش محصول — فقط مدیر
// Body: { "id": 3, "name"?: "...", "price"?: 12000, "is_active"?: 0|1, "unit"?: "بسته"|"کیلوگرم", "weight_per_package"?: 5 }
// فقط فیلدهایی که ارسال شوند تغییر می‌کنند
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

$pdo   = getDB();
$check = $pdo->prepare('SELECT id, name, price, is_active, unit, weight_per_package FROM products WHERE id = ?');
$check->execute([$id]);
$product = $check->fetch();

if (!$product) {
    jsonError('محصول یافت نشد.', 404);
}

$name               = $product['name'];
$price              = $product['price'];
$isActive           = $product['is_active'];
$unit               = $product['unit'];
$weightPerPackage   = $product['weight_per_package'];

if (isset($body['name'])) {
    $newName = trim($body['name']);
    if (empty($newName) || mb_strlen($newName) > 50) {
        jsonError('نام محصول نامعتبر است.');
    }
    $name = $newName;
}

if (isset($body['price'])) {
    $newPrice = (int)$body['price'];
    if ($newPrice < 0) {
        jsonError('قیمت نمی‌تواند منفی باشد.');
    }
    $price = $newPrice;
}

if (isset($body['is_active'])) {
    $isActive = ((int)$body['is_active'] === 1) ? 1 : 0;
}

if (isset($body['unit'])) {
    if (!in_array($body['unit'], ['بسته', 'کیلوگرم'], true)) {
        jsonError('واحد نامعتبر است.');
    }
    $unit = $body['unit'];
}

if (isset($body['weight_per_package'])) {
    $weightPerPackage = (float)$body['weight_per_package'];
}

if ($unit === 'بسته' && $weightPerPackage <= 0) {
    jsonError('برای محصول «بسته‌ای» وزن هر بسته الزامی است.');
}

$stmt = $pdo->prepare('UPDATE products SET name = ?, price = ?, is_active = ?, unit = ?, weight_per_package = ? WHERE id = ?');
$stmt->execute([$name, $price, $isActive, $unit, $weightPerPackage, $id]);

jsonOk([
    'id'                  => $id,
    'name'                => $name,
    'price'               => (int)$price,
    'is_active'           => (int)$isActive,
    'unit'                => $unit,
    'weight_per_package'  => $weightPerPackage,
], 'محصول به‌روزرسانی شد.');
