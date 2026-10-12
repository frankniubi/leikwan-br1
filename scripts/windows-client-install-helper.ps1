param(
    [ValidateSet("Install", "Uninstall")]
    [string]$Mode = "Install",
    [Parameter(Mandatory = $true)] [string]$InstallDir,
    [string]$StageDir = "",
    [string]$InstallMode = "",
    [string]$DesktopShortcut = "",
    [string]$DisplayName = "",
    [string]$AppVersion = "",
    [string]$UpdateRequested = "0"
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$InstalledHelperRelativePath = ".installer\windows-client-install-helper.ps1"

function Get-NormalizedPath {
    param([string]$Path)

    $fullPath = [System.IO.Path]::GetFullPath($Path)
    $rootPath = [System.IO.Path]::GetPathRoot($fullPath)
    if ($fullPath.Equals($rootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
        return $fullPath
    }

    $trimChars = [char[]]@(
        [System.IO.Path]::DirectorySeparatorChar,
        [System.IO.Path]::AltDirectorySeparatorChar
    )

    return $fullPath.TrimEnd($trimChars)
}

function Resolve-RequiredPath {
    param(
        [string]$Path,
        [string]$Description
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "$Description missing: $Path"
    }

    return (Get-Item -LiteralPath $Path).FullName
}

function Parse-BooleanArgument {
    param(
        [string]$Value,
        [string]$ParameterName
    )

    $normalized = if ($null -eq $Value) { "" } else { [string]$Value }
    $normalized = $normalized.Trim()
    switch -Regex ($normalized) {
        '^(?i:true|\$true|1)$' { return $true }
        '^(?i:false|\$false|0)$' { return $false }
        default { throw "$ParameterName must be one of: true, false, $true, $false, 1, 0" }
    }
}

function Get-CompatibilityFileNames {
    return @(
        "wstunnel.exe",
        "leikwan-bond.exe",
        "leikwan-ingress.exe",
        "leikwan-uot-bond.exe",
        "leikwan-mptcp-relay.exe",
        "ultra-client.exe",
        "sing-box.exe",
        "Redirector.bin",
        "netfilter2.sys",
        "nfapi.dll",
        "CN.conf"
    )
}

function Get-ExistingInstallState {
    param([string]$TargetDir)

    $statePath = Join-Path $TargetDir "install-state.json"
    if (-not (Test-Path -LiteralPath $statePath)) {
        return $null
    }

    try {
        return (Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop)
    } catch {
        throw "existing install-state.json is invalid: $($_.Exception.Message)"
    }
}

function Get-ExistingStatePropertyValue {
    param(
        [object]$State,
        [string]$PropertyName
    )

    if ($null -eq $State) {
        return $null
    }

    if ($State.PSObject.Properties.Name -contains $PropertyName) {
        return $State.$PropertyName
    }

    return $null
}

function Assert-ExistingInstallStateConsistent {
    param(
        [string]$TargetDir,
        [object]$State
    )

    if ($null -eq $State) {
        return
    }

    $declaredInstallDir = [string](Get-ExistingStatePropertyValue -State $State -PropertyName "install_dir")
    if ([string]::IsNullOrWhiteSpace($declaredInstallDir)) {
        throw "existing install-state.json is missing install_dir"
    }

    $normalizedTarget = Get-NormalizedPath -Path $TargetDir
    $normalizedDeclared = Get-NormalizedPath -Path $declaredInstallDir

    if (-not $normalizedTarget.Equals($normalizedDeclared, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "existing install-state.json install_dir mismatch: expected $normalizedTarget but found $normalizedDeclared"
    }
}

function Assert-ExistingInstallStateMatchesRequestedOptions {
    param(
        [object]$State,
        [string]$ExpectedInstallMode,
        [bool]$ExpectedDesktopShortcut,
        [bool]$RequireExistingState
    )

    if ($null -eq $State) {
        if ($RequireExistingState) {
            throw "existing install-state.json missing for silent update"
        }
        return
    }

    $existingInstallMode = [string](Get-ExistingStatePropertyValue -State $State -PropertyName "install_mode")
    if ([string]::IsNullOrWhiteSpace($existingInstallMode)) {
        throw "existing install-state.json is missing install_mode"
    }
    if (-not $existingInstallMode.Equals($ExpectedInstallMode, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "existing install-state.json install_mode mismatch: expected $ExpectedInstallMode but found $existingInstallMode"
    }

    $desktopShortcutValue = Get-ExistingStatePropertyValue -State $State -PropertyName "desktop_shortcut"
    if ($null -eq $desktopShortcutValue) {
        throw "existing install-state.json is missing desktop_shortcut"
    }

    $existingDesktopShortcut = [bool]$desktopShortcutValue
    if ($existingDesktopShortcut -ne $ExpectedDesktopShortcut) {
        throw "existing install-state.json desktop_shortcut mismatch: expected $ExpectedDesktopShortcut but found $existingDesktopShortcut"
    }
}

function Stop-InstalledProcesses {
    param([string]$TargetDir)

    $normalizedTarget = Get-NormalizedPath -Path $TargetDir
    $targetPrefix = $normalizedTarget + [System.IO.Path]::DirectorySeparatorChar

    Get-CimInstance Win32_Process | Where-Object {
        $_.ExecutablePath -and (
            $_.ExecutablePath.Equals($normalizedTarget, [System.StringComparison]::OrdinalIgnoreCase) -or
            $_.ExecutablePath.StartsWith($targetPrefix, [System.StringComparison]::OrdinalIgnoreCase)
        )
    } | ForEach-Object {
        $processId = $_.ProcessId
        $processName = if ($_.PSObject.Properties['Name']) { [string]$_.Name } else { "installed-process" }
        try {
            Stop-Process -Id $processId -Force -ErrorAction Stop
        } catch {
            Write-Warning "failed to stop process $processName [$processId]: $($_.Exception.Message)"
        }
    }

    Get-Process -Name "leikwan-client-win" -ErrorAction SilentlyContinue | ForEach-Object {
        $processId = $_.Id
        $processName = if ($_.PSObject.Properties['ProcessName']) { [string]$_.ProcessName } else { "leikwan-client-win" }
        try {
            Stop-Process -Id $processId -Force -ErrorAction Stop
        } catch {
            Write-Warning "failed to stop process $processName [$processId]: $($_.Exception.Message)"
        }
    }

    Start-Sleep -Milliseconds 800
}

function Copy-StagedPayload {
    param(
        [string]$PayloadRoot,
        [string]$TargetDir
    )

    Resolve-RequiredPath -Path $PayloadRoot -Description "staged payload root" | Out-Null
    New-Item -ItemType Directory -Path $TargetDir -Force | Out-Null

    foreach ($entry in Get-ChildItem -LiteralPath $PayloadRoot -Force) {
        $destination = Join-Path $TargetDir $entry.Name
        if ($entry.PSIsContainer) {
            New-Item -ItemType Directory -Path $destination -Force | Out-Null
            foreach ($child in Get-ChildItem -LiteralPath $entry.FullName -Force) {
                Copy-Item -LiteralPath $child.FullName -Destination (Join-Path $destination $child.Name) -Recurse -Force
            }
        } else {
            Copy-Item -LiteralPath $entry.FullName -Destination $destination -Force
        }
    }
}

function New-InstallSnapshot {
    param([string]$TargetDir)

    if (-not (Test-Path -LiteralPath $TargetDir)) {
        return [pscustomobject]@{
            Exists = $false
            Path = ""
        }
    }

    $snapshotRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("leikwan-install-snapshot-" + [guid]::NewGuid().ToString("N"))
    $snapshotPayload = Join-Path $snapshotRoot "payload"
    New-Item -ItemType Directory -Path $snapshotPayload -Force | Out-Null
    Copy-Item -LiteralPath $TargetDir -Destination $snapshotPayload -Recurse -Force

    return [pscustomobject]@{
        Exists = $true
        Path = $snapshotRoot
    }
}

function Restore-InstallSnapshot {
    param(
        [string]$TargetDir,
        [object]$Snapshot
    )

    if ($null -eq $Snapshot) {
        throw "install snapshot is missing"
    }

    if (Test-Path -LiteralPath $TargetDir) {
        Remove-Item -LiteralPath $TargetDir -Recurse -Force
    }

    if (-not [bool]$Snapshot.Exists) {
        return
    }

    $snapshotPayload = Join-Path ([string]$Snapshot.Path) "payload"
    if (-not (Test-Path -LiteralPath $snapshotPayload)) {
        throw "install snapshot payload is missing"
    }

    $snapshotInstall = Get-ChildItem -LiteralPath $snapshotPayload -Force | Select-Object -First 1
    if ($null -eq $snapshotInstall) {
        throw "install snapshot payload is empty"
    }

    New-Item -ItemType Directory -Path $TargetDir -Force | Out-Null
    foreach ($entry in Get-ChildItem -LiteralPath $snapshotInstall.FullName -Force) {
        Copy-Item -LiteralPath $entry.FullName -Destination (Join-Path $TargetDir $entry.Name) -Recurse -Force
    }
}

function Remove-InstallSnapshot {
    param([object]$Snapshot)

    if ($null -ne $Snapshot -and [bool]$Snapshot.Exists -and -not [string]::IsNullOrWhiteSpace([string]$Snapshot.Path)) {
        Remove-Item -LiteralPath ([string]$Snapshot.Path) -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Write-CompatibilityCopies {
    param([string]$TargetDir)

    $targetBinDir = Join-Path $TargetDir "bin"
    foreach ($fileName in Get-CompatibilityFileNames) {
        $sourcePath = Join-Path $targetBinDir $fileName
        if (-not (Test-Path -LiteralPath $sourcePath)) {
            throw "required staged binary missing after copy: $sourcePath"
        }

        Copy-Item -LiteralPath $sourcePath -Destination (Join-Path $TargetDir $fileName) -Force
    }
}

function Install-EmbeddedHelper {
    param([string]$TargetDir)

    $installerDir = Join-Path $TargetDir ".installer"
    New-Item -ItemType Directory -Path $installerDir -Force | Out-Null
    Copy-Item -LiteralPath $PSCommandPath -Destination (Join-Path $installerDir "windows-client-install-helper.ps1") -Force
}

function Resolve-DesktopDir {
    param(
        [string]$EffectiveInstallMode,
        [bool]$UseOverrideForCurrentUser = $true
    )

    if ([string]::Equals($EffectiveInstallMode, "all-users", [System.StringComparison]::OrdinalIgnoreCase)) {
        if (-not [string]::IsNullOrWhiteSpace($env:LEIKWAN_COMMON_DESKTOP_DIR)) {
            return $env:LEIKWAN_COMMON_DESKTOP_DIR
        }

        return [Environment]::GetFolderPath([System.Environment+SpecialFolder]::CommonDesktopDirectory)
    }

    if ($UseOverrideForCurrentUser) {
        if (-not [string]::IsNullOrWhiteSpace($env:LEIKWAN_RELAUNCH_DESKTOP_DIR)) {
            return $env:LEIKWAN_RELAUNCH_DESKTOP_DIR
        }

        if (-not [string]::IsNullOrWhiteSpace($env:LEIKWAN_DESKTOP_DIR)) {
            return $env:LEIKWAN_DESKTOP_DIR
        }
    }

    return [Environment]::GetFolderPath([System.Environment+SpecialFolder]::DesktopDirectory)
}

function Remove-DesktopShortcutVariants {
    param([string[]]$DesktopDirs)

    foreach ($desktopDir in $DesktopDirs) {
        if ([string]::IsNullOrWhiteSpace($desktopDir)) {
            continue
        }

        $shortcutPath = Join-Path $desktopDir "LeiKwan Bridge.lnk"
        Remove-Item -LiteralPath $shortcutPath -Force -ErrorAction SilentlyContinue
    }
}

function Ensure-DesktopShortcutState {
    param(
        [string]$TargetDir,
        [bool]$ShouldExist,
        [string]$ShortcutDisplayName,
        [string]$EffectiveInstallMode,
        [bool]$UseOverrideForCurrentUser = $true
    )

    $desktopDir = Resolve-DesktopDir -EffectiveInstallMode $EffectiveInstallMode -UseOverrideForCurrentUser $UseOverrideForCurrentUser
    if ([string]::IsNullOrWhiteSpace($desktopDir)) {
        Write-Warning "desktop directory unavailable; skipping shortcut management"
        return
    }

    if (-not $ShouldExist) {
        $desktopDirs = @($desktopDir)
        if ([string]::Equals($EffectiveInstallMode, "all-users", [System.StringComparison]::OrdinalIgnoreCase)) {
            $desktopDirs += [Environment]::GetFolderPath([System.Environment+SpecialFolder]::DesktopDirectory)
        }
        Remove-DesktopShortcutVariants -DesktopDirs ($desktopDirs | Select-Object -Unique)
        return
    }

    New-Item -ItemType Directory -Path $desktopDir -Force | Out-Null
    $shortcutPath = Join-Path $desktopDir "LeiKwan Bridge.lnk"
    $targetPath = Join-Path $TargetDir "leikwan-client-win.exe"
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($shortcutPath)
    $shortcut.TargetPath = $targetPath
    $shortcut.WorkingDirectory = $TargetDir
    $shortcut.IconLocation = $targetPath + ",0"
    $shortcut.Description = $ShortcutDisplayName
    $shortcut.Save()
}

function Write-JsonFile {
    param(
        [string]$Path,
        [object]$Payload
    )

    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText(
        $Path,
        ($Payload | ConvertTo-Json -Depth 6),
        $utf8NoBom
    )
}

function Get-RelativePath {
    param(
        [string]$BasePath,
        [string]$TargetPath
    )

    $normalizedBase = Get-NormalizedPath -Path $BasePath
    $normalizedTarget = Get-NormalizedPath -Path $TargetPath
    $baseUri = New-Object System.Uri(($normalizedBase + [System.IO.Path]::DirectorySeparatorChar))
    $targetUri = New-Object System.Uri($normalizedTarget)
    return [System.Uri]::UnescapeDataString($baseUri.MakeRelativeUri($targetUri).ToString()).Replace('/', '\')
}

function Get-StagedPayloadRelativeFiles {
    param([string]$PayloadRoot)

    $files = New-Object System.Collections.Generic.List[string]
    foreach ($file in Get-ChildItem -LiteralPath $PayloadRoot -File -Recurse) {
        $relativePath = Get-RelativePath -BasePath $PayloadRoot -TargetPath $file.FullName
        $files.Add($relativePath)
    }

    foreach ($fileName in Get-CompatibilityFileNames) {
        $files.Add($fileName)
    }

    $files.Add($InstalledHelperRelativePath)
    $files.Add("install-manifest.json")
    $files.Add("install-state.json")

    return $files | Sort-Object -Unique
}

function Write-InstallManifest {
    param(
        [string]$TargetDir,
        [string]$BackupDir,
        [string[]]$InstalledFiles
    )

    $targetBinDir = Join-Path $TargetDir "bin"
    $manifest = [ordered]@{
        installed_at = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        source = "leikwan-bridge-setup.exe"
        app_sha256 = (Get-FileHash (Join-Path $TargetDir "leikwan-client-win.exe") -Algorithm SHA256).Hash
        bond_sha256 = (Get-FileHash (Join-Path $targetBinDir "leikwan-bond.exe") -Algorithm SHA256).Hash
        ingress_sha256 = (Get-FileHash (Join-Path $targetBinDir "leikwan-ingress.exe") -Algorithm SHA256).Hash
        uot_bond_sha256 = (Get-FileHash (Join-Path $targetBinDir "leikwan-uot-bond.exe") -Algorithm SHA256).Hash
        mptcp_relay_sha256 = (Get-FileHash (Join-Path $targetBinDir "leikwan-mptcp-relay.exe") -Algorithm SHA256).Hash
        ultra_client_sha256 = (Get-FileHash (Join-Path $targetBinDir "ultra-client.exe") -Algorithm SHA256).Hash
        wstunnel_sha256 = (Get-FileHash (Join-Path $targetBinDir "wstunnel.exe") -Algorithm SHA256).Hash
        backup_dir = $BackupDir
        installed_files = $InstalledFiles
    }

    Write-JsonFile -Path (Join-Path $TargetDir "install-manifest.json") -Payload $manifest
}

function Write-InstallState {
    param(
        [string]$TargetDir,
        [string]$SelectedInstallMode,
        [bool]$SelectedDesktopShortcut,
        [string]$SelectedDisplayName,
        [string]$SelectedAppVersion
    )

    $state = [ordered]@{
        install_mode = $SelectedInstallMode
        install_dir = $TargetDir
        desktop_shortcut = $SelectedDesktopShortcut
        display_name = $SelectedDisplayName
        installed_version = $SelectedAppVersion
        installed_at = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
    }

    Write-JsonFile -Path (Join-Path $TargetDir "install-state.json") -Payload $state
}

function Get-FallbackInstalledFiles {
    return @(
        "leikwan-client-win.exe",
        "LeikwanBridge.App.dll",
        "LeikwanBridge.App.deps.json",
        "LeikwanBridge.App.pdb",
        "LeikwanBridge.App.runtimeconfig.json",
        "LeikwanBridge.Core.dll",
        "LeikwanBridge.Core.pdb",
        "CommunityToolkit.Mvvm.dll",
        "Serilog.dll",
        "Serilog.Sinks.File.dll",
        "appsettings.json",
        "wstunnel.exe",
        "leikwan-bond.exe",
        "leikwan-ingress.exe",
        "leikwan-uot-bond.exe",
        "leikwan-mptcp-relay.exe",
        "ultra-client.exe",
        "sing-box.exe",
        "Redirector.bin",
        "netfilter2.sys",
        "nfapi.dll",
        "bin\wstunnel.exe",
        "bin\leikwan-bond.exe",
        "bin\leikwan-ingress.exe",
        "bin\leikwan-uot-bond.exe",
        "bin\leikwan-mptcp-relay.exe",
        "bin\ultra-client.exe",
        "bin\sing-box.exe",
        "bin\Redirector.bin",
        "bin\netfilter2.sys",
        "bin\nfapi.dll",
        "bin\CN.conf",
        "bin\geoip-cn.srs",
        "bin\geosite-cn.srs",
        $InstalledHelperRelativePath,
        "install-manifest.json",
        "install-state.json"
    )
}

function Get-InstalledFilesFromManifest {
    param([string]$TargetDir)

    $manifestPath = Join-Path $TargetDir "install-manifest.json"
    if (-not (Test-Path -LiteralPath $manifestPath)) {
        return Get-FallbackInstalledFiles
    }

    try {
        $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop
        if ($manifest.PSObject.Properties.Name -contains "installed_files" -and $manifest.installed_files) {
            return @($manifest.installed_files | ForEach-Object { [string]$_ })
        }
    } catch {
        Write-Warning "failed to parse install-manifest.json during uninstall: $($_.Exception.Message)"
    }

    return Get-FallbackInstalledFiles
}

function Remove-StaleInstallerOwnedFiles {
    param(
        [string]$TargetDir,
        [string[]]$PreviousInstalledFiles,
        [string[]]$CurrentInstalledFiles
    )

    $currentSet = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
    foreach ($relativePath in $CurrentInstalledFiles) {
        if (-not [string]::IsNullOrWhiteSpace($relativePath)) {
            [void]$currentSet.Add([string]$relativePath)
        }
    }

    foreach ($relativePath in $PreviousInstalledFiles) {
        if ([string]::IsNullOrWhiteSpace($relativePath)) {
            continue
        }
        if ($currentSet.Contains($relativePath)) {
            continue
        }

        $candidatePath = Join-Path $TargetDir $relativePath
        if (-not (Test-Path -LiteralPath $candidatePath)) {
            continue
        }
        if (-not (Is-UnderInstallRoot -TargetDir $TargetDir -CandidatePath $candidatePath)) {
            Write-Warning "skipping stale manifest path outside install root: $relativePath"
            continue
        }

        Remove-Item -LiteralPath $candidatePath -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Remove-ClientBackupDirectories {
    param([string]$TargetDir)

    if (-not (Test-Path -LiteralPath $TargetDir)) {
        return
    }

    Get-ChildItem -LiteralPath $TargetDir -Directory -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -like 'backup-*' } |
        ForEach-Object {
            Remove-Item -LiteralPath $_.FullName -Recurse -Force -ErrorAction SilentlyContinue
            if (Test-Path -LiteralPath $_.FullName) {
                throw "client backup directory could not be removed: $($_.FullName)"
            }
        }
}

function Is-UnderInstallRoot {
    param(
        [string]$TargetDir,
        [string]$CandidatePath
    )

    $normalizedTargetDir = Get-NormalizedPath -Path $TargetDir
    $normalizedCandidatePath = Get-NormalizedPath -Path $CandidatePath
    if ($normalizedCandidatePath.Equals($normalizedTargetDir, [System.StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    $prefix = $normalizedTargetDir + [System.IO.Path]::DirectorySeparatorChar
    return $normalizedCandidatePath.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)
}

function Remove-InstallerOwnedFiles {
    param(
        [string]$TargetDir,
        [string[]]$InstalledFiles
    )

    $currentHelperPath = Get-NormalizedPath -Path $PSCommandPath

    foreach ($relativePath in $InstalledFiles) {
        if ([string]::IsNullOrWhiteSpace($relativePath)) {
            continue
        }

        $candidatePath = Join-Path $TargetDir $relativePath
        if (-not (Test-Path -LiteralPath $candidatePath)) {
            continue
        }

        if (-not (Is-UnderInstallRoot -TargetDir $TargetDir -CandidatePath $candidatePath)) {
            Write-Warning "skipping manifest path outside install root: $relativePath"
            continue
        }

        $normalizedCandidatePath = Get-NormalizedPath -Path $candidatePath
        if ($normalizedCandidatePath.Equals($currentHelperPath, [System.StringComparison]::OrdinalIgnoreCase)) {
            continue
        }

        Remove-Item -LiteralPath $candidatePath -Recurse -Force -ErrorAction SilentlyContinue
        if (Test-Path -LiteralPath $candidatePath) {
            throw "installer-owned file could not be removed: $candidatePath"
        }
    }

    Remove-ClientBackupDirectories -TargetDir $TargetDir
}

function Remove-EmptyDirectoryIfPossible {
    param([string]$DirectoryPath)

    if (-not (Test-Path -LiteralPath $DirectoryPath)) {
        return
    }

    $items = @(Get-ChildItem -LiteralPath $DirectoryPath -Force -ErrorAction SilentlyContinue)
    if ($items.Count -eq 0) {
        Remove-Item -LiteralPath $DirectoryPath -Force -ErrorAction SilentlyContinue
    }
}

function Schedule-InstalledHelperCleanup {
    param([string]$TargetDir)

    $helperDir = Join-Path $TargetDir ".installer"
    $helperPath = Join-Path $helperDir "windows-client-install-helper.ps1"
    $cleanupCommand = 'ping 127.0.0.1 -n 3 >nul & del /f /q "' + $helperPath + '" >nul 2>nul & rmdir /s /q "' + $helperDir + '" >nul 2>nul'
    Start-Process -FilePath $env:ComSpec -ArgumentList '/c', $cleanupCommand -WindowStyle Hidden | Out-Null
}

function Get-UninstallRegistryRoots {
    return @(
        "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall",
        "HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall",
        "HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"
    )
}

function Test-LeiKwanUninstallEntry {
    param([object]$Entry)

    $names = $Entry.PSObject.Properties.Name
    if ($names -notcontains "DisplayName") { return $false }
    $displayName = [string]$Entry.DisplayName
    if ([string]::IsNullOrWhiteSpace($displayName)) { return $false }
    if ($displayName -match "LeiKwan") { return $true }
    if ($displayName -match "Leikwan") { return $true }
    return $displayName.Contains(([string][char]0x5229) + ([string][char]0x7FA4))
}

function Get-UninstallEntryTargetPath {
    param([object]$Entry)

    $quote = [string][char]34
    foreach ($propertyName in @("InstallLocation", "DisplayIcon", "UninstallString")) {
        if ($Entry.PSObject.Properties.Name -notcontains $propertyName) { continue }
        $raw = [string]$Entry.$propertyName
        if ([string]::IsNullOrWhiteSpace($raw)) { continue }
        $candidate = $raw.Trim()
        if ($candidate.StartsWith($quote)) {
            $closingQuote = $candidate.IndexOf($quote, 1)
            if ($closingQuote -gt 0) { $candidate = $candidate.Substring(1, $closingQuote - 1) } else { $candidate = $candidate.TrimStart([char]34) }
        } elseif ($candidate.Contains(" ")) {
            $exeMatch = [regex]::Match($candidate, "^(.+?\.exe)\b", [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
            if ($exeMatch.Success) { $candidate = $exeMatch.Groups[1].Value }
        }
        $candidate = $candidate.Trim()
        if ($candidate.Length -eq 0) { continue }
        if (-not [System.IO.Path]::IsPathRooted($candidate)) { continue }
        try { $fullPath = [System.IO.Path]::GetFullPath($candidate) } catch { continue }
        if ([System.IO.Path]::GetExtension($fullPath).Equals(".exe", [System.StringComparison]::OrdinalIgnoreCase)) {
            $fullPath = [System.IO.Path]::GetDirectoryName($fullPath)
        }
        if (-not [string]::IsNullOrWhiteSpace($fullPath)) { return $fullPath }
    }
    return ""
}

# %ProgramData%\Leikwan is shared by the client and its installer. A file that
# an elevated process creates there can inherit the ProgramData root ACL
# (Administrators full control, Users read-only) instead of the folder ACL, and
# the client then dies writing its own config on startup - before it logs
# anything - until somebody starts it as administrator. Re-apply the intended
# rights to everything the client has to write on every install and update so
# machines that already carry the broken ACL heal themselves.
function Repair-SharedDataAcl {
    param([string]$TargetDir)

    $programData = [Environment]::GetEnvironmentVariable("ProgramData")
    if ([string]::IsNullOrWhiteSpace($programData)) { return 0 }

    $clientAgentDir = Join-Path $programData "Leikwan\ClientAgent"
    if (-not (Test-Path -LiteralPath $clientAgentDir)) { return 0 }

    $usersSid = "*S-1-5-32-545"
    $writablePaths = @(
        (Join-Path $clientAgentDir "config.json"),
        (Join-Path $clientAgentDir "state"),
        (Join-Path $clientAgentDir "logs")
    )

    $repaired = 0
    foreach ($path in $writablePaths) {
        if (-not (Test-Path -LiteralPath $path)) { continue }
        $item = Get-Item -LiteralPath $path -Force
        $arguments = @($path, "/grant", ($usersSid + $(if ($item.PSIsContainer) { ":(OI)(CI)M" } else { ":M" })), "/C", "/Q")
        if ($item.PSIsContainer) { $arguments += "/T" }
        $output = & icacls.exe @arguments 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Warning "failed to repair ACL on $path : $($output -join " ")"
            continue
        }
        $repaired++
    }

    # The legacy helper binary lives here and nothing the interactive user runs
    # needs to write it: the client only reads and executes it, and every update
    # replaces it from this elevated helper. Inherited write access let any local
    # process swap the binary the client executes, so narrow it to read+execute.
    $runtimeDir = Join-Path $clientAgentDir "runtime"
    if (Test-Path -LiteralPath $runtimeDir) {
        # Two steps on purpose. Granting "(OI)(CI)" straight at a file leaves the
        # file with an empty DACL when /T is combined with /inheritance:r, so the
        # directory gets explicit inheritable ACEs first and every child is then
        # reset to inherit exactly those.
        $runtimeOutput = & icacls.exe $runtimeDir /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-32-545:(OI)(CI)RX" /C /Q 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Warning "failed to restrict $runtimeDir : $($runtimeOutput -join " ")"
        } else {
            $childOutput = & icacls.exe (Join-Path $runtimeDir "*") /reset /T /C /Q 2>&1
            if ($LASTEXITCODE -ne 0) {
                Write-Warning "failed to re-inherit children of $runtimeDir : $($childOutput -join " ")"
            } else {
                Write-Host "restricted $runtimeDir to read+execute for users"
                $repaired++
            }
        }
    }

    if ($repaired -gt 0) { Write-Host "repaired $repaired shared client data path(s) under $clientAgentDir" }
    return $repaired
}

# A silent update exits the client before the installer runs, and nothing used
# to start it again: the download finished, the window vanished and the machine
# was left with no client at all - "the update reaches 100% and then nothing
# happens". Launch it through Explorer so the new process inherits the
# interactive user token: starting it straight from this elevated helper would
# hand the client an administrator token, and it would then write config and
# logs that the user can no longer modify.
function Start-InstalledClient {
    param([string]$TargetDir)

    $exePath = Join-Path $TargetDir "leikwan-client-win.exe"
    if (-not (Test-Path -LiteralPath $exePath)) { return $false }

    if (Get-Process -Name "leikwan-client-win" -ErrorAction SilentlyContinue) {
        Write-Host "client already running; not starting a second instance"
        return $false
    }

    $systemRoot = [Environment]::GetEnvironmentVariable("SystemRoot")
    if ([string]::IsNullOrWhiteSpace($systemRoot)) { return $false }
    $explorerPath = Join-Path $systemRoot "explorer.exe"
    if (-not (Test-Path -LiteralPath $explorerPath)) { return $false }

    Start-Process -FilePath $explorerPath -ArgumentList @($exePath) | Out-Null

    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Milliseconds 500
        if (Get-Process -Name "leikwan-client-win" -ErrorAction SilentlyContinue) {
            Write-Host "restarted the client in the interactive session"
            return $true
        }
    }

    Write-Warning "the client did not come back within 15s after the update"
    return $false
}

function Test-RealInstall {
    param([string]$DirectoryPath)

    if ([string]::IsNullOrWhiteSpace($DirectoryPath)) { return $false }
    if (-not (Test-Path -LiteralPath $DirectoryPath)) { return $false }
    if (Test-Path -LiteralPath (Join-Path $DirectoryPath "leikwan-client-win.exe")) { return $true }
    return (Test-Path -LiteralPath (Join-Path $DirectoryPath "install-state.json"))
}

function Get-DuplicateInstallCandidates {
    param([string]$TargetDir)

    $normalizedTarget = Get-NormalizedPath -Path $TargetDir
    $candidates = New-Object System.Collections.Generic.List[string]
    $seen = New-Object System.Collections.Generic.HashSet[string]([System.StringComparer]::OrdinalIgnoreCase)
    $knownDirs = New-Object System.Collections.Generic.List[string]

    $knownDirs.Add((Join-Path $env:LOCALAPPDATA "LeiKwan Bridge"))
    $knownDirs.Add((Join-Path $env:LOCALAPPDATA "Programs\LeiKwan Bridge"))
    foreach ($variableName in @("ProgramFiles", "ProgramFiles(x86)", "ProgramW6432")) {
        $root = [Environment]::GetEnvironmentVariable($variableName)
        if (-not [string]::IsNullOrWhiteSpace($root)) { $knownDirs.Add((Join-Path $root "LeiKwan Bridge")) }
    }

    foreach ($root in Get-UninstallRegistryRoots) {
        if (-not (Test-Path -LiteralPath $root)) { continue }
        foreach ($key in Get-ChildItem -LiteralPath $root -ErrorAction SilentlyContinue) {
            $entry = Get-ItemProperty -LiteralPath $key.PSPath -ErrorAction SilentlyContinue
            if ($null -eq $entry) { continue }
            if (-not (Test-LeiKwanUninstallEntry -Entry $entry)) { continue }
            $entryDir = Get-UninstallEntryTargetPath -Entry $entry
            if (-not [string]::IsNullOrWhiteSpace($entryDir)) { $knownDirs.Add($entryDir) }
        }
    }

    foreach ($dir in $knownDirs) {
        if ([string]::IsNullOrWhiteSpace($dir)) { continue }
        try { $normalized = Get-NormalizedPath -Path $dir } catch { continue }
        if ($normalized.Equals($normalizedTarget, [System.StringComparison]::OrdinalIgnoreCase)) { continue }
        if (-not (Test-Path -LiteralPath $normalized)) { continue }
        if ($seen.Add($normalized)) { $candidates.Add($normalized) }
    }

    return $candidates
}

function Remove-DuplicateInstallDirectory {
    param(
        [string]$DirectoryPath,
        [string]$TargetDir
    )

    $normalized = Get-NormalizedPath -Path $DirectoryPath
    $normalizedTarget = Get-NormalizedPath -Path $TargetDir
    if ($normalized.Equals($normalizedTarget, [System.StringComparison]::OrdinalIgnoreCase)) { return $false }
    if (Is-UnderInstallRoot -TargetDir $normalized -CandidatePath $normalizedTarget) {
        Write-Warning "duplicate sweep skipped a parent directory of the live install: $normalized"
        return $false
    }

    Write-Host "removing duplicate LeiKwan Bridge install: $normalized"
    $innoUninstaller = Join-Path $normalized "unins000.exe"
    if (Test-Path -LiteralPath $innoUninstaller) {
        try {
            $process = Start-Process -FilePath $innoUninstaller -ArgumentList @("/VERYSILENT", "/SUPPRESSMSGBOXES", "/NORESTART") -Wait -PassThru
            Write-Host "duplicate uninstaller exit code: $($process.ExitCode)"
        } catch {
            Write-Warning "duplicate uninstaller failed for $normalized : $($_.Exception.Message)"
        }
    }

    if (-not (Test-RealInstall -DirectoryPath $normalized)) {
        return -not (Test-Path -LiteralPath $normalized)
    }

    try { Stop-InstalledProcesses -TargetDir $normalized } catch { Write-Warning "failed to stop duplicate install processes: $($_.Exception.Message)" }

    # The installer download cache lives inside the legacy per-user directory.
    # It is still used by the running client, so it must survive the sweep.
    $updatesDir = (Join-Path $normalized "updates") + [System.IO.Path]::DirectorySeparatorChar
    Get-ChildItem -LiteralPath $normalized -Recurse -Force -File -ErrorAction SilentlyContinue |
        Where-Object { -not $_.FullName.StartsWith($updatesDir, [System.StringComparison]::OrdinalIgnoreCase) } |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force -ErrorAction SilentlyContinue }
    Get-ChildItem -LiteralPath $normalized -Recurse -Force -Directory -ErrorAction SilentlyContinue |
        Sort-Object { $_.FullName.Length } -Descending |
        ForEach-Object { Remove-EmptyDirectoryIfPossible -DirectoryPath $_.FullName }
    Remove-EmptyDirectoryIfPossible -DirectoryPath $normalized

    $cleaned = -not (Test-RealInstall -DirectoryPath $normalized)
    if ($cleaned) { Write-Host "duplicate install files removed: $normalized" } else { Write-Warning "duplicate install still present after sweep: $normalized" }
    return $cleaned
}

function Remove-GhostUninstallEntries {
    param([string]$TargetDir)

    $normalizedTarget = Get-NormalizedPath -Path $TargetDir
    $removedCount = 0

    foreach ($root in Get-UninstallRegistryRoots) {
        if (-not (Test-Path -LiteralPath $root)) { continue }
        foreach ($key in Get-ChildItem -LiteralPath $root -ErrorAction SilentlyContinue) {
            $entry = Get-ItemProperty -LiteralPath $key.PSPath -ErrorAction SilentlyContinue
            if ($null -eq $entry) { continue }
            if (-not (Test-LeiKwanUninstallEntry -Entry $entry)) { continue }

            $entryDir = Get-UninstallEntryTargetPath -Entry $entry
            if (-not [string]::IsNullOrWhiteSpace($entryDir)) {
                try { $entryDir = Get-NormalizedPath -Path $entryDir } catch { $entryDir = "" }
            }

            if ([string]::IsNullOrWhiteSpace($entryDir)) {
                # No usable location in the entry: keep it only when the live
                # install is intact, so the user can still uninstall us.
                if (Test-RealInstall -DirectoryPath $normalizedTarget) { continue }
            } elseif ($entryDir.Equals($normalizedTarget, [System.StringComparison]::OrdinalIgnoreCase)) {
                if (Test-RealInstall -DirectoryPath $normalizedTarget) { continue }
            } elseif (Test-RealInstall -DirectoryPath $entryDir) {
                # A real second install that survived the sweep: keep its entry
                # so the user can still remove it from Apps & features.
                continue
            }

            try {
                Remove-Item -LiteralPath $key.PSPath -Recurse -Force -ErrorAction Stop
                Write-Host "removed stale uninstall entry: $($key.PSPath)"
                $removedCount++
            } catch {
                Write-Warning "failed to remove stale uninstall entry $($key.PSPath): $($_.Exception.Message)"
            }
        }
    }

    return $removedCount
}

# Runtime data left behind by the legacy per-machine client agent keeps its
# own copy of the helper binary. It is not an install directory, so the
# duplicate sweep cannot see it, yet a stale copy there is invisible to the
# update: the client keeps launching an old helper and the update check keeps
# reporting runtime drift for the update it just installed.
function Update-LegacyRuntimeCopies {
    param([string]$TargetDir)

    $installedRuntime = Join-Path $TargetDir "bin\ultra-client.exe"
    if (-not (Test-Path -LiteralPath $installedRuntime)) { return 0 }

    $programData = [Environment]::GetEnvironmentVariable("ProgramData")
    if ([string]::IsNullOrWhiteSpace($programData)) { return 0 }

    $refreshedCount = 0
    $legacyDirs = @(
        (Join-Path $programData "Leikwan\ClientAgent\runtime"),
        (Join-Path $programData "LeiKwan\ClientAgent\runtime")
    )

    foreach ($legacyDir in $legacyDirs) {
        $legacyRuntime = Join-Path $legacyDir "ultra-client.exe"
        if (-not (Test-Path -LiteralPath $legacyRuntime)) { continue }

        Get-CimInstance Win32_Process | Where-Object {
            $_.ExecutablePath -and $_.ExecutablePath.Equals($legacyRuntime, [System.StringComparison]::OrdinalIgnoreCase)
        } | ForEach-Object {
            try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch { Write-Warning "failed to stop legacy runtime [$($_.ProcessId)]: $($_.Exception.Message)" }
        }
        Start-Sleep -Milliseconds 400

        try {
            Copy-Item -LiteralPath $installedRuntime -Destination $legacyRuntime -Force -ErrorAction Stop
            Write-Host "refreshed legacy runtime copy: $legacyRuntime"
            $refreshedCount++
        } catch {
            Write-Warning "failed to refresh legacy runtime copy $legacyRuntime : $($_.Exception.Message)"
        }

        Get-ChildItem -LiteralPath $legacyDir -Force -File -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -like "ultra-client.exe.tmp-*" } |
            ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force -ErrorAction SilentlyContinue }
    }

    return $refreshedCount
}

function Invoke-DuplicateInstallSweep {
    param([string]$TargetDir)

    $removedCount = 0
    foreach ($candidate in @(Get-DuplicateInstallCandidates -TargetDir $TargetDir)) {
        try {
            if (Remove-DuplicateInstallDirectory -DirectoryPath $candidate -TargetDir $TargetDir) { $removedCount++ }
        } catch {
            Write-Warning "duplicate install sweep failed for $candidate : $($_.Exception.Message)"
        }
    }

    try { $removedCount += Remove-GhostUninstallEntries -TargetDir $TargetDir } catch { Write-Warning "stale uninstall entry sweep failed: $($_.Exception.Message)" }
    try { Update-LegacyRuntimeCopies -TargetDir $TargetDir | Out-Null } catch { Write-Warning "legacy runtime refresh failed: $($_.Exception.Message)" }

    if ($removedCount -gt 0) { Write-Host "duplicate install sweep removed $removedCount stale item(s)" }
    return $removedCount
}function Invoke-InstallMode {
    if ([string]::IsNullOrWhiteSpace($StageDir)) {
        throw "StageDir is required for install mode"
    }
    if ([string]::IsNullOrWhiteSpace($InstallMode)) {
        throw "InstallMode is required for install mode"
    }
    if ([string]::IsNullOrWhiteSpace($DesktopShortcut)) {
        throw "DesktopShortcut is required for install mode"
    }
    if ([string]::IsNullOrWhiteSpace($DisplayName)) {
        throw "DisplayName is required for install mode"
    }
    if ([string]::IsNullOrWhiteSpace($AppVersion)) {
        throw "AppVersion is required for install mode"
    }

    $selectedDesktopShortcut = Parse-BooleanArgument -Value $DesktopShortcut -ParameterName "DesktopShortcut"
    $updateRequested = Parse-BooleanArgument -Value $UpdateRequested -ParameterName "UpdateRequested"
    $normalizedStageDir = Get-NormalizedPath -Path $StageDir
    $payloadRoot = Join-Path $normalizedStageDir "app"

    Resolve-RequiredPath -Path $payloadRoot -Description "staged payload root" | Out-Null

    $existingState = Get-ExistingInstallState -TargetDir $InstallDir
    Assert-ExistingInstallStateConsistent -TargetDir $InstallDir -State $existingState
    if ($updateRequested) {
        Assert-ExistingInstallStateMatchesRequestedOptions -State $existingState -ExpectedInstallMode $InstallMode -ExpectedDesktopShortcut $selectedDesktopShortcut -RequireExistingState $true
    }

    $previousInstalledFiles = Get-InstalledFilesFromManifest -TargetDir $InstallDir
    $currentInstalledFiles = Get-StagedPayloadRelativeFiles -PayloadRoot $payloadRoot

    $snapshot = New-InstallSnapshot -TargetDir $InstallDir
    try {
        Stop-InstalledProcesses -TargetDir $InstallDir
        # Repair before copying: a client whose shared config is read-only
        # cannot start at all, and that must be fixed even if this install
        # later fails and rolls back.
        try {
            Repair-SharedDataAcl -TargetDir $InstallDir | Out-Null
        } catch {
            Write-Warning "shared data ACL repair failed: $($_.Exception.Message)"
        }
        Remove-ClientBackupDirectories -TargetDir $InstallDir
        # Client updates replace installer-owned files in place. Development-side
        # artifacts may keep backups, but the installed client must not create them.
        $backupDir = ""
        Remove-StaleInstallerOwnedFiles -TargetDir $InstallDir -PreviousInstalledFiles $previousInstalledFiles -CurrentInstalledFiles $currentInstalledFiles
        Copy-StagedPayload -PayloadRoot $payloadRoot -TargetDir $InstallDir
        Write-CompatibilityCopies -TargetDir $InstallDir
        Install-EmbeddedHelper -TargetDir $InstallDir
        Ensure-DesktopShortcutState -TargetDir $InstallDir -ShouldExist $selectedDesktopShortcut -ShortcutDisplayName $DisplayName -EffectiveInstallMode $InstallMode
        $installedFiles = $currentInstalledFiles
        Write-InstallManifest -TargetDir $InstallDir -BackupDir $backupDir -InstalledFiles $installedFiles
        Write-InstallState -TargetDir $InstallDir -SelectedInstallMode $InstallMode -SelectedDesktopShortcut $selectedDesktopShortcut -SelectedDisplayName $DisplayName -SelectedAppVersion $AppVersion

        Write-Host "Installed LeiKwan Bridge to $InstallDir"
        $repairToolPath = Join-Path $InstallDir "leikwan-bridge-repair.cmd"
        if (Test-Path -LiteralPath $repairToolPath) {
            Write-Host "Repair/update helper: $repairToolPath (run it as administrator to fix permissions and update)"
        }
    } catch {
        $installError = $_
        try {
            Restore-InstallSnapshot -TargetDir $InstallDir -Snapshot $snapshot
        } catch {
            throw "install failed and rollback failed: $($installError.Exception.Message); rollback: $($_.Exception.Message)"
        }
        throw "install failed; previous installation restored: $($installError.Exception.Message)"
    } finally {
        Remove-InstallSnapshot -Snapshot $snapshot
    }

    # Older builds could leave a second install behind: a leftover all-users
    # copy, a pre-migration per-user copy, or a dead Apps & features entry
    # whose files are already gone. They surface as a duplicate product and
    # they keep stale runtimes alive after an update, so every interactive
    # install and every silent update sweeps them away.
    try {
        Invoke-DuplicateInstallSweep -TargetDir $InstallDir | Out-Null
    } catch {
        Write-Warning "duplicate install sweep failed: $($_.Exception.Message)"
    }

    # Only a self-update tears the client down first, so only a self-update has
    # to bring it back. A fresh install never had one running and must not pop a
    # window behind the wizard.
    if ($updateRequested) {
        try {
            Start-InstalledClient -TargetDir $InstallDir | Out-Null
        } catch {
            Write-Warning "could not restart the client after the update: $($_.Exception.Message)"
        }
    }
}

function Invoke-UninstallMode {
    $existingState = Get-ExistingInstallState -TargetDir $InstallDir
    $effectiveInstallMode = if ($null -ne $existingState -and ($existingState.PSObject.Properties.Name -contains "install_mode") -and -not [string]::IsNullOrWhiteSpace([string]$existingState.install_mode)) {
        [string]$existingState.install_mode
    } else {
        "just-for-me"
    }

    Stop-InstalledProcesses -TargetDir $InstallDir
    Ensure-DesktopShortcutState -TargetDir $InstallDir -ShouldExist $false -ShortcutDisplayName "LeiKwan Bridge | 利群會聚" -EffectiveInstallMode $effectiveInstallMode -UseOverrideForCurrentUser $true
    $installedFiles = Get-InstalledFilesFromManifest -TargetDir $InstallDir
    Remove-InstallerOwnedFiles -TargetDir $InstallDir -InstalledFiles $installedFiles
    Remove-Item -LiteralPath (Join-Path $InstallDir "install-manifest.json") -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $InstallDir "install-state.json") -Force -ErrorAction SilentlyContinue
    Remove-EmptyDirectoryIfPossible -DirectoryPath (Join-Path $InstallDir "bin")
    Remove-EmptyDirectoryIfPossible -DirectoryPath $InstallDir
    Schedule-InstalledHelperCleanup -TargetDir $InstallDir

    Write-Host "Uninstalled installer-owned LeiKwan Bridge artifacts from $InstallDir"
}

$InstallDir = Get-NormalizedPath -Path $InstallDir

switch ($Mode) {
    "Install" { Invoke-InstallMode }
    "Uninstall" { Invoke-UninstallMode }
    default { throw "unsupported mode: $Mode" }
}
