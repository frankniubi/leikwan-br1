using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Net;
using System.Runtime.InteropServices;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

/// <summary>
/// Enumerates TCP/UDP socket owners (owning pid) for a local port via iphlpapi.
/// Returns only non-secret protocol/address/port/pid metadata. No shell.
/// </summary>
public static class PortOwnerEnumerator
{
    private const uint AfInet = 2;
    private const uint AfInet6 = 23;
    private const uint ErrorSuccess = 0;
    private const uint ErrorInsufficientBuffer = 122;
    private const uint TcpStateListen = 2;

    private enum TcpTableClass : uint { BasicListener = 0, BasicConnected = 1, BasicAll = 2, OwnerPidListener = 3, OwnerPidConnected = 4, OwnerPidAll = 5 }
    private enum UdpTableClass : uint { Basic = 0, OwnerPid = 1, OwnerModule = 2 }

    [DllImport("iphlpapi.dll", SetLastError = true)]
    private static extern uint GetExtendedTcpTable(IntPtr pTcpTable, ref int dwSize, [MarshalAs(UnmanagedType.Bool)] bool bOrder, uint ulAf, TcpTableClass tableClass, uint reserved);

    [DllImport("iphlpapi.dll", SetLastError = true)]
    private static extern uint GetExtendedUdpTable(IntPtr pUdpTable, ref int dwSize, [MarshalAs(UnmanagedType.Bool)] bool bOrder, uint ulAf, UdpTableClass tableClass, uint reserved);

    private delegate uint TableQuery(IntPtr buffer, ref int size, bool order, uint family, uint tableClass, uint reserved);

    /// <summary>Returns every TCP listener and UDP socket bound to <paramref name="port"/> (any local address, IPv4 and IPv6).</summary>
    public static IReadOnlyList<PortOwner> FindOwners(int port)
    {
        if (port is < 1 or > 65535)
            return [];

        var result = new List<PortOwner>();
        AddTcpOwners(port, AfInet, result);
        AddTcpOwners(port, AfInet6, result);
        AddUdpOwners(port, AfInet, result);
        AddUdpOwners(port, AfInet6, result);
        return result;
    }

    /// <summary>Best-effort executable path for a pid; null on access-denied/disposed (denied ⇒ treated foreign).</summary>
    public static string? ResolveExecutablePath(int pid)
    {
        if (pid <= 0)
            return null;
        try
        {
            using var process = Process.GetProcessById(pid);
            return process.MainModule?.FileName;
        }
        catch
        {
            return null;
        }
    }

    private static uint QueryTcp(IntPtr buffer, ref int size, bool order, uint family, uint tableClass, uint reserved)
        => GetExtendedTcpTable(buffer, ref size, order, family, (TcpTableClass)tableClass, reserved);

    private static uint QueryUdp(IntPtr buffer, ref int size, bool order, uint family, uint tableClass, uint reserved)
        => GetExtendedUdpTable(buffer, ref size, order, family, (UdpTableClass)tableClass, reserved);

    private static IntPtr? Query(TableQuery call, uint family, uint tableClass)
    {
        var size = 0;
        var ret = call(IntPtr.Zero, ref size, false, family, tableClass, 0);
        if (ret != ErrorInsufficientBuffer || size <= 0)
            return null;

        for (var attempt = 0; attempt < 3; attempt++)
        {
            var ptr = Marshal.AllocHGlobal(size);
            try
            {
                ret = call(ptr, ref size, false, family, tableClass, 0);
                if (ret == ErrorSuccess)
                    return ptr;
                if (ret == ErrorInsufficientBuffer && size > 0)
                {
                    Marshal.FreeHGlobal(ptr);
                    continue;
                }
                Marshal.FreeHGlobal(ptr);
                return null;
            }
            catch
            {
                Marshal.FreeHGlobal(ptr);
                return null;
            }
        }
        return null;
    }

    private static void AddTcpOwners(int port, uint family, List<PortOwner> result)
    {
        var buffer = Query(QueryTcp, family, (uint)TcpTableClass.OwnerPidAll);
        if (buffer == null)
            return;
        try
        {
            var count = Marshal.ReadInt32(buffer.Value);
            var rowSize = family == AfInet6 ? 52 : 24;
            var basePtr = buffer.Value + 4;
            for (var i = 0; i < count; i++)
            {
                var row = basePtr + i * rowSize;
                var state = unchecked((uint)Marshal.ReadInt32(row));
                if (state != TcpStateListen)
                    continue;

                var localPort = PortFromNetworkUInt32(unchecked((uint)Marshal.ReadInt32(row + (family == AfInet6 ? 20 : 8))));
                if (localPort != port)
                    continue;

                var address = family == AfInet6
                    ? ReadIpv6(row)
                    : ReadIpv4(unchecked((uint)Marshal.ReadInt32(row + 4)));
                var pid = unchecked((int)(uint)Marshal.ReadInt32(row + (family == AfInet6 ? 48 : 20)));
                result.Add(new PortOwner("tcp", address, localPort, pid));
            }
        }
        finally
        {
            Marshal.FreeHGlobal(buffer.Value);
        }
    }

    private static void AddUdpOwners(int port, uint family, List<PortOwner> result)
    {
        var buffer = Query(QueryUdp, family, (uint)UdpTableClass.OwnerPid);
        if (buffer == null)
            return;
        try
        {
            var count = Marshal.ReadInt32(buffer.Value);
            var rowSize = family == AfInet6 ? 28 : 12;
            var basePtr = buffer.Value + 4;
            for (var i = 0; i < count; i++)
            {
                var row = basePtr + i * rowSize;
                var localPort = PortFromNetworkUInt32(unchecked((uint)Marshal.ReadInt32(row + (family == AfInet6 ? 20 : 4))));
                if (localPort != port)
                    continue;

                var address = family == AfInet6
                    ? ReadIpv6(row)
                    : ReadIpv4(unchecked((uint)Marshal.ReadInt32(row)));
                var pid = unchecked((int)(uint)Marshal.ReadInt32(row + (family == AfInet6 ? 24 : 8)));
                result.Add(new PortOwner("udp", address, localPort, pid));
            }
        }
        finally
        {
            Marshal.FreeHGlobal(buffer.Value);
        }
    }

    private static int PortFromNetworkUInt32(uint raw) => (int)(((raw & 0xFF) << 8) | ((raw >> 8) & 0xFF));

    private static string ReadIpv4(uint networkOrderAddr)
    {
        var b = new byte[4];
        b[0] = (byte)(networkOrderAddr >> 24);
        b[1] = (byte)(networkOrderAddr >> 16);
        b[2] = (byte)(networkOrderAddr >> 8);
        b[3] = (byte)networkOrderAddr;
        return new IPAddress(b).ToString();
    }

    private static string ReadIpv6(IntPtr row)
    {
        var b = new byte[16];
        Marshal.Copy(row, b, 0, 16);
        return new IPAddress(b).ToString();
    }
}
