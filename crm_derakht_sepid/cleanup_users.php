<?php
/**
 * پاک‌سازی یک‌بارهٔ حساب‌های تست/اضافی — تصمیم کارفرما (۲۰۲۶-۱۱-۰۸):
 *   «بجز اکانتی که فایل اینیسیال ساخت، بقیهٔ حساب‌ها همه حذف شوند.»
 *   نمونهٔ اجرا: ادمینِ نصب بعداً به «hamednbt» تغییرنام داده شده — این ابزار
 *   حسابِ «نگه‌داری» را صریحاً از تو می‌خواهد (پیش‌فرض: حساب فعلیِ لاگین‌شده).
 *
 * ── دو مرحله دارد ─────────────────────────────────────────────
 *   ۱) صفحهٔ گزارش (GET): هیچ چیز حذف نمی‌شود؛ نشان می‌دهد کدام حساب‌ها حذف
 *      می‌شوند، کدام می‌ماند، و چه دادهٔ حقوق/حضوری به حساب‌های حذف‌شونده متصل
 *      است (این داده‌ها عمداً پاک نمی‌شوند).
 *   ۲) حذف واقعی: فقط با POST + انتخاب حساب نگه‌داری + تایپِ دقیق عبارت تأیید.
 *
 * ── نکات ایمنی ────────────────────────────────────────────────
 *   • اجرا فقط برای ادمینی که همین حالا با role='admin' در دیتابیس وجود دارد.
 *   • حساب نگه‌داری باید role='admin' داشته باشد؛ حداقل یک ادمین همیشه می‌ماند.
 *   • پیش‌فرضِ نگه‌داری = حساب ادمینی که الآن لاگین کرده؛ می‌توان عوضش کرد
 *     (اگر حساب فعلی تو حذف‌شونده شود، بعد از اجرا از دسترسی خارج می‌شوی).
 *   • قبل از حذف از جدول‌های حساب‌ها backup گرفته می‌شود (backups/*.sql).
 *   • سوابق مالی/حقوقی پاک نمی‌شوند؛ فقط گزارش تعدادشان نشان داده می‌شود.
 *   • پس از اجرا، این فایل را از هاست حذف کنید.
 */
require __DIR__ . '/config.php';

$ACCOUNT_TABLES = ['notification_recipients','push_subscriptions','chat_channel_members','user_page_access','user_permissions','temp_entry_grants','workshop_personnel'];
$BUSINESS_TABLES = [ // فقط برای گزارش؛ پاک نمی‌شوند
    'work_hours'           => 'user_id',
    'leave_requests'       => 'user_id',
    'salary_calculations'  => 'user_id',
    'advances'             => 'user_id',
    'bonuses_penalties'    => 'user_id',
    'petty_cash'           => 'user_id',
    'daily_reports'        => 'user_id',
    'work_orders'          => 'created_by',
    'purchase_requests'    => 'created_by',
    'crm_deals'            => 'owner_user_id',
    'crm_leads'            => 'owner_user_id',
    'crm_tasks'            => 'user_id',
    'crm_activities'       => 'created_by',
    'audit_log'            => 'user_id',
    'chat_messages'        => 'user_id',
    'personnel_documents'  => 'user_id',
];
$CONFIRM_PHRASE = 'حذف همهٔ حساب‌ها بجز ادمین نصب';

// ── احراز هویت: باید ادمین وارد‌شده و هنوز در دیتابیس ادمین باقی مانده باشد ──
function cu_fail(string $msg): void {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><div dir="rtl" style="font-family:tahoma;max-width:640px;margin:60px auto;padding:24px;border:1px solid #fecaca;background:#fef2f2;border-radius:12px;color:#991b1b">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
    exit;
}
$me = null;
if (!empty($_SESSION['user_id'])) {
    try { $st = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $st->execute([(int)$_SESSION['user_id']]); $me = $st->fetch(); } catch (Throwable $e) {}
}
if (!$me || ($me['role'] ?? '') !== 'admin') {
    cu_fail('این ابزار فقط برای مدیر کل (ادمین) واردشده در دسترس است. ابتدا با حساب ادمین وارد شوید.');
}
$sessId = (int)$me['id'];

