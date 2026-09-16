systemctl stop ultra-soak.service 2>/dev/null; systemctl reset-failed ultra-soak.service 2>/dev/null; sleep 1
pkill -f 'soak.py --duration' 2>/dev/null; sleep 1
echo stopped