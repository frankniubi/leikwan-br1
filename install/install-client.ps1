param(
    [string]$MasterUrl = "",
    [int]$ForwardId = 0,
    [string]$Token = ""
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($MasterUrl) -or $ForwardId -le 0 -or [string]::IsNullOrWhiteSpace($Token)) {
    throw "Usage: install-client.ps1 -MasterUrl <url> -ForwardId <id> -Token <token>"
}

$baseDir = Join-Path $env:ProgramData "Leikwan\ClientForwards\$ForwardId"
New-Item -ItemType Directory -Path $baseDir -Force | Out-Null

$runtimeUrl = "$MasterUrl/artifacts/leikwan-mptcp-client.py"
$runtimePath = Join-Path $baseDir "leikwan-mptcp-client.py"
Invoke-WebRequest -UseBasicParsing $runtimeUrl -OutFile $runtimePath

$configUrl = "$MasterUrl/api/user/forwards/$ForwardId/client-config?token=$([uri]::EscapeDataString($Token))"
$configPath = Join-Path $baseDir "forward-config.json"
Invoke-WebRequest -UseBasicParsing $configUrl -OutFile $configPath

Write-Host "Downloaded forward runtime files to $baseDir"
Write-Host "Windows direct service installation is not automated in this build; use the client-agent path or a Linux edge host for MPTCP forwarding."
