#!/usr/bin/env bash
#
# uot-forward.sh - UDP-over-TCP Port Forwarding via Phantun
#
# Interactive TUI (dialog) + CLI fallback.
#
# Interactive:  sudo ./uot-forward.sh
# CLI:          sudo ./uot-forward.sh install
#               sudo ./uot-forward.sh server add --tcp-port 1001 --udp-target 127.0.0.1:1002
#               sudo ./uot-forward.sh client add --server-addr 1.2.3.4 --server-port 1001 --local-port 1001
#               sudo ./uot-forward.sh list / status / remove <name> / uninstall
#
set -euo pipefail

readonly SCRIPT_VERSION="1.0.0"
readonly PHANTUN_VERSION="v0.8.1"
readonly PHANTUN_SHA256_AMD64="8a7e143db2eb06ad8969bbafd8dcaedd5483b7f3683090865074872e0938e14a"
readonly INSTALL_DIR="/usr/local/bin"
readonly CONFIG_DIR="/etc/uot-forward"
readonly SERVICE_PREFIX="uot-fwd"
readonly IPTABLES_COMMENT="uot-forward"
readonly TUN_SUBNET_BASE=210

# GitHub download mirror for China mainland acceleration
# Set to empty to use github.com directly
GITHUB_MIRROR="${GITHUB_MIRROR:-}"

# ─── Colors (CLI mode only) ────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()  { echo -e "${GREEN}[INFO]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
error() { echo -e "${RED}[ERROR]${NC} $*" >&2; }
die()   { error "$@"; exit 1; }

# ═══════════════════════════════════════════════════════════════════
#  HELPERS (shared by CLI + dialog)
# ═══════════════════════════════════════════════════════════════════
require_root() {
    [[ $EUID -eq 0 ]] || die "This command requires root privileges. Run with sudo."
}

detect_arch() {
    case "$(uname -m)" in
        x86_64|amd64)  echo "x86_64-unknown-linux-gnu" ;;
        aarch64|arm64) echo "aarch64-unknown-linux-gnu" ;;
        *)             return 1 ;;
    esac
}

detect_iface() {
    ip route show default 2>/dev/null | awk '/default/{print $5; exit}'
}

check_debian() {
    if [[ -f /etc/os-release ]]; then
        local os_id os_ver_id
        os_id=$(grep -oP '^ID=\K.*' /etc/os-release 2>/dev/null || true)
        os_ver_id=$(grep -oP '^VERSION_ID="\K[^"]+' /etc/os-release 2>/dev/null || true)
        if [[ "$os_id" == "debian" ]]; then
            local major="${os_ver_id%%.*}"
            if [[ "$major" -ge 11 && "$major" -le 13 ]]; then return 0; fi
        fi
    fi
    return 1
}

