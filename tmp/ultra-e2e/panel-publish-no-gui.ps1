$ErrorActionPreference = 'Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$pscp = 'C:\Program Files\PuTTY\pscp.exe'
$plink = 'C:\Program Files\PuTTY\plink.exe'
$hk = 'SHA256:fPxnxfse5aBhNe2rX1w41cpP0EqciKbg1MekD10nYzc'
$target = 'root@173.208.183.93'
& $plink -ssh -batch -pw 'tcrGZXKkJWk3' -hostkey $hk $target 'mkdir -p /root/nogui' | Out-Null
$files = @(
  'artifacts\macos\leikwan-bridge-update-arm64.zip',
  'artifacts\macos\leikwan-bridge-update-x64.zip',
  'artifacts\macos\leikwan-bridge-arm64.dmg',
  'artifacts\macos\leikwan-bridge-x64.dmg',
  'artifacts\windows\leikwan-bridge-headless-win-x64.zip',
  'install\install-macos-headless.sh',
  'install\uninstall-macos-headless.sh',
  'install\install-windows-headless.ps1',
  'install\uninstall-windows-headless.ps1',
  'artifacts\versions.json',
  'master\src\Controllers\ArtifactController.php',
  'master\src\Controllers\BaseController.php',
  'master\src\Controllers\TenantController.php'
)
foreach ($file in $files) {
  $src = (Resolve-Path $file).Path
  & $pscp -batch -pw 'tcrGZXKkJWk3' -hostkey $hk $src ("{0}:/root/nogui/{1}" -f $target, (Split-Path -Leaf $file))
  if ($LASTEXITCODE -ne 0) { throw "upload failed: $file" }
  'uploaded ' + (Split-Path -Leaf $file)
}
& $pscp -batch -pw 'tcrGZXKkJWk3' -hostkey $hk (Resolve-Path 'tmp\ultra-e2e\publish-no-gui.py').Path ("{0}:/root/nogui/publish.py" -f $target) | Out-Null
& $plink -ssh -batch -pw 'tcrGZXKkJWk3' -hostkey $hk $target 'cd /root/nogui && python3 publish.py' 2>&1 | Out-String -Width 220
'NO-GUI-PUBLISH-DONE'
