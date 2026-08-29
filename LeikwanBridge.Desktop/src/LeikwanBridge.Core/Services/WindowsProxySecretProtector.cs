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
/// </summary>
public sealed class WindowsDpapiSecretProtector : IWindowsProxySecretProtector
{
    public bool IsAvailable => OperatingSystem.IsWindows();

    public byte[] Protect(ReadOnlySpan<byte> plaintext)
        => Transform(plaintext, protect: true);

    public byte[] Unprotect(ReadOnlySpan<byte> protectedData)
        => Transform(protectedData, protect: false);

    private static byte[] Transform(ReadOnlySpan<byte> input, bool protect)
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
                ? CryptProtectData(ref inputBlob, null, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, 0, ref outputBlob)
                : CryptUnprotectData(ref inputBlob, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, 0, ref outputBlob);
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
