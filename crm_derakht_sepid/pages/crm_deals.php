<?php
/**
 * CRM — پایپ‌لاین واردات (سرنخ → پیش‌فاکتور → سورس‌یابی → حمل → ترخیص → حواله → تحویل)
 * نمای ستونی (Kanban) بر اساس مرحله + ثبت معامله + تغییر مرحله/وضعیت
 */
$pageTitle = 'پایپ‌لاین واردات';

require_login();
require_once __DIR__ . '/../includes/deal_process.php';
if (!ensure_deal_process_schema($pdo)) { include 'includes/header.php'; echo '<div class="alert alert-danger">جدول پرونده‌ها در دسترس نیست.</div>'; include 'includes/footer.php'; return; }
$processStages = deal_process_stages();
$me      = (int)($_SESSION['user_id'] ?? 0);
$myTeam  = current_team_id($pdo);
$canAll  = is_strict_admin() || is_finance_officer();
$managed = managed_team_ids($pdo);
$isMgr   = $canAll || !empty($managed);
$teams   = all_teams($pdo);

$staffOptions = [];
if ($isMgr) {
    if ($canAll) {
        $staffOptions = $pdo->query("SELECT id, full_name FROM users WHERE role != 'admin' ORDER BY full_name")->fetchAll();
    } else {
        $ph = implode(',', array_fill(0, count($managed), '?'));
        $st = $pdo->prepare("SELECT id, full_name FROM users WHERE team_id IN ($ph) ORDER BY full_name");
        $st->execute($managed);
        $staffOptions = $st->fetchAll();
    }
}

function crm_deal_can_edit(array $row, bool $canAll, array $managed, int $me): bool {
    if ($canAll) return true;
    if ((int)($row['owner_user_id'] ?? 0) === $me) return true;
    if (!empty($managed) && in_array((int)($row['team_id'] ?? 0), $managed, true)) return true;
    return false;
}

$stages = $pdo->query("SELECT * FROM crm_deal_stages ORDER BY sort_order, id")->fetchAll();
$stageIds = array_map('intval', array_column($stages, 'id'));

// ═══════════ اکشن‌ها ═══════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_deal') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $cust  = (int)($_POST['customer_id'] ?? 0) ?: null;
        if ($title === '') { showToast('عنوان معامله الزامی است.', 'error'); redirect('index.php?page=crm_deals'); }
        $stageId = (int)($_POST['stage_id'] ?? 0);
        if (!in_array($stageId, $stageIds, true)) $stageId = $stageIds[0] ?? null;

        $owner = (int)($_POST['owner_user_id'] ?? 0);
        if (!$isMgr) $owner = $me;
        if ($owner <= 0) $owner = $me;
        $team = (int)($_POST['team_id'] ?? 0);
        if (!$isMgr) $team = $myTeam;

        $value    = (float)str_replace([',', '٬', '،'], '', (string)($_POST['estimated_value'] ?? '0'));
        $currency = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($_POST['currency'] ?? 'USD')), 0, 3)) ?: 'USD';
        $closeDate = to_gregorian((string)($_POST['expected_close_date'] ?? '')) ?: null;

        if ($id > 0) {
            $st = $pdo->prepare("SELECT * FROM crm_deals WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row || !crm_deal_can_edit($row, $canAll, $managed, $me)) { showToast(t('error_access_denied'), 'error'); redirect('index.php?page=crm_deals'); }
            $pdo->prepare("UPDATE crm_deals SET customer_id=?, title=?, product_name=?, estimated_value=?, currency=?, stage_id=?, expected_close_date=?, owner_user_id=?, team_id=?, notes=? WHERE id=?")
                ->execute([$cust, $title, trim((string)($_POST['product_name'] ?? '')), $value, $currency, $stageId, $closeDate, $owner, $team ?: null, trim((string)($_POST['notes'] ?? '')), $id]);
            showToast('معامله به‌روزرسانی شد.');
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO crm_deals (customer_id, title, product_name, estimated_value, currency, stage_id, expected_close_date, owner_user_id, team_id, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$cust, $title, trim((string)($_POST['product_name'] ?? '')), $value, $currency, $stageId, $closeDate, $owner, $team ?: null, trim((string)($_POST['notes'] ?? '')), $me]);
                $newId=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO crm_deal_process (deal_id,stage_key,created_by,updated_by) VALUES (?,'contact',?,?)")->execute([$newId,$me,$me]);
                deal_process_add_event($pdo,$newId,null,'contact','start',$me);
                $pdo->commit(); operations_audit($pdo, $me, 'crm_deal_create', 'crm_deal', $newId, $title);
                showToast('معامله و پروندهٔ مرحله‌ای ثبت شد.');
                redirect('index.php?page=crm_deal_process&id='.$newId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Deal create: '.$e->getMessage()); showToast('ثبت معامله انجام نشد.', 'error');
            }
        }
        redirect('index.php?page=crm_deals');
    }

    if ($action === 'move_stage') {
        $id      = (int)($_POST['id'] ?? 0);
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM crm_deals WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        $pst=$pdo->prepare('SELECT 1 FROM crm_deal_process WHERE deal_id=?'); $pst->execute([$id]);
        if ($pst->fetchColumn()) { showToast('مرحلهٔ پرونده را از صفحهٔ پرونده تغییر دهید.', 'error'); redirect('index.php?page=crm_deals'); }
        if ($row && in_array($stageId, $stageIds, true) && crm_deal_can_edit($row, $canAll, $managed, $me)) {
            $pdo->prepare("UPDATE crm_deals SET stage_id = ? WHERE id = ?")->execute([$stageId, $id]);
            showToast('مرحله تغییر کرد.');
        } else {
            showToast(t('error_access_denied'), 'error');
        }
        redirect('index.php?page=crm_deals');
    }

    if ($action === 'set_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['open', 'won', 'lost'], true) ? $_POST['status'] : 'open';
        $st = $pdo->prepare("SELECT * FROM crm_deals WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row && crm_deal_can_edit($row, $canAll, $managed, $me)) {
            $pdo->prepare("UPDATE crm_deals SET status = ? WHERE id = ?")->execute([$status, $id]);
            operations_audit($pdo, $me, 'crm_deal_status', 'crm_deal', $id, $row['title'] . ' → ' . $status);
            showToast('وضعیت معامله ثبت شد.');
        } else {
            showToast(t('error_access_denied'), 'error');
        }
        redirect('index.php?page=crm_deals');
    }
}

