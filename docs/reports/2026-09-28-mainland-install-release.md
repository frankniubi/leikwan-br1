# Mainland-only Linux install release (2026-09-28)

## Problem

Some Linux hosts can reach only mainland networks. The Linux command on the website fetched
`install-client-agent.sh` from Cloudflare only, and the installer downloaded every artifact from that one base,
so those hosts could not install. Installed agents were already fine: control-plane requests fail over to the
Aliyun mirror, and self-updates use whichever endpoint answered.

## What shipped (26572f37, manifest 77ba1ff5)

- **Panel commands:** when the artifact base is `https://v3.leikwanhost.com`, the Linux install, arm64 install
  and uninstall commands retry through `https://api-leikwanbridge.nbwly.com` after a 10 s connect timeout
  (`ARTIFACT_MIRROR_BASE_URL` overrides). The script is saved to a temp file before bash runs it, so a failed
  first attempt can never run a partial script. The tenant install page says mainland-only hosts can use the same command.
- **Installer 2026.09.28.1:** `versions.json` is fetched from the given official source, then from the other
  one. The source that answers becomes the download base and the persisted artifact base. Custom bases (such as
  the arm64 bundle's local server) get no fallback. Panel connects time out after 8 s (curl 10 s).
- **arm64 offline installer 2026.09.28.1:** rebuilt because it embeds the installer (reproducible, `49053a12`).

## Publication (18:36–18:48 UTC)

1. **Panel PHP**, 18:36 UTC: `BaseController.php` and `TenantController.php`.
   - Both live files matched git before the change.
   - Backup and `rollback.sh`: `/root/codex-backups/panel-mainland-install-20260928/`.
   - The production PHP 8.3 rendered the three new commands from the production `.env`.
2. **Artifacts `mainland-install-20260928-1`**, 18:48 UTC: status `committed`.
   - Backup: `/root/codex-backups/mainland-install-20260928-1/`.
   - Installer `f5ded3fa` and arm64 installer `49053a12` are served with those hashes through both Cloudflare and Aliyun.
3. **Health:** php-fpm active, `/api/health` ok, login page renders, no PHP fatal, no 429. The only 5xx were
   3 × 502 at the reload second, from long-polls cut off by the reload.

## Verification

On Debian host 172.21.255.189, Cloudflare ranges were dropped for uid `nobody` only. The test ran in a private
mount namespace with a clean `/etc/hosts`, and the rules were removed afterwards.

| Case | Result |
|---|---|
| Normal network | Cloudflare selected, 2 s |
| Cloudflare dropped, website command | Switched to Aliyun after 11 s, fetched installer 2026.09.28.1 |
| Cloudflare dropped, installer source selection | Switched to Aliyun after 18 s, both bases persisted as Aliyun |

## Not covered

- Missing packages (`apt-get`) and clock sync (NTP) use the host's own mirrors and time servers.
- Installed agents still retry Cloudflare at most every 5 minutes, and each attempt can take up to 30 s
  (agent code unchanged).
- 172.21.255.189 still carries two old `# lk-outage` lines in `/etc/hosts` (from 2026-09-24) that point both
  panel hosts at `127.0.0.9`. They block that host's Aliyun mirror. Left untouched.

## Follow-up: shorter fallback wait (releases -2 and -3)

A mainland-only host used to wait about 29 s with no output: 10 s in the website command, then 18 s in the installer
(two Cloudflare addresses at 8 s each). A reachable Cloudflare edge connects in 0.22–0.34 s from the Jiangsu host.

- **Release -2**, 05:16–05:17 UTC (0cafd83a, installer 2026.09.28.2):
  - Website command: the Cloudflare attempt now has a 3 s connect timeout. `BaseController.php` was deployed with a
    backup at `/root/codex-backups/panel-mainland-install-20260928-2/`.
  - Installer: asks both sources at once, with a 3 s budget for the whole `versions.json` fetch.
  - Problem: after release, a healthy host once needed more than 3 s for DoH + TLS + transfer and was moved to Aliyun.
- **Release -3**, 05:31 UTC (8966b116, installer 2026.09.28.3):
  - The 3 s now covers only the connect: a probe connects to the first address with a 3 s timeout.
  - The given source wins whenever it connects, however long the transfer takes. Another source is used once that
    probe failed, or after 10 s as a last resort.
  - Messages come from the probe state at decision time.
  - Published hashes: installer `409760b5`, arm64 installer `08b99faa`, identical through Cloudflare and Aliyun.

Result with the published -3 installer on 172.21.255.189:

| Case | Result |
|---|---|
| Normal network, 3 runs | Cloudflare every time (2–3 s) |
| Cloudflare dropped, website command | Switched to Aliyun after 3 s |
| Cloudflare dropped, installer | Switched to Aliyun after 4 s |

The total extra wait on a mainland-only host went from about 29 s to about 7 s.
