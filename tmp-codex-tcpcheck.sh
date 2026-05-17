for h in 114.80.33.50 47.116.11.144 10.0.10.196; do
  echo ===$h===
  timeout 5 bash -c "cat < /dev/null > /dev/tcp/$h/22" && echo tcp22_ok || echo tcp22_fail
done
ping -c 2 -W 2 114.80.33.50 || true
