[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Apk,
    [string]$AvdName = "LeiKwan_QA_API34",
    [int]$Port = 5556,
    [string]$SdkRoot = "",
    [switch]$SkipConnectedTests
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$apkPath = [System.IO.Path]::GetFullPath($Apk)
if (-not (Test-Path -LiteralPath $apkPath -PathType Leaf)) { throw "APK not found: $apkPath" }
$sdkCandidates = if ([string]::IsNullOrWhiteSpace($SdkRoot)) {
    @($env:ANDROID_HOME, $env:ANDROID_SDK_ROOT, "C:\Users\$env:USERNAME\Android\Sdk", (Join-Path $env:LOCALAPPDATA "Android\Sdk"))
} else {
    @([System.IO.Path]::GetFullPath($SdkRoot))
}
function Test-SdkHasApkAnalyzer([string]$Root) {
    if (Test-Path -LiteralPath (Join-Path $Root "cmdline-tools\latest\bin\apkanalyzer.bat") -PathType Leaf) {
        return $true
    }
    return $null -ne (Get-ChildItem -LiteralPath (Join-Path $Root "cmdline-tools") `
        -Recurse -Filter "apkanalyzer.bat" -File -ErrorAction SilentlyContinue | Select-Object -First 1)
}
$sdk = $sdkCandidates |
    Where-Object {
        -not [string]::IsNullOrWhiteSpace($_) -and
            (Test-Path (Join-Path $_ "platform-tools\adb.exe")) -and
            (Test-Path (Join-Path $_ "emulator\emulator.exe")) -and
            (Test-Path (Join-Path $_ "system-images\android-34\google_apis\x86_64\package.xml")) -and
            (Test-SdkHasApkAnalyzer $_)
    } |
    Select-Object -First 1
if ([string]::IsNullOrWhiteSpace($sdk)) { throw "no usable Android SDK with API 34 x86_64 image found" }
$adb = Join-Path $sdk "platform-tools\adb.exe"
$emulator = Join-Path $sdk "emulator\emulator.exe"
if (-not (Test-Path -LiteralPath $adb)) { throw "adb not found: $adb" }
if (-not (Test-Path -LiteralPath $emulator)) { throw "emulator not found: $emulator" }

$serial = "emulator-$Port"
$evidenceRoot = Join-Path (Split-Path -Parent $PSScriptRoot) ("artifacts\android\native-qa-{0}" -f (Get-Date -Format "yyyyMMdd-HHmmss"))
New-Item -ItemType Directory -Path $evidenceRoot -Force | Out-Null
$apkInfo = Get-Item -LiteralPath $apkPath
$apkHash = (Get-FileHash -LiteralPath $apkPath -Algorithm SHA256).Hash.ToLowerInvariant()
$contractOutput = & (Join-Path (Split-Path -Parent $PSScriptRoot) "scripts\verify-android-build-version.ps1") `
    -ApkPath $apkPath -AndroidSdk $sdk
if ($LASTEXITCODE -ne 0) { throw "APK build contract verification failed" }
$contract = $contractOutput | Select-Object -Last 1 | ConvertFrom-Json
[ordered]@{
    artifact = $apkPath
    sha256 = $apkHash
    size_bytes = $apkInfo.Length
    version_name = $contract.version_name
    version_code = $contract.version_code
    abi_matrix = @("arm64-v8a", "x86_64")
    release_contract_checked = $true
} | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $evidenceRoot "artifact.json") -Encoding utf8
$emulatorLog = Join-Path $evidenceRoot "emulator.log"
$emulatorErrorLog = Join-Path $evidenceRoot "emulator.err.log"
$process = $null
$previousAndroidHome = $env:ANDROID_HOME
$previousAndroidSdkRoot = $env:ANDROID_SDK_ROOT

function Invoke-Adb([string[]]$Arguments) {
    $previousErrorAction = $ErrorActionPreference
    try {
        $ErrorActionPreference = "Continue"
        $output = & $adb @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorAction
    }
    if ($exitCode -ne 0) { throw "adb failed: $($Arguments -join ' ')" }
    return (($output | ForEach-Object { $_.ToString() }) -join "`n").Trim()
}

function Invoke-AdbRetry {
    param(
        [string[]]$Arguments,
        [int]$Attempts = 8,
        [int]$DelaySeconds = 2
    )

    $lastError = $null
    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        try {
            return Invoke-Adb $Arguments
        } catch {
            $lastError = $_
            if ($attempt -lt $Attempts) { Start-Sleep -Seconds $DelaySeconds }
        }
    }
    throw $lastError
}

try {
    # The emulator resolves its system image from the process environment,
    # which may differ from the SDK path selected above.
    $env:ANDROID_HOME = $sdk
    $env:ANDROID_SDK_ROOT = $sdk
    $listed = & $adb devices 2>$null
    if (($listed -join "`n") -match [regex]::Escape($serial)) {
        throw "refusing to reuse an existing $serial instance"
    }
    $args = @(
        "-avd", $AvdName,
        "-port", $Port,
        "-read-only",
        "-no-snapshot-load",
        "-no-snapshot-save",
        "-no-boot-anim",
        "-no-window",
        "-gpu", "swiftshader_indirect"
    )
    $process = Start-Process -FilePath $emulator -ArgumentList $args -RedirectStandardOutput $emulatorLog -RedirectStandardError $emulatorErrorLog -WindowStyle Hidden -PassThru

    $deadline = (Get-Date).AddMinutes(4)
    do {
        Start-Sleep -Seconds 2
        $state = try { (& $adb -s $serial get-state 2>$null | Out-String).Trim() } catch { "" }
        if ($state -eq "device") { break }
        if ((Get-Date) -gt $deadline) { throw "emulator did not reach device state" }
    } while ($true)

    do {
        Start-Sleep -Seconds 2
        $boot = try { (& $adb -s $serial shell getprop sys.boot_completed 2>$null | Out-String).Trim() } catch { "" }
        if ($boot -eq "1") { break }
        if ((Get-Date) -gt $deadline) { throw "emulator boot timed out" }
    } while ($true)

    $abi = Invoke-Adb @("-s", $serial, "shell", "getprop", "ro.product.cpu.abilist")
    if ($abi -notmatch "(^|,)x86_64(,|$)") { throw "emulator ABI does not include x86_64: $abi" }
    # The temporary AVD may contain an older debug install signed by another
    # local build; remove only the app and instrumentation packages from this
    # disposable instance so connected-test installation cannot hit a signer
    # mismatch left by a previous run.
    foreach ($packageName in @("com.leikwan.bridge", "com.leikwan.bridge.test")) {
        try { & $adb -s $serial uninstall $packageName 2>$null | Out-Null } catch { }
    }
    $package = Invoke-Adb @("-s", $serial, "install", "-r", "-d", $apkPath)
    Start-Sleep -Seconds 2
    $launch = Invoke-Adb @("-s", $serial, "shell", "monkey", "-p", "com.leikwan.bridge", "1")
    $installed = Invoke-AdbRetry @("-s", $serial, "shell", "dumpsys", "package", "com.leikwan.bridge")
    if ($installed -notmatch "Package \[com\.leikwan\.bridge\]") { throw "package was not installed" }
    $uiReady = $false
    $uiDeadline = (Get-Date).AddSeconds(30)
    do {
        Start-Sleep -Seconds 1
        $activity = Invoke-AdbRetry @("-s", $serial, "shell", "dumpsys", "activity", "activities")
        if ($activity -notmatch "Splash Screen com\.leikwan\.bridge") {
            $uiReady = $true
            break
        }
        if ((Get-Date) -gt $uiDeadline) { break }
    } while ($true)
    if (-not $uiReady) { throw "application did not leave the splash screen within 30 seconds" }
    $logcat = Invoke-Adb @("-s", $serial, "logcat", "-d", "-v", "time")
    Set-Content -LiteralPath (Join-Path $evidenceRoot "logcat.txt") -Value $logcat -Encoding utf8
    Set-Content -LiteralPath (Join-Path $evidenceRoot "activity.txt") -Value $activity -Encoding utf8
    $remoteScreenshot = "/sdcard/leikwan-native-outbound-qa.png"
    $localScreenshot = Join-Path $evidenceRoot "launcher.png"
    Invoke-Adb @("-s", $serial, "shell", "screencap", "-p", $remoteScreenshot) | Out-Null
    Invoke-Adb @("-s", $serial, "pull", $remoteScreenshot, $localScreenshot) | Out-Null
    if (-not (Test-Path -LiteralPath $localScreenshot -PathType Leaf) -or (Get-Item -LiteralPath $localScreenshot).Length -lt 1024) {
        throw "launcher screenshot was not captured"
    }

    Set-Content -LiteralPath (Join-Path $evidenceRoot "serial.txt") -Value $serial -Encoding ascii
    Set-Content -LiteralPath (Join-Path $evidenceRoot "abi.txt") -Value $abi -Encoding ascii
    Set-Content -LiteralPath (Join-Path $evidenceRoot "install.txt") -Value "installed" -Encoding ascii
    Set-Content -LiteralPath (Join-Path $evidenceRoot "launch.txt") -Value "launched" -Encoding ascii
    Set-Content -LiteralPath (Join-Path $evidenceRoot "screenshot.txt") -Value $localScreenshot -Encoding ascii

    if (-not $SkipConnectedTests) {
        # The smoke-tested artifact uses the controlled release signer, while
        # connectedDebugAndroidTest installs the local debug-signed app. Remove
        # both packages after smoke evidence is captured so Gradle gets a clean
        # instrumentation installation boundary.
        foreach ($packageName in @("com.leikwan.bridge", "com.leikwan.bridge.test")) {
            try { & $adb -s $serial uninstall $packageName 2>$null | Out-Null } catch { }
        }
        $gradle = Join-Path (Split-Path -Parent $PSScriptRoot) "leikwan-android\gradlew.bat"
        Push-Location (Split-Path -Parent $gradle)
        try {
            & $gradle ":app:connectedDebugAndroidTest" "-Pqatest=true" "-PandroidX86=true" "--no-daemon" "--rerun-tasks" *>&1 |
                Tee-Object -FilePath (Join-Path $evidenceRoot "connected-tests.txt")
            if ($LASTEXITCODE -ne 0) { throw "connected Android tests failed" }
            $resultsRoot = Join-Path (Split-Path -Parent $PSScriptRoot) "leikwan-android\app\build\outputs\androidTest-results\connected\debug"
            $resultFile = Get-ChildItem -LiteralPath $resultsRoot -Filter "TEST-*.xml" -File -ErrorAction SilentlyContinue |
                Sort-Object LastWriteTime -Descending | Select-Object -First 1
            if ($null -eq $resultFile) { throw "connected Android test result XML not found" }
            $suite = [xml](Get-Content -LiteralPath $resultFile.FullName -Raw)
            $testCount = [int]$suite.testsuite.tests
            $failureCount = [int]$suite.testsuite.failures
            $errorCount = [int]$suite.testsuite.errors
            $skippedCount = [int]$suite.testsuite.skipped
            if ($testCount -lt 1 -or $failureCount -ne 0 -or $errorCount -ne 0) {
                throw "connected Android test result is not clean: tests=$testCount failures=$failureCount errors=$errorCount"
            }
            [ordered]@{
                tests = $testCount
                failures = $failureCount
                errors = $errorCount
                skipped = $skippedCount
                result_file = $resultFile.FullName
                artifact_sha256 = $apkHash
            } | ConvertTo-Json -Compress | Set-Content -LiteralPath (Join-Path $evidenceRoot "connected-summary.json") -Encoding utf8
        } finally { Pop-Location }
    } else {
        Set-Content -LiteralPath (Join-Path $evidenceRoot "connected-tests.txt") -Value "skipped" -Encoding ascii
        [ordered]@{
            tests = 0
            failures = 0
            errors = 0
            skipped = 0
            result_file = $null
            artifact_sha256 = $apkHash
            status = "skipped"
        } | ConvertTo-Json -Compress | Set-Content -LiteralPath (Join-Path $evidenceRoot "connected-summary.json") -Encoding utf8
    }
    Write-Output ("PASS`t{0}`t{1}" -f $serial, $evidenceRoot)
} finally {
    if ($process -ne $null) {
        try { & $adb -s $serial emu kill 2>$null | Out-Null } catch { }
        $null = $process.WaitForExit(30000)
        if (-not $process.HasExited) { Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue }
    }
    $env:ANDROID_HOME = $previousAndroidHome
    $env:ANDROID_SDK_ROOT = $previousAndroidSdkRoot
}
