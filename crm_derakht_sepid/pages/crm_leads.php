<?php
/**
 * CRM — سرنخ‌ها (Leads): از سایت / تماس / ارجاع ... ثبت، پیگیری و تبدیل به مشتری
 */
$pageTitle = 'سرنخ‌ها';

require_login();
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

function crm_lead_can_edit(array $row, bool $canAll, array $managed, int $me): bool {
    if ($canAll) return true;
    if ((int)($row['owner_user_id'] ?? 0) === $me) return true;
    if (!empty($managed) && in_array((int)($row['team_id'] ?? 0), $managed, true)) return true;
    return false;
}

// ═══════════ اکشن‌ها ═══════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_lead') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { showToast('نام سرنخ الزامی است.', 'error'); redirect('index.php?page=crm_leads'); }

        $owner = (int)($_POST['owner_user_id'] ?? 0);
        if (!$isMgr) $owner = $me;
        if ($owner <= 0) $owner = $me;
        $team = (int)($_POST['team_id'] ?? 0);
        if (!$isMgr) $team = $myTeam;
        $status = in_array($_POST['status'] ?? 'new', ['new', 'contacted', 'qualified', 'converted', 'lost'], true) ? $_POST['status'] : 'new';

        $data = [
            trim((string)($_POST['name'] ?? '')), trim((string)($_POST['company'] ?? '')),
            trim((string)($_POST['phone'] ?? '')), trim((string)($_POST['mobile'] ?? '')),
            trim((string)($_POST['source'] ?? '')), trim((string)($_POST['product_interest'] ?? '')),
            trim((string)($_POST['notes'] ?? '')),
        ];

        if ($id > 0) {
            $st = $pdo->prepare("SELECT * FROM crm_leads WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row || !crm_lead_can_edit($row, $canAll, $managed, $me)) { showToast(t('error_access_denied'), 'error'); redirect('index.php?page=crm_leads'); }
            $pdo->prepare("UPDATE crm_leads SET name=?, company=?, phone=?, mobile=?, source=?, product_interest=?, notes=?, status=?, owner_user_id=?, team_id=? WHERE id=?")
                ->execute([...$data, $status, $owner, $team ?: null, $id]);
            showToast('سرنخ به‌روزرسانی شد.');
        } else {
            $pdo->prepare("INSERT INTO crm_leads (name, company, phone, mobile, source, product_interest, notes, status, owner_user_id, team_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([...$data, $status, $owner, $team ?: null, $me]);
            showToast('سرنخ ثبت شد.');
        }
        redirect('index.php?page=crm_leads');
    }

    if ($action === 'set_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM crm_leads WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        $status = in_array($_POST['status'] ?? '', ['new', 'contacted', 'qualified', 'converted', 'lost'], true) ? $_POST['status'] : 'new';
        if ($row && crm_lead_can_edit($row, $canAll, $managed, $me)) {
            $pdo->prepare("UPDATE crm_leads SET status = ? WHERE id = ?")->execute([$status, $id]);
            showToast('وضعیت سرنخ تغییر کرد.');
        } else {
            showToast(t('error_access_denied'), 'error');
        }
        redirect('index.php?page=crm_leads');
    }

    // تبدیل سرنخ به مشتری
    if ($action === 'convert') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM crm_leads WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row && crm_lead_can_edit($row, $canAll, $managed, $me) && $row['status'] !== 'converted') {
            $pdo->prepare("INSERT INTO crm_customers (type, name, phone, mobile, source, industry, notes, status, owner_user_id, team_id, created_by) VALUES ('company',?,?,?,?,?,?, 'active', ?, ?, ?)")
                ->execute([$row['company'] ?: $row['name'], $row['phone'], $row['mobile'], 'سرنخ تبدیل‌شده — ' . ($row['source'] ?: '-'), $row['product_interest'], $row['notes'], $row['owner_user_id'] ?: $me, $row['team_id'] ?: $myTeam, $me]);
            $newCustomerId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE crm_leads SET status = 'converted', converted_customer_id = ? WHERE id = ?")->execute([$newCustomerId, $id]);
            // اگر نام شخصی جدا از شرکت بود، به‌عنوان مخاطب ثبت کن
            if ($row['company'] && $row['name']) {
                $pdo->prepare("INSERT INTO crm_contacts (customer_id, name, phone, mobile, is_primary) VALUES (?,?,?,?,1)")
                    ->execute([$newCustomerId, $row['name'], $row['phone'], $row['mobile']]);
            }
            operations_audit($pdo, $me, 'crm_lead_convert', 'crm_lead', $id, 'تبدیل به مشتری #' . $newCustomerId);
            showToast('✅ سرنخ به مشتری تبدیل شد.');
            redirect('index.php?page=crm_customers&view=' . $newCustomerId);
        }
        redirect('index.php?page=crm_leads');
    }

    if ($action === 'delete_lead') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM crm_leads WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row && crm_lead_can_edit($row, $canAll, $managed, $me)) {
            $pdo->prepare("DELETE FROM crm_leads WHERE id = ?")->execute([$id]);
            showToast('سرنخ حذف شد.');
        } else {
            showToast(t('error_access_denied'), 'error');
        }
        redirect('index.php?page=crm_leads');
    }
}

