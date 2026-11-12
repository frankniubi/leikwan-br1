using Avalonia.Controls;
using Avalonia.Interactivity;
using Avalonia.Threading;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.ViewModels;

namespace LeikwanBridge.Mac.Views;

public sealed partial class MainWindow : Window
{
    private readonly DispatcherTimer _tick;

    public MainWindow()
    {
        InitializeComponent();
        // Drives the running timer and the live traffic sample once a second.
        _tick = new DispatcherTimer(TimeSpan.FromSeconds(1), DispatcherPriority.Background, OnTick);
        Opened += (_, _) => _tick.Start();
        Closed += (_, _) => _tick.Stop();
    }

    private void OnTick(object? sender, EventArgs e)
    {
        if (DataContext is MainWindowViewModel viewModel)
            viewModel.Tick(DateTimeOffset.UtcNow);
    }

    public bool AllowClose { get; set; }

    public event EventHandler? ExitRequested;

    /// <summary>Raised after the close button hid the window.</summary>
    public event EventHandler? Hidden;

    protected override void OnClosing(WindowClosingEventArgs e)
    {
        // Cmd+Q, Quit in the Dock menu, logout and shutdown reach the window as an
        // application-shutdown close. Those must quit; only the red button hides.
        var appIsQuitting = e.CloseReason is WindowCloseReason.ApplicationShutdown or WindowCloseReason.OSShutdown;
        switch (MacWindowClosePolicy.Decide(AllowClose, appIsQuitting))
        {
            case MacWindowCloseAction.Hide when DataContext is MainWindowViewModel:
                e.Cancel = true;
                Hide();
                Hidden?.Invoke(this, EventArgs.Empty);
                return;
            case MacWindowCloseAction.Quit:
                // The quit sequence stops the connections first and then closes this
                // window itself with AllowClose set.
                e.Cancel = true;
                ExitRequested?.Invoke(this, EventArgs.Empty);
                return;
        }
        base.OnClosing(e);
    }

    private async void OnClientClick(object? sender, RoutedEventArgs e)
    {
        if (sender is Button { DataContext: DesktopClientChoice client }
            && DataContext is MainWindowViewModel viewModel)
            await viewModel.SelectClientCommand.ExecuteAsync(client.Id);
    }
}
