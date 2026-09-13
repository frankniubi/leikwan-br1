using System.Runtime.InteropServices;

namespace LeikwanBridge.Headless;

// Minimal SCM plumbing so the windowless client is a real Windows service
// (visible in services.msc, automatic start, restart on failure) without
// pulling a hosting dependency into a process that must stay as small and as
// auditable as the tunnel it supervises.
internal static class WindowsServiceHost
{
    private const uint ServiceWin32OwnProcess = 0x00000010;
    private const uint ServiceAcceptStop = 0x00000001;
    private const uint ServiceAcceptShutdown = 0x00000004;
    private const uint ServiceRunning = 0x00000004;
    private const uint ServiceStartPending = 0x00000002;
    private const uint ServiceStopPending = 0x00000003;
    private const uint ServiceStopped = 0x00000001;
    private const int ServiceControlStop = 1;
    private const int ServiceControlShutdown = 5;

    private static ServiceControlHandler _handler = HandleControl;
    private static IntPtr _statusHandle = IntPtr.Zero;
    private static CancellationTokenSource? _shutdown;
    private static int _exitCode = 1;

    [StructLayout(LayoutKind.Sequential)]
    private struct ServiceStatus
    {
        public uint ServiceType;
        public uint CurrentState;
        public uint ControlsAccepted;
        public uint Win32ExitCode;
        public uint ServiceSpecificExitCode;
        public uint CheckPoint;
        public uint WaitHint;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ServiceTableEntry
    {
        public IntPtr ServiceName;
        public ServiceMainCallback ServiceMain;
    }

    private delegate void ServiceMainCallback(int argumentCount, IntPtr arguments);
    private delegate void ServiceControlHandler(int control);

    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool StartServiceCtrlDispatcherW(ServiceTableEntry[] table);

    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern IntPtr RegisterServiceCtrlHandlerW(string serviceName, ServiceControlHandler handler);

    [DllImport("advapi32.dll", SetLastError = true)]
    private static extern bool SetServiceStatus(IntPtr statusHandle, ref ServiceStatus status);

    /// <summary>
    /// Runs the process as an SCM service. Blocks until the service stops and
    /// returns the body's exit code (or 1 when the service could not start).
    /// </summary>
    public static int Run(string serviceName, Func<CancellationToken, Task<int>> body)
    {
        var table = new[]
        {
            new ServiceTableEntry
            {
                ServiceName = Marshal.StringToHGlobalUni(serviceName),
                ServiceMain = ServiceMain,
            },
            new ServiceTableEntry { ServiceName = IntPtr.Zero, ServiceMain = null! },
        };

        try
        {
            if (!StartServiceCtrlDispatcherW(table))
                return 1;

            return _exitCode;
        }
        finally
        {
            foreach (var entry in table)
            {
                if (entry.ServiceName != IntPtr.Zero)
                    Marshal.FreeHGlobal(entry.ServiceName);
            }
        }

        void ServiceMain(int argumentCount, IntPtr arguments)
        {
            _statusHandle = RegisterServiceCtrlHandlerW(serviceName, _handler);
            if (_statusHandle == IntPtr.Zero)
            {
                _exitCode = 1;
                return;
            }

            ReportStatus(ServiceStartPending, ServiceAcceptStop | ServiceAcceptShutdown, waitHint: 30000);
            _shutdown = new CancellationTokenSource();
            try
            {
                ReportStatus(ServiceRunning, ServiceAcceptStop | ServiceAcceptShutdown);
                _exitCode = body(_shutdown.Token).GetAwaiter().GetResult();
            }
            catch (Exception)
            {
                _exitCode = 1;
            }
            finally
            {
                ReportStatus(ServiceStopped, 0);
                _shutdown.Dispose();
                _shutdown = null;
            }
        }
    }

    private static void HandleControl(int control)
    {
        switch (control)
        {
            case ServiceControlStop:
            case ServiceControlShutdown:
                ReportStatus(ServiceStopPending, 0, waitHint: 30000);
                try
                {
                    _shutdown?.Cancel();
                }
                catch (ObjectDisposedException)
                {
                }

                break;
        }
    }

    private static void ReportStatus(uint state, uint accepted, uint waitHint = 0)
    {
        if (_statusHandle == IntPtr.Zero)
            return;
        var status = new ServiceStatus
        {
            ServiceType = ServiceWin32OwnProcess,
            CurrentState = state,
            ControlsAccepted = accepted,
            Win32ExitCode = 0,
            ServiceSpecificExitCode = 0,
            CheckPoint = 0,
            WaitHint = waitHint,
        };
        SetServiceStatus(_statusHandle, ref status);
    }
}
