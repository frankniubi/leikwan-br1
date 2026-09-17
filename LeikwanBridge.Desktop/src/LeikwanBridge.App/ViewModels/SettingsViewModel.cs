using System.IO;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.App.ViewModels;

public partial class SettingsViewModel : ObservableObject
{
    [ObservableProperty] private string _masterUrl = "";
    [ObservableProperty] private int _clientId;
    [ObservableProperty] private string _agentToken = "";
    [ObservableProperty] private int _pollInterval = 30;
    [ObservableProperty] private int _healthCheckInterval = 20;
    [ObservableProperty] private string _logLevel = DesktopLogLevel.Warning;
    [ObservableProperty] private bool _autoStart;
    [ObservableProperty] private bool _minimizeToTray = true;
    [ObservableProperty] private string _bondBin = "";
    [ObservableProperty] private string _wstunnelBin = "";
    [ObservableProperty] private string _wgBin = "";
    [ObservableProperty] private string _language = "en-US";
    [ObservableProperty] private string _statusMessage = "";

    private readonly string _configPath;
    private readonly StateManager _stateManager;

    public SettingsViewModel(string configPath)
    {
        _configPath = configPath;
        _stateManager = new StateManager(Path.GetDirectoryName(configPath)!);
    }

    public void LoadFrom(AgentConfig config)
    {
        MasterUrl = config.MasterUrl;
        ClientId = config.ClientId;
        AgentToken = config.AgentToken;
        PollInterval = config.PollIntervalSeconds;
        HealthCheckInterval = config.HealthCheckIntervalSeconds;
        LogLevel = DesktopLogLevel.Normalize(config.LogLevel);
        AutoStart = config.AutoStart;
        MinimizeToTray = config.MinimizeToTray;
        BondBin = config.BondBin;
        WstunnelBin = config.WstunnelBin;
        WgBin = config.WgBin;
        Language = config.Language;
    }

    public AgentConfig ToConfig()
    {
        return new AgentConfig
        {
            MasterUrl = MasterUrl.Trim(),
            ClientId = ClientId,
            AgentToken = AgentToken.Trim(),
            PollIntervalSeconds = PollInterval > 0 ? PollInterval : 30,
            HealthCheckIntervalSeconds = HealthCheckInterval > 0 ? HealthCheckInterval : 20,
            LogLevel = DesktopLogLevel.Normalize(LogLevel),
            AutoStart = AutoStart,
            MinimizeToTray = MinimizeToTray,
            BondBin = BondBin,
            WstunnelBin = WstunnelBin,
            WgBin = WgBin,
            Language = Language,
        };
    }

    [RelayCommand]
    private void Save()
    {
        var config = ToConfig();
        _stateManager.SaveConfig(_configPath, config);
        StatusMessage = "Settings saved.";
    }
}
