#!/usr/bin/env python3
"""Offline package integrity check. Does not inspect the host or approve deployment."""
import argparse
import pathlib
import re
import stat
import subprocess
import sys
import tempfile
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
NAMES = ('crm.zip', 'website.zip', 'shop.zip')
MAX_TOTAL = 256 * 1024 * 1024

class PackageError(Exception):
    pass

def contents(path: pathlib.Path) -> dict[str, bytes]:
    try:
        with zipfile.ZipFile(path) as archive:
            infos = archive.infolist()
            if sum(info.file_size for info in infos) > MAX_TOTAL:
                raise PackageError(f'{path.name}: uncompressed size limit exceeded')
            names = set()
            data = {}
            for info in infos:
                name = info.filename
                parts = pathlib.PurePosixPath(name).parts
                if not name or name.startswith('/') or '\\' in name or any(x in ('.','..') for x in parts) or '\x00' in name or info.is_dir():
                    raise PackageError(f'{path.name}: unsafe path')
                if name in names:
                    raise PackageError(f'{path.name}: duplicate entry {name}')
                if stat.S_ISLNK(info.external_attr >> 16):
                    raise PackageError(f'{path.name}: symlink not permitted')
                names.add(name)
                data[name] = archive.read(info)
            return data
    except (OSError, zipfile.BadZipFile, RuntimeError) as e:
        raise PackageError(f'{path.name}: cannot read ZIP') from e

def policy(name: str, paths: set[str]) -> None:
    for entry in paths:
        p = pathlib.PurePosixPath(entry)
        lower = entry.lower()
        if p.name.lower() in {'config.local.php', 'site-crm.local.php', '.env', '.netrc', '.git-credentials'} or lower.endswith(('.sql','.pem','.key')) or '.git' in p.parts:
            raise PackageError(f'{name}: restricted file {entry}')
        if name != 'crm.zip':
            if entry in {'config.php','config.example.php','.htaccess'} or entry.startswith(('uploads/','logs/','tests/')):
                raise PackageError(f'{name}: runtime/config file {entry}')
        else:
            if entry.startswith(('tests/','docs/')) or (entry.startswith('backups/') and entry!='backups/.htaccess'):
                raise PackageError(f'{name}: runtime/test file {entry}')
            if entry.startswith('uploads/') and p.name not in {'.htaccess','index.html'}:
                raise PackageError(f'{name}: uploaded file {entry}')

def verify(directory: pathlib.Path, sha: str) -> dict[str, int]:
    if not re.fullmatch(r'[a-f0-9]{40}',sha):
        raise PackageError('Expected commit must be a full SHA')
    actual = {name: contents(directory / name) for name in NAMES}
    for name, entries in actual.items():
        policy(name,set(entries))
        info=entries.get('PACKAGE-INFO.txt',b'').decode('utf-8',errors='replace')
        if not info.startswith(f'Git commit: {sha}\n'):
            raise PackageError(f'{name}: incorrect source commit')
    # Rebuild from the exact committed builder, not from the mutable checkout.
    with tempfile.TemporaryDirectory() as tmp:
        script = pathlib.Path(tmp)/'builder.py'
        try:
            script.write_bytes(subprocess.check_output(['git','show',f'{sha}:tools/build_deploy_packages.py'],cwd=ROOT,stderr=subprocess.PIPE))
            subprocess.run([sys.executable,str(script),'--ref',sha,'--output',str(pathlib.Path(tmp)/'expected')],cwd=ROOT,check=True,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
        except subprocess.CalledProcessError as e:
            raise PackageError('Could not rebuild expected packages from commit') from e
        for name in NAMES:
            expected=contents(pathlib.Path(tmp)/'expected'/name)
            if actual[name].keys()!=expected.keys():
                raise PackageError(f'{name}: file list differs from committed build')
            for path, data in expected.items():
                if actual[name][path]!=data:
                    raise PackageError(f'{name}: content differs from committed build: {path}')
    common=set(actual['website.zip']) & set(actual['shop.zip']) - {'PACKAGE-INFO.txt'}
    for name in common:
        if actual['website.zip'][name]!=actual['shop.zip'][name]:
            raise PackageError(f'Shared site/shop file differs: {name}')
    return {name: len(entries) for name,entries in actual.items()}

def main() -> int:
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory',default='packages')
    parser.add_argument('--ref',default='HEAD',help='Code commit used to build packages (not the later package commit)')
    args=parser.parse_args()
    try:
        sha=subprocess.check_output(['git','rev-parse','--verify',args.ref+'^{commit}'],cwd=ROOT,text=True,stderr=subprocess.PIPE).strip()
        result=verify(pathlib.Path(args.directory),sha)
    except (PackageError,subprocess.CalledProcessError) as e:
        print('FAIL:',e,file=sys.stderr); return 1
    for name,count in result.items(): print(f'PASS {name}: {count} files, source {sha}')
    print('Checks only repository artifacts; host configuration, database, restore and production readiness are not verified.')
    return 0
if __name__=='__main__':
    sys.exit(main())