[$scopeSql, $scopeParams] = crm_scope_where($pdo, 'l');

$q      = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$where  = "($scopeSql)";
$params = $scopeParams;
if ($q !== '') {
    $where .= " AND (l.name LIKE ? OR l.company LIKE ? OR l.phone LIKE ? OR l.mobile LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($status, ['new', 'contacted', 'qualified', 'converted', 'lost'], true)) {
    $where .= " AND l.status = ?";
    $params[] = $status;
}
$st = $pdo->prepare("SELECT l.*, u.full_name AS owner_name, t.name AS team_name FROM crm_leads l LEFT JOIN users u ON u.id = l.owner_user_id LEFT JOIN teams t ON t.id = l.team_id WHERE $where ORDER BY FIELD(l.status,'new','contacted','qualified','converted','lost'), l.id DESC LIMIT 300");
$st->execute($params);
$leads = $st->fetchAll();

$editId = (int)($_GET['edit'] ?? 0);
$editLead = null;
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM crm_leads WHERE id = ? AND ($scopeSql)");
    $st->execute(array_merge([$editId], $scopeParams));
    $editLead = $st->fetch() ?: null;
    if ($editLead && !crm_lead_can_edit($editLead, $canAll, $managed, $me)) $editLead = null;
}
$showForm = isset($_GET['new']) || $editLead;

$statusMeta = [
    'new'       => ['🆕 جدید', '#64748b'],
    'contacted' => ['📞 تماس گرفته شد', '#0ea5e9'],
    'qualified' => ['✅ واجد شرایط', '#16a34a'],
    'converted' => ['🎉 تبدیل به مشتری', '#8b5cf6'],
    'lost'      => ['❌ از دست رفت', '#dc2626'],
];

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2>سرنخ‌ها (لید) <span class="text-muted" style="font-size:13px;font-weight:400">(<?= count($leads) ?>)</span></h2>
    <a href="index.php?page=crm_leads&new=1" class="btn btn-primary"><i data-lucide="plus"></i> سرنخ جدید</a>
</div>

