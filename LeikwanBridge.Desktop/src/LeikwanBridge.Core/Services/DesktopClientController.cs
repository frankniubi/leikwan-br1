using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public interface IDesktopClientController : IAsyncDisposable
{
    event Action<DesktopClientSnapshot>? SnapshotChanged;
    event Action<IReadOnlyList<DesktopForwardSnapshot>>? ForwardsChanged;
    event Action<string>? NoticeRaised;
    event Action<string>? LogRaised;
    event Action<string>? UpdateAvailable;

    DesktopClientSnapshot Snapshot { get; }
    Task LoginAsync(string panelUrl, string email, string password, CancellationToken ct = default);
    Task LoginWithAgentTokenAsync(string panelUrl, int clientId, string agentToken, CancellationToken ct = default);
    Task SelectClientAsync(int clientId, CancellationToken ct = default);
    Task ConnectAsync(CancellationToken ct = default);
    Task RefreshAsync(CancellationToken ct = default);
    Task DisconnectAsync(CancellationToken ct = default);
    Task StartForwardAsync(int forwardId, CancellationToken ct = default);
    Task StopForwardAsync(int forwardId, CancellationToken ct = default);
    Task StartAllAsync(CancellationToken ct = default);
    Task StopAllAsync(CancellationToken ct = default);
    Task SignOutAsync(CancellationToken ct = default);
}

public sealed class DesktopClientController : IDesktopClientController
{
    private readonly AgentConfig _config;
    private readonly ClientPlatformIdentity _identity;
    private readonly Func<AgentConfig, CancellationToken, Task> _saveConfig;
    private readonly Func<string, string, string, CancellationToken, Task<DesktopLoginResponse>> _login;
    private readonly Func<string, string, int, CancellationToken, Task<ClientSelectionResponse>> _selectClient;
    private readonly Func<string, string, int, CancellationToken, Task<PullResponse>> _agentTokenPull;
    private readonly Func<AgentConfig, ClientPlatformIdentity, IDesktopRuntimeSession> _runtimeFactory;
    private readonly SemaphoreSlim _lifecycleGate = new(1, 1);
    private readonly object _snapshotLock = new();
    private IDesktopRuntimeSession? _runtimeSession;
    private DesktopClientSnapshot _snapshot;

    public DesktopClientController(
        AgentConfig config,
        ClientPlatformIdentity identity,
        Func<AgentConfig, CancellationToken, Task> saveConfig,
        Func<AgentConfig, ClientPlatformIdentity, IDesktopRuntimeSession> runtimeFactory,
        Func<string, string, string, CancellationToken, Task<DesktopLoginResponse>>? login = null,
        Func<string, string, int, CancellationToken, Task<ClientSelectionResponse>>? selectClient = null,
        Func<string, string, int, CancellationToken, Task<PullResponse>>? agentTokenPull = null)
    {
        _config = config;
        _identity = identity;
        _saveConfig = saveConfig;
        _runtimeFactory = runtimeFactory;
        _login = login ?? LoginWithMasterAsync;
        _selectClient = selectClient ?? SelectWithMasterAsync;
        _agentTokenPull = agentTokenPull ?? PullWithMasterAsync;
        _snapshot = SnapshotFromConfig(config);
    }

    public event Action<DesktopClientSnapshot>? SnapshotChanged;
    public event Action<IReadOnlyList<DesktopForwardSnapshot>>? ForwardsChanged;
    public event Action<string>? NoticeRaised;
    public event Action<string>? LogRaised;
    public event Action<string>? UpdateAvailable;

    public DesktopClientSnapshot Snapshot
    {
        get
        {
            lock (_snapshotLock)
                return _snapshot;
        }
    }

    public IReadOnlyList<ForwardConfig> RuntimeForwards
        => _runtimeSession?.Forwards ?? [];

