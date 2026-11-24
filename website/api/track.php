<?php
/**
 * Public analytics tracking endpoint
 */

declare(strict_types=1);
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/Analytics.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$type = $input['type'] ?? '';
$valid = ['contact_form', 'phone_click', 'email_click', 'whatsapp_click', 'cta_click'];
if (in_array($type, $valid, true)) {
    Analytics::trackConversion($type, $input['value'] ?? null);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
