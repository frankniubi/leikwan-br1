# SS2022 key-normalization interop cases

Proves the client-side SS2022 key normalizer (`SS2022KeyNormalizer`) makes the app's generated
sing-box outbound connect to the three server flavours users actually run:

1. **sing-box server with an over-long key** — sing-box's server accepts `len(psk) > keyLength`
   and hashes it (SHA256, truncated). The strict sing-box *client* library rejects such keys with
   `bad key length`; the normalizer converts the stored key to `base64(SHA256(decoded)[:32])`,
   which the server's `Key()` maps to the same working key.
2. **Xray server with a raw 32-char plaintext key** — Xray's `ParseKey` accepts raw bytes; the
   normalizer re-encodes them as base64, which Xray also accepts.
3. **Exact-length base64 key** — passes through unchanged.

The client config is built by the app's own `SingboxConfigBuilder` (unit test dumps it), then
`harness.py` starts the real server (sing-box or Xray), connects through the app config's
shadowsocks outbound, and asserts an HTTP round-trip succeeds.

Files:
- `server-singbox-overlong.json` — sing-box server config, multi-user `iPSK:uPSK`, iPSK decodes
  to 59 bytes (over-long on purpose).
- `server-xray-rawkey.json` — Xray server config, 32-char raw ASCII password.
- `client-android.json` / `client-ios.json` — dumped by the unit tests
  (`SS2022InteropConfigDumpTest` / `SS2022InteropConfigDumpTests`) when `SS2022_INTEROP_OUT` is set.
- `harness.py` — stdlib-only: starts the server, rewrites the client config's inbound to a
  loopback mixed inbound (route rules untouched), dials through it, expects the marker body.

Run (CI, see .github/workflows/ss2022-interop.yml):
```
python3 tools/ss2022-interop/harness.py --server singbox --config client-android.json \
  --singbox <sing-box binary> --out out/
python3 tools/ss2022-interop/harness.py --server xray --config client-ios.json \
  --xray <xray binary> --out out/
```
