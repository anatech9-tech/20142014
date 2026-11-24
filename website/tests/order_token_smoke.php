<?php
/** Test that the success page cannot look up order data without its token. */
class DB {
    public static int $queries = 0;
    public static function fetch(string $sql, array $params = []): ?array {
        self::$queries++;
        return ['id'=>1, 'success_token'=>'test-secret-token'];
    }
    public static function fetchAll(string $sql, array $params = []): array { return []; }
}
require_once __DIR__ . '/../includes/shop/Orders.php';
use Shop\Orders;
if (Orders::byNo('ORDER', '') !== null || DB::$queries !== 0) { fwrite(STDERR,"FAIL missing token\n"); exit(1); }
echo "PASS missing token rejected before lookup\n";
if (Orders::byNo('ORDER', 'wrong') !== null) { fwrite(STDERR,"FAIL invalid token\n"); exit(1); }
echo "PASS invalid token rejected\n";
$order = Orders::byNo('ORDER', 'test-secret-token');
if (!$order || $order['id'] !== 1) { fwrite(STDERR,"FAIL valid token\n"); exit(1); }
echo "PASS valid token accepted\n";
