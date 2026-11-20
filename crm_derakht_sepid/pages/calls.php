<?php
/**
 * «تماس‌ها» — همگام‌سازی (مکش) و مدیریت تماس‌های تلفنچی + تحلیل هوشمند (فقط مدیر کل)
 * معماری: Pull از وب‌سرویس تلفنچی؛ هیچ وابستگی به وب‌هوک/فایروالِ ورودی ندارد (§۱۱٫۶).
 */
$pageTitle = t('nav_calls');
@set_time_limit(240);
require_login();
if (!is_strict_admin()) {
    showToast(t('error_access_denied'), 'error');
    redirect('index.php?page=dashboard');
    exit;
}

require_once __DIR__ . '/../services/telefonchy.php';
if (!ensure_telefonchy_schema($pdo)) {
    include 'includes/header.php';
    echo '<div class="alert alert-warning">⚠️ ساخت جدول‌های تلفنچی ناموفق بود. سطح دسترسی دیتابیس/مجوز پوشهٔ uploads را بررسی کنید.</div>';
    include 'includes/footer.php';
    exit;
}
$me = (int)($_SESSION['user_id'] ?? 0);

// ── اکشن‌های فرم ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'sync') {
        $pages = max(1, min(30, (int)($_POST['pages'] ?? 3)));
        $report = tel_sync_calls($pdo, $pages);
        $_SESSION['calls_sync_report'] = $report;
        operations_audit($pdo, $me, 'telefonchy_sync', 'telefonchy', 0, 'inserted:' . (int)$report['inserted'] . ' requested:' . (int)$report['analysis_requested']);
        redirect('index.php?page=calls' . ($_GET['from'] ?? $_GET['to'] ? '&from=' . urlencode((string)($_GET['from'] ?? '')) . '&to=' . urlencode((string)($_GET['to'] ?? '')) : ''));
    }

    if ($action === 'analyze_all') {
        $rep = tel_bulk_request_analysis($pdo, 20);
        $_SESSION['calls_bulk_report'] = $rep;
        redirect('index.php?page=calls');
    }

    if ($action === 'analyze_one' || $action === 'poll_one') {
        $cuid = trim((string)($_POST['cuid'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._\-]{1,96}$/', $cuid)) {
            if ($action === 'analyze_one') {
                $r = tel_request_analysis($pdo, $cuid);
                if ($r['http'] === 200 || $r['http'] === 202) showToast('درخواست تحلیل ثبت شد (HTTP ' . (int)$r['http'] . '). چند دقیقه بعد دکمهٔ 🔄 را بزنید.');
                else showToast('خطا در ثبت تحلیل (HTTP ' . (int)$r['http'] . ')' . (!empty($r['error']) ? ' — ' . $r['error'] : ''), 'error');
            } else {
                $r = tel_poll_analysis($pdo, $cuid);
                $msg = 'وضعیت تحلیل: ' . ($r['status'] ?? 'نامشخص');
                if (isset($r['data']['progress']) && $r['data']['progress'] !== null) $msg .= ' — ' . (int)$r['data']['progress'] . '٪';
                if (($r['http'] ?? 0) !== 200) $msg .= ' (HTTP ' . (int)$r['http'] . (!empty($r['error']) ? ' — ' . $r['error'] : '') . ')';
                showToast($msg, ($r['http'] ?? 0) === 200 ? 'success' : 'error');
            }
        }
        redirect('index.php?page=calls');
    }

    if ($action === 'save_ai_settings') {
        $auto = (($_POST['ai_analysis_auto'] ?? '') === '1') ? '1' : '0';
        $cap  = max(0, min(500, (int)($_POST['ai_analysis_daily_cap'] ?? 30)));
        tel_set_setting($pdo, 'ai_analysis_auto', $auto);
        tel_set_setting($pdo, 'ai_analysis_daily_cap', (string)$cap);
        showToast('تنظیمات تحلیل ذخیره شد.');
        redirect('index.php?page=calls');
    }
}

