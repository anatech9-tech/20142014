<?php
/** Run from cron on the host; never via the web server. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
$result = operations_auto_backups($pdo);
if (empty($result['ok'])) {
    fwrite(STDERR, "Backup failed: " . ($result['reason'] ?? 'unknown') . "\n");
    exit(1);
}
echo "Database backup OK: " . ($result['latest'] ?? 'none') . "\n";
