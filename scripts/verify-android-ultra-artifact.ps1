[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ApkPath
)

$ErrorActionPreference = "Stop"
$ApkPath = [System.IO.Path]::GetFullPath($ApkPath)
if (-not (Test-Path -LiteralPath $ApkPath -PathType Leaf)) {
    throw "APK not found: $ApkPath"
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Fail([string]$Message) {
    throw "ANDROID_ULTRA_ARTIFACT_REJECTED: $Message"
}

function Read-EntryPrefix([System.IO.Compression.ZipArchiveEntry]$Entry, [int]$Length) {
    $stream = $Entry.Open()
    try {
        $buffer = New-Object byte[] $Length
        $offset = 0
        while ($offset -lt $Length) {
            $read = $stream.Read($buffer, $offset, $Length - $offset)
            if ($read -le 0) { break }
            $offset += $read
        }
        if ($offset -lt $Length) {
            return $buffer[0..([Math]::Max(0, $offset - 1))]
        }
        return $buffer
    } finally {
        $stream.Dispose()
    }
}

function Get-EntrySha256([System.IO.Compression.ZipArchiveEntry]$Entry) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $stream = $Entry.Open()
    try {
        $null = $sha.ComputeHash($stream)
        return ([System.BitConverter]::ToString($sha.Hash)).Replace('-', '').ToLowerInvariant()
    } finally {
        $stream.Dispose()
        $sha.Dispose()
    }
}

function Assert-ElfMachine([System.IO.Compression.ZipArchiveEntry]$Entry, [int]$ExpectedMachine, [string]$Abi) {
    $header = Read-EntryPrefix $Entry 20
    $validElf = $header.Length -ge 20 -and $header[0] -eq 0x7f -and $header[1] -eq [byte][char]'E' -and $header[2] -eq [byte][char]'L' -and $header[3] -eq [byte][char]'F'
    if (-not $validElf) {
        Fail "Ultra entry is not ELF64: $($Entry.FullName)"
    }
    if ($header[4] -ne 2 -or $header[5] -ne 1) {
        Fail "Ultra entry is not ELF64 little-endian: $($Entry.FullName)"
    }
    $machine = [BitConverter]::ToUInt16($header, 18)
    if ($machine -ne $ExpectedMachine) {
        Fail "Ultra entry has e_machine=$machine, expected $ExpectedMachine for ${Abi}: $($Entry.FullName)"
    }
}

function Test-StaticEntry([System.IO.Compression.ZipArchiveEntry]$Entry) {
    $maxBytes = 8MB
    if ($Entry.Length -gt $maxBytes) {
        Fail "static asset is too large to scan safely: $($Entry.FullName)"
    }
    $stream = $Entry.Open()
    try {
        $reader = New-Object System.IO.StreamReader($stream, [System.Text.Encoding]::UTF8, $true)
        $content = $reader.ReadToEnd()
    } finally {
        if ($null -ne $reader) { $reader.Dispose() } else { $stream.Dispose() }
    }
    $forbidden = @(
        @{ Name = 'raw_agent_token_url'; Pattern = '(?i)agent_token\s*=' },
        @{ Name = 'provider_endpoint'; Pattern = '(?i)"(?:endpoint|provider_ip|provider_port)"\s*:' },
        @{ Name = 'provider_credential'; Pattern = '(?i)"(?:credential|password|user)"\s*:' },
        @{ Name = 'lease_material'; Pattern = '(?i)"(?:lease_id|session_ticket|nonce_hash|direct_ip_cidrs)"\s*:' }
    )
    foreach ($rule in $forbidden) {
        if ([regex]::IsMatch($content, $rule.Pattern)) {
            # Never print the matching content: assets may contain secrets in a rejected artifact.
            Fail "static asset contains $($rule.Name): $($Entry.FullName)"
        }
    }
}

function Assert-NoRawAgentTokenMarker([System.IO.Compression.ZipArchiveEntry]$Entry) {
    $stream = $Entry.Open()
    try {
        $buffer = New-Object byte[] 65536
        $carry = ''
        while ($true) {
            $read = $stream.Read($buffer, 0, $buffer.Length)
            if ($read -le 0) { break }
            $chunk = $carry + [System.Text.Encoding]::ASCII.GetString($buffer, 0, $read)
            if ($chunk -match '(?i)agent_token\s*=') {
                Fail "raw agent-token URL marker found in $($Entry.FullName)"
            }
            $carry = if ($chunk.Length -gt 64) { $chunk.Substring($chunk.Length - 64) } else { $chunk }
        }
    } finally {
        $stream.Dispose()
    }
}

$archive = [System.IO.Compression.ZipFile]::OpenRead($ApkPath)
try {
    $entries = @($archive.Entries)
    # Keep the ABI matrix explicit. A release that silently falls back to an
    # older single-ABI payload can install but fail at runtime on the QA
    # x86_64 emulator, so both entries are required and hash-paired.
    $ultraExpectations = @(
        [pscustomobject]@{ Abi = 'arm64-v8a'; Machine = 183 },
        [pscustomobject]@{ Abi = 'x86_64'; Machine = 62 }
    )
    $ultraRecords = @()
    foreach ($expectation in $ultraExpectations) {
        $libName = "lib/$($expectation.Abi)/libultra_client.so"
        $assetName = "assets/runtime/$($expectation.Abi)/ultra-client"
        $libs = @($entries | Where-Object { $_.FullName -eq $libName })
        if ($libs.Count -ne 1) {
            Fail "APK must contain exactly one Ultra JNI runtime entry for $($expectation.Abi)"
        }
        $ultra = $libs[0]
        Assert-ElfMachine $ultra $expectation.Machine $expectation.Abi
        $assets = @($entries | Where-Object { $_.FullName -eq $assetName })
        if ($assets.Count -ne 1) {
            Fail "APK must contain exactly one Ultra runtime asset for $($expectation.Abi)"
        }
        $asset = $assets[0]
        Assert-ElfMachine $asset $expectation.Machine $expectation.Abi
        $binaryHash = Get-EntrySha256 $ultra
        $assetHash = Get-EntrySha256 $asset
        if ($binaryHash -ne $assetHash) {
            Fail "Ultra JNI/runtime asset hashes differ for $($expectation.Abi)"
        }
        $ultraRecords += [pscustomobject]@{
            abi = $expectation.Abi
            lib = $libName
            asset = $assetName
            sha256 = $binaryHash
        }
    }

    $textAssets = @($entries | Where-Object {
        $_.FullName -match '^assets/.+\.(json|txt|conf|properties|xml)$'
    })
    foreach ($entry in $textAssets) {
        Test-StaticEntry $entry
    }

    $dexEntries = @($entries | Where-Object { $_.FullName -match '^classes[0-9]*\.dex$' })
    foreach ($entry in $dexEntries) {
        Assert-NoRawAgentTokenMarker $entry
    }

    [pscustomobject]@{
        apk = $ApkPath
        ultra_entries = (($ultraRecords | ForEach-Object { $_.lib }) -join ',')
        ultra_sha256 = (($ultraRecords | Where-Object { $_.abi -eq 'arm64-v8a' } | Select-Object -First 1).sha256)
        ultra_abi_matrix = $ultraRecords
        static_assets_scanned = $textAssets.Count
        dex_entries_scanned = $dexEntries.Count
        status = 'ok'
    } | ConvertTo-Json -Compress
} finally {
    $archive.Dispose()
}
