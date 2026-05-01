param(
    [string]$MasterUrl = "",
    [int]$ClientId = 0,
    [string]$AgentToken = ""
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($MasterUrl) -or $ClientId -le 0 -or [string]::IsNullOrWhiteSpace($AgentToken)) {
    throw "Usage: manage-client.ps1 -MasterUrl <url> -ClientId <id> -AgentToken <token>"
}

$baseDir = Join-Path $env:ProgramData "Leikwan\ClientAgent"
New-Item -ItemType Directory -Path $baseDir -Force | Out-Null

$files = @(
    @{ Url = "$MasterUrl/artifacts/client-agent.py"; Path = (Join-Path $baseDir "client_agent.py") },
    @{ Url = "$MasterUrl/artifacts/install-forward-runtime.py"; Path = (Join-Path $baseDir "install_forward_runtime.py") },
    @{ Url = "$MasterUrl/artifacts/leikwan-rate-limit.py"; Path = (Join-Path $baseDir "leikwan_rate_limit.py") },
    @{ Url = "$MasterUrl/artifacts/leikwan-mptcp-client.py"; Path = (Join-Path $baseDir "leikwan_mptcp_client.py") }
)

foreach ($file in $files) {
    Invoke-WebRequest -UseBasicParsing $file.Url -OutFile $file.Path
}

$config = @{
    master_url = $MasterUrl
    client_id = $ClientId
    agent_token = $AgentToken
    state_dir = (Join-Path $baseDir "state")
    mptcp_client_bin = (Join-Path $baseDir "leikwan_mptcp_client.py")
    log_level = "INFO"
} | ConvertTo-Json -Depth 6

$configPath = Join-Path $baseDir "config.json"
Set-Content -Path $configPath -Value $config -Encoding UTF8

Write-Host "Updated client-agent files under $baseDir"
Write-Host "Register the agent as a Windows service manually if needed."
