# macOS installation regression investigation

Status: not released. Mac 1.0.8 remains online; do not claim the installation problem is fixed.

## Evidence

- The customer screenshot shows the app icon, but no `Applications` installation shortcut, and a Gatekeeper warning that Apple cannot check the app for malicious software.
- The original `scripts/package-macos-client.sh` creates `Applications -> /Applications`. The temporary Windows/WSL 1.0.8 packaging workflow bypassed that script and omitted this root-level link. Both final extracted 1.0.8 DMGs are rejected by the new install-surface validator for this exact omission.
- Gatekeeper is a separate acceptance requirement from file hashes, CodeDirectory digest checks, DMG mounting and executable presence. Previous ad-hoc signing is not Developer ID signing/notarization.
- New test Mac: `100.103.253.21`, Tailscale online and directly reachable. Remote Login was initially disabled; after the user enabled it, TCP 22 became reachable. Authentication as `leikwan` using the existing local Mac build key is currently rejected. The user was given the public-key authorization command; no password was requested or stored.

## Changes Prepared

- `scripts/verify-macos-dmg-layout.py` requires one root-level app, the exact `/Applications` symlink, correct bundle identity/version/architecture and executable mode.
- The native package script now verifies and mounts the completed DMG and invokes this gate. It retains the mount directory if cleanup cannot detach it.
- The macOS workflow runs the eight layout regression tests and validates the actual mounted image.
- Existing packaging tests still pass. Local evidence: `.tmp/macos-install-repair-20260906/validation.json` and adjacent logs.
- Remote read-only audit is prepared at `.tmp/macos-install-repair-20260906/audit-test-mac.sh`. It downloads the exact public 1.0.8 artifact, verifies its hash, mounts it, and records Applications-link, Apple codesign and Gatekeeper outcomes independently. It does not disable Gatekeeper or modify the installed app.

## Next Steps

1. Complete SSH key authorization and confirm the actual macOS login username.
2. Run the prepared audit on the test Mac; preserve system version, Apple signature details, Gatekeeper outcome and package layout.
3. Inspect the Mac keychain for a usable Developer ID Application identity and notarization configuration. Do not invent a certificate or claim ad-hoc signing passes default Gatekeeper.
4. Build/package using native macOS tooling, verify the Applications shortcut, install a backed-up test copy into `/Applications`, and test first launch. Record any explicit per-app trust exception separately from default Gatekeeper acceptance.
5. Only after these results, publish a new Mac-only version with backup-first hash guards and full public download checks. Do not modify the verified Android/Windows/Linux/PHP artifacts.

Apple's documented per-app manual exception is System Settings > Privacy & Security > Open Anyway after attempting to open the app: https://support.apple.com/102445. This is a temporary user action, not a substitute for signing/notarization or a reason to disable system-wide protection.
