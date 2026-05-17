#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
src="wstunnel-main/target/release/leikwan-mptcp-relay"
dst="artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64"
if [ ! -x "$src" ]; then
  echo "missing built relay: $src" >&2
  exit 1
fi
install -m 0755 "$src" "$dst"
python3 - <<'PY'
import hashlib, json, pathlib
root = pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1')
artifact = root / 'artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64'
versions_path = root / 'artifacts/versions.json'
data = json.loads(versions_path.read_text(encoding='utf-8'))
blob = artifact.read_bytes()
entry = data.setdefault('leikwan_mptcp_relay_linux_amd64', {})
entry['version'] = entry.get('version') or '0.1.0'
entry['path'] = 'artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64'
entry['artifact_url'] = '/artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64'
entry['sha256'] = hashlib.sha256(blob).hexdigest()
entry['size_bytes'] = len(blob)
versions_path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps(entry, indent=2))
PY
sha256sum "$dst"
strings "$dst" > /tmp/relay-new.strings
for needle in 'bond session' 'bond mode requested' 'no bond legs connected' 'leg_direct'; do
  echo "--- $needle ---"
  grep -F "$needle" /tmp/relay-new.strings | head -5 || true
done