import importlib.util
import json
import plistlib
import struct
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('prepare_bundle', Path(__file__).parents[1] / 'prepare-macos-bundle.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class PrepareBundleTests(unittest.TestCase):
    def test_managed_dll_in_macos_is_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            app=Path(d)/'Test.app'; macos=app/'Contents/MacOS';macos.mkdir(parents=True)
            (app/'Contents/Info.plist').write_bytes(plistlib.dumps({'CFBundleExecutable':'main'}))
            (macos/'main').write_bytes(b'host');(macos/'System.dll').write_bytes(b'managed')
            with self.assertRaisesRegex(ValueError,'must move to Resources'):
                module.seal(app)

    def test_removes_only_signature_incompatible_metadata(self):
        with tempfile.TemporaryDirectory() as d:
            app = Path(d) / 'Test.app'; app.mkdir(); (app / 'file').write_bytes(b'content')
            with patch.object(module.subprocess, 'run') as run:
                run.return_value.stdout = 'com.apple.FinderInfo\ncom.apple.ResourceFork\ncom.apple.quarantine\n'
                module.clean_metadata(app)
                removal = [call.args[0] for call in run.call_args_list if '-d' in call.args[0]]
                self.assertEqual(4, len(removal))
                self.assertEqual({'com.apple.FinderInfo','com.apple.ResourceFork'}, {args[2] for args in removal})

    def test_helper_hashes_are_updated_after_nested_signing(self):
        with tempfile.TemporaryDirectory() as d:
            app = Path(d) / 'Test.app'; contents = app / 'Contents'
            helpers = contents / 'Resources/bin'; helpers.mkdir(parents=True)
            (contents / 'MacOS').mkdir()
            main = contents / 'MacOS/LeikwanBridge.Mac'
            helper = helpers / 'ultra-client'
            for p in (main, helper): p.write_bytes(struct.pack('<II', 0xfeedfacf, 0x100000c) + bytes(24))
            (contents / 'Info.plist').write_bytes(plistlib.dumps({'CFBundleExecutable':'LeikwanBridge.Mac'}))
            manifest = contents / 'Resources/helper-manifest.json'
            manifest.write_text(json.dumps({'architecture':'arm64','helpers':[{'name':'ultra-client','path':'bin/ultra-client','sha256':'old','size_bytes':1}]}))
            commands=[]
            def run(args, **kwargs):
                commands.append(args)
                if '--sign' in args and Path(args[-1]).is_file():
                    p=Path(args[-1]);p.write_bytes(p.read_bytes()+b'signed')
                if args[-1]==str(app) and '--sign' in args:
                    self.assertEqual(module.digest(helper),json.loads(manifest.read_text())['helpers'][0]['sha256'])
            with patch.object(module,'clean_metadata'), patch.object(module.subprocess,'run',side_effect=run):
                module.seal(app)
            self.assertTrue(any('--verify' in command for command in commands))
            self.assertFalse(any('--deep' in command and '--sign' in command for command in commands))


if __name__ == '__main__': unittest.main()
