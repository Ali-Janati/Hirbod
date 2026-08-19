<?php
// ============================================================
// POST /api/login.php
// ورود با توکن و برگرداندن نقش و نام کاربر
// Body: { "token": "USER-TOKEN-HERE" }
// Response: { success, message, data: { id, name, role } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$token = trim($body['token'] ?? $_POST['token'] ?? '');

if (empty($token)) {
    jsonError('توکن را وارد کنید.');
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    'SELECT id, name, role FROM users WHERE token = ? AND is_active = 1 LIMIT 1'
);
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    jsonError('توکن نامعتبر است.', 401);
}

jsonOk([
    'id'   => $user['id'],
    'name' => $user['name'],
    'role' => $user['role'],
], 'ورود موفق');
