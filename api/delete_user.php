<?php
// ============================================================
// POST /api/delete_user.php
// حذف کاربر — فقط مدیر
// Body: { "token": "...", "id": 3 }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('فقط درخواست POST قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = isset($body['id']) ? (int)$body['id'] : 0;

if ($id < 1) {
    jsonError('شناسه کاربر نامعتبر است.');
}

// جلوگیری از حذف خود مدیر
if ($id === (int)$user['id']) {
    jsonError('نمی‌توانید خودتان را حذف کنید.');
}

$pdo = getDB();

// بررسی وجود کاربر
$check = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ?');
$check->execute([$id]);
$target = $check->fetch();

if (!$target) {
    jsonError('کاربر یافت نشد.', 404);
}

// حذف کاربر (CASCADE سفارشات رو هم حذف می‌کند)
$stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
$stmt->execute([$id]);

jsonOk([
    'id' => $id,
    'name' => $target['name'],
    'role' => $target['role'],
], "کاربر «{$target['name']}» حذف شد.");
