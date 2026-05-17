command -v leikwan-ingress || true
ls -l /usr/local/bin | grep -E 'wstunnel|leikwan|bond|ingress|mptcp' | head -100 || true
find / -maxdepth 4 -type f \( -name wstunnel -o -name leikwan-bond -o -name leikwan-ingress \) 2>/dev/null | head -50