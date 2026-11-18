using System.Runtime.InteropServices;
using Serilog;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// Adds or removes the app's Dock icon at runtime through the AppKit activation
/// policy: regular has a Dock icon and a menu bar, accessory lives in the menu-bar
/// item only. Avalonia's IActivatableLifetime.TryEnterBackground is NSApp hide:
/// (what Cmd+H does), which hides the app but keeps its Dock icon, so a window
/// closed to the menu bar still held a Dock slot.
/// </summary>
public static class MacDockIcon
{
    private const string ObjectiveCLibrary = "/usr/lib/libobjc.A.dylib";
    private const nint ActivationPolicyRegular = 0;
    private const nint ActivationPolicyAccessory = 1;

    /// <summary>
    /// Shows or removes the Dock icon. Runs on the main thread (Avalonia's UI
    /// thread on macOS). Returns false off macOS or when AppKit refuses the change.
    /// </summary>
    public static bool TrySetVisible(bool visible)
    {
        if (!OperatingSystem.IsMacOS())
            return false;
        try
        {
            var application = SendIntPtr(GetClass("NSApplication"), RegisterSelector("sharedApplication"));
            if (application == IntPtr.Zero)
                return false;
            var policy = visible ? ActivationPolicyRegular : ActivationPolicyAccessory;
            if (SendNint(application, RegisterSelector("activationPolicy")) == policy)
                return true;
            if (!SendBoolWithNint(application, RegisterSelector("setActivationPolicy:"), policy))
                return false;
            // Coming back from accessory, AppKit only shows the menu bar again once
            // the app is activated.
            if (visible)
                SendVoidWithBool(application, RegisterSelector("activateIgnoringOtherApps:"), true);
            return true;
        }
        catch (Exception exception) when (exception is DllNotFoundException
            or EntryPointNotFoundException
            or MarshalDirectiveException)
        {
            Log.Warning("dock_icon_policy_unavailable type={ErrorType}", exception.GetType().Name);
            return false;
        }
    }

    [DllImport(ObjectiveCLibrary, EntryPoint = "objc_getClass")]
    private static extern IntPtr GetClass(string name);

    [DllImport(ObjectiveCLibrary, EntryPoint = "sel_registerName")]
    private static extern IntPtr RegisterSelector(string name);

    [DllImport(ObjectiveCLibrary, EntryPoint = "objc_msgSend")]
    private static extern IntPtr SendIntPtr(IntPtr receiver, IntPtr selector);

    [DllImport(ObjectiveCLibrary, EntryPoint = "objc_msgSend")]
    private static extern nint SendNint(IntPtr receiver, IntPtr selector);

    [DllImport(ObjectiveCLibrary, EntryPoint = "objc_msgSend")]
    [return: MarshalAs(UnmanagedType.I1)]
    private static extern bool SendBoolWithNint(IntPtr receiver, IntPtr selector, nint value);

    [DllImport(ObjectiveCLibrary, EntryPoint = "objc_msgSend")]
    private static extern void SendVoidWithBool(IntPtr receiver, IntPtr selector, [MarshalAs(UnmanagedType.I1)] bool value);
}
