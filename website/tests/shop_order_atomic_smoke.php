<?php
/** Tests real shop order methods against SQLite; this is not a MySQL concurrency test. */
namespace Shop {
    class Rates { public static int $calls=0; public static function getUsd(?int $ttl=null): int { self::$calls++; return 1000; } }
    class Customers { public static function user(): ?array { return null; } }
    class Jalali { public static function toJalali($y,$m,$d): array { return ['jy'=>1405,'jm'=>1,'jd'=>1]; } public static function toPersianDigits($x) { return $x; } }
}
namespace {
    class TestPDO extends PDO {
        public function prepare(string $query, array $options=[]): PDOStatement|false {
            return parent::prepare(str_replace([' FOR UPDATE','GREATEST('],['','MAX('],$query),$options);
        }
    }
    class DB {
        public static PDO $connection;
        public static function pdo(): PDO { return self::$connection; }
        public static function fetch(string $sql,array $params=[]): ?array { $s=self::pdo()->prepare($sql); $s->execute($params); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
        public static function fetchAll(string $sql,array $params=[]): array { $s=self::pdo()->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
        public static function execute(string $sql,array $params=[]): int { $s=self::pdo()->prepare($sql); $s->execute($params); return $s->rowCount(); }
        public static function insert(string $table,array $data): int { $cols=array_keys($data); $sql='INSERT INTO '.$table.' ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')'; self::execute($sql,array_values($data)); return (int)self::pdo()->lastInsertId(); }
    }
    class Settings { public static function get($key,$default='') { return $key==='shop_shipping_cost' ? 50 : $default; } }
    require_once __DIR__.'/../includes/shop/Price.php';
    require_once __DIR__.'/../includes/shop/Products.php';
    require_once __DIR__.'/../includes/shop/Coupons.php';
    require_once __DIR__.'/../includes/shop/Cart.php';
    require_once __DIR__.'/../includes/shop/Orders.php';
    function check($x,$label) { if (!$x) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
    session_start();
    $pdo=new TestPDO('sqlite::memory:'); DB::$connection=$pdo; $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    foreach ([
        'CREATE TABLE products(id INTEGER PRIMARY KEY,name_fa TEXT,images TEXT,currency TEXT,price_toman INTEGER,price_usd REAL,stock INTEGER,sold_count INTEGER,is_active INTEGER)',
        'CREATE TABLE discount_codes(id INTEGER PRIMARY KEY,code TEXT UNIQUE,is_active INTEGER,expires_at TEXT,max_uses INTEGER,used_count INTEGER,min_amount INTEGER,type TEXT,value REAL)',
        'CREATE TABLE orders(id INTEGER PRIMARY KEY AUTOINCREMENT,order_no TEXT UNIQUE,customer_id INTEGER,customer_name TEXT,customer_phone TEXT,customer_email TEXT,province TEXT,city TEXT,postal_code TEXT,address TEXT,notes TEXT,subtotal_toman INTEGER,shipping_fee INTEGER,discount_toman INTEGER,coupon_code TEXT,total_toman INTEGER,usd_rate INTEGER,payment_status TEXT,order_status TEXT,status_note TEXT,success_token TEXT)',
        'CREATE TABLE order_items(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,product_id INTEGER,product_name TEXT,product_image TEXT,price_toman INTEGER,qty INTEGER,total_toman INTEGER)',
        'CREATE TABLE order_events(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,status TEXT,note TEXT)'
    ] as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT INTO products VALUES (1,'one','[]','toman',100,NULL,3,0,1),(2,'two','[]','usd',NULL,2,2,0,1),(3,'out','[]','toman',20,NULL,0,0,1)");
    $pdo->exec("INSERT INTO discount_codes VALUES (1,'ONE',1,NULL,1,0,0,'percent',10)");
    $input=['name'=>'Test User','phone'=>'09121234567','email'=>'test@example.com','address'=>'a sufficiently long address','expected_total'=>'2030'];
    $_SESSION['shop_cart']=[3=>1];
    $r=\Shop\Orders::create($input); check(!$r['success'] && (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0,'zero stock rejected');
    $_SESSION['shop_cart']=[1=>4]; $r=\Shop\Orders::create($input); check(!$r['success'],'requested quantity is not clamped');
    $_SESSION['shop_cart']=[1=>1,999=>1]; $r=\Shop\Orders::create($input); check(!$r['success'],'missing product rejected');
    $_SESSION['shop_cart']=[1=>2,2=>1]; $_SESSION['shop_cart_coupon']='ONE';
    $wrong=$input; $wrong['expected_total']='1'; $r=\Shop\Orders::create($wrong);
    check(!$r['success'] && (int)$pdo->query('SELECT used_count FROM discount_codes')->fetchColumn()===0, 'changed total rejected and coupon rolled back');
    \Shop\Rates::$calls=0;
    $summary=\Shop\Cart::summary();
    check($summary['subtotal']===2200 && $summary['discount']===220 && $summary['total']===2030 && \Shop\Rates::$calls===1,'cart summary uses one item snapshot and one rate');
    \Shop\Rates::$calls=0;
    $r=\Shop\Orders::create($input); check($r['success'],'checkout succeeds');
    $o=$pdo->query('SELECT * FROM orders')->fetch(PDO::FETCH_ASSOC);
    check((int)$o['subtotal_toman']===2200 && (int)$o['discount_toman']===220 && (int)$o['total_toman']===2030 && (int)$o['usd_rate']===1000 && \Shop\Rates::$calls===1,'single rate and consistent totals');
    check((int)$pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn()===1 && (int)$pdo->query('SELECT used_count FROM discount_codes')->fetchColumn()===1,'stock and coupon reserved');
    $_SESSION['shop_cart']=[1=>1]; $_SESSION['shop_cart_coupon']='ONE'; $r=\Shop\Orders::create($input);
    check(!$r['success'] && (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===1 && (int)$pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn()===1,'exhausted coupon rolls back');
    unset($_SESSION['shop_cart_coupon']); $pdo->exec("CREATE TRIGGER fail_event BEFORE INSERT ON order_events BEGIN SELECT RAISE(ABORT,'failed event'); END");
    $attempt=$input; $attempt['expected_total']='150';
    $r=\Shop\Orders::create($attempt); check(!$r['success'] && ($r['error'] ?? '')==='خطا در ثبت سفارش. لطفاً دوباره تلاش کنید.' && (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===1 && (int)$pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn()===1,'event failure rolls back order and stock');
    $pdo->exec('DROP TRIGGER fail_event'); $id=(int)$o['id'];
    $r=\Shop\Orders::update($id,null,'paid','', 'pending','pending'); check($r['success'],'payment update');
    $r=\Shop\Orders::update($id,'cancelled',null,'cancel','pending','pending'); check(!$r['success'],'stale update rejected');
    $pdo->exec("CREATE TRIGGER fail_cancel BEFORE INSERT ON order_events BEGIN SELECT RAISE(ABORT,'failed event'); END");
    $r=\Shop\Orders::update($id,'cancelled',null,'cancel','pending','paid');
    check(!$r['success'] && (int)$pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn()===1 && $pdo->query('SELECT order_status FROM orders')->fetchColumn()==='pending','cancel event failure rolls back restock');
    $pdo->exec('DROP TRIGGER fail_cancel');
    $r=\Shop\Orders::update($id,'cancelled',null,'cancel','pending','paid'); check($r['success'],'cancel succeeds');
    $r=\Shop\Orders::update($id,'cancelled',null,'again','cancelled','paid');
    $o=$pdo->query('SELECT * FROM orders')->fetch(PDO::FETCH_ASSOC);
    check($r['success'] && (int)$pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn()===3 && (int)$pdo->query('SELECT stock FROM products WHERE id=2')->fetchColumn()===2 && $o['payment_status']==='paid','repeat cancellation does not restock or reset payment');
    check(!\Shop\Orders::update($id,'pending',null)['success'],'cancelled order cannot reopen');
    $pdo->exec("UPDATE orders SET order_status='shipped' WHERE id=$id");
    check(!\Shop\Orders::update($id,'cancelled',null)['success'],'shipped cancellation requires manual return');
}
