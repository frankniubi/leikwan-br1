#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT_DIR/install/install-shadowsocks-none.sh"

fail() {
  echo "test_shadowsocks_none_installer: $*" >&2
  exit 1
}

[[ -f "$SCRIPT" ]] || fail "installer is missing: $SCRIPT"

need() {
  local pattern="$1"
  grep -Eq -- "$pattern" "$SCRIPT" || fail "missing installer contract: $pattern"
}

need '^#!/usr/bin/env bash$'
need 'set -Eeuo pipefail'
need '--port'
need '--password'
need '--no-start'
need 'read[[:space:]]+-r[[:space:]]+-p'
need 'read[[:space:]]+-r[[:space:]]+-s[[:space:]]+-p'
need 'sing-box'
need 'github.com/SagerNet/sing-box/releases/download'
need 'sha256sum'
need 'SING_BOX_VERSION="1.13.12"'
need 'EXPECTED_SHA256'
need 'curl[[:space:]]+--fail'
need 'tar[[:space:]]+-xzf'
need 'check[[:space:]]+-c'
need 'apt-get'
need 'method[^[:alnum:]]*[=:][^[:alnum:]]*none|none[^[:alnum:]]*method'
need '"type": "shadowsocks"'
need '"outbounds"'
need 'systemctl[[:space:]]+daemon-reload'
need 'systemctl[[:space:]]+is-active'
need 'ss[[:space:]]+-'
need '/var/backups/leikwan-shadowsocks-none'
need 'CAP_NET_BIND_SERVICE'
need 'NoNewPrivileges=true'
need 'PrivateTmp=true'
need 'ProtectSystem=full'
need 'umask 077'

if grep -Eiq '(^|[[:space:]])(iptables|ip6tables|ufw|nft)[[:space:]]' "$SCRIPT"; then
  fail "installer must not mutate firewall rules"
fi

if grep -Eiq '(echo|printf)[^\n]*(PASSWORD|password)' "$SCRIPT"; then
  fail "installer must not print password variables"
fi

bash -n "$SCRIPT"
echo "ok"
