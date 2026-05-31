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
        "leikwan-mptcp-relay.exe"
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
        try {
            Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop
        } catch {
            Write-Warning "failed to stop process $($_.Name) [$($_.ProcessId)]: $($_.Exception.Message)"
        }
    }

    Get-Process -Name "leikwan-client-win" -ErrorAction SilentlyContinue | ForEach-Object {
        try {
            Stop-Process -Id $_.Id -Force -ErrorAction Stop
        } catch {
            Write-Warning "failed to stop process $($_.ProcessName) [$($_.Id)]: $($_.Exception.Message)"
        }
    }

    Start-Sleep -Milliseconds 800
}

function Backup-ExistingInstall {
    param([string]$TargetDir)

    if (-not (Test-Path -LiteralPath $TargetDir)) {
        return ""
    }

    $entries = @(Get-ChildItem -LiteralPath $TargetDir -Force | Where-Object { $_.Name -notlike 'backup-*' })
    if ($entries.Count -eq 0) {
        return ""
    }

    $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $backupDir = Join-Path $TargetDir ("backup-" + $timestamp)
    New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

    foreach ($entry in $entries) {
        $destination = Join-Path $backupDir $entry.Name
        if ($entry.PSIsContainer) {
            Copy-Item -LiteralPath $entry.FullName -Destination $destination -Recurse -Force
        } else {
            Copy-Item -LiteralPath $entry.FullName -Destination $destination -Force
        }
    }

    return $backupDir
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
            Copy-Item -LiteralPath $entry.FullName -Destination $destination -Recurse -Force
        } else {
            Copy-Item -LiteralPath $entry.FullName -Destination $destination -Force
        }
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
        "bin\wstunnel.exe",
        "bin\leikwan-bond.exe",
        "bin\leikwan-ingress.exe",
        "bin\leikwan-uot-bond.exe",
        "bin\leikwan-mptcp-relay.exe",
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

    Get-ChildItem -LiteralPath $TargetDir -Directory -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -like 'backup-*' } |
        ForEach-Object {
            Remove-Item -LiteralPath $_.FullName -Recurse -Force -ErrorAction SilentlyContinue
            if (Test-Path -LiteralPath $_.FullName) {
                throw "backup directory could not be removed: $($_.FullName)"
            }
        }
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

function Invoke-InstallMode {
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

    Stop-InstalledProcesses -TargetDir $InstallDir
    $backupDir = Backup-ExistingInstall -TargetDir $InstallDir
    Remove-StaleInstallerOwnedFiles -TargetDir $InstallDir -PreviousInstalledFiles $previousInstalledFiles -CurrentInstalledFiles $currentInstalledFiles
    Copy-StagedPayload -PayloadRoot $payloadRoot -TargetDir $InstallDir
    Write-CompatibilityCopies -TargetDir $InstallDir
    Install-EmbeddedHelper -TargetDir $InstallDir
    Ensure-DesktopShortcutState -TargetDir $InstallDir -ShouldExist $selectedDesktopShortcut -ShortcutDisplayName $DisplayName -EffectiveInstallMode $InstallMode
    $installedFiles = $currentInstalledFiles
    Write-InstallManifest -TargetDir $InstallDir -BackupDir $backupDir -InstalledFiles $installedFiles
    Write-InstallState -TargetDir $InstallDir -SelectedInstallMode $InstallMode -SelectedDesktopShortcut $selectedDesktopShortcut -SelectedDisplayName $DisplayName -SelectedAppVersion $AppVersion

    Write-Host "Installed LeiKwan Bridge to $InstallDir"
    if (-not [string]::IsNullOrWhiteSpace($backupDir)) {
        Write-Host "Backup saved to $backupDir"
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