    public async Task LoginAsync(string panelUrl, string email, string password, CancellationToken ct = default)
    {
        await _lifecycleGate.WaitAsync(ct);
        try
        {
            SetBusy(true);
            var normalizedPanel = panelUrl.TrimEnd('/');
            var response = await _login(normalizedPanel, email, password, ct);
            if (!response.Ok || string.IsNullOrWhiteSpace(response.SessionToken))
                throw new InvalidOperationException("login_failed");
            _config.MasterUrl = normalizedPanel;
            _config.DesktopSessionToken = response.SessionToken;
            _config.SignedInUserId = response.UserId;
            _config.SignedInUsername = response.Username;
            _config.SignedInEmail = response.Email;
            _config.AvailableClients = response.Clients.Take(128).ToList();
            await _saveConfig(_config, ct);
            PublishSnapshot(SnapshotFromConfig(_config) with
            {
                IsBusy = false,
                RequiresClientSelection = response.Clients.Count > 1,
                LastErrorCode = string.Empty,
            });
            if (response.Clients.Count == 1)
                await SelectClientCoreAsync(response.Clients[0].Id, ct);
        }
        catch
        {
            PublishFailure("login_failed");
            throw;
        }
        finally
        {
            SetBusy(false);
            _lifecycleGate.Release();
        }
    }

    public async Task LoginWithAgentTokenAsync(
        string panelUrl,
        int clientId,
        string agentToken,
        CancellationToken ct = default)
    {
        await _lifecycleGate.WaitAsync(ct);
        try
        {
            SetBusy(true);
            var normalizedPanel = NormalizePanelUrl(panelUrl);
            if (clientId <= 0 || string.IsNullOrWhiteSpace(agentToken))
                throw new InvalidOperationException("agent_token_login_invalid");

            var normalizedToken = agentToken.Trim();
            var response = await _agentTokenPull(normalizedPanel, normalizedToken, clientId, ct);
            if (!response.Ok)
                throw new InvalidOperationException("agent_token_login_failed");

            var returnedClient = response.Client;
            if (returnedClient is not null && returnedClient.Id > 0 && returnedClient.Id != clientId)
                throw new InvalidOperationException("agent_token_client_mismatch");

            _config.MasterUrl = normalizedPanel;
            _config.AgentToken = normalizedToken;
            _config.ClientId = clientId;
            _config.ActiveClientId = returnedClient?.Id > 0 ? returnedClient.Id : clientId;
            _config.ActiveClientName = returnedClient?.Name?.Trim() is { Length: > 0 } name
                ? name
                : $"Client {clientId}";
            _config.DesktopSessionToken = string.Empty;
            _config.SignedInUserId = 0;
            _config.SignedInUsername = string.Empty;
            _config.SignedInEmail = string.Empty;
            _config.AvailableClients = [];
            await _saveConfig(_config, ct);
            PublishSnapshot(SnapshotFromConfig(_config) with
            {
                IsBusy = false,
                RequiresClientSelection = false,
                LastErrorCode = string.Empty,
            });
        }
        catch
        {
            PublishFailure("agent_token_login_failed");
            throw;
        }
        finally
        {
            SetBusy(false);
            _lifecycleGate.Release();
        }
    }

    public async Task SelectClientAsync(int clientId, CancellationToken ct = default)
    {
        await _lifecycleGate.WaitAsync(ct);
        try
        {
            SetBusy(true);
            await SelectClientCoreAsync(clientId, ct);
        }
        catch
        {
            PublishFailure("client_selection_failed");
            throw;
        }
        finally
        {
            SetBusy(false);
            _lifecycleGate.Release();
        }
    }

    public async Task ConnectAsync(CancellationToken ct = default)
    {
        await _lifecycleGate.WaitAsync(ct);
        try
        {
            if (string.IsNullOrWhiteSpace(_config.MasterUrl)
                || string.IsNullOrWhiteSpace(_config.AgentToken)
                || _config.ActiveClientId <= 0)
                throw new InvalidOperationException("client_not_configured");
            SetBusy(true);
            await DisconnectCoreAsync();
            var session = _runtimeFactory(_config, _identity);
            session.ForwardsChanged += OnRuntimeForwardsChanged;
            session.ForwardRejected += (_, notice) => NoticeRaised?.Invoke(notice);
            session.LogRaised += message => LogRaised?.Invoke(message);
            _runtimeSession = session;
            await session.StartAsync(ct);
            PublishSnapshot(Snapshot with
            {
                IsConnected = true,
                IsBusy = false,
                ConnectionStatus = "connected",
                LastErrorCode = string.Empty,
            });
            LogRaised?.Invoke("connected");
        }
        catch
        {
            await DisconnectCoreAsync();
            PublishFailure("connection_failed");
            throw;
        }
        finally
        {
            SetBusy(false);
            _lifecycleGate.Release();
        }
    }

