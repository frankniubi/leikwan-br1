systemctl reset-failed ultra-soak.service 2>/dev/null
OUT=/root/soak-$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p $OUT
echo "out=$OUT"
systemd-run --unit=ultra-soak --collect --property=StandardOutput=file:$OUT/soak.log --property=StandardError=append:$OUT/soak.log /usr/bin/python3 /root/soak.py --duration 1800 --out-dir $OUT
sleep 8
systemctl is-active ultra-soak.service
ls -la $OUT
echo $OUT > /root/soak-current-dir
cat /root/soak-current-dir
cat $OUT/progress.json 2>/dev/null
tail -n 3 $OUT/soak.log 2>/dev/null