#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
src="wstunnel-main/target/x86_64-unknown-linux-musl/release/leikwan-mptcp-relay"
dst="artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64"
install -m 0755 "$src" "$dst"
python3 - <<'PY'
import hashlib, json, pathlib
root=pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1')
artifact=root/'artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64'
versions=root/'artifacts/versions.json'
data=json.loads(versions.read_text(encoding='utf-8'))
blob=artifact.read_bytes()
entry=data.setdefault('leikwan_mptcp_relay_linux_amd64', {})
entry.update({
 'version': '0.1.0',
 'path': 'artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64',
 'artifact_url': '/artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64',
 'sha256': hashlib.sha256(blob).hexdigest(),
 'size_bytes': len(blob),
})
versions.write_text(json.dumps(data, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
print(entry)
PY
sha256sum "$dst"
ldd "$dst" || true
strings "$dst" > /tmp/relay-musl.strings
for needle in 'bond session' 'bond mode requested' 'no bond legs connected' 'leg_direct'; do echo ---$needle---; grep -F "$needle" /tmp/relay-musl.strings | head -3 || true; done