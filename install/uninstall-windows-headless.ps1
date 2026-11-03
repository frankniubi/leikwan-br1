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

# Nothing may keep running from the install directory (child runtimes such as
# bin\ultra-client.exe outlive a killed service host otherwise).
if (Test-Path -LiteralPath $InstallDir -PathType Container) {
    $root = ([IO.Path]::GetFullPath($InstallDir)).TrimEnd('\') + '\'
    $deadline = (Get-Date).AddSeconds(30)
    do {
        $left = @(Get-CimInstance -ClassName Win32_Process -ErrorAction SilentlyContinue | Where-Object {
                $_.ProcessId -ne $PID -and $null -ne $_.ExecutablePath -and
                $_.ExecutablePath.StartsWith($root, [StringComparison]::OrdinalIgnoreCase)
            })
        if ($left.Count -eq 0) { break }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)
    foreach ($process in $left) {
        Write-Warning "killing $($process.Name) (pid $($process.ProcessId)) still running from $InstallDir"
        Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
    }
}

if ($Purge) {
    $stateDir = Join-Path $env:ProgramData "Leikwan\ClientAgent"
    Remove-Item -LiteralPath (Join-Path $stateDir "agent-token") -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $stateDir "headless-status.json") -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $stateDir "updates") -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $stateDir "backups") -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $InstallDir -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host "purged $InstallDir, the stored client token, update state and install backups"
} else {
    Write-Host "state kept in $(Join-Path $env:ProgramData 'Leikwan\ClientAgent') (use -Purge to remove it)"
}