// ── کاندیداهای «حساب نگه‌داری»: فقط role='admin' ─────────────────────────────
try {
    $candidates = $pdo->query("SELECT id, username, full_name FROM users WHERE role = 'admin' ORDER BY id")->fetchAll();
} catch (Throwable $e) {
    cu_fail('خطا در خواندن جدول users: ' . $e->getMessage());
}
// حساب فعلی حتماً در کاندیداها هست (احراز هویت بالا). پیش‌فرض = همان حساب فعلی.
$keep = null;
$keepId = (int)($_REQUEST['keep'] ?? 0);
if ($keepId > 0) {
    foreach ($candidates as $c) { if ((int)$c['id'] === $keepId) { $keep = $c; break; } }
    if (!$keep) cu_fail('حساب نگه‌داری انتخاب‌شده معتبر نیست (یا ادمین نیست).');
} else {
    foreach ($candidates as $c) { if ((int)$c['id'] === $sessId) { $keep = $c; break; } }
}
$keepId = (int)$keep['id'];

$vst = $pdo->prepare("SELECT id, username, full_name, role, is_karigar, created_at FROM users WHERE id <> ? ORDER BY id");
$vst->execute([$keepId]);
$victims = $vst->fetchAll();
$victimIds = array_map(fn($r) => (int)$r['id'], $victims);

