cd /tmp
curl -sS -A 'LeikwanBridge/1.0' -D /tmp/apk.h -o /tmp/apk.bin https://v3.leikwanhost.com/artifacts/leikwan-android-client.apk
echo "--- headers ---"; cat /tmp/apk.h
echo "--- size ---"; stat -c %s /tmp/apk.bin; stat -c %s "/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/android/leikwan-android-client.apk"
echo "--- head bytes ---"; head -c 16 /tmp/apk.bin | xxd | head -2
echo "--- repo head bytes ---"; head -c 16 "/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/android/leikwan-android-client.apk" | xxd | head -2
echo "--- other routes ---"
curl -sS -A 'LeikwanBridge/1.0' -o /tmp/apk2.bin -w 'direct=%{size_download} %{http_code}\n' https://v3.leikwanhost.com/artifacts/android/leikwan-android-client.apk
sha256sum /tmp/apk2.bin | cut -c1-16
echo DONE
