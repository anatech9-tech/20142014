<?php
$pageTitle = t('nav_workshops');
$ws_id = $_SESSION['workshop_id'] ?? 0;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = sanitize($_POST['name']);
        $address = sanitize($_POST['address'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $employer_name = sanitize($_POST['employer_name'] ?? '');
        
        $logo = '';
        if (!empty($_FILES['logo']['name'])) {
            if (!is_dir(LOGO_DIR)) mkdir(LOGO_DIR, 0777, true);
            $filename = uniqid() . '_' . basename($_FILES['logo']['name']);
            $logo = 'uploads/logos/' . $filename;
            move_uploaded_file($_FILES['logo']['tmp_name'], LOGO_DIR . $filename);
            compress_image(LOGO_DIR . $filename, 800, 85);
        }

        $employer_logo = '';
        if (!empty($_FILES['employer_logo']['name'])) {
            if (!is_dir(LOGO_DIR)) mkdir(LOGO_DIR, 0777, true);
            $filename = 'emp_' . uniqid() . '_' . basename($_FILES['employer_logo']['name']);
            $employer_logo = 'uploads/logos/' . $filename;
            move_uploaded_file($_FILES['employer_logo']['tmp_name'], LOGO_DIR . $filename);
            compress_image(LOGO_DIR . $filename, 800, 85);
        }
        
        $stmt = $pdo->prepare("INSERT INTO workshops (name, address, logo, employer_name, employer_logo, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $address, $logo, $employer_name, $employer_logo, $description]);
        
        $new_id = $pdo->lastInsertId();
        
        // Give all permissions
        $perms = ['view_dashboard','manage_workshops','manage_personnel','manage_work_hours','manage_salary','manage_bonus_penalty','manage_advances','manage_notifications','manage_subcontractors','manage_purchases','manage_petty_cash','manage_arash_coins','manage_leaves','manage_settings','view_reports','export_data','audit_log','manage_contracts','transfer_personnel'];
        $stmt = $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, workshop_id, permission) VALUES (?, ?, ?)");
        foreach ($perms as $p) {
            $stmt->execute([$_SESSION['user_id'], $new_id, $p]);
        }
        
        // Audit
        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'create', 'workshop', ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $new_id, $name]);
        
        showToast(t('کارگاه با موفقیت ایجاد شد'));
        redirect('index.php?page=workshops');
    }
    
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $name = sanitize($_POST['name']);
        $address = sanitize($_POST['address'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $employer_name = sanitize($_POST['employer_name'] ?? '');
        
        $extra_sql = '';
        $params = [$name, $address, $description, $employer_name];
        
        if (!empty($_POST['remove_logo'])) {
            $extra_sql .= ", logo = NULL";
        } elseif (!empty($_FILES['logo']['name'])) {
            if (!is_dir(LOGO_DIR)) mkdir(LOGO_DIR, 0777, true);
            $filename = uniqid() . '_' . basename($_FILES['logo']['name']);
            $logo = 'uploads/logos/' . $filename;
            move_uploaded_file($_FILES['logo']['tmp_name'], LOGO_DIR . $filename);
            compress_image(LOGO_DIR . $filename, 800, 85);
            $extra_sql .= ", logo = ?";
            $params[] = $logo;
        }

        if (!empty($_POST['remove_employer_logo'])) {
            $extra_sql .= ", employer_logo = NULL";
        } elseif (!empty($_FILES['employer_logo']['name'])) {
            if (!is_dir(LOGO_DIR)) mkdir(LOGO_DIR, 0777, true);
            $filename = 'emp_' . uniqid() . '_' . basename($_FILES['employer_logo']['name']);
            $employer_logo = 'uploads/logos/' . $filename;
            move_uploaded_file($_FILES['employer_logo']['tmp_name'], LOGO_DIR . $filename);
            compress_image(LOGO_DIR . $filename, 800, 85);
            $extra_sql .= ", employer_logo = ?";
            $params[] = $employer_logo;
        }
        
        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE workshops SET name = ?, address = ?, description = ?, employer_name = ? {$extra_sql} WHERE id = ?");
        $stmt->execute($params);
        
        showToast(t('کارگاه بروزرسانی شد'));
        redirect('index.php?page=workshops');
    }
    
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE workshops SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        showToast(t('کارگاه غیرفعال شد'));
        redirect('index.php?page=workshops');
    }
}

// Get workshops
$stmt = $pdo->prepare("SELECT w.*, (SELECT COUNT(*) FROM workshop_personnel WHERE workshop_id = w.id AND status = 'active') as personnel_count FROM workshops w WHERE w.status = 'active' ORDER BY w.id");
$stmt->execute();
$workshops = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('wor_title') ?></h2>
    <?php if (has_permission('manage_workshops')): ?>
    <button onclick="openModal('addModal')" class="btn btn-primary">
        <i data-lucide="plus"></i> <?= t('wor_new') ?>
    </button>
    <?php endif; ?>
</div>

