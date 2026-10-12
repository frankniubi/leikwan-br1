using System.IO;
using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Nodes;
using Serilog;

namespace LeikwanBridge.App.ViewModels;

/// <summary>
/// Cloud nodes ("上云") on Windows. The user's nodes are the saved proxy profiles; a hosted
/// node is a profile with a panel id. <see cref="HostedNodeSyncService"/> (Core, shared with
/// macOS) does the two-way sync; this part wires it to the profile list, the per-forward
/// pairing map and the config pull.
///
/// Pairing rules (same as Android): a panel pairing that appears or changes becomes the
/// forward's node on this device; picking a hosted node for a forward here is written back
/// to the panel (every device of the client); picking a local-only node stays on this device.
/// </summary>
public partial class MainViewModel
{
    private HostedNodeSyncService? _nodeSync;
    private bool _applyingPanelPairings;

    [ObservableProperty] private bool _isCloudBusy;
    [ObservableProperty] private string _cloudStatusText = string.Empty;
    [ObservableProperty] private bool _hasCloudError;

    /// <summary>
    /// client.allow_inbound_proxy from the last pull. Forward listeners bind 127.0.0.1 unless
    /// this is true; the "connect from your LAN" hint is shown only then.
    /// </summary>
    [ObservableProperty] private bool _allowInboundProxy;

    /// <summary>Panel API used for hosted nodes. Defaults to the signed-in client's API; tests substitute it.</summary>
    public Func<IHostedNodeApi?>? HostedNodeApiFactory { get; set; }

    /// <summary>Raised when cloud sync changed the per-forward pairing so the window can persist the config.</summary>
    public event Action? CloudPairingsApplied;

    public HostedNodeSyncService? NodeSync => _nodeSync;

    public int HostedNodeCount => ProxyProfiles.Count(profile => profile.IsHosted);

    public int LocalNodeCount => ProxyProfiles.Count(profile => !profile.IsHosted && !profile.IsExternal);

    private void CreateNodeSync(string stateDir)
    {
        if (_nodeSync is not null || _proxyService is null)
            return;
        _nodeSync = new HostedNodeSyncService(
            () => HostedNodeApiFactory is { } factory ? factory() : _api,
            new WindowsProxyProfileNodeRepository(_proxyService),
            new JsonFileHostedNodeSyncStateStore(Path.Combine(stateDir, "hosted-nodes-state.json")),
            () => _config.ActiveClientId > 0 ? _config.ActiveClientId : _config.ClientId);
        _nodeSync.StatusChanged += status => PostToUi(() => ApplyCloudStatus(status));
        _nodeSync.NodesChanged += () => PostToUi(() =>
        {
            RefreshProxyProfiles();
            OnPropertyChanged(nameof(HostedNodeCount));
            OnPropertyChanged(nameof(LocalNodeCount));
        });
        _nodeSync.PanelPairingsChanged += pairings => PostToUi(() => ApplyPanelPairings(pairings));
    }

    private async Task<HostedNodeSyncService> RequireNodeSyncAsync(CancellationToken ct)
    {
        await EnsureProxyServiceAsync(ct);
        return _nodeSync ?? throw new InvalidOperationException("hosted_nodes_unavailable");
    }

    /// <summary>Applies the per-client flags of a config pull (UI thread safe).</summary>
    public void ApplyClientMeta(ClientMeta client)
    {
        ArgumentNullException.ThrowIfNull(client);
        PostToUi(() => AllowInboundProxy = client.AllowInboundProxy);
    }

    /// <summary>Called after each config pull with client.hosted_nodes_revision; never throws.</summary>
    public async Task SyncHostedNodesIfChangedAsync(string? revision, CancellationToken ct = default)
    {
        if (revision is null || _nodeSync is null || IsShuttingDown)
            return;
        try
        {
            await _nodeSync.SyncIfRevisionChangedAsync(revision, ct);
        }
        catch (OperationCanceledException)
        {
        }
        catch (Exception exception)
        {
            Log.Warning("hosted node sync failed: {Code}", HostedNodeSyncService.ErrorCode(exception));
        }
    }

    /// <summary>Imports pasted ss:// / socks5:// / vless:// links (one or many) as local nodes.</summary>
    public async Task<UserNodeImportResult> ImportNodesAsync(string? text, CancellationToken ct = default)
    {
        var sync = await RequireNodeSyncAsync(ct);
        var result = await sync.ImportAsync(text, ct);
        // A rejected link changes nothing: no refresh, so an unsaved editor draft survives.
        if (result.Added.Count == 0 && result.Duplicates == 0)
            throw new InvalidOperationException(string.IsNullOrWhiteSpace(result.FirstError) ? "node_uri_invalid" : result.FirstError);
        if (result.Added.Count > 0)
            RefreshProxyProfiles();
        if (result.Added.FirstOrDefault() is { } first)
        {
            SelectedProxyProfileId = first.LocalId;
            ProxyEditor.Load(ProxyProfiles.FirstOrDefault(profile => profile.Id == first.LocalId));
        }
        AddLog($"Imported {result.Added.Count} node(s); {result.Duplicates} already present.");
        return result;
    }

