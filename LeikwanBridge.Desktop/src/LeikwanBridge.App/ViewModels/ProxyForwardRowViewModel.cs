using CommunityToolkit.Mvvm.ComponentModel;
using System.Collections.ObjectModel;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.App.ViewModels;

/// <summary>Independent ordinary-proxy selection and actions for one forward.</summary>
public sealed partial class ProxyForwardRowViewModel : ObservableObject
{
    private readonly MainViewModel _owner;
    private bool _refreshing;

    internal ForwardViewModel Forward { get; private set; }
    public int ForwardId => Forward.ForwardId;
    public string Title => Forward.DisplayName;
    public string ListenerEndpoint => $"127.0.0.1:{Forward.Config?.ListenPort}";
    public string ModeLabel => string.Equals(Forward.Config?.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
        ? "ULTRA" : (string.IsNullOrWhiteSpace(Forward.Config?.ClientMode)
            ? Forward.Config?.Mode ?? "single" : Forward.Config.ClientMode).ToUpperInvariant();
    public ObservableCollection<ProxySavedNodeChoice> Profiles { get; } = [];

    [ObservableProperty]
    private int _selectedProfileId;

    public ProxySavedNodeChoice? SelectedProfile =>
        SelectedProfileId > 0
            ? Profiles.FirstOrDefault(profile => profile.Id == SelectedProfileId)
            : null;

    public string SelectedProfileName => SelectedProfile?.Name
        ?? _owner.UnboundProxyProfileText;

    public bool IsUnbound => SelectedProfile is null;
    public bool IsActive => _owner.IsProxySessionForForward(ForwardId);
    public bool IsStarting => IsActive && _owner.IsProxyStarting;
    public bool CanConnect => !IsUnbound && !IsActive && !_owner.IsProxyStarting && !_owner.IsProxyConnected;
    public bool CanDisconnect => IsActive;
    public bool CanSelectProfile => !IsActive && !_owner.IsProxyStarting;

    public ProxyForwardRowViewModel(MainViewModel owner, ForwardViewModel forward)
    {
        _owner = owner;
        Forward = forward;
        _selectedProfileId = owner.GetProxyProfileIdForForward(forward.ForwardId);
        Refresh();
    }

    partial void OnSelectedProfileIdChanged(int value)
    {
        if (!_refreshing)
            _owner.SetProxyProfileForForward(ForwardId, value);
        OnPropertyChanged(nameof(SelectedProfile));
        OnPropertyChanged(nameof(SelectedProfileName));
        OnPropertyChanged(nameof(IsUnbound));
        OnPropertyChanged(nameof(CanConnect));
    }

    public Task ConnectAsync(CancellationToken ct = default) =>
        _owner.StartProxyForForwardAsync(ForwardId, SelectedProfileId, ct);

    public Task DisconnectAsync(CancellationToken ct = default) =>
        _owner.StopProxyForForwardAsync(ForwardId, ct);

    internal void Refresh()
    {
        _refreshing = true;
        try
        {
        var choices = new List<ProxySavedNodeChoice>
        {
            new(0, _owner.UnboundProxyProfileText),
        };
        choices.AddRange(_owner.ProxyProfiles.Select(profile => new ProxySavedNodeChoice(profile.Id, profile.Name)));
        for (var index = Profiles.Count - 1; index >= 0; index--)
            if (!choices.Any(choice => choice.Id == Profiles[index].Id))
                Profiles.RemoveAt(index);
        for (var index = 0; index < choices.Count; index++)
        {
            var choice = choices[index];
            var existing = Profiles.FirstOrDefault(item => item.Id == choice.Id);
            if (existing is null)
                Profiles.Insert(index, choice);
            else
            {
                var oldIndex = Profiles.IndexOf(existing);
                if (oldIndex != index)
                    Profiles.Move(oldIndex, index);
                if (existing.Name != choice.Name)
                    Profiles[index] = choice;
            }
        }
        var selected = _owner.GetProxyProfileIdForForward(ForwardId);
        if (selected != SelectedProfileId)
            SelectedProfileId = selected;
        }
        finally { _refreshing = false; }
        OnPropertyChanged(nameof(SelectedProfile));
        OnPropertyChanged(nameof(SelectedProfileName));
        OnPropertyChanged(nameof(IsUnbound));
        OnPropertyChanged(nameof(IsActive));
        OnPropertyChanged(nameof(IsStarting));
        OnPropertyChanged(nameof(CanConnect));
        OnPropertyChanged(nameof(CanDisconnect));
        OnPropertyChanged(nameof(CanSelectProfile));
    }

    internal void UpdateForward(ForwardViewModel forward)
    {
        Forward = forward;
        OnPropertyChanged(nameof(Title));
        OnPropertyChanged(nameof(ListenerEndpoint));
        OnPropertyChanged(nameof(ModeLabel));
    }
}

public sealed record ProxySavedNodeChoice(int Id, string Name);
