<?php
// ============================================================
// GET /api/get_stats.php?token=XXX
// آمار داشبورد — مدیر و ناظر
// Response: { success, data: { today_orders, month_sales, top_product, pending_count, sales_chart, product_pie, recent_orders, active_users } }
// ============================================================

require_once __DIR__ . '/../config/db.php';

$user = requireAuth();
if ($user['role'] === 'user') {
    jsonError('دسترسی ندارید.', 403);
}

$pdo = getDB();
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$weekAgo = date('Y-m-d', strtotime('-7 days'));

// 1. سفارشات امروز
$stmt = $pdo->prepare('SELECT COUNT(*) as cnt, COALESCE(SUM(total_price),0) as total FROM orders WHERE delivery_date = ?');
$stmt->execute([$today]);
$todayStats = $stmt->fetch();

// 2. فروش ماه جاری
$stmt = $pdo->prepare('SELECT COUNT(*) as cnt, COALESCE(SUM(total_price),0) as total FROM orders WHERE delivery_date >= ? AND delivery_date <= ?');
$stmt->execute([$monthStart, $monthEnd]);
$monthStats = $stmt->fetch();

// 3. فروش ماه قبل (برای مقایسه)
$prevMonthStart = date('Y-m-01', strtotime('-1 month'));
$prevMonthEnd = date('Y-m-t', strtotime('-1 month'));
$stmt = $pdo->prepare('SELECT COALESCE(SUM(total_price),0) as total FROM orders WHERE delivery_date >= ? AND delivery_date <= ?');
$stmt->execute([$prevMonthStart, $prevMonthEnd]);
$prevMonthTotal = $stmt->fetch()['total'];

// 4. پرفروش‌ترین محصول
$stmt = $pdo->query('SELECT salt_type, SUM(quantity) as total_qty, SUM(total_price) as total_sales FROM orders WHERE status = "confirmed" GROUP BY salt_type ORDER BY total_sales DESC LIMIT 1');
$topProduct = $stmt->fetch();

// 5. سفارشات در انتظار
$stmt = $pdo->query('SELECT COUNT(*) as cnt FROM orders WHERE status = "pending"');
$pendingCount = $stmt->fetch()['cnt'];

// 6. نمودار فروش ۳۰ روز اخیر
$salesChart = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $salesChart[$date] = 0;
}
$stmt = $pdo->prepare('SELECT delivery_date, SUM(total_price) as daily_total FROM orders WHERE delivery_date >= ? AND delivery_date <= ? GROUP BY delivery_date');
$stmt->execute([date('Y-m-d', strtotime('-29 days')), $today]);
foreach ($stmt->fetchAll() as $row) {
    if (isset($salesChart[$row['delivery_date']])) {
        $salesChart[$row['delivery_date']] = (int)$row['daily_total'];
    }
}

// 7. نمودار دایره‌ای سهم محصولات
$stmt = $pdo->query('SELECT salt_type, SUM(total_price) as sales FROM orders WHERE status = "confirmed" GROUP BY salt_type ORDER BY sales DESC');
$productPie = $stmt->fetchAll();

// 8. ۵ سفارش اخیر
$stmt = $pdo->query('SELECT o.id, u.name as user_name, o.salt_type, o.quantity, o.total_price, o.delivery_date, o.status FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.id DESC LIMIT 5');
$recentOrders = $stmt->fetchAll();

// 9. کاربران فعال ۷ روز گذشته
$stmt = $pdo->prepare('SELECT COUNT(DISTINCT user_id) as cnt FROM orders WHERE order_date >= ?');
$stmt->execute([$weekAgo]);
$activeUsers = $stmt->fetch()['cnt'];

jsonOk([
    'today_orders'  => (int)$todayStats['cnt'],
    'today_sales'   => (int)$todayStats['total'],
    'month_orders'  => (int)$monthStats['cnt'],
    'month_sales'   => (int)$monthStats['total'],
    'prev_month_sales' => (int)$prevMonthTotal,
    'top_product'   => $topProduct ? ['name' => $topProduct['salt_type'], 'qty' => (int)$topProduct['total_qty'], 'sales' => (int)$topProduct['total_sales']] : null,
    'pending_count' => (int)$pendingCount,
    'sales_chart'   => $salesChart,
    'product_pie'   => $productPie,
    'recent_orders' => $recentOrders,
    'active_users'  => (int)$activeUsers,
]);
