<#
.SYNOPSIS
    Install or upgrade the windowless LeiKwan Bridge client as a Windows service.

.DESCRIPTION
    The no-GUI client is the same Core stack the desktop app runs, registered as
    an auto-start service under LocalSystem. It signs in with the client's agent
    token, keeps that machine's panel forwards up, and reports the same runtime
    attestation as the windowed client.

    Re-running the script over an existing install is safe: the running service
    and every process started from the install directory are stopped first, the
    current install is backed up under %ProgramData%\Leikwan\ClientAgent\backups
    (the last three are kept), the service registration is updated in place, and
    the new build must come up RUNNING and write a fresh, connected status file.
    If it does not, the backup, the previous token and the previous service
    registration are restored and the old service is started again.

.EXAMPLE
    .\install-windows-headless.ps1 -Panel https://v3.leikwanhost.com -ClientId 123 -AgentToken abc123
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Panel,
    [Parameter(Mandatory = $true)][int]$ClientId,
    [string]$AgentToken = "",
    [string]$AgentTokenFile = "",
    [string]$EgressInterface = $env:LEIKWAN_EGRESS_INTERFACE,
    [string]$ClientIdArgument = "",
    [string]$InstallDir = (Join-Path $env:ProgramFiles "LeiKwan Bridge Headless"),
    [string]$ServiceName = "LeikwanBridgeHeadless",
    [string]$PayloadDir = $PSScriptRoot,
    [ValidateRange(30, 1800)][int]$HealthTimeoutSeconds = 180,
    [switch]$SkipHealthCheck
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$InstallerVersion = "0.1.20.66"
$KeepBackups = 3

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "run this script from an elevated PowerShell (the service runs as LocalSystem)"
}

$stateDir = Join-Path $env:ProgramData "Leikwan\ClientAgent"
$tokenPath = Join-Path $stateDir "agent-token"
$statusPath = Join-Path $stateDir "headless-status.json"
$logDir = Join-Path $stateDir "logs"
$backupRoot = Join-Path $stateDir "backups"

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
$payloadVersion = [Diagnostics.FileVersionInfo]::GetVersionInfo($payload).FileVersion
if ($payloadVersion -ne $InstallerVersion) {
    throw "headless installer/payload version mismatch: installer=$InstallerVersion payload=$payloadVersion"
}

$exe = Join-Path $InstallDir "leikwan-bridge-headless.exe"

function Write-Step([string]$Message) {
    Write-Host "install:      $Message"
}

function Get-FullDirectory([string]$Path) {
    return ([IO.Path]::GetFullPath($Path)).TrimEnd('\')
}

# SYSTEM and Administrators only: a LocalSystem service restores from here.
function Protect-AdminOnlyDirectory([string]$Path) {
    New-Item -ItemType Directory -Force -Path $Path | Out-Null
    & icacls.exe $Path /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "could not restrict the ACL of $Path (icacls exit $LASTEXITCODE)"
    }
}

function Get-InstallDirProcesses([string]$Directory) {
    $root = (Get-FullDirectory $Directory) + '\'
    # Callers wrap the result in @(): an empty array unrolls to $null.
    return @(Get-CimInstance -ClassName Win32_Process -ErrorAction SilentlyContinue | Where-Object {
            $_.ProcessId -ne $PID -and $null -ne $_.ExecutablePath -and
            $_.ExecutablePath.StartsWith($root, [StringComparison]::OrdinalIgnoreCase)
        })
}

