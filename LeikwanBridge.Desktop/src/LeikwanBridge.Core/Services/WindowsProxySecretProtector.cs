using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Security.Cryptography;

namespace LeikwanBridge.Core.Services;

public interface IWindowsProxySecretProtector
{
    bool IsAvailable { get; }
    byte[] Protect(ReadOnlySpan<byte> plaintext);
    byte[] Unprotect(ReadOnlySpan<byte> protectedData);
}

/// <summary>
/// Uses the logged-in Windows user's DPAPI key.  On non-Windows hosts it is
/// unavailable, allowing Core tests to keep a portable JSON fixture.
/// With <c>localMachineScope</c> the blob is bound to this machine instead of
/// one account, so every account on the machine (the GUI user and the
/// LocalSystem service) can open it, while a copy is useless elsewhere.
/// </summary>
public sealed class WindowsDpapiSecretProtector : IWindowsProxySecretProtector
{
    private const int CryptProtectUiForbidden = 0x1;
    private const int CryptProtectLocalMachine = 0x4;

    public WindowsDpapiSecretProtector(bool localMachineScope = false)
    {
        IsLocalMachineScope = localMachineScope;
    }

    public bool IsAvailable => OperatingSystem.IsWindows();

    public bool IsLocalMachineScope { get; }

    public byte[] Protect(ReadOnlySpan<byte> plaintext)
        => Transform(
            plaintext,
            protect: true,
            IsLocalMachineScope ? CryptProtectLocalMachine | CryptProtectUiForbidden : 0);

    public byte[] Unprotect(ReadOnlySpan<byte> protectedData)
        => Transform(protectedData, protect: false, IsLocalMachineScope ? CryptProtectUiForbidden : 0);

    private static byte[] Transform(ReadOnlySpan<byte> input, bool protect, int flags)
    {
        if (!OperatingSystem.IsWindows())
            throw new PlatformNotSupportedException("windows_dpapi_required");

        var source = input.ToArray();
        var sourceHandle = GCHandle.Alloc(source, GCHandleType.Pinned);
        try
        {
            var inputBlob = new DataBlob
            {
                cbData = source.Length,
                pbData = source.Length == 0 ? IntPtr.Zero : sourceHandle.AddrOfPinnedObject(),
            };
            var outputBlob = new DataBlob();
            var ok = protect
                ? CryptProtectData(ref inputBlob, null, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, flags, ref outputBlob)
                : CryptUnprotectData(ref inputBlob, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, flags, ref outputBlob);
            if (!ok)
                throw new Win32Exception(Marshal.GetLastWin32Error(), "windows_dpapi_failed");
            try
            {
                var output = new byte[outputBlob.cbData];
                if (output.Length > 0)
                    Marshal.Copy(outputBlob.pbData, output, 0, output.Length);
                return output;
            }
            finally
            {
                if (outputBlob.pbData != IntPtr.Zero)
                    LocalFree(outputBlob.pbData);
            }
        }
        finally
        {
            CryptographicOperations.ZeroMemory(source);
            sourceHandle.Free();
        }
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct DataBlob
    {
        public int cbData;
        public IntPtr pbData;
    }

    [DllImport("crypt32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CryptProtectData(
        ref DataBlob pDataIn,
        string? szDataDescr,
        IntPtr pOptionalEntropy,
        IntPtr pvReserved,
        IntPtr pPromptStruct,
        int dwFlags,
        ref DataBlob pDataOut);

    [DllImport("crypt32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CryptUnprotectData(
        ref DataBlob pDataIn,
        IntPtr ppszDataDescr,
        IntPtr pOptionalEntropy,
        IntPtr pvReserved,
        IntPtr pPromptStruct,
        int dwFlags,
        ref DataBlob pDataOut);

    [DllImport("kernel32.dll")]
    private static extern IntPtr LocalFree(IntPtr hMem);
}
