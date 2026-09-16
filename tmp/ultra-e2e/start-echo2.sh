systemctl reset-failed tokyo-echo.service 2>/dev/null
systemctl stop tokyo-echo.service 2>/dev/null
systemd-run --unit=tokyo-echo --collect --property=Restart=always --property=RestartSec=2 /usr/bin/python3 /root/tokyo-echo.py
sleep 3
systemctl is-active tokyo-echo.service
ss -lntup | grep -E ':60000|:60001' || echo 'NOT LISTENING'
journalctl -u tokyo-echo --no-pager -n 5 2>/dev/null | tail -5