#!/usr/bin/env bash
# Run the actual release ELF in a disposable Debian 13 CI container, never on a host install.
set -euo pipefail
agent_dir="$(realpath "${1:?agent artifact directory}")"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin"
cat >"$tmp/bin/systemctl" <<'WRAPPER'
#!/bin/sh
set -eu
# Observe the environment inherited from the frozen agent, not the CI parent.
env >"$PROBE_DIR/child-environment.txt"
if [ "${LD_LIBRARY_PATH+x}" = x ] || env | grep -q '^_PYI_'; then
    echo 'frozen bootloader environment leaked to systemctl' >"$PROBE_DIR/failed"
fi
/usr/bin/curl --version >"$PROBE_DIR/curl.txt" 2>&1 || touch "$PROBE_DIR/failed"
/usr/bin/journalctl --version >"$PROBE_DIR/journalctl.txt" 2>&1 || touch "$PROBE_DIR/failed"
set +e
/usr/bin/systemctl "$@" >"$PROBE_DIR/systemctl.txt" 2>&1
rc=$?
cat "$PROBE_DIR/systemctl.txt"
exit "$rc"
WRAPPER
chmod +x "$tmp/bin/systemctl"
for agent in client sidecar; do
    work="$tmp/$agent"
    mkdir -p "$work"
    printf '{"state_dir":"%s/state","runtime_config_path":"%s/runtime.json","master_url":"http://127.0.0.1:1"}\n' "$work" "$work" >"$work/config.json"
    binary="$agent_dir/leikwan-$agent-agent_linux_$(dpkg --print-architecture)"
    chmod +x "$binary"
    set +e
    PATH="$tmp/bin:$PATH" PROBE_DIR="$work" "$binary" --config "$work/config.json" check >"$work/check.json" 2>"$work/check.stderr"
    rc=$?
    set -e
    # Client check correctly exits 1 because no agent service is active in this container.
    if [ "$agent" = client ]; then [ "$rc" -eq 1 ]; else [ "$rc" -eq 0 ]; fi
    test -s "$work/child-environment.txt"
    test ! -e "$work/failed"
    grep -q '"agent_version": "2026.11.21.1"' "$work/check.json"
    grep -q '"service_active": false' "$work/check.json"
    grep -Eq 'System has not been booted with systemd|Failed to connect to' "$work/systemctl.txt"
    if grep -E 'OPENSSL_[0-9].*not found|symbol lookup error|error while loading shared libraries' "$work"/*.txt "$work/check.stderr"; then exit 1; fi
    printf '\n%s real frozen agent: clean child environment, Debian 13 host tools work\n' "$agent"
    cat "$work/curl.txt" "$work/journalctl.txt" "$work/systemctl.txt"
done
