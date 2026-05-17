#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank1212.'
sshpass -e ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 root@10.0.10.196 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
python3 - <<'PY'
from pathlib import Path
vals={}
for line in Path('master/.env').read_text().splitlines():
    line=line.strip()
    if not line or line.startswith('#') or '=' not in line: continue
    k,v=line.split('=',1); vals[k]=v.strip().strip('"\'')
for k in ['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASSWORD']:
    v=vals.get(k,'')
    print(k, 'len='+str(len(v)) if k=='DB_PASSWORD' else v)
PY
# Test app env credentials without printing password
DB_HOST=$(awk -F= '/^DB_HOST=/{print $2}' master/.env | tr -d '"\047')
DB_PORT=$(awk -F= '/^DB_PORT=/{print $2}' master/.env | tr -d '"\047')
DB_NAME=$(awk -F= '/^DB_NAME=/{print $2}' master/.env | tr -d '"\047')
DB_USER=$(awk -F= '/^DB_USER=/{print $2}' master/.env | tr -d '"\047')
DB_PASS=$(awk -F= '/^DB_PASSWORD=/{print $2}' master/.env | tr -d '"\047')
MYSQL_PWD="$DB_PASS" mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e 'SELECT 1 AS ok;' || true
MYSQL_PWD='8f2f79a295f3ea8e' mysql -h"$DB_HOST" -P"$DB_PORT" -uroot -e 'SHOW DATABASES;' 2>&1 | head -20 || true
MYSQL_PWD='8f2f79a295f3ea8e' mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e 'SELECT 1 AS ok;' 2>&1 | head -20 || true
REMOTE