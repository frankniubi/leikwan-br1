$ErrorActionPreference = 'Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$pscp = 'C:\Program Files\PuTTY\pscp.exe'
$plink = 'C:\Program Files\PuTTY\plink.exe'
$hk = 'SHA256:fPxnxfse5aBhNe2rX1w41cpP0EqciKbg1MekD10nYzc'
$target = 'root@173.208.183.93'
& $plink -ssh -batch -pw 'tcrGZXKkJWk3' -hostkey $hk $target 'mkdir -p /root/kbheadless' | Out-Null
foreach ($pair in @(
  @('master\src\Controllers\BaseController.php','BaseController.php'),
  @('master\src\Controllers\TenantController.php','TenantController.php'),
  @('master\migrations\20260729_seed_multilingual_knowledgebase.sql','20260729_seed_multilingual_knowledgebase.sql'),
  @('tmp\ultra-e2e\publish-kb-headless.py','publish.py'))) {
  & $pscp -batch -pw 'tcrGZXKkJWk3' -hostkey $hk (Resolve-Path $pair[0]).Path ("{0}:/root/kbheadless/{1}" -f $target, $pair[1])
  if ($LASTEXITCODE -ne 0) { throw "upload failed: $($pair[0])" }
  'uploaded ' + $pair[1]
}
& $plink -ssh -batch -pw 'tcrGZXKkJWk3' -hostkey $hk $target 'cd /root/kbheadless && python3 publish.py' 2>&1 | Out-String -Width 220
'KB-PUBLISH-DONE'
