namespace LeikwanBridge.App.Services;

public sealed class TrayWindowLifecycle
{
    public bool ExitRequested { get; private set; }
    public bool IsClosing { get; private set; }

    public bool CanRestore => !ExitRequested && !IsClosing;

    public bool ShouldHideOnClose(bool minimizeToTray, bool trayReady)
    {
        return !ExitRequested && !IsClosing && minimizeToTray && trayReady;
    }

    public void RequestExit()
    {
        ExitRequested = true;
    }

    public void MarkClosing()
    {
        IsClosing = true;
    }
}
