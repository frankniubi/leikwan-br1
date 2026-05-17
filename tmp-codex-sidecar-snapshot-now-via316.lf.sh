set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SLAVE_PASS_FILE=/tmp/codex-slave316-pass
printf '%s' '@XwDQ6H5mgMXbrGD' > "$SLAVE_PASS_FILE"
SIDE_B64=$(printf '%s' 'GK@Sp7z%T!s4*w5P' | base64 -w0)
bash tmp-codex-run-sidecar-via316.sh "$SLAVE_PASS_FILE" "$SIDE_B64"
rm -f "$SLAVE_PASS_FILE"