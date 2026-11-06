<?php
$pageTitle = t('page_work_orders');
$ws_id = $_SESSION['workshop_id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = generate_token();

// اگر درخواستی برای عملیات دستور کار (POST) آمد، پردازش شده و پاسخ داده شود
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action']) || isset($_GET['action']))) {
    require_once __DIR__ . '/../api/work_orders.php';
    exit;
}

$stmt = $pdo->prepare("SELECT work_role, is_karigar, full_name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_info = $stmt->fetch();

$user_team = team_from_work_role($user_info['work_role'] ?? '');

$team_labels = team_labels();
$team_icons = ['ejra' => '🔧', 'hse' => '🛡️', 'anbar' => '📦'];
$is_admin = is_strict_admin() || is_nazer();
// §v11: ناظر = دیدن دستورکار هر سه واحد | ویراستار = ارسال/ویرایش دستور کار
$__wo_mode = access_mode('work_orders');
$can_view_all = $is_admin || in_array($__wo_mode, ['viewer', 'editor'], true);
$can_send_wo  = $is_admin || $__wo_mode === 'editor';

// تب فعال: مدیر/ناظر می‌تونه بین ۳ واحد جابه‌جا شه؛ نیروها فقط واحد خودشون
$active_tab = $_GET['tab'] ?? '';
if (!in_array($active_tab, ['ejra', 'hse', 'anbar'])) $active_tab = $can_view_all ? 'ejra' : $user_team;
if (!$can_view_all) $active_tab = $user_team;

include 'includes/header.php';
?>

<style>
.wo-items-table { width:100%; border-collapse:collapse; font-size:12px; }
.wo-items-table th { background:#f1f5f9; padding:6px 4px; border:1px solid #ccc; text-align:center; font-weight:600; font-size:11px; }
.wo-items-table td { padding:6px 4px; border:1px solid #ddd; text-align:center; font-size:12px; }
.wo-status { display:inline-block; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; }
.wo-status.pending { background:#fef3c7; color:#92400e; }
.wo-status.completed { background:#d1fae5; color:#065f46; }
.team-badge { display:inline-block; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; }
.team-badge.ejra { background:#e0e7ff; color:#3730a3; }
.team-badge.hse { background:#fef3c7; color:#92400e; }
.team-badge.anbar { background:#d1fae5; color:#065f46; }
.item-done { background:#f0fdf4; }
.manual-report-box { border:2px dashed #e2e8f0; border-radius:10px; padding:16px; margin-top:16px; background:var(--bg); }
.report-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:8px; }
.report-row .inp { flex:1; min-width:100px; }
</style>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2>📋 <?= t('nav_work_orders') ?></h2>
    <div class="d-flex gap-2 align-center">
        <?php if ($can_send_wo): ?>
        <button onclick="openModal('createWOModal')" class="btn btn-warning" style="font-size:12px"><i data-lucide="plus"></i> <?= t('btn_submit') ?> <?= t('nav_work_orders') ?></button>
        <?php endif; ?>
        <span class="team-badge <?= $user_team ?>"><?= t('واحد') ?> <?= t('شما:') ?> <?= $team_labels[$user_team] ?></span>
    </div>
</div>

<?php if ($can_view_all): ?>
<!-- تب‌های سه واحد -->
<div class="tabs mb-4 animate-in" style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach (['ejra', 'hse', 'anbar'] as $tb): ?>
    <a href="index.php?page=work_orders&tab=<?= $tb ?>"
       class="btn <?= $active_tab === $tb ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
       style="<?= $active_tab === $tb ? '' : 'border:1px solid var(--border)' ?>">
        <?= $team_icons[$tb] ?> <?= $team_labels[$tb] ?>
    </a>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="tabs mb-4 animate-in">
    <span class="team-badge <?= $active_tab ?>" style="font-size:13px;padding:6px 16px"><?= $team_icons[$active_tab] ?> <?= $team_labels[$active_tab] ?></span>
</div>
<?php endif; ?>

<?php
// ⚠️ اخطار آیتم‌های جامانده از دستور کار روزهای قبل (همین واحد)
$stL = $pdo->prepare("SELECT woi.id, woi.description, wo.order_date, wo.shift_type FROM work_order_items woi JOIN work_orders wo ON woi.work_order_id = wo.id WHERE wo.workshop_id = ? AND wo.target_team = ? AND woi.is_completed = 0 AND wo.order_date < CURDATE() ORDER BY wo.order_date DESC, woi.row_num LIMIT 30");
$stL->execute([$ws_id, $active_tab]);
$leftover_items = $stL->fetchAll();
?>
<?php if (!empty($leftover_items)): ?>
<div class="card mb-4 animate-in" style="border-right:4px solid #f59e0b;background:#fffbeb">
    <div style="padding:12px 16px">
        <div class="d-flex align-center gap-2" style="font-weight:700;color:#92400e;font-size:13px">
            ⚠️ <?= count($leftover_items) ?> <?= t('آیتم') ?> <?= t('lea_from') ?> <?= t('nav_work_orders') ?> <?= t('روزهای قبل') ?> <?= t('هنوز') ?> <?= t('انجام') ?> <?= t('نشده') ?>!
        </div>
        <details style="margin-top:6px">
            <summary style="cursor:pointer;font-size:12px;color:#b45309"><?= t('btn_show') ?> <?= t('sal_details') ?> (<?= t('با') ?> <?= t('انجام') ?> <?= t('هرکدام') ?>، <?= t('همان') ?> <?= t('wh_day') ?> <?= t('وارد') ?> <?= t('گزارش') ?> <?= t('sal_daily') ?> <?= t('می‌شود') ?>)</summary>
            <ul style="margin:8px 0 0;padding:0 18px 0 0;font-size:12px;line-height:1.9">
                <?php foreach ($leftover_items as $li): ?>
                <li>
                    <span style="background:#fef3c7;border-radius:6px;padding:1px 6px;font-size:11px"><?= to_jalali($li['order_date']) ?> <?= ($li['shift_type'] ?? 'day') === 'night' ? '🌙' : '☀️' ?></span>
                    <?= htmlspecialchars($li['description']) ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </details>
    </div>
</div>
<?php endif; ?>

<!-- فرم گزارش کار (3 فیلدی) -->
<div class="card mb-4 animate-in">
    <div class="card-header"><h3 class="card-title" style="font-size:14px">✍️ <?= t('ثبت') ?> <?= t('nav_daily_reports') ?> — <?= t('فرم') ?> <?= $team_labels[$active_tab] ?></h3></div>
    <div style="padding:16px">
        <form method="POST" action="index.php?page=work_orders" id="manualReportForm">
            <?= get_csrf_field() ?>
            <input type="hidden" name="action" value="save_manual_report">
            <input type="hidden" name="category" value="<?= htmlspecialchars($active_tab) ?>">
            <div class="report-row">
                <input type="text" name="report_date" id="manualDate" value="<?= jalali_now() ?>" class="form-control jalali-date" style="width:120px;font-size:12px" placeholder="<?= t('wh_date') ?>">
                <input type="text" name="wo_description" id="manualDesc" class="form-control" placeholder="<?= t('شرح') ?> <?= t('فعالیت') ?>..." style="font-size:12px" required>
                <input type="text" name="wo_progress" id="manualProgress" class="form-control" style="width:80px;font-size:12px" placeholder="<?= t('درصد') ?> %">
                <input type="text" name="wo_next_day" id="manualNextDay" class="form-control" style="width:140px;font-size:12px" placeholder="<?= t('پیش‌بینی') ?> <?= t('wh_next_day') ?>">
                <button type="submit" id="btnManualSubmit" class="btn btn-primary btn-sm"><?= t('ثبت') ?> <?= t('گزارش') ?></button>
            </div>
            <p style="font-size:11px;color:#999;margin:0"><?= t('با') ?> <?= t('کلیک') ?> «<?= t('انجام شد') ?>» <?= t('روی') ?> <?= t('دستور کار') ?>، <?= t('info') ?> <?= t('خودکار') ?> <?= t('به') ?> <?= t('این') ?> <?= t('فرم') ?> <?= t('اضافه') ?> <?= t('می‌شود') ?></p>
        </form>
    </div>
</div>

<div id="ordersArea">
<?php
$stmt = $pdo->prepare("SELECT wo.*, u.full_name as creator_name FROM work_orders wo JOIN users u ON wo.created_by = u.id WHERE wo.workshop_id = ? AND wo.target_team = ? ORDER BY wo.order_date DESC, wo.created_at DESC LIMIT 30");
$stmt->execute([$ws_id, $active_tab]);
$orders = $stmt->fetchAll();

$itemStmt = $pdo->prepare("SELECT woi.*, u.full_name as completed_by_name FROM work_order_items woi LEFT JOIN users u ON woi.completed_by = u.id WHERE woi.work_order_id = ? ORDER BY woi.row_num");
foreach ($orders as &$o) {
    $itemStmt->execute([$o['id']]);
    $o['items'] = $itemStmt->fetchAll();
    $completed = 0;
    foreach ($o['items'] as $item) { if ($item['is_completed']) $completed++; }
    $o['completed_count'] = $completed;
    $o['total_count'] = count($o['items']);
}
?>

<?php if (empty($orders)): ?>
<div class="card animate-in">
    <div class="empty-state" style="padding:60px">
        <i data-lucide="clipboard-list" style="width:48px;height:48px;color:var(--text-secondary);margin-bottom:12px"></i>
        <h3 style="color:var(--text-secondary)"><?= t('دستور کاری ندارید') ?></h3>
    </div>
</div>
<?php else: ?>

<?php
$grouped = [];
foreach ($orders as $o) {
    $jalali_date = to_jalali($o['order_date']);
    if (!$jalali_date || strpos($jalali_date, '0000') !== false || strpos($jalali_date, '-') !== false) {
        $jalali_date = jalali_now();
    }
    $grouped[$jalali_date][] = $o;
}
?>

<?php foreach ($grouped as $date_label => $day_orders): ?>
<div class="card mb-4 animate-in">
    <div class="card-header"><h3 class="card-title" style="font-size:14px">📅 <?= $date_label ?></h3></div>

    <?php foreach ($day_orders as $wo): ?>
    <div style="padding:16px;border-top:1px solid var(--border)">
        <div class="d-flex justify-between align-center mb-3">
            <div class="d-flex gap-2 align-center" style="flex-wrap:wrap">
                <span class="wo-status <?= $wo['status'] ?>"><?= $wo['status'] === 'completed' ? '✓ ' . t('تکمیل شده') : '⏳ ' . t('در انتظار') ?></span>
                <span class="wo-status" style="<?= ($wo['shift_type'] ?? 'day') === 'night' ? 'background:#1e293b;color:#e2e8f0' : 'background:#fde68a;color:#92400e' ?>"><?= ($wo['shift_type'] ?? 'day') === 'night' ? '🌙 ' . t('شیفت شب') : '☀️ ' . t('شیفت روز') ?></span>
                <span style="font-size:12px;color:var(--text-secondary)"><?= $wo['completed_count'] ?>/<?= $wo['total_count'] ?> <?= t('تکمیل') ?></span>
            </div>
            <span class="text-muted" style="font-size:12px"><?= t('توسط:') ?> <?= $wo['creator_name'] ?></span>
        </div>

        <table class="wo-items-table">
            <thead>
                <tr>
                    <th style="width:35px"><?= t('ردیف') ?></th>
                    <th style="text-align:right"><?= t('شرح') ?> <?= t('فعالیت') ?></th>
                    <th style="width:80px"><?= t('پیشرفت') ?></th>
                    <th style="text-align:right"><?= t('پیش‌بینی') ?> <?= t('wh_next_day') ?></th>
                    <th style="width:90px"><?= t('wh_status') ?></th>
                    <th style="width:100px"><?= t('انجام‌دهنده') ?></th>
                    <th style="width:50px"><?= t('wh_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($wo['items'] as $item): ?>
                <tr id="item-row-<?= $item['id'] ?>" class="<?= $item['is_completed'] ? 'item-done' : '' ?>">
                    <td><?= $item['row_num'] ?></td>
                    <td style="text-align:right;font-weight:500;font-size:12px" data-field="desc"><?= htmlspecialchars($item['description']) ?></td>
                    <td>
                        <?php if ($item['is_completed']): ?>
                        <span style="font-size:12px;font-weight:600;color:#16a34a"><?= htmlspecialchars($item['progress']) ?: '-' ?></span>
                        <?php else: ?>
                        <input type="text" class="inp item-progress" value="<?= htmlspecialchars($item['progress']) ?>" placeholder="%" style="width:60px" onchange="updateItemField(<?= $item['id'] ?>)">
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right">
                        <?php if ($item['is_completed']): ?>
                        <span style="font-size:11px" data-field="nextday"><?= htmlspecialchars($item['next_day']) ?: '-' ?></span>
                        <?php else: ?>
                        <input type="text" class="inp item-nextday" value="<?= htmlspecialchars($item['next_day']) ?>" placeholder="<?= t('پیش‌بینی') ?>..." onchange="updateItemField(<?= $item['id'] ?>)">
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($item['is_completed']): ?>
                        <span style="color:#16a34a;font-weight:600;font-size:12px">✓ <?= t('انجام شد') ?></span>
                        <?php else: ?>
                        <button onclick="toggleComplete(<?= $item['id'] ?>, 1)" style="background:#e0e7ff;color:#3730a3;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:600"><?= t('انجام شد') ?></button>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:10px;color:#666">
                        <?= $item['completed_by_name'] ?: '-' ?>
                        <?php if ($item['completed_at']): ?>
                        <div style="font-size:9px;color:#999"><?= substr($item['completed_at'], 11, 5) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ($item['is_completed']): ?>
                        <button onclick="undoComplete(<?= $item['id'] ?>)" title="<?= t('برگشت') ?> (<?= t('btn_delete') ?> <?= t('lea_from') ?> <?= t('گزارش') ?> <?= t('sal_daily') ?>)" style="background:none;border:none;color:#f59e0b;cursor:pointer;font-size:14px">↩</button>
                        <?php endif; ?>
                        <?php if ($can_send_wo): ?>
                        <button onclick='openItemEdit(<?= $item['id'] ?>, <?= js_arg($item['description']) ?>, <?= js_arg($item['progress'] ?? '') ?>, <?= js_arg($item['next_day'] ?? '') ?>)' title="<?= t('btn_edit') ?>" style="background:none;border:none;color:#0ea5e9;cursor:pointer;font-size:14px">✏️</button>
                        <?php endif; ?>
                        <button onclick="deleteItem(<?= $item['id'] ?>)" title="<?= t('btn_delete') ?>" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:14px">✕</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<script>
// تابع ارسال فرم با متد POST استاندارد مرورگر (بدون fetch برای عبور ایمن از فایروال OpenResty)
function submitPostAction(action, fields) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php?page=work_orders';
    fields = fields || {};
    fields.action = action;
    fields.csrf_token = <?= json_encode($_SESSION['csrf_token']) ?>;
    for (var k in fields) {
        if (fields.hasOwnProperty(k)) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = k;
            input.value = fields[k];
            form.appendChild(input);
        }
    }
    document.body.appendChild(form);
    form.submit();
}

function toggleComplete(itemId, isCompleted) {
    var row = document.getElementById('item-row-' + itemId);
    var progressInput = row ? row.querySelector('.item-progress') : null;
    var nextdayInput = row ? row.querySelector('.item-nextday') : null;
    var progress = progressInput ? progressInput.value.trim() : '';
    var nextDay = nextdayInput ? nextdayInput.value.trim() : '';

    submitPostAction('complete_item', {
        item_id: itemId,
        is_completed: isCompleted,
        progress: progress,
        next_day: nextDay
    });
}

function undoComplete(itemId) {
    toggleComplete(itemId, 0);
}

function deleteItem(itemId) {
    if (!confirm('<?= t('آیا از حذف این ردیف اطمینان دارید؟') ?>')) return;
    submitPostAction('delete_item', { item_id: itemId });
}

function updateItemField(itemId) {
    var row = document.getElementById('item-row-' + itemId);
    if (!row) return;
    var progress = row.querySelector('.item-progress') ? row.querySelector('.item-progress').value : '';
    var nextDay = row.querySelector('.item-nextday') ? row.querySelector('.item-nextday').value : '';
    submitPostAction('complete_item', {
        item_id: itemId,
        is_completed: 0,
        progress: progress,
        next_day: nextDay
    });
}
</script>

<!-- مودال ارسال دستور کار -->
<div id="createWOModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:750px">
        <div class="modal-header">
            <h3 class="modal-title">📋 <?= t('btn_submit') ?> <?= t('nav_work_orders') ?> <?= t('جدید') ?></h3>
            <button onclick="closeModal('createWOModal')" class="modal-close">✕</button>
        </div>
        <form id="createWOForm" method="POST" action="index.php?page=work_orders" onsubmit="return submitNewWO(event)">
            <?= get_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">
                    <div class="form-group" style="flex:0 0 140px">
                        <label class="form-label"><?= t('wh_date') ?> *</label>
                        <input type="text" name="order_date" id="newWO_date" class="form-control jalali-date" value="<?= jalali_now() ?>" required>
                    </div>
                    <div class="form-group" style="flex:0 0 120px">
                        <label class="form-label"><?= t('wh_shift') ?></label>
                        <div style="display:flex;gap:6px;margin-top:4px">
                            <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;padding:6px 10px;border:2px solid var(--primary);border-radius:8px;background:rgba(99,102,241,0.08)">
                                <input type="radio" name="shift" value="day" checked style="display:none">
                                <span>☀️ <?= t('wh_day') ?></span>
                            </label>
                            <label id="nightShiftLabel" style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;padding:6px 10px;border:2px solid var(--border);border-radius:8px">
                                <input type="radio" name="shift" value="night" style="display:none">
                                <span>🌙 <?= t('wh_night') ?></span>
                            </label>
                        </div>
                    </div>
                    <div class="form-group" style="flex:1;min-width:200px">
                        <label class="form-label"><?= t('btn_submit') ?> <?= t('به') ?> <?= t('واحد') ?> *</label>
                        <select name="target_team" class="form-control" required>
                            <option value="ejra">🔧 <?= t('واحد اجرا') ?></option>
                            <option value="hse">🛡️ <?= t('hse_sign_keeper') ?></option>
                            <option value="anbar">📦 <?= t('anbar_keeper') ?></option>
                        </select>
                    </div>
                </div>

                <div style="overflow-x:auto">
                    <table class="wo-items-table" id="newWOItemsTable">
                        <thead>
                            <tr>
                                <th style="width:35px"><?= t('ردیف') ?></th>
                                <th style="min-width:200px"><?= t('شرح') ?> <?= t('فعالیت') ?> *</th>
                                <th style="width:100px"><?= t('درصد پیشرفت') ?></th>
                                <th style="min-width:160px"><?= t('پیش‌بینی') ?> <?= t('wh_next_day') ?></th>
                                <th style="width:35px"></th>
                            </tr>
                        </thead>
                        <tbody id="newWOItemsBody">
                            <tr>
                                <td style="text-align:center">1</td>
                                <td><input class="inp" name="item_description[]"></td>
                                <td><input class="inp" name="item_progress[]" placeholder="%"></td>
                                <td><input class="inp" name="item_next_day[]"></td>
                                <td style="text-align:center"><button type="button" onclick="removeNewWORow(this)" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px">✕</button></td>
                            </tr>
                            <tr>
                                <td style="text-align:center">2</td>
                                <td><input class="inp" name="item_description[]"></td>
                                <td><input class="inp" name="item_progress[]" placeholder="%"></td>
                                <td><input class="inp" name="item_next_day[]"></td>
                                <td style="text-align:center"><button type="button" onclick="removeNewWORow(this)" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px">✕</button></td>
                            </tr>
                            <tr>
                                <td style="text-align:center">3</td>
                                <td><input class="inp" name="item_description[]"></td>
                                <td><input class="inp" name="item_progress[]" placeholder="%"></td>
                                <td><input class="inp" name="item_next_day[]"></td>
                                <td style="text-align:center"><button type="button" onclick="removeNewWORow(this)" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px">✕</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-ghost btn-sm mt-2" style="font-size:12px" onclick="addNewWORow()">+ <?= t('btn_add') ?> <?= t('ردیف') ?></button>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('createWOModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
                <button type="submit" class="btn btn-warning" style="color:#000;font-weight:700"><?= t('btn_submit') ?> <?= t('nav_work_orders') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش آیتم دستور کار -->
<div id="editItemModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:480px">
        <div class="modal-header">
            <h3 class="modal-title">✏️ <?= t('btn_edit') ?> <?= t('ردیف') ?> <?= t('nav_work_orders') ?></h3>
            <button onclick="closeModal('editItemModal')" class="modal-close">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="edit_item_id">
            <div class="form-group">
                <label class="form-label"><?= t('شرح') ?> <?= t('فعالیت') ?> *</label>
                <input type="text" id="edit_item_desc" class="form-control">
            </div>
            <div class="form-group" style="display:flex;gap:10px">
                <div style="flex:0 0 120px">
                    <label class="form-label"><?= t('پیشرفت') ?></label>
                    <input type="text" id="edit_item_prog" class="form-control" placeholder="%">
                </div>
                <div style="flex:1">
                    <label class="form-label"><?= t('پیش‌بینی') ?> <?= t('wh_next_day') ?></label>
                    <input type="text" id="edit_item_next" class="form-control">
                </div>
            </div>
            <p style="font-size:11px;color:var(--text-secondary);margin:0"><?= t('اگر') ?> <?= t('این') ?> <?= t('ردیف') ?> <?= t('انجام') ?> <?= t('شده') ?> <?= t('باشد') ?>، <?= t('متن') ?> <?= t('آن') ?> <?= t('در') ?> <?= t('گزارش') ?> <?= t('sal_daily') ?> <?= t('هم') ?> <?= t('به') ?>‌<?= t('wh_day') ?> <?= t('می‌شود') ?>.</p>
        </div>
        <div class="modal-footer">
            <button onclick="closeModal('editItemModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
            <button onclick="submitItemEdit()" class="btn btn-primary"><?= t('btn_save') ?></button>
        </div>
    </div>
</div>

<script>
function openItemEdit(id, desc, prog, next) {
    document.getElementById('edit_item_id').value = id;
    document.getElementById('edit_item_desc').value = desc || '';
    document.getElementById('edit_item_prog').value = prog || '';
    document.getElementById('edit_item_next').value = next || '';
    openModal('editItemModal');
}

function submitItemEdit() {
    var desc = document.getElementById('edit_item_desc').value.trim();
    if (!desc) { alert('<?= t('شرح فعالیت را وارد کنید') ?>'); return; }
    submitPostAction('edit_item', {
        item_id: document.getElementById('edit_item_id').value,
        description: desc,
        progress: document.getElementById('edit_item_prog').value.trim(),
        next_day: document.getElementById('edit_item_next').value.trim()
    });
}
</script>

<script>
// ===== <?= t('مدیریت') ?> <?= t('ردیف') ?>‌<?= t('های') ?> <?= t('دستور کار') ?> =====
function addNewWORow() {
    var tbody = document.getElementById('newWOItemsBody');
    var count = tbody.rows.length + 1;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td style="text-align:center">' + count + '</td>'
        + '<td><input class="inp" name="item_description[]"></td>'
        + '<td><input class="inp" name="item_progress[]" placeholder="%"></td>'
        + '<td><input class="inp" name="item_next_day[]"></td>'
        + '<td style="text-align:center"><button type="button" onclick="removeNewWORow(this)" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px">✕</button></td>';
    tbody.appendChild(tr);
}

function removeNewWORow(btn) {
    var tbody = document.getElementById('newWOItemsBody');
    if (tbody.rows.length <= 1) return;
    btn.closest('tr').remove();
    Array.from(tbody.rows).forEach(function(row, i) {
        row.cells[0].textContent = i + 1;
    });
}

// شیفت toggle
document.querySelectorAll('#createWOModal input[name="shift"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('#createWOModal input[name="shift"]').forEach(function(r) {
            r.closest('label').style.borderColor = 'var(--border)';
            r.closest('label').style.background = '';
        });
        this.closest('label').style.borderColor = 'var(--primary)';
        this.closest('label').style.background = 'rgba(99,102,241,0.08)';
    });
});

// ارسال دستور کار (ارسال فرم مستقیم به سرور برای عبور ایمن از فایروال)
function submitNewWO(e) {
    var form = document.getElementById('createWOForm');
    var descs = form.querySelectorAll('input[name="item_description[]"]');
    var hasItem = false;
    for (var i = 0; i < descs.length; i++) {
        if (descs[i].value.trim()) { hasItem = true; break; }
    }
    if (!hasItem) {
        alert('<?= t('حداقل یک ردیف فعالیت پر کنید') ?>');
        if (e && e.preventDefault) e.preventDefault();
        return false;
    }
    return true;
}
</script>

<?php include 'includes/footer.php'; ?>
