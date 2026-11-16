<?php
$pageTitle = t('nav_contracts');
$ws_id = $_SESSION['workshop_id'] ?? 0;

require_once __DIR__ . '/../includes/org_commission.php';
ensure_org_commission_schema($pdo);

$stmt = $pdo->prepare("SELECT u.id, u.full_name, u.staff_category, u.position, wp.contract_start, wp.contract_end, wp.contract_type, wp.insurance_number, wp.insurance_amount FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY wp.contract_end ASC");
$stmt->execute([$ws_id]);
$contracts = $stmt->fetchAll();

// مدارک اسکن‌شدهٔ هر نفر (اسکن قرارداد و مدارک داخل «پرسنل» آپلود می‌شود — بند ۸ و ۱۳)
$doc_map = [];
try {
    $stid = $pdo->query("SELECT user_id, COUNT(*) AS c FROM personnel_documents GROUP BY user_id");
    foreach ($stid->fetchAll() as $r) { $doc_map[(int)$r['user_id']] = (int)$r['c']; }
} catch (Throwable $e) {}
$cat_names = ['sales' => 'کارشناس فروش', 'office' => 'کارمند اداری', 'manager' => 'مدیر'];

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('nav_contracts') ?></h2>
</div>

<p class="text-muted animate-in" style="font-size:11.5px;margin:0 0 10px">📌 از این نسخه، اسکن قراردادها و مدارک هر نیرو داخل «پرسنل» ثبت و نگه‌داری می‌شود (دکمهٔ 📎 در ردیف هر شخص)؛ این صفحه فقط وضعیت سررسید قراردادها را نشان می‌دهد.</p>
<div class="card animate-in">
    <div class="table-container"><table>
        <thead><tr><th><?= t('wh_name') ?></th><th>دسته / سمت</th><th><?= t('per_contract_type') ?></th><th><?= t('wh_start') ?></th><th><?= t('wh_end') ?></th><th><?= t('باقیمانده') ?></th><th><?= t('per_insurance_number') ?></th><th><?= t('حق بیمه') ?></th><th>📎 مدارک</th></tr></thead>
        <tbody>
            <?php foreach ($contracts as $c): 
                $remaining = '-';
                $alert = false;
                if ($c['contract_end']) {
                    $diff = (strtotime($c['contract_end']) - strtotime(date('Y-m-d'))) / 86400;
                    if ($diff < 0) {
                        $remaining = t('منقضی شده');
                        $alert = true;
                    } elseif ($diff < 30) {
                        $remaining = round($diff) . ' ' . t('روز');
                        $alert = true;
                    } else {
                        $remaining = round($diff) . ' ' . t('روز');
                    }
                }
            ?>
            <tr style="<?= $alert ? 'background:rgba(239,68,68,0.05)' : '' ?>">
                <td><strong><?= $c['full_name'] ?></strong></td>
                <td><?= htmlspecialchars(($cat_names[$c['staff_category'] ?? ''] ?? '—') . (($c['position'] ?? '') !== '' ? ' · ' . $c['position'] : '')) ?></td>
                <td><?= $c['contract_type'] ?: '-' ?></td>
                <td><?= $c['contract_start'] ? to_jalali($c['contract_start']) : '-' ?></td>
                <td><?= $c['contract_end'] ? to_jalali($c['contract_end']) : '-' ?></td>
                <td><span class="tag <?= $alert ? 'tag-rejected' : 'tag-active' ?>"><?= $remaining ?></span></td>
                <td><?= $c['insurance_number'] ?: '-' ?></td>
                <td><?= number_format($c['insurance_amount']) ?> <?= t('ت') ?></td>
                <td><?php $__dc = $doc_map[(int)$c['id']] ?? 0; ?>
                    <?php if ($__dc > 0): ?>
                        <a href="index.php?page=personnel" class="tag tag-active" style="text-decoration:none" title="مشاهده در صفحهٔ پرسنل (دکمهٔ 📎 در ردیف شخص)">📎 <?= $__dc ?> مدرک</a>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:11px">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php include 'includes/footer.php'; ?>
