using System.Collections.ObjectModel;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Mac.Localization;
using LeikwanBridge.Mac.Services;

namespace LeikwanBridge.Mac.ViewModels;

/// <summary>A service (forward) a node can be paired with.</summary>
public sealed record NodeServiceOption(int ForwardId, string Name)
{
    public static NodeServiceOption None => new(0, L.T("NodesNotPaired", "Not paired"));
}

/// <summary>
/// Nodes page: the user's own nodes with their cloud state, share-link import, in-place
/// editing and the cloud actions (upload, sync all, refresh from cloud, pair with a service).
/// It only talks to <see cref="INodeLibrary"/> plus callbacks owned by the main view model.
/// </summary>
public sealed partial class NodesViewModel : ObservableObject
{
    private readonly Func<INodeLibrary?> _library;
    private readonly Func<string, int, Task> _pair;
    private readonly Func<string, Task> _delete;
    private readonly Action<Action> _dispatch;
    private readonly Action<string>? _editClassic;
    private IReadOnlyList<NodeServiceOption> _serviceOptions = [NodeServiceOption.None];

    public NodesViewModel(
        Func<INodeLibrary?> library,
        Func<string, int, Task> pair,
        Func<string, Task> delete,
        Action<Action> dispatch,
        Action<string>? editClassic = null)
    {
        _library = library;
        _pair = pair;
        _delete = delete;
        _dispatch = dispatch;
        _editClassic = editClassic;
    }

    public ObservableCollection<NodeCardViewModel> Nodes { get; } = [];

    public NodeEditorViewModel Editor { get; } = new();

    [ObservableProperty] private string _importLink = string.Empty;
    [ObservableProperty] private string _errorMessage = string.Empty;
    [ObservableProperty] private string _statusMessage = string.Empty;
    [ObservableProperty] private bool _isWorking;

    public bool HasNodes => Nodes.Count > 0;
    public bool IsCloudAvailable => _library()?.IsCloudAvailable == true;
    public string CloudHint => IsCloudAvailable
        ? L.T("NodesCloudHintOn", "Hosted nodes follow this client to every trusted device.")
        : L.T("NodesCloudHintOff", "Sign in to sync nodes.");
    public int HostedCount => Nodes.Count(node => node.IsHosted);