# Stop the service (bounded, then kill its process), then wait until nothing
# runs from the install directory any more (bin\ultra-client.exe and other
# runtimes, stray "leikwan" CLIs), killing stragglers after the grace period.
function Stop-HeadlessService([string]$Name, [string]$Directory, [int]$StopTimeoutSeconds = 60, [int]$DrainTimeoutSeconds = 30) {
    $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if ($null -ne $service -and $service.Status -ne 'Stopped') {
        Write-Step "stopping $Name (state $($service.Status))"
        & sc.exe stop $Name | Out-Null
        $deadline = (Get-Date).AddSeconds($StopTimeoutSeconds)
        while ((Get-Date) -lt $deadline) {
            $service.Refresh()
            if ($service.Status -eq 'Stopped') { break }
            Start-Sleep -Seconds 1
        }
        $service.Refresh()
        if ($service.Status -ne 'Stopped') {
            $serviceCim = Get-CimInstance -ClassName Win32_Service -Filter "Name='$Name'"
            $servicePid = 0
            if ($null -ne $serviceCim) { $servicePid = [int]$serviceCim.ProcessId }
            if ($servicePid -gt 0) {
                Write-Warning "$Name did not stop within $StopTimeoutSeconds s; killing pid $servicePid"
                Stop-Process -Id $servicePid -Force -ErrorAction SilentlyContinue
                Start-Sleep -Seconds 2
            }
        }
    }

    if (-not (Test-Path -LiteralPath $Directory -PathType Container)) {
        return
    }
    $deadline = (Get-Date).AddSeconds($DrainTimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (@(Get-InstallDirProcesses $Directory).Count -eq 0) {
            return
        }
        Start-Sleep -Seconds 1
    }
    foreach ($process in Get-InstallDirProcesses $Directory) {
        Write-Warning "killing $($process.Name) (pid $($process.ProcessId)) still running from $Directory"
        Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
    }
    Start-Sleep -Seconds 2
    $left = @(Get-InstallDirProcesses $Directory)
    if ($left.Count -gt 0) {
        throw "processes still running from ${Directory}: $(($left | ForEach-Object { "$($_.Name)#$($_.ProcessId)" }) -join ', ')"
    }
}

function Invoke-Robocopy([string]$Source, [string]$Destination, [string[]]$Options) {
    # A trailing backslash would escape robocopy's closing quote.
    $Source = $Source.TrimEnd('\')
    $Destination = $Destination.TrimEnd('\')
    & robocopy.exe $Source $Destination @Options /R:5 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
    $code = $LASTEXITCODE
    $global:LASTEXITCODE = 0
    if ($code -ge 8) {
        throw "robocopy $Source -> $Destination failed with exit code $code"
    }
}

function New-InstallBackup([string]$Directory, [string]$Version) {
    Protect-AdminOnlyDirectory $backupRoot
    $safeVersion = ($Version -replace '[^0-9A-Za-z._-]', '_')
    $stamp = (Get-Date).ToUniversalTime().ToString("yyyyMMdd'T'HHmmss'Z'")
    $target = Join-Path $backupRoot "install-$safeVersion-$stamp"
    Invoke-Robocopy $Directory $target @('/E', '/COPY:DAT')
    Get-ChildItem -LiteralPath $backupRoot -Directory -Filter "install-*" |
        Sort-Object CreationTimeUtc -Descending |
        Select-Object -Skip $KeepBackups |
        ForEach-Object {
            Write-Step "pruning old backup $($_.Name)"
            Remove-Item -LiteralPath $_.FullName -Recurse -Force -ErrorAction SilentlyContinue
        }
    return $target
}

function Read-StatusUpdatedUtc($Status) {
    $raw = $Status.UpdatedAt
    if ($raw -is [datetime]) {
        return $raw.ToUniversalTime()
    }
    return [DateTimeOffset]::Parse([string]$raw, [Globalization.CultureInfo]::InvariantCulture).UtcDateTime
}

# Healthy = SCM RUNNING and a status file written after $SinceUtc with
# Connected=true (the client only writes it once it has signed in).
function Wait-HeadlessHealthy([string]$Name, [datetime]$SinceUtc, [int]$TimeoutSeconds) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
        if ($null -ne $service -and $service.Status -eq 'Running' -and (Test-Path -LiteralPath $statusPath -PathType Leaf)) {
            try {
                $status = Get-Content -LiteralPath $statusPath -Raw | ConvertFrom-Json
                if ($status.Connected -and (Read-StatusUpdatedUtc $status) -gt $SinceUtc) {
                    return $true
                }
            } catch {
                # Mid-write or unreadable: poll again.
            }
        }
        Start-Sleep -Seconds 3
    }
    return $false
}

