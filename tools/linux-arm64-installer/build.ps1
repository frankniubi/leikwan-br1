[CmdletBinding()]
param(
    [string]$Version = "2026.09.19.1",
    [string]$OutputPath = "artifacts/install-client-agent-linux-arm64",
    [switch]$SkipReproducibilityCheck
)

$ErrorActionPreference = "Stop"
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "../..")).Path
$packageRoot = (Resolve-Path $PSScriptRoot).Path
$payloadRoot = Join-Path $packageRoot "payload"
$outputPath = [System.IO.Path]::GetFullPath((Join-Path $repoRoot $OutputPath))
$candidatePath = Join-Path (Split-Path $outputPath -Parent) (".install-client-agent-linux-arm64-{0}.new" -f $PID)
$reproPath = Join-Path (Split-Path $outputPath -Parent) (".install-client-agent-linux-arm64-{0}.repro" -f $PID)

function Copy-Payload([string]$Source, [string]$RelativePath) {
    $destination = Join-Path $payloadRoot $RelativePath
    New-Item -ItemType Directory -Force -Path (Split-Path $destination -Parent) | Out-Null
    Copy-Item -LiteralPath (Join-Path $repoRoot $Source) -Destination $destination -Force
}

try {
    Remove-Item -LiteralPath $payloadRoot -Recurse -Force -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Force -Path $payloadRoot | Out-Null
    Copy-Payload "install/install-client-agent.sh" "install-client-agent.sh"
    Copy-Payload "artifacts/versions.json" "artifacts/versions.json"
    $bundleManifestPath = Join-Path $payloadRoot "artifacts/versions.json"
    $bundleManifest = Get-Content -Raw -LiteralPath (Join-Path $repoRoot "artifacts/versions.json") | ConvertFrom-Json
    $bundleManifest.PSObject.Properties.Remove("client_agent_installer_linux_arm64")
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($bundleManifestPath, ($bundleManifest | ConvertTo-Json -Depth 100), $utf8NoBom)
    Copy-Payload "client-agent/client_agent.py" "artifacts/client-agent.py"
    Copy-Payload "client-agent/cfwg_latency_watchdog.py" "artifacts/cfwg-latency-watchdog.py"
    Copy-Payload "install/client-agent-update.sh" "artifacts/client-agent-update.sh"
    Copy-Payload "install/leikwan-linux.sh" "artifacts/leikwan-linux.sh"
    Copy-Payload "install/install-tcp-accel.sh" "artifacts/install-tcp-accel.sh"
    Copy-Payload "tools/tcp-accel/tcp_accel.py" "artifacts/leikwan-tcp-accel.py"
    Copy-Payload "artifacts/install-forward-runtime.py" "artifacts/install-forward-runtime.py"
    Copy-Payload "artifacts/forward-strategy.py" "artifacts/forward-strategy.py"
    Copy-Payload "client-agent/leikwan_tcp_race.py" "artifacts/leikwan-tcp-race.py"
    Copy-Payload "client-agent/leikwan_udp_fanout.py" "artifacts/leikwan-udp-fanout.py"
    Copy-Payload "client-agent/leikwan_rate_limit.py" "artifacts/leikwan-rate-limit.py"
    Copy-Payload "client-agent/leikwan_uot.py" "artifacts/leikwan-uot.py"
    Copy-Payload "client-agent/leikwan_uot_bond.py" "artifacts/leikwan-uot-bond.py"
    Copy-Payload "artifacts/ultra/ultra-client_linux_arm64" "artifacts/ultra/ultra-client_linux_arm64"
    Copy-Payload "artifacts/wstunnel/leikwan-uot-bond_linux_arm64" "artifacts/wstunnel/leikwan-uot-bond_linux_arm64"
    Copy-Payload "artifacts/wstunnel/wstunnel_linux_arm64" "artifacts/wstunnel/wstunnel_linux_arm64"
    Copy-Payload "artifacts/wstunnel/leikwan-ingress_linux_arm64" "artifacts/wstunnel/leikwan-ingress_linux_arm64"
    Copy-Payload "artifacts/wstunnel/leikwan-bond_linux_arm64" "artifacts/wstunnel/leikwan-bond_linux_arm64"

    New-Item -ItemType Directory -Force -Path (Split-Path $outputPath -Parent) | Out-Null
    Push-Location $packageRoot
    try {
        $env:GOOS = "linux"
        $env:GOARCH = "arm64"
        $env:CGO_ENABLED = "0"
        & go build -trimpath -buildvcs=false -ldflags "-s -w -buildid=" -o $candidatePath .
        if ($LASTEXITCODE -ne 0) { throw "go build failed: $LASTEXITCODE" }
        if (-not $SkipReproducibilityCheck) {
            & go build -trimpath -buildvcs=false -ldflags "-s -w -buildid=" -o $reproPath .
            if ($LASTEXITCODE -ne 0) { throw "reproducibility build failed: $LASTEXITCODE" }
            $candidateHash = (Get-FileHash $candidatePath -Algorithm SHA256).Hash
            $reproHash = (Get-FileHash $reproPath -Algorithm SHA256).Hash
            if ($candidateHash -ne $reproHash) { throw "reproducibility check failed" }
        }
    } finally {
        Pop-Location
    }
    Move-Item -LiteralPath $candidatePath -Destination $outputPath -Force
    $artifact = Get-Item -LiteralPath $outputPath
    [pscustomobject]@{
        version = $Version
        architecture = "arm64"
        sha256 = (Get-FileHash $outputPath -Algorithm SHA256).Hash.ToLowerInvariant()
        size_bytes = $artifact.Length
        go_version = (& go version)
        reproducible = -not $SkipReproducibilityCheck
    } | ConvertTo-Json -Compress
} finally {
    Remove-Item -LiteralPath $candidatePath, $reproPath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $payloadRoot -Recurse -Force -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Force -Path $payloadRoot | Out-Null
    Set-Content -LiteralPath (Join-Path $payloadRoot ".keep") -Value "Bundle payload is generated by build.ps1 before compilation." -Encoding ascii
}
