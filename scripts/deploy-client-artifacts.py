#!/usr/bin/env python3
"""Backup-first, guarded publication of selected desktop/mobile client artifacts.

Run on the Panel host with an audited JSON plan and a separately uploaded stage.
Each file is atomically replaced, manifest last. A failed postflight restores only
files still matching this transaction, never overwriting another writer's change.
PHP is deliberately excluded from this release tool's write allowlist.
"""
import argparse
import contextlib
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import tempfile
from datetime import datetime, timezone

MANIFEST = 'artifacts/versions.json'
# The macOS client ships for Apple Silicon only: there are no macOS x64 keys.
CANONICAL = {
    'windows_client_app': ('artifacts/windows/leikwan-bridge-setup.exe', '/artifacts/leikwan-bridge-setup.exe'),
    'android_client_apk': ('artifacts/android/leikwan-android-client.apk', '/artifacts/leikwan-android-client.apk'),
    'client_agent': ('client-agent/client_agent.py', '/artifacts/client-agent.py'),
    'macos_client_app_arm64': ('artifacts/macos/leikwan-bridge-arm64.dmg', '/artifacts/macos/leikwan-bridge-arm64.dmg'),
    'macos_client_update_arm64': ('artifacts/macos/leikwan-bridge-update-arm64.zip', '/artifacts/macos/leikwan-bridge-update-arm64.zip'),
    'windows_headless_client': ('artifacts/windows/leikwan-bridge-headless-win-x64.zip', '/artifacts/windows/leikwan-bridge-headless-win-x64.zip'),
    'windows_headless_installer': ('install/install-windows-headless.ps1', '/artifacts/install-windows-headless.ps1'),
    'windows_headless_uninstaller': ('install/uninstall-windows-headless.ps1', '/artifacts/uninstall-windows-headless.ps1'),
    'macos_headless_uninstaller': ('install/uninstall-macos-headless.sh', '/artifacts/uninstall-macos-headless.sh'),
    'tcp_accel_installer': ('install/install-tcp-accel.sh', '/artifacts/install-tcp-accel.sh'),
    'cfwg_latency_watchdog': ('client-agent/cfwg_latency_watchdog.py', '/artifacts/cfwg-latency-watchdog.py'),
    'client_lease_seal': ('client-agent/leikwan_lease_seal.py', '/artifacts/leikwan-lease-seal.py'),
    'ultra_client_runtime_android_arm64': ('artifacts/ultra/ultra-client_android_arm64', '/artifacts/ultra/ultra-client_android_arm64'),
    'ultra_client_runtime_android_x86_64': ('artifacts/ultra/ultra-client_android_x86_64', '/artifacts/ultra/ultra-client_android_x86_64'),
    'macos_headless_installer': ('install/install-macos-headless.sh', '/artifacts/install-macos-headless.sh'),
    'client_agent_installer': ('install/install-client-agent.sh', '/artifacts/install-client-agent.sh'),
    'client_agent_installer_linux_arm64': ('artifacts/install-client-agent-linux-arm64', '/artifacts/install-client-agent-linux-arm64'),
    'client_agent_linux_amd64': ('artifacts/python-agent/leikwan-client-agent_linux_amd64', '/artifacts/python-agent/leikwan-client-agent_linux_amd64'),
    'client_agent_update': ('install/client-agent-update.sh', '/artifacts/client-agent-update.sh'),
    'client_installer': ('install/install-client.sh', '/artifacts/install-client.sh'),
    'install_forward_runtime': ('client-agent/install_forward_runtime.py', '/artifacts/install-forward-runtime.py'),
    'external_uot_installer': ('install/install-external-uot.sh', '/artifacts/install-external-uot.sh'),
    'ultra_client_runtime': ('artifacts/ultra/ultra-client_linux_amd64', '/artifacts/ultra/ultra-client_linux_amd64'),
    'ultra_client_runtime_linux_arm64': ('artifacts/ultra/ultra-client_linux_arm64', '/artifacts/ultra/ultra-client_linux_arm64'),
    'ultra_client_runtime_windows': ('artifacts/ultra/ultra-client_windows_amd64.exe', '/artifacts/ultra/ultra-client_windows_amd64.exe'),
    'ultra_client_runtime_macos_arm64': ('artifacts/ultra/ultra-client_macos_arm64', '/artifacts/ultra/ultra-client_macos_arm64'),
    'uot_sidecar_linux_amd64': ('artifacts/uot-sidecar/uot-sidecar_linux_amd64', '/artifacts/uot-sidecar/uot-sidecar_linux_amd64'),
    'uot_sidecar_linux_arm64': ('artifacts/uot-sidecar/uot-sidecar_linux_arm64', '/artifacts/uot-sidecar/uot-sidecar_linux_arm64'),
}
LOCKS = ('/tmp/codex-coordinated-release.lock', '/tmp/leikwan-windows-native-renewal.lock', '/tmp/ordinary-ultra-release.lock')


