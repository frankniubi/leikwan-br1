set +e
echo host=$(hostname) date=$(date -Is)
ip -4 -br addr | grep -E 'lwf47|10\.203\.201' || true
for ip in 10.203.201.179 10.203.201.175 10.203.201.183 10.203.201.187 10.203.201.191 185.220.238.143; do
  echo ===$ip===
  for p in 22 8899 8898 2053 5201; do
    timeout 1 bash -lc "</dev/tcp/$ip/$p" >/dev/null 2>&1 && echo open:$p || echo fail:$p
  done
done