<?php
/**
 * API: تنظیم موجودی روزانه (فقط مدیر)
 * Method: POST
 * Parameters: token, salt_type, quantity
 */

// شامل کردن فایل اتصال دیتابیس
require_once __DIR__ . '/../config/db.php';

// هدرها توسط تابع setJsonHeaders() در config/db.php تنظیم می‌شود
setJsonHeaders();

// مدیریت درخواست OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// فقط متد POST مجاز است
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط متد POST مجاز است.', 405);
}

// دریافت داده‌ها از بدنه JSON
$input = getJsonBody();

// اعتبارسنجی ورودی‌ها
if (!isset($input['salt_type']) || !isset($input['quantity'])) {
    jsonError('پارامترهای salt_type و quantity الزامی هستند.');
}

$salt_type = trim($input['salt_type']);
$quantity = intval($input['quantity']);

// اعتبارسنجی مقدار quantity
if ($quantity < 0) {
    jsonError('مقدار موجودی نمی‌تواند منفی باشد.');
}

try {
    // 1. احراز هویت کاربر (توکن)
    $user = requireAuth();
    
    // 2. بررسی نقش مدیر
    requireAdmin($user);
    
    // 3. بررسی وجود محصول در جدول products
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, name FROM products WHERE name = ? AND is_active = 1");
    $stmt->execute([$salt_type]);
    $product = $stmt->fetch();
    
    if (!$product) {
        jsonError('نوع نمک نامعتبر است. لطفاً ابتدا محصول را به جدول products اضافه کنید.');
    }
    
    // 4. به‌روزرسانی موجودی در جدول stock
    $today = date('Y-m-d');
    
    // ابتدا بررسی کن اگر رکورد امروز وجود ندارد، ایجاد کن
    $stmt = $pdo->prepare("INSERT INTO stock (stock_date, salt_type, quantity) 
                           VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE quantity = ?");
    $stmt->execute([$today, $salt_type, $quantity, $quantity]);
    
    // 5. دریافت اطلاعات به‌روز شده
    $stmt = $pdo->prepare("SELECT * FROM stock WHERE stock_date = ? AND salt_type = ?");
    $stmt->execute([$today, $salt_type]);
    $updatedStock = $stmt->fetch();
    
    jsonOk([
        'salt_type' => $salt_type,
        'quantity' => $quantity,
        'stock_date' => $today,
        'updated_at' => $updatedStock['updated_at'] ?? date('Y-m-d H:i:s')
    ], 'موجودی با موفقیت به‌روزرسانی شد.');
    
} catch (PDOException $e) {
    error_log('Database error in set_stock.php: ' . $e->getMessage());
    jsonError('خطای داخلی سرور: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    jsonError($e->getMessage(), 400);
}
?>