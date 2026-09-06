import importlib.util
import plistlib
import struct
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('dmg_layout', Path(__file__).parents[1] / 'verify-macos-dmg-layout.py')
layout = importlib.util.module_from_spec(spec)
spec.loader.exec_module(layout)


class DmgLayoutTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.volume = Path(self.temp.name)
        self.app = self.volume / 'LeiKwan Bridge.app'
        contents = self.app / 'Contents'
        (contents / 'MacOS').mkdir(parents=True)
        (contents / 'Info.plist').write_bytes(plistlib.dumps({
            'CFBundleIdentifier': 'com.leikwan.bridge', 'CFBundleExecutable': 'LeikwanBridge.Mac',
            'CFBundlePackageType': 'APPL', 'CFBundleShortVersionString': '1.0.9', 'CFBundleVersion': '1.0.9',
        }))
        executable = contents / 'MacOS/LeikwanBridge.Mac'
        executable.write_bytes(struct.pack('<II', 0xfeedfacf, 0x100000c) + bytes(24))
        executable.chmod(0o755)

    def test_missing_applications_shortcut_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'Applications'):
            layout.validate(self.volume, '1.0.9', 'arm64')

    def test_real_directory_is_not_an_applications_shortcut(self):
        (self.volume / 'Applications').mkdir()
        with self.assertRaisesRegex(ValueError, 'Applications'):
            layout.validate(self.volume, '1.0.9', 'arm64')

    def test_correct_install_surface_passes(self):
        (self.volume / 'Applications').symlink_to('/Applications', target_is_directory=True)
        self.assertEqual('1.0.9', layout.validate(self.volume, '1.0.9', 'arm64')['version'])

    def test_wrong_link_target_is_rejected(self):
        (self.volume / 'Applications').symlink_to('/tmp', target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'target'):
            layout.validate(self.volume, '1.0.9', 'arm64')

    def test_nested_app_is_rejected(self):
        (self.volume / 'Applications').symlink_to('/Applications', target_is_directory=True)
        nested = self.volume / 'nested'; nested.mkdir(); self.app.rename(nested / self.app.name)
        with self.assertRaisesRegex(ValueError, 'top-level'):
            layout.validate(self.volume, '1.0.9', 'arm64')

    def test_wrong_architecture_is_rejected(self):
        (self.volume / 'Applications').symlink_to('/Applications', target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'architecture'):
            layout.validate(self.volume, '1.0.9', 'x86_64')

    def test_wrong_version_is_rejected(self):
        (self.volume / 'Applications').symlink_to('/Applications', target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'version'):
            layout.validate(self.volume, '1.0.8', 'arm64')

    def test_nonexecutable_apphost_is_rejected(self):
        (self.volume / 'Applications').symlink_to('/Applications', target_is_directory=True)
        (self.app / 'Contents/MacOS/LeikwanBridge.Mac').chmod(0o644)
        with self.assertRaisesRegex(ValueError, 'executable'):
            layout.validate(self.volume, '1.0.9', 'arm64')


if __name__ == '__main__':
    unittest.main()
