<?php
/**
 * «رویدادهای تلفنچی» — آخرین ایونت‌های دریافتی CDR/ایونت (فقط مدیر کل)
 * برای عیب‌یابی وب‌هوک: چه چیزی رسیده، چه فیلدهایی استخراج شده، چه چیزی خام.
 */
$pageTitle = t('nav_telefonchy_events');

require_login();
if (!is_strict_admin()) {
    showToast(t('error_access_denied'), 'error');
    redirect('index.php?page=dashboard');
}

// جدول در خود وب‌هوک ساخته می‌شود؛ اینجا فقط نمایشی است
$table_exists = false;
$rows = [];
try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telefonchy_events' LIMIT 1");
    $st->execute();
    $table_exists = (bool)$st->fetchColumn();
} catch (Throwable $e) {}

if ($table_exists) {
    try {
        $rows = $pdo->query("SELECT * FROM telefonchy_events ORDER BY id DESC LIMIT 50")->fetchAll();
    } catch (Throwable $e) { $rows = []; }
}

include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2>📞 <?= t('nav_telefonchy_events') ?></h2>
    <span class="text-muted" style="font-size:12px"><?= t('به‌روزرسانی:') ?> <?= date('H:i:s') ?></span>
</div>

<div class="card animate-in" style="margin-bottom:16px">
    <div class="card-header"><h3 class="card-title">ℹ️ <?= t('نحوهٔ کار') ?></h3></div>
    <p class="text-muted" style="font-size:12px;line-height:2;margin:0">
        هر چیزی که تلفنچی به وب‌هوک می‌فرستد اینجا ذخیره می‌شود. اگر لیست خالی است:
        (۱) در پنل تلفنچی «آدرس فراخوانی» ثبت شده؟ (۲) آیا تماس تستی زده‌اید؟ (۳) فایل <code>api/telefonchy_webhook.php</code> و مقدار <code>TELEFONCHY_WEBHOOK_KEY</code> در <code>config.local.php</code> فعال است؟
        <br>می‌توانید همین‌جا فایل کامل خام هر رویداد را ببینید تا در فاز بعدی (AI) نگاشت فیلدها دقیق شود.
    </p>
</div>

<?php if (!$table_exists): ?>
    <div class="card animate-in"><p class="text-muted" style="margin:0">⏳ <?= t('هنوز هیچ رویدادی دریافت نشده است (جدول تلفنچی_ایونت ساخته نشده). بعد از اولین تماس دریافت می‌شود.') ?></p></div>
<?php elseif (!$rows): ?>
    <div class="card animate-in"><p class="text-muted" style="margin:0">📭 <?= t('جدول ساخته شده ولی هنوز رویدادی ذخیره نشده — یک تماس تستی بزنید.') ?></p></div>
<?php else: ?>
    <div class="card animate-in">
        <div class="card-header"><h3 class="card-title"><?= t('آخرین') ?> <?= count($rows) ?> <?= t('رویداد') ?></h3></div>
        <div style="overflow-x:auto">
            <table class="print-table" style="width:100%;border-collapse:collapse;font-size:12px">
                <thead>
                    <tr>
                        <th>ID</th><th><?= t('زمان') ?></th><th><?= t('نوع/رویداد') ?></th><th>CUID</th>
                        <th><?= t('جهت') ?></th><th><?= t('از') ?></th><th><?= t('به') ?></th><th><?= t('داخلی') ?></th>
                        <th><?= t('مدت (ث)') ?></th><th><?= t('وضعیت') ?></th><th><?= t('منبع') ?></th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td style="white-space:nowrap;direction:ltr;text-align:center"><?= htmlspecialchars(substr((string)$r['created_at'], 5)) ?></td>
                        <td><?= htmlspecialchars((string)($r['event_type'] ?? '—')) ?></td>
                        <td style="direction:ltr;text-align:center"><code style="font-size:11px"><?= htmlspecialchars((string)($r['cuid'] ?? '—')) ?></code></td>
                        <td style="text-align:center"><?= htmlspecialchars((string)($r['direction'] ?? '—')) ?></td>
                        <td style="direction:ltr;text-align:center"><?= htmlspecialchars((string)($r['from_number'] ?? '—')) ?></td>
                        <td style="direction:ltr;text-align:center"><?= htmlspecialchars((string)($r['to_number'] ?? '—')) ?></td>
                        <td style="text-align:center"><?= htmlspecialchars((string)($r['extension'] ?? '—')) ?></td>
                        <td style="text-align:center"><?= $r['duration_sec'] !== null ? (int)$r['duration_sec'] : '—' ?></td>
                        <td><?= htmlspecialchars((string)($r['call_status'] ?? '—')) ?></td>
                        <td style="text-align:center"><span class="tag" style="background:#eef2ff;color:#4338ca"><?= htmlspecialchars((string)$r['source']) ?></span></td>
                        <td style="text-align:center"><button class="btn btn-ghost btn-sm" type="button" onclick="var p=this.parentNode.querySelector('pre');p.style.display=(p.style.display==='block'?'none':'block')">🔍</button>
                            <pre dir="ltr" style="display:none;text-align:left;white-space:pre-wrap;word-break:break-all;max-width:520px;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px;font-size:10px;max-height:280px;overflow:auto"><?= htmlspecialchars((string)$r['payload']) ?></pre></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>
