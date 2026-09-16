cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/tmp
for f in task3-note-block-*.sh task3-verify-block.sh; do
  if bash -n "$f" 2>/tmp/rb.err; then
    echo "OK   $f"
  else
    echo "FAIL $f"
    sed -n '1,6p' /tmp/rb.err
  fi
done