[$scopeSql, $scopeParams] = crm_scope_where($pdo, 'd');

// مشتری‌های در دامنهٔ دید (برای دراپ‌دان فرم)
[$custScope, $custParams] = ['1=1', []];
$stCust = $pdo->prepare("SELECT c2.id, c2.name FROM crm_customers c2 WHERE c2.deleted_at IS NULL AND ($custScope) ORDER BY c2.name LIMIT 500");
$stCust->execute($custParams);
$customerOptions = $stCust->fetchAll();

$st = $pdo->prepare("SELECT d.*, c.name AS customer_name, u.full_name AS owner_name, dp.stage_key AS process_stage FROM crm_deals d LEFT JOIN crm_customers c ON c.id = d.customer_id LEFT JOIN users u ON u.id = d.owner_user_id LEFT JOIN crm_deal_process dp ON dp.deal_id=d.id WHERE ($scopeSql) ORDER BY d.id DESC");
$st->execute($scopeParams);
$deals = $st->fetchAll();

$dealsByStage = ['legacy'=>[]];
foreach ($processStages as $key=>$meta) $dealsByStage[$key]=[];
$openCount=0; $wonCount=0; $pipelineValue=0.0;
foreach ($deals as $d) {
    $key=(string)($d['process_stage'] ?? 'legacy');
    if (!isset($dealsByStage[$key])) $key='legacy';
    $dealsByStage[$key][]=$d;
    if ($d['status']==='open') { $openCount++; $pipelineValue+=(float)$d['estimated_value']; }
    if ($d['status']==='won') $wonCount++;
}
$filterStatus=(string)($_GET['status'] ?? 'all');
include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2>پایپ‌لاین واردات</h2>
    <div class="btn-group">
        <?php foreach (['open' => 'باز', 'won' => 'برنده', 'lost' => 'باخته', 'all' => 'همه'] as $k => $v): ?>
            <a href="index.php?page=crm_deals&status=<?= $k ?>" class="btn btn-sm <?= $filterStatus === $k ? 'btn-primary' : 'btn-ghost' ?>"><?= $v ?></a>
        <?php endforeach; ?>
        <button class="btn btn-primary btn-sm" onclick="openModal('dealModal')"><i data-lucide="plus"></i> معامله جدید</button>
    </div>
</div>

<div class="stats-grid animate-in">
    <div class="stat-card glass-gradient-1"><div class="stat-info"><h3><?= $openCount ?></h3><p>معاملات باز</p></div></div>
    <div class="stat-card glass-gradient-2"><div class="stat-info"><h3 dir="ltr"><?= number_format($pipelineValue, 0, '.', '،') ?></h3><p>ارزش کل پایپ‌لاین باز</p></div></div>
    <div class="stat-card glass-gradient-3"><div class="stat-info"><h3><?= $wonCount ?></h3><p>برنده‌شده</p></div></div>
</div>

