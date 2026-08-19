#!/bin/bash
# ============================================================
# نصب هیربد روی سرور لینوکس محلی
# IP: 192.168.100.25
# ============================================================
set -e

INSTALL_DIR="/var/www/html/hirbad"
CONFIG_DIR="/var/www/hirbad-config"
DB_NAME="hirbad_db"
DB_USER="hirbad_user"
DB_PASS="hirbad_pass_2026"
SERVER_IP="192.168.100.25"

echo "=== نصب هیربد ==="

# وابستگی‌ها
if command -v apt-get &>/dev/null; then
    sudo apt-get update
    sudo apt-get install -y apache2 mariadb-server php php-mysql libapache2-mod-php
elif command -v dnf &>/dev/null; then
    sudo dnf install -y httpd mariadb-server php php-mysqlnd
    sudo systemctl enable --now httpd mariadb
fi

# ساخت پوشه‌ها
sudo mkdir -p "$INSTALL_DIR/api" "$CONFIG_DIR"

# کپی فایل‌ها (از پوشه‌ای که اسکریپت در آن اجرا می‌شود)
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
sudo cp "$SCRIPT_DIR/index.html" "$INSTALL_DIR/"
sudo cp "$SCRIPT_DIR/api/"*.php "$INSTALL_DIR/api/"
sudo cp "$SCRIPT_DIR/deploy/db.local.php" "$CONFIG_DIR/db.php"

# لینک config (require_once ../config/db.php)
sudo ln -sfn "$CONFIG_DIR" "$INSTALL_DIR/config"

# دیتابیس
sudo mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

sudo mysql -u root "$DB_NAME" < "$SCRIPT_DIR/sql/schema.sql"

# Apache — دسترسی به پوشه hirbad
sudo cp "$SCRIPT_DIR/deploy/apache-hirbad.conf" /etc/apache2/conf-available/hirbad.conf 2>/dev/null \
    || sudo cp "$SCRIPT_DIR/deploy/apache-hirbad.conf" /etc/httpd/conf.d/hirbad.conf

sudo a2enconf hirbad 2>/dev/null || true
sudo a2enmod rewrite 2>/dev/null || true

sudo chown -R www-data:www-data "$INSTALL_DIR" 2>/dev/null \
    || sudo chown -R apache:apache "$INSTALL_DIR"
sudo chmod -R 755 "$INSTALL_DIR"
sudo chmod 640 "$CONFIG_DIR/db.php"

sudo systemctl restart apache2 2>/dev/null || sudo systemctl restart httpd

echo ""
echo "=== نصب کامل شد ==="
echo "آدرس اپ: http://$SERVER_IP/hirbad/"
echo "API:     http://$SERVER_IP/hirbad/api/"
echo "دیتابیس: $DB_NAME | کاربر: $DB_USER | رمز: $DB_PASS"
echo ""
echo "توکن‌ها در فایل deploy/TOKENS.md موجود است."
