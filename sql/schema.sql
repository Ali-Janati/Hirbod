-- ============================================================
-- اسکیمای کامل دیتابیس هیربد - ثبت سفارش نمک
-- شامل: کاربران، موجودی، پیش‌بینی، سفارشات، محصولات و قیمت
-- MariaDB / MySQL
-- ============================================================

CREATE DATABASE IF NOT EXISTS if0_42703206_hirbad_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE if0_42703206_hirbad_db;

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
-- جدول محصولات (نوع نمک + قیمت)
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(50)     NOT NULL UNIQUE,
    price      DECIMAL(12,0)   NOT NULL DEFAULT 0,
    is_active  TINYINT(1)      NOT NULL DEFAULT 1,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- جدول موجودی روزانه (هر روز + هر محصول یک ردیف)
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
-- جدول سفارشات (شامل قیمت لحظه سفارش)
-- status: 'pending' | 'confirmed' | 'rejected'
-- ============================================================
CREATE TABLE IF NOT EXISTS orders (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    salt_type     VARCHAR(50)  NOT NULL,
    quantity      INT UNSIGNED NOT NULL,
    unit_price    DECIMAL(12,0) NOT NULL DEFAULT 0,
    total_price   DECIMAL(12,0) NOT NULL DEFAULT 0,
    delivery_date DATE         NOT NULL,
    status        ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'confirmed',
    order_date    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- داده‌های اولیه کاربران (مثال - توکن‌ها را حتماً تغییر دهید!)
-- ============================================================
INSERT INTO users (token, name, role) VALUES
    ('ADMIN-TOKEN-CHANGE-ME',  'مدیر اصلی',   'admin'),
    ('NAZER-TOKEN-CHANGE-ME',  'ناظر',         'viewer'),
    ('USER1-TOKEN-CHANGE-ME',  'مشتری اول',    'user'),
    ('USER2-TOKEN-CHANGE-ME',  'مشتری دوم',    'user'),
    ('USER3-TOKEN-CHANGE-ME',  'مشتری سوم',    'user')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ============================================================
-- محصولات پایه (قیمت را از پنل مدیر ویرایش کنید)
-- ============================================================
INSERT INTO products (name, price, is_active) VALUES
    ('صورتی', 0, 1),
    ('آبی', 0, 1),
    ('سفید', 0, 1),
    ('دریایی', 0, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ============================================================
-- موجودی امروز برای محصولات پایه (پیش‌فرض صفر)
-- ============================================================
INSERT INTO stock (stock_date, salt_type, quantity)
SELECT CURDATE(), p.name, 0
FROM products p
WHERE p.is_active = 1
ON DUPLICATE KEY UPDATE quantity = quantity;