function table_exists(PDO $pdo, string $t): bool {
    try { $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"); $st->execute([$t]); return (bool)$st->fetchColumn(); } catch (Throwable $e) { return false; }
}

function business_counts(PDO $pdo, array $victimIds, array $BUSINESS_TABLES): array {
    $out = [];
    if (!$victimIds) return $out;
    $ph = implode(',', array_fill(0, count($victimIds), '?'));
    foreach ($BUSINESS_TABLES as $table => $col) {
        if (!table_exists($pdo, $table)) continue;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$col` IN ($ph)");
            $st->execute($victimIds);
            $n = (int)$st->fetchColumn();
            if ($n > 0) $out["$table.$col"] = $n;
        } catch (Throwable $e) { $out["$table.$col"] = -1; }
    }
    return $out;
}

function dump_tables(PDO $pdo, array $tables, string $file): bool {
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
    $sql = "-- پیش از پاک‌سازی حساب‌ها — " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- این فایل فقط پشتیبان است (برای نگهداری)؛ در گیت commit نشود.\nSET FOREIGN_KEY_CHECKS=0;\n";
    foreach ($tables as $t) {
        if (!table_exists($pdo, $t)) continue;
        try {
            $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll();
        } catch (Throwable $e) { continue; }
        if (!$rows) { $sql .= "-- $t: خالی\n"; continue; }
        $cols = array_keys($rows[0]);
        $colList = '`' . implode('`,`', $cols) . '`';
        $sql .= "\n-- جدول $t (" . count($rows) . " ردیف)\n";
        foreach ($rows as $row) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
            $sql .= "INSERT INTO `$t` ($colList) VALUES (" . implode(',', $vals) . ");\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return file_put_contents($file, $sql) !== false;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$didDelete = false;
$backupFile = '';
$deleteError = null;

if ($isPost && ($_POST['action'] ?? '') === 'delete') {
    $typed = trim((string)($_POST['confirm_phrase'] ?? ''));
    if ($typed !== $CONFIRM_PHRASE) {
        $deleteError = 'عبارت تأیید دقیق نیست؛ حذف انجام نشد.';
    } elseif (!$victimIds) {
        $deleteError = 'حسابی برای حذف وجود ندارد.';
    } else {
        $backupFile = BACKUP_DIR . 'pre_cleanup_users_' . date('Ymd_His') . '.sql';
        $dumpOk = dump_tables($pdo, array_merge(['users','teams'], $ACCOUNT_TABLES), $backupFile);
        if (!$dumpOk) {
            $deleteError = 'پشتیبان‌گیری ناموفق بود؛ حذف انجام نشد. دسترسی پوشهٔ backups/ را بررسی کنید.';
            @unlink($backupFile);
        } else {
            try {
                $pdo->beginTransaction();
                $ph = implode(',', array_fill(0, count($victimIds), '?'));
                foreach ($ACCOUNT_TABLES as $t) {
                    if (!table_exists($pdo, $t)) continue;
                    $pdo->prepare("DELETE FROM `$t` WHERE user_id IN ($ph)")->execute($victimIds);
                }
                // مدیر تیم‌های حذف‌شده را به NULL برگردان ( teams.manager_user_id )
                if (table_exists($pdo, 'teams')) {
                    $pdo->prepare("UPDATE teams SET manager_user_id = NULL WHERE manager_user_id IN ($ph)")->execute($victimIds);
                }
                $pdo->prepare("DELETE FROM users WHERE id <> ?")->execute([$keepId]);
                $pdo->commit();
                $didDelete = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $deleteError = 'خطا در حذف (چیزی پاک نشد، تراکنش برگشت): ' . $e->getMessage();
            }
        }
    }
}

$bizAfter = $didDelete ? [] : business_counts($pdo, $victimIds, $BUSINESS_TABLES);
$keptAdmin = [['id' => $keepId, 'username' => $keep['username'], 'full_name' => $keep['full_name'], 'role' => 'admin']];
$executorWillBeDeleted = !$didDelete && $keepId !== $sessId;
?><!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>پاک‌سازی حساب‌ها</title>
<style>body{font-family:tahoma,sans-serif;background:#f1f5f9;margin:0;padding:24px}main{max-width:920px;margin:0 auto}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px 22px;margin-bottom:16px}
h1{font-size:19px;margin:0 0 12px}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:right}th{background:#f8fafc}
.badge{display:inline-block;padding:2px 8px;border-radius:8px;font-size:11px}.keep{background:#dcfce7;color:#166534}.del{background:#fee2e2;color:#991b1b}
.warn{background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;padding:12px 14px;font-size:13px;color:#92400e}
.ok{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:12px;padding:12px 14px;color:#065f46}
button{background:#dc2626;color:#fff;border:0;border-radius:10px;padding:10px 22px;font-size:14px;cursor:pointer}input[type=text]{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:10px;margin:10px 0;font-family:inherit}</style></head>
<body><main>
<h1>🧹 پاک‌سازی حساب‌ها (به‌جز حساب نگه‌داریِ انتخاب‌شده)</h1>

<?php if ($didDelete): ?>
    <div class="ok"><b>انجام شد.</b> همهٔ حساب‌ها بجز <code><?= htmlspecialchars($keep['username']) ?></code> حذف شدند.<?= $backupFile ? ' پشتیبان حساب‌ها در <code>' . htmlspecialchars(basename($backupFile), ENT_QUOTES, 'UTF-8') . '</code> ذخیره شد.' : '' ?></div>
    <div class="warn"><b>همین حالا این فایل (<code>cleanup_users.php</code>) را از هاست حذف کن</b> تا کسی به‌اشتباه دوباره اجرا نشود. همچنین نشست‌های بازِ حساب‌های حذف‌شده با مراجعهٔ بعدی از کار می‌افتند؛ اگر مرورگری لاگین مانده، یک‌بار خروج/ورود کن.</div>
    <div class="card"><h1>حساب باقی‌مانده</h1>
    <table><thead><tr><th>ID</th><th>نام کاربری</th><th>نام</th><th>نقش</th></tr></thead><tbody>
    <?php foreach ($keptAdmin as $u): ?><tr><td><?= (int)$u['id'] ?></td><td><code><?= htmlspecialchars($u['username']) ?></code></td><td><?= htmlspecialchars($u['full_name']) ?></td><td><span class="badge keep"><?= htmlspecialchars($u['role']) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div>
<?php else: ?>
    <?php if ($deleteError): ?><div class="warn"><?= htmlspecialchars($deleteError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="card"><h1>🎯 حساب نگه‌داری (می‌ماند)</h1>
    <p>در حال حاضر این حساب انتخاب شده است. اگر باید حساب دیگری از این لیست بماند، روی «من را نگه دار» همان ردیف بزن:</p>
    <table><thead><tr><th>ID</th><th>نام کاربری</th><th>نام</th><th>نقش</th><th></th></tr></thead><tbody>
    <?php foreach ($candidates as $c): $isKeep = (int)$c['id'] === $keepId; $isMe = (int)$c['id'] === $sessId; ?>
    <tr>
        <td><?= (int)$c['id'] ?></td>
        <td><code><?= htmlspecialchars($c['username']) ?></code><?= $isMe ? ' <span class="badge" style="background:#e0f2fe;color:#0369a1">حساب فعلی شما</span>' : '' ?></td>
        <td><?= htmlspecialchars($c['full_name']) ?></td>
        <td><span class="badge keep"><?= htmlspecialchars($me['role']) ?></span></td>
        <td><?= $isKeep ? '<span class="badge keep">✔ می‌ماند</span>' : '<a href="?keep=' . (int)$c['id'] . '">من را نگه دار</a>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php if ($executorWillBeDeleted): ?>
    <div class="warn" style="margin-top:10px">⚠️ حساب <u>فعلیِ</u> شما (<code><?= htmlspecialchars($me['username']) ?></code>) در این حالت حذف خواهد شد و بلافاصله از دسترسی خارج می‌شوید.</div>
    <?php endif; ?>
    </div>

    <div class="card"><h1>✅ این حساب می‌ماند</h1>
    <table><thead><tr><th>ID</th><th>نام کاربری</th><th>نام</th><th>نقش</th></tr></thead><tbody>
    <?php foreach ($keptAdmin as $u): ?><tr><td><?= (int)$u['id'] ?></td><td><code><?= htmlspecialchars($u['username']) ?></code></td><td><?= htmlspecialchars($u['full_name']) ?></td><td><span class="badge keep"><?= htmlspecialchars($u['role']) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div>

    <div class="card"><h1>🗑️ این حساب(ها) حذف می‌شوند (<?= count($victims) ?>)</h1>
    <?php if (!$victims): ?><p>حسابی برای حذف وجود ندارد. 🎉</p><?php else: ?>
    <table><thead><tr><th>ID</th><th>نام کاربری</th><th>نام</th><th>نقش</th><th>نوع</th><th>تاریخ ساخت</th></tr></thead><tbody>
    <?php foreach ($victims as $u): $type = ['0' => 'کارمند', '1' => 'کارگر', '2' => 'پیمانکار'][(string)$u['is_karigar']] ?? '?'; ?>
    <tr><td><?= (int)$u['id'] ?></td><td><code><?= htmlspecialchars($u['username']) ?></code></td><td><?= htmlspecialchars($u['full_name']) ?></td><td><?= htmlspecialchars($u['role']) ?></td><td><?= $type ?></td><td><?= htmlspecialchars($u['created_at']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>

    <div class="card"><h1>📊 دادهٔ متصل به این حساب‌ها (پاک <u>نمی‌شود</u>)</h1>
    <?php if (!$bizAfter): ?><p>هیچ دادهٔ حقوق/حضوری/مالی متصل به این حساب‌ها پیدا نشد — احتمالاً پاک‌سازی کاملاً تمیزه.</p><?php else: ?>
    <div class="warn">این جدول‌ها ردیف‌های متصل به حساب‌های در حال حذف را دارند. چون ممکن است دادهٔ واقعی (نه تست) باشند، پاک نمی‌شوند. اگر مطمئن هستی همه تستی‌اند و خودت می‌خواهی پاکشان کنم، بعد از حذف حساب‌ها همین صفحه را دوباره باز کن و مقدار «بله» را ببین؛ یا پیام بده تا نسخهٔ دوم را بنویسم.</div>
    <table><thead><tr><th>جدول.ستون</th><th>تعداد ردیف</th></tr></thead><tbody>
    <?php foreach ($bizAfter as $k => $n): ?><tr><td><code><?= htmlspecialchars($k) ?></code></td><td><?= $n === -1 ? 'خطای خواندن' : number_format($n) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
    </div>

    <div class="card"><h1>⚠️ اجرای حذف</h1>
    <p>پیش از حذف، یک نسخهٔ پشتیبان از جدول‌های حساب‌ها در پوشهٔ <code>backups/</code> ذخیره می‌شود. برای حذف واقعی، دقیقاً این عبارت را تایپ کن و دکمه را بزن:</p>
    <form method="post"><input type="hidden" name="action" value="delete">
        <input type="hidden" name="keep" value="<?= $keepId ?>">
        <code style="font-size:14px"><?= htmlspecialchars($CONFIRM_PHRASE, ENT_QUOTES, 'UTF-8') ?></code>
        <input type="text" name="confirm_phrase" placeholder="عبارت بالا را اینجا تایپ کن…" autocomplete="off" required>
        <button type="submit">حذف <?= count($victims) ?> حساب</button>
    </form></div>
    <?php endif; ?>
<?php endif; ?>
</main></body></html>
