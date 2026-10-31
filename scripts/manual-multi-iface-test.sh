#!/bin/bash
# manual-multi-iface-test.sh
# Usage: ./manual-multi-iface-test.sh <forward_id>
# Run on client machine to split lw-mptcp-{fwdId} into per-leg interfaces.
# Requires: the current config JSON at /tmp/mptcp-config.json
#
# Phase 1 validation: manually creates N WG interfaces (one per leg)
# and registers MPTCP endpoints with per-interface dev binding.

set -euo pipefail

FWD_ID="${1:?Usage: $0 <forward_id>}"
IFACE="lw-mptcp-${FWD_ID}"
CONFIG_JSON="/tmp/mptcp-config.json"

if [ ! -f "$CONFIG_JSON" ]; then
    echo "ERROR: Place the forward's MPTCP JSON config at $CONFIG_JSON"
    echo "  Copy from: /etc/leikwan/mptcp-forward-${FWD_ID}.json"
    exit 1
fi

echo "=== Step 1: Stop current single interface ==="
systemctl stop "wg-quick@${IFACE}.service" 2>/dev/null || true
wg-quick down "$IFACE" 2>/dev/null || true

echo "=== Step 2: Flush existing MPTCP endpoints ==="
ip mptcp endpoint flush 2>/dev/null || true

echo "=== Step 3: Read legs from config ==="
LEGS=$(python3 -c "
import json, sys
cfg = json.load(open('$CONFIG_JSON'))
underlay = cfg.get('underlay', {})
legs = underlay.get('legs', [])
privkey_path = underlay.get('local_private_key_path', '')
keepalive = underlay.get('peer_keepalive_seconds', 25)
initcwnd = cfg.get('initcwnd', 80)
initrwnd = cfg.get('initrwnd', 80)
for i, leg in enumerate(legs):
    print(json.dumps({
        'idx': i,
        'local_address': leg['local_address'],
        'peer_address': leg['peer_address'],
        'mptcp_remote_address': leg['mptcp_remote_address'],
        'peer_public_key': leg['peer_public_key'],
        'peer_public_host': leg['peer_public_host'],
        'peer_udp_port': leg['peer_udp_port'],
        'outer_transport': leg.get('outer_transport', 'udp'),
        'privkey_path': privkey_path,
        'keepalive': keepalive,
        'initcwnd': initcwnd,
        'initrwnd': initrwnd,
    }))
")

NLEGS=$(echo "$LEGS" | wc -l)
echo "  Found $NLEGS legs"

echo "=== Step 4: Create per-leg interfaces ==="
echo "$LEGS" | while IFS= read -r leg; do
    IDX=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['idx'])")
    LOCAL=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['local_address'])")
    PEER=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['peer_address'])")
    REMOTE=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['mptcp_remote_address'])")
    PUBKEY=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['peer_public_key'])")
    HOST=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['peer_public_host'])")
    PORT=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['peer_udp_port'])")
    PRIVKEY_PATH=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['privkey_path'])")
    KEEPALIVE=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['keepalive'])")
    INITCWND=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['initcwnd'])")
    INITRWND=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['initrwnd'])")

    NEW_IFACE="${IFACE}-${IDX}"
    CONF="/etc/wireguard/${NEW_IFACE}.conf"

    PRIVKEY=$(cat "$PRIVKEY_PATH")

    cat > "$CONF" <<WGEOF
[Interface]
PrivateKey = ${PRIVKEY}
Address = ${LOCAL}/32
PostUp = ip -4 route replace ${REMOTE}/32 dev ${NEW_IFACE} src ${LOCAL} initcwnd ${INITCWND} initrwnd ${INITRWND}
PostUp = ip -4 route replace ${PEER}/32 dev ${NEW_IFACE} src ${LOCAL} initcwnd ${INITCWND} initrwnd ${INITRWND}
PostDown = ip -4 route del ${REMOTE}/32 dev ${NEW_IFACE} >/dev/null 2>&1 || true
PostDown = ip -4 route del ${PEER}/32 dev ${NEW_IFACE} >/dev/null 2>&1 || true

[Peer]
PublicKey = ${PUBKEY}
Endpoint = ${HOST}:${PORT}
AllowedIPs = ${PEER}/32, ${REMOTE}/32
PersistentKeepalive = ${KEEPALIVE}
WGEOF

    chmod 600 "$CONF"
    wg-quick up "${NEW_IFACE}"
    echo "  Created ${NEW_IFACE} -> ${HOST}:${PORT} (local=${LOCAL})"

    # Register MPTCP endpoint with dev binding
    EP_ID=$((100 + IDX))
    ip mptcp endpoint add "${LOCAL}" id "${EP_ID}" dev "${NEW_IFACE}" subflow
    echo "  MPTCP endpoint: ${LOCAL} id=${EP_ID} dev=${NEW_IFACE} subflow"
done

echo ""
echo "=== Step 5: Set MPTCP limits ==="
ip mptcp limits set subflow "$NLEGS" add_addr_accepted "$NLEGS"
echo "  subflow=$NLEGS add_addr_accepted=$NLEGS"

echo ""
echo "=== Verification ==="
echo "--- WG interfaces ---"
wg show interfaces
echo ""
echo "--- MPTCP endpoints ---"
ip mptcp endpoint show
echo ""
echo "--- To test: ---"
echo "  1. Run iperf through the forward"
echo "  2. Check subflows: ss -M | grep ESTAB"
echo "  3. Expected: $NLEGS subflows in ESTAB state"
