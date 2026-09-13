<#
.SYNOPSIS
    Install the windowless LeiKwan Bridge client as a Windows service.

.DESCRIPTION
    The no-GUI client is the same Core stack the desktop app runs, registered as
    an auto-start service under LocalSystem. It signs in with the client's agent
    token, keeps that machine's panel forwards up, and reports the same runtime
    attestation as the windowed client.

.EXAMPLE
    .\install-windows-headless.ps1 -Panel https://v3.leikwanhost.com -ClientId 123 -AgentToken abc123
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Panel,
    [Parameter(Mandatory = $true)][int]$ClientId,
    [string]$AgentToken = "",
    [string]$AgentTokenFile = "",
    [string]$ClientIdArgument = "",
    [string]$InstallDir = (Join-Path $env:ProgramFiles "LeiKwan Bridge Headless"),
    [string]$ServiceName = "LeikwanBridgeHeadless",
    [string]$PayloadDir = $PSScriptRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "run this script from an elevated PowerShell (the service runs as LocalSystem)"
}

$stateDir = Join-Path $env:ProgramData "Leikwan\ClientAgent"
$tokenPath = Join-Path $stateDir "agent-token"
$statusPath = Join-Path $stateDir "headless-status.json"
$logDir = Join-Path $stateDir "logs"

if ([string]::IsNullOrWhiteSpace($AgentToken) -and -not [string]::IsNullOrWhiteSpace($AgentTokenFile)) {
    if (-not (Test-Path -LiteralPath $AgentTokenFile -PathType Leaf)) {
        throw "agent token file not found: $AgentTokenFile"
    }
    $AgentToken = (Get-Content -LiteralPath $AgentTokenFile -Raw).Trim()
}
if ([string]::IsNullOrWhiteSpace($AgentToken)) {
    throw "an agent token is required (-AgentToken or -AgentTokenFile); copy it from the client page in the panel"
}

$payload = Join-Path $PayloadDir "leikwan-bridge-headless.exe"
if (-not (Test-Path -LiteralPath $payload -PathType Leaf)) {
    throw "leikwan-bridge-headless.exe not found next to this script; extract the published zip first"
}

New-Item -ItemType Directory -Force -Path $InstallDir, $stateDir, $logDir | Out-Null
Get-ChildItem -LiteralPath $PayloadDir -File | Where-Object { $_.Name -ne (Split-Path -Leaf $PSCommandPath) } |
    Copy-Item -Destination $InstallDir -Force
Get-ChildItem -LiteralPath $PayloadDir -Directory | Copy-Item -Destination $InstallDir -Recurse -Force

# The token is the client's whole identity: keep it readable by SYSTEM and
# Administrators only, and never in the service command line where any
# authenticated user could read it back with "sc qc".
Set-Content -LiteralPath $tokenPath -Value $AgentToken -NoNewline -Encoding ascii
& icacls.exe $tokenPath /inheritance:r /grant:r "SYSTEM:(R)" "Administrators:(F)" | Out-Null

$exe = Join-Path $InstallDir "leikwan-bridge-headless.exe"
$arguments = @(
    "--install-service",
    "--service-name", $ServiceName,
    "--panel", $Panel,
    "--client-id", $ClientId.ToString(),
    "--agent-token-file", $tokenPath,
    "--status-file", $statusPath,
    "--log-file", (Join-Path $logDir "headless-.log")
)
& $exe @arguments
if ($LASTEXITCODE -ne 0) {
    throw "service registration failed with exit code $LASTEXITCODE"
}

Write-Host ""
Write-Host "service:      $ServiceName"
Write-Host "binary:       $exe"
Write-Host "status file:  $statusPath"
Write-Host "log:          $logDir\headless-*.log"
Write-Host "check with:   sc query $ServiceName; Get-Content '$statusPath'"
Write-Host "remove with:  .\uninstall-windows-headless.ps1 -ServiceName $ServiceName"
