$ErrorActionPreference = 'Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$pscp = 'C:\Program Files\PuTTY\pscp.exe'
$plink = 'C:\Program Files\PuTTY\plink.exe'
$hk = 'SHA256:fPxnxfse5aBhNe2rX1w41cpP0EqciKbg1MekD10nYzc'
$target = 'root@173.208.183.93'
& $plink -ssh -batch -pw 'tcrGZXKkJWk3' -hostkey $hk $target 'mkdir -p /root/cli1034' | Out-Null
$files = @(
  @('artifacts\macos\leikwan-bridge-update-arm64.zip','leikwan-bridge-update-arm64.zip'),
  @('artifacts\macos\leikwan-bridge-update-x64.zip','leikwan-bridge-update-x64.zip'),
  @('artifacts\macos\leikwan-bridge-arm64.dmg','leikwan-bridge-arm64.dmg'),
  @('artifacts\macos\leikwan-bridge-x64.dmg','leikwan-bridge-x64.dmg'),
  @('artifacts\windows\leikwan-bridge-headless-win-x64.zip','leikwan-bridge-headless-win-x64.zip'),
  @('install\install-macos-headless.sh','install-macos-headless.sh'),
  @('install\install-windows-headless.ps1','install-windows-headless.ps1'),
  @('install\leikwan','leikwan'),
  @('install\leikwan.cmd','leikwan.cmd'),
  @('artifacts\versions.json','versions.json'),
  @('.build\kb-1034-refresh.sql','kb-1034-refresh.sql'),
  @('tmp\ultra-e2e\publish-cli-1034.py','publish.py')
)
foreach ($pair in $files) {
  & $pscp -batch -pw 'tcrGZXKkJWk3' -hostkey $hk (Resolve-Path $pair[0]).Path ("{0}:/root/cli1034/{1}" -f $target, $pair[1])
  if ($LASTEXITCODE -ne 0) { throw "upload failed: $($pair[0])" }
}
'uploaded'
& $plink -ssh -batch -pw 'tcrGZXKkJWk3' -hostkey $hk $target 'cd /root/cli1034 && python3 publish.py 2>&1 | tail -22' 2>&1 | Out-String -Width 220
'CLI-1034-PUBLISH-DONE'
