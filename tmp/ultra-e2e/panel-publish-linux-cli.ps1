$ErrorActionPreference = 'Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$pscp = 'C:\Program Files\PuTTY\pscp.exe'
$plink = 'C:\Program Files\PuTTY\plink.exe'
$hk = 'SHA256:fPxnxfse5aBhNe2rX1w41cpP0EqciKbg1MekD10nYzc'
$target = 'root@173.208.183.93'
& $plink -ssh -batch -pw 'tcrGZXKkJWk3' -hostkey $hk $target 'mkdir -p /root/linuxcli' | Out-Null
foreach ($pair in @(
  @('install\leikwan-linux.sh','leikwan-linux.sh'),
  @('install\install-client-agent.sh','install-client-agent.sh'),
  @('master\src\Controllers\ArtifactController.php','ArtifactController.php'),
  @('artifacts\versions.json','versions.json'),
  @('tmp\ultra-e2e\publish-linux-cli.py','publish.py'))) {
  & $pscp -batch -pw 'tcrGZXKkJWk3' -hostkey $hk (Resolve-Path $pair[0]).Path ("{0}:/root/linuxcli/{1}" -f $target, $pair[1])
  if ($LASTEXITCODE -ne 0) { throw "upload failed: $($pair[0])" }
}
& $plink -ssh -batch -pw 'tcrGZXKkJWk3' -hostkey $hk $target 'cd /root/linuxcli && python3 publish.py 2>&1 | tail -18' 2>&1 | Out-String -Width 220
'LINUX-CLI-PUBLISH-DONE'
