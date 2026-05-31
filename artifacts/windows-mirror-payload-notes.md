# Windows mirror payload notes

## Evidence sources

- Approved spec: `docs/superpowers/specs/2026-05-29-windows-app-workflow-parity-design.md`
- Local desktop binding file: `client-binding.json` from the current desktop installation binding evidence
- Recovered real active-forward mirror pull cache: `%LOCALAPPDATA%/Temp/LeikwanClientHotfix/forward_14/forward_14.json`
- Historical Windows runtime payload: `forward_14.json` from an older Windows runtime capture
- Current C# models/builders in `LeikwanBridge.Desktop/src/LeikwanBridge.Core`
- User clarification: `v3` and `v3-mirror` are API-contract equivalent; `-mirror` only changes China-mainland CDN acceleration for file pulls

## Confidence

- Overall confidence for **live active-forward Windows mirror payload shape**: **high for bond/client envelope, medium overall**
- Direct evidence confidence for `endpoint.health_status`: **high**
- Direct evidence confidence for live active-forward Windows MPTCP relay fields on this machine: **low**
- Code/spec inference confidence for relay-first MPTCP / underlay leg shape: **medium**

## Findings

- Real active-forward Windows mirror payload: **present locally**
  - A recovered cached mirror pull under `%LOCALAPPDATA%/Temp/LeikwanClientHotfix/forward_14/forward_14.json` contains `ok: true`, a `client` envelope with `master_url: https://v3-mirror.leikwanhost.com`, and multiple active `bond` forwards.
  - This closes the original “no active-forward live proof” gap for the Windows mirror payload at the client envelope / active-forward level.

- `slave_relay_host` / `slave_relay_port`: **not directly observed in the recovered live active-forward payload on this machine**
  - The recovered active-forward mirror payload is bond-only, so it does not prove a live Windows MPTCP assignment.
  - The current bound client sample is still schema-equivalent because `v3` and `v3-mirror` are API-contract equivalent.
  - The approved design and current `MptcpConfig` / `MptcpRuntimeBuilder` explicitly support relay-first Windows behavior.

- `underlays[].legs[]` fields such as `slave_preconnect`, `preconnect_relay_port`, `health_status`, and `outer_transport`: **represented in the sanitized implementation sample from code/spec inference, not from the recovered live bond payload**
  - The recovered live mirror payload proves active Windows forwards and real client envelope shape, but those active forwards are bond-only.
  - The sanitized JSON keeps the MPTCP contract fields the implementation must consume when such payloads are assigned.

- `endpoint.health_status`: **directly evidenced in the recovered live active-forward mirror payload and historical Windows payloads**
  - Both the recovered mirror pull and historical Windows runtime payload contain `health_status` under `endpoints`.
  - This is strong direct evidence that panel health metadata is part of the Windows client contract.

- Direct Windows CF-WG self-leg requirement: **no evidence that it is currently required**
  - The recovered live active-forward mirror payload is bond-oriented and does not require direct desktop CF-WG self-leg behavior.
  - The approved plan keeps direct Windows CF-WG self-leg out of scope unless a future real payload proves it is required.

## Implementation note

`artifacts/windows-mirror-payload-sample.json` is a **sanitized implementation sample** for the MPTCP-oriented Windows contract shape used by this feature work. It is not presented as a raw live MPTCP pull from the current local binding.

It should be read together with the recovered real active-forward mirror pull evidence above:

- the recovered mirror pull provides real live active-forward proof for the Windows client envelope and bond forward payload shape;
- the sanitized sample provides the MPTCP field shape the implementation must be ready to consume when Windows is assigned such forwards.
