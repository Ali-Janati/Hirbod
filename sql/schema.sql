-- ============================================================
-- اسکیمای دیتابیس هیربد - ثبت سفارش نمک
-- MariaDB / MySQL
-- ============================================================

CREATE DATABASE IF NOT EXISTS hirbad_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hirbad_db;

-- ============================================================
-- جدول کاربران
-- role: 'admin' | 'viewer' | 'user'
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token      VARCHAR(64)  NOT NULL UNIQUE,
    name       VARCHAR(100) NOT NULL,
    role       ENUM('admin','viewer','user') NOT NULL DEFAULT 'user',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- جدول موجودی روزانه (هر روز + هر نوع نمک یک ردیف)
-- ============================================================
CREATE TABLE IF NOT EXISTS stock (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_date DATE         NOT NULL,
    salt_type  VARCHAR(50)  NOT NULL,
    quantity   INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_date_type (stock_date, salt_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- جدول پیش‌بینی (مدیر برای روزهای آینده مقدار می‌گذارد)
-- ============================================================
CREATE TABLE IF NOT EXISTS forecast (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    forecast_date DATE         NOT NULL,
    salt_type     VARCHAR(50)  NOT NULL,
    quantity      INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_forecast_date_type (forecast_date, salt_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- جدول سفارشات
-- status: 'pending' | 'confirmed' | 'rejected'
-- ============================================================
CREATE TABLE IF NOT EXISTS orders (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    salt_type    VARCHAR(50)  NOT NULL,
    quantity     INT UNSIGNED NOT NULL,
    delivery_date DATE        NOT NULL,
    status       ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'confirmed',
    order_date   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- داده‌های اولیه (مثال - توکن‌ها را تغییر دهید!)
-- ============================================================
INSERT INTO users (token, name, role) VALUES
    ('hrb_a7f3c9e2b1d84f6a0e5c8b3d7f2a941',  'مدیر اصلی',   'admin'),
    ('hrb_4e8b2a1c9d7f3e6b0a5c8d2f7e9413b',  'ناظر',         'viewer'),
    ('hrb_9c1d5f8a2b7e4c3d6f0a8b5e2d71394',  'مشتری اول',    'user'),
    ('hrb_2f7a9c4e8b1d5a3f6c0e9b7d4a82156',  'مشتری دوم',    'user'),
    ('hrb_6b3e8d1f5a9c2b7e4f0d6a3c8b51479',  'مشتری سوم',    'user')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ============================================================
-- نمک‌های پایه برای موجودی امروز (اگر وجود نداشت ۰ بگذار)
-- ============================================================
INSERT INTO stock (stock_date, salt_type, quantity)
SELECT CURDATE(), t.salt_type, 0
FROM (
    SELECT 'صورتی' AS salt_type UNION ALL
    SELECT 'آبی'              UNION ALL
    SELECT 'سفید'             UNION ALL
    SELECT 'دریایی'
) t
ON DUPLICATE KEY UPDATE quantity = quantity;

-- جدول محصولات را بسازید
CREATE TABLE IF NOT EXISTS products (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(50) NOT NULL UNIQUE,
    price      DECIMAL(10,2) DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- محصولات پایه را اضافه کنید
INSERT INTO products (name, price, is_active) VALUES
    ('صورتی', 25000, 1),
    ('آبی', 28000, 1),
    ('سفید', 22000, 1),
    ('دریایی', 35000, 1)
ON DUPLICATE KEY UPDATE name = name;

-- محصول جدید خود را اضافه کنید
INSERT INTO products (name, price, is_active) VALUES 
    ('نام_محصول_شما', 30000, 1)
ON DUPLICATE KEY UPDATE name = name;

-- اضافه کردن یک محصول جدید
INSERT INTO products (name, price, is_active) VALUES 
    ('نمک دریاچه', 38000, 1);

-- اضافه کردن چند محصول با هم
INSERT INTO products (name, price, is_active) VALUES 
    ('نمک سیاه', 45000, 1),
    ('نمک لیمو', 32000, 1),
    ('نمک سیر', 29000, 1)
ON DUPLICATE KEY UPDATE 
    price = VALUES(price),
    is_active = VALUES(is_active);