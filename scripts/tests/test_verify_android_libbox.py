import importlib.util
import io
from pathlib import Path
import struct
import tempfile
import unittest
import zipfile

spec = importlib.util.spec_from_file_location('bindings', Path(__file__).parents[1] / 'verify_android_libbox.py')
bindings = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bindings)
PREFIX = 'io/nekohasekai/libbox/'


class LibboxBindingTests(unittest.TestCase):
    def test_current_command_server_api_does_not_require_obsolete_bridge_options(self):
        self.assertEqual({PREFIX + 'CommandServer'}, bindings.validate_bindings(
            {PREFIX + 'CommandServer'}, (PREFIX + 'CommandServer\0').encode()))

    def test_native_reference_to_missing_bridge_options_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'BridgeOptions'):
            bindings.validate_bindings({PREFIX + 'CommandServer'},
                (PREFIX + 'CommandServer\0' + PREFIX + 'BridgeOptions\0').encode())

    def fixture(self, directory, mismatch=False, extra_x86_class=False):
        jar = io.BytesIO()
        with zipfile.ZipFile(jar, 'w') as java:
            for name in ('CommandServer', 'OverrideOptions'):
                java.writestr(PREFIX + name + '.class', b'fixture')
        aar, apk = Path(directory)/'libbox.aar', Path(directory)/'app.apk'
        with zipfile.ZipFile(aar, 'w') as a, zipfile.ZipFile(apk, 'w') as p:
            a.writestr('classes.jar', jar.getvalue())
            for abi, machine in (('arm64-v8a', 183), ('x86_64', 62)):
                elf = bytearray(20); elf[:6] = b'\x7fELF\x02\x01'; struct.pack_into('<H', elf, 18, machine)
                elf += (PREFIX + 'CommandServer\0').encode()
                if extra_x86_class and abi == 'x86_64': elf += (PREFIX + 'OverrideOptions\0').encode()
                a.writestr(f'jni/{abi}/libbox.so', elf)
                p.writestr(f'lib/{abi}/libbox.so', elf + (b'override' if mismatch else b''))
        return aar, apk

    def test_matched_pair_passes(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(2, len(bindings.verify(*self.fixture(d))['abis']))

    def test_apk_native_override_is_rejected(self):
        with tempfile.TemporaryDirectory() as d, self.assertRaisesRegex(ValueError, 'does not match AAR'):
            bindings.verify(*self.fixture(d, mismatch=True))

    def test_abi_binding_inventory_mismatch_is_rejected(self):
        with tempfile.TemporaryDirectory() as d, self.assertRaisesRegex(ValueError, 'inventories differ'):
            bindings.verify(*self.fixture(d, extra_x86_class=True))


if __name__ == '__main__':
    unittest.main()
