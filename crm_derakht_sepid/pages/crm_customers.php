<?php
/**
 * CRM — مشتریان (شرکت/شخص حقیقی)
 * اسکوپ دید: مدیر کل و مسئول مالی → همه | مدیر تیم → تیم‌هایش | کارشناس → فقط مشتریان خودش
 */
$pageTitle = 'مشتریان';

require_login();
$me       = (int)($_SESSION['user_id'] ?? 0);
$myTeam   = current_team_id($pdo);
$canAll   = is_strict_admin() || is_finance_officer();
$managed  = managed_team_ids($pdo);
$isMgr    = $canAll || !empty($managed);
$teams    = all_teams($pdo);

// فهرست پرسنل برای «مسئول پیگیری» (فقط مدیر کل/مالی/مدیر تیم)
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

// ── هلپر: آیا کاربر جاری می‌تواند این رکورد را ویرایش کند؟ ──
function crm_can_edit_record(array $row, bool $canAll, array $managed, int $me): bool {
    if ($canAll) return true;
    if ((int)($row['owner_user_id'] ?? 0) === $me) return true;
    if (!empty($managed) && in_array((int)($row['team_id'] ?? 0), $managed, true)) return true;
    return false;
}

// ═══════════ اکشن‌ها (POST) ═══════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ذخیرهٔ مشتری (افزودن/ویرایش) ──
    if ($action === 'save_customer') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { showToast('نام مشتری الزامی است.', 'error'); redirect('index.php?page=crm_customers' . ($id ? '&edit=' . $id : '')); }
        $type   = ($_POST['type'] ?? 'company') === 'person' ? 'person' : 'company';
        $status = in_array(($_POST['status'] ?? 'active'), ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        $owner = (int)($_POST['owner_user_id'] ?? 0);
        if (!$isMgr) $owner = $me; // کارشناس: فقط خودش
        if ($owner <= 0) $owner = $me;
        $team = (int)($_POST['team_id'] ?? 0);
        if (!$isMgr) { $team = $myTeam; }

        $fields = [
            'type', 'name', 'economic_code', 'national_id', 'registration_no',
            'phone', 'mobile', 'email', 'website', 'city', 'address', 'industry', 'source', 'notes',
        ];
        $data = [];
        foreach ($fields as $f) { $data[$f] = trim((string)($_POST[$f] ?? '')); }
        $data['type']   = $type;
        $data['status'] = $status;

        if ($id > 0) {
            // ویرایش — بررسی مالکیت
            $st = $pdo->prepare("SELECT * FROM crm_customers WHERE id = ? AND deleted_at IS NULL");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row || !crm_can_edit_record($row, $canAll, $managed, $me)) { showToast(t('error_access_denied'), 'error'); redirect('index.php?page=crm_customers'); }
            $sql = "UPDATE crm_customers SET type=?, name=?, economic_code=?, national_id=?, registration_no=?, phone=?, mobile=?, email=?, website=?, city=?, address=?, industry=?, source=?, notes=?, status=?, owner_user_id=?, team_id=? WHERE id=?";
            $pdo->prepare($sql)->execute([
                $data['type'], $data['name'], $data['economic_code'], $data['national_id'], $data['registration_no'],
                $data['phone'], $data['mobile'], $data['email'], $data['website'], $data['city'], $data['address'],
                $data['industry'], $data['source'], $data['notes'], $status, $owner, $team ?: null, $id,
            ]);
            operations_audit($pdo, $me, 'crm_customer_update', 'crm_customer', $id, $data['name']);
            showToast('مشتری با موفقیت به‌روزرسانی شد.');
            redirect('index.php?page=crm_customers&view=' . $id);
        } else {
            $sql = "INSERT INTO crm_customers (type, name, economic_code, national_id, registration_no, phone, mobile, email, website, city, address, industry, source, notes, status, owner_user_id, team_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql)->execute([
                $data['type'], $data['name'], $data['economic_code'], $data['national_id'], $data['registration_no'],
                $data['phone'], $data['mobile'], $data['email'], $data['website'], $data['city'], $data['address'],
                $data['industry'], $data['source'], $data['notes'], $status, $owner, $team ?: null, $me,
            ]);
            $newId = (int)$pdo->lastInsertId();
            operations_audit($pdo, $me, 'crm_customer_create', 'crm_customer', $newId, $data['name']);
            showToast('مشتری جدید ثبت شد.');
            redirect('index.php?page=crm_customers&view=' . $newId);
        }
    }

    // ── حذف نرم مشتری ──
    if ($action === 'delete_customer') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM crm_customers WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row && crm_can_edit_record($row, $canAll, $managed, $me)) {
            $pdo->prepare("UPDATE crm_customers SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
            operations_audit($pdo, $me, 'crm_customer_delete', 'crm_customer', $id, $row['name']);
            showToast('مشتری به بایگانی منتقل شد.');
        } else {
            showToast(t('error_access_denied'), 'error');
        }
        redirect('index.php?page=crm_customers');
    }

    // ── افزودن مخاطب ──
    if ($action === 'add_contact') {
        $cid  = (int)($_POST['customer_id'] ?? 0);
        $st   = $pdo->prepare("SELECT * FROM crm_customers WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$cid]);
        $row  = $st->fetch();
        $name = trim((string)($_POST['contact_name'] ?? ''));
        if ($row && $name !== '' && crm_can_edit_record($row, $canAll, $managed, $me)) {
            $isPrimary = !empty($_POST['is_primary']) ? 1 : 0;
            if ($isPrimary) { $pdo->prepare("UPDATE crm_contacts SET is_primary = 0 WHERE customer_id = ?")->execute([$cid]); }
            $pdo->prepare("INSERT INTO crm_contacts (customer_id, name, position, phone, mobile, email, is_primary) VALUES (?,?,?,?,?,?,?)")
                ->execute([$cid, $name, trim((string)($_POST['position'] ?? '')), trim((string)($_POST['contact_phone'] ?? '')), trim((string)($_POST['contact_mobile'] ?? '')), trim((string)($_POST['contact_email'] ?? '')), $isPrimary]);
            showToast('مخاطب اضافه شد.');
        } else {
            showToast($name === '' ? 'نام مخاطب الزامی است.' : t('error_access_denied'), 'error');
        }
        redirect('index.php?page=crm_customers&view=' . $cid);
    }

    // ── حذف مخاطب ──
    if ($action === 'delete_contact') {
        $contactId = (int)($_POST['contact_id'] ?? 0);
        $cid       = (int)($_POST['customer_id'] ?? 0);
        $st        = $pdo->prepare("SELECT * FROM crm_customers WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$cid]);
        $row       = $st->fetch();
        if ($row && crm_can_edit_record($row, $canAll, $managed, $me)) {
            $pdo->prepare("DELETE FROM crm_contacts WHERE id = ? AND customer_id = ?")->execute([$contactId, $cid]);
            showToast('مخاطب حذف شد.');
        } else {
            showToast(t('error_access_denied'), 'error');
        }
        redirect('index.php?page=crm_customers&view=' . $cid);
    }

    // ── ثبت فعالیت (تماس/جلسه/یادداشت...) ──
    if ($action === 'add_activity') {
        $cid     = (int)($_POST['customer_id'] ?? 0);
        $st      = $pdo->prepare("SELECT * FROM crm_customers WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$cid]);
        $row     = $st->fetch();
        $subject = trim((string)($_POST['subject'] ?? ''));
        $type    = in_array($_POST['type'] ?? 'note', ['call', 'meeting', 'note', 'email', 'visit', 'sms'], true) ? $_POST['type'] : 'note';
        $date    = to_gregorian((string)($_POST['activity_date'] ?? jalali_now())) ?: date('Y-m-d');
        $time    = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['activity_time'] ?? '')) ? $_POST['activity_time'] . ':00' : '00:00:00';
        if ($row && $subject !== '' && crm_can_edit_record($row, $canAll, $managed, $me)) {
            $pdo->prepare("INSERT INTO crm_activities (customer_id, user_id, type, subject, body, activity_date, duration_min, outcome) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$cid, $me, $type, $subject, trim((string)($_POST['body'] ?? '')), $date . ' ' . $time, ($_POST['duration_min'] ?? '') !== '' ? (int)$_POST['duration_min'] : null, trim((string)($_POST['outcome'] ?? ''))]);
            showToast('فعالیت ثبت شد.');
        } else {
            showToast($subject === '' ? 'موضوع فعالیت الزامی است.' : t('error_access_denied'), 'error');
        }
        redirect('index.php?page=crm_customers&view=' . $cid);
    }
}

[$scopeSql, $scopeParams] = crm_scope_where($pdo, 'c');

// ═══════════ نمایش تک‌رکورد ═══════════
$viewId = (int)($_GET['view'] ?? 0);
$customer = null;
if ($viewId > 0) {
    $st = $pdo->prepare("SELECT c.*, u.full_name AS owner_name, t.name AS team_name FROM crm_customers c LEFT JOIN users u ON u.id = c.owner_user_id LEFT JOIN teams t ON t.id = c.team_id WHERE c.id = ? AND c.deleted_at IS NULL AND ($scopeSql)");
    $st->execute(array_merge([$viewId], $scopeParams));
    $customer = $st->fetch() ?: null;
    if ($customer) {
        $contacts = $pdo->prepare("SELECT * FROM crm_contacts WHERE customer_id = ? ORDER BY is_primary DESC, id");
        $contacts->execute([$viewId]);
        $customer['contacts'] = $contacts->fetchAll();

        $activities = $pdo->prepare("SELECT a.*, u.full_name FROM crm_activities a LEFT JOIN users u ON u.id = a.user_id WHERE a.customer_id = ? ORDER BY a.activity_date DESC, a.id DESC LIMIT 50");
        $activities->execute([$viewId]);
        $customer['activities'] = $activities->fetchAll();

        $deals = $pdo->prepare("SELECT d.*, s.name AS stage_name FROM crm_deals d LEFT JOIN crm_deal_stages s ON s.id = d.stage_id WHERE d.customer_id = ? ORDER BY d.id DESC");
        $deals->execute([$viewId]);
        $customer['deals'] = $deals->fetchAll();
    }
}

// ═══════════ فرم افزودن/ویرایش ═══════════
$editId = (int)($_GET['edit'] ?? 0);
$editCustomer = null;
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM crm_customers WHERE id = ? AND deleted_at IS NULL AND ($scopeSql)");
    $st->execute(array_merge([$editId], $scopeParams));
    $editCustomer = $st->fetch() ?: null;
    if ($editCustomer && !crm_can_edit_record($editCustomer, $canAll, $managed, $me)) { $editCustomer = null; }
}
$showForm = isset($_GET['new']) || $editCustomer;

