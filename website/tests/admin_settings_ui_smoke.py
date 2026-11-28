#!/usr/bin/env python3
"""Legacy settings HTML fixture. No real HTTP, database, permissions or writes."""
import html.parser
import os
import pathlib
import shutil
import subprocess
import tempfile

root = pathlib.Path(__file__).resolve().parents[1]
php = os.environ.get('PHP_BIN', 'php')
with tempfile.TemporaryDirectory() as tmp:
    app = pathlib.Path(tmp)
    for sub in ('admin/includes', 'includes'):
        (app / sub).mkdir(parents=True)
    shutil.copy(root / 'admin/settings.php', app / 'admin/settings.php')
    (app / 'includes/db.php').write_text('<?php')
    (app / 'includes/Auth.php').write_text('''<?php
class Auth {
 public static function requirePermission($p) { if ($GLOBALS['case']==='denied') { echo 'DENIED'; exit; } }
 public static function csrfToken(){return 'csrf"token';}
 public static function can($p){return $GLOBALS['case']!=='viewer';}
}
''')
    (app / 'includes/Settings.php').write_text('''<?php
class Settings { public static function getByCategory(string $category): array {
 $GLOBALS['observed']=$category;
 return ['evil" autofocus onfocus="alert(1)<svg/onload=alert(1)>' => '\" onfocus=\"bad', 'site_name'=>'normal'];
}}
''')
    (app / 'admin/includes/layout.php').write_text("<?php echo $contentEcho; echo '|CATEGORY:'.htmlspecialchars($GLOBALS['observed'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');")
    (app / 'run.php').write_text('''<?php
$case=$argv[1]; define('BASE_PATH',__DIR__); require __DIR__.'/includes/Settings.php';
$_GET['cat']=$case==='array'?['bad']:($case==='long'?str_repeat('a',51):($case==='injection'?'<svg/onload=alert(2)>':($case==='custom'?'custom':'general')));
include __DIR__.'/admin/settings.php';
''')
    def run(case):
        r = subprocess.run([php, str(app/'run.php'), case], capture_output=True, text=True, check=True)
        assert not any(s in r.stdout+r.stderr for s in ('Fatal error', 'Uncaught', 'Warning:', 'Parse error')), r.stdout+r.stderr
        return r.stdout
    class Inputs(html.parser.HTMLParser):
        def __init__(self):
            super().__init__(); self.inputs=[]
        def handle_starttag(self, tag, attrs):
            if tag == 'input': self.inputs.append(dict(attrs))
    page=run('injection'); assert '<svg/onload=alert(2)>' not in page and '&lt;svg/onload=alert(2)&gt;' in page
    assert '<svg/onload=alert(1)>' not in page and '&lt;svg/onload=alert(1)&gt;' in page
    p=Inputs(); p.feed(page)
    malicious=[i for i in p.inputs if i.get('name','').startswith('settings[evil')]
    assert len(malicious)==1 and malicious[0]['name']=='settings[evil" autofocus onfocus="alert(1)<svg/onload=alert(1)>]' and 'autofocus' not in malicious[0] and 'onfocus' not in malicious[0]
    assert malicious[0]['value']=='" onfocus="bad' and any(i.get('name')=='csrf_token' and i.get('value')=='csrf"token' for i in p.inputs)
    assert '|CATEGORY:general' in run('array') and '|CATEGORY:general' in run('long') and '|CATEGORY:custom' in run('custom')
    assert '💾 ذخیره' in run('editor') and '💾 ذخیره' not in run('viewer') and run('denied')=='DENIED'
    print('PASS legacy settings category and stored key/value escaping, custom categories, viewer and permission fixtures')
