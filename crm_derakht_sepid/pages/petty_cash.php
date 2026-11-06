<?php
$pageTitle = t('nav_petty_cash');
$ws_id = $_SESSION['workshop_id'] ?? 0;
// §v11: ویراستارِ تنخواه = واریز/مدیریت — ناظر و بقیه مثل قبل فقط مشاهده + ثبت هزینه خودشان
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') || access_mode('petty_cash') === 'editor';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_income' && $is_admin) {
        $person = sanitize($_POST['person_name'] ?? '');
        $from = $_POST['payment_source'] ?? 'fund';
        $source_label = $from == 'petty_cash' ? t('nav_petty_cash') : t('صندوق');
        $desc = t('واریز تنخواه') . ($person ? ' ' . t('به') . ' ' . $person : '') . ' - ' . $source_label . ': ' . sanitize($_POST['description']);
        $stmt = $pdo->prepare("INSERT INTO petty_cash (workshop_id, type, amount, description, date, created_by) VALUES (?, 'income', ?, ?, ?, ?)");
        $stmt->execute([$ws_id, (float)$_POST['amount'], $desc, to_gregorian($_POST['date']), $_SESSION['user_id']]);
        showToast(t('واریز ثبت شد'));
        redirect("index.php?page=petty_cash");
    }
    
    if ($action === 'add_expense') {
        $stmt = $pdo->prepare("INSERT INTO petty_cash (workshop_id, type, amount, description, date, created_by) VALUES (?, 'expense', ?, ?, ?, ?)");
        $stmt->execute([$ws_id, (float)$_POST['amount'], sanitize($_POST['description']), to_gregorian($_POST['date']), $_SESSION['user_id']]);
        showToast(t('هزینه ثبت شد'));
        redirect("index.php?page=petty_cash");
    }
}

