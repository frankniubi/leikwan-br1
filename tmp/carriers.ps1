
foreach ($f in 'cm','cu','ct') {
  $p = "master/public/assets/carriers/$f.svg"
  $c = Get-Content $p -Raw
  $m = [regex]::Matches($c, 'fill="([^"]+)"')
  $groups = $m | ForEach-Object { $_.Groups[1].Value.ToLower() } | Group-Object | Sort-Object Count -Descending
  Write-Output ("--- " + $f + ".svg bytes=" + $c.Length + " " + [regex]::Match($c,'viewBox="[^"]*"').Value)
  $groups | Select-Object -First 10 | ForEach-Object { Write-Output ("      " + $_.Count + "x " + $_.Name) }
  Write-Output ("      style-attrs: " + ([regex]::Matches($c,'style="[^"]*"').Count))
}