    public async Task DisconnectAsync(CancellationToken ct = default)
    {
        await _lifecycleGate.WaitAsync(ct);
        try
        {
            SetBusy(true);
            await DisconnectCoreAsync();
            PublishSnapshot(Snapshot with
            {
                IsConnected = false,
                IsBusy = false,
                ConnectionStatus = "disconnected",
                Forwards = [],
            });
            ForwardsChanged?.Invoke([]);
        }
        finally
        {
            SetBusy(false);
            _lifecycleGate.Release();
        }
    }

    public async Task RefreshAsync(CancellationToken ct = default)
    {
        await _lifecycleGate.WaitAsync(ct);
        try
        {
            var runtime = _runtimeSession ?? throw new InvalidOperationException("client_not_connected");
            SetBusy(true);
            await runtime.RefreshAsync(ct);
            PublishSnapshot(Snapshot with
            {
                IsConnected = true,
                IsBusy = false,
                ConnectionStatus = "connected",
                LastErrorCode = string.Empty,
            });
        }
        catch
        {
            // A refresh is intentionally non-destructive. Keep the current
            // runtime usable when the pull itself fails or times out.
            if (_runtimeSession is not null)
            {
                PublishSnapshot(Snapshot with
                {
                    IsConnected = true,
                    IsBusy = false,
                    ConnectionStatus = "connected",
                    LastErrorCode = "refresh_failed",
                });
                LogRaised?.Invoke("refresh_failed");
            }
            else
            {
                PublishFailure("refresh_failed");
            }
            throw;
        }
        finally
        {
            SetBusy(false);
            _lifecycleGate.Release();
        }
    }

    public Task StartForwardAsync(int forwardId, CancellationToken ct = default)
        => WithRuntimeAsync(runtime => runtime.StartForwardAsync(forwardId, ct));

    public Task StopForwardAsync(int forwardId, CancellationToken ct = default)
        => WithRuntimeAsync(runtime => runtime.StopForwardAsync(forwardId, ct));

    public Task StartAllAsync(CancellationToken ct = default)
        => WithRuntimeAsync(runtime => runtime.StartAllAsync(ct));

    public Task StopAllAsync(CancellationToken ct = default)
        => WithRuntimeAsync(runtime => runtime.StopAllAsync(ct));

    public async Task SignOutAsync(CancellationToken ct = default)
    {
        await DisconnectAsync(ct);
        await _lifecycleGate.WaitAsync(ct);
        try
        {
            _config.DesktopSessionToken = string.Empty;
            _config.AgentToken = string.Empty;
            _config.ClientId = 0;
            _config.ActiveClientId = 0;
            _config.ActiveClientName = string.Empty;
            _config.SignedInUserId = 0;
            _config.SignedInUsername = string.Empty;
            _config.SignedInEmail = string.Empty;
            _config.AvailableClients = [];
            await _saveConfig(_config, ct);
            PublishSnapshot(SnapshotFromConfig(_config));
        }
        finally
        {
            _lifecycleGate.Release();
        }
    }

    public void PublishNotice(string notice)
    {
        if (!string.IsNullOrWhiteSpace(notice))
            NoticeRaised?.Invoke(notice);
    }

    public void PublishUpdateAvailable(string version)
    {
        if (!string.IsNullOrWhiteSpace(version))
            UpdateAvailable?.Invoke(version);
    }

    public async ValueTask DisposeAsync()
    {
        await DisconnectAsync();
        _lifecycleGate.Dispose();
    }

    private async Task SelectClientCoreAsync(int clientId, CancellationToken ct)
    {
        if (clientId <= 0 || string.IsNullOrWhiteSpace(_config.DesktopSessionToken))
            throw new InvalidOperationException("client_selection_invalid");
        var selection = await _selectClient(_config.MasterUrl, _config.DesktopSessionToken, clientId, ct);
        if (!selection.Ok || selection.ClientId <= 0 || string.IsNullOrWhiteSpace(selection.AgentToken))
            throw new InvalidOperationException("client_selection_failed");
        _config.ClientId = selection.ClientId;
        _config.ActiveClientId = selection.ClientId;
        _config.ActiveClientName = selection.ClientName;
        _config.AgentToken = selection.AgentToken;
        await _saveConfig(_config, ct);
        PublishSnapshot(SnapshotFromConfig(_config) with { IsBusy = false, RequiresClientSelection = false });
    }

