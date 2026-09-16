echo '=== host ==='
hostname; id -un; . /etc/os-release 2>/dev/null; echo "$PRETTY_NAME"; uname -r; uptime
echo '=== ipv4 ==='
ip -4 -o addr show | awk '{print $2, $4}'
echo '=== listeners ==='
ss -lntup 2>/dev/null | head -60
echo '=== port 10001 ==='
ss -lntup 2>/dev/null | grep 10001 || echo 'none on 10001'
echo '=== units ==='
systemctl list-units --type=service --all --no-pager 2>/dev/null | grep -iE 'leikwan|ultra|uot|bond|shadow' || echo none
echo '=== procs ==='
ps aux | grep -iE 'ss-server|shadowsocks|gost|lguot|ultra|leikwan|leigod' | grep -v grep || echo none
echo '=== /usr/local/bin ==='
ls -l /usr/local/bin 2>/dev/null | head -40
echo '=== /opt + /root dirs ==='
ls -la /opt /root 2>/dev/null | head -60
echo '=== leikwan config/state ==='
ls -la /etc/leikwan* /run/leikwan* /var/lib/leikwan* /root/.leikwan* 2>/dev/null | head -60
echo '=== ss-ish configs ==='
find /etc /root /opt -maxdepth 3 -iname '*shadow*' -o -maxdepth 3 -iname '*ss-server*' -o -maxdepth 3 -iname '*uot*' 2>/dev/null | head -40
echo '=== tooling ==='
for b in python3 curl iperf3 socat nc tcpdump jq; do printf '%s: ' $b; command -v $b || echo missing; done
echo '=== done ==='