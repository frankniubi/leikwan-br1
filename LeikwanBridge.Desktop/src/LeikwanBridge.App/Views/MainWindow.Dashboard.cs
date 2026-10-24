using System.Windows;
using System.Windows.Controls;
using LeikwanBridge.App.Services;
using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Nodes;
using Serilog;

namespace LeikwanBridge.App.Views;

/// <summary>
/// Dashboard cards, the start / stop button, the Nodes page (cloud sync) and the theme picker.
/// Every action here reuses an existing flow: connect / disconnect go through the selected
/// service row exactly like the proxy page, node edits through the saved-profile handlers.
/// </summary>
public partial class MainWindow
{
    private bool _syncingDashboardMode;
    private bool _suppressThemeHandling;
    private bool _themeSelectionReady;

    // ------------------------------------------------------------ start / stop

    private async void OnCoreToggle_Click(object sender, RoutedEventArgs e)
    {
        if (_vm.IsCoreRunning)
        {
            try
            {
                await _vm.StopProxyAsync();
                SaveCurrentConfig();
            }
            catch (Exception ex)
            {
                SetProxyStatusError(ex.Message);
                Log.Warning(ex, "failed to stop the local connection");
            }
            return;
        }
        if (_vm.IsProxyReadyForSelectedPairedForward)
        {
            OnProxySelectedConnect_Click(sender, e);
            return;
        }
        // Not ready yet: take the user to the page that completes the setup.
        if (_vm.PairedProxyForwardRows.Count == 0)
        {
            _vm.NavigateToCommand.Execute("Forwards");
            ForwardPairingStatus.Text = FindResource("DashPairHint") as string ?? "Pair a node with a service first.";
            return;
        }
        _vm.NavigateToCommand.Execute("Proxy");
        SetProxyStatusError(_vm.IsProxyModeSelected ? "proxy_saved_profile_required" : "proxy_mode_required");
    }

    private void UpdateCoreToggle()
    {
        var running = _vm.IsCoreRunning;
        CoreToggleGlyph.Text = running ? "" : "";
        if (running)
            CoreToggleText.Text = _vm.ProxySnapshot.State == WindowsProxyConnectionState.Starting
                ? FindResource("ProxyStarting") as string ?? "Starting"
                : _vm.RunningTimeText;
        else
            CoreToggleText.SetResourceReference(TextBlock.TextProperty, "FabStart");
        CoreToggleButton.SetResourceReference(BackgroundProperty, running ? "PrimaryBrush" : "FabBrush");
        CoreToggleButton.SetResourceReference(ForegroundProperty, running ? "OnPrimaryBrush" : "OnFabBrush");
        CoreToggleButton.ToolTip = FindResource(running ? "FabStop" : "FabStart");
    }

    // ------------------------------------------------------------ dashboard cards

    private void OnDashModeChecked(object sender, RoutedEventArgs e)
    {
        if (_syncingDashboardMode || !_proxyUiReady || sender is not System.Windows.Controls.RadioButton { Tag: string tag })
            return;
        foreach (var item in ProxyModeCombo.Items.OfType<ComboBoxItem>())
        {
            if (string.Equals(item.Tag?.ToString(), tag, StringComparison.OrdinalIgnoreCase))
            {
                ProxyModeCombo.SelectedItem = item;
                SaveCurrentConfig();
                return;
            }
        }
    }

    private void SyncDashboardModeButtons()
    {
        if (DashModeHttp is null)
            return;
        _syncingDashboardMode = true;
        try
        {
            var hasMode = TryGetSelectedProxyMode(out var mode);
            DashModeHttp.IsChecked = hasMode && mode == WindowsProxyMode.Http;
            DashModeTun.IsChecked = hasMode && mode == WindowsProxyMode.Tun;
            DashModeProcess.IsChecked = hasMode && mode == WindowsProxyMode.Process;
        }
        finally
        {
            _syncingDashboardMode = false;
        }
        UpdateHttpModeNotice();
    }

    private void UpdateHttpModeNotice()
    {
        if (DashModeHttpNotice is null || ProxyModeHttpNotice is null)
            return;
        var snapshot = _vm.ProxySnapshot;
        var http = snapshot.State is WindowsProxyConnectionState.Connected or WindowsProxyConnectionState.Starting
            ? snapshot.Mode == WindowsProxyMode.Http && !_vm.IsExternalProxyActive
            : TryGetSelectedProxyMode(out var mode) && mode == WindowsProxyMode.Http;
        var visibility = http ? Visibility.Visible : Visibility.Collapsed;
        DashModeHttpNotice.Visibility = visibility;
        ProxyModeHttpNotice.Visibility = visibility;
    }

