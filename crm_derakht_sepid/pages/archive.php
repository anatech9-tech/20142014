<?php
$pageTitle = t('nav_archive');
$ws_id = $_SESSION['workshop_id'] ?? 0;
$is_admin = is_strict_admin();
$current_user_id = $_SESSION['user_id'];
$ARCHIVE_DIR = __DIR__ . '/../uploads/archive/';

if (!is_dir($ARCHIVE_DIR)) mkdir($ARCHIVE_DIR, 0777, true);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_folder') {
        $name = trim($_POST['folder_name'] ?? '');
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        if (!empty($name) && $ws_id > 0) {
            $stmt = $pdo->prepare("INSERT INTO archive_folders (parent_id, name, workshop_id, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$parent_id > 0 ? $parent_id : null, $name, $ws_id, $current_user_id]);
            showToast(t('پوشه ایجاد شد'));
        } else {
            showToast(t('خطا: نام پوشه الزامی است'), 'error');
        }
        $redir = $parent_id > 0 ? "?page=archive&folder=$parent_id" : "?page=archive";
        header("Location: index.php$redir");
        exit;
    }

    if ($action === 'upload_file') {
        $folder_id = (int)($_POST['folder_id'] ?? 0);
        if ($folder_id && isset($_FILES['files'])) {
            $folder_path = [];
            $fid = $folder_id;
            while ($fid) {
                $stmt3 = $pdo->prepare("SELECT id, parent_id, name FROM archive_folders WHERE id = ?");
                $stmt3->execute([$fid]);
                $finfo = $stmt3->fetch();
                if (!$finfo) break;
                $folder_path[] = $finfo['name'];
                $fid = $finfo['parent_id'];
            }
            $folder_path = array_reverse($folder_path);
            $upload_dir = $ARCHIVE_DIR . implode('/', $folder_path);
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $count = 0;
            foreach ($_FILES['files']['name'] as $i => $fname) {
                if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $safe_name = time() . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($fname));
                if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $upload_dir . '/' . $safe_name)) {
                    $fsize = filesize($upload_dir . '/' . $safe_name);
                    $ftype = mime_content_type($upload_dir . '/' . $safe_name);
                    $stmt2 = $pdo->prepare("INSERT INTO archive_files (folder_id, filename, original_name, file_size, file_type, uploaded_by, workshop_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt2->execute([$folder_id, $safe_name, $fname, $fsize, $ftype, $current_user_id, $ws_id]);
                    $count++;
                }
            }
            showToast(strtr(t('{count} فایل آپلود شد'), ['{count}' => $count]));
        }
        header("Location: index.php?page=archive&folder=$folder_id");
        exit;
    }

    if ($action === 'delete_file') {
        $file_id = (int)($_POST['file_id'] ?? 0);
        $folder_id = (int)($_POST['folder_id'] ?? 0);
        if ($file_id) {
            $stmt2 = $pdo->prepare("SELECT filename, folder_id FROM archive_files WHERE id = ?");
            $stmt2->execute([$file_id]);
            $finfo = $stmt2->fetch();
            if ($finfo) {
                $folder_path = [];
                $fid = $finfo['folder_id'];
                while ($fid) {
                    $stmt3 = $pdo->prepare("SELECT id, parent_id, name FROM archive_folders WHERE id = ?");
                    $stmt3->execute([$fid]);
                    $fp = $stmt3->fetch();
                    if (!$fp) break;
                    $folder_path[] = $fp['name'];
                    $fid = $fp['parent_id'];
                }
                $folder_path = array_reverse($folder_path);
                $full_path = $ARCHIVE_DIR . implode('/', $folder_path) . '/' . $finfo['filename'];
                if (file_exists($full_path)) @unlink($full_path);
                $pdo->prepare("DELETE FROM archive_files WHERE id = ?")->execute([$file_id]);
                showToast(t('فایل حذف شد'));
            }
        }
        $redir = $folder_id > 0 ? "?page=archive&folder=$folder_id" : "?page=archive";
        header("Location: index.php$redir");
        exit;
    }

    if ($action === 'delete_folder') {
        $folder_id = (int)($_POST['folder_id'] ?? 0);
        if ($folder_id) {
            $folder_path = [];
            $fid = $folder_id;
            while ($fid) {
                $stmt3 = $pdo->prepare("SELECT id, parent_id, name FROM archive_folders WHERE id = ?");
                $stmt3->execute([$fid]);
                $fp = $stmt3->fetch();
                if (!$fp) break;
                $folder_path[] = $fp['name'];
                $fid = $fp['parent_id'];
            }
            $folder_path = array_reverse($folder_path);
            $dir_path = $ARCHIVE_DIR . implode('/', $folder_path);
            $stmt2 = $pdo->prepare("SELECT filename FROM archive_files WHERE folder_id = ?");
            $stmt2->execute([$folder_id]);
            foreach ($stmt2->fetchAll() as $fi) {
                $fp = $dir_path . '/' . $fi['filename'];
                if (file_exists($fp)) @unlink($fp);
            }
            if (is_dir($dir_path)) @rmdir($dir_path);
            $pdo->prepare("DELETE FROM archive_folders WHERE id = ?")->execute([$folder_id]);
            showToast(t('پوشه حذف شد'));
        }
        header("Location: index.php?page=archive");
        exit;
    }

    if ($action === 'rename_folder') {
        $folder_id = (int)($_POST['folder_id'] ?? 0);
        $new_name = trim($_POST['new_name'] ?? '');
        if ($folder_id && !empty($new_name)) {
            $pdo->prepare("UPDATE archive_folders SET name = ? WHERE id = ? AND workshop_id = ?")->execute([$new_name, $folder_id, $ws_id]);
            showToast(t('نام پوشه تغییر کرد'));
        }
        $redir = $folder_id > 0 ? "?page=archive&folder=$folder_id" : "?page=archive";
        header("Location: index.php$redir");
        exit;
    }
}

