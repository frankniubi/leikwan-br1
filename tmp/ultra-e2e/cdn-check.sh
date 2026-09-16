echo '### served installer through the mirror (what jstc downloads)'
curl -fsSL https://api-leikwanbridge.nbwly.com/artifacts/install-client-agent.sh 2>/dev/null | sha256sum
echo '### served installer through the primary'
curl -fsSL https://v3.leikwanhost.com/artifacts/install-client-agent.sh 2>/dev/null | sha256sum
echo '### expected sha'
echo '5dcc452aa432a94812a771bcb6c56f4c9809cd855338af2c658e9639658c06d4  expected (2026.09.13.1)'
echo '8a8f7ffe8c3a2901efb37948e5d7cf89865de7f01c4a3de77a2171a9d3c365a6  stale (2026.09.11.3)'
echo '### served agent'
curl -fsSL https://api-leikwanbridge.nbwly.com/artifacts/client-agent.py 2>/dev/null | sha256sum
echo '### update unit state'
systemctl list-units --all --no-pager 2>/dev/null | grep 'leikwan-client-agent-update' | tail -1
echo '### unit watchdog'
systemctl cat leikwan-client-agent 2>/dev/null | grep -i watchdog