next_tun_index() {
    local max=0
    if [[ -d "$CONFIG_DIR" ]]; then
        for conf in "$CONFIG_DIR"/*.conf; do
            [[ -f "$conf" ]] || continue
            local idx
            idx=$(grep -oP '^TUN_INDEX=\K\d+' "$conf" 2>/dev/null || echo 0)
            (( idx > max )) && max=$idx
        done
    fi
    echo $(( max + 1 ))
}

validate_port() {
    local port="$1"
    [[ "$port" =~ ^[0-9]+$ ]] && (( port >= 1 && port <= 65535 ))
}

validate_addr_port() {
    local val="$1"
    local addr="${val%:*}" port="${val##*:}"
    [[ -n "$addr" && -n "$port" ]] && validate_port "$port"
}

config_name_exists() { [[ -f "$CONFIG_DIR/$1.conf" ]]; }

auto_name() {
    local prefix="$1" port="$2" name="${1}${2}" i=1
    while config_name_exists "$name"; do
        name="${prefix}${port}_${i}"; (( i++ ))
    done
    echo "$name"
}

list_config_names() {
    local names=()
    if [[ -d "$CONFIG_DIR" ]]; then
        for conf in "$CONFIG_DIR"/*.conf; do
            [[ -f "$conf" ]] || continue
            local n; n=$(grep -oP '^NAME=\K.+' "$conf" 2>/dev/null || true)
            [[ -n "$n" ]] && names+=("$n")
        done
    fi
    printf '%s\n' "${names[@]}"
}

# ═══════════════════════════════════════════════════════════════════
#  BACKEND: do_install / do_server_add / do_client_add / do_remove / do_uninstall
#  All output goes to stdout; callers capture or display as needed.
# ═══════════════════════════════════════════════════════════════════
do_install() {
    local arch_target
    arch_target=$(detect_arch) || { echo "ERROR: Unsupported architecture: $(uname -m)"; return 1; }

    if ! check_debian; then
        echo "WARNING: Not Debian 11/12/13. Proceeding anyway..."
    fi

    echo "Installing Phantun ${PHANTUN_VERSION} (${arch_target})..."

    local gh_base="https://github.com"
    if [[ -n "$GITHUB_MIRROR" ]]; then
        gh_base="${GITHUB_MIRROR}/https://github.com"
        echo "Using mirror: ${GITHUB_MIRROR}"
    fi
    local url="${gh_base}/dndx/phantun/releases/download/${PHANTUN_VERSION}/phantun_${arch_target}.zip"
    local tmpdir; tmpdir=$(mktemp -d)

    echo "Downloading ${url} ..."
    if command -v wget &>/dev/null; then
        wget -q -O "$tmpdir/phantun.zip" "$url" 2>&1
    elif command -v curl &>/dev/null; then
        curl -fSL -o "$tmpdir/phantun.zip" "$url" 2>&1
    else
        rm -rf "$tmpdir"
        echo "ERROR: Neither wget nor curl found."; return 1
    fi

    if [[ "$arch_target" == x86_64* ]]; then
        echo "Verifying SHA256..."
        local actual; actual=$(sha256sum "$tmpdir/phantun.zip" | awk '{print $1}')
        if [[ "$actual" != "$PHANTUN_SHA256_AMD64" ]]; then
            rm -rf "$tmpdir"
            echo "ERROR: Checksum mismatch!"; echo "  Expected: $PHANTUN_SHA256_AMD64"; echo "  Got:      $actual"; return 1
        fi
        echo "Checksum OK."
    fi

    command -v unzip &>/dev/null || { echo "Installing unzip..."; apt-get update -qq && apt-get install -y -qq unzip; }
    unzip -o "$tmpdir/phantun.zip" -d "$tmpdir/phantun" >/dev/null

    cp "$tmpdir/phantun/phantun_server" "$INSTALL_DIR/phantun_server"
    cp "$tmpdir/phantun/phantun_client" "$INSTALL_DIR/phantun_client"
    chmod +x "$INSTALL_DIR/phantun_server" "$INSTALL_DIR/phantun_client"
    setcap cap_net_admin=+pe "$INSTALL_DIR/phantun_server"
    setcap cap_net_admin=+pe "$INSTALL_DIR/phantun_client"

    if ! sysctl -n net.ipv4.ip_forward 2>/dev/null | grep -q 1; then
        echo "Enabling IPv4 forwarding..."
        sysctl -w net.ipv4.ip_forward=1 >/dev/null
        grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf 2>/dev/null || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
    fi

    mkdir -p "$CONFIG_DIR"
    echo ""
    echo "Phantun ${PHANTUN_VERSION} installed successfully."
    echo "  phantun_server -> $INSTALL_DIR/phantun_server"
    echo "  phantun_client -> $INSTALL_DIR/phantun_client"
    echo "  Config dir     -> $CONFIG_DIR"

    rm -rf "$tmpdir"
}

do_server_add() {
    local tcp_port="$1" udp_target="$2" name="$3" iface="$4"

    validate_port "$tcp_port"      || { echo "ERROR: Invalid TCP port: $tcp_port"; return 1; }
    validate_addr_port "$udp_target" || { echo "ERROR: Invalid UDP target: $udp_target (expect host:port)"; return 1; }
    [[ -f "$INSTALL_DIR/phantun_server" ]] || { echo "ERROR: Phantun not installed. Install first."; return 1; }
    [[ -z "$iface" ]] && iface=$(detect_iface)
    [[ -n "$iface" ]] || { echo "ERROR: Cannot detect network interface."; return 1; }
    [[ -z "$name" ]] && name=$(auto_name "s" "$tcp_port")
    config_name_exists "$name" && { echo "ERROR: Mapping '$name' already exists."; return 1; }

    local tun_idx; tun_idx=$(next_tun_index)
    local tun_name="ptn_s_${name}"
    local tun_subnet=$((TUN_SUBNET_BASE + tun_idx))
    (( tun_subnet > 254 )) && { echo "ERROR: Too many mappings (subnet overflow)."; return 1; }
    local tun_local="192.168.${tun_subnet}.1"
    local tun_peer="192.168.${tun_subnet}.2"

    echo "Creating server mapping '${name}'..."
    echo "  TCP listen:  :${tcp_port}"
    echo "  UDP forward: ${udp_target}"
    echo "  TUN device:  ${tun_name} (${tun_local} <-> ${tun_peer})"
    echo "  Interface:   ${iface}"

    cat > "$CONFIG_DIR/${name}.conf" <<EOF
MODE=server
NAME=${name}
TCP_PORT=${tcp_port}
UDP_TARGET=${udp_target}
TUN_NAME=${tun_name}
TUN_LOCAL=${tun_local}
TUN_PEER=${tun_peer}
TUN_INDEX=${tun_idx}
IFACE=${iface}
EOF

    cat > "$CONFIG_DIR/${name}-iptables-up.sh" <<EOF
#!/usr/bin/env bash
iptables -t nat -A PREROUTING -p tcp -i ${iface} --dport ${tcp_port} -j DNAT --to-destination ${tun_peer} -m comment --comment "${IPTABLES_COMMENT}-${name}"
EOF
    chmod +x "$CONFIG_DIR/${name}-iptables-up.sh"

    cat > "$CONFIG_DIR/${name}-iptables-down.sh" <<EOF
#!/usr/bin/env bash
iptables -t nat -D PREROUTING -p tcp -i ${iface} --dport ${tcp_port} -j DNAT --to-destination ${tun_peer} -m comment --comment "${IPTABLES_COMMENT}-${name}" 2>/dev/null || true
EOF
    chmod +x "$CONFIG_DIR/${name}-iptables-down.sh"

    local svc_name="${SERVICE_PREFIX}-${name}"
    cat > "/etc/systemd/system/${svc_name}.service" <<EOF
[Unit]
Description=UoT Forward (server) - ${name} [TCP:${tcp_port} -> UDP:${udp_target}]
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
Environment=RUST_LOG=warn
ExecStartPre=${CONFIG_DIR}/${name}-iptables-up.sh
ExecStart=${INSTALL_DIR}/phantun_server --local ${tcp_port} --remote ${udp_target} --tun ${tun_name} --tun-local ${tun_local} --tun-peer ${tun_peer} -4
ExecStopPost=${CONFIG_DIR}/${name}-iptables-down.sh
Restart=always
RestartSec=5
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable --now "${svc_name}.service" 2>&1

    echo ""
    echo "Server mapping '${name}' created and started."
    echo "Service: ${svc_name}.service"
}

do_client_add() {
    local server_addr="$1" server_port="$2" local_port="$3" name="$4" iface="$5"

    validate_port "$server_port" || { echo "ERROR: Invalid server port: $server_port"; return 1; }
    validate_port "$local_port"  || { echo "ERROR: Invalid local port: $local_port"; return 1; }
    [[ -n "$server_addr" ]]      || { echo "ERROR: Server address is required."; return 1; }
    [[ -f "$INSTALL_DIR/phantun_client" ]] || { echo "ERROR: Phantun not installed. Install first."; return 1; }
    [[ -z "$iface" ]] && iface=$(detect_iface)
    [[ -n "$iface" ]] || { echo "ERROR: Cannot detect network interface."; return 1; }
    [[ -z "$name" ]] && name=$(auto_name "c" "$local_port")
    config_name_exists "$name" && { echo "ERROR: Mapping '$name' already exists."; return 1; }

    local tun_idx; tun_idx=$(next_tun_index)
    local tun_name="ptn_c_${name}"
    local tun_subnet=$((TUN_SUBNET_BASE + tun_idx))
    (( tun_subnet > 254 )) && { echo "ERROR: Too many mappings (subnet overflow)."; return 1; }
    local tun_local="192.168.${tun_subnet}.1"
    local tun_peer="192.168.${tun_subnet}.2"

    echo "Creating client mapping '${name}'..."
    echo "  Server:     ${server_addr}:${server_port}"
    echo "  Local UDP:  127.0.0.1:${local_port}"
    echo "  TUN device: ${tun_name} (${tun_local} <-> ${tun_peer})"
    echo "  Interface:  ${iface}"

    cat > "$CONFIG_DIR/${name}.conf" <<EOF
MODE=client
NAME=${name}
SERVER_ADDR=${server_addr}
SERVER_PORT=${server_port}
LOCAL_PORT=${local_port}
TUN_NAME=${tun_name}
TUN_LOCAL=${tun_local}
TUN_PEER=${tun_peer}
TUN_INDEX=${tun_idx}
IFACE=${iface}
EOF

    cat > "$CONFIG_DIR/${name}-iptables-up.sh" <<EOF
#!/usr/bin/env bash
iptables -t nat -A POSTROUTING -s ${tun_local}/24 -o ${iface} -j MASQUERADE -m comment --comment "${IPTABLES_COMMENT}-${name}"
EOF
    chmod +x "$CONFIG_DIR/${name}-iptables-up.sh"

    cat > "$CONFIG_DIR/${name}-iptables-down.sh" <<EOF
#!/usr/bin/env bash
iptables -t nat -D POSTROUTING -s ${tun_local}/24 -o ${iface} -j MASQUERADE -m comment --comment "${IPTABLES_COMMENT}-${name}" 2>/dev/null || true
EOF
    chmod +x "$CONFIG_DIR/${name}-iptables-down.sh"

    local svc_name="${SERVICE_PREFIX}-${name}"
    cat > "/etc/systemd/system/${svc_name}.service" <<EOF
[Unit]
Description=UoT Forward (client) - ${name} [UDP:${local_port} -> ${server_addr}:${server_port}]
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
Environment=RUST_LOG=warn
ExecStartPre=${CONFIG_DIR}/${name}-iptables-up.sh
ExecStart=${INSTALL_DIR}/phantun_client --local 127.0.0.1:${local_port} --remote ${server_addr}:${server_port} --tun ${tun_name} --tun-local ${tun_local} --tun-peer ${tun_peer} -4
ExecStopPost=${CONFIG_DIR}/${name}-iptables-down.sh
Restart=always
RestartSec=5
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable --now "${svc_name}.service" 2>&1

    echo ""
    echo "Client mapping '${name}' created and started."
    echo "Service: ${svc_name}.service"
    echo ""
    echo "Point your WireGuard Endpoint to 127.0.0.1:${local_port}"
}

do_remove() {
    local target_name="$1"
    [[ -n "$target_name" ]] || { echo "ERROR: Name is required."; return 1; }
    config_name_exists "$target_name" || { echo "ERROR: Mapping '$target_name' not found."; return 1; }

    source "$CONFIG_DIR/${target_name}.conf"
    local svc_name="${SERVICE_PREFIX}-${NAME}"

    echo "Removing mapping '${target_name}'..."
    systemctl stop "${svc_name}.service" 2>/dev/null || true
    systemctl disable "${svc_name}.service" 2>/dev/null || true
    [[ -x "$CONFIG_DIR/${target_name}-iptables-down.sh" ]] && "$CONFIG_DIR/${target_name}-iptables-down.sh"
    rm -f "/etc/systemd/system/${svc_name}.service"
    rm -f "$CONFIG_DIR/${target_name}.conf"
    rm -f "$CONFIG_DIR/${target_name}-iptables-up.sh"
    rm -f "$CONFIG_DIR/${target_name}-iptables-down.sh"
    systemctl daemon-reload
    echo "Mapping '${target_name}' removed."
}

do_uninstall() {
    local names=()
    if [[ -d "$CONFIG_DIR" ]]; then
        for conf in "$CONFIG_DIR"/*.conf; do
            [[ -f "$conf" ]] || continue
            local n; n=$(grep -oP '^NAME=\K.+' "$conf" 2>/dev/null || true)
            [[ -n "$n" ]] && names+=("$n")
        done
        for n in "${names[@]}"; do
            do_remove "$n"
        done
    fi
    rm -f "$INSTALL_DIR/phantun_server" "$INSTALL_DIR/phantun_client"
    rm -rf "$CONFIG_DIR"
    echo "Uninstall complete."
}

build_status_text() {
    local target_name="${1:-}"
    if [[ -n "$target_name" ]]; then
        config_name_exists "$target_name" || { echo "Mapping '$target_name' not found."; return; }
        source "$CONFIG_DIR/${target_name}.conf"
        local svc_name="${SERVICE_PREFIX}-${NAME}"
        echo "Mapping: ${NAME}"
        echo "Mode:      ${MODE}"
        if [[ "$MODE" == "server" ]]; then
            echo "TCP Port:  ${TCP_PORT}"
            echo "UDP Target:${UDP_TARGET}"
        else
            echo "Server:    ${SERVER_ADDR}:${SERVER_PORT}"
            echo "Local Port:${LOCAL_PORT}"
        fi
        echo "TUN:       ${TUN_NAME} (${TUN_LOCAL} <-> ${TUN_PEER})"
        echo "Interface: ${IFACE}"
        echo ""
        systemctl status "$svc_name" --no-pager 2>/dev/null || echo "(service not found)"
        return
    fi
    # All mappings summary
    if [[ ! -d "$CONFIG_DIR" ]] || ! ls "$CONFIG_DIR"/*.conf &>/dev/null 2>&1; then
        echo "No mappings configured."
        return
    fi
    printf "%-14s %-8s %-8s %-28s %-10s\n" "NAME" "MODE" "STATUS" "MAPPING" "IFACE"
    printf '%.0s-' {1..70}; echo ""
    for conf in "$CONFIG_DIR"/*.conf; do
        [[ -f "$conf" ]] || continue
        source "$conf"
        local svc_name="${SERVICE_PREFIX}-${NAME}"
        local st="stopped"
        systemctl is-active "$svc_name" &>/dev/null && st="running"
        local mapping
        if [[ "$MODE" == "server" ]]; then
            mapping="TCP:${TCP_PORT} -> ${UDP_TARGET}"
        else
            mapping="UDP:${LOCAL_PORT} -> ${SERVER_ADDR}:${SERVER_PORT}"
        fi
        printf "%-14s %-8s %-8s %-28s %-10s\n" "$NAME" "$MODE" "$st" "$mapping" "$IFACE"
    done
}

# ═══════════════════════════════════════════════════════════════════
#  DIALOG TUI
# ═══════════════════════════════════════════════════════════════════
DIALOG_OK=0
DIALOG_CANCEL=1
DIALOG_ESC=255
DLG_TEMP=""

ensure_dialog() {
    if ! command -v dialog &>/dev/null; then
        echo "Installing dialog..."
        apt-get update -qq && apt-get install -y -qq dialog
    fi
    DLG_TEMP=$(mktemp)
}

dlg_msgbox() {
    local title="$1" msg="$2"
    dialog --title "$title" --msgbox "$msg" 20 76
}

dlg_yesno() {
    local title="$1" msg="$2"
    dialog --title "$title" --yesno "$msg" 10 60
}

dlg_error() {
    dialog --title "Error" --msgbox "$1" 10 60
}

# Run a backend function, capture output, show in msgbox
dlg_run() {
    local title="$1"; shift
    local output
    output=$("$@" 2>&1) || true
    dlg_msgbox "$title" "$output"
}

# ─── Main Menu ──────────────────────────────────────────────────────
menu_main() {
    while true; do
        local phantun_status="Not installed"
        [[ -f "$INSTALL_DIR/phantun_server" ]] && phantun_status="Installed (${PHANTUN_VERSION})"

        local mapping_count=0
        if [[ -d "$CONFIG_DIR" ]]; then
            mapping_count=$(ls "$CONFIG_DIR"/*.conf 2>/dev/null | wc -l || true)
        fi

        local ret=0
        dialog --title "UoT Forward v${SCRIPT_VERSION} - Phantun UDP-over-TCP" \
            --cancel-label "Exit" \
            --menu "\n  Phantun: ${phantun_status}\n  Mappings: ${mapping_count}\n" \
            20 60 8 \
            1 "Install / Update Phantun" \
            2 "Add Port Mapping" \
            3 "View All Mappings" \
            4 "Mapping Details" \
            5 "Start / Stop Mapping" \
            6 "Remove Mapping" \
            7 "Uninstall Everything" \
            2>"$DLG_TEMP" || ret=$?

        [[ $ret -ne $DIALOG_OK ]] && break

        local choice; choice=$(<"$DLG_TEMP")
        case "$choice" in
            1) menu_install ;;
            2) menu_add_mapping ;;
            3) menu_list ;;
            4) menu_details ;;
            5) menu_startstop ;;
            6) menu_remove ;;
            7) menu_uninstall ;;
        esac
    done
    rm -f "$DLG_TEMP"
    clear
}

# ─── Install ────────────────────────────────────────────────────────
menu_install() {
    local ret=0
    dialog --title "Download Source" \
        --menu "\nSelect download source:\n(China mainland recommended: use mirror)\n" 15 65 4 \
        direct  "GitHub Direct (github.com)" \
        ghfast  "ghfast.top Mirror (recommended for China)" \
        ghproxy "ghproxy.com Mirror" \
        custom  "Custom mirror URL" \
        2>"$DLG_TEMP" || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    local src; src=$(<"$DLG_TEMP")
    case "$src" in
        direct)  GITHUB_MIRROR="" ;;
        ghfast)  GITHUB_MIRROR="https://ghfast.top" ;;
        ghproxy) GITHUB_MIRROR="https://ghproxy.com" ;;
        custom)
            ret=0
            dialog --title "Custom Mirror" \
                --inputbox "\nEnter mirror base URL\n(e.g. https://ghfast.top):\n" 11 55 \
                2>"$DLG_TEMP" || ret=$?
            [[ $ret -ne $DIALOG_OK ]] && return
            GITHUB_MIRROR=$(<"$DLG_TEMP")
            ;;
    esac

    dialog --title "Install Phantun" --infobox "\nDownloading and installing Phantun ${PHANTUN_VERSION}...\nPlease wait..." 7 55
    local output
    output=$(do_install 2>&1) || true
    dlg_msgbox "Install Result" "$output"
}

# ─── Add Mapping ────────────────────────────────────────────────────
menu_add_mapping() {
    local ret=0
    dialog --title "Add Mapping" \
        --menu "\nSelect mode for this machine:\n" 12 55 2 \
        server "This machine receives connections (Server/B)" \
        client "This machine initiates connections (Client/A)" \
        2>"$DLG_TEMP" || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    local mode; mode=$(<"$DLG_TEMP")
    case "$mode" in
        server) menu_add_server ;;
        client) menu_add_client ;;
    esac
}

menu_add_server() {
    local def_iface; def_iface=$(detect_iface || true)
    local def_name; def_name=$(auto_name "s" "1001")

    local ret=0
    dialog --title "Add Server Mapping" \
        --form "\nPhantun will listen on a TCP port and forward\ndecapsulated UDP to a local service.\n" \
        18 65 5 \
        "TCP Listen Port :"  1 1 "1001"             1 21 20 0 \
        "UDP Target      :"  2 1 "127.0.0.1:1002"   2 21 40 0 \
        "Mapping Name    :"  3 1 "$def_name"         3 21 20 0 \
        "Net Interface   :"  4 1 "$def_iface"        4 21 20 0 \
        2>"$DLG_TEMP" || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    local fields
    mapfile -t fields < "$DLG_TEMP"
    local tcp_port="${fields[0]}"
    local udp_target="${fields[1]}"
    local name="${fields[2]}"
    local iface="${fields[3]}"

    if ! validate_port "$tcp_port"; then
        dlg_error "Invalid TCP port: ${tcp_port}"; return
    fi
    if ! validate_addr_port "$udp_target"; then
        dlg_error "Invalid UDP target: ${udp_target}\nExpected format: host:port"; return
    fi

    ret=0
    dialog --title "Confirm" --yesno "\nCreate server mapping?\n\n  TCP Listen:  :${tcp_port}\n  UDP Target:  ${udp_target}\n  Name:        ${name}\n  Interface:   ${iface}\n" 14 50 || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    dialog --title "Creating..." --infobox "\nCreating server mapping '${name}'...\nPlease wait..." 7 50
    local output
    output=$(do_server_add "$tcp_port" "$udp_target" "$name" "$iface" 2>&1) || true
    dlg_msgbox "Server Mapping Result" "$output"
}

menu_add_client() {
    local def_iface; def_iface=$(detect_iface || true)
    local def_name; def_name=$(auto_name "c" "1001")

    local ret=0
    dialog --title "Add Client Mapping" \
        --form "\nPhantun will wrap local UDP into TCP and tunnel\nit to the remote Phantun server.\n" \
        18 65 5 \
        "Server Address  :"  1 1 ""                  1 21 40 0 \
        "Server TCP Port :"  2 1 "1001"              2 21 20 0 \
        "Local UDP Port  :"  3 1 "1001"              3 21 20 0 \
        "Mapping Name    :"  4 1 "$def_name"         4 21 20 0 \
        "Net Interface   :"  5 1 "$def_iface"        5 21 20 0 \
        2>"$DLG_TEMP" || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    local fields
    mapfile -t fields < "$DLG_TEMP"
    local server_addr="${fields[0]}"
    local server_port="${fields[1]}"
    local local_port="${fields[2]}"
    local name="${fields[3]}"
    local iface="${fields[4]}"

    if [[ -z "$server_addr" ]]; then
        dlg_error "Server address is required."; return
    fi
    if ! validate_port "$server_port"; then
        dlg_error "Invalid server port: ${server_port}"; return
    fi
    if ! validate_port "$local_port"; then
        dlg_error "Invalid local port: ${local_port}"; return
    fi

    ret=0
    dialog --title "Confirm" --yesno "\nCreate client mapping?\n\n  Server:      ${server_addr}:${server_port}\n  Local UDP:   127.0.0.1:${local_port}\n  Name:        ${name}\n  Interface:   ${iface}\n" 14 55 || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    dialog --title "Creating..." --infobox "\nCreating client mapping '${name}'...\nPlease wait..." 7 50
    local output
    output=$(do_client_add "$server_addr" "$server_port" "$local_port" "$name" "$iface" 2>&1) || true
    dlg_msgbox "Client Mapping Result" "$output"
}

# ─── View Mappings ──────────────────────────────────────────────────
menu_list() {
    local text
    text=$(build_status_text 2>&1) || true
    dlg_msgbox "All Mappings" "$text"
}

# ─── Mapping Details ────────────────────────────────────────────────
menu_details() {
    local names_raw; names_raw=$(list_config_names) || true
    [[ -z "$names_raw" ]] && { dlg_msgbox "Details" "No mappings configured."; return; }

    local menu_items=()
    while IFS= read -r n; do
        [[ -z "$n" ]] && continue
        source "$CONFIG_DIR/${n}.conf"
        local desc
        if [[ "$MODE" == "server" ]]; then
            desc="[server] TCP:${TCP_PORT} -> ${UDP_TARGET}"
        else
            desc="[client] UDP:${LOCAL_PORT} -> ${SERVER_ADDR}:${SERVER_PORT}"
        fi
        menu_items+=("$n" "$desc")
    done <<< "$names_raw"

    local ret=0
    dialog --title "Select Mapping" \
        --menu "\nChoose a mapping to view details:\n" 18 65 10 \
        "${menu_items[@]}" \
        2>"$DLG_TEMP" || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    local selected; selected=$(<"$DLG_TEMP")
    local text; text=$(build_status_text "$selected" 2>&1) || true
    dlg_msgbox "Details: ${selected}" "$text"
}

# ─── Start / Stop ──────────────────────────────────────────────────
menu_startstop() {
    local names_raw; names_raw=$(list_config_names) || true
    [[ -z "$names_raw" ]] && { dlg_msgbox "Start/Stop" "No mappings configured."; return; }

    local menu_items=()
    while IFS= read -r n; do
        [[ -z "$n" ]] && continue
        local svc="${SERVICE_PREFIX}-${n}"
        local st="stopped"
        systemctl is-active "$svc" &>/dev/null && st="RUNNING"
        menu_items+=("$n" "[${st}]")
    done <<< "$names_raw"

    local ret=0
    dialog --title "Start / Stop Mapping" \
        --menu "\nSelect a mapping to toggle:\n" 18 55 10 \
        "${menu_items[@]}" \
        2>"$DLG_TEMP" || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    local selected; selected=$(<"$DLG_TEMP")
    local svc="${SERVICE_PREFIX}-${selected}"

    if systemctl is-active "$svc" &>/dev/null; then
        ret=0
        dialog --title "Stop" --yesno "\nMapping '${selected}' is RUNNING.\n\nStop it?" 9 45 || ret=$?
        if [[ $ret -eq $DIALOG_OK ]]; then
            systemctl stop "${svc}.service" 2>/dev/null || true
            dlg_msgbox "Stopped" "Mapping '${selected}' stopped."
        fi
    else
        ret=0
        dialog --title "Start" --yesno "\nMapping '${selected}' is STOPPED.\n\nStart it?" 9 45 || ret=$?
        if [[ $ret -eq $DIALOG_OK ]]; then
            systemctl start "${svc}.service" 2>/dev/null || true
            dlg_msgbox "Started" "Mapping '${selected}' started."
        fi
    fi
}

# ─── Remove Mapping ────────────────────────────────────────────────
menu_remove() {
    local names_raw; names_raw=$(list_config_names) || true
    [[ -z "$names_raw" ]] && { dlg_msgbox "Remove" "No mappings configured."; return; }

    local menu_items=()
    while IFS= read -r n; do
        [[ -z "$n" ]] && continue
        source "$CONFIG_DIR/${n}.conf"
        local desc
        if [[ "$MODE" == "server" ]]; then
            desc="[server] TCP:${TCP_PORT} -> ${UDP_TARGET}"
        else
            desc="[client] UDP:${LOCAL_PORT} -> ${SERVER_ADDR}:${SERVER_PORT}"
        fi
        menu_items+=("$n" "$desc")
    done <<< "$names_raw"

    local ret=0
    dialog --title "Remove Mapping" \
        --menu "\nSelect a mapping to remove:\n" 18 65 10 \
        "${menu_items[@]}" \
        2>"$DLG_TEMP" || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    local selected; selected=$(<"$DLG_TEMP")
    ret=0
    dialog --title "Confirm Removal" \
        --yesno "\nRemove mapping '${selected}'?\n\nThis will stop the service, remove iptables rules,\nand delete all configuration files for this mapping." \
        11 60 || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    local output; output=$(do_remove "$selected" 2>&1) || true
    dlg_msgbox "Remove Result" "$output"
}

# ─── Uninstall ──────────────────────────────────────────────────────
menu_uninstall() {
    local ret=0
    dialog --title "Uninstall" \
        --yesno "\nThis will:\n\n  - Stop and remove ALL mappings\n  - Delete Phantun binaries\n  - Remove all configuration\n\nAre you sure?" \
        14 50 || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    ret=0
    dialog --title "Really?" --yesno "\nThis action is irreversible.\n\nProceed with full uninstall?" 10 45 || ret=$?
    [[ $ret -ne $DIALOG_OK ]] && return

    dialog --title "Uninstalling..." --infobox "\nRemoving all mappings and Phantun...\nPlease wait..." 7 50
    local output; output=$(do_uninstall 2>&1) || true
    dlg_msgbox "Uninstall Result" "$output"
}

# ═══════════════════════════════════════════════════════════════════
#  CLI MODE (fallback when arguments are passed)
# ═══════════════════════════════════════════════════════════════════
cli_usage() {
    cat <<EOF

${BOLD}uot-forward${NC} v${SCRIPT_VERSION} - UDP-over-TCP Port Forwarding via Phantun

${BOLD}INTERACTIVE:${NC}
    sudo $(basename "$0")                    Launch dialog menu

${BOLD}CLI USAGE:${NC}
    sudo $(basename "$0") <command> [options]

${BOLD}COMMANDS:${NC}
    ${CYAN}install${NC}                           Download and install Phantun
    ${CYAN}server add${NC} [options]               Add a server-side port mapping
    ${CYAN}client add${NC} [options]               Add a client-side port mapping
    ${CYAN}list${NC}                               List all port mappings
    ${CYAN}status${NC} [name]                      Show status of mapping(s)
    ${CYAN}remove${NC} <name>                      Remove a port mapping
    ${CYAN}uninstall${NC}                          Remove everything

${BOLD}SERVER OPTIONS:${NC}
    --tcp-port <port>               TCP port to listen on
    --udp-target <host:port>        UDP destination to forward to
    --name <name>                   Optional mapping name
    --iface <interface>             Network interface (auto-detected)

${BOLD}CLIENT OPTIONS:${NC}
    --server-addr <address>         Server IP/hostname
    --server-port <port>            Server TCP port
    --local-port <port>             Local UDP port to expose
    --name <name>                   Optional mapping name
    --iface <interface>             Network interface (auto-detected)

${BOLD}GLOBAL OPTIONS:${NC}
    --cn                            Use ghfast.top mirror (China mainland)
    --mirror <url>                  Custom GitHub mirror (e.g. https://ghfast.top)

${BOLD}EXAMPLES:${NC}
    $(basename "$0") --cn install
    $(basename "$0") server add --tcp-port 1001 --udp-target 127.0.0.1:1002
    $(basename "$0") client add --server-addr 1.2.3.4 --server-port 1001 --local-port 1001
EOF
}

cli_server_add() {
    local tcp_port="" udp_target="" name="" iface=""
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --tcp-port)   tcp_port="$2"; shift 2 ;;
            --udp-target) udp_target="$2"; shift 2 ;;
            --name)       name="$2"; shift 2 ;;
            --iface)      iface="$2"; shift 2 ;;
            *) die "Unknown option: $1" ;;
        esac
    done
    [[ -n "$tcp_port" ]]   || die "Missing --tcp-port"
    [[ -n "$udp_target" ]] || die "Missing --udp-target"
    do_server_add "$tcp_port" "$udp_target" "$name" "$iface"
}

cli_client_add() {
    local server_addr="" server_port="" local_port="" name="" iface=""
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --server-addr) server_addr="$2"; shift 2 ;;
            --server-port) server_port="$2"; shift 2 ;;
            --local-port)  local_port="$2"; shift 2 ;;
            --name)        name="$2"; shift 2 ;;
            --iface)       iface="$2"; shift 2 ;;
            *) die "Unknown option: $1" ;;
        esac
    done
    [[ -n "$server_addr" ]] || die "Missing --server-addr"
    [[ -n "$server_port" ]] || die "Missing --server-port"
    [[ -n "$local_port" ]]  || die "Missing --local-port"
    do_client_add "$server_addr" "$server_port" "$local_port" "$name" "$iface"
}

cli_dispatch() {
    require_root
    local cmd="$1"; shift
    case "$cmd" in
        install)   do_install ;;
        server)    [[ "${1:-}" == "add" ]] || die "Usage: server add [options]"; shift; cli_server_add "$@" ;;
        client)    [[ "${1:-}" == "add" ]] || die "Usage: client add [options]"; shift; cli_client_add "$@" ;;
        list)      build_status_text ;;
        status)    build_status_text "${1:-}" ;;
        remove)    do_remove "${1:-}" ;;
        uninstall)
            warn "This will remove everything."
            echo -n "Continue? [y/N] "
            read -r ans
            [[ "$ans" =~ ^[Yy] ]] || { info "Aborted."; exit 0; }
            do_uninstall
            ;;
        -h|--help|help) cli_usage ;;
        *) die "Unknown command: $cmd. Use --help." ;;
    esac
}

# ═══════════════════════════════════════════════════════════════════
#  ENTRY POINT
# ═══════════════════════════════════════════════════════════════════
main() {
    # Parse global flags first
    local args=()
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --mirror) GITHUB_MIRROR="$2"; shift 2 ;;
            --cn)     GITHUB_MIRROR="https://ghfast.top"; shift ;;
            *)        args+=("$1"); shift ;;
        esac
    done
    set -- "${args[@]}"

    if [[ $# -gt 0 ]]; then
        # CLI mode
        cli_dispatch "$@"
    else
        # Interactive dialog mode
        require_root
        ensure_dialog
        menu_main
    fi
}

main "$@"
