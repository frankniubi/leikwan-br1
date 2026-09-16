$ErrorActionPreference = 'Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
function Report($label, $output) {
    $json = ($output | Where-Object { $_ -match '^\{' } | Select-Object -Last 1)
    "$label => $json"
}
Report 'windows' (& scripts\build-ultra-windows.ps1 -Version 2026.09.13.3 -ShortSha 452c8fe8 2>&1)
Report 'linux-arm64' (& scripts\build-ultra-linux.ps1 -Version 2026.09.13.3 -ShortSha 452c8fe8 -Arch arm64 2>&1)
Report 'macos' (& scripts\build-ultra-macos.ps1 -Version 2026.09.13.3 -ShortSha 452c8fe8 2>&1)
Report 'android' (& scripts\build-ultra-android.ps1 -Version 2026.09.13.3 -ShortSha 452c8fe8 2>&1)
