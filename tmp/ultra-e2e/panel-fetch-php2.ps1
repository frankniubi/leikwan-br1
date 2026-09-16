$ErrorActionPreference='Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$pscp = 'C:\Program Files\PuTTY\pscp.exe'
$hk = 'SHA256:fPxnxfse5aBhNe2rX1w41cpP0EqciKbg1MekD10nYzc'
foreach ($pair in @(
  @('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/BaseController.php','.build\panel\BaseController.php'),
  @('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/TenantController.php','.build\panel\TenantController.php'))) {
  & $pscp -batch -pw 'tcrGZXKkJWk3' -hostkey $hk ("root@173.208.183.93:" + $pair[0]) $pair[1] | Out-Null
}
'downloaded'
