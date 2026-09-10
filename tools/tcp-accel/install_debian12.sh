#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  cat <<'USAGE'
Usage:
  sudo bash tools/tcp-accel/install_debian12.sh [all|server|client|bridge]

Installs the Python-only TCP acceleration toolkit for Debian 12:
  - /opt/leigod/tcp-accel/tcp_accel.py
  - /etc/leigod/tcp-accel/token
  - systemd units:
      leigod-tcp-accel-server.service
      leigod-tcp-accel-client@.service
      leigod-tcp-accel-bridge@.service

No compilation is required.
USAGE
  exit 0
fi

mode="${1:-all}"
case "$mode" in
  all|server|client|bridge) ;;
  *) echo "unsupported mode: $mode" >&2; exit 2 ;;
esac

if [[ "$(id -u)" != "0" ]]; then
  echo "run as root: sudo bash $0 $mode" >&2
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 is required on Debian 12" >&2
  exit 1
fi

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "$script_dir/../.." && pwd)"
install_root="/opt/leigod/tcp-accel"
config_root="/etc/leigod/tcp-accel"

install -d -m 0755 "$install_root" "$config_root"
install -m 0755 "$script_dir/tcp_accel.py" "$install_root/tcp_accel.py"
install -m 0644 "$script_dir/README.md" "$install_root/README.md"
if [[ -d "$script_dir/examples" ]]; then
  install -d -m 0755 "$install_root/examples"
  install -m 0644 "$script_dir"/examples/* "$install_root/examples/"
fi
python3 -m py_compile "$install_root/tcp_accel.py"

if [[ ! -s "$config_root/token" ]]; then
  umask 077
  python3 - <<'PY' >"$config_root/token"
import secrets
print(secrets.token_urlsafe(48))
PY
fi
chmod 0600 "$config_root/token"

if [[ -f "$repo_root/slave-agent/leigod_tcp_accel_bridge.py" ]]; then
  install -m 0755 "$repo_root/slave-agent/leigod_tcp_accel_bridge.py" "$install_root/leigod_tcp_accel_bridge.py"
  python3 -m py_compile "$install_root/leigod_tcp_accel_bridge.py"
fi

if [[ "$mode" == "all" || "$mode" == "server" ]]; then
  if [[ ! -f "$config_root/server.env" ]]; then
    cat >"$config_root/server.env" <<'ENV'
TCP_ACCEL_LISTEN=0.0.0.0:29030
TCP_ACCEL_TOKEN_FILE=/etc/leigod/tcp-accel/token
TCP_ACCEL_TCP_USER_TIMEOUT_MS=30000
ENV
    chmod 0600 "$config_root/server.env"
  fi

  cat >/etc/systemd/system/leigod-tcp-accel-server.service <<'UNIT'
[Unit]
Description=LeiGod universal TCP acceleration server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=/etc/leigod/tcp-accel/server.env
ExecStart=/usr/bin/python3 /opt/leigod/tcp-accel/tcp_accel.py --log-level INFO server --listen ${TCP_ACCEL_LISTEN} --tcp-user-timeout-ms ${TCP_ACCEL_TCP_USER_TIMEOUT_MS} --token-file ${TCP_ACCEL_TOKEN_FILE}
Restart=always
RestartSec=1
TimeoutStopSec=5
KillSignal=SIGTERM
NoNewPrivileges=true
PrivateTmp=true
UMask=0077
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
UNIT
fi

if [[ "$mode" == "all" || "$mode" == "client" ]]; then
  if [[ ! -f "$config_root/client-example.json" ]]; then
    install -m 0600 "$script_dir/examples/client-direct.json" "$config_root/client-example.json"
  fi
  if [[ ! -f "$config_root/client-through-bridge-example.json" && -f "$script_dir/examples/client-through-bridge.json" ]]; then
    install -m 0600 "$script_dir/examples/client-through-bridge.json" "$config_root/client-through-bridge-example.json"
  fi

  cat >/etc/systemd/system/leigod-tcp-accel-client@.service <<'UNIT'
[Unit]
Description=LeiGod universal TCP acceleration client %i
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStartPre=/usr/bin/python3 /opt/leigod/tcp-accel/tcp_accel.py check-client-config --config /etc/leigod/tcp-accel/client-%i.json
ExecStart=/usr/bin/python3 /opt/leigod/tcp-accel/tcp_accel.py --log-level INFO client-config --config /etc/leigod/tcp-accel/client-%i.json
Restart=always
RestartSec=1
TimeoutStopSec=5
KillSignal=SIGTERM
NoNewPrivileges=true
PrivateTmp=true
UMask=0077
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
UNIT
fi

if [[ "$mode" == "all" || "$mode" == "bridge" ]]; then
  if [[ ! -x "$install_root/leigod_tcp_accel_bridge.py" ]]; then
    echo "BR1 bridge helper missing: $repo_root/slave-agent/leigod_tcp_accel_bridge.py" >&2
    exit 1
  fi
  if [[ ! -f "$config_root/bridge-example.json" ]]; then
    install -m 0600 "$script_dir/examples/bridge.json" "$config_root/bridge-example.json"
  fi
  cat >/etc/systemd/system/leigod-tcp-accel-bridge@.service <<'UNIT'
[Unit]
Description=LeiGod TCP acceleration SOCKS bridge %i
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStartPre=/usr/bin/python3 /opt/leigod/tcp-accel/leigod_tcp_accel_bridge.py bridge-validate --config /etc/leigod/tcp-accel/bridge-%i.json
ExecStart=/usr/bin/python3 /opt/leigod/tcp-accel/leigod_tcp_accel_bridge.py bridge --config /etc/leigod/tcp-accel/bridge-%i.json
Restart=always
RestartSec=1
TimeoutStopSec=5
KillSignal=SIGTERM
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
UNIT
fi

systemctl daemon-reload

cat <<EOF
INSTALL_OK mode=$mode

Installed:
  $install_root/tcp_accel.py
  $config_root/token

Useful next commands:
  systemctl enable --now leigod-tcp-accel-server.service
  cp $config_root/client-example.json $config_root/client-main.json
  python3 $install_root/tcp_accel.py check-client-config --config $config_root/client-main.json
  systemctl enable --now leigod-tcp-accel-client@main.service

Edit configs under:
  $config_root/
EOF
