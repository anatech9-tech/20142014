<?php
/**
 * فروشگاه — API نرخ دلار (JSON عمومی)
 */
declare(strict_types=1);

if (!defined('BASE_PATH')) require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Settings.php';
require_once BASE_PATH . '/includes/shop/Rates.php';
require_once BASE_PATH . '/includes/shop/Price.php';

use Shop\Rates, Shop\Price;

header('Content-Type: application/json; charset=utf-8');

$rate = Rates::getUsd();
echo json_encode([
    'usd_toman'      => $rate,
    'usd_toman_fa'   => Price::faNumber($rate),
    'updated_at'     => date('Y-m-d H:i:s'),
    'date_fa'        => \Shop\Jalali::dateTime(date('Y-m-d H:i:s')),
], JSON_UNESCAPED_UNICODE);
