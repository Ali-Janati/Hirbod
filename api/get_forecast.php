<?php
// ============================================================
// GET /api/get_forecast.php?token=XXX[&days=20]
// پیش‌بینی روزهای آینده — فقط مدیر و ناظر
// days: تعداد روز (پیش‌فرض ۲۰، حداکثر ۶۰)
// Response: { success, data: { "2025-08-21": { "صورتی": 30, ... }, ... } }
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

    // فقط مدیر و ناظر مجاز هستند
    if ($user['role'] === 'user') {
        jsonError('دسترسی ندارید. فقط مدیر و ناظر می‌توانند پیش‌بینی را ببینند.', 403);
    }

    $days = min(60, max(1, (int)($_GET['days'] ?? 20)));
    $pdo = getDB();

    // ============================================================
    // دریافت لیست محصولات فعال از دیتابیس (داینامیک)
    // ============================================================
    $stmt = $pdo->query("SELECT name FROM products WHERE is_active = 1 ORDER BY name");
    $activeProducts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($activeProducts)) {
        jsonError('هیچ محصول فعالی در سیستم وجود ندارد.', 404);
    }

    // ============================================================
    // دریافت پیش‌بینی‌ها از دیتابیس
    // ============================================================
    $stmt = $pdo->prepare(
        'SELECT forecast_date, salt_type, quantity
         FROM forecast
         WHERE forecast_date > CURDATE()
           AND forecast_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
         ORDER BY forecast_date ASC'
    );
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll();

    // ساختار: date → { type → qty }
    $result = [];

    // مقداردهی پیش‌فرض صفر برای همه روزها و همه محصولات فعال
    for ($i = 1; $i <= $days; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} days"));
        $result[$date] = array_fill_keys($activeProducts, 0);
    }

    // پر کردن مقادیر موجود
    foreach ($rows as $row) {
        $d = $row['forecast_date'];
        $t = $row['salt_type'];
        if (isset($result[$d]) && in_array($t, $activeProducts)) {
            $result[$d][$t] = (int)$row['quantity'];
        }
    }

    jsonOk([
        'forecast' => $result,
        'products' => $activeProducts,
        'days' => $days,
        'date_range' => [
            'start' => date('Y-m-d', strtotime("+1 days")),
            'end' => date('Y-m-d', strtotime("+{$days} days"))
        ]
    ], 'پیش‌بینی با موفقیت دریافت شد.');

} catch (PDOException $e) {
    error_log('Database error in get_forecast.php: ' . $e->getMessage());
    jsonError('خطای داخلی سرور. لطفاً دوباره تلاش کنید.', 500);
} catch (Exception $e) {
    jsonError($e->getMessage(), 400);
}
?>