// Current folder
$current_folder = (int)($_GET['folder'] ?? 0);
$breadcrumbs = [];
if ($current_folder) {
    $fid = $current_folder;
    while ($fid) {
        $stmt = $pdo->prepare("SELECT id, parent_id, name FROM archive_folders WHERE id = ?");
        $stmt->execute([$fid]);
        $finfo = $stmt->fetch();
        if (!$finfo) break;
        $breadcrumbs[] = $finfo;
        $fid = $finfo['parent_id'];
    }
    $breadcrumbs = array_reverse($breadcrumbs);
}

// Get subfolders
if ($current_folder) {
    $stmt = $pdo->prepare("SELECT * FROM archive_folders WHERE workshop_id = ? AND parent_id = ? ORDER BY name");
    $stmt->execute([$ws_id, $current_folder]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM archive_folders WHERE workshop_id = ? AND parent_id IS NULL ORDER BY name");
    $stmt->execute([$ws_id]);
}
$subfolders = $stmt->fetchAll();

// Get files
if ($current_folder) {
    $stmt = $pdo->prepare("SELECT af.*, u.full_name FROM archive_files af JOIN users u ON af.uploaded_by = u.id WHERE af.folder_id = ? ORDER BY af.created_at DESC");
    $stmt->execute([$current_folder]);
} else {
    $stmt = $pdo->prepare("SELECT af.*, u.full_name FROM archive_files af JOIN users u ON af.uploaded_by = u.id WHERE af.workshop_id = ? AND af.folder_id IN (SELECT id FROM archive_folders WHERE parent_id IS NULL AND workshop_id = ?) ORDER BY af.created_at DESC");
    $stmt->execute([$ws_id, $ws_id]);
}
$files = $stmt->fetchAll();

function getFilePath($pdo, $ARCHIVE_DIR, $folder_id, $filename) {
    $path = [];
    $fid = $folder_id;
    while ($fid) {
        $stmt = $pdo->prepare("SELECT id, parent_id, name FROM archive_folders WHERE id = ?");
        $stmt->execute([$fid]);
        $fp = $stmt->fetch();
        if (!$fp) break;
        $path[] = $fp['name'];
        $fid = $fp['parent_id'];
    }
    $path = array_reverse($path);
    return $ARCHIVE_DIR . implode('/', $path) . '/' . $filename;
}

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= $pageTitle ?></h2>
    <div class="d-flex gap-2">
        <button onclick="openModal('newFolderModal')" class="btn btn-primary btn-sm"><i data-lucide="folder-plus"></i> <?= t('پوشه جدید') ?></button>
        <?php if ($current_folder): ?>
        <button onclick="openModal('uploadModal')" class="btn btn-success btn-sm"><i data-lucide="upload"></i> <?= t('btn_upload') ?> <?= t('فایل') ?></button>
        <?php endif; ?>
    </div>
</div>

<!-- Breadcrumbs -->
<div class="card mb-4 animate-in" style="padding:10px 16px">
    <a href="index.php?page=archive" style="text-decoration:none;color:var(--primary);font-weight:500">🏠 <?= t('ریشه') ?></a>
    <?php foreach ($breadcrumbs as $bc): ?>
    <span style="margin:0 6px;color:var(--text-secondary)">/</span>
    <a href="index.php?page=archive&folder=<?= $bc['id'] ?>" style="text-decoration:none;color:var(--primary);font-weight:500"><?= htmlspecialchars($bc['name']) ?></a>
    <?php endforeach; ?>
</div>

<!-- Folders -->
<?php if (!empty($subfolders)): ?>
<div class="grid-3 mb-4 animate-in">
    <?php foreach ($subfolders as $sf):
        $stmt_c = $pdo->prepare("SELECT COUNT(*) FROM archive_folders WHERE parent_id = ?");
        $stmt_c->execute([$sf['id']]);
        $child_count = (int)$stmt_c->fetchColumn();
        $stmt_f = $pdo->prepare("SELECT COUNT(*) FROM archive_files WHERE folder_id = ?");
        $stmt_f->execute([$sf['id']]);
        $file_count = (int)$stmt_f->fetchColumn();
    ?>
    <div class="workshop-card" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px;display:flex;justify-content:space-between;align-items:center">
        <a href="index.php?page=archive&folder=<?= $sf['id'] ?>" style="text-decoration:none;color:var(--text);display:flex;align-items:center;gap:10px;flex:1">
            <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#f59e0b,#f97316);display:flex;align-items:center;justify-content:center;color:white;font-size:20px">📁</div>
            <div>
                <strong><?= htmlspecialchars($sf['name']) ?></strong>
                <div class="text-muted" style="font-size:11px"><?= $child_count ?> <?= t('پوشه ·') ?> <?= $file_count ?> <?= t('فایل') ?></div>
            </div>
        </a>
        <div class="d-flex gap-1">
            <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('btn_delete') ?> <?= t('پوشه') ?> <?= t('و') ?> <?= t('تمام') ?> <?= t('محتویات') ?> <?= t('آن') ?>؟')"><?= get_csrf_field() ?>
                <input type="hidden" name="action" value="delete_folder">
                <input type="hidden" name="folder_id" value="<?= $sf['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);font-size:11px;padding:4px 8px">✕</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Files -->
<?php if (!empty($files)): ?>
<div class="card animate-in">
    <div class="table-container">
        <table>
            <thead><tr><th><?= t('wh_name') ?> <?= t('فایل') ?></th><th><?= t('اندازه') ?></th><th><?= t('sal_type') ?></th><th><?= t('btn_upload') ?> <?= t('کننده') ?></th><th><?= t('wh_date') ?></th><th><?= t('wh_actions') ?></th></tr></thead>
            <tbody>
                <?php foreach ($files as $f):
                    $fp = getFilePath($pdo, $ARCHIVE_DIR, $f['folder_id'], $f['filename']);
                    $exists = file_exists($fp);
                ?>
                <tr>
                    <td>
                        <div class="d-flex align-center gap-2">
                            <span style="font-size:18px"><?= preg_match('/image/', $f['file_type']) ? '🖼️' : (preg_match('/pdf/', $f['file_type']) ? '📄' : '📎') ?></span>
                            <strong style="font-size:13px"><?= htmlspecialchars($f['original_name']) ?></strong>
                        </div>
                    </td>
                    <td style="font-size:12px"><?= $f['file_size'] > 1048576 ? round($f['file_size']/1048576, 1) . ' MB' : round($f['file_size']/1024) . ' KB' ?></td>
                    <td style="font-size:12px" class="text-muted"><?= $f['file_type'] ?: '-' ?></td>
                    <td style="font-size:12px"><?= $f['full_name'] ?></td>
                    <td style="font-size:12px"><?= to_jalali(substr($f['created_at'], 0, 10)) ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <?php if ($exists): ?>
                            <a href="uploads/archive/<?= ltrim(substr($fp, strlen(__DIR__ . '/../uploads/archive/')), '/') ?>" download class="btn btn-ghost btn-sm" style="font-size:11px"><?= t('btn_download') ?></a>
                            <?php endif; ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('btn_delete') ?> <?= t('فایل') ?>؟')"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="delete_file">
                                <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                                <input type="hidden" name="folder_id" value="<?= $current_folder ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);font-size:11px;padding:4px 8px"><?= t('btn_delete') ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($subfolders) && empty($files)): ?>
