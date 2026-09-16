NEW=8251965d614635c9df32f9451086b7cb0bce8d894c7bcee129275663d200c33c
for i in $(seq 1 40); do
  sha=$(sha256sum /usr/local/bin/ultra-client | cut -d' ' -f1)
  tok=$(python3 -c "import json;print(json.load(open('/var/lib/leikwan-client-agent/config_pull_state.json')).get('agent_update_request_token',''))" 2>/dev/null)
  echo "$(date -u +%H:%M:%S) ultra-client=$sha token=$tok"
  [ "$sha" = "$NEW" ] && { echo CONVERGED; break; }
  sleep 15
done
echo '--- final ---'
sha256sum /usr/local/bin/ultra-client; /usr/local/bin/ultra-client --version
systemctl is-active ultra-forward-158 ultra-forward-159 ultra-forward-170 ultra-forward-171 ultra-forward-180 | tr '\n' ' '; echo