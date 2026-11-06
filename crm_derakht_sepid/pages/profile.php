<?php
$pageTitle = t('nav_profile');

// Fetch current user for avatar reference in POST
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        // Handle avatar upload
        $avatar_path = $current_user['avatar'] ?? '';
        if (!empty($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/avatars/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $filename = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '_' . basename($_FILES['avatar']['name']);
            $avatar_path = 'uploads/avatars/' . $filename;
            move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_dir . $filename);
            compress_image($upload_dir . $filename, 500, 85);
        } else {
            $avatar_path = sanitize($_POST['avatar_path'] ?? $current_user['avatar'] ?? '');
        }
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, phone = ?, address = ?, iban = ?, card_number = ?, account_number = ?, account_name = ?, avatar = ? WHERE id = ?");
        $stmt->execute([sanitize($_POST['full_name']), sanitize($_POST['phone'] ?? ''), sanitize($_POST['address'] ?? ''), sanitize($_POST['iban'] ?? ''), sanitize($_POST['card_number'] ?? ''), sanitize($_POST['account_number'] ?? ''), sanitize($_POST['account_name'] ?? ''), $avatar_path, $_SESSION['user_id']]);
        $_SESSION['full_name'] = $_POST['full_name'];
        showToast(t('پروفایل بروزرسانی شد'));
        redirect("index.php?page=profile");
    }

    if ($action === 'change_username') {
        $new_user = sanitize($_POST['new_username']);
        if (empty($new_user)) {
            showToast(t('نام کاربری خالی است'), 'error');
        } else {
            // Check uniqueness
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $chk->execute([$new_user, $_SESSION['user_id']]);
            if ($chk->fetch()) {
                showToast(t('این نام کاربری قبلاً استفاده شده'), 'error');
            } else {
                $pdo->prepare("UPDATE users SET username = ? WHERE id = ?")->execute([$new_user, $_SESSION['user_id']]);
                $_SESSION['username'] = $new_user;
                showToast(t('نام کاربری تغییر کرد'));
            }
        }
        redirect("index.php?page=profile");
    }

    if ($action === 'change_password') {
        if ($_POST['new_password'] !== $_POST['confirm_password']) {
            showToast(t('رمز عبور مطابقت ندارد'), 'error');
        } else {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (password_verify($_POST['old_password'], $user['password'])) {
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $_SESSION['user_id']]);
                showToast(t('رمز عبور تغییر کرد'));
            } else {
                showToast(t('رمز عبور فعلی اشتباه است'), 'error');
            }
        }
        redirect("index.php?page=profile");
    }
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Get workshops
$stmt = $pdo->prepare("SELECT w.*, wp.daily_salary FROM workshops w JOIN workshop_personnel wp ON w.id = wp.workshop_id WHERE wp.user_id = ? AND wp.status = 'active'");
$stmt->execute([$_SESSION['user_id']]);
$my_workshops = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('pro_title') ?></h2>
</div>

<div class="grid-2 animate-in">
    <div class="card">
        <div class="card-header"><h3 class="card-title"><?= t('pro_personal_info') ?></h3></div>
        <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <div class="form-group"><label class="form-label"><?= t('per_full_name') ?></label><input type="text" name="full_name" class="form-control" value="<?= $user['full_name'] ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('per_username') ?></label>
                <input type="text" class="form-control" value="<?= $user['username'] ?>" disabled style="margin-bottom:6px">
            </div>
            <div class="form-group"><label class="form-label"><?= t('per_phone') ?></label><input type="text" name="phone" class="form-control" value="<?= $user['phone'] ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('per_national_code') ?></label><input type="text" class="form-control" value="<?= $user['national_code'] ?>" disabled></div>
            <div class="form-group"><label class="form-label"><?= t('per_address') ?></label><input type="text" name="address" class="form-control" value="<?= $user['address'] ?: '' ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('per_iban') ?></label><input type="text" name="iban" class="form-control" value="<?= $user['iban'] ?: '' ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('per_card_number') ?></label><input type="text" name="card_number" class="form-control" value="<?= $user['card_number'] ?: '' ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('per_account_number') ?></label><input type="text" name="account_number" class="form-control" value="<?= $user['account_number'] ?: '' ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('per_bank_name') ?></label><input type="text" name="account_name" class="form-control" value="<?= $user['account_name'] ?: '' ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('عکس') ?> <?= t('پروفایل') ?></label><input type="file" name="avatar" class="form-control" accept="image/*"></div>
            <button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button>
        </form>
    </div>
    
    <div class="card">
        <div class="card-header"><h3 class="card-title"><?= t('pro_change_password') ?></h3></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-group"><label class="form-label"><?= t('pro_old_password') ?></label><input type="password" name="old_password" class="form-control" required></div>
            <div class="form-group"><label class="form-label"><?= t('pro_new_password') ?></label><input type="password" name="new_password" class="form-control" required></div>
            <div class="form-group"><label class="form-label"><?= t('pro_confirm_password') ?></label><input type="password" name="confirm_password" class="form-control" required></div>
            <button type="submit" class="btn btn-primary"><?= t('btn_change_password') ?></button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title"><?= t('pro_change_username') ?></h3></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="change_username">
            <div class="form-group"><label class="form-label"><?= t('pro_current_username') ?></label><input type="text" class="form-control" value="<?= $user['username'] ?>" disabled></div>
            <div class="form-group"><label class="form-label"><?= t('pro_new_username') ?></label><input type="text" name="new_username" class="form-control" required></div>
            <button type="submit" class="btn btn-primary"><?= t('btn_change_username') ?></button>
        </form>
    </div>
</div>

<div class="card mt-4 animate-in">
    <div class="card-header"><h3 class="card-title"><?= t('pro_my_workshops') ?></h3></div>
    <div class="table-container"><table>
        <thead><tr><th><?= t('کارگاه') ?></th><th><?= t('per_daily_salary') ?></th></tr></thead>
        <tbody>
            <?php foreach ($my_workshops as $w): ?>
            <tr><td><?= $w['name'] ?></td><td><?= number_format($w['daily_salary']) ?> <?= t('ت') ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php include 'includes/footer.php'; ?>