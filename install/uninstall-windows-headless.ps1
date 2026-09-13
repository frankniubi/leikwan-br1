<#
.SYNOPSIS
    Remove the windowless LeiKwan Bridge client service.
#>
[CmdletBinding()]
param(
    [string]$ServiceName = "LeikwanBridgeHeadless",
    [string]$InstallDir = (Join-Path $env:ProgramFiles "LeiKwan Bridge Headless"),
    [switch]$Purge
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "run this script from an elevated PowerShell"
}

$exe = Join-Path $InstallDir "leikwan-bridge-headless.exe"
if (Test-Path -LiteralPath $exe -PathType Leaf) {
    & $exe --uninstall-service --service-name $ServiceName
} else {
    & sc.exe stop $ServiceName | Out-Null
    & sc.exe delete $ServiceName | Out-Null
}

if ($Purge) {
    $stateDir = Join-Path $env:ProgramData "Leikwan\ClientAgent"
    Remove-Item -LiteralPath (Join-Path $stateDir "agent-token") -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $stateDir "headless-status.json") -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $InstallDir -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host "purged $InstallDir and the stored client token"
} else {
    Write-Host "state kept in $(Join-Path $env:ProgramData 'Leikwan\ClientAgent') (use -Purge to remove it)"
}
