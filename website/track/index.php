<?php
/**
 * Shipment Tracking Page
 * URL: /track/ or /track/{code}
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/SiteContent.php';
require_once BASE_PATH . '/includes/Analytics.php';

Analytics::trackPageView('ردیابی محموله');

$code = trim($_GET['code'] ?? '');
$shipment = null;
$events = [];
$notFound = false;

if ($code) {
    $shipment = SiteContent::shipmentByCode($code);
    if ($shipment) {
        $events = SiteContent::shipmentEvents((int) $shipment['id']);
    } else {
        $notFound = true;
    }
}

// Status labels & progress
$statusLabels = [
    'registered' => ['ثبت اولیه', '🆕', 10],
    'picked_up' => ['جمع‌آوری شد', '📦', 25],
    'in_transit' => ['در حال حمل', '✈️', 50],
    'customs_clearance' => ['ترخیص گمرک', '🛃', 65],
    'out_for_delivery' => ['آماده تحویل', '🚚', 85],
    'delivered' => ['تحویل شد', '✅', 100],
    'cancelled' => ['لغو شده', '❌', 0],
    'on_hold' => ['در انتظار', '⏸️', 0],
];

function e2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ردیابی محموله — درخت سپید تجارت پارس</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: linear-gradient(135deg, #0a0f1c 0%, #141b2d 100%);
            color: #ccd6f6;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .header { text-align: center; padding: 40px 0; }
        .header img { width: 64px; margin-bottom: 16px; }
        .header h1 { color: #fff; margin-bottom: 8px; }
        .search-box {
            background: rgba(20, 27, 45, 0.7);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            backdrop-filter: blur(10px);
        }
        .search-form { display: flex; gap: 8px; }
        .search-form input {
            flex: 1; background: rgba(10,15,28,0.6); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px; padding: 12px 16px; color: #fff; font-family: inherit; font-size: 1rem;
        }
        .search-form button {
            background: linear-gradient(135deg, #1a6fb5, #2d8bd8); color: white;
            border: none; border-radius: 10px; padding: 12px 24px; font-weight: 600; cursor: pointer;
        }
        .card {
            background: rgba(20, 27, 45, 0.7);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
        }
        .track-code {
            font-size: 1.5rem;
            font-weight: 800;
            color: #d4af37;
            text-align: center;
            margin-bottom: 20px;
        }
        .progress {
            background: rgba(255,255,255,0.06);
            border-radius: 20px;
            height: 12px;
            margin: 24px 0;
            overflow: hidden;
        }
        .progress-bar {
            background: linear-gradient(90deg, #d4af37, #f4a261);
            height: 100%;
            transition: width 1s ease;
        }
        .status-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 30px;
            background: rgba(212, 175, 55, 0.2);
            color: #d4af37;
            font-weight: 700;
            margin-bottom: 16px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin: 20px 0;
        }
        .info-grid > div {
            background: rgba(255,255,255,0.03);
            padding: 14px;
            border-radius: 10px;
        }
        .info-grid strong { color: #fff; display: block; margin-bottom: 4px; font-size: 0.85rem; }
        .info-grid span { color: #8892b0; font-size: 0.95rem; }
        .timeline { margin-top: 20px; }
        .timeline-item {
            display: flex;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .timeline-item:last-child { border-bottom: none; }
        .timeline-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, #1a6fb5, #d4af37);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            font-size: 1.2rem;
        }
        .timeline-content { flex: 1; }
        .timeline-content strong { color: #fff; display: block; margin-bottom: 4px; }
        .timeline-content small { color: #8892b0; }
        .error { color: #e74c3c; text-align: center; padding: 30px; }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #d4af37; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="/logo.webp" alt="لوگو">
            <h1>ردیابی محموله</h1>
            <p style="color: #8892b0;">کد رهگیری خود را وارد کنید</p>
        </div>

        <div class="search-box">
            <form class="search-form" method="get">
                <input type="text" name="code" placeholder="مثلاً: STP-A1B2C3D4" dir="ltr"
                       value="<?= e2($code) ?>" required autofocus>
                <button type="submit">🔍 ردیابی</button>
            </form>
        </div>

        <?php if ($notFound): ?>
            <div class="card error">
                <p>❌ محموله‌ای با این کد یافت نشد. لطفاً کد را بررسی کنید.</p>
            </div>
        <?php elseif ($shipment): ?>
            <?php
            $status = $shipment['status'];
            $statusInfo = $statusLabels[$status] ?? ['نامشخص', '❓', 0];
            ?>
            <div class="card">
                <div class="track-code"><?= e2($shipment['tracking_code']) ?></div>
                <div style="text-align: center;">
                    <span class="status-badge"><?= $statusInfo[1] ?> <?= e2($statusInfo[0]) ?></span>
                </div>
                <div class="progress">
                    <div class="progress-bar" style="width: <?= $statusInfo[2] ?>%"></div>
                </div>

                <div class="info-grid">
                    <div>
                        <strong>نام مشتری</strong>
                        <span><?= e2($shipment['customer_name']) ?></span>
                    </div>
                    <div>
                        <strong>سرویس</strong>
                        <span>
                            <?php
                            $serviceNames = ['air' => 'هوایی', 'sea' => 'دریایی', 'land' => 'زمینی', 'multimodal' => 'چندوجهی'];
                            echo $serviceNames[$shipment['service_type']] ?? $shipment['service_type'];
                            ?>
                        </span>
                    </div>
                    <div>
                        <strong>مبدا</strong>
                        <span><?= e2($shipment['origin_country']) ?><?= $shipment['origin_city'] ? ' - ' . e2($shipment['origin_city']) : '' ?></span>
                    </div>
                    <div>
                        <strong>مقصد</strong>
                        <span><?= e2($shipment['destination_country']) ?><?= $shipment['destination_city'] ? ' - ' . e2($shipment['destination_city']) : '' ?></span>
                    </div>
                    <?php if ($shipment['weight_kg']): ?>
                        <div>
                            <strong>وزن</strong>
                            <span><?= e2($shipment['weight_kg']) ?> کیلوگرم</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($shipment['estimated_delivery']): ?>
                        <div>
                            <strong>زمان تحویل تخمینی</strong>
                            <span><?= e2($shipment['estimated_delivery']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <h2 style="color: #fff; margin-bottom: 16px;">📍 تاریخچه</h2>
                <?php if (empty($events)): ?>
                    <p style="color: #8892b0; text-align: center; padding: 20px;">هنوز رویدادی ثبت نشده.</p>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($events as $ev): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon">
                                    <?= $statusLabels[$ev['event_type']][1] ?? '📌' ?>
                                </div>
                                <div class="timeline-content">
                                    <strong><?= e2($ev['description_fa']) ?></strong>
                                    <small>
                                        <?= e2($ev['location'] ?? '') ?>
                                        · <?= date('Y/m/d H:i', strtotime($ev['event_time'])) ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="back-link">
            <a href="/">← بازگشت به سایت</a>
        </div>
    </div>
</body>
</html>