$configured      = tel_configured();
$auto            = tel_setting($pdo, 'ai_analysis_auto', '1') === '1';
$cap             = (int)tel_setting($pdo, 'ai_analysis_daily_cap', '30');
$usedToday       = tel_analysis_used_today($pdo);
$pendingAnalysis = tel_analysis_pending_count($pdo);
$stats           = tel_calls_stats($pdo);

$syncReport = $_SESSION['calls_sync_report'] ?? null; unset($_SESSION['calls_sync_report']);
$bulkReport = $_SESSION['calls_bulk_report'] ?? null; unset($_SESSION['calls_bulk_report']);

// فیلتر بازهٔ تاریخ (شمسی) — مثل: from=1405/08/01&to=1405/08/25
$fromJ = trim(normalize_digits((string)($_GET['from'] ?? '')));
$toJ   = trim(normalize_digits((string)($_GET['to'] ?? '')));
$fromG = $fromJ !== '' ? to_gregorian($fromJ) : '';
$toG   = $toJ !== '' ? to_gregorian($toJ) : '';
$where = '';
$params = [];
if ($fromG !== '') { $where .= ' AND COALESCE(pc.created_at_tel, pc.created_at) >= ?'; $params[] = $fromG . ' 00:00:00'; }
if ($toG !== '')   { $where .= ' AND COALESCE(pc.created_at_tel, pc.created_at) <= ?'; $params[] = $toG . ' 23:59:59'; }
try {
    $sql = "SELECT pc.*,
            ca.analysis_status AS a_status, ca.progress AS a_progress,
            COALESCE(cust.name, lead.name, lead.company) AS matched_name
        FROM phone_calls pc
        LEFT JOIN call_analysis ca ON ca.cuid = pc.cuid
        LEFT JOIN crm_customers cust ON cust.id = pc.customer_id
        LEFT JOIN crm_leads lead ON lead.id = pc.lead_id
        WHERE 1=1" . $where . "
        ORDER BY COALESCE(pc.created_at_tel, pc.created_at) DESC, pc.id DESC
        LIMIT 500";
    $stq = $pdo->prepare($sql);
    $stq->execute($params);
    $calls = $stq->fetchAll();
} catch (Throwable $e) { $calls = []; }

$stMap = [
    'ANSWERED' => ['پاسخ‌داده‌شده', '#dcfce7', '#166534'],
    'BUSY' => ['مشغولی', '#fef9c3', '#854d0e'],
    'NO ANSWER' => ['بی‌پاسخ', '#fee2e2', '#991b1b'],
    'CONGESTION' => ['شلوغی', '#ffedd5', '#9a3412'],
];
$typeMap = [
    'incoming' => ['ورودی', '#dbeafe', '#1e40af', 'arrow-down-left'],
    'outgoing' => ['خروجی', '#ede9fe', '#5b21b6', 'arrow-up-right'],
    'local' => ['داخلی', '#f1f5f9', '#334155', 'phone-call'],
];
$anaMap = [
    'completed' => ['✅ تحلیل کامل', '#dcfce7', '#166534'],
    'failed' => ['❌ ناموفق', '#fee2e2', '#991b1b'],
    'requesting' => ['⏳ ثبت درخواست…', '#fef9c3', '#854d0e'],
    'queued' => ['⏳ در صف…', '#fef9c3', '#854d0e'],
    'transcribing' => ['⏳ تبدیل صوت به متن…', '#fef9c3', '#854d0e'],
    'analyzing' => ['⏳ تحلیل هوشمند…', '#e0e7ff', '#3730a3'],
    'reconciling' => ['⏳ هماهنگ‌سازی…', '#e0e7ff', '#3730a3'],
];

include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4 animate-in" style="flex-wrap:wrap;gap:10px">
    <h2>📞 <?= t('nav_calls') ?></h2>
    <form method="post" class="d-flex align-center gap-2" style="flex-wrap:wrap">
        <?= get_csrf_field() ?><input type="hidden" name="action" value="sync">
        <select name="pages" class="form-control form-control-sm" style="width:auto" title="تعداد صفحات تاریخچه (۲۰ تماس در هر صفحه)">
            <option value="3">۳ صفحهٔ آخر</option>
            <option value="10">۱۰ صفحهٔ آخر</option>
            <option value="30">۳۰ صفحهٔ آخر (تاریخچهٔ بیشتر)</option>
        </select>
        <button type="submit" class="btn btn-primary"><i data-lucide="refresh-cw"></i> همگام‌سازی تماس‌ها</button>
    </form>
