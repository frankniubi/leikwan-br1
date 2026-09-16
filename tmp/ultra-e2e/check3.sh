echo '### running ultra procs'
ps -o pid,lstart,etime,cmd -C ultra-client 2>/dev/null | sed -n '1,10p'
echo '### unit state'
for f in 158 159 170 171 180; do printf 'forward-%s ' $f; systemctl show ultra-forward-$f -p MainPID -p NRestarts -p ExecMainStartTimestamp --value | tr '\n' ' '; echo; done
echo '### status file fingerprint'
python3 -c "import json;d=json.load(open('/run/ultra/forward-170.status.json'));print({k:d[k] for k in list(d)[:20]})" 2>&1 | head -5
echo '### agent log tail'
journalctl -u leikwan-client-agent --no-pager -n 8 2>/dev/null | tail -8
echo '### runtime binary version'
/usr/local/bin/ultra-client --version