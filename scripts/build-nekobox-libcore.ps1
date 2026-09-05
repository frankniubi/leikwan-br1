param(
    [string]$Targets = "android/arm64",
    [string]$OutputPath = "",
    [switch]$ForceLibboxAbiRepair
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot

# The app consumes sing-box's libbox AAR.  Keep the existing NekoBox build path
# for the legacy arm64-only invocation, but make the dual-ABI target explicit
# and fail closed if either required libbox slice is absent.
$targetValues = $Targets.Split(',') |
    ForEach-Object { $_.Trim().ToLowerInvariant() } |
    Where-Object { $_ -ne "" }
$wantsX86 = $ForceLibboxAbiRepair -or $targetValues -contains "android/amd64" -or $targetValues -contains "android/x86_64"
if ($wantsX86) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $aarPath = if ([string]::IsNullOrWhiteSpace($OutputPath)) {
        Join-Path $repoRoot "leikwan-android\app\libs\libbox.aar"
    } else {
        [System.IO.Path]::GetFullPath($OutputPath)
    }
    if (-not (Test-Path -LiteralPath $aarPath -PathType Leaf)) {
        throw "libbox AAR not found: $aarPath"
    }

    function Get-AarEntryBytes([string]$Path, [string]$EntryName) {
        $archive = [System.IO.Compression.ZipFile]::OpenRead($Path)
        try {
            $entry = $archive.GetEntry($EntryName)
            if ($null -eq $entry) { return $null }
            $stream = $entry.Open()
            try {
                $memory = New-Object System.IO.MemoryStream
                try {
                    $stream.CopyTo($memory)
                    return ,$memory.ToArray()
                } finally {
                    $memory.Dispose()
                }
            } finally {
                $stream.Dispose()
            }
        } finally {
            $archive.Dispose()
        }
    }

    function Assert-SoMachine([byte[]]$Bytes, [int]$ExpectedMachine, [string]$Label) {
        $validHeader = $null -ne $Bytes -and $Bytes.Length -ge 20 -and
            $Bytes[0] -eq 0x7f -and $Bytes[1] -eq 0x45 -and $Bytes[2] -eq 0x4c -and $Bytes[3] -eq 0x46 -and
            $Bytes[4] -eq 2 -and $Bytes[5] -eq 1
        if (-not $validHeader) {
            throw "$Label is not an ELF64 little-endian shared library"
        }
        $machine = [BitConverter]::ToUInt16($Bytes, 18)
        if ($machine -ne $ExpectedMachine) { throw "$Label has e_machine=$machine; expected $ExpectedMachine" }
    }

    $armEntry = Get-AarEntryBytes $aarPath "jni/arm64-v8a/libbox.so"
    Assert-SoMachine $armEntry 183 "jni/arm64-v8a/libbox.so"
    $x86Entry = Get-AarEntryBytes $aarPath "jni/x86_64/libbox.so"
    if ($null -eq $x86Entry) {
        throw "Missing x86_64 libbox: rebuild classes.jar and BOTH native slices with one pinned gomobile bind. Splicing a fallback .so is unsafe and is no longer supported."
    }
    & python (Join-Path $PSScriptRoot "verify_android_libbox.py") --aar $aarPath
    if ($LASTEXITCODE -ne 0) { throw "libbox Java/native binding verification failed" }
    Assert-SoMachine $x86Entry 62 "jni/x86_64/libbox.so"
    [pscustomobject]@{
        output = $aarPath
        targets = $targetValues
        arm64_bytes = $armEntry.Length
        x86_64_bytes = $x86Entry.Length
        sha256 = (Get-FileHash -LiteralPath $aarPath -Algorithm SHA256).Hash.ToLowerInvariant()
    } | ConvertTo-Json -Compress
    return
}

$nekoRoot = Join-Path $repoRoot "NekoBoxForAndroid-main"
$goRoot = "C:\Users\frankniubi\Downloads\leikwan-v3\.codex-temp\go"
$goPath = "C:\Users\frankniubi\Downloads\leikwan-v3\.codex-temp\gopath"
$sdkRoot = "C:\Users\frankniubi\Android\Sdk"
$ndkRoot = Join-Path $sdkRoot "ndk\27.1.12297006"
$gitBash = "C:\Program Files\Git\bin\bash.exe"

$singBoxPath = Join-Path $repoRoot "sing-box"
$libNekoPath = Join-Path $repoRoot "libneko"
$gomobileExe = Join-Path $goPath "bin\gomobile-matsuri.exe"
$gobindExe = Join-Path $goPath "bin\gobind-matsuri.exe"

function Ensure-GitCheckout {
    param(
        [string]$Path,
        [string]$Remote,
        [string]$Commit
    )

    if (!(Test-Path $Path)) {
        git clone --no-checkout $Remote $Path
    }

    Push-Location $Path
    try {
        git checkout $Commit
    } finally {
        Pop-Location
    }
}

Ensure-GitCheckout -Path $singBoxPath -Remote "https://github.com/MatsuriDayo/sing-box.git" -Commit "aed32ee3066cdbc7d471e3e0415c5134088962df"
Ensure-GitCheckout -Path $libNekoPath -Remote "https://github.com/MatsuriDayo/libneko.git" -Commit "1c47a3af71990a7b2192e03292b4d246c308ef0b"

if (!(Test-Path $gomobileExe) -or !(Test-Path $gobindExe)) {
    $bashInit = @'
cd /c/Users/frankniubi/Downloads/leikwan-br1/NekoBoxForAndroid-main/libcore
export PATH="$PATH:/c/Users/frankniubi/Downloads/leikwan-v3/.codex-temp/go/bin:/c/Users/frankniubi/Downloads/leikwan-v3/.codex-temp/gopath/bin"
export GOPATH=/c/Users/frankniubi/Downloads/leikwan-v3/.codex-temp/gopath
export ANDROID_HOME=/c/Users/frankniubi/Android/Sdk
export ANDROID_NDK_HOME=/c/Users/frankniubi/Android/Sdk/ndk/27.1.12297006
export NDK=/c/Users/frankniubi/Android/Sdk/ndk/27.1.12297006
./init.sh
'@
    & $gitBash -lc $bashInit
}

$env:PATH = "$($goRoot)\bin;$($goPath)\bin;$env:PATH"
$env:GOPATH = $goPath
$env:ANDROID_HOME = $sdkRoot
$env:ANDROID_NDK_HOME = $ndkRoot
$env:NDK = $ndkRoot
$env:GOBIND = "gobind-matsuri"

Push-Location (Join-Path $nekoRoot "libcore")
try {
    $cachePath = (Join-Path (Get-Location) ".build")
    & $gomobileExe bind -x -v -androidapi 21 -cache $cachePath -trimpath -ldflags="-s -w" -tags="with_conntrack,with_gvisor,with_quic,with_wireguard,with_utls,with_clash_api" .
    if ($LASTEXITCODE -ne 0) { throw "gomobile-matsuri bind failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
}

$appLibs = Join-Path $nekoRoot "app\libs"
New-Item -ItemType Directory -Force -Path $appLibs | Out-Null
Copy-Item (Join-Path $nekoRoot "libcore\libcore.aar") (Join-Path $appLibs "libcore.aar") -Force

Get-Item (Join-Path $appLibs "libcore.aar") | Select-Object FullName, Length, LastWriteTime
