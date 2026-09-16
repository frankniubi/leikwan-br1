cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
echo '### candidate files'
ls -la artifacts/windows/leikwan-bridge-setup.exe artifacts/leikwan-bridge-setup.exe 2>&1
echo '### shas'
sha256sum artifacts/windows/leikwan-bridge-setup.exe 2>/dev/null
sha256sum artifacts/leikwan-bridge-setup.exe 2>/dev/null
echo '### route for the setup url'
grep -n 'leikwan-bridge-setup' master/src/Bootstrap.php master/src/Controllers/ArtifactController.php | head -10