$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$manifestPath = Join-Path $root 'artifacts\versions.json'
$doc = Get-Content $manifestPath -Raw | ConvertFrom-Json
$runtimeVersion = '2026.09.16.1'
$fingerprint = '2026.09.16.1.e0e4a71f'
$macAppVersion = '1.0.40'
$windowsHeadlessVersion = '0.1.20.49'

function Get-Meta([string] $relativePath) {
  $path = Join-Path $root ($relativePath -replace '/', '\')
  if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Missing artifact: $relativePath" }
  $item = Get-Item -LiteralPath $path
  [pscustomobject]@{
    sha = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    size = [int64]$item.Length
  }
}

function Set-ArtifactMeta($entry, [string] $relativePath) {
  $meta = Get-Meta $relativePath
  $entry.sha256 = $meta.sha
  $entry.size_bytes = $meta.size
  if ($entry.PSObject.Properties['size']) { $entry.size = $meta.size }
  return $meta
}

function Set-Runtime($entry, [string] $relativePath, [string] $build, [string] $obfuscator) {
  $meta = Set-ArtifactMeta $entry $relativePath
  $entry.version = $runtimeVersion
  $entry.build = $build
  $entry.obfuscator = $obfuscator
  $entry.attestation = [pscustomobject]@{
    fingerprint = $fingerprint
    self_sha256 = $meta.sha
  }
}

$linuxBuild = 'go1.26.5, cgo-disabled, stripped, garble-v0.17.0, active-candidate-fallback-2026.09.16.1'
$windowsBuild = 'go1.26.5, cgo-disabled, stripped, unobfuscated (Windows Defender clean), active-candidate-fallback-2026.09.16.1'
$macBuild = 'go1.26.5, cgo-disabled, stripped, garble-v0.17.0, rcodesign-0.29.0, active-candidate-fallback-2026.09.16.1'
Set-Runtime $doc.ultra_client_runtime 'artifacts/ultra/ultra-client_linux_amd64' $linuxBuild 'garble-v0.17.0'
Set-Runtime $doc.ultra_client_runtime_linux_arm64 'artifacts/ultra/ultra-client_linux_arm64' $linuxBuild 'garble-v0.17.0'
Set-Runtime $doc.ultra_client_runtime_windows 'artifacts/ultra/ultra-client_windows_amd64.exe' $windowsBuild 'none'
Set-Runtime $doc.ultra_client_runtime_macos_arm64 'artifacts/ultra/ultra-client_macos_arm64' $macBuild 'garble-v0.17.0'
Set-Runtime $doc.ultra_client_runtime_macos_x64 'artifacts/ultra/ultra-client_macos_amd64' $macBuild 'garble-v0.17.0'

$armReport = Get-Content (Join-Path $PSScriptRoot 'mac-out\report-arm64.json') -Raw | ConvertFrom-Json
$x64Report = Get-Content (Join-Path $PSScriptRoot 'mac-out\report-x64.json') -Raw | ConvertFrom-Json
function Get-HelperMap($report) {
  $map = [ordered]@{}
  foreach ($helper in $report.helpers.PSObject.Properties) {
    $map[$helper.Value.path] = $helper.Value.sha256
  }
  return $map
}
$armHelpers = Get-HelperMap $armReport
$x64Helpers = Get-HelperMap $x64Report

foreach ($key in @('macos_client_app_arm64', 'macos_client_update_arm64')) {
  $entry = $doc.$key
  $entry.version = $macAppVersion
  Set-ArtifactMeta $entry $entry.path | Out-Null
  if ($entry.PSObject.Properties['runtime_sha256']) { $entry.runtime_sha256 = $armHelpers }
  else { $entry | Add-Member -NotePropertyName runtime_sha256 -NotePropertyValue $armHelpers }
}
foreach ($key in @('macos_client_app_x64', 'macos_client_update_x64')) {
  $entry = $doc.$key
  $entry.version = $macAppVersion
  Set-ArtifactMeta $entry $entry.path | Out-Null
  if ($entry.PSObject.Properties['runtime_sha256']) { $entry.runtime_sha256 = $x64Helpers }
  else { $entry | Add-Member -NotePropertyName runtime_sha256 -NotePropertyValue $x64Helpers }
}

$headless = $doc.windows_headless_client
$headless.version = $windowsHeadlessVersion
Set-ArtifactMeta $headless $headless.path | Out-Null

foreach ($key in @('macos_headless_installer', 'macos_headless_uninstaller')) {
  $entry = $doc.$key
  $entry.version = $macAppVersion
  Set-ArtifactMeta $entry $entry.path | Out-Null
}
foreach ($key in @('windows_headless_installer', 'windows_headless_uninstaller')) {
  $entry = $doc.$key
  $entry.version = $windowsHeadlessVersion
  Set-ArtifactMeta $entry $entry.path | Out-Null
}

$json = $doc | ConvertTo-Json -Depth 30
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText((Resolve-Path $manifestPath), $json + [Environment]::NewLine, $utf8NoBom)
Write-Output "updated $manifestPath"
