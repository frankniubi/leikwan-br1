using System.Text.Json;
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class WindowsAppUpdateManifest
{
    [JsonPropertyName("windows_client_app")]
    public WindowsClientAppArtifact WindowsClientApp { get; set; } = new();
}

public sealed class WindowsClientArtifactManifestResponse
{
    [JsonPropertyName("component")]
    public string Component { get; set; } = "";

    [JsonPropertyName("manifest")]
    public WindowsClientArtifactManifestEntries Manifest { get; set; } = new();
}

public sealed class WindowsClientArtifactManifestEntries
{
    [JsonPropertyName("windows_client_app")]
    public WindowsClientAppArtifact WindowsClientApp { get; set; } = new();

    [JsonExtensionData]
    public Dictionary<string, System.Text.Json.JsonElement> Components { get; set; } = new(StringComparer.Ordinal);
}

public sealed class WindowsClientAppArtifact
{
    [JsonPropertyName("version")]
    public string Version { get; set; } = "";

    [JsonPropertyName("path")]
    public string Path { get; set; } = "";

    [JsonPropertyName("artifact_url")]
    public string ArtifactUrl { get; set; } = "";

    [JsonPropertyName("sha256")]
    public string Sha256 { get; set; } = "";

    [JsonPropertyName("size_bytes")]
    public long SizeBytes { get; set; }

    [JsonPropertyName("runtime_sha256")]
    public Dictionary<string, string> RuntimeSha256 { get; set; } = new(StringComparer.OrdinalIgnoreCase);
}
