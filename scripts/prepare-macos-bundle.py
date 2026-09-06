"""Clean build-only metadata and ad-hoc seal a staged app with Apple's codesign.

This provides bundle integrity, not Developer ID trust or notarization.
"""
import argparse
import hashlib
import json
from pathlib import Path
import plistlib
import subprocess
import sys

MACHO = {b'\xcf\xfa\xed\xfe', b'\xce\xfa\xed\xfe', b'\xfe\xed\xfa\xcf',
         b'\xfe\xed\xfa\xce', b'\xca\xfe\xba\xbe', b'\xca\xfe\xba\xbf'}


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def clean_metadata(app):
    for path in [app, *app.rglob('*')]:
        if path.is_symlink():
            continue
        attributes = subprocess.run(['xattr', str(path)], capture_output=True, text=True, check=True).stdout.splitlines()
        for attribute in attributes:
            if attribute in ('com.apple.FinderInfo', 'com.apple.ResourceFork'):
                subprocess.run(['xattr', '-d', attribute, str(path)], check=True)


def seal(app):
    app = Path(app)
    if app.is_symlink() or not app.is_dir() or app.suffix != '.app':
        raise ValueError('expected staged app bundle')
    contents = app / 'Contents'
    info = plistlib.loads((contents / 'Info.plist').read_bytes())
    name = info['CFBundleExecutable']
    if Path(name).name != name or name in ('.', '..'):
        raise ValueError('invalid apphost name')
    main = contents / 'MacOS' / name
    unexpected = [p.name for p in main.parent.iterdir() if p != main]
    if unexpected:
        raise ValueError('non-launcher content in MacOS must move to Resources: ' + ', '.join(unexpected))
    clean_metadata(app)
    for path in sorted(app.rglob('*')):
        if not path.is_file() or path.is_symlink() or path == main:
            continue
        with path.open('rb') as stream:
            magic = stream.read(4)
        if magic in MACHO:
            subprocess.run(['codesign', '--force', '--sign', '-', str(path)], check=True)
    # Signing native helpers changes their bytes. Seal their final hashes before
    # signing the outer bundle, which in turn covers this manifest.
    manifest = contents / 'Resources/helper-manifest.json'
    payload = json.loads(manifest.read_text())
    resources = (contents / 'Resources').resolve()
    for helper in payload['helpers']:
        path = resources / helper['path']
        if path.is_symlink() or resources not in path.resolve().parents or not path.is_file():
            raise ValueError('helper outside bundle or missing')
        helper.update(sha256=digest(path), size_bytes=path.stat().st_size)
    manifest.write_text(json.dumps(payload, indent=2) + '\n', encoding='utf8')
    subprocess.run(['codesign', '--force', '--sign', '-', str(app)], check=True)
    subprocess.run(['codesign', '--verify', '--deep', '--strict', '--verbose=2', str(app)], check=True)
    return {'signature_integrity': 'verified', 'signature_type': 'ad-hoc', 'notarized': False}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('app', type=Path)
    args = parser.parse_args()
    if sys.platform != 'darwin':
        parser.error('Apple codesign verification requires macOS')
    print(json.dumps(seal(args.app)))
