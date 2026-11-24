#!/usr/bin/env python3
"""Exercise package verifier against copies only; never modify release artifacts."""
import importlib.util, pathlib, shutil, tempfile, zipfile
root=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('verifier',root/'tools/verify_deploy_packages.py')
verifier=importlib.util.module_from_spec(spec); spec.loader.exec_module(verifier)
with zipfile.ZipFile(root/'packages/crm.zip') as z:
    sha=z.read('PACKAGE-INFO.txt').decode().splitlines()[0].split(': ',1)[1]
def reject(fn,label):
    try: fn()
    except verifier.PackageError: print('PASS',label); return
    raise AssertionError(label)
with tempfile.TemporaryDirectory() as tmp:
    d=pathlib.Path(tmp)
    for n in verifier.NAMES: shutil.copy(root/'packages'/n,d/n)
    assert verifier.verify(d,sha)['crm.zip']>0
    print('PASS authentic packages match committed builder')
    reject(lambda: verifier.verify(d,'0'*40),'wrong commit rejected')
    with zipfile.ZipFile(d/'shop.zip','a') as z: z.writestr('config.local.php',b'NOT A REAL SECRET')
    reject(lambda: verifier.verify(d,sha),'private config rejected')
    shutil.copy(root/'packages/shop.zip',d/'shop.zip')
    with zipfile.ZipFile(d/'shop.zip','a') as z: z.writestr('../escape',b'x')
    reject(lambda: verifier.verify(d,sha),'path traversal rejected')
    shutil.copy(root/'packages/shop.zip',d/'shop.zip')
    with zipfile.ZipFile(d/'shop.zip','a') as z: z.writestr('js/shop.js',b'altered')
    reject(lambda: verifier.verify(d,sha),'duplicate archive entry rejected')
    shutil.copy(root/'packages/shop.zip',d/'shop.zip')
    with zipfile.ZipFile(d/'shop.zip') as z: data={i.filename:z.read(i) for i in z.infolist()}
    data['js/shop.js']=b'altered'
    with zipfile.ZipFile(d/'shop.zip','w',compression=zipfile.ZIP_DEFLATED) as z:
        for n,b in data.items(): z.writestr(n,b)
    reject(lambda: verifier.verify(d,sha),'changed source content rejected')