</div>

<?php if (!$configured): ?>
<div class="alert alert-danger animate-in">
    🔑 <b>توکن وب‌سرویس تلفنچی تنظیم نشده.</b> روی هاست، در فایل <code>config.local.php</code> (کنار <code>index.php</code>) این خط را اضافه کنید:<br>
    <code dir="ltr">define('TELEFONCHY_WEBSERVICE_TOKEN', 'توکن_وب_سرویس_تلفنچی');</code><br>
    سپس صفحه را رفرش کنید. (توکن را از پنل تلفنچی → «وب‌سرویس» بگیرید؛ هرگز در گیت قرار ندهید.)
</div>
<?php endif; ?>

<?php if ($syncReport): ?>
<div class="alert <?= empty($syncReport['errors']) ? 'alert-success' : 'alert-warning' ?> animate-in">
    <b>نتیجهٔ همگام‌سازی:</b> <?= (int)$syncReport['inserted'] ?> تماس جدید وارد شد؛ <?= (int)$syncReport['skipped_existing'] ?> مورد از قبل موجود بود؛ تحلیل‌های درخواست‌شده: <?= (int)$syncReport['analysis_requested'] ?><?= !empty($syncReport['analysis_capped']) ? ' (به سقف روزانه رسید)' : '' ?>.<?php if (!empty($syncReport['errors'])): ?><br>خطاها:<br><?php foreach ($syncReport['errors'] as $err): ?>— <?= htmlspecialchars($err) ?><br><?php endforeach; ?><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($bulkReport): ?>
<div class="alert <?= empty($bulkReport['error']) ? 'alert-info' : 'alert-danger' ?> animate-in">
    <b>تحلیل دسته‌جمعی:</b> درخواست موفق: <?= (int)($bulkReport['requested'] ?? 0) ?> فقره<?= !empty($bulkReport['failed']) ? '، ناموفق: ' . (int)$bulkReport['failed'] : '' ?><?= !empty($bulkReport['capped']) ? ' — به سقف روزانهٔ تحلیل رسیدید (تنظیمات پایین همین صفحه).' : '' ?><?= !empty($bulkReport['error']) ? ' — ' . htmlspecialchars($bulkReport['error']) : '' ?>
</div>
<?php endif; ?>

<!-- آمار سریع + تنظیمات هوش مصنوعی -->
<div class="grid grid-4 gap-4 mb-4 animate-in" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
    <div class="card"><div class="card-body" style="text-align:center"><div style="font-size:22px;font-weight:800"><?= (int)$stats['total'] ?></div><div class="text-muted" style="font-size:12px">کل تماس‌های ثبت‌شده</div></div></div>
    <div class="card"><div class="card-body" style="text-align:center"><div style="font-size:22px;font-weight:800"><?= (int)$stats['with_audio'] ?></div><div class="text-muted" style="font-size:12px">دارای صوت ضبط‌شده</div></div></div>
    <div class="card"><div class="card-body" style="text-align:center"><div style="font-size:22px;font-weight:800"><?= (int)$stats['analyzed'] ?></div><div class="text-muted" style="font-size:12px">تحلیل کامل‌شده</div></div></div>
    <div class="card"><div class="card-body" style="text-align:center"><div style="font-size:22px;font-weight:800"><?= (int)$usedToday ?> / <?= (int)$cap ?></div><div class="text-muted" style="font-size:12px">مصرف تحلیل امروز / سقف</div></div></div>
</div>

