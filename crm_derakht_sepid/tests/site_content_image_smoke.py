#!/usr/bin/env python3
"""Image path bridge with synthetic SQLite/temp files; no live HTTP, MySQL or host."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[2];php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as temp:
    d=pathlib.Path(temp)
    for folder in ('website/includes/content','crm/includes','website/assets/icons','website/assets/team','website/uploads/team'): (d/folder).mkdir(parents=True)
    for name in ('ContentCommandContract.php','ContentCommandReceiver.php','ContentLookupContract.php','ContentLookup.php'): shutil.copy(root/'website/includes/content'/name,d/'website/includes/content'/name)
    shutil.copy(root/'website/includes/ContentEditGuard.php',d/'website/includes/ContentEditGuard.php')
    for name in ('site_content_commands.php','site_content_lookup.php'): shutil.copy(root/'crm_derakht_sepid/includes'/name,d/'crm/includes'/name)
    for crm_name,site_name in (('site_content_command_contract.php','ContentCommandContract.php'),('site_content_lookup_contract.php','ContentLookupContract.php')):
        assert (root/'crm_derakht_sepid/includes'/crm_name).read_bytes()==(d/'website/includes/content'/site_name).read_bytes()
        (d/'crm/includes'/crm_name).symlink_to(d/'website/includes/content'/site_name)
    png=root/'website/assets/icons/icons8-around-the-globe-100.png'
    shutil.copy(png,d/'outside.png')
    for directory in ('website/assets/icons','website/assets/team'):
        folder=d/directory;shutil.copy(png,folder/'valid.png');shutil.copy(png,folder/'wrong.jpg');shutil.copy(root/'website/assets/team/6.webp',folder/'valid.webp');shutil.copy(root/'website/assets/shop/editorial-hero.jpg',folder/'valid.jpg');(folder/'valid.gif').write_bytes(bytes.fromhex('47494638396101000100800000ffffff00000021f90401000000002c00000000010001000002024401003b')); huge_dimensions=bytearray(png.read_bytes());huge_dimensions[16:20]=(4097).to_bytes(4,'big');(folder/'wide.png').write_bytes(huge_dimensions);(folder/'bad.png').write_bytes(b'not an image');(folder/'huge.png').write_bytes(b'a'*(5*1024*1024+1));(folder/'symlink.png').symlink_to(d/'outside.png');(folder/'linkdir').symlink_to(folder,target_is_directory=True)
    shutil.copy(png,d/'website/uploads/team/valid.png')
    env=os.environ.copy();env['CONTENT_IMAGE_FIXTURE_ROOT']=str(d)
    for kind in ('service','team'):
        for case in ('enabled','child_off','parent_off'):
            r=subprocess.run([php,str(root/'crm_derakht_sepid/tests/site_content_image_fixture.php'),kind,case],env=env,capture_output=True,text=True,check=True)
            assert not any(x in r.stdout+r.stderr for x in ('FAIL ','Fatal error','Warning:','Uncaught','Parse error')) and 'PASS' in r.stdout,r.stdout+r.stderr
            print(r.stdout,end='')
for kind in ('SERVICE','TEAM'):
    for file,func,parent,child in (
        (root/'website/includes/content/ContentCommandContract.php','content_command_kind_enabled',f'SITE_CONTENT_{kind}_EDIT_ENABLED',f'SITE_CONTENT_{kind}_IMAGE_EDIT_ENABLED'),
        (root/'website/includes/content/ContentLookupContract.php','content_lookup_kind_enabled',f'SITE_CONTENT_{kind}_LOOKUP_ENABLED',f'SITE_CONTENT_{kind}_IMAGE_LOOKUP_ENABLED'),
    ):
        for declarations,expected in (('','0'),(f"define('{parent}',true);",'0'),(f"define('{child}',true);",'0'),(f"define('{parent}',true);define('{child}',true);",'1')):
            r=subprocess.run([php,'-r',declarations+f"require {str(file)!r}; echo (int){func}('{kind.lower()}_image');"],text=True,capture_output=True,check=True)
            assert r.stdout==expected and not r.stderr,(r.stdout,r.stderr)
print('PASS image flags default off and require respective parent and child')