    private void OnDashServiceSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady || DashServiceCombo.SelectedValue is not int forwardId || forwardId <= 0)
            return;
        _vm.SelectedProxyProfileId = _vm.GetProxyProfileIdForForward(forwardId);
        SaveCurrentConfig();
    }

    private void OnDashOpenServices_Click(object sender, RoutedEventArgs e) => _vm.NavigateToCommand.Execute("Forwards");

    private void OnOpenNodes_Click(object sender, RoutedEventArgs e) => _vm.NavigateToCommand.Execute("Nodes");

    // ------------------------------------------------------------ nodes page

    private async void OnNodesRefreshCloud_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            await _vm.RefreshNodesFromCloudAsync();
            SaveCurrentConfig();
            PopulateProxyUi();
        }
        catch (Exception ex)
        {
            SetNodesStatus(DescribeNodeError(ex), error: true);
        }
    }

    private async void OnNodesSyncAll_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            var result = await _vm.UploadAllNodesAsync();
            SaveCurrentConfig();
            PopulateProxyUi();
            var summary = _vm.CloudStatusText;
            if (result.Failures.Count > 0)
            {
                summary += Environment.NewLine + string.Join(Environment.NewLine, result.Failures.Take(5).Select(failure =>
                    $"• {failure.Name}: {MainViewModel.DescribeCloudError(failure.Error)}{(string.IsNullOrWhiteSpace(failure.Field) ? "" : $" ({failure.Field})")}"));
            }
            SetNodesStatus(summary, error: result.Failed > 0);
        }
        catch (Exception ex)
        {
            SetNodesStatus(DescribeNodeError(ex), error: true);
        }
    }

    private void OnNodesPaste_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            if (System.Windows.Clipboard.ContainsText())
                NodesImportText.Text = System.Windows.Clipboard.GetText().Trim();
        }
        catch (Exception ex)
        {
            Log.Debug(ex, "clipboard unavailable");
        }
    }

    private async void OnNodesImport_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            var result = await _vm.ImportNodesAsync(NodesImportText.Text);
            NodesImportText.Clear();
            SaveCurrentConfig();
            PopulateProxyUi();
            ForwardPairingProfileList.SelectedValue = _vm.SelectedProxyProfileId;
            PairingProxyPassword.Password = _vm.ProxyEditor.Password;
            SetNodesStatus(DescribeImport(result), error: false);
        }
        catch (Exception ex)
        {
            SetNodesStatus(DescribeNodeError(ex), error: true);
        }
    }

    private async void OnNodeUpload_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not FrameworkElement { DataContext: WindowsProxyProfile profile })
            return;
        try
        {
            await _vm.UploadNodeAsync(profile.Id);
            SaveCurrentConfig();
            PopulateProxyUi();
            SetNodesStatus(string.Format(FindResource("NodesUploaded") as string ?? "{0} uploaded", profile.Name), error: false);
        }
        catch (Exception ex)
        {
            SetNodesStatus(DescribeNodeError(ex), error: true);
        }
    }

    private void OnNodeCopyLink_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not FrameworkElement { DataContext: WindowsProxyProfile profile })
            return;
        var link = _vm.ShareLinkForProfile(profile.Id);
        if (string.IsNullOrWhiteSpace(link))
        {
            SetNodesStatus(FindResource("NodesCopyUnsupported") as string ?? "This node has no share link.", error: true);
            return;
        }
        try
        {
            System.Windows.Clipboard.SetText(link);
            SetNodesStatus(FindResource("NodesLinkCopied") as string ?? "Share link copied", error: false);
        }
        catch (Exception ex)
        {
            Log.Debug(ex, "clipboard unavailable");
        }
    }

    private void SetNodesStatus(string text, bool error)
    {
        if (NodesStatusText is null)
            return;
        NodesStatusText.Text = text;
        NodesStatusText.SetResourceReference(TextBlock.ForegroundProperty, error ? "DangerBrush" : "SuccessBrush");
    }

    private string DescribeImport(UserNodeImportResult result)
        => string.Format(FindResource("NodesImported") as string ?? "{0} added, {1} already present, {2} not recognized",
            result.Added.Count, result.Duplicates, result.Invalid);

    private static string DescribeNodeError(Exception exception)
    {
        var code = HostedNodeSyncService.ErrorCode(exception);
        if (exception is ArgumentException)
            code = "hosted_node_invalid";
        return MainViewModel.DescribeCloudError(code);
    }

    // ------------------------------------------------------------ theme

    private void PopulateThemeSelection()
    {
        if (SettTheme is null)
            return;
        _suppressThemeHandling = true;
        try
        {
            foreach (var item in SettTheme.Items.OfType<ComboBoxItem>())
            {
                if (string.Equals(item.Tag?.ToString(), ThemeManager.Preference.ToString(), StringComparison.OrdinalIgnoreCase))
                    SettTheme.SelectedItem = item;
            }
        }
        finally
        {
            _suppressThemeHandling = false;
            _themeSelectionReady = true;
        }
    }

    private void OnThemeSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        // Ignore selection changes until the saved preference has been shown (never overwrite it at start-up).
        if (_suppressThemeHandling || !_themeSelectionReady || SettTheme?.SelectedItem is not ComboBoxItem item)
            return;
        var preference = ThemeManager.ParsePreference(item.Tag?.ToString());
        if (preference != ThemeManager.Preference)
            ThemeManager.SetPreference(preference);
    }

    private void OnThemeChanged(bool dark)
    {
        ThemeManager.ApplyTitleBar(this);
        UpdateCoreToggle();
    }
}
