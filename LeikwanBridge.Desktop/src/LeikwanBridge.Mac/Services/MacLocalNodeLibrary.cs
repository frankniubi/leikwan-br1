using System.Globalization;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// Phase 1 node library: the Keychain-backed saved nodes of <see cref="IMacConnectionService"/>.
/// It has no cloud: upload / sync / refresh report <c>cloud_sync_unavailable</c> and
/// pairing write-through is a no-op, until the shared Core sync service is wired in.
/// </summary>
public sealed class MacLocalNodeLibrary : INodeLibrary
{
    public const string CloudUnavailableCode = "cloud_sync_unavailable";

    private readonly IMacConnectionService _connections;

    public MacLocalNodeLibrary(IMacConnectionService connections)
    {
        _connections = connections;
    }

    public event Action? Changed;

    public event Action<IReadOnlyList<NodePairing>>? PanelPairingsChanged { add { } remove { } }

    public bool IsCloudAvailable => false;

    public IReadOnlyList<NodeListItem> Nodes => Project(_connections.Profiles, _connections.Selection);

    public Task ReloadAsync(CancellationToken ct = default)
    {
        Changed?.Invoke();
        return Task.CompletedTask;
    }

    public async Task<NodeListItem> ImportShareLinkAsync(string link, CancellationToken ct = default)
    {
        if (!MacConnectionUriParser.TryParse(link, out var profile, out var error))
            throw new NodeLibraryException(string.IsNullOrEmpty(error) ? "proxy_uri_invalid" : error);
        var before = _connections.Profiles.Select(item => item.Id).ToHashSet();
        profile.Id = 0;
        try
        {
            await _connections.SaveProfileAsync(profile, ct).ConfigureAwait(false);
        }
        catch (Exception exception) when (exception is ArgumentException or FormatException or InvalidOperationException)
        {
            throw new NodeLibraryException("proxy_uri_invalid");
        }
        var created = _connections.Profiles.FirstOrDefault(item => !before.Contains(item.Id))
            ?? _connections.Profiles.LastOrDefault()
            ?? throw new NodeLibraryException("node_save_failed");
        Changed?.Invoke();
        return Project([created], _connections.Selection)[0];
    }

    public Task UploadAsync(string key, CancellationToken ct = default)
        => Task.FromException(new NodeLibraryException(CloudUnavailableCode));

    public Task<NodeSyncSummary> SyncAllAsync(CancellationToken ct = default)
        => Task.FromException<NodeSyncSummary>(new NodeLibraryException(CloudUnavailableCode));

    public Task RefreshFromCloudAsync(CancellationToken ct = default)
        => Task.FromException(new NodeLibraryException(CloudUnavailableCode));

    public Task PairAsync(int forwardId, string? key, CancellationToken ct = default)
        => Task.CompletedTask;

    public async Task DeleteAsync(string key, CancellationToken ct = default)
    {
        if (!TryParseKey(key, out var id))
            throw new NodeLibraryException("node_not_found");
        await _connections.DeleteProfileAsync(id, ct).ConfigureAwait(false);
        Changed?.Invoke();
    }

    public static bool TryParseKey(string? key, out int id)
        => int.TryParse(key, NumberStyles.None, CultureInfo.InvariantCulture, out id) && id > 0;

    public static string KeyFor(MacConnectionProfile profile)
        => profile.Id.ToString(CultureInfo.InvariantCulture);

    public static IReadOnlyList<NodeListItem> Project(
        IReadOnlyList<MacConnectionProfile> profiles,
        MacConnectionSelection selection)
        => profiles
            .Where(profile => profile.Id > 0)
            .Select(profile => new NodeListItem(
                KeyFor(profile),
                string.IsNullOrWhiteSpace(profile.Name) ? profile.DisplayProtocol : profile.Name,
                profile.DisplayProtocol,
                FormatEndpoint(profile.Server, profile.Port),
                CloudStateFor(profile.Protocol),
                selection.ForwardProfiles
                    .Where(pair => pair.Value == profile.Id)
                    .Select(pair => pair.Key)
                    .OrderBy(id => id)
                    .ToList()))
            .ToList();

    /// <summary>The panel hosts Shadowsocks, SOCKS5 and VLESS nodes; anything else stays local.</summary>
    public static NodeCloudState CloudStateFor(string? protocol)
        => protocol?.Trim().ToLowerInvariant() is MacConnectionProtocol.Shadowsocks or "socks5" or "vless"
            ? NodeCloudState.LocalOnly
            : NodeCloudState.Unsupported;

    private static string FormatEndpoint(string server, int port)
    {
        if (string.IsNullOrWhiteSpace(server))
            return string.Empty;
        var host = server.Contains(':') && !server.StartsWith('[') ? $"[{server}]" : server;
        return port > 0 ? $"{host}:{port.ToString(CultureInfo.InvariantCulture)}" : host;
    }
}