    /// <summary>Rebuilds the cards from the library and the current service list.</summary>
    public void Refresh(IReadOnlyList<NodeServiceOption> services)
    {
        _serviceOptions = [NodeServiceOption.None, .. services];
        var items = _library()?.Nodes ?? [];
        Nodes.Clear();
        foreach (var item in items)
            Nodes.Add(new NodeCardViewModel(item, _serviceOptions, OnCardPaired));
        if (Editor.IsOpen && !items.Any(item => item.Key == Editor.Key))
            Editor.Close();
        OnPropertyChanged(nameof(HasNodes));
        OnPropertyChanged(nameof(IsCloudAvailable));
        OnPropertyChanged(nameof(CloudHint));
        OnPropertyChanged(nameof(HostedCount));
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
            StatusMessage = L.F("NodesImported", "Imported {0}.", node.Name);
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
            StatusMessage = L.F("NodesUploaded", "{0} is now hosted in the cloud.", node.Name);
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
            StatusMessage = L.F("NodesSyncSummary",
                "Uploaded {0}, already in cloud {1}, not supported {2}, failed {3}.",
                summary.Uploaded, summary.AlreadyInCloud, summary.Unsupported, summary.Failed);
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
            StatusMessage = L.T("NodesRefreshed", "Nodes refreshed from the cloud.");
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
                ? L.F("NodesDeletedEverywhere", "{0} was removed from every device of this client.", node.Name)
                : L.F("NodesDeleted", "{0} was removed.", node.Name);
        });
    }

    /// <summary>Opens the in-place editor (SS / SOCKS5 / VLESS) or the classic editor for WireGuard / external.</summary>
    [RelayCommand]
    private void Edit(NodeCardViewModel? node)
    {
        ErrorMessage = string.Empty;
        StatusMessage = string.Empty;
        if (node is null || _library() is not { } library)
            return;
        var editable = library.GetEditableNode(node.Key);
        if (editable is null)
        {
            Editor.Close();
            _editClassic?.Invoke(node.Key);
            return;
        }
        Editor.Open(node.Key, editable, node.IsHosted);
    }

    [RelayCommand]
    private Task SaveEditAsync()
    {
        if (!Editor.IsOpen || _library() is not { } library)
            return Task.CompletedTask;
        Editor.Error = string.Empty;
        return RunAsync(async () =>
        {
            var hosted = Editor.IsHosted;
            try
            {
                var node = Editor.Build();
                await library.SaveNodeAsync(node);
                StatusMessage = hosted
                    ? L.F("NodesSavedEverywhere", "{0} was saved and synced to every device.", node.Name)
                    : L.F("NodesSaved", "{0} was saved.", node.Name);
                Editor.Close();
            }
            catch (NodeLibraryException exception)
            {
                Editor.Error = DescribeError(exception.Code);
                throw;
            }
        });
    }

    [RelayCommand]
    private void CancelEdit() => Editor.Close();

    private void OnCardPaired(NodeCardViewModel node, int forwardId)
        => _ = RunAsync(async () =>
        {
            await _pair(node.Key, forwardId);
            StatusMessage = forwardId > 0
                ? L.F("NodesPaired", "{0} is paired with {1}.", node.Name,
                    _serviceOptions.FirstOrDefault(option => option.ForwardId == forwardId)?.Name)
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
            ErrorMessage = L.T("NodesOperationFailed", "The node operation could not be completed.");
        }
        finally
        {
            IsWorking = false;
            _dispatch(Refresh);
        }
    }

    public static string DescribeError(string code) => code switch
    {
        "proxy_uri_required" => L.T("NodeErrUriRequired", "Paste a share link first."),
        "proxy_protocol_unsupported" => L.T("NodeErrProtocol", "This share link protocol is not supported on macOS yet."),
        "proxy_plugin_unsupported" => L.T("NodeErrPlugin", "Shadowsocks plugin links are not supported."),
        "proxy_wg_config_invalid" => L.T("NodeErrWireGuard", "The WireGuard configuration is incomplete."),
        "proxy_credentials_invalid" => L.T("NodeErrCredentials", "The Shadowsocks credentials are invalid."),
        "proxy_endpoint_invalid" or "proxy_port_invalid" => L.T("NodeErrEndpoint", "The node server or port is invalid."),
        MacLocalNodeLibrary.CloudUnavailableCode => L.T("NodeErrCloudUnavailable", "Cloud sync is not available in this build yet."),
        "hosted_node_limit_reached" => L.T("NodeErrLimit", "This client already hosts the maximum number of nodes."),
        "hosted_node_duplicate" => L.T("NodeErrHostedDuplicate", "That node is already in the cloud."),
        "hosted_nodes_unavailable" => L.T("NodeErrPanelUnavailable", "The panel cannot store nodes right now. Try again later."),
        "rate_limited" => L.T("NodeErrRateLimited", "Too many changes in a short time. Wait a minute and try again."),
        "node_not_found" or "hosted_node_not_found" => L.T("NodeErrNotFound", "The node no longer exists. The list was refreshed."),
        "node_duplicate" => L.T("NodeErrDuplicate", "That node is already in the list."),
        "hosted_nodes_offline" => L.T("NodeErrOffline", "Sign in to use cloud nodes."),
        "hosted_nodes_unsupported" => L.T("NodeErrPanelOld", "This panel does not support cloud nodes yet."),
        "hosted_nodes_network" => L.T("NodeErrNetwork", "The panel could not be reached. Check the connection and try again."),
        "device_limit_reached" => L.T("NodeErrDeviceLimit", "Device limit reached. Disconnect another device first."),
        "hosted_node_invalid" => L.T("NodeErrInvalid", "The node settings are invalid. Check the protocol fields."),
        _ when code.StartsWith("node_", StringComparison.Ordinal) => L.T("NodeErrLinkInvalid", "The share link is invalid or uses settings that are not supported."),
        _ => L.T("NodeErrImport", "The share link could not be imported."),
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
        var first = item.PairedForwardIds.Count > 0 ? item.PairedForwardIds[0] : 0;
        SelectedService = serviceOptions.FirstOrDefault(option => option.ForwardId == first) ?? serviceOptions[0];
        _loading = false;
    }

    public string Key { get; }
    public string Name { get; }
    public string Protocol { get; }
    public string Endpoint { get; }
    public NodeCloudState CloudState { get; }
    public IReadOnlyList<NodeServiceOption> ServiceOptions { get; }
    public bool IsHosted => CloudState == NodeCloudState.Hosted;
    public bool IsLocalOnly => CloudState == NodeCloudState.LocalOnly;
    public bool IsCloudUnsupported => CloudState == NodeCloudState.Unsupported;
    public string CloudLabel => CloudState switch
    {
        NodeCloudState.Hosted => L.T("NodeBadgeCloud", "Cloud"),
        NodeCloudState.LocalOnly => L.T("NodeBadgeThisMac", "This Mac"),
        _ => L.T("NodeBadgeLocalOnly", "Local only"),
    };
    public string CloudTooltip => CloudState switch
    {
        NodeCloudState.Hosted => L.T("NodeBadgeCloudTip", "Hosted by the panel and synced to every trusted device of this client."),
        NodeCloudState.LocalOnly => L.T("NodeBadgeThisMacTip", "Stored on this Mac. Upload it to use it on your other devices."),
        _ => L.T("NodeBadgeLocalOnlyTip", "This node can't be synced; it stays on this Mac."),
    };

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