<p class="text-muted" style="font-size:12px">مرحلهٔ جاری هر معامله از پروندهٔ آن خوانده می‌شود. پرونده‌های قدیمی بدون تغییر حفظ شده‌اند و می‌توان آن‌ها را جداگانه شروع کرد.</p>
<div style="display:flex;gap:14px;overflow-x:auto;padding-bottom:12px;align-items:flex-start" class="animate-in">
    <?php foreach (array_merge(['legacy'=>['پرونده‌های قدیمی / شروع‌نشده',false]],$processStages) as $stageKey=>$stage): ?>
        <div style="flex:0 0 260px;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:10px">
            <div style="border-bottom:2px solid var(--primary);padding-bottom:8px;margin-bottom:8px"><strong style="font-size:12px"><?= htmlspecialchars($stage[0],ENT_QUOTES,'UTF-8') ?></strong><br><span class="text-muted" style="font-size:10px"><?= count($dealsByStage[$stageKey]) ?> پرونده</span></div>
            <div style="display:flex;flex-direction:column;gap:8px;max-height:62vh;overflow-y:auto">
                <?php foreach ($dealsByStage[$stageKey] as $d): if ($filterStatus!=='all' && $d['status']!==$filterStatus) continue; ?>
                    <div style="border:1px solid var(--border);border-radius:10px;padding:10px;background:var(--bg);font-size:12px">
                        <div style="font-weight:700;line-height:1.7"><?= htmlspecialchars($d['title'],ENT_QUOTES,'UTF-8') ?></div>
                        <div class="text-muted" style="font-size:11px"><?= htmlspecialchars($d['customer_name'] ?: 'بدون مشتری',ENT_QUOTES,'UTF-8') ?> • <?= htmlspecialchars($d['owner_name'] ?? '',ENT_QUOTES,'UTF-8') ?></div>
                        <div dir="ltr" style="font-weight:800;color:var(--primary-light);margin:6px 0;font-size:12px"><?= number_format((float)$d['estimated_value'],0,'.','،') ?> <?= htmlspecialchars($d['currency'],ENT_QUOTES,'UTF-8') ?></div>
                        <div class="text-muted" style="font-size:11px"><?= $d['status']==='won'?'فروش برنده':($d['status']==='lost'?'باخته':'باز') ?></div>
                        <a href="index.php?page=crm_deal_process&amp;id=<?= (int)$d['id'] ?>" class="btn btn-primary btn-sm" style="margin-top:6px;font-size:11px"><?= $stageKey==='legacy'?'شروع پرونده':'باز کردن پرونده' ?></a>
                        <?php if(access_mode('crm_deals')!=='viewer' && !is_nazer_kol()): ?>
                        <form method="post" style="margin-top:8px"><?= get_csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><select name="status" class="form-control" style="font-size:11px" onchange="this.form.submit()"><option value="open" <?= $d['status']==='open'?'selected':'' ?>>فروش باز</option><option value="won" <?= $d['status']==='won'?'selected':'' ?>>فروش برنده (پرونده ادامه دارد)</option><option value="lost" <?= $d['status']==='lost'?'selected':'' ?>>باخته / توقف</option></select></form>
                        <?php endif ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- مودال معامله جدید -->
<div class="modal" id="dealModal"><div class="modal-content">
    <div class="modal-header"><h3>معامله / پروژهٔ واردات جدید</h3><button class="modal-close" onclick="closeModal('dealModal')">&times;</button></div>
    <form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="save_deal">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">عنوان معامله *</label><input name="title" class="form-control" required placeholder="مثلاً واردات ۵۰۰ عدد پمپ هیدرولیک برای مشتری الف"></div>
            <div class="form-group"><label class="form-label">مشتری</label><select name="customer_id" class="form-control"><option value="0">— انتخاب مشتری —</option>
                <?php foreach ($customerOptions as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-group"><label class="form-label">کالا / شرح</label><input name="product_name" class="form-control" placeholder="نام کالا، HS Code، وزن/حجم..."></div>
            <div class="grid-2" style="display:grid;grid-template-columns:1fr 90px 1fr;gap:12px">
                <div class="form-group"><label class="form-label">ارزش تقریبی</label><input name="estimated_value" type="number" min="0" step="any" class="form-control" dir="ltr" value="0"></div>
                <div class="form-group"><label class="form-label">ارز</label><select name="currency" class="form-control"><option>USD</option><option>EUR</option><option>CNY</option><option>AED</option><option>IRR</option></select></div>
                <div class="form-group"><label class="form-label">موعد تحویل احتمالی</label><input name="expected_close_date" class="form-control jalali-date" placeholder="۱۴۰۵/۰۸/۳۰"></div>
            </div>
            <?php if ($isMgr): ?>
            <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">مسئول پیگیری</label><select name="owner_user_id" class="form-control">
                    <?php foreach ($staffOptions as $su): ?><option value="<?= (int)$su['id'] ?>" <?= (int)$su['id'] === $me ? 'selected' : '' ?>><?= htmlspecialchars($su['full_name']) ?></option><?php endforeach; ?>
                </select></div>
                <div class="form-group"><label class="form-label">تیم</label><select name="team_id" class="form-control"><option value="">—</option>
                    <?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $myTeam ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
                </select></div>
            </div>
            <?php endif; ?>
            <div class="form-group"><label class="form-label">یادداشت</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary btn-lg"><i data-lucide="save"></i> ثبت معامله</button></div>
    </form>
</div></div>

<?php include 'includes/footer.php'; ?>
