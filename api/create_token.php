<?php
// ============================================================
// POST /api/create_token.php
// ساخت توکن جدید — فقط مدیر
// Body: { "name": "نام", "last_name": "نام خانوادگی", "phone": "0912...", "role": "user", "custom_token": "..." }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$name         = trim($body['name'] ?? '');
$lastName     = trim($body['last_name'] ?? '');
$phone        = trim($body['phone'] ?? '');
$role         = trim($body['role'] ?? 'user');
$customToken  = trim($body['custom_token'] ?? '');

// اعتبارسنجی
$validRoles = ['admin', 'viewer', 'user'];

if ($name === '') {
    jsonError('نام را وارد کنید.');
}
if (mb_strlen($name) > 100) {
    jsonError('نام حداکثر ۱۰۰ کاراکتر است.');
}
if ($lastName === '') {
    jsonError('نام خانوادگی را وارد کنید.');
}
if ($phone === '') {
    jsonError('شماره تلفن را وارد کنید.');
}
if (!preg_match('/^0[0-9]{10}$/', $phone)) {
    jsonError('شماره تلفن نامعتبر است (فرمت: 09123456789).');
}
if (!in_array($role, $validRoles, true)) {
    jsonError('نقش نامعتبر است.');
}

$fullName = trim($name . ' ' . $lastName);

$pdo = getDB();

// بررسی توکن سفارشی
if ($customToken !== '') {
    if (mb_strlen($customToken) < 3 || mb_strlen($customToken) > 100) {
        jsonError('توکن سفارشی باید بین ۳ تا ۱۰۰ کاراکتر باشد.');
    }
    if (strpos($customToken, 'hrb_') !== 0) {
        $customToken = 'hrb_' . $customToken;
    }
    $check = $pdo->prepare('SELECT id FROM users WHERE token = ?');
    $check->execute([$customToken]);
    if ($check->fetch()) {
        jsonError('این توکن قبلاً استفاده شده است.');
    }
}

// ساخت توکن جدید
$newToken = ($customToken !== '') ? $customToken : 'hrb_' . bin2hex(random_bytes(16));

$stmt = $pdo->prepare(
    'INSERT INTO users (token, name, last_name, phone, role) VALUES (?, ?, ?, ?, ?)'
);
$stmt->execute([$newToken, $name, $lastName, $phone, $role]);

jsonOk([
    'id'        => (int)$pdo->lastInsertId(),
    'token'     => $newToken,
    'name'      => $fullName,
    'last_name' => $lastName,
    'phone'     => $phone,
    'role'      => $role,
], 'توکن جدید ساخته شد.');