function Wait-ServiceRunning([string]$Name, [int]$TimeoutSeconds) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
        if ($null -ne $service -and $service.Status -eq 'Running') {
            return $true
        }
        Start-Sleep -Seconds 1
    }
    return $false
}

function Set-ServiceImagePath([string]$Name, [string]$ImagePath) {
    # Goes through the SCM (ChangeServiceConfig), unlike a registry write.
    $cim = Get-CimInstance -ClassName Win32_Service -Filter "Name='$Name'"
    if ($null -eq $cim) { return }
    $result = Invoke-CimMethod -InputObject $cim -MethodName Change -Arguments @{ PathName = $ImagePath }
    if ($result.ReturnValue -ne 0) {
        throw "could not restore the $Name service command line (Win32_Service.Change $($result.ReturnValue))"
    }
}

# ---- snapshot of what is there now ------------------------------------------

$existingService = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
$previousVersion = $null
if (Test-Path -LiteralPath $exe -PathType Leaf) {
    $previousVersion = [Diagnostics.FileVersionInfo]::GetVersionInfo($exe).FileVersion
}
$previousImagePath = $null
if ($null -ne $existingService) {
    $existingCim = Get-CimInstance -ClassName Win32_Service -Filter "Name='$ServiceName'"
    if ($null -ne $existingCim) { $previousImagePath = $existingCim.PathName }
}
# The previous token stays in memory only, for a rollback.
$previousToken = $null
if (Test-Path -LiteralPath $tokenPath -PathType Leaf) {
    $previousToken = Get-Content -LiteralPath $tokenPath -Raw
}
$installingInPlace = (Test-Path -LiteralPath $InstallDir -PathType Container) -and
    ((Get-FullDirectory $PayloadDir) -eq (Get-FullDirectory $InstallDir))

if ($null -ne $existingService) {
    Write-Step "upgrading $ServiceName from $(if ($previousVersion) { $previousVersion } else { 'unknown' }) to $payloadVersion"
} else {
    Write-Step "installing $ServiceName $payloadVersion"
}

New-Item -ItemType Directory -Force -Path $InstallDir, $stateDir, $logDir | Out-Null

# ---- stop, back up ------------------------------------------------------------

$backup = $null
try {
    Stop-HeadlessService -Name $ServiceName -Directory $InstallDir
    if ($null -ne $previousVersion -and -not $installingInPlace) {
        $backup = New-InstallBackup -Directory $InstallDir -Version $previousVersion
        Write-Step "backed up $previousVersion to $backup"
    }
} catch {
    # Nothing has been changed yet: put the old service back and stop here.
    $failure = $_
    if ($null -ne $existingService -and $existingService.Status -eq 'Running') {
        Write-Warning "restarting $ServiceName on $previousVersion (no files were changed)"
        & sc.exe start $ServiceName | Out-Null
    }
    throw $failure
}

# ---- swap, register, start, verify --------------------------------------------

