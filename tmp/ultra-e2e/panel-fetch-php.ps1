$ErrorActionPreference='Stop'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
New-Item -ItemType Directory -Force -Path .build\panel | Out-Null
$pscp = 'C:\Program Files\PuTTY\pscp.exe'
& $pscp -batch -pw 'tcrGZXKkJWk3' -hostkey 'SHA256:fPxnxfse5aBhNe2rX1w41cpP0EqciKbg1MekD10nYzc' root@173.208.183.93:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Bootstrap.php .build\panel\Bootstrap.php
& $pscp -batch -pw 'tcrGZXKkJWk3' -hostkey 'SHA256:fPxnxfse5aBhNe2rX1w41cpP0EqciKbg1MekD10nYzc' root@173.208.183.93:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/ArtifactController.php .build\panel\ArtifactController.php
'downloaded'
