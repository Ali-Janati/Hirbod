<?php
// ============================================================
// POST /api/create_token.php
// ساخت توکن جدید — فقط مدیر
// Body: { "name": "نام کاربر", "role": "user|viewer|admin" }
// Response: { success, message, data: { id, token, name, role } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

// فقط POST قبول می‌شه
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

// احراز هویت کاربر و بررسی نقش مدیر
$user = requireAuth();
requireAdmin($user);

// ============================================================
// دریافت بدنه‌ی درخواست (حذف getJsonBody() که وجود نداشت)
// ============================================================
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true) ?? [];

$name = trim($body['name'] ?? '');
$role = trim($body['role'] ?? 'user');

// ============================================================
// اعتبارسنجی ورودی‌ها
// ============================================================
$validRoles = ['admin', 'viewer', 'user'];

if ($name === '') {
    jsonError('نام کاربر را وارد کنید.');
}
if (mb_strlen($name) > 100) {
    jsonError('نام کاربر حداکثر ۱۰۰ کاراکتر است.');
}
if (!in_array($role, $validRoles, true)) {
    jsonError('نقش نامعتبر است.');
}

// ============================================================
// ساخت توکن جدید
// ============================================================
$newToken = 'hrb_' . bin2hex(random_bytes(16));

$pdo = getDB();
$stmt = $pdo->prepare(
    'INSERT INTO users (token, name, role) VALUES (?, ?, ?)'
);
$stmt->execute([$newToken, $name, $role]);

// ============================================================
// پاسخ موفق
// ============================================================
jsonOk([
    'id'    => (int)$pdo->lastInsertId(),
    'token' => $newToken,
    'name'  => $name,
    'role'  => $role,
], 'توکن جدید ساخته شد.');