<?php
/**
 * «جزئیات تماس و تحلیل» — خلاصه، احساس، مربی، کلیدواژه‌ها و متن تفکیک‌شده (فقط مدیر کل)
 */
$pageTitle = 'جزئیات تماس و تحلیل';
@set_time_limit(120);
require_login();
if (!is_strict_admin()) {
    showToast(t('error_access_denied'), 'error');
    redirect('index.php?page=dashboard');
    exit;
}

require_once __DIR__ . '/../services/telefonchy.php';
ensure_telefonchy_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'poll' && $id > 0) {
        $st = $pdo->prepare("SELECT cuid FROM phone_calls WHERE id = ?");
        $st->execute([$id]);
        $cuid = (string)($st->fetchColumn() ?: '');
        if ($cuid !== '') { tel_poll_analysis($pdo, $cuid); showToast('وضعیت تحلیل به‌روزرسانی شد.'); }
        redirect('index.php?page=call_detail&id=' . $id);
    }
    if ($action === 'analyze' && $id > 0) {
        $st = $pdo->prepare("SELECT cuid FROM phone_calls WHERE id = ?");
        $st->execute([$id]);
        $cuid = (string)($st->fetchColumn() ?: '');
        if ($cuid !== '') { tel_request_analysis($pdo, $cuid); showToast('درخواست تحلیل ثبت شد.'); }
        redirect('index.php?page=call_detail&id=' . $id);
    }
}

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT pc.*, COALESCE(cust.name, lead.name, lead.company) AS matched_name
    FROM phone_calls pc
    LEFT JOIN crm_customers cust ON cust.id = pc.customer_id
    LEFT JOIN crm_leads lead ON lead.id = pc.lead_id
    WHERE pc.id = ?");
$st->execute([$id]);
$call = $st->fetch();
if (!$call) {
    include 'includes/header.php';
    echo '<div class="alert alert-danger">این تماس پیدا نشد.</div>';
    include 'includes/footer.php';
    exit;
}
$cuid = (string)$call['cuid'];

$st2 = $pdo->prepare("SELECT * FROM call_analysis WHERE cuid = ?");
$st2->execute([$cuid]);
$an = $st2->fetch() ?: null;

$jdecode = function ($v) { if (!$v) return null; $d = json_decode((string)$v, true); return (is_array($d) || is_object($d)) ? $d : $v; };
$stMap = ['ANSWERED' => 'پاسخ‌داده‌شده', 'BUSY' => 'مشغولی', 'NO ANSWER' => 'بی‌پاسخ', 'CONGESTION' => 'شلوغی'];
$tpMap = ['incoming' => 'ورودی', 'outgoing' => 'خروجی', 'local' => 'داخلی'];
$aStatus = $an['analysis_status'] ?? $call['analysis_status'] ?? null;

$jt = (!empty($call['created_at_tel'])) ? to_jalali(substr((string)$call['created_at_tel'], 0, 10)) . ' ' . substr((string)$call['created_at_tel'], 11, 8) : '—';

$roleText = null; $mapping = null;
if ($an) {
    $tbr = $jdecode($an['transcript_by_role']);
    if (is_array($tbr)) {
        $mapping = isset($tbr['mapping']) ? (string)$tbr['mapping'] : null;
        $roleText = [
            'operator' => is_string($tbr['operator'] ?? null) ? $tbr['operator'] : (isset($tbr['operator']) ? json_encode($tbr['operator'], JSON_UNESCAPED_UNICODE) : null),
            'customer' => is_string($tbr['customer'] ?? null) ? $tbr['customer'] : (isset($tbr['customer']) ? json_encode($tbr['customer'], JSON_UNESCAPED_UNICODE) : null),
            'unknown'  => is_string($tbr['unknown'] ?? null) ? $tbr['unknown'] : (isset($tbr['unknown']) ? json_encode($tbr['unknown'], JSON_UNESCAPED_UNICODE) : null),
        ];
    }
}
$activeKw = $an ? $jdecode($an['active_keywords']) : null;
$matchedKw = $an ? $jdecode($an['matched_keywords']) : null;
$mapped = (($an['mapping'] ?? $mapping) === 'verified');

include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2>📞 جزئیات تماس و تحلیل</h2>
    <div class="d-flex gap-2">
        <a href="index.php?page=calls" class="btn btn-ghost"><i data-lucide="arrow-right"></i> تماس‌ها</a>
    </div>
