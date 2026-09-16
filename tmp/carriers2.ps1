
foreach ($f in 'cm','cu','ct') {
  $p = "master/public/assets/carriers/$f.svg"
  $c = Get-Content $p -Raw
  $cols = [regex]::Matches($c, 'fill:\s*#[0-9a-fA-F]{3,8}') | ForEach-Object { $_.Value } | Group-Object | Sort-Object Count -Descending
  Write-Output ("--- " + $f + " unique fills: " + ($cols | Measure-Object).Count)
  $cols | Select-Object -First 12 | ForEach-Object { Write-Output ("      " + $_.Count + "x " + $_.Name) }
  $names = [regex]::Matches($c, '\.(st[0-9]+|cls-[0-9]+)\s*\{[^}]*\}') | Select-Object -First 4
  foreach ($n in $names) { Write-Output ("      rule: " + $n.Value.Substring(0, [Math]::Min(90, $n.Value.Length))) }
}
