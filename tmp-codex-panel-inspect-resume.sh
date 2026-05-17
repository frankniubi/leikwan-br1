set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
printf '=== panel git/code version ===\n'
php -l master/src/Controllers/SidecarAgentController.php || true
php -l sidecar-agent/sidecar_agent.py 2>/dev/null || true
printf '=== config files ===\n'
ls -la | sed -n '1,80p'
printf '=== db env hints ===\n'
for f in .env config.php master/config.php master/.env; do [ -f "$f" ] && { echo "--- $f"; sed -n '1,120p' "$f" | sed -E 's/(PASSWORD|PASS|SECRET|TOKEN|KEY)([^=]*=).*/\1\2***REDACTED***/Ig'; }; done
