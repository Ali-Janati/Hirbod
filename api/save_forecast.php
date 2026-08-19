<?php
// ============================================================
// POST /api/save_forecast.php
// ذخیره پیش‌بینی چند روزه — فقط مدیر
// Body: {
//   "token": "...",
//   "forecast": {
//     "2025-08-21": { "صورتی": 30, "آبی": 20 },
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

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$forecast = $body['forecast'] ?? null;

if (!is_array($forecast) || empty($forecast)) {
    jsonError('داده‌ی پیش‌بینی ارسال نشده است.');
}

// اعتبارسنجی از روی جدول محصولات فعال (به‌جای آرایه ثابت قبلی)
$validTypes = getActiveProductNames();
$today      = date('Y-m-d');
$pdo        = getDB();

$stmt = $pdo->prepare(
    'INSERT INTO forecast (forecast_date, salt_type, quantity)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
);

$savedRows = 0;

foreach ($forecast as $date => $types) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date <= $today) {
        continue;
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
        $savedRows++;
    }
}

jsonOk(['saved_rows' => $savedRows], "پیش‌بینی ذخیره شد ({$savedRows} ردیف).");
