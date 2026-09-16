set -e
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/tmp
fail=0
for pair in "task3-embed-probe.py 850bc6183e7fbe05303d650118ca711d5c8fdf063be863e6d4c0cf94e20f1a6a" "task3-embed-agent.py 1d11023ffd5afb61f5dbf722f6dbe94fca1b6148394a271ffa7161c1d231e57a"; do
  set -- $pair
  file=$1; want=$2
  # exactly what the note does
  cp "$file" "paste-lf-$file"
  sed -i "s/\r$//" "paste-lf-$file"
  got=$(sha256sum "paste-lf-$file" | cut -d" " -f1)
  if [ "$got" = "$want" ]; then echo "OK   LF paste  $file"; else echo "FAIL LF paste  $file got=$got"; fail=1; fi
  # simulate a Windows editor / CRLF paste, then the same normalisation
  sed "s/$/\r/" "$file" > "paste-crlf-$file"
  sed -i "s/\r$//" "paste-crlf-$file"
  got=$(sha256sum "paste-crlf-$file" | cut -d" " -f1)
  if [ "$got" = "$want" ]; then echo "OK   CRLF paste $file (normalised back)"; else echo "FAIL CRLF paste $file got=$got"; fail=1; fi
done
exit $fail
