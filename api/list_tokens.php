<?php
// ============================================================
// GET /api/list_tokens.php?token=...
// لیست کاربران و توکن‌ها — فقط مدیر
// Response: { success, message, data: [ { id, token, name, role, is_active, created_at } ] }
// ============================================================

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('فقط درخواست GET قبول می‌شود.', 405);
}

$user = requireAuth();
requireAdmin($user);

$pdo  = getDB();
$stmt = $pdo->query(
    'SELECT id, token, name, last_name, phone, role, is_active, created_at
     FROM users
     ORDER BY created_at DESC'
);

jsonOk($stmt->fetchAll(), 'لیست توکن‌ها');
