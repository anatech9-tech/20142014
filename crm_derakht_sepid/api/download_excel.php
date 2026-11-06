<?php
require_once __DIR__ . '/../config.php';
require_login();
require_once __DIR__ . '/../includes/excel_generator.php';

$type = $_GET['type'] ?? '';
$ws_id = $_SESSION['workshop_id'] ?? 0;

try {
    switch ($type) {
        case 'employee_payment':
            $year = (int)($_GET['year'] ?? 0);
            $month = (int)($_GET['month'] ?? 0);
            if (!$year || !$month) {
                $j = gregorian_to_jalali(date('Y'), date('m'), date('d'));
                $year = $j[0]; $month = $j[1];
            }
            generate_employee_payment_csv($pdo, $ws_id, $year, $month);
            break;

        case 'worker_payment':
            $year = (int)($_GET['year'] ?? 0);
            $month = (int)($_GET['month'] ?? 0);
            if (!$year || !$month) {
                $j = gregorian_to_jalali(date('Y'), date('m'), date('d'));
                $year = $j[0]; $month = $j[1];
            }
            generate_worker_payment_csv($pdo, $ws_id, $year, $month);
            break;

        default:
            echo t('نوع فرم نامعتبر');
    }
} catch (\Exception $e) {
    echo t('خطا') . ': ' . $e->getMessage();
}