    private async Task WithRuntimeAsync(Func<IDesktopRuntimeSession, Task> action)
    {
        await _lifecycleGate.WaitAsync();
        try
        {
            var runtime = _runtimeSession ?? throw new InvalidOperationException("client_not_connected");
            SetBusy(true);
            await action(runtime);
        }
        finally
        {
            SetBusy(false);
            _lifecycleGate.Release();
        }
    }

    private void OnRuntimeForwardsChanged(IReadOnlyList<ForwardConfig> forwards)
    {
        var runtime = _runtimeSession;
        var publicForwards = forwards.Select(forward => new DesktopForwardSnapshot(
            forward.ForwardId,
            $"Forward {forward.ForwardId}",
            string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
                ? "ultra"
                : (!string.IsNullOrWhiteSpace(forward.ClientMode) ? forward.ClientMode : forward.Mode).Trim().ToLowerInvariant(),
            forward.ListenPort,
            runtime?.IsForwardRunning(forward.ForwardId) == true,
            runtime?.IsForwardRunning(forward.ForwardId) == true ? "running" : "stopped"))
            .ToList();
        PublishSnapshot(Snapshot with { Forwards = publicForwards });
        ForwardsChanged?.Invoke(publicForwards);
    }

    private async Task DisconnectCoreAsync()
    {
        var session = Interlocked.Exchange(ref _runtimeSession, null);
        if (session is null)
            return;
        await session.StopAsync();
        await session.DisposeAsync();
    }

    private void SetBusy(bool busy)
    {
        PublishSnapshot(Snapshot with { IsBusy = busy });
    }

    private void PublishFailure(string errorCode)
    {
        PublishSnapshot(Snapshot with
        {
            IsBusy = false,
            IsConnected = false,
            ConnectionStatus = "error",
            LastErrorCode = errorCode,
        });
    }

    private void PublishSnapshot(DesktopClientSnapshot snapshot)
    {
        lock (_snapshotLock)
            _snapshot = snapshot;
        SnapshotChanged?.Invoke(snapshot);
    }

    private DesktopClientSnapshot SnapshotFromConfig(AgentConfig config)
    {
        var hasDesktopSession = !string.IsNullOrWhiteSpace(config.DesktopSessionToken);
        var hasAgentToken = !string.IsNullOrWhiteSpace(config.AgentToken) && config.ActiveClientId > 0;
        return new DesktopClientSnapshot
        {
            IsSignedIn = hasDesktopSession || hasAgentToken,
            RequiresClientSelection = hasDesktopSession && config.ActiveClientId <= 0,
            IsConnected = false,
            PanelUrl = config.MasterUrl,
            SignedInUsername = config.SignedInUsername,
            SignedInEmail = config.SignedInEmail,
            ActiveClientName = config.ActiveClientName,
            ConnectionStatus = "disconnected",
            AvailableClients = config.AvailableClients.ToList(),
        };
    }

    private async Task<DesktopLoginResponse> LoginWithMasterAsync(
        string panelUrl,
        string email,
        string password,
        CancellationToken ct)
    {
        using var api = new MasterApiClient(panelUrl, string.Empty, platformIdentity: _identity);
        return await api.DesktopLoginAsync(email, password, ct);
    }

    private async Task<ClientSelectionResponse> SelectWithMasterAsync(
        string panelUrl,
        string sessionToken,
        int clientId,
        CancellationToken ct)
    {
        using var api = new MasterApiClient(panelUrl, string.Empty, platformIdentity: _identity);
        return await api.SelectClientAsync(sessionToken, clientId, ct);
    }

    private async Task<PullResponse> PullWithMasterAsync(
        string panelUrl,
        string agentToken,
        int clientId,
        CancellationToken ct)
    {
        using var api = new MasterApiClient(panelUrl, agentToken, platformIdentity: _identity);
        var response = await api.PullConfigsAsync(ct: ct);
        if (response.Client is { Id: > 0 } returned && returned.Id != clientId)
            throw new InvalidOperationException("agent_token_client_mismatch");
        return response;
    }

    private static string NormalizePanelUrl(string panelUrl)
    {
        var normalized = panelUrl.Trim().TrimEnd('/');
        if (!Uri.TryCreate(normalized, UriKind.Absolute, out var uri)
            || uri.Scheme is not ("http" or "https")
            || string.IsNullOrWhiteSpace(uri.Host))
            throw new InvalidOperationException("panel_url_invalid");
        return normalized;
    }
}
