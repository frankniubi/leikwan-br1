using Avalonia.Controls;
using Avalonia.Interactivity;
using Avalonia.Threading;
using LeikwanBridge.Core.Models;
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

    protected override void OnClosing(WindowClosingEventArgs e)
    {
        if (!AllowClose && DataContext is MainWindowViewModel viewModel)
        {
            e.Cancel = true;
            if (viewModel.CloseToTray && viewModel.TrayAvailable)
            {
                ShowInTaskbar = false;
                Hide();
                return;
            }
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