<div class="card animate-in">
    <p class="text-center text-muted" style="padding:40px"><?= t('این') ?> <?= t('پوشه') ?> <?= t('خالی') ?> <?= t('است') ?>. <?= t('فایل') ?> <?= t('btn_upload') ?> <?= t('کنید') ?> <?= t('یا') ?> <?= t('پوشه جدید') ?> <?= t('بسازید') ?>.</p>
</div>
<?php endif; ?>

<!-- New Folder Modal -->
<div id="newFolderModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('پوشه جدید') ?></h3><button onclick="closeModal('newFolderModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="create_folder">
            <input type="hidden" name="parent_id" value="<?= $current_folder ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?= t('wh_name') ?> <?= t('پوشه *') ?></label>
                    <input type="text" name="folder_name" class="form-control" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('newFolderModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= t('ایجاد') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Upload Modal -->
<?php if ($current_folder): ?>
<div id="uploadModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_upload') ?> <?= t('فایل') ?></h3><button onclick="closeModal('uploadModal')" class="modal-close">✕</button></div>
        <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="upload_file">
            <input type="hidden" name="folder_id" value="<?= $current_folder ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?= t('فایل') ?>‌<?= t('ها') ?> *</label>
                    <input type="file" name="files[]" class="form-control" multiple required>
                </div>
                <p class="text-muted" style="font-size:11px"><?= t('می‌توانید') ?> <?= t('چند') ?> <?= t('فایل') ?> <?= t('را') ?> <?= t('همزمان') ?> <?= t('انتخاب کنید') ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('uploadModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= t('btn_upload') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