def numeric_version(value):
    # Runtime versions historically append a source SHA after the numeric release.
    # Compare release components, not the non-orderable build identity.
    match = re.fullmatch(r'(\d+(?:\.\d+){2,3})(?:\.[0-9a-f]{7,40})?', str(value))
    if not match:
        raise ValueError('invalid artifact version: ' + str(value))
    return tuple(map(int, match.group(1).split('.')))


def metadata(path):
    with path.open('rb') as stream:
        digest = hashlib.file_digest(stream, 'sha256').hexdigest() if hasattr(hashlib, 'file_digest') else None
        if digest is None:
            h = hashlib.sha256()
            for block in iter(lambda: stream.read(1024 * 1024), b''):
                h.update(block)
            digest = h.hexdigest()
    s = path.stat()
    return {'sha256': digest, 'size_bytes': s.st_size, 'mode': stat.S_IMODE(s.st_mode), 'uid': s.st_uid, 'gid': s.st_gid}


def guarded_path(root, relative):
    p = Path(relative)
    if p.is_absolute() or not p.parts or any(part in ('..', '.') for part in p.parts):
        raise ValueError('unsafe relative path')
    result = root / p
    for parent in [result, *result.parents]:
        if parent == root:
            break
        if parent.is_symlink():
            raise ValueError('symlink target or parent: ' + relative)
    if root.resolve() not in result.resolve().parents:
        raise ValueError('path outside release root')
    return result


def verify(path, expected):
    if path.is_symlink() or not path.is_file():
        raise ValueError('not a regular file: ' + str(path))
    actual = metadata(path)
    for key in ('sha256', 'size_bytes', 'mode', 'uid', 'gid'):
        if key in expected and actual[key] != expected[key]:
            raise ValueError('guard mismatch: ' + str(path) + ': ' + key)
    return actual


def is_absent(entry):
    """A payload the release adds for the first time (plan['new_entries'])."""
    return entry['before'] == {'absent': True}


def verify_before(path, entry):
    if is_absent(entry):
        if path.exists() or path.is_symlink():
            raise ValueError('new artifact already present: ' + str(path))
        return None
    return verify(path, entry['before'])


