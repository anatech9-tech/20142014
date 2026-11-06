<?php
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = t('نام کاربری و رمز عبور الزامی است');
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['is_karigar'] = $user['is_karigar'];
            $_SESSION['is_nazer'] = ($user['role'] === 'nazer');

            if ($user['role'] === 'nazer') {
                // Nazer: no workshop assignment, sees all workshops, read-only
                $first_ws = $pdo->query("SELECT * FROM workshops WHERE status = 'active' ORDER BY id LIMIT 1")->fetch();
                if ($first_ws) {
                    $_SESSION['workshop_id'] = $first_ws['id'];
                    $_SESSION['workshop_name'] = $first_ws['name'];
                    $_SESSION['workshop_logo'] = $first_ws['logo'];
                }
                $_SESSION['permissions'] = ['nazer_view'];
            } else {
                // Get first workshop
                $ws_stmt = $pdo->prepare("SELECT w.* FROM workshops w JOIN workshop_personnel wp ON w.id = wp.workshop_id WHERE wp.user_id = ? AND w.status = 'active' LIMIT 1");
                $ws_stmt->execute([$user['id']]);
                $workshop = $ws_stmt->fetch();

                if ($workshop) {
                    $_SESSION['workshop_id'] = $workshop['id'];
                    $_SESSION['workshop_name'] = $workshop['name'];
                    $_SESSION['workshop_logo'] = $workshop['logo'];
                }

                // Load permissions
                $perm_stmt = $pdo->prepare("SELECT permission FROM user_permissions WHERE user_id = ?");
                $perm_stmt->execute([$user['id']]);
                $_SESSION['permissions'] = $perm_stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            
            // Audit log
            $log_stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, ip_address) VALUES (?, 'login', 'user', ?)");
            $log_stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR']]);
            
            redirect('index.php?page=dashboard');
        } else {
            $error = t('log_error');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($lang_dir, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('log_title') ?> - <?= htmlspecialchars(t('app_name'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/glass.css">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#16233f">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon-16.png">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
</head>
<body x-data="{theme: localStorage.getItem('theme') || 'light'}" :class="theme" dir="<?= $lang_dir ?>">
    <div class="login-container">
        <div class="login-box">
            <div class="login-logo">
                <img src="assets/images/logo-512.png" alt="درخت سپید تجارت پارس" class="login-brand-logo" width="150" height="157">
                <h1><?= htmlspecialchars(t('app_name'), ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="login-tagline"><?= t('log_subtitle') ?></p>
                <!-- Language Switcher -->
                <div style="margin-top:10px;display:flex;gap:8px;justify-content:center;align-items:center">
                    <button type="button" id="tourStartBtn" onclick="startPageTour()" title="راهنمای صفحه" style="font-size:15px;font-weight:800;padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:transparent;cursor:pointer;color:#ffffff">؟</button>
                    <a href="?lang=fa" style="font-size:12px;padding:4px 10px;border-radius:6px;text-decoration:none;border:1px solid <?= $lang === 'fa' ? 'var(--primary)' : 'var(--border)' ?>;color:var(--text);background:<?= $lang === 'fa' ? 'rgba(99,102,241,0.1)' : 'transparent' ?>"><?= lang_flag_svg('fa') ?> FA</a>
                    <a href="?lang=ar" style="font-size:12px;padding:4px 10px;border-radius:6px;text-decoration:none;border:1px solid <?= $lang === 'ar' ? 'var(--primary)' : 'var(--border)' ?>;color:var(--text);background:<?= $lang === 'ar' ? 'rgba(99,102,241,0.1)' : 'transparent' ?>"><?= lang_flag_svg('ar') ?> AR</a>
                    <a href="?lang=en" style="font-size:12px;padding:4px 10px;border-radius:6px;text-decoration:none;border:1px solid <?= $lang === 'en' ? 'var(--primary)' : 'var(--border)' ?>;color:var(--text);background:<?= $lang === 'en' ? 'rgba(99,102,241,0.1)' : 'transparent' ?>"><?= lang_flag_svg('en') ?> EN</a>
                </div>
            </div>
            
            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i data-lucide="alert-circle"></i>
                <?= $error ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="login-form"><?= get_csrf_field() ?>
                <div class="form-group">
                    <label class="form-label"><?= t('log_username') ?></label>
                    <input type="text" name="username" class="form-control" placeholder="<?= t('log_username') ?>" value="<?= sanitize($_POST['username'] ?? '') ?>" required autofocus>
                </div>
                
                <div class="form-group">
                    <label class="form-label"><?= t('log_password') ?></label>
                    <input type="password" name="password" class="form-control" placeholder="<?= t('log_password') ?>" required>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <?= t('log_button') ?>
                </button>
            </form>
            
            <div class="text-center mt-3">
                <button @click="theme = theme === 'light' ? 'dark' : 'light'; localStorage.setItem('theme', theme)" class="btn btn-ghost btn-sm">
                    <?= t('log_change_theme') ?>
                </button>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <script>
    /* بارگذار راهنمای صفحه (تور) */
    (function () {
        if (window.__otLoader) return;
        window.__otLoader = 1;
        var tries = 0;
        function inject() {
            if (document.getElementById('guideTourScript')) return;
            var s = document.createElement('script');
            s.id = 'guideTourScript';
            s.src = 'assets/js/guide_tour.js?v=20260830';
            document.head.appendChild(s);
        }
        window.startPageTour = function () {
            if (window.startPageTourReal) { window.startPageTourReal(); return; }
            inject();
            tries = 0;
            var iv = setInterval(function () {
                if (window.startPageTourReal) { clearInterval(iv); window.startPageTourReal(); return; }
                if (++tries > 60) { clearInterval(iv); alert('فایل راهنمای صفحه (assets/js/guide_tour.js) روی سرور پیدا نشد؛ لطفاً آن را آپلود کنید.'); }
            }, 100);
        };
        inject();
    })();
    </script>
    <script>lucide.createIcons();</script>
</body>
</html>