// ═══════════ لیست ═══════════
$q      = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$where  = "c.deleted_at IS NULL AND ($scopeSql)";
$params = $scopeParams;
if ($q !== '') {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.mobile LIKE ? OR c.economic_code LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where .= " AND c.status = ?";
    $params[] = $status;
}
$st = $pdo->prepare("SELECT c.*, u.full_name AS owner_name, t.name AS team_name,
    (SELECT COUNT(*) FROM crm_deals d WHERE d.customer_id = c.id AND d.status = 'open') AS open_deals
    FROM crm_customers c LEFT JOIN users u ON u.id = c.owner_user_id LEFT JOIN teams t ON t.id = c.team_id
    WHERE $where ORDER BY c.id DESC LIMIT 300");
$st->execute($params);
$customers = $st->fetchAll();

include 'includes/header.php';
?>

<?php if ($customer): // ═══════════ نمای جزئیات مشتری ═══════════ ?>
    <?php $canEdit = crm_can_edit_record($customer, $canAll, $managed, $me); ?>
    <div class="d-flex justify-between align-center mb-4 animate-in">
        <div>
            <h2 style="margin:0"><?= htmlspecialchars($customer['name']) ?>
                <span class="tag <?= $customer['type'] === 'company' ? 'tag-info' : 'tag-active' ?>" style="vertical-align:middle"><?= $customer['type'] === 'company' ? 'شخص حقوقی' : 'شخص حقیقی' ?></span></h2>
            <div class="text-muted" style="font-size:12px;margin-top:6px">
                مسئول پیگیری: <?= htmlspecialchars($customer['owner_name'] ?? '—') ?><?= $customer['team_name'] ? ' • تیم: ' . htmlspecialchars($customer['team_name']) : '' ?>
            </div>
        </div>
        <div class="btn-group">
            <?php if ($canEdit): ?>
                <a href="index.php?page=crm_customers&edit=<?= (int)$customer['id'] ?>" class="btn btn-ghost"><i data-lucide="edit-2"></i> ویرایش</a>
                <form method="post" style="display:inline" onsubmit="return confirm('مشتری به بایگانی منتقل شود؟');">
                    <?= get_csrf_field() ?>
                    <input type="hidden" name="action" value="delete_customer"><input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
                    <button class="btn btn-danger"><i data-lucide="trash-2"></i> حذف</button>
                </form>
            <?php endif; ?>
            <a href="index.php?page=crm_customers" class="btn btn-ghost"><i data-lucide="arrow-right"></i> بازگشت</a>
        </div>
    </div>

    <div class="stats-grid animate-in" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
        <div class="card"><div class="card-header"><h3 class="card-title">اطلاعات تماس</h3></div><div style="font-size:13px;line-height:2.1">
            <div>📞 <span dir="ltr"><?= htmlspecialchars($customer['phone'] ?: '—') ?></span></div>
            <div>📱 <span dir="ltr"><?= htmlspecialchars($customer['mobile'] ?: '—') ?></span></div>
            <div>✉️ <?= htmlspecialchars($customer['email'] ?: '—') ?></div>
            <div>🌐 <?= htmlspecialchars($customer['website'] ?: '—') ?></div>
            <div>🏙️ <?= htmlspecialchars($customer['city'] ?: '—') ?></div>
        </div></div>
        <div class="card"><div class="card-header"><h3 class="card-title">اطلاعات ثبتی</h3></div><div style="font-size:13px;line-height:2.1">
            <div>کد اقتصادی: <span dir="ltr"><?= htmlspecialchars($customer['economic_code'] ?: '—') ?></span></div>
            <div>شناسه ملی: <span dir="ltr"><?= htmlspecialchars($customer['national_id'] ?: '—') ?></span></div>
            <div>شماره ثبت: <span dir="ltr"><?= htmlspecialchars($customer['registration_no'] ?: '—') ?></span></div>
            <div>صنعت: <?= htmlspecialchars($customer['industry'] ?: '—') ?></div>
            <div>منبع آشنایی: <?= htmlspecialchars($customer['source'] ?: '—') ?></div>
        </div></div>
        <div class="card"><div class="card-header"><h3 class="card-title">آدرس و توضیحات</h3></div><div style="font-size:13px;line-height:2">
            <div><?= nl2br(htmlspecialchars($customer['address'] ?: '—')) ?></div>
            <?php if ($customer['notes']): ?><hr style="margin:8px 0;border-color:var(--border)"><div class="text-muted"><?= nl2br(htmlspecialchars($customer['notes'])) ?></div><?php endif; ?>
        </div></div>
    </div>

    <div class="card animate-in">
        <div class="card-header d-flex justify-between align-center"><h3 class="card-title">👥 مخاطبین (<?= count($customer['contacts']) ?>)</h3>
            <?php if ($canEdit): ?><button class="btn btn-sm btn-primary" onclick="openModal('contactModal')"><i data-lucide="plus"></i> افزودن مخاطب</button><?php endif; ?>
        </div>
        <div class="table-container"><table><thead><tr><th>نام</th><th>سمت</th><th>تلفن</th><th>موبایل</th><th>ایمیل</th><th>اصلی</th><th></th></tr></thead><tbody>
        <?php foreach ($customer['contacts'] as $ct): ?>
            <tr>
                <td><?= htmlspecialchars($ct['name']) ?></td>
                <td><?= htmlspecialchars($ct['position'] ?: '—') ?></td>
                <td dir="ltr"><?= htmlspecialchars($ct['phone'] ?: '—') ?></td>
                <td dir="ltr"><?= htmlspecialchars($ct['mobile'] ?: '—') ?></td>
                <td><?= htmlspecialchars($ct['email'] ?: '—') ?></td>
                <td><?= $ct['is_primary'] ? '⭐' : '' ?></td>
                <td><?php if ($canEdit): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('مخاطب حذف شود؟');"><?= get_csrf_field() ?>
                        <input type="hidden" name="action" value="delete_contact"><input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>"><input type="hidden" name="contact_id" value="<?= (int)$ct['id'] ?>">
                        <button class="btn btn-ghost btn-sm" title="حذف"><i data-lucide="x"></i></button>
                    </form><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$customer['contacts']): ?><tr><td colspan="7" class="text-center text-muted" style="padding:18px">هنوز مخاطبی ثبت نشده است.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>

    <div class="card animate-in">
        <div class="card-header d-flex justify-between align-center"><h3 class="card-title">🤝 معاملات (<?= count($customer['deals']) ?>)</h3>
            <a href="index.php?page=crm_deals" class="btn btn-sm btn-ghost">پایپ‌لاین <i data-lucide="arrow-left"></i></a>
        </div>
        <div class="table-container"><table><thead><tr><th>عنوان</th><th>مرحله</th><th>ارزش</th><th>وضعیت</th></tr></thead><tbody>
        <?php foreach ($customer['deals'] as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['title']) ?></td>
                <td><?= htmlspecialchars($d['stage_name'] ?? '—') ?></td>
                <td dir="ltr"><?= number_format((float)$d['estimated_value']) ?> <?= htmlspecialchars($d['currency']) ?></td>
                <td><?= $d['status'] === 'open' ? '🟡 باز' : ($d['status'] === 'won' ? '🟢 برنده' : '🔴 باخته') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$customer['deals']): ?><tr><td colspan="4" class="text-center text-muted" style="padding:18px">هنوز معامله‌ای ثبت نشده است.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>

    <div class="card animate-in">
        <div class="card-header d-flex justify-between align-center"><h3 class="card-title">🕒 تاریخچهٔ تعاملات</h3>
            <?php if ($canEdit): ?><button class="btn btn-sm btn-primary" onclick="openModal('activityModal')"><i data-lucide="plus"></i> ثبت فعالیت</button><?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px">
        <?php
        $actIcons = ['call' => '📞', 'meeting' => '🤝', 'note' => '📝', 'email' => '✉️', 'visit' => '🚗', 'sms' => '💬'];
        foreach ($customer['activities'] as $a): ?>
            <div style="display:flex;gap:10px;border:1px solid var(--border);border-radius:12px;padding:10px 12px;background:var(--bg)">
                <div style="font-size:18px"><?= $actIcons[$a['type']] ?? '📝' ?></div>
                <div style="flex:1">
                    <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($a['subject']) ?>
                        <span class="text-muted" style="font-weight:400;font-size:11px"> — <?= htmlspecialchars($a['full_name'] ?? '') ?> • <?= to_jalali(substr($a['activity_date'], 0, 10)) ?> <?= substr($a['activity_date'], 11, 5) ?></span></div>
                    <?php if ($a['body']): ?><div style="font-size:12px;margin-top:4px;line-height:1.9"><?= nl2br(htmlspecialchars($a['body'])) ?></div><?php endif; ?>
                    <?php if ($a['outcome']): ?><div style="font-size:11px;color:var(--text-secondary);margin-top:4px">نتیجه: <?= htmlspecialchars($a['outcome']) ?></div><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$customer['activities']): ?><div class="text-muted text-center" style="padding:18px">هنوز فعالیتی ثبت نشده است.</div><?php endif; ?>
        </div>
    </div>

    <!-- مودال مخاطب -->
    <div class="modal" id="contactModal"><div class="modal-content">
        <div class="modal-header"><h3>افزودن مخاطب</h3><button class="modal-close" onclick="closeModal('contactModal')">&times;</button></div>
        <form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="add_contact"><input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">نام *</label><input name="contact_name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">سمت</label><input name="position" class="form-control" placeholder="مثلاً بازرگان / مدیرعامل"></div>
                <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group"><label class="form-label">تلفن</label><input name="contact_phone" class="form-control" dir="ltr"></div>
                    <div class="form-group"><label class="form-label">موبایل</label><input name="contact_mobile" class="form-control" dir="ltr"></div>
                </div>
                <div class="form-group"><label class="form-label">ایمیل</label><input name="contact_email" type="email" class="form-control" dir="ltr"></div>
                <label class="form-check"><input type="checkbox" name="is_primary" value="1"> مخاطب اصلی</label>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">ثبت مخاطب</button></div>
        </form>
    </div></div>

    <!-- مودال فعالیت -->
    <div class="modal" id="activityModal"><div class="modal-content">
        <div class="modal-header"><h3>ثبت فعالیت</h3><button class="modal-close" onclick="closeModal('activityModal')">&times;</button></div>
        <form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="add_activity"><input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">نوع فعالیت</label>
                    <select name="type" class="form-control">
                        <option value="call">📞 تماس تلفنی</option><option value="meeting">🤝 جلسه</option><option value="visit">🚗 بازدید حضوری</option><option value="email">✉️ ایمیل</option><option value="sms">💬 پیامک</option><option value="note" selected>📝 یادداشت</option>
                    </select></div>
                <div class="form-group"><label class="form-label">موضوع *</label><input name="subject" class="form-control" required placeholder="مثلاً پیگیری پیش‌فاکتور پمپ هیدرولیک"></div>
                <div class="form-group"><label class="form-label">شرح</label><textarea name="body" class="form-control" rows="3"></textarea></div>
                <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr 120px;gap:12px">
                    <div class="form-group"><label class="form-label">تاریخ</label><input type="text" name="activity_date" class="form-control jalali-date" value="<?= jalali_now() ?>"></div>
                    <div class="form-group"><label class="form-label">ساعت</label><input type="time" name="activity_time" class="form-control" value="<?= date('H:i') ?>" dir="ltr"></div>
                    <div class="form-group"><label class="form-label">مدت (دقیقه)</label><input type="number" name="duration_min" min="0" class="form-control" dir="ltr"></div>
                </div>
                <div class="form-group"><label class="form-label">نتیجه / اقدام بعدی</label><input name="outcome" class="form-control" placeholder="مثلاً روز شنبه تماس مجدد"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">ثبت</button></div>
        </form>
    </div></div>

