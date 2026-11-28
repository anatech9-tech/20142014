#!/usr/bin/env python3
"""Exercise opaque backup checksum inspection using synthetic files only."""
import hashlib
import importlib.util
import json
import os
import pathlib
import subprocess
import sys
import tempfile

root = pathlib.Path(__file__).resolve().parents[2]
script = root / 'tools/inspect_backup_pair.py'
with tempfile.TemporaryDirectory() as tmp:
    d = pathlib.Path(tmp)
    site = d/'site.sql'; crm = d/'crm.sql.gz'; secret = b'SYNTHETIC PRIVATE CONTENT'
    site.write_bytes(b'CREATE TABLE synthetic;\n'+secret); crm.write_bytes(b'opaque compressed bytes')
    def run(*args):
        return subprocess.run([sys.executable,str(script),'--site-db',str(site),'--crm-db',str(crm),*args],capture_output=True,text=True)
    result=run(); assert result.returncode==0 and secret.decode() not in result.stdout+result.stderr and str(d) not in result.stdout+result.stderr
    info=json.loads(result.stdout); assert info['site']['sha256']==hashlib.sha256(site.read_bytes()).hexdigest() and info['crm']['size_bytes']==crm.stat().st_size and info['matches_expected'] is None and not info['identical_bytes']
    a,b=info['site']['sha256'],info['crm']['sha256']
    assert json.loads(run('--expect-site-sha256',a,'--expect-crm-sha256',b).stdout)['matches_expected'] is True
    bad=run('--expect-site-sha256','0'*64,'--expect-crm-sha256',b); assert bad.returncode==1 and not bad.stdout and str(d) not in bad.stderr
    for args in (('--expect-site-sha256',a),('--expect-site-sha256','','--expect-crm-sha256',b),('--expect-site-sha256',a.upper(),'--expect-crm-sha256',b)):
        assert run(*args).returncode==2
    crm.write_bytes(site.read_bytes()); assert json.loads(run().stdout)['identical_bytes'] is True
    crm.unlink(); os.link(site,crm); assert run().returncode==1
    crm.unlink(); crm.symlink_to(site); assert run().returncode==1
    crm.unlink(); crm.write_bytes(b''); assert run().returncode==1
    crm.unlink(); os.mkfifo(crm); assert run().returncode==1
    crm.unlink(); crm.mkdir(); assert run().returncode==1
    # Mutate the file as the first block is read: the before/after stat must detect it.
    spec=importlib.util.spec_from_file_location('backup_inspector',script); module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    target=d/'changed.sql'; target.write_bytes(b'x'*(1024*1024+100)); real_read=module.os.read; changed=[False]
    def read_and_change(fd,amount):
        data=real_read(fd,amount)
        if not changed[0]:
            changed[0]=True
            with target.open('ab') as out: out.write(b'extra')
        return data
    module.os.read=read_and_change
    try:
        try: module.inspect_file(target); raise AssertionError('modified input accepted')
        except module.InspectionError: pass
    finally: module.os.read=real_read
    print('PASS opaque backup pair hashes, redaction, mismatch, symlink, hardlink, FIFO, empty and mutation checks')
