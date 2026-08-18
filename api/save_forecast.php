<?php
// ============================================================
// POST /api/save_forecast.php
// ذخیره پیش‌بینی چند روزه — فقط مدیر
// Body: {
//   "token": "...",
//   "forecast": {
//     "2025-08-21": { "صورتی": 30, "آبی": 20, "سفید": 10, "دریایی": 5 },
//     "2025-08-22": { ... }
//   }
// }
// Response: { success, message, data: { saved_rows: N } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$body     = getJsonBody();
$forecast = $body['forecast'] ?? null;

if (!is_array($forecast) || empty($forecast)) {
    jsonError('داده‌ی پیش‌بینی ارسال نشده است.');
}

$validTypes = ['صورتی', 'آبی', 'سفید', 'دریایی'];
$today      = date('Y-m-d');
$pdo        = getDB();

$stmt = $pdo->prepare(
    'INSERT INTO forecast (forecast_date, salt_type, quantity)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
);

// همگام‌سازی پیش‌بینی با موجودی واقعی (سفارش‌ها از جدول stock کسر می‌کنند)
$stmtStock = $pdo->prepare(
    'INSERT INTO stock (stock_date, salt_type, quantity)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
);

$savedRows = 0;

foreach ($forecast as $date => $types) {
    // اعتبارسنجی تاریخ
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date <= $today) {
        continue; // تاریخ‌های گذشته یا نامعتبر را رد کن
    }

    if (!is_array($types)) {
        continue;
    }

    foreach ($types as $saltType => $qty) {
        if (!in_array($saltType, $validTypes, true)) {
            continue;
        }
        $qty = max(0, (int)$qty);
        $stmt->execute([$date, $saltType, $qty]);
        $stmtStock->execute([$date, $saltType, $qty]);
        $savedRows++;
    }
}

jsonOk(['saved_rows' => $savedRows], "پیش‌بینی ذخیره شد ({$savedRows} ردیف).");
