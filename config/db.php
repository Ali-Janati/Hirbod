<?php
// ============================================================
// تنظیمات اتصال به دیتابیس — محیط محلی (XAMPP)
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'hirbad_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/**
 * اتصال PDO به دیتابیس (singleton)
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

/**
 * هدرهای مشترک JSON + CORS
 */
function setJsonHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

/**
 * پاسخ موفق JSON
 */
function jsonOk(array $data = [], string $message = 'ok'): never {
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * پاسخ خطا JSON
 */
function jsonError(string $message, int $httpCode = 400): never {
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => $message, 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * بررسی توکن و برگرداندن اطلاعات کاربر
 */
function requireAuth(): array {
    $token = '';

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $token = trim(substr($authHeader, 7));
    }

    if (empty($token)) {
        $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
    }

    if (empty($token)) {
        jsonError('توکن ارسال نشده است.', 401);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id, name, role FROM users WHERE token = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonError('توکن نامعتبر است.', 401);
    }

    return $user;
}

/**
 * بررسی نقش مدیر (admin)
 */
function requireAdmin(array $user): void {
    if ($user['role'] !== 'admin') {
        jsonError('دسترسی ندارید.', 403);
    }
}

/**
 * لیست محصولات فعال
 */
function getActiveProducts(): array {
    $pdo = getDB();
    $stmt = $pdo->query('SELECT id, name, price FROM products WHERE is_active = 1 ORDER BY id ASC');
    return $stmt->fetchAll();
}

/**
 * فقط اسم محصولات فعال
 */
function getActiveProductNames(): array {
    return array_column(getActiveProducts(), 'name');
}

/**
 * گرفتن یک محصول فعال بر اساس نام
 */
function getActiveProductByName(string $name): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id, name, price FROM products WHERE name = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// پاسخ به درخواست‌های preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    setJsonHeaders();
    http_response_code(204);
    exit;
}

setJsonHeaders();
