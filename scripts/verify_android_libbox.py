"""Fail closed on missing gomobile classes, mixed ABI bindings, or APK overrides.

This is a static packaging gate, not a replacement for real JNI/TUN tests.
"""
import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import struct
import zipfile


def entry(archive, name):
    if archive.namelist().count(name) != 1:
        raise ValueError('expected exactly one entry: ' + name)
    return archive.read(name)


def validate_bindings(classes, native):
    refs = set(v.decode('ascii') for v in re.findall(
        rb'io/nekohasekai/libbox/[A-Za-z_$][A-Za-z0-9_$]*(?=\x00)', native))
    if 'io/nekohasekai/libbox/CommandServer' not in refs:
        raise ValueError('native libbox CommandServer binding missing')
    missing = refs - classes
    if missing:
        raise ValueError('missing Java bindings: ' + ', '.join(sorted(missing)))
    return refs


def verify(aar, apk=None):
    result = {'aar_sha256': hashlib.sha256(Path(aar).read_bytes()).hexdigest(), 'abis': {}}
    with zipfile.ZipFile(aar) as archive:
        jar = entry(archive, 'classes.jar')
        with zipfile.ZipFile(io.BytesIO(jar)) as java:
            classes = {n[:-6] for n in java.namelist() if n.endswith('.class')}
        result['classes_sha256'] = hashlib.sha256(jar).hexdigest()
        previous_refs = None
        for abi, machine in (('arm64-v8a', 183), ('x86_64', 62)):
            native = entry(archive, f'jni/{abi}/libbox.so')
            if len(native) < 20 or native[:6] != b'\x7fELF\x02\x01' or struct.unpack_from('<H', native, 18)[0] != machine:
                raise ValueError('invalid libbox ELF architecture: ' + abi)
            refs = validate_bindings(classes, native)
            if previous_refs is not None and refs != previous_refs:
                raise ValueError('ABI Java binding inventories differ')
            previous_refs = refs
            if apk:
                with zipfile.ZipFile(apk) as app:
                    if entry(app, f'lib/{abi}/libbox.so') != native:
                        raise ValueError('APK libbox does not match AAR: ' + abi)
            result['abis'][abi] = {'sha256': hashlib.sha256(native).hexdigest(),
                                   'size_bytes': len(native), 'java_references': len(refs)}
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--aar', required=True)
    parser.add_argument('--apk')
    args = parser.parse_args()
    print(json.dumps(verify(args.aar, args.apk), indent=2))
