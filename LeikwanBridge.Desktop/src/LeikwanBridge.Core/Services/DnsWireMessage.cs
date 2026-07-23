using System.Net;
using System.Security.Cryptography;
using System.Text;

namespace LeikwanBridge.Core.Services;

public static class DnsWireMessage
{
    private const ushort TypeA = 1;
    private const ushort ClassIn = 1;

    public static byte[] EncodeAQuery(string host)
    {
        return EncodeAQuery(host, GenerateTransactionId());
    }

    public static byte[] EncodeAQuery(string host, ushort transactionId)
    {
        var normalizedHost = NormalizeHost(host);
        using var stream = new MemoryStream();
        WriteUInt16(stream, transactionId);
        WriteUInt16(stream, 0x0100); // recursion desired
        WriteUInt16(stream, 1); // QDCOUNT
        WriteUInt16(stream, 0); // ANCOUNT
        WriteUInt16(stream, 0); // NSCOUNT
        WriteUInt16(stream, 0); // ARCOUNT
        WriteQName(stream, normalizedHost);
        WriteUInt16(stream, TypeA);
        WriteUInt16(stream, ClassIn);
        return stream.ToArray();
    }

    public static IReadOnlyList<IPAddress> ParseAResponse(byte[] message, ushort expectedTransactionId)
    {
        if (message.Length < 12)
            throw new InvalidOperationException("DNS response is too short");

        var offset = 0;
        var transactionId = ReadUInt16(message, ref offset);
        if (transactionId != expectedTransactionId)
            throw new InvalidOperationException("DNS response transaction id mismatch");

        var flags = ReadUInt16(message, ref offset);
        if ((flags & 0x8000) == 0)
            throw new InvalidOperationException("DNS response is not a response message");
        if ((flags & 0x000f) != 0)
            throw new InvalidOperationException($"DNS response returned rcode {flags & 0x000f}");

        var questionCount = ReadUInt16(message, ref offset);
        var answerCount = ReadUInt16(message, ref offset);
        _ = ReadUInt16(message, ref offset); // NSCOUNT
        _ = ReadUInt16(message, ref offset); // ARCOUNT

        for (var i = 0; i < questionCount; i++)
        {
            SkipName(message, ref offset);
            EnsureAvailable(message, offset, 4);
            offset += 4;
        }

        var addresses = new List<IPAddress>();
        for (var i = 0; i < answerCount; i++)
        {
            SkipName(message, ref offset);
            var type = ReadUInt16(message, ref offset);
            var klass = ReadUInt16(message, ref offset);
            EnsureAvailable(message, offset, 4);
            offset += 4; // TTL
            var rdLength = ReadUInt16(message, ref offset);
            EnsureAvailable(message, offset, rdLength);

            if (type == TypeA && klass == ClassIn && rdLength == 4)
            {
                addresses.Add(new IPAddress(message.AsSpan(offset, 4)));
            }

            offset += rdLength;
        }

        if (addresses.Count == 0)
            throw new InvalidOperationException("DNS response did not contain an A record");

        return addresses;
    }

    private static string NormalizeHost(string host)
    {
        var normalized = (host ?? string.Empty).Trim().TrimEnd('.');
        if (normalized.Length == 0)
            throw new ArgumentException("DNS host is empty", nameof(host));
        return normalized;
    }

    private static ushort GenerateTransactionId()
    {
        Span<byte> bytes = stackalloc byte[2];
        RandomNumberGenerator.Fill(bytes);
        return (ushort)((bytes[0] << 8) | bytes[1]);
    }

    private static void WriteQName(Stream stream, string host)
    {
        foreach (var label in host.Split('.'))
        {
            if (label.Length == 0)
                throw new ArgumentException("DNS host contains an empty label", nameof(host));
            var bytes = Encoding.ASCII.GetBytes(label);
            if (bytes.Length > 63)
                throw new ArgumentException("DNS host label is too long", nameof(host));
            stream.WriteByte((byte)bytes.Length);
            stream.Write(bytes, 0, bytes.Length);
        }
        stream.WriteByte(0);
    }

    private static ushort ReadUInt16(byte[] message, ref int offset)
    {
        EnsureAvailable(message, offset, 2);
        var value = (ushort)((message[offset] << 8) | message[offset + 1]);
        offset += 2;
        return value;
    }

    private static void WriteUInt16(Stream stream, ushort value)
    {
        stream.WriteByte((byte)(value >> 8));
        stream.WriteByte((byte)(value & 0xff));
    }

    private static void SkipName(byte[] message, ref int offset)
    {
        var jumps = 0;
        while (true)
        {
            EnsureAvailable(message, offset, 1);
            var length = message[offset];
            if ((length & 0xc0) == 0xc0)
            {
                EnsureAvailable(message, offset, 2);
                offset += 2;
                return;
            }

            if ((length & 0xc0) != 0)
                throw new InvalidOperationException("DNS response contains an unsupported label pointer");

            offset++;
            if (length == 0)
                return;

            EnsureAvailable(message, offset, length);
            offset += length;
            jumps++;
            if (jumps > 128)
                throw new InvalidOperationException("DNS response name is too deep");
        }
    }

    private static void EnsureAvailable(byte[] message, int offset, int count)
    {
        if (offset < 0 || count < 0 || offset + count > message.Length)
            throw new InvalidOperationException("DNS response is truncated");
    }
}
