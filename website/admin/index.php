<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
$pageTitle = 'داشبورد';
$activeMenu = 'dashboard';
$contentEcho = '';

// Stats
try {
    $stats = [
        'messages_total'  => (int) DB::fetchColumn('SELECT COUNT(*) FROM contact_messages'),
        'messages_new'    => (int) DB::fetchColumn("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'"),
        'shipments_active' => (int) DB::fetchColumn("SELECT COUNT(*) FROM shipments WHERE status NOT IN ('delivered', 'cancelled')"),
        'shipments_total' => (int) DB::fetchColumn('SELECT COUNT(*) FROM shipments'),
        'blog_published'  => (int) DB::fetchColumn('SELECT COUNT(*) FROM blog_posts WHERE is_published = 1'),
        'team_count'      => (int) DB::fetchColumn('SELECT COUNT(*) FROM team_members WHERE is_active = 1'),
        'views_today'     => (int) DB::fetchColumn("SELECT COUNT(*) FROM page_views WHERE DATE(viewed_at) = CURDATE()"),
        'views_week'      => (int) DB::fetchColumn("SELECT COUNT(*) FROM page_views WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        'views_month'     => (int) DB::fetchColumn("SELECT COUNT(*) FROM page_views WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
    ];

    $recentMessages = DB::fetchAll(
        "SELECT id, name, email, subject, status, created_at FROM contact_messages
         ORDER BY created_at DESC LIMIT 5"
    );

    $recentShipments = DB::fetchAll(
        "SELECT id, tracking_code, customer_name, status, service_type, created_at
         FROM shipments ORDER BY created_at DESC LIMIT 5"
    );

    $dailyViews = DB::fetchAll(
        "SELECT DATE(viewed_at) AS day, COUNT(*) AS cnt FROM page_views
         WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
         GROUP BY DATE(viewed_at) ORDER BY day"
    );
} catch (Throwable $e) {
    $stats = array_fill_keys(['messages_total','messages_new','shipments_active','shipments_total','blog_published','team_count','views_today','views_week','views_month'], 0);
    $recentMessages = $recentShipments = $dailyViews = [];
}

ob_start();
?>
<div class="stats-grid">
    <div class="stat-card primary">
        <div class="stat-icon">✉️</div>
        <div class="stat-value"><?= number_format($stats['messages_new']) ?></div>
        <div class="stat-label">پیام جدید</div>
        <small style="color: var(--text-2); font-size: 0.78rem;">از <?= $stats['messages_total'] ?> پیام کل</small>
    </div>
    <div class="stat-card warning">
        <div class="stat-icon">📦</div>
        <div class="stat-value"><?= number_format($stats['shipments_active']) ?></div>
        <div class="stat-label">محموله‌های فعال</div>
        <small style="color: var(--text-2); font-size: 0.78rem;">از <?= $stats['shipments_total'] ?> کل</small>
    </div>
    <div class="stat-card success">
        <div class="stat-icon">📰</div>
        <div class="stat-value"><?= $stats['blog_published'] ?></div>
        <div class="stat-label">مقاله منتشر شده</div>
    </div>
    <div class="stat-card primary">
        <div class="stat-icon">👥</div>
        <div class="stat-value"><?= $stats['team_count'] ?></div>
        <div class="stat-label">عضو تیم فعال</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">👁️</div>
        <div class="stat-value"><?= number_format($stats['views_today']) ?></div>
        <div class="stat-label">بازدید امروز</div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon">📈</div>
        <div class="stat-value"><?= number_format($stats['views_week']) ?></div>
        <div class="stat-label">بازدید هفته</div>
        <small style="color: var(--text-2); font-size: 0.78rem;"><?= number_format($stats['views_month']) ?> در ماه</small>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 20px;">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">آخرین پیام‌ها</h2>
            <a href="/admin/messages.php" class="btn btn-sm">مشاهده همه</a>
        </div>
        <?php if (empty($recentMessages)): ?>
            <div class="empty">
                <div class="empty-icon">✉️</div>
                هنوز پیامی دریافت نشده
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>نام</th><th>موضوع</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentMessages as $m): ?>
                        <tr>
                            <td>
                                <a href="/admin/message-view.php?id=<?= $m['id'] ?>" style="color: var(--text-strong); font-weight: 600;">
                                    <?= htmlspecialchars($m['name']) ?>
                                </a>
                                <br><small style="color: var(--text-2);"><?= htmlspecialchars($m['email']) ?></small>
                            </td>
                            <td><?= htmlspecialchars(mb_substr($m['subject'] ?: '(بدون موضوع)', 0, 40)) ?></td>
                            <td><span class="badge <?= $m['status'] ?>"><?= $m['status'] ?></span></td>
                            <td><small><?= date('Y/m/d H:i', strtotime($m['created_at'])) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">محموله‌های اخیر</h2>
            <a href="/admin/shipments.php" class="btn btn-sm">همه</a>
        </div>
        <?php if (empty($recentShipments)): ?>
            <div class="empty">
                <div class="empty-icon">📦</div>
                محموله‌ای ثبت نشده
            </div>
        <?php else: ?>
            <?php foreach ($recentShipments as $s): ?>
                <div style="padding: 10px 0; border-bottom: 1px solid var(--border);">
                    <a href="/admin/shipment-edit.php?id=<?= $s['id'] ?>" style="color: var(--text-strong); font-weight: 600;">
                        <?= htmlspecialchars($s['tracking_code']) ?>
                    </a>
                    <span class="badge" style="margin-right: 8px;"><?= $s['service_type'] ?></span>
                    <br>
                    <small style="color: var(--text-2);"><?= htmlspecialchars($s['customer_name']) ?></small>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h2 class="card-title">نمودار بازدید ۱۴ روز اخیر</h2>
    </div>
    <div style="position: relative; height: 280px;">
        <canvas id="viewsChart"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const data = <?= json_encode($dailyViews) ?>;
    const labels = data.map(d => d.day);
    const values = data.map(d => +d.cnt);
    if (!labels.length) {
        document.getElementById('viewsChart').replaceWith(
            Object.assign(document.createElement('div'), { className: 'empty', textContent: 'هنوز داده‌ای موجود نیست' })
        );
        return;
    }
    new Chart(document.getElementById('viewsChart'), {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'بازدید',
                data: values,
                borderColor: '#d4af37',
                backgroundColor: 'rgba(212, 175, 55, 0.15)',
                fill: true,
                tension: 0.35,
                pointRadius: 4,
                pointBackgroundColor: '#d4af37',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: '#8892b0' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                y: { ticks: { color: '#8892b0' }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true }
            }
        }
    });
})();
</script>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
