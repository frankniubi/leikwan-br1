$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$stage = Join-Path $PSScriptRoot 'publish-stage'
$zip = Join-Path $PSScriptRoot 'ultra-release-20260916.1-e0e4a71f.zip'
if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force }
foreach ($dir in @('artifacts\ultra', 'artifacts\windows', 'artifacts\macos', 'install')) {
  New-Item -ItemType Directory -Force -Path (Join-Path $stage $dir) | Out-Null
}
$files = @(
  'artifacts\ultra\ultra-client_linux_amd64',
  'artifacts\ultra\ultra-client_linux_arm64',
  'artifacts\ultra\ultra-client_macos_arm64',
  'artifacts\ultra\ultra-client_macos_amd64',
  'artifacts\ultra\ultra-client_windows_amd64.exe',
  'artifacts\windows\leikwan-bridge-headless-win-x64.zip',
  'artifacts\macos\leikwan-bridge-update-arm64.zip',
  'artifacts\macos\leikwan-bridge-update-x64.zip',
  'artifacts\macos\leikwan-bridge-arm64.dmg',
  'artifacts\macos\leikwan-bridge-x64.dmg',
  'artifacts\versions.json',
  'install\install-macos-headless.sh',
  'install\uninstall-macos-headless.sh',
  'install\install-windows-headless.ps1',
  'install\uninstall-windows-headless.ps1'
)
foreach ($relative in $files) {
  $source = Join-Path $root $relative
  Copy-Item -LiteralPath $source -Destination (Join-Path $stage $relative) -Force
}
Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $zip -CompressionLevel Optimal
Write-Output "stage=$stage"
Write-Output "zip=$zip"
Get-Item -LiteralPath $zip | Select-Object FullName, Length
