namespace LeikwanBridge.Core.Models;

public sealed class WindowsAppUpdateState
{
    public string PendingUpdateVersion { get; set; } = "";
    public string PendingUpdateInstallerPath { get; set; } = "";
    public string PendingUpdateSha256 { get; set; } = "";
    public string PendingUpdateDownloadedAt { get; set; } = "";
    public string LastUpdateCheckAt { get; set; } = "";
}
