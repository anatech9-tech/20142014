<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/Auth.php';
Auth::logout();
$_SESSION['flash_success'] = 'با موفقیت خارج شدید.';
sts_redirect('/admin/login.php');
