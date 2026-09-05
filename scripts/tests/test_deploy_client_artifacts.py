import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('deploy_client_artifacts', Path(__file__).parents[1] / 'deploy-client-artifacts.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


@unittest.skipUnless(os.name == 'posix', 'atomic fsync and ownership integration requires POSIX')
class GuardedDeployTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)/'root'
        self.stage = Path(self.temp.name)/'stage'
        self.backups = Path(self.temp.name)/'backups'
        for p in (self.root,self.stage,self.backups): p.mkdir()
        self.path,route=deploy.CANONICAL['windows_client_app']
        live=self.root/self.path
        live.parent.mkdir(parents=True)
        live.write_bytes(b'old binary')
        upload=self.stage/'setup.exe'; upload.write_bytes(b'new binary')
        entry=lambda path,version:{'version':version,'path':self.path,'artifact_url':route,**{k:v for k,v in deploy.metadata(path).items() if k in ('sha256','size_bytes')}}
        before={'windows_client_app':entry(live,'0.1.20.10'),'macos_client_app_arm64':{'version':'1.0.7'}}
        after={'windows_client_app':entry(upload,'0.1.20.11'),'macos_client_app_arm64':{'version':'1.0.7'}}
        (self.root/deploy.MANIFEST).write_text(json.dumps(before))
        (self.stage/'versions.json').write_text(json.dumps(after))
        preserved=self.root/'master/example.php'; preserved.parent.mkdir(); preserved.write_bytes(b'unchanged')
        self.plan={'release_id':'release-test','git_commit':'a'*40,'versions':{'windows_client_app':'0.1.20.11'},
          'payloads':[{'path':self.path,'upload':'setup.exe','before':deploy.metadata(live),'after':deploy.metadata(upload)}],
          'manifest':{'path':deploy.MANIFEST,'upload':'versions.json','before':deploy.metadata(self.root/deploy.MANIFEST),'after':deploy.metadata(self.stage/'versions.json')},
          'preserved':{'master/example.php':deploy.metadata(preserved)}}

    def run_release(self, health=lambda:{'ok':True}):
        with contextlib.redirect_stdout(io.StringIO()):
            return deploy.publish(self.root,self.stage,self.backups,self.plan,health,min_free=0)

    def test_commit_and_backup_hashes(self):
        result=self.run_release()
        self.assertEqual('committed',result['status'])
        self.assertEqual(b'new binary',(self.root/self.path).read_bytes())
        backup=self.backups/self.plan['release_id']
        self.assertEqual(b'old binary',(backup/self.path).read_bytes())
        self.assertEqual('committed',json.loads((backup/'receipt.json').read_text())['status'])
        self.assertEqual(b'unchanged',(self.root/'master/example.php').read_bytes())

    def test_postflight_failure_restores_payload_and_manifest(self):
        calls=[]
        def health():
            calls.append(True)
            if len(calls)>1: raise RuntimeError('health failed')
            return {'ok':True}
        with self.assertRaisesRegex(RuntimeError,'health failed'):self.run_release(health)
        for e in self.plan['payloads']+[self.plan['manifest']]:deploy.verify(self.root/e['path'],e['before'])
        receipt=json.loads((self.backups/self.plan['release_id']/'receipt.json').read_text())
        self.assertEqual('rolled_back',receipt['status'])

    def test_changed_live_file_refuses_before_any_write(self):
        (self.root/self.path).write_bytes(b'other release')
        with self.assertRaisesRegex(ValueError,'guard mismatch'):self.run_release()
        self.assertFalse(any(self.backups.iterdir()))
        self.assertEqual(b'other release',(self.root/self.path).read_bytes())

    def test_preserved_mac_manifest_entry_cannot_change(self):
        p=self.stage/'versions.json'; m=json.loads(p.read_text());m['macos_client_app_arm64']['version']='1.0.8';p.write_text(json.dumps(m))
        self.plan['manifest']['after']=deploy.metadata(p)
        with self.assertRaisesRegex(ValueError,'unexpected manifest'):self.run_release()

    def test_noncanonical_route_rejected(self):
        p=self.stage/'versions.json'; m=json.loads(p.read_text());m['windows_client_app']['artifact_url']='/wrong';p.write_text(json.dumps(m))
        self.plan['manifest']['after']=deploy.metadata(p)
        with self.assertRaisesRegex(ValueError,'noncanonical'):self.run_release()

    def test_symlink_upload_rejected(self):
        p=self.stage/'setup.exe';p.unlink();p.symlink_to(self.root/self.path)
        with self.assertRaisesRegex(ValueError,'symlink'):self.run_release()

    def test_concurrent_change_is_not_destroyed_during_rollback(self):
        calls=[]
        def health():
            calls.append(True)
            if len(calls)>1:
                (self.root/self.path).write_bytes(b'external newer binary')
                raise RuntimeError('postflight interrupted')
            return {'ok':True}
        with self.assertRaises(RuntimeError):self.run_release(health)
        self.assertEqual(b'external newer binary',(self.root/self.path).read_bytes())
        r=json.loads((self.backups/self.plan['release_id']/'receipt.json').read_text())
        self.assertEqual('rollback_conflict',r['status'])

    def test_corrupt_temporary_payload_never_replaces_live_file(self):
        source=self.stage/'setup.exe'
        source.write_bytes(b'corrupted upload')
        expected=self.plan['payloads'][0]
        with self.assertRaisesRegex(ValueError,'guard mismatch'):
            deploy.atomic_copy(source,self.root/self.path,expected['before'],expected['after'])
        self.assertEqual(b'old binary',(self.root/self.path).read_bytes())
        self.assertFalse(list((self.root/self.path).parent.glob('.leikwan-release-*.tmp')))

    def test_missing_upload_fails_before_backup(self):
        (self.stage/'setup.exe').unlink()
        with self.assertRaises(ValueError):self.run_release()
        self.assertFalse(any(self.backups.iterdir()))


if __name__=='__main__':unittest.main()
