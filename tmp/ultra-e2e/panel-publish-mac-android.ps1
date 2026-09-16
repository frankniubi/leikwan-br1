$ErrorActionPreference='Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$pscp = 'C:\Program Files\PuTTY\pscp.exe'
$plink = 'C:\Program Files\PuTTY\plink.exe'
$pw = 'tcrGZXKkJWk3'
$hostkey = 'SHA256:fPxnxfse5aBhNe2rX1w41cpP0EqciKbg1MekD10nYzc'
$target = 'root@173.208.183.93'
& $plink -ssh -batch -pw $pw -hostkey $hostkey $target 'mkdir -p /root/mac1032'
$files = @(
  @('artifacts\ultra\ultra-client_macos_arm64','ultra-client_macos_arm64.new'),
  @('artifacts\ultra\ultra-client_macos_amd64','ultra-client_macos_amd64.new'),
  @('artifacts\macos\leikwan-bridge-update-arm64.zip','leikwan-bridge-update-arm64.zip.new'),
  @('artifacts\macos\leikwan-bridge-update-x64.zip','leikwan-bridge-update-x64.zip.new'),
  @('artifacts\macos\leikwan-bridge-arm64.dmg','leikwan-bridge-arm64.dmg.new'),
  @('artifacts\macos\leikwan-bridge-x64.dmg','leikwan-bridge-x64.dmg.new'),
  @('artifacts\android\leikwan-android-client.apk','leikwan-android-client.apk.new'),
  @('artifacts\versions.json','versions.json')
)
foreach ($pair in $files) {
  $src = (Resolve-Path $pair[0]).Path
  & $pscp -batch -pw $pw -hostkey $hostkey $src ("{0}:/root/mac1032/{1}" -f $target, $pair[1])
  if ($LASTEXITCODE -ne 0) { throw "upload failed: $($pair[0])" }
  'uploaded ' + $pair[1]
}
& $pscp -batch -pw $pw -hostkey $hostkey (Resolve-Path 'tmp\ultra-e2e\publish-mac-android.py').Path ("{0}:/root/mac1032/publish.py" -f $target)
& $plink -ssh -batch -pw $pw -hostkey $hostkey $target -m tmp\ultra-e2e\panel-run-publish.sh
'PUBLISH-UPLOAD-DONE'