<?php else: // ═══════════ لیست/فرم ═══════════ ?>

    <div class="d-flex justify-between align-center mb-4 animate-in">
        <h2>مشتریان <span class="text-muted" style="font-size:13px;font-weight:400">(<?= count($customers) ?>)</span></h2>
        <a href="index.php?page=crm_customers&new=1" class="btn btn-primary"><i data-lucide="plus"></i> مشتری جدید</a>
    </div>

    <?php if ($showForm): $c = $editCustomer ?: []; ?>
    <div class="card animate-in">
        <div class="card-header d-flex justify-between align-center"><h3 class="card-title"><?= $editCustomer ? 'ویرایش مشتری' : 'مشتری جدید' ?></h3>
            <a href="index.php?page=crm_customers" class="btn btn-ghost btn-sm">انصراف</a></div>
        <form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="save_customer"><?php if ($editCustomer): ?><input type="hidden" name="id" value="<?= (int)$editCustomer['id'] ?>"><?php endif; ?>
            <div class="modal-body">
                <div class="grid-2" style="display:grid;grid-template-columns:140px 1fr;gap:12px">
                    <div class="form-group"><label class="form-label">نوع</label><select name="type" class="form-control"><option value="company" <?= ($c['type'] ?? 'company') === 'company' ? 'selected' : '' ?>>حقوقی</option><option value="person" <?= ($c['type'] ?? '') === 'person' ? 'selected' : '' ?>>حقیقی</option></select></div>
                    <div class="form-group"><label class="form-label">نام *</label><input name="name" class="form-control" required value="<?= htmlspecialchars($c['name'] ?? '') ?>"></div>
                </div>
                <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                    <div class="form-group"><label class="form-label">کد اقتصادی</label><input name="economic_code" class="form-control" dir="ltr" value="<?= htmlspecialchars($c['economic_code'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">شناسه ملی / کد ملی</label><input name="national_id" class="form-control" dir="ltr" value="<?= htmlspecialchars($c['national_id'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">شماره ثبت</label><input name="registration_no" class="form-control" dir="ltr" value="<?= htmlspecialchars($c['registration_no'] ?? '') ?>"></div>
                </div>
                <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px">
                    <div class="form-group"><label class="form-label">تلفن</label><input name="phone" class="form-control" dir="ltr" value="<?= htmlspecialchars($c['phone'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">موبایل</label><input name="mobile" class="form-control" dir="ltr" value="<?= htmlspecialchars($c['mobile'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">ایمیل</label><input name="email" type="email" class="form-control" dir="ltr" value="<?= htmlspecialchars($c['email'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">وب‌سایت</label><input name="website" class="form-control" dir="ltr" value="<?= htmlspecialchars($c['website'] ?? '') ?>"></div>
                </div>
                <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                    <div class="form-group"><label class="form-label">شهر</label><input name="city" class="form-control" value="<?= htmlspecialchars($c['city'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">صنعت</label><input name="industry" class="form-control" value="<?= htmlspecialchars($c['industry'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">منبع آشنایی</label><input name="source" class="form-control" placeholder="سایت / تماس / ارجاع / نمایشگاه" value="<?= htmlspecialchars($c['source'] ?? '') ?>"></div>
                </div>
                <div class="form-group"><label class="form-label">آدرس</label><textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($c['address'] ?? '') ?></textarea></div>
                <div class="form-group"><label class="form-label">توضیحات</label><textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($c['notes'] ?? '') ?></textarea></div>
                <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                    <div class="form-group"><label class="form-label">وضعیت</label><select name="status" class="form-control"><option value="active" <?= ($c['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>فعال</option><option value="inactive" <?= ($c['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>غیرفعال</option></select></div>
                    <?php if ($isMgr): ?>
                    <div class="form-group"><label class="form-label">مسئول پیگیری</label><select name="owner_user_id" class="form-control">
                        <?php foreach ($staffOptions as $su): ?><option value="<?= (int)$su['id'] ?>" <?= (int)($c['owner_user_id'] ?? $me) === (int)$su['id'] ? 'selected' : '' ?>><?= htmlspecialchars($su['full_name']) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="form-group"><label class="form-label">تیم</label><select name="team_id" class="form-control"><option value="">—</option>
                        <?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($c['team_id'] ?? $myTeam) === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
                    </select></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary btn-lg"><i data-lucide="save"></i> <?= $editCustomer ? 'ذخیره تغییرات' : 'ثبت مشتری' ?></button></div>
        </form>
    </div>
    <?php endif; ?>

    <form method="get" class="d-flex gap-2 mb-3 animate-in" style="gap:8px">
        <input type="hidden" name="page" value="crm_customers">
        <input type="text" name="q" class="form-control" style="max-width:320px" placeholder="جستجو نام / تلفن / کد اقتصادی..." value="<?= htmlspecialchars($q) ?>">
        <select name="status" class="form-control" style="max-width:140px" onchange="this.form.submit()">
            <option value="">همه وضعیت‌ها</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>فعال</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>غیرفعال</option>
        </select>
        <button type="submit" class="btn btn-ghost"><i data-lucide="search"></i></button>
    </form>

    <div class="card animate-in"><div class="table-container"><table>
        <thead><tr><th>#</th><th>نام</th><th>نوع</th><th>تلفن/موبایل</th><th>شهر</th><th>مسئول</th><th>تیم</th><th>معاملات باز</th><th></th></tr></thead><tbody>
        <?php foreach ($customers as $row): ?>
            <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><a href="index.php?page=crm_customers&view=<?= (int)$row['id'] ?>" style="font-weight:700;color:var(--primary-light)"><?= htmlspecialchars($row['name']) ?></a></td>
                <td><?= $row['type'] === 'company' ? 'حقوقی' : 'حقیقی' ?></td>
                <td dir="ltr"><?= htmlspecialchars($row['mobile'] ?: $row['phone'] ?: '—') ?></td>
                <td><?= htmlspecialchars($row['city'] ?: '—') ?></td>
                <td><?= htmlspecialchars($row['owner_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($row['team_name'] ?? '—') ?></td>
                <td><?= (int)$row['open_deals'] ?></td>
                <td><a href="index.php?page=crm_customers&view=<?= (int)$row['id'] ?>" class="btn btn-ghost btn-sm"><i data-lucide="eye"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$customers): ?><tr><td colspan="9" class="text-center text-muted" style="padding:26px">هنوز مشتری‌ای ثبت نشده است. اولین رکورد را بسازید 👈</td></tr><?php endif; ?>
        </tbody>
    </table></div></div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
