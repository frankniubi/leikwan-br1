# No-GUI clients (macOS and Windows)

> The same guide is published in the panel knowledge base as
> **Run the Client Without a GUI** (`/knowledgebase?thread=no-gui-clients`, in
> English, 简体中文 and 繁體中文), and the client app download centre links it
> next to the Windows payload and the macOS installer.

The windowless client keeps this machine's panel forwards running with no
window, no tray and no desktop session. It is **the same client**: same managed
payload, same Core services, same lease/attestation code, same update pinning.
Only the process lifecycle differs.

## Why it is not a second client

Every protocol-level fix (policy lease delivery, Ultra failover, runtime
attestation) has to reach every surface, and a second implementation would drift
from the GUI exactly where it matters most — the runtime attestation chain that
the panel enforces. Shipping a lifecycle switch instead means:

* the runtime fingerprint and self-hash the panel checks are identical;
* a no-GUI machine can be moved to the GUI client (and back) without
  re-registering, because both read `%ProgramData%\Leikwan\ClientAgent`
  (Windows) / `~/Library/Application Support/LeiKwan/ClientAgent` (macOS);
* the installer scripts never embed a credential: the token lives in a
  root/SYSTEM-owned file that only the service can read.

## macOS

The windowless client is the installed application bundle, run with
`--headless` by a LaunchDaemon (`com.leikwan.bridge.headless`).

1. Install the app (1.0.33 or newer):

       # download artifacts/macos/leikwan-bridge-arm64.dmg (or -x64) from the panel
       # then open it and drag "LeiKwan Bridge" into /Applications

2. Install the daemon (needs root; it writes the plist and the token file):

       curl -fsSL https://v3.leikwanhost.com/artifacts/install-macos-headless.sh -o install-macos-headless.sh
       sudo sh install-macos-headless.sh \
         --panel https://v3.leikwanhost.com \
         --client-id <CLIENT_ID> \
         --agent-token <AGENT_TOKEN>

   `<CLIENT_ID>` and `<AGENT_TOKEN>` are shown on the client page in the panel.

3. Check it:

       sudo sh install-macos-headless.sh --status
       tail -f "/Library/Logs/Leikwan/ClientAgent/headless-"*.log
       cat "/Library/Application Support/Leikwan/ClientAgent/headless-status.json"

4. Remove it:

       curl -fsSL https://v3.leikwanhost.com/artifacts/uninstall-macos-headless.sh -o uninstall-macos-headless.sh
       sudo sh uninstall-macos-headless.sh            # add --purge to drop the stored token

The daemon runs as root because the tunnel installs routes and a TUN device.
It restarts automatically after a crash (`KeepAlive.SuccessfulExit=false`,
15 s throttle) and starts at boot.

## Windows

The Windows payload is a small self-contained build
(`leikwan-bridge-headless.exe`) of the same Core stack; it registers itself as
the service `LeikwanBridgeHeadless` (automatic start, LocalSystem, restart on
failure).

1. Download and extract `artifacts/windows/leikwan-bridge-headless-win-x64.zip`.

2. From an **elevated** PowerShell in the extracted folder:

       .\install-windows-headless.ps1 -Panel https://v3.leikwanhost.com -ClientId <CLIENT_ID> -AgentToken <AGENT_TOKEN>

3. Check it:

       sc query LeikwanBridgeHeadless
       Get-Content "$env:ProgramData\Leikwan\ClientAgent\headless-status.json"
       Get-Content "$env:ProgramData\Leikwan\ClientAgent\logs\headless-$(Get-Date -Format yyyyMMdd).log" -Tail 40

4. Remove it:

       .\uninstall-windows-headless.ps1 -ServiceName LeikwanBridgeHeadless     # add -Purge to remove the token

The service registration keeps the token **out of the command line** (any
authenticated user can read `sc qc`); the installer writes
`%ProgramData%\Leikwan\ClientAgent\agent-token` with an ACL that grants
SYSTEM and Administrators only, and passes `--agent-token-file` to the service.

## Managing an installed client: `leikwan`

The installer puts a `leikwan` command on the PATH (macOS: `/usr/local/bin/leikwan`;
Windows: the install directory, added to the machine PATH):

| command | what it does |
|---|---|
| `leikwan status` | service state, client state and every forward; exit 0 only when connected with a running forward |
| `leikwan start` / `stop` / `restart` | control the daemon/service (macOS asks for your password, Windows needs an elevated shell) |
| `leikwan logs -n 50 [-f]` | tail the client log |
| `leikwan doctor` | service, status file, log and the published version |
| `leikwan version` | installed client version |
| `leikwan update [--check]` | update from the panel manifest: download, SHA-256 check against the manifest, replace the payload, restart the service |

`leikwan update` is the no-GUI replacement for the GUI's update button: it reads
`/artifacts/versions.json`, refuses bytes that do not match the published hash,
and only then swaps the payload and restarts. `--check` reports the installed and
published versions without touching anything, so it is safe in monitoring.

Both wrappers call the same binary with `--cli`, so the command surface cannot
drift from the client it controls.

## Command line

Both surfaces accept the same options:

| option | meaning |
|---|---|
| `--headless` | run without a GUI (macOS: also how the daemon starts) |
| `--panel <url>` | panel base URL; defaults to the stored config, then the shipped panel |
| `--client-id <n>` | client id from the panel |
| `--agent-token <token>` / `--agent-token-file <path>` | client token |
| `--forwards all\|1,2` | which forwards to keep up (default: all) |
| `--log-file <path>` | log file (default: the per-platform log directory) |
| `--status-file <path>` | status JSON rewritten every `--status-interval` seconds |
| `--once` | connect, wait `--once-window` seconds, report, exit 0/1 (smoke test) |
| `--print-status` | also print the status document to stdout |
| `--install-service` / `--uninstall-service` / `--service-name <name>` | Windows service management (Windows only) |

Example smoke test (no daemon, no service):

    "/Applications/LeiKwan Bridge.app/Contents/MacOS/LeikwanBridge.Mac" --headless \
      --panel https://v3.leikwanhost.com --client-id 12 --agent-token <TOKEN> --once --print-status

## Behaviour

* Identity: the agent token `--client-id` pair, verified against the panel
  before any runtime starts.
* Routes: the panel's forward set is applied and kept applied — a forward that
  drops is restarted on the next supervision tick (default 15 s), and a policy
  revocation from the panel stops the forward immediately.
* Liveness: the same heartbeat as the GUI client, which carries the runtime
  attestation (fingerprint + self-hash) the panel enforces.
* Recovery: connect failures back off 1/2/5/10/20/30 s and never exit, because
  the service manager would otherwise restart in a hot loop.
* Shutdown: SIGTERM (launchd), the SCM stop control, or Ctrl+C stops every
  forward and releases the lease before the process exits.

## Not covered yet

* The windowless client does **not** install the Windows system proxy or the
  sing-box TUN: it keeps the panel forwards (including Ultra/native-outbound
  routes) up. Proxy/game mode remains a GUI feature.
* Update *application* is manual on both platforms: re-run the installer (macOS)
  or replace the payload and restart the service (Windows). The client logs and
  reports its own version, and the panel shows it like any other client.
