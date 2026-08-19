<?php
// ============================================================
// GET /api/my_orders.php?token=XXX
// سفارشات کاربر جاری به همراه قیمت و جمع کل
// Response: { success, data: [ { id, salt_type, quantity, price, total_price, delivery_date, status, order_date } ] }
// ============================================================

require_once __DIR__ . '/../config/db.php';

setJsonHeaders();

// مدیریت درخواست OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('فقط متد GET مجاز است.', 405);
}

try {
    $user = requireAuth();

    // فقط کاربران عادی می‌توانند سفارشات خود را ببینند
    if ($user['role'] !== 'user') {
        jsonError('فقط کاربران عادی می‌توانند سفارشات خود را ببینند.', 403);
    }

    $pdo = getDB();
    
    // ============================================================
    // کوئری با JOIN به جدول products برای دریافت قیمت
    // ============================================================
    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            o.salt_type,
            o.quantity,
            COALESCE(p.price, 0) as price,
            (o.quantity * COALESCE(p.price, 0)) as total_price,
            o.delivery_date,
            o.status,
            DATE_FORMAT(o.order_date, '%Y-%m-%d %H:%i:%s') as order_date,
            COALESCE(p.is_active, 0) as product_active,
            CASE 
                WHEN p.price IS NULL THEN 'محصول حذف شده یا غیرفعال'
                ELSE 'فعال'
            END as product_status
        FROM orders o
        LEFT JOIN products p ON p.name = o.salt_type
        WHERE o.user_id = ?
        ORDER BY o.order_date DESC, o.id DESC
    ");
    $stmt->execute([$user['id']]);
    $orders = $stmt->fetchAll();

    // تبدیل نوع‌ها به عدد
    foreach ($orders as &$o) {
        $o['id'] = (int)$o['id'];
        $o['quantity'] = (int)$o['quantity'];
        $o['price'] = (float)$o['price'];
        $o['total_price'] = (float)$o['total_price'];
        $o['product_active'] = (bool)$o['product_active'];
    }
    unset($o);

    // اگر سفارشی وجود نداشت
    if (empty($orders)) {
        jsonOk([], 'هیچ سفارشی برای شما یافت نشد.');
    }

    // محاسبه مجموع کل سفارشات
    $totalOrders = count($orders);
    $totalAmount = array_sum(array_column($orders, 'total_price'));

    jsonOk([
        'orders' => $orders,
        'summary' => [
            'total_orders' => $totalOrders,
            'total_amount' => $totalAmount,
            'currency' => 'تومان'
        ]
    ], "{$totalOrders} سفارش یافت شد.");

} catch (PDOException $e) {
    error_log('Database error in my_orders.php: ' . $e->getMessage());
    jsonError('خطای داخلی سرور. لطفاً دوباره تلاش کنید.', 500);
} catch (Exception $e) {
    jsonError($e->getMessage(), 400);
}
?>