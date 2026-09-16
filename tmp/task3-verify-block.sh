sudo bash -c 'set -u
UNIT=ultra-source-agent
AGENT=/usr/local/bin/ultra-source-agent.py
PROBE=/opt/ultra-source/runtime/ultra_line_review.py
want_agent=1d11023ffd5afb61f5dbf722f6dbe94fca1b6148394a271ffa7161c1d231e57a
want_probe=850bc6183e7fbe05303d650118ca711d5c8fdf063be863e6d4c0cf94e20f1a6a
fail=0
got_agent=$(sha256sum "$AGENT" | cut -d" " -f1)
got_probe=$(sha256sum "$PROBE" | cut -d" " -f1)
if [ "$got_agent" = "$want_agent" ]; then echo "OK   agent file is the new revision"; else echo "FAIL agent file digest: $got_agent"; fail=1; fi
if [ "$got_probe" = "$want_probe" ]; then echo "OK   probe file is the new revision"; else echo "FAIL probe file digest: $got_probe"; fail=1; fi
if python3 "$PROBE" --help 2>/dev/null | grep -q -- "--target"; then echo "OK   probe CLI exposes --target/--advertised"; else echo "FAIL probe CLI is still the old one"; fail=1; fi
if python3 -c "import sys; sys.path.insert(0, \"/opt/ultra-source/runtime\"); import native_udp as n; [getattr(n, k) for k in (\"NativeSession\", \"LineNotLive\", \"AuthError\")]" 2>/dev/null; then echo "OK   probe dependency native_udp is usable"; else echo "FAIL probe dependency native_udp"; fail=1; fi
if systemctl is-active --quiet "$UNIT"; then echo "OK   $UNIT is active"; else echo "FAIL $UNIT is not active"; fail=1; fi
started=$(date -d "$(systemctl show -p ExecMainStartTimestamp --value "$UNIT")" +%s 2>/dev/null || echo 0)
newest=$(stat -c %Y "$AGENT" "$PROBE" | sort -n | tail -1)
if [ "$started" -ge "$newest" ]; then echo "OK   service started after the new files were written"; else echo "FAIL service start predates the new files - restart it"; fail=1; fi
if [ "$fail" -eq 0 ]; then echo "RESULT: the new review probe is installed and running"; else echo "RESULT: NOT verified"; fi
exit $fail'
