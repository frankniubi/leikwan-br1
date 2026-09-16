$ErrorActionPreference = 'Continue'
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
$php = 'C:\Program Files\PHP\current\php.exe'
$files = Get-ChildItem master\tests -Filter '*.php' | Sort-Object Name
$fail = @()
foreach ($file in $files) {
    $out = & $php $file.FullName 2>&1 | Out-String
    $code = $LASTEXITCODE
    if ($code -ne 0) {
        $tail = ($out.Trim() -split "\r?\n" | Select-Object -Last 2) -join ' | '
        $fail += ("{0} (exit {1}): {2}" -f $file.Name, $code, $tail)
    }
}
"master tests run: $($files.Count)"
"failures: $($fail.Count)"
$fail | ForEach-Object { $_ }
