<?php
// ============================================================
// POST /api/submit_order.php
// ثبت سفارش با کسر خودکار موجودی — فقط کاربر عادی
// Body: { "token": "...", "salt_type": "صورتی", "quantity": 5, "delivery_date": "2025-08-20" }
// Response: { success, message, data: { order_id, ... } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();

// مدیر و ناظر نمی‌توانند سفارش بدهند
if ($user['role'] !== 'user') {
    jsonError('فقط کاربران عادی می‌توانند سفارش ثبت کنند.', 403);
}

$body         = getJsonBody();
$saltType     = trim($body['salt_type'] ?? '');
$quantity     = isset($body['quantity']) ? (int)$body['quantity'] : 0;
$deliveryDate = trim($body['delivery_date'] ?? '');

// اعتبارسنجی اولیه
if (empty($saltType)) {
    jsonError('نوع نمک نمی‌تواند خالی باشد.');
}
if ($quantity < 1) {
    jsonError('تعداد باید حداقل ۱ باشد.');
}
if (empty($deliveryDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
    jsonError('تاریخ دریافت نامعتبر است.');
}
if ($deliveryDate < date('Y-m-d')) {
    jsonError('تاریخ دریافت نمی‌تواند در گذشته باشد.');
}

$pdo = getDB();

// ============================================================
// بررسی وجود محصول در جدول products (داینامیک)
// ============================================================
try {
    $stmt = $pdo->prepare("SELECT id, name, price, is_active FROM products WHERE name = ? AND is_active = 1");
    $stmt->execute([$saltType]);
    $product = $stmt->fetch();
    
    if (!$product) {
        jsonError('نوع نمک نامعتبر است. لطفاً محصول معتبر انتخاب کنید.');
    }
} catch (PDOException $e) {
    jsonError('خطا در بررسی محصول: ' . $e->getMessage(), 500);
}

// ============================================================
// تراکنش: بررسی موجودی + کسر + ثبت سفارش — همه یا هیچ
// ============================================================
$pdo->beginTransaction();

try {
    // قفل ردیف موجودی برای جلوگیری از race condition
    $stmtStock = $pdo->prepare(
        'SELECT quantity FROM stock
         WHERE stock_date = ? AND salt_type = ?
         FOR UPDATE'
    );
    $stmtStock->execute([$deliveryDate, $saltType]);
    $stockRow = $stmtStock->fetch();

    // اگر ردیف موجودی وجود نداشت، ۰ در نظر بگیر (یا از پیش‌بینی بخوان)
    $available = $stockRow ? (int)$stockRow['quantity'] : 0;

    if ($available < $quantity) {
        $pdo->rollBack();
        jsonError('موجودی کافی نیست. موجودی فعلی: ' . $available . ' - درخواستی: ' . $quantity);
    }

    // کسر موجودی (با شرط quantity >= برای جلوگیری از race condition)
    $stmtDeduct = $pdo->prepare(
        'UPDATE stock SET quantity = quantity - ?
         WHERE stock_date = ? AND salt_type = ? AND quantity >= ?'
    );
    $stmtDeduct->execute([$quantity, $deliveryDate, $saltType, $quantity]);

    if ($stmtDeduct->rowCount() === 0) {
        $pdo->rollBack();
        jsonError('موجودی کافی نیست. لطفاً تاریخ یا تعداد را تغییر دهید.');
    }

    // ثبت سفارش
    $stmtOrder = $pdo->prepare(
        'INSERT INTO orders (user_id, salt_type, quantity, delivery_date, status)
         VALUES (?, ?, ?, ?, "confirmed")'
    );
    $stmtOrder->execute([$user['id'], $saltType, $quantity, $deliveryDate]);
    $orderId = $pdo->lastInsertId();

    $pdo->commit();

    // دریافت اطلاعات کامل سفارش
    $stmt = $pdo->prepare("
        SELECT o.*, u.name as user_name 
        FROM orders o
        JOIN users u ON u.id = o.user_id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    jsonOk([
        'order_id'      => (int)$orderId,
        'salt_type'     => $saltType,
        'quantity'      => $quantity,
        'delivery_date' => $deliveryDate,
        'status'        => 'confirmed',
        'user'          => $user['name'],
        'product_price' => $product['price'],
        'total_price'   => $product['price'] * $quantity,
        'remaining_stock' => $available - $quantity
    ], "سفارش {$quantity} عدد {$saltType} با موفقیت ثبت شد.");

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Database error in submit_order.php: ' . $e->getMessage());
    jsonError('خطای سرور. لطفاً دوباره تلاش کنید.', 500);
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('خطای سرور. لطفاً دوباره تلاش کنید.', 500);
}
?>