<?php if ($showForm): $l = $editLead ?: []; ?>
<div class="card animate-in">
    <div class="card-header d-flex justify-between align-center"><h3 class="card-title"><?= $editLead ? 'ویرایش سرنخ' : 'سرنخ جدید' ?></h3>
        <a href="index.php?page=crm_leads" class="btn btn-ghost btn-sm">انصراف</a></div>
    <form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="save_lead"><?php if ($editLead): ?><input type="hidden" name="id" value="<?= (int)$editLead['id'] ?>"><?php endif; ?>
        <div class="modal-body">
            <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">نام شخص *</label><input name="name" class="form-control" required value="<?= htmlspecialchars($l['name'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">شرکت</label><input name="company" class="form-control" value="<?= htmlspecialchars($l['company'] ?? '') ?>"></div>
            </div>
            <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">تلفن</label><input name="phone" class="form-control" dir="ltr" value="<?= htmlspecialchars($l['phone'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">موبایل</label><input name="mobile" class="form-control" dir="ltr" value="<?= htmlspecialchars($l['mobile'] ?? '') ?>"></div>
            </div>
            <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">منبع آشنایی</label><select name="source" class="form-control">
                    <?php foreach (['سایت', 'تماس ورودی', 'ارجاع/معرف', 'نمایشگاه', 'اینستاگرام', 'لینکدین', 'سایر'] as $src): ?>
                        <option value="<?= $src ?>" <?= ($l['source'] ?? '') === $src ? 'selected' : '' ?>><?= $src ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="form-group"><label class="form-label">وضعیت</label><select name="status" class="form-control">
                    <?php foreach ($statusMeta as $key => [$label]): ?><option value="<?= $key ?>" <?= ($l['status'] ?? 'new') === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                </select></div>
                <div class="form-group"><label class="form-label">نیاز/کالای موردنظر</label><input name="product_interest" class="form-control" placeholder="مثلاً پمپ هیدرولیک ۲ اینچ" value="<?= htmlspecialchars($l['product_interest'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">یادداشت</label><textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($l['notes'] ?? '') ?></textarea></div>
            <?php if ($isMgr): ?>
            <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">مسئول پیگیری</label><select name="owner_user_id" class="form-control">
                    <?php foreach ($staffOptions as $su): ?><option value="<?= (int)$su['id'] ?>" <?= (int)($l['owner_user_id'] ?? $me) === (int)$su['id'] ? 'selected' : '' ?>><?= htmlspecialchars($su['full_name']) ?></option><?php endforeach; ?>
                </select></div>
                <div class="form-group"><label class="form-label">تیم</label><select name="team_id" class="form-control"><option value="">—</option>
                    <?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($l['team_id'] ?? $myTeam) === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
                </select></div>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary btn-lg"><i data-lucide="save"></i> <?= $editLead ? 'ذخیره تغییرات' : 'ثبت سرنخ' ?></button></div>
    </form>
</div>
<?php endif; ?>

<form method="get" class="d-flex gap-2 mb-3 animate-in" style="gap:8px">
    <input type="hidden" name="page" value="crm_leads">
    <input type="text" name="q" class="form-control" style="max-width:320px" placeholder="جستجو نام / شرکت / موبایل..." value="<?= htmlspecialchars($q) ?>">
    <select name="status" class="form-control" style="max-width:190px" onchange="this.form.submit()">
        <option value="">همه وضعیت‌ها</option>
        <?php foreach ($statusMeta as $key => [$label]): ?><option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-ghost"><i data-lucide="search"></i></button>
</form>

<div class="card animate-in"><div class="table-container"><table>
    <thead><tr><th>#</th><th>نام / شرکت</th><th>تماس</th><th>منبع</th><th>نیاز</th><th>مسئول</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php foreach ($leads as $row): ?>
        <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><strong><?= htmlspecialchars($row['name']) ?></strong><?php if ($row['company']): ?><br><span class="text-muted" style="font-size:11px"><?= htmlspecialchars($row['company']) ?></span><?php endif; ?></td>
            <td dir="ltr"><?= htmlspecialchars($row['mobile'] ?: $row['phone'] ?: '—') ?></td>
            <td><?= htmlspecialchars($row['source'] ?: '—') ?></td>
            <td style="max-width:180px"><?= htmlspecialchars($row['product_interest'] ?: '—') ?></td>
            <td><?= htmlspecialchars($row['owner_name'] ?? '—') ?></td>
            <td>
                <form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <select name="status" class="form-control" style="font-size:11px;padding:4px;min-width:130px" onchange="this.form.submit()" <?= $row['status'] === 'converted' ? 'disabled' : '' ?>>
                    <?php foreach ($statusMeta as $key => [$label]): ?><option value="<?= $key ?>" <?= $row['status'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                </select></form>
            </td>
            <td style="white-space:nowrap">
                <?php if ($row['status'] !== 'converted'): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('این سرنخ به مشتری تبدیل شود؟');"><?= get_csrf_field() ?>
                        <input type="hidden" name="action" value="convert"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button class="btn btn-success btn-sm" title="تبدیل به مشتری"><i data-lucide="user-check"></i></button></form>
                <?php else: ?>
                    <a href="index.php?page=crm_customers&view=<?= (int)$row['converted_customer_id'] ?>" class="btn btn-ghost btn-sm" title="مشاهده مشتری"><i data-lucide="external-link"></i></a>
                <?php endif; ?>
                <a href="index.php?page=crm_leads&edit=<?= (int)$row['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="edit-2"></i></a>
                <form method="post" style="display:inline" onsubmit="return confirm('سرنخ حذف شود؟');"><?= get_csrf_field() ?>
                    <input type="hidden" name="action" value="delete_lead"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button class="btn btn-ghost btn-sm" title="حذف"><i data-lucide="trash-2"></i></button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$leads): ?><tr><td colspan="8" class="text-center text-muted" style="padding:26px">سرنخی ثبت نشده است.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>

<?php include 'includes/footer.php'; ?>