def atomic_copy(source, target, attributes, expected):
    fd, temp = tempfile.mkstemp(prefix='.leikwan-release-', suffix='.tmp', dir=str(target.parent))
    try:
        with os.fdopen(fd, 'wb') as out, source.open('rb') as src:
            shutil.copyfileobj(src, out)
            out.flush()
            os.fchmod(out.fileno(), attributes['mode'])
            if hasattr(os, 'fchown'):
                os.fchown(out.fileno(), attributes['uid'], attributes['gid'])
            os.fsync(out.fileno())
        verify(Path(temp), {k:expected[k] for k in ('sha256', 'size_bytes')})
        os.replace(temp, target)
        directory = os.open(str(target.parent), os.O_RDONLY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def validate_plan(root, stage, plan):
    if not re.fullmatch(r'[a-zA-Z0-9_-]{1,90}', plan['release_id']):
        raise ValueError('invalid release id')
    if not re.fullmatch(r'[0-9a-f]{40}', plan['git_commit']):
        raise ValueError('expected a full source commit')
    entries = plan['payloads'] + [plan['manifest']]
    new_keys = set(plan.get('new_entries', []))
    unknown_keys = set(plan['versions']) - set(CANONICAL)
    if unknown_keys:
        raise ValueError('not a releasable artifact: ' + ', '.join(sorted(unknown_keys)))
    if not new_keys <= set(plan['versions']):
        raise ValueError('new entry outside the release versions')
    new_paths = {CANONICAL[key][0] for key in new_keys}
    expected_paths = {CANONICAL[key][0] for key in plan['versions']}
    if 'client_agent' in plan['versions']:
        expected_paths.add('artifacts/client-agent.py')
    if len(plan['payloads']) != len(expected_paths) or {e['path'] for e in plan['payloads']} != expected_paths:
        raise ValueError('payload write set mismatch')
    if plan['manifest']['path'] != MANIFEST:
        raise ValueError('manifest target mismatch')
    for entry in entries:
        if is_absent(entry) != (entry['path'] in new_paths):
            raise ValueError('absent marker must match new_entries: ' + entry['path'])
        verify_before(guarded_path(root, entry['path']), entry)
        verify(guarded_path(stage, entry['upload']), entry['after'])
    before = json.loads((root / MANIFEST).read_text())
    if any(key in before for key in new_keys):
        raise ValueError('new entry already in the live manifest')
    after = json.loads(guarded_path(stage, plan['manifest']['upload']).read_text())
    changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
    if changed != set(plan['versions']):
        raise ValueError('unexpected manifest keys changed')
    mac_keys = {key for key in CANONICAL if key.startswith('macos_client_')}
    selected_mac = mac_keys & set(plan['versions'])
    if selected_mac:
        if selected_mac != mac_keys or len({plan['versions'][key] for key in mac_keys}) != 1:
            raise ValueError('macOS DMG/ZIP matrix must use one version and ship both the DMG and the update ZIP')
        for key in mac_keys:
            architecture = 'arm64'
            artifact_format = 'zip' if '_update_' in key else 'dmg'
            if after[key].get('architecture') != architecture or after[key].get('format') != artifact_format:
                raise ValueError('macOS architecture/format mismatch: ' + key)
    for key, version in plan['versions'].items():
        path, route = CANONICAL[key]
        entry = next(e for e in entries if e['path'] == path)
        info = after[key]
        if info['path'] != path or info['artifact_url'] != route or info['version'] != version:
            raise ValueError('noncanonical artifact contract: ' + key)
        if key not in new_keys and numeric_version(version) <= numeric_version(before[key]['version']):
            raise ValueError('version must strictly increase: ' + key)
        for field in ('sha256', 'size_bytes'):
            if info[field] != entry['after'][field]:
                raise ValueError('manifest payload mismatch: ' + key + ': ' + field)
        if key == 'android_client_apk' and info['version_code'] <= before[key]['version_code']:
            raise ValueError('Android versionCode must increase')
    if 'client_agent' in plan['versions']:
        linux = [e['after']['sha256'] for e in entries if e['path'] in ('client-agent/client_agent.py', 'artifacts/client-agent.py')]
        if len(set(linux)) != 1:
            raise ValueError('Linux mirror mismatch')
    verify_untouched_entries(root, after, plan)
    for path, expected in plan['preserved'].items():
        if path in expected_paths or path == MANIFEST:
            raise ValueError('preserved path overlaps write set')
        verify(guarded_path(root, path), expected)
    return entries


def verify_untouched_entries(root, manifest, plan):
    """Refuse to publish a manifest whose other entries do not match their files.

    A release that only replaces some artifacts still republishes the whole
    manifest, so a stale hash elsewhere would keep sending clients a file that
    fails their check. A known gap may be carried only by naming the entry and
    the hash actually on disk in plan['acknowledged_mismatches'], so a later
    change to that file is still caught.
    """
    acknowledged = plan.get('acknowledged_mismatches', {})
    problems = []
    for key, info in sorted(manifest.items()):
        if key in plan['versions'] or not isinstance(info, dict) or 'path' not in info or 'sha256' not in info:
            continue
        try:
            actual = metadata(guarded_path(root, info['path']))['sha256']
        except (OSError, ValueError):
            actual = 'missing'
        if actual == info['sha256']:
            if key in acknowledged:
                problems.append(key + ': acknowledged mismatch no longer exists')
            continue
        if acknowledged.get(key) != actual:
            problems.append(key + ': manifest ' + info['sha256'][:12] + ' disk ' + actual[:12])
    if problems:
        raise ValueError('manifest integrity: ' + '; '.join(problems))


def publish(root, stage, backup_base, plan, health, min_free=1024**3):
    root, stage, backup_base = root.resolve(), stage.resolve(), backup_base.resolve()
    entries = validate_plan(root, stage, plan)
    needed = sum(e['before'].get('size_bytes', 0) + 2 * e['after']['size_bytes'] for e in entries) + min_free
    for location in (root, stage, backup_base):
        if shutil.disk_usage(location).free < needed:
            raise ValueError('insufficient free space: ' + str(location))
    health()
    backup = backup_base / plan['release_id']
    backup.mkdir(mode=0o700, exist_ok=False)
    (backup / 'plan.json').write_text(json.dumps(plan, indent=2) + '\n')
    for entry in entries:
        if is_absent(entry):
            continue
        destination = guarded_path(backup, entry['path'])
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(guarded_path(root, entry['path']), destination)
        verify(destination, {k:entry['before'][k] for k in ('sha256','size_bytes')})
        with destination.open('rb') as saved:
            os.fsync(saved.fileno())
    installed = []
    receipt = {'release_id': plan['release_id'], 'git_commit': plan['git_commit'], 'backup': str(backup),
               'started_utc': datetime.now(timezone.utc).isoformat(), 'before': {e['path']:e['before'] for e in entries}}
    try:
        # Recheck after backup: no stale preflight may overwrite another release.
        validate_plan(root, stage, plan)
        for entry in entries:
            target = guarded_path(root, entry['path'])
            verify_before(target, entry)
            installed.append(entry)
            attributes = entry['after'] if is_absent(entry) else entry['before']
            atomic_copy(guarded_path(stage, entry['upload']), target, attributes, entry['after'])
            verify(target, entry['after'])
        receipt['after'] = {e['path']:verify(guarded_path(root, e['path']), e['after']) for e in entries}
        receipt['preserved'] = {p:verify(guarded_path(root, p), v) for p,v in plan['preserved'].items()}
        receipt['health'] = health()
        receipt['status'] = 'committed'
    except BaseException as error:
        conflicts = []
        # Payloads first, manifest last on rollback as well.
        for entry in installed:
            target = guarded_path(root, entry['path'])
            try:
                if is_absent(entry):
                    # Only remove what this release wrote.
                    if target.exists():
                        if metadata(target)['sha256'] != entry['after']['sha256']:
                            raise RuntimeError('concurrent modification; not removing')
                        target.unlink()
                    continue
                current = metadata(target)
                if current['sha256'] != entry['before']['sha256']:
                    if current['sha256'] != entry['after']['sha256']:
                        raise RuntimeError('concurrent modification; not overwriting')
                    atomic_copy(guarded_path(backup, entry['path']), target, entry['before'], entry['before'])
                verify(target, entry['before'])
            except BaseException as rollback_error:
                conflicts.append({'path':entry['path'], 'error':str(rollback_error)})
        receipt.update(status='rollback_conflict' if conflicts else 'rolled_back', error=str(error), conflicts=conflicts)
        (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2) + '\n')
        print(json.dumps(receipt), flush=True)
        raise
    receipt['completed_utc'] = datetime.now(timezone.utc).isoformat()
    (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2) + '\n')
    print(json.dumps(receipt), flush=True)
    return receipt


