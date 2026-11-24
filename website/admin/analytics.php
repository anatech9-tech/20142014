<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
Auth::requirePermission('analytics.view');
$pageTitle = 'آمار و تحلیل';
$activeMenu = 'analytics';

$period = $_GET['period'] ?? '7d';
switch ($period) {
    case '24h': $days = 1; break;
    case '7d':  $days = 7; break;
    case '30d': $days = 30; break;
    case '90d': $days = 90; break;
    default:    $days = 7;
}
$sinceDate = date('Y-m-d H:i:s', time() - $days * 86400);

$totalViews = (int) DB::fetchColumn("SELECT COUNT(*) FROM page_views WHERE viewed_at >= ?", [$sinceDate]);
$uniqueVisitors = (int) DB::fetchColumn("SELECT COUNT(DISTINCT session_id) FROM page_views WHERE viewed_at >= ?", [$sinceDate]);
$totalConversions = (int) DB::fetchColumn("SELECT COUNT(*) FROM conversion_events WHERE created_at >= ?", [$sinceDate]);

$topPages = DB::fetchAll(
    "SELECT page_url, COUNT(*) AS views, COUNT(DISTINCT session_id) AS visitors
     FROM page_views WHERE viewed_at >= ?
     GROUP BY page_url ORDER BY views DESC LIMIT 10",
    [$sinceDate]
);

$topReferrers = DB::fetchAll(
    "SELECT COALESCE(NULLIF(referrer, ''), 'مستقیم') AS referrer, COUNT(*) AS cnt
     FROM page_views WHERE viewed_at >= ?
     GROUP BY referrer ORDER BY cnt DESC LIMIT 10",
    [$sinceDate]
);

$devices = DB::fetchAll(
    "SELECT device_type, COUNT(*) AS cnt FROM page_views
     WHERE viewed_at >= ? AND device_type IS NOT NULL
     GROUP BY device_type",
    [$sinceDate]
);

$dailyChart = DB::fetchAll(
    "SELECT DATE(viewed_at) AS day, COUNT(*) AS views, COUNT(DISTINCT session_id) AS visitors
     FROM page_views WHERE viewed_at >= ?
     GROUP BY DATE(viewed_at) ORDER BY day",
    [$sinceDate]
);

$conversions = DB::fetchAll(
    "SELECT event_type, COUNT(*) AS cnt FROM conversion_events
     WHERE created_at >= ? GROUP BY event_type",
    [$sinceDate]
);
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">بازه زمانی</h2>
        <div style="display:flex; gap:6px;">
            <?php foreach (['24h' => '۲۴ ساعت', '7d' => '۷ روز', '30d' => '۳۰ روز', '90d' => '۹۰ روز'] as $k => $l): ?>
                <a href="?period=<?= $k ?>" class="btn btn-sm <?= $period === $k ? 'btn-primary' : '' ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card primary">
        <div class="stat-icon">👁️</div>
        <div class="stat-value"><?= number_format($totalViews) ?></div>
        <div class="stat-label">کل بازدید</div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon">👤</div>
        <div class="stat-value"><?= number_format($uniqueVisitors) ?></div>
        <div class="stat-label">بازدیدکننده یکتا</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-icon">🎯</div>
        <div class="stat-value"><?= number_format($totalConversions) ?></div>
        <div class="stat-label">تبدیل (Conversion)</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📊</div>
        <div class="stat-value"><?= $totalViews > 0 ? round($totalConversions * 100 / $totalViews, 1) : 0 ?>%</div>
        <div class="stat-label">نرخ تبدیل</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 20px;">
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 16px;">نمودار بازدید روزانه</h3>
        <div style="position: relative; height: 280px;">
            <canvas id="chartMain"></canvas>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom: 16px;">دستگاه‌ها</h3>
        <div style="position: relative; height: 280px;">
            <canvas id="chartDevices"></canvas>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 16px;">صفحات پربازدید</h3>
        <table class="data-table">
            <thead><tr><th>صفحه</th><th>بازدید</th><th>یکتا</th></tr></thead>
            <tbody>
                <?php foreach ($topPages as $p): ?>
                    <tr>
                        <td><small dir="ltr" style="text-align:right;"><?= htmlspecialchars($p['page_url']) ?></small></td>
                        <td><?= $p['views'] ?></td>
                        <td><?= $p['visitors'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom: 16px;">منابع ورود</h3>
        <table class="data-table">
            <thead><tr><th>منبع</th><th>تعداد</th></tr></thead>
            <tbody>
                <?php foreach ($topReferrers as $r): ?>
                    <tr>
                        <td><small dir="ltr" style="text-align:right;"><?= htmlspecialchars(mb_substr($r['referrer'], 0, 50)) ?></small></td>
                        <td><?= $r['cnt'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top: 20px;">
    <h3 class="card-title" style="margin-bottom: 16px;">رویدادهای تبدیل</h3>
    <div class="stats-grid">
        <?php foreach ($conversions as $c): ?>
            <div class="stat-card">
                <div class="stat-label"><?= $c['event_type'] ?></div>
                <div class="stat-value"><?= $c['cnt'] ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($conversions)): ?>
            <p style="color: var(--text-2);">هنوز تبدیلی ثبت نشده.</p>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const daily = <?= json_encode($dailyChart) ?>;
const devices = <?= json_encode($devices) ?>;
const labels = daily.map(d => d.day);
const viewsData = daily.map(d => +d.views);
const visitorsData = daily.map(d => +d.visitors);

new Chart(document.getElementById('chartMain'), {
    type: 'line',
    data: {
        labels,
        datasets: [
            { label: 'بازدید', data: viewsData, borderColor: '#d4af37', backgroundColor: 'rgba(212,175,55,0.15)', fill: true, tension: 0.35 },
            { label: 'بازدیدکننده', data: visitorsData, borderColor: '#2d8bd8', backgroundColor: 'rgba(45,139,216,0.1)', fill: true, tension: 0.35 },
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#ccd6f6' } } },
        scales: {
            x: { ticks: { color: '#8892b0' }, grid: { color: 'rgba(255,255,255,0.05)' } },
            y: { ticks: { color: '#8892b0' }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true }
        }
    }
});

new Chart(document.getElementById('chartDevices'), {
    type: 'doughnut',
    data: {
        labels: devices.map(d => d.device_type),
        datasets: [{
            data: devices.map(d => +d.cnt),
            backgroundColor: ['#d4af37', '#1a6fb5', '#2ecc71', '#e74c3c'],
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#ccd6f6' } } }
    }
});
</script>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