</div>

<div class="card animate-in" style="margin-bottom:16px">
    <div class="card-header"><h3 class="card-title">مشخصات تماس <code style="font-size:11px"><?= htmlspecialchars($cuid) ?></code></h3></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;padding:12px 14px;font-size:13px">
        <div><div class="text-muted" style="font-size:11px">زمان</div><b><?= htmlspecialchars($jt) ?></b></div>
        <div><div class="text-muted" style="font-size:11px">نوع</div><b><?= htmlspecialchars($tpMap[strtolower((string)($call['type'] ?? ''))] ?? (string)($call['type'] ?? '—')) ?></b></div>
        <div><div class="text-muted" style="font-size:11px">وضعیت</div><b><?= htmlspecialchars($stMap[strtoupper((string)($call['status'] ?? ''))] ?? (string)($call['status'] ?? '—')) ?></b></div>
        <div><div class="text-muted" style="font-size:11px">مسیر</div><b dir="ltr"><?= htmlspecialchars((string)$call['call_source']) ?> → <?= htmlspecialchars((string)$call['call_dest']) ?></b></div>
        <div><div class="text-muted" style="font-size:11px">داخلی</div><b><?= htmlspecialchars((string)($call['exten'] ?? '—')) ?><?= !empty($call['trunk']) ? ' <span class="text-muted">/ ' . htmlspecialchars((string)$call['trunk']) . '</span>' : '' ?></b></div>
        <div><div class="text-muted" style="font-size:11px">انتظار / صحبت</div><b><?= (int)($call['time_wait'] ?? 0) ?> / <?= (int)($call['time_talk'] ?? 0) ?> ثانیه</b></div>
        <div><div class="text-muted" style="font-size:11px">هزینه</div><b><?= $call['price'] !== null ? number_format((int)$call['price'], 0, '.', '،') : '—' ?></b></div>
        <div><div class="text-muted" style="font-size:11px">مخاطب CRM</div><b><?= !empty($call['matched_name']) ? '👤 ' . htmlspecialchars((string)$call['matched_name']) : '—' ?></b></div>
    </div>
    <?php if (!empty($call['file_id'])): ?>
    <div style="padding:0 14px 14px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <audio controls preload="none" src="api/telefonchy_audio.php?cuid=<?= urlencode($cuid) ?>&action=stream" style="flex:1;min-width:220px"></audio>
        <a class="btn btn-outline-primary btn-sm" href="api/telefonchy_audio.php?cuid=<?= urlencode($cuid) ?>&action=archive"><i data-lucide="download"></i> ذخیرهٔ آرشیو</a>
    </div>
    <?php else: ?>
    <div style="padding:0 14px 14px" class="text-muted">برای این تماس صوت ضبط‌شده وجود ندارد (file_id خالی است).</div>
    <?php endif; ?>
</div>

