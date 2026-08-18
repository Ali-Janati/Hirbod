<?php
// ============================================================
// GET /api/get_forecast.php?token=XXX[&days=20]
// پیش‌بینی روزهای آینده — فقط مدیر و ناظر
// days: تعداد روز (پیش‌فرض ۲۰، حداکثر ۶۰)
// Response: { success, data: { "2025-08-21": { "صورتی": 30, ... }, ... } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

$user = requireAuth();

if ($user['role'] === 'user') {
    jsonError('دسترسی ندارید.', 403);
}

$days = min(60, max(1, (int)($_GET['days'] ?? 20)));

$pdo  = getDB();
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
$saltTypes = ['صورتی', 'آبی', 'سفید', 'دریایی'];
$result    = [];

// مقداردهی پیش‌فرض صفر برای همه روزها و همه نمک‌ها
for ($i = 1; $i <= $days; $i++) {
    $date = date('Y-m-d', strtotime("+{$i} days"));
    $result[$date] = array_fill_keys($saltTypes, 0);
}

foreach ($rows as $row) {
    $d = $row['forecast_date'];
    $t = $row['salt_type'];
    if (isset($result[$d])) {
        $result[$d][$t] = (int)$row['quantity'];
    }
}

jsonOk($result);
