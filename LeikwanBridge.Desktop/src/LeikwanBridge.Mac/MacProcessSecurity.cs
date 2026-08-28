using System.Runtime.InteropServices;

namespace LeikwanBridge.Mac;

internal static class MacProcessSecurity
{
    private const int RlimitCore = 4;

    public static void DisableCoreDumps()
    {
        if (!OperatingSystem.IsMacOS())
            return;
        var limit = new RLimit { Current = 0, Maximum = 0 };
        _ = setrlimit(RlimitCore, ref limit);
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct RLimit
    {
        public ulong Current;
        public ulong Maximum;
    }

    [DllImport("libSystem.B.dylib")]
    private static extern int setrlimit(int resource, ref RLimit limit);
}
