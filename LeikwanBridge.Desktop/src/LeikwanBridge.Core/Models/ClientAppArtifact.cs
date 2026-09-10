using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed record ClientAppArtifact
{
    [JsonPropertyName("version")]
    public string Version { get; init; } = string.Empty;

    [JsonPropertyName("path")]
    public string Path { get; init; } = string.Empty;

    [JsonPropertyName("artifact_url")]
    public string ArtifactUrl { get; init; } = string.Empty;

    [JsonPropertyName("sha256")]
    public string Sha256 { get; init; } = string.Empty;

    [JsonPropertyName("size_bytes")]
    public long SizeBytes { get; init; }

    [JsonPropertyName("architecture")]
    public string Architecture { get; init; } = string.Empty;

    [JsonPropertyName("format")]
    public string Format { get; init; } = string.Empty;

    [JsonPropertyName("runtime_sha256")]
    public Dictionary<string, string> RuntimeSha256 { get; init; } = new(StringComparer.OrdinalIgnoreCase);

    [JsonIgnore]
    public ClientAppArtifact? ManualInstaller { get; init; }
}

public sealed record ClientAppArtifactManifestResponse
{
    [JsonPropertyName("component")]
    public string Component { get; init; } = string.Empty;

    [JsonPropertyName("manifest")]
    public Dictionary<string, ClientAppArtifact> Manifest { get; init; } = new(StringComparer.Ordinal);
}

public sealed record PreparedAppUpdate(
    ClientAppArtifact Artifact,
    string PackagePath,
    string StagedBundlePath,
    string MainExecutableSha256);

public sealed record AppUpdateLaunchResult(
    bool Started,
    bool RequiresManualInstall,
    bool Deferred,
    string Code,
    string Path = "");