try {
    if (-not $installingInPlace) {
        $scriptName = Split-Path -Leaf $PSCommandPath
        Invoke-Robocopy $PayloadDir $InstallDir @('/E', '/IS', '/IT', '/XF', $scriptName)
        Write-Step "copied $payloadVersion into $InstallDir"
    }

    # The token is the client's whole identity: keep it readable by SYSTEM and
    # Administrators only, and never in the service command line where any
    # authenticated user could read it back with "sc qc".
    Set-Content -LiteralPath $tokenPath -Value $AgentToken -NoNewline -Encoding ascii
    & icacls.exe $tokenPath /inheritance:r /grant:r "SYSTEM:(R)" "Administrators:(F)" | Out-Null

    # --install-service creates the service, or updates an existing
    # registration in place (binPath, LocalSystem, automatic start, recovery
    # actions); --no-start leaves starting and verifying to this script.
    $arguments = @(
        "--install-service",
        "--no-start",
        "--service-name", $ServiceName,
        "--panel", $Panel,
        "--client-id", $ClientId.ToString(),
        "--agent-token-file", $tokenPath,
        "--status-file", $statusPath,
        "--log-file", (Join-Path $logDir "headless-.log")
    )
    if (-not [string]::IsNullOrWhiteSpace($EgressInterface)) {
        $arguments += @("--egress-interface", $EgressInterface.Trim())
    }
    & $exe @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "service registration failed with exit code $LASTEXITCODE"
    }

    $startedAtUtc = (Get-Date).ToUniversalTime()
    Write-Step "starting $ServiceName $payloadVersion"
    & sc.exe start $ServiceName | Out-Null
    if ($SkipHealthCheck) {
        if (-not (Wait-ServiceRunning -Name $ServiceName -TimeoutSeconds 60)) {
            throw "$ServiceName did not reach RUNNING within 60 s"
        }
        Write-Step "$ServiceName is RUNNING (health check skipped)"
    } else {
        Write-Step "waiting up to $HealthTimeoutSeconds s for RUNNING and a fresh connected status file"
        if (-not (Wait-HeadlessHealthy -Name $ServiceName -SinceUtc $startedAtUtc -TimeoutSeconds $HealthTimeoutSeconds)) {
            throw "$ServiceName $payloadVersion did not become healthy within $HealthTimeoutSeconds s (needs SCM RUNNING and a fresh Connected=true $statusPath); see $logDir"
        }
        Write-Step "$ServiceName $payloadVersion is RUNNING and connected"
    }
} catch {
    $failure = $_
    Write-Warning "install of $payloadVersion failed: $($failure.Exception.Message)"
    if ($null -ne $backup) {
        Write-Warning "rolling back to $previousVersion from $backup"
        try {
            Stop-HeadlessService -Name $ServiceName -Directory $InstallDir
            Invoke-Robocopy $backup $InstallDir @('/MIR', '/IS', '/IT')
            if ($null -ne $previousToken) {
                Set-Content -LiteralPath $tokenPath -Value $previousToken -NoNewline -Encoding ascii
                & icacls.exe $tokenPath /inheritance:r /grant:r "SYSTEM:(R)" "Administrators:(F)" | Out-Null
            }
            if ($null -ne $previousImagePath) {
                Set-ServiceImagePath -Name $ServiceName -ImagePath $previousImagePath
            }
            & sc.exe start $ServiceName | Out-Null
            if (Wait-ServiceRunning -Name $ServiceName -TimeoutSeconds 60) {
                Write-Warning "restored $previousVersion; $ServiceName is running the previous build again"
            } else {
                Write-Warning "restored $previousVersion but $ServiceName did not reach RUNNING; check: sc query $ServiceName"
            }
        } catch {
            Write-Warning "rollback failed: $($_.Exception.Message); the backup is still in $backup"
        }
    }
    throw $failure
}

# Make "leikwan" usable from any shell: the wrapper next to the payload calls
# this binary with --cli.
$machinePath = [Environment]::GetEnvironmentVariable("Path", "Machine")
if ($machinePath -notlike "*$InstallDir*") {
    [Environment]::SetEnvironmentVariable("Path", ($machinePath.TrimEnd(';') + ';' + $InstallDir), "Machine")
    Write-Host "cli:          added $InstallDir to the machine PATH (open a new shell to pick it up)"
}

Write-Host ""
Write-Host "service:      $ServiceName ($payloadVersion)"
Write-Host "binary:       $exe"
Write-Host "status file:  $statusPath"
Write-Host "log:          $logDir\headless-*.log (updates: $logDir\update-*.log)"
if ($null -ne $backup) {
    Write-Host "backup:       $backup"
}
Write-Host "updates:      only after ""request client update"" in the panel console; ""disable_auto_updates"": true in $stateDir\config.json refuses them"
Write-Host "cli:          leikwan status | start | stop | restart | logs | doctor | update"
Write-Host "check with:   sc query $ServiceName; Get-Content '$statusPath'"
Write-Host "remove with:  .\uninstall-windows-headless.ps1 -ServiceName $ServiceName"
