#!/usr/bin/env python3
"""Build component ZIPs exclusively from a Git commit (never from runtime files)."""
import argparse
import io
import pathlib
import subprocess
import tarfile
import zipfile

parser = argparse.ArgumentParser()
parser.add_argument('--ref', default='HEAD')
parser.add_argument('--output', default='deploy-packages')
args = parser.parse_args()
sha = subprocess.check_output(['git', 'rev-parse', '--verify', args.ref + '^{commit}'], text=True).strip()
archive = subprocess.check_output(['git', 'archive', '--format=tar', sha, 'website', 'crm_derakht_sepid'])
out = pathlib.Path(args.output)
out.mkdir(parents=True, exist_ok=True)
packages = {'crm.zip': [], 'website.zip': [], 'shop.zip': []}
shop_dirs = ('shop/', 'cart/', 'checkout/', 'account/', 'includes/shop/', 'assets/shop/')
shop_shared = {'css/shop-home.css','cli/shop_crm_sync.php','cli/shop_catalog_sync.php','cli/shop_review_meta_sync.php','includes/db.php','includes/Auth.php','includes/Settings.php','admin/includes/layout.php','admin/api/upload.php','admin/assets/css/admin.css','admin/assets/js/admin.js','css/shop.css','js/shop.js'}
with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
    for f in tar.getmembers():
        if not f.isfile(): continue
        name = f.name
        if name.startswith('crm_derakht_sepid/'):
            rel = name[len('crm_derakht_sepid/'):]
            if rel.startswith(('tests/', 'docs/')) or rel in ('install_db.php','cleanup_users.php','INSTALL_FA.txt'): continue
            if rel.startswith('backups/') and rel != 'backups/.htaccess': continue
            packages['crm.zip'].append((rel,tar.extractfile(f).read()))
        elif name.startswith('website/'):
            rel = name[len('website/'):]
            if rel in ('config.php','config.example.php','config.local.php','.htaccess','SHOP-README.md') or rel.endswith('.sql'): continue
            if rel.startswith(('uploads/','logs/','tests/')): continue
            is_shop = rel.startswith(shop_dirs) or rel.startswith(('admin/shop-', 'admin/api/shop-'))
            data = tar.extractfile(f).read()
            if not is_shop or rel.startswith(('includes/shop/', 'assets/shop/')):
                packages['website.zip'].append((rel,data))
            if is_shop or rel in shop_shared:
                packages['shop.zip'].append((rel,data))
for filename, entries in packages.items():
    target = '/CRM/' if filename == 'crm.zip' else '/public_html/'
    info = (f'Git commit: {sha}\nTarget: {target}\n'
            'Runtime uploads, backups, secrets, site config and schema are deliberately omitted.\n'
            'Back up first. Site/shop share files. Verify staging and compatibility before deployment.\n'
            'Shop and website are not yet approved for production; see docs/WEBSITE_HANDOFF.md.\n')
    with zipfile.ZipFile(out / filename,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
        z.writestr('PACKAGE-INFO.txt',info)
        for rel, data in entries:
            if rel.startswith('/') or '..' in pathlib.PurePosixPath(rel).parts: raise ValueError(rel)
            z.writestr(rel,data)
    print(filename, len(entries), 'files', sha)
