cd /root/mac1032
for pair in \
  "macos_client_app_arm64 artifacts/macos/leikwan-bridge-arm64.dmg" \
  "macos_client_app_x64 artifacts/macos/leikwan-bridge-x64.dmg" \
  "macos_client_update_arm64 artifacts/macos/leikwan-bridge-update-arm64.zip" \
  "macos_client_update_x64 artifacts/macos/leikwan-bridge-update-x64.zip" \
  "ultra_client_runtime_macos_arm64 artifacts/ultra/ultra-client_macos_arm64" \
  "ultra_client_runtime_macos_x64 artifacts/ultra/ultra-client_macos_amd64" \
  "android_client_apk artifacts/android/leikwan-android-client.apk" ; do
  set -- $pair
  key=$1; path=$2
  manifest=$(python3 -c "import json,sys;print(json.load(open('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json'))['$key']['sha256'])")
  ondisk=$(sha256sum "/www/wwwroot/v3.leikwanhost.com/leikwan-v3/$path" | cut -d' ' -f1)
  served=$(curl -sS -A 'LeikwanBridge/1.0' "https://v3.leikwanhost.com/$path" | sha256sum | cut -d' ' -f1)
  status=OK
  [ "$manifest" = "$ondisk" ] || status=DISK-MISMATCH
  [ "$manifest" = "$served" ] || status="SERVED-MISMATCH"
  echo "$key manifest=${manifest:0:16} disk=${ondisk:0:16} served=${served:0:16} $status"
done
echo "--- served manifest version check ---"
curl -sS -A 'LeikwanBridge/1.0' https://v3.leikwanhost.com/artifacts/versions.json | python3 -c "import json,sys;d=json.load(sys.stdin);print('mac app',d['macos_client_app_arm64']['version'],'android',d['android_client_apk']['version'],d['android_client_apk']['version_code'],'mac rt',d['ultra_client_runtime_macos_arm64']['attestation'])"
echo VERIFY-DONE
