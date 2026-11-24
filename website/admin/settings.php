<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
Auth::requirePermission('settings.view');
$pageTitle = 'تنظیمات سایت';
$activeMenu = 'settings';

$categories = [
    'general' => 'عمومی',
    'hero' => 'صفحه اصلی',
    'seo' => 'سئو',
    'contact' => 'تماس',
    'social' => 'شبکه‌های اجتماعی',
    'i18n' => 'زبان',
    'security' => 'امنیت',
    'analytics' => 'آمار',
];
$cat = $_GET['cat'] ?? 'general';

$settings = Settings::getByCategory($cat);

$labels = [
    'site_name' => ['نام سایت', 'text'],
    'site_email' => ['ایمیل اصلی', 'email'],
    'site_phone' => ['تلفن', 'text'],
    'site_address' => ['آدرس', 'textarea'],
    'hero_title' => ['عنوان اصلی', 'text'],
    'hero_subtitle' => ['زیرعنوان', 'text'],
    'hero_tagline' => ['شعار', 'text'],
    'seo_meta_title' => ['Meta Title', 'text'],
    'seo_meta_description' => ['Meta Description', 'textarea'],
    'seo_og_image' => ['OG Image URL', 'url'],
    'social_instagram' => ['اینستاگرام', 'url'],
    'social_linkedin' => ['لینکدین', 'url'],
    'social_whatsapp' => ['واتساپ (با +)', 'text'],
    'social_telegram' => ['تلگرام', 'text'],
    'default_lang' => ['زبان پیش‌فرض', 'select:fa,en'],
    'google_analytics_id' => ['کد GA4 (مثلاً G-XXXXX)', 'text'],
    'recaptcha_site_key' => ['reCAPTCHA Site Key', 'text'],
    'contact_rate_limit' => ['سقف پیام در ساعت', 'number'],
];
?>
<?php ob_start(); ?>
<div class="card">
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        <?php foreach ($categories as $k => $l): ?>
            <a href="?cat=<?= $k ?>" class="btn btn-sm <?= $cat === $k ? 'btn-primary' : '' ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
</div>

<form id="settingsForm">
    <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 16px;"><?= $categories[$cat] ?? $cat ?></h3>

        <?php if (empty($settings)): ?>
            <p style="color: var(--text-2);">موردی برای تنظیم در این بخش نیست.</p>
        <?php else: ?>
            <?php foreach ($settings as $key => $value):
                [$label, $type] = $labels[$key] ?? [$key, 'text'];
            ?>
                <div class="form-field">
                    <label><?= $label ?></label>
                    <?php if ($type === 'textarea'): ?>
                        <textarea name="settings[<?= $key ?>]" rows="3"><?= htmlspecialchars((string) $value) ?></textarea>
                    <?php elseif ($type === 'select:fa,en'): ?>
                        <select name="settings[<?= $key ?>]">
                            <option value="fa" <?= $value === 'fa' ? 'selected' : '' ?>>فارسی</option>
                            <option value="en" <?= $value === 'en' ? 'selected' : '' ?>>English</option>
                        </select>
                    <?php elseif ($type === 'boolean'): ?>
                        <label class="checkbox-row">
                            <input type="checkbox" name="settings[<?= $key ?>]" value="1" <?= $value ? 'checked' : '' ?>>
                            <span>فعال</span>
                        </label>
                    <?php else: ?>
                        <input type="<?= $type ?>" name="settings[<?= $key ?>]" value="<?= htmlspecialchars((string) $value) ?>" dir="<?= in_array($type, ['email','url']) ? 'ltr' : 'auto' ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (Auth::can('settings.edit')): ?>
                <button type="submit" class="btn btn-primary">💾 ذخیره</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</form>

<script>
document.getElementById('settingsForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'save');
    try {
        const r = await stsFetch('api/settings.php', { method: 'POST', body: fd });
        if (r.success) {
            const a = document.createElement('div');
            a.className = 'alert success';
            a.textContent = 'ذخیره شد ✓';
            document.querySelector('.content').prepend(a);
            setTimeout(() => a.remove(), 3000);
        }
    } catch (err) { alert(err.message); }
});
</script>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
