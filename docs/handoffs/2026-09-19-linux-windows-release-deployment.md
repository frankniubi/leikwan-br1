# Linux / Windows release deployment — 2026-09-19

## Scope and release

- Linux agent / installer / Ultra runtime: `2026.09.19.1`.
- Windows GUI and headless: `0.1.20.52`.
- Release commit: `4fd3c15c`; Linux canonical delivery-mode fix: `502fb0a1`.
- Changes were committed locally; this operation did not push Git history.
- UDP acceptance used external UoT through the leased provider relay and Tokyo sidecar, never a native UDP fallback or a LeiKwan slave.

## Linux: installed and verified

Host `172.21.255.189`, client `3`, formal `*:10001` listener.
The existing configuration and forward definitions were retained.

| Component | Installed SHA-256 |
|---|---|
| `/usr/local/bin/leikwan-client-agent` | `ad1fcc60055ae9f2f1e6715dae1a4313dc82d92f80196c9b739fded5742debf6` |
| `/usr/local/bin/ultra-client` | `a391e5b9388b86d53bef9e8cfc44e248bfba9db1a14b903e5863dd37b39acd6e` |
| `/usr/local/bin/install_forward_runtime.py` | `de6c4277eb949e3bd49a4451e4a868c41320a1602e360edac19890d5fc33f7bd` |

The frozen agent was built on Debian 12 / glibc 2.36, not the workstation's newer glibc.
The renderer preserves canonical `reliable_datagram_v1`, omits the raw-mode key for old strict decoders, and rejects the unsupported `reliable_datagram` alias.

After installation, managed configuration was changed by the panel's device-scoped Tokyo allowlist to `reliable_datagram_v1`, sidecar port `7443`.

### Actual post-install tests

Each run: 60 seconds offered load, 128 TCP workers, 20 Mbps per protocol, with drain time recorded separately.

| Mode | TCP successful / failed | TCP Mbps | UDP received / sent | Missing |
|---|---:|---:|---:|---:|
| raw external UoT | 1152 / 0 | 20.150 | 124997 / 125000 | 3 |
| reliable_datagram_v1 | 1152 / 0 | 20.143 | 125000 / 125000 | 0 |

Reliable run: `2026-09-19T08:56:25Z`–`08:57:37Z`, UDP 19.941 Mbps within the offered-load window.
Live runtime evidence showed `ready`, delivery sequence `125010` (including control receipts), empty reliable window, and zero queue drops, queue timeouts, sequence gaps, unrecoverable frames, and socket receive drops.
This is a **60-second post-install check**, not a new 12-minute acceptance run.
The old harness's `reliable_udp_verdict=NOT_TESTED` denotes its missing automatic runtime integration; separate live `runtime_proof` provides mode/counter evidence without changing that original field.

Final readback at approximately `2026-09-19T08:59:41Z`:

- Agent PID `1598262`, executable hash matched above.
- Ultra forwards `158`, `159`, `170`, `171`, `180`: all active, all live `/proc/PID/exe` hashes matched the new runtime.
- Forward `170` PID `1597230`, formal `*:10001` listener; reliable mode and sidecar `7443` retained.
- Automatic rollback timer stopped after acceptance; temporary update hold removed; agent restarted to remove its inherited hold environment.
- Backups and manual rollback retained at `/root/release-upgrade-20260919/`.

Manual rollback, as root:

```sh
bash /root/release-upgrade-20260919/rollback.sh
```

Coordinate panel delivery-mode rollback first when reverting to an older runtime that cannot decode `reliable_datagram_v1`.

## Windows: published artifact staged, formal installation pending

Host `172.21.255.4`, account `lwtest`, client `3`.
The account is not an administrator; service-control queries fail with Access Denied.
The installed headless client resides at `C:\Program Files\LeiKwan Bridge Headless`.
No existing Windows helper or service was stopped or replaced by this deployment operation.

Staged at `C:\Users\lwtest\release-20260919`:

| File | SHA-256 |
|---|---|
| `leikwan-bridge-setup.exe` | `cf6d706169f182caad7a209b2132357f5b24d098002a644116f54178e0c6ac2f` |
| `leikwan-bridge-headless-win-x64.zip` | `e707ca3527fb6f4d60173bd432a6460bbbb37f234333d3022bdadd7feeea4040` |
| `install-windows-headless.ps1` | `ce937ca8b407199639a9dbc7524304248d96fd845af29c490cf2917c5d6a80c4` |

All staged files were read back and hash-checked, including `Install-Headless-Admin.ps1` and `WINDOWS-INSTALL-README.txt`.
The headless ZIP has one outer payload directory, matching the existing updater's directory expectation.
The installer verifies payload version `0.1.20.52`.

From an **Administrator PowerShell**:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Users\lwtest\release-20260919\Install-Headless-Admin.ps1"
```

The helper verifies the archive hash, backs up the existing installation and identity under restricted ACLs, stops the registered headless service, installs the payload, starts the service, and restores the prior files on failure. It reads the existing identity locally without printing tokens.
The GUI setup is separate; do not substitute it for the headless upgrade.

The existing CLI updater launches a detached process with the caller's privileges, not an administrator IPC broker. No noninteractive administrative update path was confirmed for `lwtest`.
**Windows formal IPv4 endpoint deployment and post-install traffic acceptance remain pending administrator execution.** Prior isolated IPv6-loopback canary results do not establish formal endpoint deployment.

## Evidence locations

Local operational evidence, outside the committed source:
`tmp/release-20260919/` in the original checkout.

- `linux-upgrade-result.txt`
- `linux-release-raw-60-summary.json`
- `linux-release-reliable-60.json`
- `linux-release-reliable-proof.json`
- `linux-final-deployment-readback.json`
- `windows-stage-readback.json`
- `windows-updater-readonly.txt`
- `linux-candidate-entries.json`
- `windows-build-manifest.json`

The arm64 installer was rebuilt with the corrected renderer, double-built byte-identically, and passed its QEMU payload self-test. This is not native arm64 installation or traffic acceptance.
