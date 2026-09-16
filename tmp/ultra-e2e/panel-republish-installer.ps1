$ErrorActionPreference = 'Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$pscp = 'C:\Program Files\PuTTY\pscp.exe'
$plink = 'C:\Program Files\PuTTY\plink.exe'
$hk = 'SHA256:fPxnxfse5aBhNe2rX1w41cpP0EqciKbg1MekD10nYzc'
$target = 'root@173.208.183.93'
foreach ($file in @('install\install-macos-headless.sh','artifacts\versions.json')) {
  & $pscp -batch -pw 'tcrGZXKkJWk3' -hostkey $hk (Resolve-Path $file).Path ("{0}:/root/nogui/{1}" -f $target, (Split-Path -Leaf $file))
  if ($LASTEXITCODE -ne 0) { throw "upload failed: $file" }
  'uploaded ' + (Split-Path -Leaf $file)
}
& $plink -ssh -batch -pw 'tcrGZXKkJWk3' -hostkey $hk $target 'cd /root/nogui && python3 publish.py' 2>&1 | Select-Object -Last 22 | Out-String -Width 200
'REPUBLISH-DONE'
