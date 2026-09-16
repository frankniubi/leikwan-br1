echo '### full download with size check'
curl -sk --max-time 300 -o /tmp/setup-served.exe -w 'http=%{http_code} bytes=%{size_download} time=%{time_total}\n' https://v3.leikwanhost.com/artifacts/leikwan-bridge-setup.exe
ls -l /tmp/setup-served.exe
sha256sum /tmp/setup-served.exe
echo '### manifest expectation'
python3 - <<'PY'
import json
d = json.load(open('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json'))
print('app sha', d['windows_client_app']['sha256'], 'size', d['windows_client_app']['size_bytes'])
PY