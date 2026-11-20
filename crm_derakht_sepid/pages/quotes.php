<?php
/**
 * «استعلام قیمت» — لیست + ثبت استعلام زیر یک معامله (گره از «پیش‌فاکتور» پایپ‌لاین)
 */
$pageTitle = 'استعلام قیمت';
require_login();
require_once __DIR__ . '/../includes/quotes.php';
ensure_quote_schema($pdo);

$me      = (int)($_SESSION['user_id'] ?? 0);
$canAll  = is_strict_admin() || is_finance_officer();
$managed = managed_team_ids($pdo);

// ── ثبت «استعلام جدید» از روی یک معامله (فقط POST — فرم در «پایپ‌لاین واردات»؛ CSRF توسط index.php بررسی می‌شود) ──
if (isset($_GET['new']) && $_SERVER['REQUEST_METHOD'] === 'POST' && (int)($_GET['deal_id'] ?? 0) > 0) {
    $dealId = (int)$_GET['deal_id'];
    $st = $pdo->prepare("SELECT d.*, c.name AS customer_name FROM crm_deals d LEFT JOIN crm_customers c ON c.id = d.customer_id WHERE d.id = ? LIMIT 1");
    $st->execute([$dealId]);
    $deal = $st->fetch(PDO::FETCH_ASSOC);
    if (!$deal) { showToast('معامله پیدا نشد.', 'error'); redirect('index.php?page=crm_deals'); }
    // فقط صاحبان معامله / مدیران / ادمین / مالی
    $canCreate = $canAll || (int)($deal['owner_user_id'] ?? 0) === $me || (!empty($managed) && in_array((int)($deal['team_id'] ?? 0), $managed, true));
    if (!$canCreate) { showToast('این معامله در اختیار شما نیست.', 'error'); redirect('index.php?page=crm_deals'); }

    $wf = quote_workflow_load($pdo);
    $title = 'استعلام قیمت — ' . mb_substr((string)($deal['product_name'] ?: $deal['title']), 0, 180);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("INSERT INTO crm_quotes (deal_id, customer_id, title, product_name, specs, quantity, unit, status, current_step, workflow_json, created_by)
                             VALUES (?,?,?,?,?,?,?, 'in_flow', 0, ?, ?)");
        $specs = trim((string)($deal['product_name'] ?? ''));
        $st->execute([$dealId, $deal['customer_id'] ?: null, $title, $deal['product_name'] ?: null, $specs !== '' ? $specs : null, 1, 'عدد', json_encode($wf, JSON_UNESCAPED_UNICODE), $me]);
        $qid = (int)$pdo->lastInsertId();
        // ساخت ردیف‌های هزینه (۷ ردیف خالی)
        quote_cost_lines($pdo, $qid);
        quote_history_add($pdo, $qid, $me, 0, 'submit', 'create', 'از معامله #' . $dealId);
        $pdo->commit();
        operations_audit($pdo, $me, 'quote_create', 'crm_quote', $qid, $title);
        redirect('index.php?page=quote_view&id=' . $qid);
    } catch (Throwable $e) {
        $pdo->rollBack();
        showToast('خطا در ثبت استعلام', 'error');
        redirect('index.php?page=crm_deals');
    }
}

