<?php
$pageTitle = t('nav_contracts');
$ws_id = $_SESSION['workshop_id'] ?? 0;

$stmt = $pdo->prepare("SELECT u.id, u.full_name, wp.contract_start, wp.contract_end, wp.contract_type, wp.insurance_number, wp.insurance_amount FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY wp.contract_end ASC");
$stmt->execute([$ws_id]);
$contracts = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('nav_contracts') ?></h2>
</div>

<div class="card animate-in">
    <div class="table-container"><table>
        <thead><tr><th><?= t('wh_name') ?></th><th><?= t('per_contract_type') ?></th><th><?= t('wh_start') ?></th><th><?= t('wh_end') ?></th><th><?= t('باقیمانده') ?></th><th><?= t('per_insurance_number') ?></th><th><?= t('حق بیمه') ?></th></tr></thead>
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
                <td><?= $c['contract_type'] ?: '-' ?></td>
                <td><?= $c['contract_start'] ? to_jalali($c['contract_start']) : '-' ?></td>
                <td><?= $c['contract_end'] ? to_jalali($c['contract_end']) : '-' ?></td>
                <td><span class="tag <?= $alert ? 'tag-rejected' : 'tag-active' ?>"><?= $remaining ?></span></td>
                <td><?= $c['insurance_number'] ?: '-' ?></td>
                <td><?= number_format($c['insurance_amount']) ?> <?= t('ت') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php include 'includes/footer.php'; ?>
