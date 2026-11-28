#!/usr/bin/env python3
"""Render legacy team and services pages with synthetic rows, not a browser or DB."""
import html
import os
import pathlib
import re
import shutil
import subprocess
import tempfile

root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for name in ('admin/includes','includes'): (app/name).mkdir(parents=True)
    for name in ('services','team'): shutil.copy(root/f'admin/{name}.php',app/f'admin/{name}.php')
    shutil.copy(root/'includes/ContentEditGuard.php',app/'includes/ContentEditGuard.php')
    (app/'includes/db.php').write_text('''<?php
class DB { public static function fetchAll($sql):array { if (str_contains($sql,'team_members')) return [['id'=>1,'name'=>'Test','role'=>'Role','handle'=>'','bio'=>'','image_path'=>'/a.png','email'=>'','linkedin_url'=>'','display_order'=>0,'is_active'=>1,'created_at'=>'2026','updated_at'=>'2026']]; return [['id'=>1,'icon_path'=>'','title_fa'=>'Title','title_en'=>'','description_fa'=>'Body','description_en'=>'','display_order'=>0,'is_active'=>1,'created_at'=>'2026','updated_at'=>'2026']]; } }
''')
    (app/'includes/Auth.php').write_text('''<?php
class Auth { public static function requirePermission($p){} public static function can($p){return $GLOBALS['case']!=='viewer';} public static function csrfToken(){return 'csrf-token';} }
''')
    (app/'admin/includes/layout.php').write_text('<?php echo $contentEcho;')
    (app/'run.php').write_text('''<?php
$case=$argv[2]; define('BASE_PATH',__DIR__); include __DIR__.'/admin/'.$argv[1].'.php';
''')
    for kind,fn,prefix in (('team','Team','team'),('services','Srv','srv')):
        def run(case):
            r=subprocess.run([php,str(app/'run.php'),kind,case],capture_output=True,text=True,check=True)
            assert not any(s in r.stdout+r.stderr for s in ('Fatal error','Warning:','Uncaught','Parse error')),r.stdout+r.stderr
            return r.stdout
        page=run('editor')
        assert 'name="expected_token" value=""' in page and f"document.getElementById('{prefix}EditToken').value" in page and "fd.append('expected_token', expectedToken)" in page
        assert re.search(rf"delete{fn}\(1, '[a-f0-9]{{64}}'\)",page)
        assert '__edit_token' in html.unescape(page) and f'delete{fn}(1,' not in run('viewer')
    print('PASS service/team UI fixtures include version tokens for edit and delete and respect view-only buttons')
