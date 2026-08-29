using System.IO;
using System.Windows;
using System.Windows.Controls;
using Forms = System.Windows.Forms;
using Win32OpenFileDialog = Microsoft.Win32.OpenFileDialog;

namespace LeikwanBridge.App.Views;

public partial class ProxyGameAddDialog : Window
{
    private string? _selectedPath;
    private bool _displayNameEdited;

    public ProxyGameAddDialog()
    {
        InitializeComponent();
        DisplayNameText.TextChanged += (_, _) => _displayNameEdited = true;
    }

    public bool ScanFolder => (SourceModeCombo.SelectedItem as ComboBoxItem)?.Tag?.ToString() == "Folder";

    public string SelectedPath => _selectedPath ?? string.Empty;

    public string DisplayName => DisplayNameText.Text.Trim();

    private void OnSourceModeSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (SelectedPathText is null)
            return;
        _selectedPath = null;
        SelectedPathText.Text = FindResource("ProxyGameNoPath") as string ?? "Choose a file or folder";
        ChoosePathButton.Content = FindResource(ScanFolder ? "ProxyGameChooseFolder" : "ProxyGameChooseFile");
        if (!_displayNameEdited)
            DisplayNameText.Text = string.Empty;
    }

    private void OnChoosePath_Click(object sender, RoutedEventArgs e)
    {
        if (ScanFolder)
        {
            using var dialog = new Forms.FolderBrowserDialog
            {
                Description = FindResource("ProxyGameChooseFolder") as string ?? "Choose a game folder",
                UseDescriptionForTitle = true,
                ShowNewFolderButton = false,
            };
            if (dialog.ShowDialog() != Forms.DialogResult.OK)
                return;
            SetPath(dialog.SelectedPath);
            return;
        }

        var fileDialog = new Win32OpenFileDialog
        {
            Title = FindResource("ProxyGameChooseFile") as string ?? "Choose game executable",
            Filter = "Executable (*.exe)|*.exe|All files (*.*)|*.*",
            CheckFileExists = true,
            Multiselect = false,
        };
        if (fileDialog.ShowDialog(this) == true)
            SetPath(fileDialog.FileName);
    }

    private void SetPath(string path)
    {
        _selectedPath = path.Trim();
        SelectedPathText.Text = _selectedPath;
        if (_displayNameEdited)
            return;
        DisplayNameText.Text = ScanFolder
            ? new DirectoryInfo(_selectedPath).Name
            : Path.GetFileNameWithoutExtension(_selectedPath);
        _displayNameEdited = false;
    }

    private void OnConfirm_Click(object sender, RoutedEventArgs e)
    {
        if (string.IsNullOrWhiteSpace(SelectedPath))
        {
            System.Windows.MessageBox.Show(
                this,
                FindResource("ProxyGameNoPath") as string ?? "Choose a file or folder first.",
                FindResource("ProxyGameAddTitle") as string ?? "Add game",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
            return;
        }

        DialogResult = true;
    }

    private void OnCancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
    }
}
