# هیربد — بک‌اند PHP/MariaDB

## ساختار فایل‌ها

```
hirbad-backend/
├── api/
│   ├── login.php          ← ورود با توکن
│   ├── get_stock.php      ← موجودی امروز (مدیر/ناظر)
│   ├── set_stock.php      ← تنظیم موجودی (مدیر)
│   ├── submit_order.php   ← ثبت سفارش (کاربر)
│   ├── my_orders.php      ← سفارشات کاربر جاری
│   ├── all_orders.php     ← همه سفارشات (مدیر/ناظر)
│   ├── save_forecast.php  ← ذخیره پیش‌بینی (مدیر)
│   └── get_forecast.php   ← دریافت پیش‌بینی (مدیر/ناظر)
├── config/
│   └── db.php             ← اتصال دیتابیس + توابع کمکی
├── sql/
│   └── schema.sql         ← ساختار جداول + داده اولیه
├── index.html             ← فرانت‌اند (آپدیت‌شده با API)
└── README.md              ← همین فایل
```

---

## مراحل نصب روی هاست

### ۱. آپلود فایل‌ها

```
public_html/
├── index.html          ← فرانت‌اند
└── api/                ← پوشه بک‌اند
    ├── login.php
    ├── get_stock.php
    ├── ...
    └── config/         ← ترجیحاً خارج از public_html بگذارید
        └── db.php
```

> **توصیه امنیتی:** پوشه `config/` را یک سطح بالاتر از `public_html` بگذارید و مسیر `require_once` را در فایل‌های API تنظیم کنید.

### ۲. ساخت دیتابیس در هاست

۱. وارد cPanel → phpMyAdmin شوید  
۲. یک دیتابیس جدید بسازید: `hirbad_db`  
۳. یک یوزر دیتابیس بسازید و به آن دسترسی کامل بدهید  
۴. محتوای `sql/schema.sql` را اجرا کنید (Import → انتخاب فایل)

### ۳. تنظیم `config/db.php`

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'hirbad_db');
define('DB_USER', 'نام_کاربر_دیتابیس');
define('DB_PASS', 'رمز_دیتابیس');
```

### ۴. تنظیم آدرس API در `index.html`

خط زیر را پیدا کنید:

```javascript
const API_BASE = 'https://yourdomain.com/api';
```

آدرس دامنه‌ی خود را جایگزین کنید، مثلاً:

```javascript
const API_BASE = 'https://hirbad.ir/api';
```

### ۵. تنظیم توکن‌های کاربران

توکن‌های نمونه در `schema.sql` فقط برای تست هستند.  
برای تغییر آنها در phpMyAdmin جدول `users` را ویرایش کنید یا این SQL را اجرا کنید:

```sql
-- حذف داده‌های نمونه
DELETE FROM users;

-- افزودن کاربران واقعی
INSERT INTO users (token, name, role) VALUES
  ('TOKEN-واقعی-مدیر',    'علی احمدی',    'admin'),
  ('TOKEN-واقعی-کاربر1',  'حسن رضایی',    'user'),
  ('TOKEN-واقعی-کاربر2',  'مریم محمدی',   'user');
```

**توکن باید:** تصادفی، طولانی و غیرقابل حدس باشد.  
برای تولید توکن امن: `openssl rand -hex 16`

---

## تست با Postman (یا مرورگر)

### ورود
```
POST https://yourdomain.com/api/login.php
Body: { "token": "TOKEN-واقعی-مدیر" }
```

### ثبت سفارش
```
POST https://yourdomain.com/api/submit_order.php
Body: {
  "token": "TOKEN-واقعی-کاربر1",
  "salt_type": "صورتی",
  "quantity": 5,
  "delivery_date": "2025-08-25"
}
```

### موجودی امروز
```
GET https://yourdomain.com/api/get_stock.php?token=TOKEN-واقعی-مدیر
```

---

## نکات مهم

- همه خروجی‌ها JSON هستند با فرمت: `{ success: bool, message: string, data: ... }`
- در صورت خطا `success: false` برمی‌گردد
- موجودی با تراکنش دیتابیس کسر می‌شود (race condition وجود ندارد)
- مشتریان عادی به موجودی دسترسی ندارند (سرور چک می‌کند)
- ناظر فقط می‌تواند ببیند، نه تغییر دهد
