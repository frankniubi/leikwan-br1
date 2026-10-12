using System.Collections.ObjectModel;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Mac.Services;

namespace LeikwanBridge.Mac.ViewModels;

/// <summary>A service (forward) a node can be paired with.</summary>
public sealed record NodeServiceOption(int ForwardId, string Name)
{
    public static NodeServiceOption None { get; } = new(0, "Not paired");
}

/// <summary>
/// Nodes page: the user's own nodes with their cloud state, share-link import and
/// the cloud actions (upload, sync all, refresh from cloud, pair with a service).
/// It only talks to <see cref="INodeLibrary"/> plus two callbacks owned by the main
/// view model, so the shared Core sync service can replace the library later.
/// </summary>
public sealed partial class NodesViewModel : ObservableObject
{
    private readonly Func<INodeLibrary?> _library;
    private readonly Func<string, int, Task> _pair;
    private readonly Func<string, Task> _delete;
    private readonly Action<Action> _dispatch;
    private IReadOnlyList<NodeServiceOption> _serviceOptions = [NodeServiceOption.None];

    public NodesViewModel(
        Func<INodeLibrary?> library,
        Func<string, int, Task> pair,
        Func<string, Task> delete,
        Action<Action> dispatch)
    {
        _library = library;
        _pair = pair;
        _delete = delete;
        _dispatch = dispatch;
    }

    public ObservableCollection<NodeCardViewModel> Nodes { get; } = [];

    [ObservableProperty] private string _importLink = string.Empty;
    [ObservableProperty] private string _errorMessage = string.Empty;
    [ObservableProperty] private string _statusMessage = string.Empty;
    [ObservableProperty] private bool _isWorking;

    public bool HasNodes => Nodes.Count > 0;
    public bool IsCloudAvailable => _library()?.IsCloudAvailable == true;
    public string CloudHint => IsCloudAvailable
        ? "Hosted nodes follow this client to every trusted device."
        : "Sign in to sync hosted nodes. Nodes are stored in this Mac's Keychain.";
    public int HostedCount => Nodes.Count(node => node.IsHosted);
    public string Summary => Nodes.Count == 0
        ? "No nodes yet"
        : $"{Nodes.Count} node{(Nodes.Count == 1 ? string.Empty : "s")} · {HostedCount} in cloud";

    /// <summary>Rebuilds the cards from the library and the current service list.</summary>
    public void Refresh(IReadOnlyList<NodeServiceOption> services)
    {
        _serviceOptions = [NodeServiceOption.None, .. services];
        var items = _library()?.Nodes ?? [];
        Nodes.Clear();
        foreach (var item in items)
            Nodes.Add(new NodeCardViewModel(item, _serviceOptions, OnCardPaired));
        OnPropertyChanged(nameof(HasNodes));
        OnPropertyChanged(nameof(IsCloudAvailable));
        OnPropertyChanged(nameof(CloudHint));
        OnPropertyChanged(nameof(HostedCount));
        OnPropertyChanged(nameof(Summary));
        UploadCommand.NotifyCanExecuteChanged();
        SyncAllCommand.NotifyCanExecuteChanged();
        RefreshFromCloudCommand.NotifyCanExecuteChanged();
    }

    public void Refresh() => Refresh(_serviceOptions.Where(option => option.ForwardId > 0).ToList());

    [RelayCommand]
    private async Task ImportShareLinkAsync()
    {
        ErrorMessage = string.Empty;
        StatusMessage = string.Empty;
        var library = _library();
        if (library is null)
            return;
        if (string.IsNullOrWhiteSpace(ImportLink))
        {
            ErrorMessage = DescribeError("proxy_uri_required");
            return;
        }
        await RunAsync(async () =>
        {
            var node = await library.ImportShareLinkAsync(ImportLink.Trim());
            ImportLink = string.Empty;
            StatusMessage = $"Imported {node.Name}.";
        });
    }

    [RelayCommand(CanExecute = nameof(CanUpload))]
    private Task UploadAsync(NodeCardViewModel? node)
    {
        if (node is null || _library() is not { } library)
            return Task.CompletedTask;
        return RunAsync(async () =>
        {
            await library.UploadAsync(node.Key);
            StatusMessage = $"{node.Name} is now hosted in the cloud.";
        });
    }

    private bool CanUpload(NodeCardViewModel? node) => IsCloudAvailable && node is { IsLocalOnly: true };

    [RelayCommand(CanExecute = nameof(CanUseCloud))]
    private Task SyncAllAsync()
    {
        if (_library() is not { } library)
            return Task.CompletedTask;
        return RunAsync(async () =>
        {
            var summary = await library.SyncAllAsync();
            StatusMessage = $"Uploaded {summary.Uploaded}, already in cloud {summary.AlreadyInCloud}, "
                + $"not supported {summary.Unsupported}, failed {summary.Failed}.";
        });
    }

    [RelayCommand(CanExecute = nameof(CanUseCloud))]
    private Task RefreshFromCloudAsync()
    {
        if (_library() is not { } library)
            return Task.CompletedTask;
        return RunAsync(async () =>
        {
            await library.RefreshFromCloudAsync();
            StatusMessage = "Nodes refreshed from the cloud.";
        });
    }

    private bool CanUseCloud() => IsCloudAvailable;

    [RelayCommand]
    private Task DeleteAsync(NodeCardViewModel? node)
    {
        if (node is null)
            return Task.CompletedTask;
        if (node.IsHosted && !node.IsDeleteArmed)
        {
            // A hosted node is deleted on every device of this client: ask once more.
            node.IsDeleteArmed = true;
            return Task.CompletedTask;
        }
        return RunAsync(async () =>
        {
            await _delete(node.Key);
            StatusMessage = node.IsHosted
                ? $"{node.Name} was removed from every device of this client."
                : $"{node.Name} was removed.";
        });
    }