def live_health():
    active = subprocess.check_output(['systemctl', 'is-active', 'php-fpm-83-source196.service'], text=True).strip()
    if active != 'active':
        raise RuntimeError('php-fpm inactive')
    reply = subprocess.check_output(['curl', '--noproxy', '*', '--fail', '--silent', '--show-error',
                                    '--connect-timeout','10','--max-time','20',
                                    'https://v3.leikwanhost.com/api/health'], text=True)
    body = json.loads(reply)
    if body.get('ok') is not True and body.get('status') != 'ok':
        raise RuntimeError('health response not ok')
    return {'php_fpm':active, 'body':body}


def main():
    import fcntl
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, required=True)
    parser.add_argument('--stage', type=Path, required=True)
    parser.add_argument('--plan', type=Path, required=True)
    parser.add_argument('--backup-base', type=Path, default=Path('/root/codex-backups'))
    parser.add_argument('--validate-only', action='store_true')
    args = parser.parse_args()
    def interrupted(signum, _frame):
        raise RuntimeError('release interrupted by signal ' + str(signum))
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    with contextlib.ExitStack() as locks:
        for name in LOCKS:
            handle = locks.enter_context(open(name, 'a'))
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        plan = json.loads(args.plan.read_text())
        if args.validate_only:
            validate_plan(args.root.resolve(), args.stage.resolve(), plan)
            print('STAGED_RELEASE_VALIDATED')
        else:
            publish(args.root, args.stage, args.backup_base, plan, live_health)


if __name__ == '__main__':
    main()
