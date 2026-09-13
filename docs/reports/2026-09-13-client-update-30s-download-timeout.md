# Client updates died at exactly 30 seconds (2026-09-13)

## Cause

Every panel-facing HTTP client is built by `LeikwanHttpClientFactory` with

    Timeout = TimeSpan.FromSeconds(30)

and in .NET `HttpClient.Timeout` bounds the **whole operation, including reading
the response body**. The Windows installer is **69,545,860 B** and the macOS
update packages are 68-72 MB, so `WindowsAppUpdateService.DownloadInstallerAsync`
(and `MacAppUpdateService.DownloadVerifiedAsync`) could only ever finish on a
link faster than ~2.3 MB/s. Anything slower died at **exactly 30 seconds** with
partial bytes thrown away and the next attempt starting from zero - which is the
"update always has a 30 second limit" report.

The 30 s value itself is fine for control-plane calls (heartbeat, config pull,
lease); it is simply wrong for a 70 MB artifact.

## Fix

`LeikwanBridge.Core/Services/ResumableArtifactDownloader.cs`, used by both the
Windows and the macOS updater:

* The artifact lives in `<destination>.part` and each attempt continues from the
  byte it reached, sending `Range: bytes=N-`. The 30 s request budget becomes a
  **chunk size** instead of a deadline: a slow link finishes in a bounded number
  of attempts (64 by default), a healthy one still needs exactly one.
* **Liveness is enforced separately**: no bytes for 20 s abandons the attempt
  immediately, so a dead connection still fails fast instead of hanging.
* **The manifest size is authoritative.** A body truncated to exactly the
  `Content-Length` the server advertised would otherwise look complete; the test
  suite caught that case.
* A server that ignores `Range` (200 instead of 206) forces the attempt to
  **restart from zero** rather than append a body that starts at byte 0.
  `416` restarts as well.
* The completed `.part` is promoted with `File.Move(overwrite: true)`, so the
  destination path only ever holds a complete artifact.

## Verification

Unit tests (`ResumableArtifactDownloaderTests`, 6 cases, all green as part of
**508/508** Core tests):

| case | pinned behaviour |
|---|---|
| a dead attempt resumes | offsets 0/300/600/900 for a 1000 B artifact at 300 B/attempt, final bytes equal, `.part` gone |
| Range header on retry | offsets 0/250/500 |
| server ignores Range | result is not corrupted by the first 200 bytes; downloader rewrites from 0 |
| `416` | starts over and completes |
| stalled connection | gives up and reports, well inside the stall budget |
| final hash | `WindowsAppUpdateService.ComputeSha256` equals the artifact hash |

Live panel checks of the server half:

    GET                  -> 200  content-length: 69545860   accept-ranges: bytes
    Range: bytes=60000000-  -> 206  content-range: bytes 60000000-69545859/69545860
    Range: bytes=100-199    -> sha256 identical to bytes 100..199 of the file on disk

The last one matters: it proves a resumed chunk is the *right* bytes, so a
resumed download cannot produce a shifted or mixed artifact.

## Release

**0.1.20.43** - `609c204308759d275ff3e811eafa86fad6e8420a671524826cfb9fde1251497a`,
69,545,860 B, runtime pin unchanged (`ecbee8f7e315e6fb`).

Carries both this fix and the lease-cadence fix from
`docs/reports/2026-09-13-panel-manifest-digest-and-lease-cadence.md`
(0.1.20.42 was superseded before it had rolled out). Published to both
entrances; only the `windows_client_app` manifest entry changed and the bytes
served over HTTPS hash to the published value.

## Left alone

* `MacAppUpdateApplier` gives the macOS installer **90 s** `WaitForExitAsync`
  budget. That is the apply step, not the download, and it cannot be tested from
  Windows - flagging it rather than changing it blind.
* `LeikwanHttpClientFactory` still caps control-plane requests at 30 s, which is
  the right value for those.
