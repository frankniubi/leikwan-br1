using System.Buffers.Binary;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;
using System.Xml;
using System.Xml.Linq;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Mac.Services;

public sealed class MacBundleValidator
{
    public const string BundleIdentifier = "com.leikwan.bridge";

    public void Validate(string bundlePath, ClientAppArtifact artifact)
    {
        if (!Directory.Exists(bundlePath)
            || !bundlePath.EndsWith(".app", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("bundle_missing");

        var contents = Path.Combine(bundlePath, "Contents");
        var plist = ReadPlist(Path.Combine(contents, "Info.plist"));
        if (!string.Equals(GetRequired(plist, "CFBundleIdentifier"), BundleIdentifier, StringComparison.Ordinal))
            throw new InvalidDataException("bundle_identifier_invalid");
        if (!VersionsEqual(GetRequired(plist, "CFBundleShortVersionString"), artifact.Version))
            throw new InvalidDataException("bundle_version_invalid");

        var executableName = GetRequired(plist, "CFBundleExecutable");
        if (!IsFileName(executableName))
            throw new InvalidDataException("bundle_executable_invalid");
        var executablePath = Path.Combine(contents, "MacOS", executableName);
        if (!File.Exists(executablePath))
            throw new InvalidDataException("bundle_executable_missing");

        var expectedArchitecture = NormalizeArchitecture(artifact.Architecture);
        RequireArchitecture(executablePath, expectedArchitecture);
        ValidateHelpers(contents, expectedArchitecture);
    }

    public static string ComputeSha256(string path)
    {
        using var stream = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(stream));
    }

    public static string GetMainExecutablePath(string bundlePath)
    {
        var contents = Path.Combine(bundlePath, "Contents");
        var plist = ReadPlist(Path.Combine(contents, "Info.plist"));
        var executableName = GetRequired(plist, "CFBundleExecutable");
        if (!IsFileName(executableName))
            throw new InvalidDataException("bundle_executable_invalid");
        return Path.Combine(contents, "MacOS", executableName);
    }

    private static void ValidateHelpers(string contents, string expectedArchitecture)
    {
        var resources = Path.Combine(contents, "Resources");
        var manifestPath = Path.Combine(resources, "helper-manifest.json");
        if (!File.Exists(manifestPath))
            throw new InvalidDataException("helper_manifest_missing");
        HelperManifest manifest;
        try
        {
            using var stream = File.OpenRead(manifestPath);
            manifest = JsonSerializer.Deserialize<HelperManifest>(stream)
                ?? throw new InvalidDataException("helper_manifest_invalid");
        }
        catch (JsonException)
        {
            throw new InvalidDataException("helper_manifest_invalid");
        }
        if (!string.Equals(NormalizeArchitecture(manifest.Architecture), expectedArchitecture, StringComparison.Ordinal))
            throw new InvalidDataException("helper_architecture_invalid");
        if (manifest.Helpers.Count is < 1 or > 32)
            throw new InvalidDataException("helper_manifest_invalid");

        var resourcesRoot = Path.GetFullPath(resources) + Path.DirectorySeparatorChar;
        foreach (var helper in manifest.Helpers)
        {
            var relative = (helper.Path ?? string.Empty).Replace('\\', '/');
            if (relative.Length == 0
                || Path.IsPathRooted(relative)
                || relative.Split('/').Any(segment => segment is "" or "." or ".."))
                throw new InvalidDataException("helper_path_invalid");
            var fullPath = Path.GetFullPath(Path.Combine(resources, relative));
            if (!fullPath.StartsWith(resourcesRoot, StringComparison.Ordinal) || !File.Exists(fullPath))
                throw new InvalidDataException("helper_missing");
            if (helper.SizeBytes <= 0 || new FileInfo(fullPath).Length != helper.SizeBytes)
                throw new InvalidDataException("helper_size_mismatch");
            if (!Regex.IsMatch(helper.Sha256 ?? string.Empty, "^[A-Fa-f0-9]{64}$", RegexOptions.CultureInvariant)
                || !string.Equals(ComputeSha256(fullPath), helper.Sha256, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("helper_hash_mismatch");
            RequireArchitecture(fullPath, expectedArchitecture);
        }
    }

    private static Dictionary<string, string> ReadPlist(string path)
    {
        if (!File.Exists(path))
            throw new InvalidDataException("bundle_plist_missing");
        try
        {
            var settings = new XmlReaderSettings
            {
                DtdProcessing = DtdProcessing.Ignore,
                XmlResolver = null,
                MaxCharactersInDocument = 256 * 1024,
            };
            using var stream = File.OpenRead(path);
            using var reader = XmlReader.Create(stream, settings);
            var document = XDocument.Load(reader, LoadOptions.None);
            var dict = document.Root?.Element("dict")
                ?? throw new InvalidDataException("bundle_plist_invalid");
            var values = new Dictionary<string, string>(StringComparer.Ordinal);
            var elements = dict.Elements().ToList();
            for (var index = 0; index + 1 < elements.Count; index++)
            {
                if (elements[index].Name.LocalName != "key")
                    continue;
                var value = elements[index + 1];
                if (value.Name.LocalName == "string")
                    values[elements[index].Value] = value.Value;
                index++;
            }
            return values;
        }
        catch (XmlException)
        {
            throw new InvalidDataException("bundle_plist_invalid");
        }
    }

    private static string GetRequired(IReadOnlyDictionary<string, string> values, string key)
    {
        if (!values.TryGetValue(key, out var value) || string.IsNullOrWhiteSpace(value))
            throw new InvalidDataException("bundle_plist_invalid");
        return value.Trim();
    }

    private static bool VersionsEqual(string left, string right)
    {
        return Version.TryParse(left, out var parsedLeft)
            && Version.TryParse(right, out var parsedRight)
            && NormalizeVersion(parsedLeft) == NormalizeVersion(parsedRight);
    }

    private static Version NormalizeVersion(Version version)
        => new(version.Major, version.Minor, Math.Max(version.Build, 0), Math.Max(version.Revision, 0));

    private static bool IsFileName(string value)
        => value.Length <= 128
            && !value.Contains('/')
            && !value.Contains('\\')
            && value is not "." and not "..";

    private static void RequireArchitecture(string path, string expectedArchitecture)
    {
        var architectures = ReadArchitectures(path);
        if (architectures.Count != 1 || !architectures.Contains(expectedArchitecture))
            throw new InvalidDataException("bundle_architecture_invalid");
    }

    internal static HashSet<string> ReadArchitectures(string path)
    {
        using var stream = File.OpenRead(path);
        if (stream.Length < 8)
            throw new InvalidDataException("bundle_architecture_invalid");
        Span<byte> header = stackalloc byte[8];
        stream.ReadExactly(header);
        var magic = header[..4];
        if (magic.SequenceEqual(new byte[] { 0xCF, 0xFA, 0xED, 0xFE }))
            return [MapCpu(BinaryPrimitives.ReadInt32LittleEndian(header[4..]))];
        if (magic.SequenceEqual(new byte[] { 0xFE, 0xED, 0xFA, 0xCF }))
            return [MapCpu(BinaryPrimitives.ReadInt32BigEndian(header[4..]))];
        if (magic.SequenceEqual(new byte[] { 0xCE, 0xFA, 0xED, 0xFE }))
            return [MapCpu(BinaryPrimitives.ReadInt32LittleEndian(header[4..]))];
        if (magic.SequenceEqual(new byte[] { 0xFE, 0xED, 0xFA, 0xCE }))
            return [MapCpu(BinaryPrimitives.ReadInt32BigEndian(header[4..]))];

        var fat64 = magic.SequenceEqual(new byte[] { 0xCA, 0xFE, 0xBA, 0xBF })
            || magic.SequenceEqual(new byte[] { 0xBF, 0xBA, 0xFE, 0xCA });
        var fat32 = magic.SequenceEqual(new byte[] { 0xCA, 0xFE, 0xBA, 0xBE })
            || magic.SequenceEqual(new byte[] { 0xBE, 0xBA, 0xFE, 0xCA });
        if (!fat32 && !fat64)
            throw new InvalidDataException("bundle_architecture_invalid");
        var littleEndian = magic[0] is 0xBE or 0xBF;
        var count = littleEndian
            ? BinaryPrimitives.ReadUInt32LittleEndian(header[4..])
            : BinaryPrimitives.ReadUInt32BigEndian(header[4..]);
        if (count is 0 or > 8)
            throw new InvalidDataException("bundle_architecture_invalid");
        var entrySize = fat64 ? 32 : 20;
        var entry = new byte[entrySize];
        var result = new HashSet<string>(StringComparer.Ordinal);
        for (var index = 0; index < count; index++)
        {
            stream.ReadExactly(entry);
            var cpu = littleEndian
                ? BinaryPrimitives.ReadInt32LittleEndian(entry.AsSpan(0, 4))
                : BinaryPrimitives.ReadInt32BigEndian(entry.AsSpan(0, 4));
            result.Add(MapCpu(cpu));
        }
        return result;
    }

    private static string MapCpu(int cpu) => cpu switch
    {
        0x0100000C => "arm64",
        0x01000007 => "x86_64",
        _ => throw new InvalidDataException("bundle_architecture_invalid"),
    };

    internal static string NormalizeArchitecture(string architecture) => architecture.Trim().ToLowerInvariant() switch
    {
        "arm64" => "arm64",
        "x64" or "x86_64" or "amd64" => "x86_64",
        _ => throw new InvalidDataException("bundle_architecture_invalid"),
    };

    private sealed class HelperManifest
    {
        [JsonPropertyName("architecture")]
        public string Architecture { get; set; } = string.Empty;

        [JsonPropertyName("helpers")]
        public List<HelperEntry> Helpers { get; set; } = [];
    }

    private sealed class HelperEntry
    {
        [JsonPropertyName("name")]
        public string Name { get; set; } = string.Empty;

        [JsonPropertyName("path")]
        public string Path { get; set; } = string.Empty;

        [JsonPropertyName("sha256")]
        public string Sha256 { get; set; } = string.Empty;

        [JsonPropertyName("size_bytes")]
        public long SizeBytes { get; set; }
    }
}
