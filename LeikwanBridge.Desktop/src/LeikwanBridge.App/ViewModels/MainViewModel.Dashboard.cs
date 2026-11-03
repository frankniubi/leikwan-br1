using System.Reflection;
using System.Windows.Threading;
using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.App.ViewModels;

/// <summary>
/// Dashboard cards (FlClash-style): network speed history, traffic usage, outbound mode,
/// current service, connections, version / update and the start-stop button timer.
/// Everything here is derived from state the app already had; nothing new is started.
/// </summary>
public partial class MainViewModel
{
    public const int SpeedHistoryLength = 60;

    private readonly List<double> _speedInHistory = [];
    private readonly List<double> _speedOutHistory = [];
    private RuntimeUsageSnapshot? _lastUsageSample;
    private DateTimeOffset _lastUsageSampleAt;
    private long _totalBytesInValue;
    private long _totalBytesOutValue;
    private DispatcherTimer? _runTimer;
    private DateTimeOffset? _proxyConnectedSince;

    [ObservableProperty] private string _speedInText = "0 B/s";
    [ObservableProperty] private string _speedOutText = "0 B/s";
    [ObservableProperty] private string _runningTimeText = "00:00:00";
    [ObservableProperty] private string _updateCheckStatus = string.Empty;

    /// <summary>Raised after every speed sample so the chart can redraw.</summary>
    public event Action? SpeedHistoryChanged;

    public IReadOnlyList<double> SpeedInHistory => _speedInHistory;
    public IReadOnlyList<double> SpeedOutHistory => _speedOutHistory;

    /// <summary>Share of inbound bytes in the session total (0..1), for the usage ring.</summary>
    public double TrafficInShare
    {
        get
        {
            var total = (double)_totalBytesInValue + _totalBytesOutValue;
            return total <= 0 ? 0 : _totalBytesInValue / total;
        }
    }

    public string AppVersionText => "v" + CurrentAppVersion;

    /// <summary>The Windows app's file version (same number the installer and update check use).</summary>
    public static string CurrentAppVersion
        => typeof(MainViewModel).Assembly.GetCustomAttribute<AssemblyFileVersionAttribute>()?.Version
            ?? typeof(MainViewModel).Assembly.GetName().Version?.ToString()
            ?? "0.0.0.0";

    public bool IsUpdateAvailable => !string.IsNullOrWhiteSpace(AvailableUpdateVersion)
        || !string.IsNullOrWhiteSpace(_config.PendingUpdateVersion);

    public string ProxyModeDisplay => ProxySnapshot.State is WindowsProxyConnectionState.Disconnected
        ? (IsProxyModeSelected ? GetString("ProxyReady") : GetString("ProxyModeSelect"))
        : ProxySnapshot.Mode switch
        {
            WindowsProxyMode.Tun => GetString("ProxyModeTun"),
            WindowsProxyMode.Process => GetString("ProxyModeProcess"),
            _ => GetString("ProxyModeHttp"),
        };

    /// <summary>Start / stop button state: running when the local proxy is connected or starting.</summary>
    public bool IsCoreRunning => ProxySnapshot.State is WindowsProxyConnectionState.Connected or WindowsProxyConnectionState.Starting;

    public string CurrentServiceTitle => SelectedPairedProxyForward?.Title
        ?? ProxyForwardRows.FirstOrDefault(row => row.ForwardId == SelectedProxyForwardId)?.Title
        ?? GetString("DashNoService");

    partial void OnProxySnapshotChanged(WindowsProxySnapshot value)
    {
        if (value.State == WindowsProxyConnectionState.Connected)
        {
            _proxyConnectedSince ??= DateTimeOffset.UtcNow;
            StartRunTimer();
        }
        else if (value.State is WindowsProxyConnectionState.Disconnected or WindowsProxyConnectionState.Error)
        {
            _proxyConnectedSince = null;
            StopRunTimer();
            RunningTimeText = "00:00:00";
        }
        OnPropertyChanged(nameof(IsCoreRunning));
        OnPropertyChanged(nameof(ProxyModeDisplay));
    }

    partial void OnAvailableUpdateVersionChanged(string value)
        => OnPropertyChanged(nameof(IsUpdateAvailable));

    /// <summary>Feeds the speed chart and the usage ring from the runtime byte counters.</summary>
    private void SampleUsage(RuntimeUsageSnapshot snapshot)
    {
        var now = DateTimeOffset.UtcNow;
        _totalBytesInValue = Math.Max(0, snapshot.BytesIn);
        _totalBytesOutValue = Math.Max(0, snapshot.BytesOut);
        if (_lastUsageSample is { } previous && now > _lastUsageSampleAt)
        {
            var seconds = Math.Max(0.25, (now - _lastUsageSampleAt).TotalSeconds);
            // Counters restart with the runtime; a drop is a reset, not negative speed.
            var inRate = Math.Max(0, snapshot.BytesIn - previous.BytesIn) / seconds;
            var outRate = Math.Max(0, snapshot.BytesOut - previous.BytesOut) / seconds;
            Push(_speedInHistory, inRate);
            Push(_speedOutHistory, outRate);
            SpeedInText = FormatBytes((long)inRate) + "/s";
            SpeedOutText = FormatBytes((long)outRate) + "/s";
            SpeedHistoryChanged?.Invoke();
        }
        _lastUsageSample = snapshot;
        _lastUsageSampleAt = now;
        OnPropertyChanged(nameof(TrafficInShare));
    }

    private static void Push(List<double> history, double value)
    {
        history.Add(value);
        if (history.Count > SpeedHistoryLength)
            history.RemoveRange(0, history.Count - SpeedHistoryLength);
    }

    private void StartRunTimer()
    {
        if (_runTimer is not null)
            return;
        try
        {
            _runTimer = new DispatcherTimer(DispatcherPriority.Background) { Interval = TimeSpan.FromSeconds(1) };
            _runTimer.Tick += (_, _) => UpdateRunningTime();
            _runTimer.Start();
        }
        catch (InvalidOperationException)
        {
            _runTimer = null;
        }
        UpdateRunningTime();
    }

    private void StopRunTimer()
    {
        _runTimer?.Stop();
        _runTimer = null;
    }

    private void UpdateRunningTime()
    {
        if (_proxyConnectedSince is not { } since)
            return;
        var elapsed = DateTimeOffset.UtcNow - since;
        RunningTimeText = $"{(int)elapsed.TotalHours:00}:{elapsed.Minutes:00}:{elapsed.Seconds:00}";
    }

    /// <summary>Called whenever the selected service or its pairing changes.</summary>
    private void NotifyDashboardServiceChanged()
    {
        OnPropertyChanged(nameof(CurrentServiceTitle));
        OnPropertyChanged(nameof(ProxyModeDisplay));
    }
}