// ── فیلتر ──
$filter = (string)($_GET['f'] ?? 'all'); // all | mine | fixed
$rows = [];
try {
    $sql = "SELECT q.*, d.title AS deal_title, d.owner_user_id AS deal_owner, d.team_id AS deal_team,
                   d.stage_id AS deal_stage, st.name AS stage_name, c.name AS customer_name, u.full_name AS creator_name
            FROM crm_quotes q
            LEFT JOIN crm_deals d ON d.id = q.deal_id
            LEFT JOIN crm_deal_stages st ON st.id = d.stage_id
            LEFT JOIN crm_customers c ON c.id = q.customer_id
            LEFT JOIN users u ON u.id = q.created_by
            ORDER BY q.id DESC LIMIT 400";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rows = []; }

$visible = [];
foreach ($rows as $q) {
    if (!$canAll) {
        $ok = ((int)$q['created_by'] === $me)
            || ((int)($q['deal_owner'] ?? 0) === $me)
            || (!empty($managed) && in_array((int)($q['deal_team'] ?? 0), $managed, true));
        if (!$ok) { $ok = quote_user_can_act($pdo, $q, $me); }
        if (!$ok) continue;
    }
    $q['can_act'] = quote_user_can_act($pdo, $q, $me);
    $step = quote_current_step($q);
    $q['step_title'] = $step['title'] ?? ($q['status'] === 'fixed' ? 'فیکس‌شده' : '—');
    $visible[] = $q;
}
if ($filter === 'mine') {
    $visible = array_values(array_filter($visible, fn($q) => !empty($q['can_act'])));
} elseif ($filter === 'fixed') {
    $visible = array_values(array_filter($visible, fn($q) => $q['status'] === 'fixed'));
}

$counts = ['all' => count($visible), 'mine' => 0, 'fixed' => 0];
foreach ($visible as $q) { if (!empty($q['can_act'])) $counts['mine']++; if ($q['status'] === 'fixed') $counts['fixed']++; }

include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4 animate-in" style="flex-wrap:wrap;gap:10px">
    <h2>🧾 استعلام قیمت <span class="text-muted" style="font-size:13px;font-weight:400">(<?= count($visible) ?>)</span></h2>
    <a class="btn btn-primary" href="index.php?page=crm_deals"><i data-lucide="plus"></i> استعلام جدید (از «پایپ‌لاین واردات» → دکمهٔ 🧾 روی معامله)</a>
</div>

<div class="d-flex gap-2 mb-3">
    <a href="index.php?page=quotes" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-ghost' ?>">همه (<?= $counts['all'] ?>)</a>
    <a href="index.php?page=quotes&f=mine" class="btn btn-sm <?= $filter === 'mine' ? 'btn-primary' : 'btn-ghost' ?>">منتظر اقدام من (<?= $counts['mine'] ?>)</a>
    <a href="index.php?page=quotes&f=fixed" class="btn btn-sm <?= $filter === 'fixed' ? 'btn-primary' : 'btn-ghost' ?>">فیکس‌شده (<?= $counts['fixed'] ?>)</a>
</div>

<div class="card animate-in"><div class="overflow-x-auto">
<table class="table" style="width:100%;font-size:12.5px">
    <thead><tr><th>#</th><th>تاریخ</th><th>مشتری</th><th>معامله / کالا</th><th>مرحلهٔ جاری</th><th>قیمت نهایی</th><th>ثبت‌کننده</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($visible as $q):
        $stBadge = match ($q['status']) {
            'fixed'     => '<span class="tag" style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:8px">✅ فیکس شد</span>',
            'cancelled' => '<span class="tag" style="background:#fee2e2;color:#991b1b;padding:2px 10px;border-radius:8px">لغو شد</span>',
            default     => '<span class="tag" style="background:#e0e7ff;color:#3730a3;padding:2px 10px;border-radius:8px">' . htmlspecialchars($q['step_title']) . '</span>',
        };
        $jt = $q['created_at'] ? (function_exists('to_jalali') ? to_jalali(substr((string)$q['created_at'], 0, 10)) : substr((string)$q['created_at'], 0, 10)) : '';
    ?>
        <tr style="<?= !empty($q['can_act']) ? 'background:rgba(37,99,235,.05)' : '' ?>">
            <td dir="ltr">#<?= (int)$q['id'] ?></td>
            <td dir="ltr" style="white-space:nowrap"><?= htmlspecialchars($jt) ?></td>
            <td><?= $q['customer_name'] ? htmlspecialchars($q['customer_name']) : '<span class="text-muted">—</span>' ?></td>
            <td><a href="index.php?page=crm_deals" style="text-decoration:none"><strong><?= htmlspecialchars(mb_substr($q['title'], 0, 90)) ?></strong></a><br><span class="text-muted" style="font-size:10.5px"><?= htmlspecialchars(mb_substr((string)($q['product_name'] ?: ''), 0, 90)) ?></span></td>
            <td><?= $stBadge ?></td>
            <td dir="ltr" style="white-space:nowrap"><?= $q['fixed_sell_toman'] ? number_format((float)$q['fixed_sell_toman'], 0, '.', '،') . ' TOM' : '<span class="text-muted">—</span>' ?></td>
            <td><?= htmlspecialchars((string)($q['creator_name'] ?? '')) ?></td>
            <td><a class="btn btn-ghost btn-sm" href="index.php?page=quote_view&id=<?= (int)$q['id'] ?>"><i data-lucide="arrow-left"></i> باز کردن</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$visible): ?>
        <tr><td colspan="8" class="text-muted text-center" style="padding:28px">هنوز استعلامی ثبت نشده.<br><span style="font-size:11px">روی «🧾» هر معامله در «پایپ‌لاین واردات» بزنید تا استعلام جدید باز شود.</span></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div></div>
<?php include 'includes/footer.php'; ?>
