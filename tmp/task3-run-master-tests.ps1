$ErrorActionPreference = 'Continue'
$files = Get-ChildItem master/tests/*_test.php | Sort-Object Name
$pass = 0; $fail = 0; $failures = @()
foreach ($f in $files) {
  $out = & php $f.FullName 2>&1 | Out-String
  $code = $LASTEXITCODE
  if ($code -eq 0) { $pass++ } else {
    $fail++
    $tail = ($out.Trim() -split "`r?`n" | Where-Object { $_ -ne '' } | Select-Object -Last 2) -join ' | '
    $failures += "$($f.Name) :: $tail"
  }
}
Write-Output "RESULT pass=$pass fail=$fail total=$($files.Count)"
foreach ($x in $failures) { Write-Output "FAILED $x" }
