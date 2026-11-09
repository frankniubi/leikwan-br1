using System.Net;
using System.Runtime.InteropServices;
using LeikwanBridge.Core.Services.PanelEgress;
using Xunit;

namespace LeikwanBridge.Core.Tests.PanelEgress;

public sealed class WindowsSocketAddressListTests
{
    private const int EntrySize = 64;

    [Fact]
    public void ReadsDnsEligibleUnicastListWithoutTreatingTheHeaderAsAPointer()
    {
        // Length=0x40 and Flags=1 (DNS eligible) is what the LeiKwan TUN adapter
        // reports; the old reader dereferenced 0x0000000100000040 and crashed.
        var v4 = Marshal.AllocHGlobal(16);
        var v6 = Marshal.AllocHGlobal(28);
        var second = Marshal.AllocHGlobal(EntrySize);
        var first = Marshal.AllocHGlobal(EntrySize);
        try
        {
            Zero(v4, 16);
            Marshal.WriteInt16(v4, 0, 2);
            Marshal.Copy(new byte[] { 172, 19, 0, 1 }, 0, v4 + 4, 4);

            Zero(v6, 28);
            Marshal.WriteInt16(v6, 0, 23);
            Marshal.Copy(IPAddress.Parse("fdfe:dcba:9876::1").GetAddressBytes(), 0, v6 + 8, 16);

            WriteEntry(second, IntPtr.Zero, v6, 28);
            WriteEntry(first, second, v4, 16);

            var addresses = WindowsSocketAddressList.Read(first);

            Assert.Equal(
                new[] { IPAddress.Parse("172.19.0.1"), IPAddress.Parse("fdfe:dcba:9876::1") },
                addresses);
        }
        finally
        {
            Marshal.FreeHGlobal(first);
            Marshal.FreeHGlobal(second);
            Marshal.FreeHGlobal(v6);
            Marshal.FreeHGlobal(v4);
        }
    }

    [Fact]
    public void EmptyListYieldsNoAddresses()
        => Assert.Empty(WindowsSocketAddressList.Read(IntPtr.Zero));

    private static void WriteEntry(IntPtr entry, IntPtr next, IntPtr sockaddr, int sockaddrLength)
    {
        Zero(entry, EntrySize);
        Marshal.WriteInt32(entry, 0, EntrySize);
        Marshal.WriteInt32(entry, 4, 1);
        Marshal.WriteIntPtr(entry, WindowsSocketAddressList.NextOffset, next);
        Marshal.WriteIntPtr(entry, WindowsSocketAddressList.SockaddrOffset, sockaddr);
        Marshal.WriteInt32(entry, WindowsSocketAddressList.SockaddrOffset + IntPtr.Size, sockaddrLength);
    }

    private static void Zero(IntPtr pointer, int length)
        => Marshal.Copy(new byte[length], 0, pointer, length);
}
