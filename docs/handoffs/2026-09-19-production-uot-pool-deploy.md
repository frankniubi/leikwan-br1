# 2026-09-19 production UoT pool deploy

## Completed

- Deployed the committed pool-capable panel source to the live panel
  `v3.leikwanhost.com`.
- Backup created before deployment:
  `master/.deploy-backups/uot-pool-predeploy-20260919T170226Z`.
- Remote PHP syntax checks passed for `Bootstrap.php`,
  `ExternalUotConfigController.php`, `ExternalUoTPool.php`, and
  `LeigodTokenBroker.php`.
- Anonymous pool endpoint returned `401`; the existing external-UoT config
  endpoint returned `200` for Tokyo and rendered 15 forwards on port `7443`.
- A live UOT2 HELLO/HELLO_ACK canary to Tokyo `185.220.238.143:7443`
  returned `ok=true`.
- Tokyo's installed Go sidecar was already current:
  version `2026.09.19.1.f18d01fb`, SHA-256
  `c6d11563b8c1b8a28f2d8b3a9422d4215ccc67755a0d0d32e059eba40dfd5317`;
  service was active and listening on `7443`, so no replacement was
  necessary.

## Not enabled

`EXTERNAL_UOT_POOL_JSON` remains unset in production. The new pool code is
therefore fail-closed and does not change current client routing. Enabling it
requires per-node secrets and verified agent access for every candidate node.

## Fleet upgrade completed

After the correct `uot-sidecars.txt` root credentials were supplied, all five
listed UoT hosts were upgraded serially from `2026.09.17.2.95535c6` to
`2026.09.19.1.f18d01fb`, with the manifest SHA-256
`c6d11563b8c1b8a28f2d8b3a9422d4215ccc67755a0d0d32e059eba40dfd5317`.
Each host kept a root backup under `/root/backups/uot-upgrade-20260919-*`;
the installer also created its own binary/config rollback directory.

Upgraded hosts and post-upgrade configured forward counts:

| root host | forwards | service/listener |
|---|---:|---|
| `70.36.96.183` | 10 | active / `:7443` |
| `77.93.90.45` | 2 | active / `:7443` |
| `103.246.219.39:27789` | 3 | active / `:7443` |
| `157.228.138.23` | 1 | active / `:7443` |
| `208.122.17.50` | 3 | active / `:7443` |

Tokyo `185.220.238.143` was already on the same version and was left
running; its configured 15-forward endpoint also passed the canary.

Post-upgrade live UOT2 HELLO/HELLO_ACK canaries passed for all six targets,
using each target's live panel-generated forward token. No secrets are
recorded here.

## Verification

The feature branch tests passed before deployment: PHP pool/config/reliable
tests, Python pool-agent tests including real Go-sidecar echo and rollback,
and `go test ./...`. The production live canary above was run after deploy.

## Commit

Source commit: `e3604ca4 feat: add pooled external UoT sidecars with heartbeats`.
