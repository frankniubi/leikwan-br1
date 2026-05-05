using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class WindowsAppUpdateManifest
{
    [JsonPropertyName("windows_client_app")]
    public WindowsClientAppArtifact WindowsClientApp { get; set; } = new();
}

public sealed class WindowsClientAppArtifact
{
    [JsonPropertyName("version")]
    public string Version { get; set; } = "";

    [JsonPropertyName("path")]
    public string Path { get; set; } = "";

    [JsonPropertyName("artifact_url")]
    public string ArtifactUrl { get; set; } = "";
}
