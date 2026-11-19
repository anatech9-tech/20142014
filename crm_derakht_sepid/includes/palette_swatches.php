<?php
/**
 * نمونه‌رنگ‌های ۶ پالت مرجع کارکت — مشترک بین مودال پالت (سربرگ) و کارت پالت در پروفایل.
 * نمونهٔ فعال بر اساس data-palette روی <html> با CSS مشخص می‌شود (.palette-swatch.active از app.js).
 */
$__palettes = [
    ['teal',    'فیروزه‌ای اصیل', '#308e89'],
    ['ocean',   'آبی اقیانوسی',   '#3478d4'],
    ['orchid',  'ارغوانی مدرن',   '#8660cf'],
    ['ruby',    'یاقوتی غروب',    '#d44f6c'],
    ['amber',   'کهربایی گرم',    '#bd861f'],
    ['emerald', 'زمردی نوین',     '#14a179'],
];
foreach ($__palettes as [$pk, $pname, $pcolor]): ?>
                <button type="button" class="palette-swatch" data-palette="<?= $pk ?>" aria-pressed="false" aria-label="پالت <?= $pname ?>">
                    <span class="swatch-dot" style="background:<?= $pcolor ?>"></span>
                    <span class="swatch-name"><?= $pname ?></span>
                    <i data-lucide="check" class="swatch-check"></i>
                </button>
<?php endforeach; ?>