<div class="card animate-in" style="margin-bottom:16px">
    <div class="card-header"><h3 class="card-title">⚙️ تنظیمات تحلیل خودکار</h3></div>
    <form method="post" class="d-flex align-center gap-3" style="padding:10px 14px;flex-wrap:wrap">
        <?= get_csrf_field() ?><input type="hidden" name="action" value="save_ai_settings">
        <label class="d-flex align-center gap-2" style="cursor:pointer">
            <input type="checkbox" name="ai_analysis_auto" value="1" <?= $auto ? 'checked' : '' ?>> تحلیل خودکارِ تماس‌های پاسخ‌داده‌شدهٔ دارای صوت در هر همگام‌سازی
        </label>
        <label class="d-flex align-center gap-2">سقف روزانهٔ تحلیل:
            <input type="number" name="ai_analysis_daily_cap" class="form-control form-control-sm" style="width:90px" min="0" max="500" value="<?= (int)$cap ?>">
        </label>
        <button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="save"></i> ذخیرهٔ تنظیمات</button>
        <span class="text-muted" style="font-size:11px">هر درخواست تحلیل ۱ واحد از سهمیهٔ وب‌سرویس تلفنچی کم می‌کند (تحلیل تکراری دوباره شارژ نمی‌کند).</span>
    </form>
    <div style="padding:0 14px 10px">
        <form method="post" onsubmit="return confirm('درخواست تحلیل برای همهٔ تماس‌های صوت‌دارِ درخواست‌نشده تا سقف روزانه ارسال شود؟')">
            <?= get_csrf_field() ?><input type="hidden" name="action" value="analyze_all">
            <button type="submit" class="btn btn-outline-primary btn-sm">🤖 تحلیل همهٔ صوت‌دارهای باقی‌مانده (<?= (int)$pendingAnalysis ?> فقره)</button>
        </form>
    </div>
</div>