<div class="grid-3 animate-in">
    <?php
    // §fix v22: مسیر مطلق api تا اگر مرورگر روی URL عمیقی بود، لینک همچنان درست کار کند
    $__ws_root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    ?>
    <?php foreach ($workshops as $ws): ?>
    <a href="<?= $__ws_root ?>/index.php?action=switch_workshop&id=<?= $ws['id'] ?>" class="workshop-card">
        <?php if ($ws['logo']): ?>
            <img src="<?= $ws['logo'] ?>" alt="<?= $ws['name'] ?>">
        <?php else: ?>
            <div style="width:80px;height:80px;border-radius:16px;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-size:32px;font-weight:800;margin:0 auto 15px;">
                <?= mb_substr($ws['name'], 0, 1) ?>
            </div>
        <?php endif; ?>
        <h3><?= $ws['name'] ?></h3>
        <p><?= $ws['personnel_count'] ?> <?= t('wor_personnel_count') ?></p>
        <?php if ($ws['address']): ?>
        <p class="mt-1 text-muted" style="font-size:12px"><?= $ws['address'] ?></p>
        <?php endif; ?>
        <?php if (has_permission('manage_workshops')): ?>
        <div class="mt-2 btn-group" style="justify-content:center">
            <button onclick="event.preventDefault(); editWorkshop(<?= htmlspecialchars(json_encode($ws)) ?>)" class="btn btn-ghost btn-sm"><?= t('btn_edit') ?></button>
            <button onclick="event.preventDefault(); deleteWorkshop(<?= $ws['id'] ?>)" class="btn btn-danger btn-sm"><?= t('btn_delete') ?></button>
        </div>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
    
    <?php if (empty($workshops)): ?>
    <div class="empty-state" style="grid-column: 1/-1">
        <i data-lucide="building-2"></i>
        <h3><?= t('wor_no_workshops') ?></h3>
    </div>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header">
            <h3 class="modal-title"><?= t('wor_new') ?></h3>
            <button onclick="closeModal('addModal')" class="modal-close">✕</button>
        </div>
        <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?= t('wor_name') ?> *</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('wor_address') ?></label>
                    <textarea name="address" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('wor_description') ?></label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('wor_logo') ?> (<?= t('لوگوی کارگاه / شرکت') ?>)</label>
                    <input type="file" name="logo" class="form-control" accept="image/*">
                </div>
                <div class="form-group" style="padding:10px;background:rgba(245,158,11,.06);border-radius:8px;border:1px dashed var(--border)">
                    <label class="form-label">👔 <?= t('نام کارفرما') ?> <span class="text-muted" style="font-size:11px">(<?= t('برای سربرگ فرم‌ها') ?>)</span></label>
                    <input type="text" name="employer_name" class="form-control" placeholder="<?= t('مثلاً: گروه جاوید، شهرداری، مهندس کمائی...') ?>">
                    <label class="form-label mt-2">🖼️ <?= t('لوگوی کارفرما') ?></label>
                    <input type="file" name="employer_logo" class="form-control" accept="image/*">
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= t('btn_add') ?></button>
                <button type="button" onclick="closeModal('addModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header">
            <h3 class="modal-title"><?= t('btn_edit') ?> <?= t('wor_name') ?></h3>
            <button onclick="closeModal('editModal')" class="modal-close">✕</button>
        </div>
        <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?= t('wor_name') ?> *</label>
                    <input type="text" name="name" id="edit_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('per_address') ?></label>
                    <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('wor_description') ?></label>
                    <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('wor_logo') ?> (<?= t('خالی') ?> <?= t('بذارید') ?> <?= t('برای') ?> <?= t('عدم') ?> <?= t('تغییر') ?>)</label>
                    <input type="file" name="logo" class="form-control" accept="image/*">
                    <div id="edit_logo_preview" style="margin-top:6px;display:none;align-items:center;gap:8px">
                        <img id="edit_logo_img" src="" alt="logo" style="height:36px;border-radius:4px">
                        <label class="text-danger" style="font-size:12px;cursor:pointer"><input type="checkbox" name="remove_logo" value="1"> <?= t('حذف لوگو') ?></label>
                    </div>
                </div>
                <div class="form-group" style="padding:10px;background:rgba(245,158,11,.06);border-radius:8px;border:1px dashed var(--border)">
                    <label class="form-label">👔 <?= t('نام کارفرما') ?> <span class="text-muted" style="font-size:11px">(<?= t('سربرگ فرم گزارش روزانه') ?>)</span></label>
                    <input type="text" name="employer_name" id="edit_employer_name" class="form-control" placeholder="<?= t('نام کارفرما') ?>">
                    <label class="form-label mt-2">🖼️ <?= t('لوگوی کارفرما') ?> (<?= t('خالی بذارید برای عدم تغییر') ?>)</label>
                    <input type="file" name="employer_logo" class="form-control" accept="image/*">
                    <div id="edit_emp_logo_preview" style="margin-top:6px;display:none;align-items:center;gap:8px">
                        <img id="edit_emp_logo_img" src="" alt="employer logo" style="height:36px;border-radius:4px">
                        <label class="text-danger" style="font-size:12px;cursor:pointer"><input type="checkbox" name="remove_employer_logo" value="1"> <?= t('حذف لوگوی کارفرما') ?></label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button>
                <button type="button" onclick="closeModal('editModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function editWorkshop(ws) {
    document.getElementById('edit_id').value = ws.id;
    document.getElementById('edit_name').value = ws.name;
    document.getElementById('edit_address').value = ws.address || '';
    document.getElementById('edit_description').value = ws.description || '';
    document.getElementById('edit_employer_name').value = ws.employer_name || '';

    var lp = document.getElementById('edit_logo_preview');
    if (ws.logo) {
        document.getElementById('edit_logo_img').src = ws.logo;
        lp.style.display = 'flex';
    } else {
        lp.style.display = 'none';
    }

    var elp = document.getElementById('edit_emp_logo_preview');
    if (ws.employer_logo) {
        document.getElementById('edit_emp_logo_img').src = ws.employer_logo;
        elp.style.display = 'flex';
    } else {
        elp.style.display = 'none';
    }

    openModal('editModal');
}

async function deleteWorkshop(id) {
    if (await confirmAction('<?= t('آیا') ?> <?= t('lea_from') ?> <?= t('btn_deactivate') ?> <?= t('کردن') ?> <?= t('این') ?> <?= t('کارگاه') ?> <?= t('مطمئنید؟') ?>')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<?= get_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include 'includes/footer.php'; ?>
