using System.Diagnostics;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Manages WireGuard key generation for heartbeat identity reporting.
/// </summary>
public sealed class WireGuardManager
{
    private readonly string _wgBin;
    private readonly string _keyDir;

    public string PublicKey { get; private set; } = "";

    public WireGuardManager(string wgBin, string stateDir)
    {
        _wgBin = wgBin;
        _keyDir = Path.Combine(stateDir, "wireguard");
        Directory.CreateDirectory(_keyDir);
    }

    public void EnsureIdentity()
    {
        var privatePath = Path.Combine(_keyDir, "private.key");
        var publicPath = Path.Combine(_keyDir, "public.key");

        if (File.Exists(privatePath) && File.Exists(publicPath))
        {
            PublicKey = File.ReadAllText(publicPath).Trim();
            if (!string.IsNullOrEmpty(PublicKey))
            {
                Log.Debug("loaded existing WireGuard identity: {PublicKey}", PublicKey);
                return;
            }
        }

        if (!File.Exists(_wgBin))
        {
            // Expected: the Windows installer ships no wg.exe (it is not in the
            // setup inputs), so the WireGuard identity is simply absent on a fresh
            // install. This used to log a warning that reads like a fault on every
            // start; the identity is optional and the panel treats a missing key as
            // unknown.
            Log.Debug("wg binary not present at {Path}; no WireGuard identity on this host", _wgBin);
            return;
        }

        try
        {
            var genkey = RunWg("genkey");
            File.WriteAllText(privatePath, genkey);

            var pubkey = RunWgWithInput("pubkey", genkey);
            File.WriteAllText(publicPath, pubkey);
            PublicKey = pubkey;

            Log.Information("generated WireGuard identity: {PublicKey}", PublicKey);
        }
        catch (Exception ex)
        {
            Log.Error(ex, "failed to generate WireGuard identity");
        }
    }

    private string RunWg(string args)
    {
        var psi = new ProcessStartInfo(_wgBin, args)
        {
            UseShellExecute = false,
            RedirectStandardOutput = true,
            CreateNoWindow = true,
        };
        using var proc = Process.Start(psi)!;
        var output = proc.StandardOutput.ReadToEnd().Trim();
        proc.WaitForExit(5000);
        return output;
    }

    private string RunWgWithInput(string args, string input)
    {
        var psi = new ProcessStartInfo(_wgBin, args)
        {
            UseShellExecute = false,
            RedirectStandardInput = true,
            RedirectStandardOutput = true,
            CreateNoWindow = true,
        };
        using var proc = Process.Start(psi)!;
        proc.StandardInput.Write(input);
        proc.StandardInput.Close();
        var output = proc.StandardOutput.ReadToEnd().Trim();
        proc.WaitForExit(5000);
        return output;
    }
}
