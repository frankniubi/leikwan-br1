namespace LeikwanBridge.Mac.Services;

/// <summary>
/// What the main window does with a close request.
/// </summary>
public enum MacWindowCloseAction
{
    /// <summary>Let the window close: the app is already quitting.</summary>
    Close,
    /// <summary>Hide the window; the app and its connections keep running.</summary>
    Hide,
    /// <summary>Stop every connection, then quit the app.</summary>
    Quit,
}

/// <summary>
/// The macOS window-close policy. The red button (and Cmd+W) closes the
/// window, not the app, so it only hides it: connections keep running and the
/// Dock icon or the menu-bar item brings the window back. Cmd+Q, Quit in the
/// Dock or menu-bar menu, logout and shutdown quit for real, which stops every
/// connection and ends the process. Previously the close handler cancelled
/// every close, including the one Avalonia sends for Cmd+Q, so Quit only hid
/// the window and the process stayed behind.
/// </summary>
public static class MacWindowClosePolicy
{
    /// <param name="quitStarted">The app's own quit sequence is already closing the window.</param>
    /// <param name="appIsQuitting">The close came from Cmd+Q, Dock Quit, logout or shutdown.</param>
    public static MacWindowCloseAction Decide(bool quitStarted, bool appIsQuitting)
    {
        if (quitStarted)
            return MacWindowCloseAction.Close;
        return appIsQuitting ? MacWindowCloseAction.Quit : MacWindowCloseAction.Hide;
    }

    /// <summary>
    /// Whether the app keeps its Dock icon while the window is hidden. With
    /// "keep in menu bar" on and a working menu-bar icon the app lives only in
    /// the menu bar; otherwise the Dock icon is the way back to the window.
    /// </summary>
    public static bool ShowInDockWhileHidden(bool keepInMenuBar, bool menuBarIconAvailable)
        => !(keepInMenuBar && menuBarIconAvailable);
}