    private void OnCardPaired(NodeCardViewModel node, int forwardId)
        => _ = RunAsync(async () =>
        {
            await _pair(node.Key, forwardId);
            StatusMessage = forwardId > 0
                ? $"{node.Name} is paired with {_serviceOptions.FirstOrDefault(option => option.ForwardId == forwardId)?.Name}."
                : string.Empty;
        });

    private async Task RunAsync(Func<Task> action)
    {
        ErrorMessage = string.Empty;
        IsWorking = true;
        try
        {
            await action();
        }
        catch (NodeLibraryException exception)
        {
            ErrorMessage = DescribeError(exception.Code);
        }
        catch (Exception)
        {
            ErrorMessage = "The node operation could not be completed.";
        }
        finally
        {
            IsWorking = false;
            _dispatch(Refresh);
        }
    }

    public static string DescribeError(string code) => code switch
    {
        "proxy_uri_required" => "Paste a share link first.",
        "proxy_protocol_unsupported" => "This share link protocol is not supported on macOS yet.",
        "proxy_plugin_unsupported" => "Shadowsocks plugin links are not supported.",
        "proxy_wg_config_invalid" => "The WireGuard configuration is incomplete.",
        "proxy_credentials_invalid" => "The Shadowsocks credentials are invalid.",
        "proxy_endpoint_invalid" or "proxy_port_invalid" => "The node server or port is invalid.",
        MacLocalNodeLibrary.CloudUnavailableCode => "Cloud sync is not available in this build yet.",
        "hosted_node_limit_reached" => "This client already hosts the maximum number of nodes.",
        "hosted_node_duplicate" => "That node is already in the cloud.",
        "hosted_nodes_unavailable" => "The panel cannot store nodes right now. Try again later.",
        "rate_limited" => "Too many changes in a short time. Wait a minute and try again.",
        "node_not_found" or "hosted_node_not_found" => "The node no longer exists. The list was refreshed.",
        "node_duplicate" => "That node is already in the list.",
        "hosted_nodes_offline" => "Sign in to use cloud nodes.",
        "hosted_nodes_unsupported" => "This panel does not support cloud nodes yet.",
        "hosted_nodes_network" => "The panel could not be reached. Check the connection and try again.",
        "device_limit_reached" => "Device limit reached. Disconnect another device first.",
        "hosted_node_invalid" => "The panel rejected this node. Check its protocol settings.",
        _ when code.StartsWith("node_", StringComparison.Ordinal) => "The share link is invalid or uses settings that are not supported.",
        _ => "The share link could not be imported.",
    };
}

/// <summary>One node card on the Nodes page.</summary>
public sealed partial class NodeCardViewModel : ObservableObject
{
    private readonly Action<NodeCardViewModel, int> _paired;
    private bool _loading = true;

    public NodeCardViewModel(
        NodeListItem item,
        IReadOnlyList<NodeServiceOption> serviceOptions,
        Action<NodeCardViewModel, int> paired)
    {
        _paired = paired;
        Key = item.Key;
        Name = item.Name;
        Protocol = item.Protocol;
        Endpoint = item.Endpoint;
        CloudState = item.CloudState;
        ServiceOptions = serviceOptions;
        PairedServicesText = item.PairedForwardIds.Count == 0
            ? "Not paired with a service"
            : "Paired: " + string.Join(", ", item.PairedForwardIds.Select(id =>
                serviceOptions.FirstOrDefault(option => option.ForwardId == id)?.Name ?? $"Forward #{id}"));
        var first = item.PairedForwardIds.Count > 0 ? item.PairedForwardIds[0] : 0;
        SelectedService = serviceOptions.FirstOrDefault(option => option.ForwardId == first) ?? NodeServiceOption.None;
        _loading = false;
    }

    public string Key { get; }
    public string Name { get; }
    public string Protocol { get; }
    public string Endpoint { get; }
    public NodeCloudState CloudState { get; }
    public IReadOnlyList<NodeServiceOption> ServiceOptions { get; }
    public string PairedServicesText { get; }
    public bool IsHosted => CloudState == NodeCloudState.Hosted;
    public bool IsLocalOnly => CloudState == NodeCloudState.LocalOnly;
    public bool IsCloudUnsupported => CloudState == NodeCloudState.Unsupported;
    public string CloudLabel => CloudState switch
    {
        NodeCloudState.Hosted => "Cloud",
        NodeCloudState.LocalOnly => "This Mac",
        _ => "Local only",
    };
    public string CloudTooltip => CloudState switch
    {
        NodeCloudState.Hosted => "Hosted by the panel and synced to every trusted device of this client.",
        NodeCloudState.LocalOnly => "Stored on this Mac. Upload it to use it on your other devices.",
        _ => "The panel only hosts Shadowsocks, SOCKS5 and VLESS nodes; this one stays on this Mac.",
    };
    public string ProtocolInitial => string.IsNullOrEmpty(Protocol) ? "?" : Protocol[..1].ToUpperInvariant();

    [ObservableProperty] private NodeServiceOption? _selectedService;

    /// <summary>First delete click on a hosted node arms the confirmation.</summary>
    [ObservableProperty] private bool _isDeleteArmed;

    partial void OnSelectedServiceChanged(NodeServiceOption? value)
    {
        if (_loading || value is null)
            return;
        _paired(this, value.ForwardId);
    }
}
