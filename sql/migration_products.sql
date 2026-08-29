-- ============================================================
-- مایگریشن: افزودن محصولات و قیمت
-- این فایل را روی دیتابیس فعلی (hirbad_db) اجرا کنید
-- ============================================================

USE if0_42703206_hirbad_db;

-- ============================================================
-- جدول محصولات
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(50)     NOT NULL UNIQUE,
    price      DECIMAL(12,0)   NOT NULL DEFAULT 0,
    is_active  TINYINT(1)      NOT NULL DEFAULT 1,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- انتقال انواع نمک قدیمی به جدول محصولات (قیمت را بعداً از پنل ویرایش کنید)
INSERT INTO products (name, price) VALUES
    ('صورتی', 0),
    ('آبی', 0),
    ('سفید', 0),
    ('دریایی', 0)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ============================================================
-- افزودن قیمت واحد و جمع کل به سفارشات
-- ============================================================
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS unit_price  DECIMAL(12,0) NOT NULL DEFAULT 0 AFTER quantity,
    ADD COLUMN IF NOT EXISTS total_price DECIMAL(12,0) NOT NULL DEFAULT 0 AFTER unit_price;
