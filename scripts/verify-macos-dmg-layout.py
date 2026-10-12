"""Validate a mounted DMG's install surface, independently of trust and launch."""
import argparse
import json
import os
from pathlib import Path
import plistlib
import struct


def validate(volume, version, architecture):
    volume = Path(volume)
    apps = [p for p in volume.iterdir() if p.name.endswith('.app') and p.is_dir() and not p.is_symlink()]
    if len(apps) != 1 or apps[0].name != 'LeiKwan Bridge.app':
        raise ValueError('expected one top-level LeiKwan Bridge.app')
    shortcut = volume / 'Applications'
    if not shortcut.is_symlink():
        raise ValueError('Applications installation shortcut missing')
    if os.readlink(shortcut) != '/Applications':
        raise ValueError('Applications shortcut target must be /Applications')
    contents = apps[0] / 'Contents'
    info = plistlib.loads((contents / 'Info.plist').read_bytes())
    if info.get('CFBundleIdentifier') != 'com.leikwan.bridge' or info.get('CFBundlePackageType') != 'APPL':
        raise ValueError('invalid app bundle identity/type')
    if info.get('CFBundleShortVersionString') != version or info.get('CFBundleVersion') != version:
        raise ValueError('bundle version mismatch')
    name = info.get('CFBundleExecutable')
    if not isinstance(name, str) or not name or Path(name).name != name or name in ('.', '..'):
        raise ValueError('invalid executable name')
    executable = contents / 'MacOS' / name
    if any(p != executable for p in executable.parent.iterdir()):
        raise ValueError('MacOS directory must contain only the native apphost; managed files belong in Resources')
    if executable.is_symlink() or not executable.is_file() or not executable.stat().st_mode & 0o111:
        raise ValueError('apphost missing or not executable')
    with executable.open('rb') as stream:
        header = stream.read(8)
    # The macOS client ships for Apple Silicon only.
    if architecture != 'arm64':
        raise ValueError(f'unsupported architecture {architecture!r}: the macOS client is Apple Silicon (arm64) only')
    expected_cpu = 0x100000c
    if len(header) != 8 or struct.unpack('<II', header) != (0xfeedfacf, expected_cpu):
        raise ValueError('apphost architecture mismatch')
    return {'install_surface_ok': True, 'version': version, 'architecture': architecture,
            'application': apps[0].name, 'applications_target': '/Applications'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('volume', type=Path)
    parser.add_argument('--version', required=True)
    parser.add_argument('--architecture', required=True, choices=('arm64',),
                        help='arm64 only: the macOS client supports Apple Silicon only')
    args = parser.parse_args()
    print(json.dumps(validate(args.volume, args.version, args.architecture)))
