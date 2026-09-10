using Avalonia.Controls;
using Avalonia.Interactivity;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Mac.ViewModels;

namespace LeikwanBridge.Mac.Views;

public sealed partial class MainWindow : Window
{
    public MainWindow()
    {
        InitializeComponent();
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
