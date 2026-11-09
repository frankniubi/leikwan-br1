using System.Text;

namespace LeikwanBridge.Core.Helpers;

/// <summary>
/// Makes free text safe to send as an HTTP header value. Header values must be
/// ASCII on the wire and .NET rejects anything else when the request is sent, so
/// a non-ASCII machine name fails every panel call. Printable ASCII passes
/// through unchanged; anything else becomes an RFC 2047 UTF-8 base64 encoded
/// word, which the panel decodes back to the original name.
/// </summary>
public static class AsciiHeaderValue
{
    public static string Encode(string value)
    {
        if (value.All(IsPrintableAscii))
            return value;

        return $"=?UTF-8?B?{Convert.ToBase64String(Encoding.UTF8.GetBytes(value))}?=";
    }

    private static bool IsPrintableAscii(char c) => c >= ' ' && c <= '~';
}