<div class="card animate-in">
    <div class="card-header d-flex justify-between align-center" style="flex-wrap:wrap;gap:8px">
        <h3 class="card-title">بازهٔ نمایش: <?= $fromJ !== '' || $toJ !== '' ? htmlspecialchars($fromJ ?: '…') . ' تا ' . htmlspecialchars($toJ ?: '…') : 'همیشه' ?> — <?= count($calls) ?> تماس</h3>
        <form method="get" action="index.php" class="d-flex align-center gap-2" style="flex-wrap:wrap">
            <input type="hidden" name="page" value="calls">
            <input type="text" name="from" class="form-control form-control-sm" style="width:120px;direction:ltr" placeholder="از 1405/08/01" value="<?= htmlspecialchars($fromJ) ?>">
            <input type="text" name="to" class="form-control form-control-sm" style="width:120px;direction:ltr" placeholder="تا 1405/08/25" value="<?= htmlspecialchars($toJ) ?>">
            <button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="calendar-search"></i> فیلتر</button>
            <a href="index.php?page=calls" class="btn btn-ghost btn-sm"><i data-lucide="x"></i> حذف فیلتر</a>
        </form>
    </div>
    <div style="overflow-x:auto">
        <table class="print-table" style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
                <tr>
                    <th>زمان</th><th>جهت</th><th>مسیر تماس</th><th>داخلی / ترانک</th>
                    <th>انتظار/صحبت (ث)</th><th>وضعیت</th><th>هزینه</th><th>مخاطب CRM</th><th>تحلیل هوشمند</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($calls as $c):
                $jt = (!empty($c['created_at_tel'])) ? to_jalali(substr((string)$c['created_at_tel'], 0, 10)) . ' ' . substr((string)$c['created_at_tel'], 11, 5) : '—';
                $tp = strtolower((string)($c['type'] ?? ''));
                $tpInfo = $typeMap[$tp] ?? [$tp !== '' ? $tp : '—', '#f1f5f9', '#334155', 'phone'];
                $stKey = strtoupper((string)($c['status'] ?? ''));
                $stInfo = $stMap[$stKey] ?? [$stKey !== '' ? $stKey : '—', '#f1f5f9', '#334155'];
                $aStatus = $c['a_status'] ?? $c['analysis_status'] ?? null;
                $aInfo = $aStatus ? ($anaMap[$aStatus] ?? ['⏳ ' . htmlspecialchars((string)$aStatus), '#f1f5f9', '#334155']) : null;
                $answered = (strtoupper((string)($c['status'] ?? '')) === 'ANSWERED');
            ?>
                <tr style="border-top:1px solid var(--border)">
                    <td style="white-space:nowrap;direction:ltr;text-align:center"><?= htmlspecialchars($jt) ?></td>
                    <td style="text-align:center"><span class="tag" style="background:<?= $tpInfo[1] ?>;color:<?= $tpInfo[2] ?>;padding:2px 8px;border-radius:8px"><?= htmlspecialchars($tpInfo[0]) ?></span></td>
                    <td style="direction:ltr;text-align:center;white-space:nowrap"><?= htmlspecialchars((string)($c['call_source'] ?? '—')) ?> → <?= htmlspecialchars((string)($c['call_dest'] ?? '—')) ?></td>
                    <td style="text-align:center;white-space:nowrap"><?= htmlspecialchars((string)($c['exten'] ?? '—')) ?><?= !empty($c['trunk']) ? ' / ' . htmlspecialchars((string)$c['trunk']) : '' ?></td>
                    <td style="text-align:center"><?= (int)($c['time_wait'] ?? 0) ?> / <?= (int)($c['time_talk'] ?? 0) ?></td>
                    <td style="text-align:center"><span class="tag" style="background:<?= $stInfo[1] ?>;color:<?= $stInfo[2] ?>;padding:2px 8px;border-radius:8px"><?= htmlspecialchars($stInfo[0]) ?></span></td>
                    <td style="text-align:center"><?= $c['price'] !== null ? number_format((int)$c['price'], 0, '.', '،') : '—' ?></td>
                    <td style="white-space:nowrap"><?php if (!empty($c['matched_name'])): ?>👤 <?= htmlspecialchars((string)$c['matched_name']) ?><?php elseif ($c['customer_id']): ?>مشتری #<?= (int)$c['customer_id'] ?><?php elseif ($c['lead_id']): ?>سرنخ #<?= (int)$c['lead_id'] ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                    <td style="text-align:center;white-space:nowrap">
                        <?php if ($aInfo): ?>
                            <span class="tag" style="background:<?= $aInfo[1] ?>;color:<?= $aInfo[2] ?>;padding:2px 8px;border-radius:8px"><?= $aInfo[0] ?></span><?php if ($c['a_progress'] !== null && (int)$c['a_progress'] > 0 && $c['a_status'] !== 'completed'): ?> <small><?= (int)$c['a_progress'] ?>٪</small><?php endif; ?>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:center;white-space:nowrap">
                        <?php if (!empty($c['file_id'])): ?>
                            <a class="btn btn-ghost btn-sm" href="api/telefonchy_audio.php?cuid=<?= urlencode((string)$c['cuid']) ?>&action=stream" target="_blank" title="پخش صوت"><i data-lucide="volume-2"></i></a>
                        <?php if ($answered && empty($c['analysis_requested_at'])): ?>
                        <form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="analyze_one"><input type="hidden" name="cuid" value="<?= htmlspecialchars((string)$c['cuid']) ?>"><button type="submit" class="btn btn-ghost btn-sm" title="درخواست تحلیل هوشمند"><i data-lucide="sparkles"></i></button></form>
                        <?php elseif (!empty($c['analysis_requested_at']) && ($aStatus ?? '') !== 'completed'): ?>
                        <form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="poll_one"><input type="hidden" name="cuid" value="<?= htmlspecialchars((string)$c['cuid']) ?>"><button type="submit" class="btn btn-ghost btn-sm" title="به‌روزرسانی وضعیت تحلیل"><i data-lucide="rotate-cw"></i></button></form>
                        <?php endif; ?>
                        <?php if (($aStatus ?? '') === 'completed'): ?>
                            <a class="btn btn-ghost btn-sm" href="index.php?page=call_detail&id=<?= (int)$c['id'] ?>" title="مشاهدهٔ تحلیل"><i data-lucide="file-text"></i></a>
                        <?php endif; ?>
                        <?php else: ?><span class="text-muted">بدون صوت</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$calls): ?><tr><td colspan="10" class="text-muted text-center" style="padding:20px">هنوز تماسی وارد نشده — «همگام‌سازی تماس‌ها» را بزنید.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
