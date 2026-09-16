#!/bin/bash
set -euo pipefail
NEW=/usr/local/libexec/leikwan/ultra-client-2026.09.16.2-reader.6d189471
TS=$(date +%Y%m%d-%H%M%S)
BK=/root/ultra-reader-fix-20260916-2
echo '== pre =='
OLDPID=$(systemctl show ultra-forward-170 -p MainPID --value)
echo old_pid=$OLDPID state=$(systemctl show ultra-forward-170 -p ActiveState --value)
CUR_EXE=$(readlink /proc/$OLDPID/exe)
echo cur_exe=$CUR_EXE
$CUR_EXE --version || true
mkdir -p $BK && chmod 0700 $BK
cp -a /usr/local/bin/ultra-client $BK/ultra-client.bin.$TS
cp -a /etc/systemd/system/ultra-forward-170.service $BK/
cp -a /etc/ultra/forward-170.json $BK/forward-170.json.$TS
sha256sum /usr/local/bin/ultra-client /etc/ultra/forward-170.json > $BK/pre.sha256
printf '#!/bin/bash\nset -e\nrm -f /etc/systemd/system/ultra-forward-170.service.d/90-reader-fix.conf\nsystemctl daemon-reload\nsystemctl restart ultra-forward-170\nsystemctl show ultra-forward-170 -p MainPID -p ActiveState\n' > $BK/rollback.sh
chmod 0700 $BK/rollback.sh
echo '== install =='
install -m 0755 /tmp/ultra-client-2026.09.16.2-reader.6d189471 $NEW
echo new_sha=$(sha256sum $NEW | cut -c1-24)
echo '== dropin =='
mkdir -p /etc/systemd/system/ultra-forward-170.service.d
printf '[Service]\nExecStart=\nExecStart=%s --config /etc/ultra/forward-170.json\n' $NEW > /etc/systemd/system/ultra-forward-170.service.d/90-reader-fix.conf
systemctl daemon-reload
echo '== restart =='
systemctl restart ultra-forward-170
sleep 4
NEWPID=$(systemctl show ultra-forward-170 -p MainPID --value)
echo new_pid=$NEWPID state=$(systemctl show ultra-forward-170 -p ActiveState --value)
readlink /proc/$NEWPID/exe
$NEW --version
grep forward-170.json $BK/pre.sha256 | sha256sum -c >/dev/null 2>&1 && echo config_unchanged=yes || echo config_unchanged=no
journalctl -u ultra-forward-170 --since '-2 min' --no-pager | tail -6
echo '== done =='