$personnel = [];
if ($is_admin) {
    $stmt = $pdo->prepare("SELECT full_name FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY full_name");
    $stmt->execute([$ws_id]);
    $personnel = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// §v12 §8: نمایش ماهانه تنخواه — پیش‌فرض ماه جاری شمسی، با امکان انتخاب سال/ماه یا «همه»
$pc_y = (int)($_GET['y'] ?? 0);
$pc_m = (int)($_GET['m'] ?? 0);
$pc_all = isset($_GET['all']);
$__jnow = gregorian_to_jalali((int)date('Y'), (int)date('m'), (int)date('d'));
if (!$pc_all && ($pc_y < 1300 || $pc_y > 1500 || $pc_m < 1 || $pc_m > 12)) { $pc_y = $__jnow[0]; $pc_m = $__jnow[1]; }

$pc_where_extra = '';
$pc_args = [$ws_id];
if (!$pc_all) {
    [$pc_gstart, $pc_gend] = jalali_month_bounds($pc_y, $pc_m);
    $pc_where_extra = " AND pc.date BETWEEN ? AND ?";
    $pc_args[] = $pc_gstart; $pc_args[] = $pc_gend;
}
$stmt = $pdo->prepare("SELECT pc.*, u.full_name FROM petty_cash pc JOIN users u ON pc.created_by = u.id WHERE pc.workshop_id = ?$pc_where_extra ORDER BY pc.date DESC, pc.created_at DESC");
$stmt->execute($pc_args);
$records = $stmt->fetchAll();

$income = array_sum(array_map(fn($r) => $r['type'] == 'income' ? $r['amount'] : 0, $records));
$expense = array_sum(array_map(fn($r) => $r['type'] == 'expense' ? $r['amount'] : 0, $records));

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('nav_petty_cash') ?></h2>
    <div class="btn-group">
        <button onclick="openModal('expenseModal')" class="btn btn-primary"><i data-lucide="minus-circle"></i> <?= t('ثبت') ?> <?= t('pet_expense') ?></button>
        <?php if ($is_admin): ?>
        <button onclick="openModal('incomeModal')" class="btn btn-success"><i data-lucide="plus-circle"></i> <?= t('واریز') ?> <?= t('nav_petty_cash') ?></button>
        <?php endif; ?>
    </div>
</div>

<div class="stats-grid animate-in">
    <div class="stat-card glass-gradient-2"><div class="stat-icon success"><i data-lucide="arrow-down-left"></i></div><div class="stat-info"><h3><?= number_format($income) ?></h3><p><?= t('pet_income') ?></p></div></div>
    <div class="stat-card glass-gradient-4"><div class="stat-icon danger"><i data-lucide="arrow-up-right"></i></div><div class="stat-info"><h3><?= number_format($expense) ?></h3><p><?= t('pet_expense') ?> <?= t('شده') ?></p></div></div>
    <div class="stat-card glass-gradient-1"><div class="stat-icon primary"><i data-lucide="wallet"></i></div><div class="stat-info"><h3 class="<?= ($income - $expense) < 0 ? 'text-danger' : '' ?>"><?= number_format($income - $expense) ?></h3><p><?= t('pet_balance') ?></p></div></div>
</div>

<!-- §v12 §8: انتخاب سال/ماه شمسی -->
<div class="card mb-3 animate-in" style="padding:12px 15px">
    <form method="GET" class="d-flex align-center gap-2 flex-wrap" style="font-size:13px">
        <input type="hidden" name="page" value="petty_cash">
        <b>📅 <?= t('btn_show') ?> <?= t('sal_monthly') ?>:</b>
        <select name="y" class="form-control" style="width:90px">
            <?php for ($yy = $__jnow[0] + 1; $yy >= $__jnow[0] - 3; $yy--): ?>
            <option value="<?= $yy ?>" <?= !$pc_all && $pc_y === $yy ? 'selected' : '' ?>><?= $yy ?></option>
            <?php endfor; ?>
        </select>
        <select name="m" class="form-control" style="width:110px">
            <?php for ($mm = 1; $mm <= 12; $mm++): ?>
            <option value="<?= $mm ?>" <?= !$pc_all && $pc_m === $mm ? 'selected' : '' ?>><?= jalali_month_name($mm) ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><?= t('btn_show') ?></button>
        <a href="index.php?page=petty_cash&all=1" class="btn btn-ghost btn-sm <?= $pc_all ? 'active' : '' ?>" style="<?= $pc_all ? 'background:var(--primary);color:#fff' : '' ?>"><?= t('همه دوره‌ها') ?></a>
        <?php if (!$pc_all): ?><span class="text-muted">— <?= t('nav_petty_cash') ?> <?= jalali_month_name($pc_m) ?> <?= $pc_y ?>: <?= count($records) ?> <?= t('رکورد') ?></span><?php endif; ?>
    </form>
</div>

<div class="card animate-in">
    <div class="table-container" id="reportTable">
        <table>
            <thead><tr><th><?= t('sal_type') ?></th><th><?= t('مبلغ') ?></th><th><?= t('توضیح') ?></th><th><?= t('wh_date') ?></th><th><?= t('ثبت کننده') ?></th></tr></thead>
            <tbody>
                <?php foreach ($records as $r): ?>
                <tr>
                    <td><span class="tag <?= $r['type'] == 'income' ? 'tag-active' : 'tag-rejected' ?>"><?= $r['type'] == 'income' ? t('دریافت') : t('pet_expense') ?></span></td>
                    <td><?= number_format($r['amount']) ?> <?= t('ت') ?></td>
                    <td><?= $r['description'] ?></td>
                    <td><?= to_jalali($r['date']) ?></td>
                    <td><?= $r['full_name'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                <tr><td colspan="5" class="text-center text-muted" style="padding:40px"><?= t('رکوردی نیست') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Expense Modal -->
<div id="expenseModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('ثبت') ?> <?= t('pet_expense') ?></h3><button onclick="closeModal('expenseModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="add_expense">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('adv_amount') ?> *</label><input type="number" name="amount" class="form-control" required></div>
                <div class="form-group"><label class="form-label"><?= t('توضیح *') ?></label><input type="text" name="description" class="form-control" required placeholder="<?= t('بابت چی خرج شد') ?>"></div>
                <div class="form-group"><label class="form-label"><?= t('wh_date') ?></label><input type="text" name="date" class="form-control jalali-date" value="<?= jalali_now() ?>"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('ثبت') ?></button></div>
        </form>
    </div>
</div>

<!-- Income Modal (admin) -->
<div id="incomeModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('واریز') ?> <?= t('nav_petty_cash') ?></h3><button onclick="closeModal('incomeModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="add_income">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('wh_name') ?> <?= t('نیرو') ?> (<?= t('اختیاری') ?>)</label>
                    <input type="text" name="person_name" class="form-control" list="personnelList" placeholder="<?= t('برای') ?> <?= t('چه') ?> <?= t('کسی') ?> <?= t('واریز') ?> <?= t('می‌شود') ?>">
                    <datalist id="personnelList">
                        <?php foreach ($personnel as $pn): ?>
                        <option value="<?= $pn ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group"><label class="form-label"><?= t('adv_amount') ?> *</label><input type="number" name="amount" class="form-control" required></div>
                <div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('کجا *') ?></label>
                    <div class="d-flex gap-3">
                        <label class="form-check" style="flex:1;padding:10px;background:var(--bg);border-radius:8px;border:2px solid var(--primary);text-align:center;cursor:pointer">
                            <input type="radio" name="payment_source" value="fund" checked style="display:none">
                            <strong><?= t('صندوق') ?></strong>
                        </label>
                        <label class="form-check" style="flex:1;padding:10px;background:var(--bg);border-radius:8px;border:2px solid var(--border);text-align:center;cursor:pointer">
                            <input type="radio" name="payment_source" value="petty_cash" style="display:none">
                            <strong><?= t('nav_petty_cash') ?></strong>
                        </label>
                    </div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('توضیح *') ?></label><input type="text" name="description" class="form-control" required placeholder="<?= t('بابت چی') ?> <?= t('واریز') ?> <?= t('شد') ?>"></div>
                <div class="form-group"><label class="form-label"><?= t('wh_date') ?></label><input type="text" name="date" class="form-control jalali-date" value="<?= jalali_now() ?>"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-success"><?= t('ثبت') ?> <?= t('واریز') ?></button></div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('input[name="payment_source"]').forEach(r => {
    r.addEventListener('change', function() {
        document.querySelectorAll('input[name="payment_source"]').forEach(x => x.closest('label').style.borderColor = 'var(--border)');
        this.closest('label').style.borderColor = 'var(--primary)';
    });
});
</script>

<?php include 'includes/footer.php'; ?>
