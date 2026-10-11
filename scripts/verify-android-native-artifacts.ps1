[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Apk,
    [string]$Aar = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.IO.Compression.FileSystem

$repoRoot = Split-Path -Parent $PSScriptRoot
$apkPath = [System.IO.Path]::GetFullPath($Apk)
$aarPath = if ([string]::IsNullOrWhiteSpace($Aar)) {
    Join-Path $repoRoot "leikwan-android\app\libs\libbox.aar"
} else {
    [System.IO.Path]::GetFullPath($Aar)
}
if (-not (Test-Path -LiteralPath $apkPath -PathType Leaf)) { throw "APK not found: $apkPath" }
if (-not (Test-Path -LiteralPath $aarPath -PathType Leaf)) { throw "AAR not found: $aarPath" }

& python (Join-Path $PSScriptRoot "verify_android_libbox.py") --aar $aarPath --apk $apkPath
if ($LASTEXITCODE -ne 0) { throw "libbox Java/native packaging gate failed" }

function Get-ZipBytes([string]$Path, [string]$Name) {
    $archive = [System.IO.Compression.ZipFile]::OpenRead($Path)
    try {
        $entry = $archive.GetEntry($Name)
        if ($null -eq $entry) { return $null }
        $stream = $entry.Open()
        try {
            $memory = New-Object System.IO.MemoryStream
            try {
                $stream.CopyTo($memory)
                return ,$memory.ToArray()
            } finally { $memory.Dispose() }
        } finally { $stream.Dispose() }
    } finally { $archive.Dispose() }
}

function Get-ZipEntryCount([string]$Path, [string]$Name) {
    $archive = [System.IO.Compression.ZipFile]::OpenRead($Path)
    try {
        return @($archive.Entries | Where-Object { $_.FullName -eq $Name }).Count
    } finally { $archive.Dispose() }
}

function Assert-ElfMachine([byte[]]$Bytes, [int]$Expected, [string]$Name) {
    $validHeader = $null -ne $Bytes -and $Bytes.Length -ge 20 -and
        $Bytes[0] -eq 0x7f -and $Bytes[1] -eq 0x45 -and $Bytes[2] -eq 0x4c -and $Bytes[3] -eq 0x46 -and
        $Bytes[4] -eq 2 -and $Bytes[5] -eq 1
    if (-not $validHeader) {
        throw "$Name is not ELF64 little-endian"
    }
    $machine = [BitConverter]::ToUInt16($Bytes, 18)
    if ($machine -ne $Expected) { throw "$Name e_machine=$machine, expected $Expected" }
}

$apkEntries = @(
    @{ Name = "lib/arm64-v8a/libbox.so"; Machine = 183 },
    @{ Name = "lib/x86_64/libbox.so"; Machine = 62 },
    @{ Name = "lib/arm64-v8a/libultra_client.so"; Machine = 183 },
    @{ Name = "lib/x86_64/libultra_client.so"; Machine = 62 },
    @{ Name = "assets/runtime/arm64-v8a/ultra-client"; Machine = 183 },
    @{ Name = "assets/runtime/x86_64/ultra-client"; Machine = 62 }
)
$aarEntries = @(
    @{ Name = "jni/arm64-v8a/libbox.so"; Machine = 183 },
    @{ Name = "jni/x86_64/libbox.so"; Machine = 62 }
)

foreach ($item in $apkEntries) {
    if ((Get-ZipEntryCount $apkPath $item.Name) -ne 1) {
        throw "APK must contain exactly one entry named $($item.Name)"
    }
    $bytes = Get-ZipBytes $apkPath $item.Name
    Assert-ElfMachine $bytes $item.Machine $item.Name
    $digest = [System.Security.Cryptography.SHA256]::Create()
    try { $hash = ([BitConverter]::ToString($digest.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $digest.Dispose() }
    Write-Output ("{0}`t{1}`t{2}" -f $item.Name, $bytes.Length, $hash)
}
# The JNI and executable asset are two copies of the same Go runtime. Pair
# hashes per ABI so an old asset cannot be silently combined with a new .so.
foreach ($abi in @("arm64-v8a", "x86_64")) {
    $libName = "lib/$abi/libultra_client.so"
    $assetName = "assets/runtime/$abi/ultra-client"
    $libBytes = Get-ZipBytes $apkPath $libName
    $assetBytes = Get-ZipBytes $apkPath $assetName
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $libHash = ([BitConverter]::ToString($sha.ComputeHash($libBytes))).Replace('-', '').ToLowerInvariant()
        $sha.Initialize()
        $assetHash = ([BitConverter]::ToString($sha.ComputeHash($assetBytes))).Replace('-', '').ToLowerInvariant()
    } finally { $sha.Dispose() }
    if ($libHash -ne $assetHash) { throw "APK Ultra JNI/runtime asset hashes differ for $abi" }
}
foreach ($item in $aarEntries) {
    if ((Get-ZipEntryCount $aarPath $item.Name) -ne 1) {
        throw "AAR must contain exactly one entry named $($item.Name)"
    }
    $bytes = Get-ZipBytes $aarPath $item.Name
    Assert-ElfMachine $bytes $item.Machine $item.Name
    $digest = [System.Security.Cryptography.SHA256]::Create()
    try { $hash = ([BitConverter]::ToString($digest.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $digest.Dispose() }
    Write-Output ("{0}`t{1}`t{2}" -f $item.Name, $bytes.Length, $hash)
}
Write-Output ("APK`t{0}`t{1}" -f $apkPath, (Get-FileHash -LiteralPath $apkPath -Algorithm SHA256).Hash.ToLowerInvariant())
Write-Output ("AAR`t{0}`t{1}" -f $aarPath, (Get-FileHash -LiteralPath $aarPath -Algorithm SHA256).Hash.ToLowerInvariant())
