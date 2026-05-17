set +e
echo host=$(hostname) date=$(date -Is)
for ip in 10.203.201.179 185.220.238.143; do
  echo ===$ip===
  for pass in 'GK@Sp7z%T!s4*w5P' 'Frank1212.' 'Frank20021212.' 'U3I+sVL^%IXyqMX0' '@XwDQ6H5mgMXbrGD'; do
    label=$(printf %s "$pass" | sha256sum | cut -c1-8)
    out=$(timeout 6 sshpass -p "$pass" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o PubkeyAcceptedAlgorithms=+ssh-rsa -o HostkeyAlgorithms=+ssh-rsa -o NumberOfPasswordPrompts=1 -o ConnectTimeout=3 -o ConnectionAttempts=1 root@$ip 'echo SIDE_OK; hostname; date -Is; id' 2>&1 </dev/null | tail -4 || true)
    if echo "$out" | grep -q SIDE_OK; then echo LOGIN:$ip:$label; echo "$out"; exit 0; else echo fail:$ip:$label:$(echo "$out" | tr '\n' ' ' | cut -c1-80); fi
  done
done
