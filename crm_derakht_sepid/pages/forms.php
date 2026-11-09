<?php
$pageTitle = t('nav_forms');
$ws_id = $_SESSION['workshop_id'] ?? 0;
$is_yaser = (($_SESSION['username'] ?? '') === 'yaser');
$is_javidhse = (($_SESSION['username'] ?? '') === 'javidhse');
$is_karfarma_user = is_karfarma();

include 'includes/header.php';
?>

<style>
.forms-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    max-width: 900px;
    margin: 40px auto;
    padding: 0 20px;
}

.form-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 30px 24px;
    text-align: center;
    text-decoration: none;
    color: var(--text);
    transition: all 0.3s ease;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
}
.form-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 30px rgba(102, 126, 234, 0.2);
    border-color: var(--primary);
}
.form-card .icon-circle {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: white;
    flex-shrink: 0;
}
.form-card h3 {
    font-size: 15px;
    font-weight: 700;
    margin: 0;
}
.form-card p {
    font-size: 12px;
    color: var(--text-secondary);
    margin: 0;
    line-height: 1.6;
}

.icon-emp { background: linear-gradient(135deg, #667eea, #764ba2); }
.icon-wrk { background: linear-gradient(135deg, #f59e0b, #ef4444); }
.icon-rpt { background: linear-gradient(135deg, #10b981, #059669); }
.icon-hse { background: linear-gradient(135deg, #16a34a, #14532d); }
.icon-anb { background: linear-gradient(135deg, #ea580c, #7c2d12); }
</style>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('nav_forms') ?></h2>
</div>

<?php
// §v11: دسترسی فرم‌به‌فرم — هر فرم دسترسی خودش را می‌خواهد (yaser/javidhse طبق روال خودشان)
$form_ok = function ($key) { return in_array(access_mode($key), ['viewer', 'editor'], true); };
?>

<div class="forms-grid">
    <?php if (!$is_karfarma_user && !$is_javidhse && $form_ok('form_emp_pay')): ?>
    <a href="index.php?page=employee_payment_print" target="_blank" class="form-card animate-in">
        <div class="icon-circle icon-emp"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
        <h3><?= t('لیست واریزی کارکنان اداری') ?></h3>
        <p><?= t('جدول واریزی حقوق نیروهای اداری و مهندسین') ?></p>
    </a>
    <?php endif; ?>

    <?php if (!$is_karfarma_user && !$is_javidhse && $form_ok('form_anbar')): ?>
    <a href="index.php?page=anbar_report" target="_blank" class="form-card animate-in" style="animation-delay:0.3s">
        <div class="icon-circle icon-anb"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.3 7 12 12 20.7 7"/><line x1="12" y1="22" x2="12" y2="12"/></svg></div>
        <h3><?= t('فرم گزارش روزانه انبار') ?></h3>
        <p><?= t('گزارش روزانه انبار: ورود و خروج کالا، ابزارهای خراب و خلاصه موجودی') ?></p>
    </a>
    <?php endif; ?>

    <?php if (!$is_karfarma_user && !$is_javidhse && $form_ok('form_petty_month')): ?>
    <a href="index.php?page=petty_cash_print" target="_blank" class="form-card animate-in" style="animation-delay:0.35s">
        <div class="icon-circle" style="background:linear-gradient(135deg,#eab308,#a16207)"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M15 9.5c0-1.5-1.3-2.5-3-2.5s-3 1-3 2.5 1.3 2.3 3 2.5 3 1 3 2.5-1.3 2.5-3 2.5-3-1-3-2.5"/></svg></div>
        <h3><?= t('فرم گزارش ماهانه تنخواه') ?></h3>
        <p><?= t('گردش ماهانه تنخواه با جمع واریزی، هزینه‌ها و کل') ?></p>
    </a>
    <?php endif; ?>

    <?php if (!$is_karfarma_user && !$is_javidhse && $form_ok('form_inv_count')): ?>
    <a href="index.php?page=inventory_count_print" target="_blank" class="form-card animate-in" style="animation-delay:0.4s">
        <div class="icon-circle" style="background:linear-gradient(135deg,#0891b2,#164e63)"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></div>
        <h3><?= t('فرم شمارش موجودی انبار') ?></h3>
        <p><?= t('موجودی سیستم + ستون شمارش واقعی و اختلاف (با امکان چاپ فرم خالی)') ?></p>
    </a>
    <?php endif; ?>
</div>

<?php if (!$is_karfarma_user && !$is_javidhse && !$form_ok('form_anbar') && !$form_ok('form_emp_pay') && !$form_ok('form_petty_month') && !$form_ok('form_inv_count')): ?>
<p class="text-muted animate-in" style="text-align:center;padding:40px 20px">
    <?= t('هنوز فرمی برای شما فعال نشده است — از مدیر بخواهید در صفحه پرسنل با دکمهٔ «دسترسی»، فرم‌های موردنیازتان را فعال کند.') ?>
</p>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
