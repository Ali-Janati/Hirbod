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
// Response: { success, message, data: { saved_rows: N, errors: [...] } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

setJsonHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

try {
    $user = requireAuth();
    requireAdmin($user);

    $body = getJsonBody();
    $forecast = $body['forecast'] ?? null;

    if (!is_array($forecast) || empty($forecast)) {
        jsonError('داده‌ی پیش‌بینی ارسال نشده است.');
    }

    $pdo = getDB();
    $today = date('Y-m-d');
    $errors = [];
    $savedRows = 0;

    // ============================================================
    // دریافت لیست محصولات فعال از دیتابیس (داینامیک)
    // ============================================================
    $stmt = $pdo->query("SELECT name FROM products WHERE is_active = 1");
    $validTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($validTypes)) {
        jsonError('هیچ محصول فعالی در سیستم وجود ندارد. لطفاً ابتدا محصول اضافه کنید.', 404);
    }

    // ============================================================
    // آماده‌سازی کوئری‌ها
    // ============================================================
    $stmt = $pdo->prepare(
        'INSERT INTO forecast (forecast_date, salt_type, quantity)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE 
             quantity = VALUES(quantity),
             updated_at = CURRENT_TIMESTAMP'
    );

    // همگام‌سازی پیش‌بینی با موجودی واقعی (سفارش‌ها از جدول stock کسر می‌کنند)
    $stmtStock = $pdo->prepare(
        'INSERT INTO stock (stock_date, salt_type, quantity)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE 
             quantity = VALUES(quantity),
             updated_at = CURRENT_TIMESTAMP'
    );

    // ============================================================
    // پردازش پیش‌بینی‌ها
    // ============================================================
    foreach ($forecast as $date => $types) {
        // اعتبارسنجی تاریخ
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = "تاریخ نامعتبر: {$date}";
            continue;
        }
        
        if ($date <= $today) {
            $errors[] = "تاریخ {$date} در گذشته است و نمی‌توان پیش‌بینی ثبت کرد.";
            continue;
        }

        if (!is_array($types)) {
            $errors[] = "داده‌های تاریخ {$date} نامعتبر است.";
            continue;
        }

        foreach ($types as $saltType => $qty) {
            // بررسی وجود محصول در لیست محصولات فعال
            if (!in_array($saltType, $validTypes, true)) {
                $errors[] = "نوع نمک '{$saltType}' در تاریخ {$date} نامعتبر است. محصولات فعال: " . implode(', ', $validTypes);
                continue;
            }
            
            $qty = max(0, (int)$qty);
            
            // ذخیره در forecast
            $stmt->execute([$date, $saltType, $qty]);
            
            // همگام‌سازی با stock (برای موجودی روزانه)
            $stmtStock->execute([$date, $saltType, $qty]);
            
            $savedRows++;
        }
    }

    // ============================================================
    // پاسخ نهایی
    // ============================================================
    $response = ['saved_rows' => $savedRows];
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
        $response['warning'] = 'برخی از داده‌ها ذخیره نشدند.';
    }

    $message = $savedRows > 0 
        ? "پیش‌بینی ذخیره شد ({$savedRows} ردیف)." 
        : "هیچ داده‌ای ذخیره نشد.";

    if (!empty($errors)) {
        $message .= " تعداد خطا: " . count($errors);
    }

    jsonOk($response, $message);

} catch (PDOException $e) {
    error_log('Database error in save_forecast.php: ' . $e->getMessage());
    jsonError('خطای داخلی سرور. لطفاً دوباره تلاش کنید.', 500);
} catch (Exception $e) {
    jsonError($e->getMessage(), 400);
}
?>