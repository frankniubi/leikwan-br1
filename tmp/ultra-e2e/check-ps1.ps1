$errors = @()
foreach ($file in @('install\install-windows-headless.ps1','install\uninstall-windows-headless.ps1')) {
  $path = Join-Path 'C:\Users\frankniubi\Downloads\leikwan-br1' $file
  $tokens = $null; $parseErrors = $null
  [void][System.Management.Automation.Language.Parser]::ParseFile($path, [ref]$tokens, [ref]$parseErrors)
  if ($parseErrors.Count -gt 0) { $errors += ($file + ': ' + ($parseErrors | ForEach-Object { $_.Message })) } else { Write-Output ($file + ' parses ok') }
}
if ($errors.Count -gt 0) { $errors | ForEach-Object { Write-Output $_ }; exit 1 }
