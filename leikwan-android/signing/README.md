# Android Signing Identity (key rotation since 2026-09-11)

## Current identity

| Item | Value |
|---|---|
| Active signing key | `leikwan-android-release` (RSA 4096, 100-year validity, DN `CN=LeiKwan Bridge, O=LeiKwan, OU=Client Apps`) |
| Keystore | `%USERPROFILE%\.android\leikwan-android-release.jks` (password in sibling `.pass` file) |
| Lineage | `leikwan-android/signing/leikwan-android-release-lineage.pem` (no secrets; required for every build) |
| Previous key (v1/v2 co-signer) | `leikwan-android-unstable` in `%USERPROFILE%\.android\leikwan-android-unstable-20260827.jks` |
| Cert SHA-256 | release: `700adc0ab7147cb00e1fec8c0b54db64533480bac4b9cd8fef19ef532963ecb4`; legacy: `2fde6e1ec7142a2e800ee21880d7b0a163857de6985c2def5ad095aad1a68388` |

## Rules (DO NOT BREAK)

1. Every APK build must be signed with the **release key + lineage** and the
   legacy key must co-sign v1/v2 blocks via `--next-signer`. The canonical
   flow is `scripts/package-android-native-test.ps1` (defaults already wired).
2. **Never** sign with the legacy key alone again, and never introduce a third
   key without extending the lineage via `apksigner rotate`.
3. Device behavior: Android 13+ (API 33+) accepts the new cert via v3 lineage;
   Android 8-12 keep seeing the legacy cert on the v2 block, so in-place
   upgrades never break.
4. Backups of keystores, password files and lineage live on the panel host at
   `/root/codex-backups/android-signing-20260911/`.
