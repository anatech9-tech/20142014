#!/usr/bin/env python3
"""Template smoke test with isolated fixtures; not a substitute for MySQL/browser/host testing.
Run with PHP_BIN=/path/to/php python3 website/tests/storefront_smoke.py
"""
import os, pathlib, shutil, subprocess, tempfile
root = pathlib.Path(__file__).resolve().parents[1]
php = os.environ.get('PHP_BIN', 'php')
with tempfile.TemporaryDirectory() as tmp:
    app = pathlib.Path(tmp)
    for directory in ('shop', 'includes/shop'):
        (app / directory).mkdir(parents=True, exist_ok=True)
    for name in ('index.php', '_header.php', '_footer.php'):
        shutil.copy(root / 'shop' / name, app / 'shop' / name)
    (app / 'config.php').write_text('''<?php
define('BASE_PATH', __DIR__); define('SITE_NAME','Test Site'); define('SITE_NAME_EN','Test Site');
class Auth { static function csrfToken(){return 'test-token';} }
class Settings { static function get($k,$default=null){return $default;} }
''')
    (app / 'includes/db.php').write_text('<?php\n')
    (app / 'includes/Settings.php').write_text('<?php\n')
    (app / 'includes/shop/Jalali.php').write_text('''<?php namespace Shop; class Jalali { static function toPersianDigits($n){return (string)$n;} }''')
    (app / 'includes/shop/Rates.php').write_text('''<?php namespace Shop; class Rates { static function getUsd(){return 10;} }''')
    (app / 'includes/shop/Price.php').write_text('''<?php namespace Shop; class Price { static function faNumber($n){return (string)$n;} }''')
    (app / 'includes/shop/Cart.php').write_text('''<?php namespace Shop; class Cart { static function count(){return 0;} }''')
    (app / 'includes/shop/Customers.php').write_text('''<?php namespace Shop; class Customers { static function user(){return null;} }''')
    (app / 'includes/shop/Products.php').write_text('''<?php namespace Shop;
class Products {
 static function categories(){return [['slug'=>'cat & one','name_fa'=>'Category <test>','icon'=>'X','product_count'=>2]];}
 static function list($filters,$page,$perPage){
  if (($filters['q'] ?? '')==='empty') return ['items'=>[], 'total'=>0, 'page'=>$page, 'pages'=>1];
  return ['items'=>[
   ['id'=>7,'name_fa'=>'Item <script>','slug'=>'product & one','category_name'=>'Category <test>','first_image'=>'/assets/shop/product-placeholder.svg','display_price'=>100,'old_price_toman'=>150,'stock'=>5,'created_at'=>'2020-01-01'],
   ['id'=>8,'name_fa'=>'Unavailable','slug'=>'sold','category_name'=>'','first_image'=>'/assets/shop/product-placeholder.svg','display_price'=>200,'stock'=>0,'created_at'=>'2020-01-01'],
   ['id'=>9,'name_fa'=>'Inquiry','slug'=>'inquiry','category_name'=>'','first_image'=>'/assets/shop/product-placeholder.svg','display_price'=>0,'stock'=>5,'created_at'=>'2020-01-01']
  ], 'total'=>30,'page'=>$page,'pages'=>3];
 }
}''')
    (app / 'run.php').write_text('''<?php parse_str($argv[1] ?? '', $_GET); require __DIR__.'/shop/index.php';''')
    def render(query=''):
        run = subprocess.run([php, str(app / 'run.php'), query], cwd=app, capture_output=True, text=True, check=True)
        assert 'Fatal error' not in run.stdout + run.stderr, run.stdout + run.stderr
        return run.stdout
    home = render()
    assert 'market-hero' in home and 'shop-home.css' in home
    assert 'Item &lt;script&gt;' in home and 'Item <script>' not in home
    assert 'slug=product+%26+one' in home and 'category=cat+%26+one' in home
    assert 'data-add-cart="7"' in home and 'data-add-cart="8"' not in home and 'data-add-cart="9"' not in home
    assert 'استعلام قیمت' in home and 'product-placeholder.svg' in home
    filtered = render('q=%3Cscript%3E&instock=1&price_min=50&page=2')
    assert 'market-hero' not in filtered and '&lt;script&gt;' in filtered and '<script>' not in filtered
    assert 'instock=1' in filtered and 'price_min=50' in filtered and 'page=3' in filtered
    assert 'checked' in filtered
    empty = render('q=empty')
    assert 'محصولی پیدا نشد' in empty and 'data-add-cart=' not in empty
    print('PASS storefront fixture rendering, escaping, encoded URLs, stock, pagination and empty state')