    /// <summary>Uploads one local node; forwards it is paired with on this device are paired on the panel too.</summary>
    public async Task UploadNodeAsync(int profileId, CancellationToken ct = default)
    {
        var sync = await RequireNodeSyncAsync(ct);
        var uploaded = await sync.UploadAsync(profileId, ct: ct);
        RefreshProxyProfiles();
        var localId = uploaded.LocalId;
        foreach (var forwardId in _config.ProxyProfileIdsByForwardId
                     .Where(pair => pair.Value == profileId || pair.Value == localId)
                     .Select(pair => pair.Key).ToList())
        {
            _config.ProxyProfileIdsByForwardId[forwardId] = localId;
            await sync.PushPairingAsync(forwardId, localId, ct);
        }
        RefreshProxyForwardRows();
        AddLog($"Uploaded node {uploaded.Name} to the cloud.");
    }

    public async Task<HostedBulkUploadResult> UploadAllNodesAsync(CancellationToken ct = default)
    {
        var sync = await RequireNodeSyncAsync(ct);
        var result = await sync.UploadAllLocalAsync(ct);
        RefreshProxyProfiles();
        CloudStatusText = string.Format(GetString("NodesSyncAllResult"), result.Uploaded, result.AlreadyHosted, result.Unsupported, result.Failed);
        HasCloudError = result.Failed > 0;
        return result;
    }

    public async Task RefreshNodesFromCloudAsync(CancellationToken ct = default)
    {
        var sync = await RequireNodeSyncAsync(ct);
        await sync.RefreshAsync(ct);
        RefreshProxyProfiles();
    }

    /// <summary>Canonical share link of a saved node (for copying into other software), or null.</summary>
    public string? ShareLinkForProfile(int profileId)
        => ProxyProfiles.FirstOrDefault(profile => profile.Id == profileId) is { } profile
            && WindowsProxyProfileNodeRepository.ToNode(profile) is { } node
            ? UserNodeShareLink.Format(node)
            : null;

    private void ApplyPanelPairings(IReadOnlyList<HostedNodePairing> pairings)
    {
        _applyingPanelPairings = true;
        try
        {
            var changed = false;
            foreach (var pairing in pairings)
            {
                if (!ProxyProfiles.Any(profile => profile.Id == pairing.LocalNodeId))
                    continue;
                if (_config.ProxyProfileIdsByForwardId.TryGetValue(pairing.ForwardId, out var current) && current == pairing.LocalNodeId)
                    continue;
                SetProxyProfileForForward(pairing.ForwardId, pairing.LocalNodeId);
                changed = true;
            }
            if (changed)
            {
                AddLog("Applied node pairings from the cloud.");
                CloudPairingsApplied?.Invoke();
            }
        }
        finally
        {
            _applyingPanelPairings = false;
        }
    }

    /// <summary>A hosted node picked for a forward on this device is paired on the panel for every device.</summary>
    private void PushPairingInBackground(int forwardId, int profileId)
    {
        if (_applyingPanelPairings || _nodeSync is null || forwardId <= 0 || profileId <= 0)
            return;
        if (!ProxyProfiles.Any(profile => profile.Id == profileId && profile.IsHosted))
            return;
        var sync = _nodeSync;
        _ = Task.Run(async () =>
        {
            try { await sync.PushPairingAsync(forwardId, profileId, _lifecycleCts.Token); }
            catch (OperationCanceledException) { }
            catch (Exception exception)
            {
                Log.Warning("hosted node pairing not saved: {Code}", HostedNodeSyncService.ErrorCode(exception));
            }
        });
    }

    private void ApplyCloudStatus(HostedNodeSyncStatus status)
    {
        IsCloudBusy = status.IsBusy;
        if (status.IsBusy)
        {
            CloudStatusText = GetString("NodesSyncing");
            HasCloudError = false;
            return;
        }
        if (!string.IsNullOrWhiteSpace(status.LastError))
        {
            CloudStatusText = DescribeCloudError(status.LastError);
            HasCloudError = true;
            return;
        }
        HasCloudError = false;
        CloudStatusText = status.LastSyncedAt is { } at
            ? string.Format(GetString("NodesLastSynced"), at.ToLocalTime().ToString("HH:mm:ss"))
            : GetString("NodesNotSynced");
    }

    /// <summary>Localized text for a sync error code (codes only, never node content).</summary>
    public static string DescribeCloudError(string code)
    {
        var key = code switch
        {
            "hosted_nodes_offline" => "NodesErrorOffline",
            "device_untrusted" => "NodesErrorUntrusted",
            "hosted_nodes_unsupported" or "hosted_nodes_unavailable" => "NodesErrorUnsupported",
            "client_online_limit_reached" => "DeviceLimitStatus",
            "rate_limited" => "NodesErrorRateLimited",
            "hosted_node_limit_reached" => "NodesErrorLimit",
            "hosted_node_duplicate" => "NodesErrorDuplicate",
            "hosted_node_not_found" => "NodesErrorNotFound",
            "hosted_node_invalid" => "NodesErrorInvalid",
            "hosted_nodes_network" => "NodesErrorNetwork",
            _ when code.StartsWith("node_", StringComparison.Ordinal) => "NodesErrorInvalidLink",
            _ => "",
        };
        if (key.Length == 0)
            return string.Format(GetString("NodesErrorGeneric"), code);
        return GetString(key);
    }

    private void PostToUi(Action action)
    {
        if (IsShuttingDown)
            return;
        var dispatcher = System.Windows.Application.Current?.Dispatcher;
        if (dispatcher is null || dispatcher.CheckAccess())
        {
            action();
            return;
        }
        if (!dispatcher.HasShutdownStarted)
            dispatcher.BeginInvoke(action);
    }
}
