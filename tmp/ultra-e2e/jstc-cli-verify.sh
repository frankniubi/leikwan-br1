set -u
echo "--- panel artifact ---"
curl -fsSL https://v3.leikwanhost.com/artifacts/leikwan-linux.sh -o /tmp/leikwan-linux.sh
sha256sum /tmp/leikwan-linux.sh | cut -c1-16
install -m 0755 /tmp/leikwan-linux.sh /usr/local/bin/leikwan
echo "--- config keys ---"
python3 -c "import json;d=json.load(open('/etc/leikwan-client-agent/config.json'));print(sorted(d.keys())[:12])"
python3 -c "import json;d=json.load(open('/etc/leikwan-client-agent/config.json'));print('master_url',d.get('master_url'));print('artifact_base',d.get('artifact_base'))" 2>/dev/null || true
echo "--- leikwan version ---"; leikwan version; echo "exit=$?"
echo "--- leikwan status ---"; leikwan status; echo "exit=$?"
echo "--- leikwan logs -n 4 ---"; leikwan logs -n 4 2>&1 | tail -4
echo "--- leikwan doctor ---"; leikwan doctor; echo "exit=$?"
echo "--- leikwan update --check ---"; leikwan update --check; echo "exit=$?"
echo "--- leikwan bogus ---"; leikwan bogus 2>&1 | head -2; echo "exit=$?"
echo LINUX-CLI-VERIFY-DONE