<div class="card animate-in">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title">🤖 تحلیل هوشمند تماس</h3>
        <form method="post" style="display:inline">
            <?= get_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
            <?php if (empty($call['analysis_requested_at'])): ?>
                <input type="hidden" name="action" value="analyze">
                <button type="submit" class="btn btn-primary btn-sm"><i data-lucide="sparkles"></i> درخواست تحلیل</button>
            <?php else: ?>
                <input type="hidden" name="action" value="poll">
                <button type="submit" class="btn btn-outline-primary btn-sm"><i data-lucide="rotate-cw"></i> بررسی وضعیت تحلیل</button>
            <?php endif; ?>
        </form>
    </div>
    <div style="padding:14px;font-size:13px;line-height:2">
        <?php if (!$an): ?>
            <p class="text-muted">هنوز نتیجه‌ای ثبت نشده. اگر تحلیل را فعال کنید، پس از چند دقیقه با «بررسی وضعیت تحلیل» نتیجه ظاهر می‌شود.</p>
            <?php if ($aStatus): ?><p>وضعیت فعلی: <b><?= htmlspecialchars((string)$aStatus) ?></b></p><?php endif; ?>
        <?php else: ?>
            <p>وضعیت:
                <span class="tag" style="background:#e0e7ff;color:#3730a3;padding:2px 10px;border-radius:8px"><?= htmlspecialchars((string)($an['analysis_status'] ?? '—')) ?></span>
                <?php if ($an['progress'] !== null): ?> <small class="text-muted">پیشرفت: <?= (int)$an['progress'] ?>٪</small><?php endif; ?>
            </p>

            <?php if (!empty($an['summary'])): ?><h4 style="margin:14px 0 4px">خلاصه</h4><p><?= nl2br(htmlspecialchars((string)$an['summary'])) ?></p><?php endif; ?>
            <?php if (!empty($an['outcome'])): ?><h4 style="margin:14px 0 4px">نتیجهٔ تماس</h4><p><?= nl2br(htmlspecialchars((string)$an['outcome'])) ?></p><?php endif; ?>

            <?php if (!empty($an['customer_sentiment'])): ?>
                <h4 style="margin:14px 0 4px">احساس مشتری</h4>
                <p><b><?= htmlspecialchars((string)$an['customer_sentiment']) ?></b><?php if (!empty($an['sentiment_reason'])): ?> — <?= nl2br(htmlspecialchars((string)$an['sentiment_reason'])) ?><?php endif; ?></p>
            <?php endif; ?>

            <?php if (!empty($an['coaching_tip'])): ?>
                <h4 style="margin:14px 0 4px">💡 پیشنهاد مربی اپراتور</h4>
                <p><b><?= nl2br(htmlspecialchars((string)$an['coaching_tip'])) ?></b><?php if (!empty($an['coaching_reason'])): ?><br><span class="text-muted"><?= nl2br(htmlspecialchars((string)$an['coaching_reason'])) ?></span><?php endif; ?></p>
            <?php endif; ?>

            <?php if (is_array($matchedKw) && $matchedKw): ?>
                <h4 style="margin:14px 0 4px">کلیدواژه‌های تشخیص‌داده‌شده</h4>
                <p><?php foreach ($matchedKw as $kw): ?><span class="tag" style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:8px;margin:2px;display:inline-block"><?= htmlspecialchars(is_array($kw) ? json_encode($kw, JSON_UNESCAPED_UNICODE) : (string)$kw) ?></span><?php endforeach; ?></p>
            <?php endif; ?>

            <?php if ($roleText): ?>
                <h4 style="margin:14px 0 4px">متن مکالمه (تفکیک نقش)
                    <?php if (!$mapped): ?><span class="tag" style="background:#ffedd5;color:#9a3412;padding:2px 8px;border-radius:8px;font-size:11px">⚠️ نگاشت نقش مطمئن نیست (mapping=<?= htmlspecialchars((string)($an['mapping'] ?? $mapping ?? 'unknown')) ?>)</span><?php endif; ?>
                </h4>
                <?php if (!empty($roleText['operator'])): ?><div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px;margin:6px 0"><b class="text-muted">اپراتور</b><br><?= nl2br(htmlspecialchars((string)$roleText['operator'])) ?></div><?php endif; ?>
                <?php if (!empty($roleText['customer'])): ?><div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px;margin:6px 0"><b class="text-muted">مشتری</b><br><?= nl2br(htmlspecialchars((string)$roleText['customer'])) ?></div><?php endif; ?>
                <?php if (!empty($roleText['unknown'])): ?><pre dir="rtl" style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:10px;white-space:pre-wrap;max-height:320px;overflow:auto"><?= htmlspecialchars((string)$roleText['unknown']) ?></pre><?php endif; ?>
            <?php elseif (!empty($an['transcript'])): ?>
                <h4 style="margin:14px 0 4px">متن مکالمه (خام RX/TX)</h4>
                <pre dir="rtl" style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:10px;white-space:pre-wrap;max-height:320px;overflow:auto"><?= htmlspecialchars(is_array($jdecode($an['transcript'])) ? json_encode($jdecode($an['transcript']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string)$an['transcript']) ?></pre>
            <?php endif; ?>

            <?php if (!empty($an['report_text'])): ?>
                <details style="margin-top:14px"><summary style="cursor:pointer;font-weight:600">گزارش کامل تحلیل</summary><pre dir="rtl" style="background:var(--bg);border:1px solid var(--border);padding:12px;border-radius:10px;white-space:pre-wrap;margin-top:8px"><?= htmlspecialchars((string)$an['report_text']) ?></pre></details>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
