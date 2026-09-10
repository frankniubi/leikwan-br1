# LeiKwan Host Android vNext Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Deliver a trusted LeiKwan Host Android client using Client ID plus
Agent Token, silent unsupported-mode dropping, current-mode single/bond, Ultra,
direct SS/VLESS/WireGuard/SOCKS5, and authoritative Panel kick/ban.

**Architecture:** The Panel owns source-IP attribution, attestation, device
session, control state, and Ultra authorization. Android owns a Keystore-backed
identity, encrypted session, process lifecycle, direct-route compilation, and
Compose UI. Ultra provider material crosses a private Unix socket once and
never enters static configuration or UI state.

**Tech Stack:** Kotlin, Compose, Android Keystore, Retrofit/OkHttp, libbox,
Go 1.22, PHP 8.3, MySQL, JUnit/Robolectric, MockWebServer, standalone PHP
contract tests.

**Spec:** docs/superpowers/specs/2026-08-27-android-host-vnext-design.md

## Global Constraints

- Accept only backend_mode=current_mode with raw client_mode single or bond,
  plus a valid backend_mode=ultra policy-v2 forward. Drop every other raw
  family before UI, state, user-visible logging, or runtime.
- Preserve client -> slave -> sidecar for ordinary forwarding. Do not create
  direct client-to-sidecar connectivity.
- Never persist, log, render, or place in argv/environment an Ultra provider
  IP, CIDR, credential, session ticket, nonce, lease, or target.
- Reject rooted, emulator, unlocked-boot, software-attested, and non-arm64
  devices before usable config pull, VPN start, or lease access.
- Use selectors for finite choices. Retain text input only for IDs, tokens,
  arbitrary endpoints, credentials, keys, and custom rules.
- Implement in a clean linked worktree from committed master. Do not edit,
  stage, or revert dirty root-checkout files. Do not deploy or publish.
- Do not copy local credential-file values into source, tests, commits,
  artifacts, or reports.

## File Map

**Panel create**
- master/migrations/20260827_add_android_device_control.sql
- master/src/Support/TrustedClientIpResolver.php
- master/src/Support/AndroidDeviceAttestationVerifier.php
- master/src/Support/DeviceControlService.php
- master/src/Support/AndroidEnrollmentCodeService.php
- master/tests/android_device_control_schema_test.php
- master/tests/trusted_client_ip_resolver_test.php
- master/tests/android_device_attestation_contract_test.php
- master/tests/android_device_control_contract_test.php
- master/tests/android_client_enrollment_contract_test.php
- master/tests/android_ultra_policy_v2_contract_test.php

**Panel modify**
- master/src/Http/Request.php
- master/src/Support/ClientOnlineSessionService.php
- master/src/Controllers/ClientAgentController.php
- master/src/Controllers/TenantController.php
- master/src/Controllers/BaseController.php
- master/src/Bootstrap.php
- master/src/Support/LeigodTokenBroker.php
- master/src/Support/UltraLeaseService.php
- master/tests/ultra_lease_endpoint_contract_test.php

**Android create**
- app/src/main/java/com/leikwan/bridge/security/DeviceSecurity.kt
- app/src/main/java/com/leikwan/bridge/security/AndroidDeviceIdentity.kt
- app/src/main/java/com/leikwan/bridge/security/AndroidAttestationProofFactory.kt
- app/src/main/java/com/leikwan/bridge/session/DeviceControlModels.kt
- app/src/main/java/com/leikwan/bridge/session/SessionRevocationCoordinator.kt
- app/src/main/java/com/leikwan/bridge/session/ClientControlLoop.kt
- app/src/main/java/com/leikwan/bridge/runtime/ProtectedConnectivitySet.kt
- app/src/main/java/com/leikwan/bridge/runtime/UltraModels.kt
- app/src/main/java/com/leikwan/bridge/runtime/UltraForwardRunner.kt
- app/src/main/java/com/leikwan/bridge/runtime/UltraLeaseClient.kt
- app/src/main/java/com/leikwan/bridge/ui/login/EnrollmentViewModel.kt
- app/src/main/java/com/leikwan/bridge/ui/login/EnrollmentScreen.kt
- app/src/main/java/com/leikwan/bridge/ui/security/BlockedDeviceScreen.kt
- scripts/build-ultra-android.ps1
- scripts/verify-android-ultra-artifact.ps1

**Android modify**
- app/build.gradle.kts, AndroidManifest.xml, Prefs.kt, PanelApi.kt,
  PanelModels.kt, PanelRepository.kt, MainActivity.kt
- ForwardMode.kt, ForwardContract.kt, ForwardFilter.kt,
  AndroidEffectiveForwardBuilder.kt, RuntimeContext.kt,
  RuntimeBinaryInstaller.kt, AndroidRuntimeJanitor.kt
- TunnelOrchestrator.kt, LeikwanVpnService.kt, SingboxConfigBuilder.kt
- ProxyUriParser.kt, ProxyProtocolDraft.kt, ProxyNodeEditor.kt,
  AddProxyDialog.kt, ProxyListSections.kt, TunnelsScreen.kt, string resources
- existing effective-forward, filter, sing-box, proxy parser, repository,
  tunnel orchestrator tests plus new security/session/Ultra/enrollment tests

---

### Task 1: Add Device-Control Schema and Trusted Source IP

**Files:**
- Create: master/migrations/20260827_add_android_device_control.sql
- Create: master/src/Support/TrustedClientIpResolver.php
- Modify: master/src/Http/Request.php
- Modify: master/src/Support/ClientOnlineSessionService.php
- Test: master/tests/android_device_control_schema_test.php
- Test: master/tests/trusted_client_ip_resolver_test.php

**Interfaces:**
- Request::remoteAddress(): ?string reads REMOTE_ADDR without breaking current
  Request test construction.
- TrustedClientIpResolver::resolve(Request): ?string trusts forwarded IP only
  when the remote peer matches TRUSTED_PROXY_CIDRS.
- The migration contains client_device_controls, hashed sessions/nonces/codes,
  command version, state, expiry, and no provider-material field.

- [ ] **Step 1: Write failing schema and source-IP tests.**

~~~php
assert(str_contains($sql, 'client_device_controls'));
assert(str_contains($sql, 'command_version'));
assert(!preg_match('/\b(provider_ip|credential|password|agent_token)\b/i', $sql));
assertSame('203.0.113.8', $resolver->resolve(request('10.0.0.5', ['x-forwarded-for' => '203.0.113.8'])));
assertSame('198.51.100.7', $resolver->resolve(request('198.51.100.7', ['x-forwarded-for' => '1.2.3.4'])));
~~~

- [ ] **Step 2: Run RED.**

~~~powershell
php master/tests/android_device_control_schema_test.php
php master/tests/trusted_client_ip_resolver_test.php
~~~

Expected: FAIL because the migration and resolver are absent.

- [ ] **Step 3: Implement the schema and resolver.** Add idempotent control,
  challenge, and one-time-code tables. Use a unique client/device row,
  active/kicked/banned state, monotonically increasing command version,
  SHA-256 secret hashes, expiry indexes, and audit fields. Parse IPv4/IPv6
  CIDRs; choose a forwarded value only from a trusted remote peer.

- [ ] **Step 4: Run GREEN.**

~~~powershell
php master/tests/android_device_control_schema_test.php
php master/tests/trusted_client_ip_resolver_test.php
php master/tests/client_online_session_revoke_test.php
~~~

Expected: PASS.

- [ ] **Step 5: Commit.**

~~~powershell
git add master/migrations/20260827_add_android_device_control.sql master/src/Http/Request.php master/src/Support/TrustedClientIpResolver.php master/src/Support/ClientOnlineSessionService.php master/tests/android_device_control_schema_test.php master/tests/trusted_client_ip_resolver_test.php
git commit -m "feat: add Android device control state"
~~~

### Task 2: Verify Android Attestation and Device Sessions

**Files:**
- Create: master/src/Support/AndroidDeviceAttestationVerifier.php
- Create: master/src/Support/DeviceControlService.php
- Test: master/tests/android_device_attestation_contract_test.php
- Test: master/tests/android_device_control_contract_test.php

**Interfaces:**
- AndroidDeviceAttestationVerifier::verify(array $proof, string $nonce,
  int $clientId, string $deviceId): AttestationVerificationResult.
- DeviceControlService::issueChallenge, enroll, requireActive, acknowledge.
- requireActive returns terminal device_kicked, device_banned, or
  device_untrusted before config/lease material is resolved.

- [ ] **Step 1: Write failing attestation/control tests.** Include a valid
  fixture chain, nonce replay, package certificate mismatch, unlocked boot,
  software security level, invalid identity binding signature, kicked, and
  banned device cases.

~~~php
assert($verifier->verify($valid, $nonce, 17, 'android-client-17-aabb')->trusted);
assertSame('boot_not_locked', $verifier->verify($unlocked, $nonce, 17, 'android-client-17-aabb')->reason);
assertSame('device_banned', $controls->requireActive($bannedClient, $request)->errorCode());
~~~

- [ ] **Step 2: Run RED.**

~~~powershell
php master/tests/android_device_attestation_contract_test.php
php master/tests/android_device_control_contract_test.php
~~~

Expected: FAIL because verifier and control service are absent.

- [ ] **Step 3: Implement fail-closed verification.** Verify attestation OID
  1.3.6.1.4.1.11129.2.1.17, single-use nonce expiry, app package/certificate,
  GREEN locked boot, hardware security level, and the fresh attestation-key
  signature of nonce|client_id|device_id|SHA-256(identity_spki). Store only
  stable identity public-key hash and proof metadata.

- [ ] **Step 4: Implement device sessions.** Enroll validates client ID against
  bearer-authenticated client. Active and explicit reenrollment of a kicked
  device rotate a 15-minute hashed device session. Banned never enrolls.
  requireActive validates X-Device-Session, identity, expiry, trust, and state.
  ACK is observational and cannot reactivate.

- [ ] **Step 5: Run GREEN and commit.**

~~~powershell
php master/tests/android_device_attestation_contract_test.php
php master/tests/android_device_control_contract_test.php
git add master/src/Support/AndroidDeviceAttestationVerifier.php master/src/Support/DeviceControlService.php master/tests/android_device_attestation_contract_test.php master/tests/android_device_control_contract_test.php
git commit -m "feat: require trusted Android device sessions"
~~~

Expected: tests PASS before the commit.

### Task 3: Expose Enrollment, Kick, Ban, and Safe Links

**Files:**
- Create: master/src/Support/AndroidEnrollmentCodeService.php
- Modify: master/src/Controllers/ClientAgentController.php
- Modify: master/src/Controllers/TenantController.php
- Modify: master/src/Controllers/BaseController.php
- Modify: master/src/Bootstrap.php
- Test: master/tests/android_client_enrollment_contract_test.php

**Interfaces:**
- POST /api/client/devices/challenge returns nonce and expiry.
- POST /api/client/devices/enroll returns client ID, device session, and expiry.
- POST /api/client/devices/ack records terminal action acknowledgement.
- Tenant actions remain CSRF-protected kick, ban, unban, enrollment-code.
- Terminal errors contain only ok, error, command and no config/lease fields.

- [ ] **Step 1: Write endpoint RED tests.**

~~~php
assertSame(409, $kickedPull->status());
assertSame('device_kicked', json_decode($kickedPull->body(), true)['error']);
assertSame(403, $bannedLease->status());
assert(!str_contains($bannedLease->body(), 'endpoint'));
assert(!str_contains($androidInstallLink, 'agent_token='));
~~~

- [ ] **Step 2: Run RED.**

~~~powershell
php master/tests/android_client_enrollment_contract_test.php
~~~

Expected: FAIL because routes and guarded responses are absent.

- [ ] **Step 3: Implement APIs and actions.** Authenticate agent token first,
  then require control only for Android pull, heartbeat, and Ultra lease;
  preserve Windows/Linux flows. Kick increments command version and revokes the
  device session and Ultra generation. Ban blocks enrollment. Unban only
  changes state. Exchange a hashed, atomic, five-minute App Link code instead
  of rendering a raw agent token in a URI.

- [ ] **Step 4: Run GREEN and commit.**

~~~powershell
php master/tests/android_client_enrollment_contract_test.php
php master/tests/tenant_client_page_simplification_regression_test.php
git add master/src/Support/AndroidEnrollmentCodeService.php master/src/Controllers/ClientAgentController.php master/src/Controllers/TenantController.php master/src/Controllers/BaseController.php master/src/Bootstrap.php master/tests/android_client_enrollment_contract_test.php
git commit -m "feat: add Android enrollment and device actions"
~~~

Expected: tests PASS and no Android link contains an agent token.

### Task 4: Add Android Security, Identity, and Encrypted State

**Files:**
- Create: app/src/main/java/com/leikwan/bridge/security/DeviceSecurity.kt
- Create: app/src/main/java/com/leikwan/bridge/security/AndroidDeviceIdentity.kt
- Create: app/src/main/java/com/leikwan/bridge/security/AndroidAttestationProofFactory.kt
- Modify: app/src/main/java/com/leikwan/bridge/data/Prefs.kt
- Modify: app/build.gradle.kts
- Test: app/src/test/java/com/leikwan/bridge/security/DeviceSecurityTest.kt
- Test: app/src/test/java/com/leikwan/bridge/security/AndroidDeviceIdentityTest.kt

**Interfaces:**
- DeviceSecurity.evaluate(DeviceSignals): DeviceSecurityResult.
- AndroidDeviceIdentity.deviceId(clientId: Long): String.
- AndroidAttestationProofFactory.create(nonce, clientId, deviceId): AttestationProof.
- Prefs.agentToken, deviceSessionToken, deviceSessionExpiresAtMs, and
  clearEnrollment replace password/session fields.

- [ ] **Step 1: Write pure RED tests.**

~~~kotlin
assertEquals(DeviceSecurityResult.Allowed, DeviceSecurity.evaluate(physicalArm64Signals))
assertEquals(DeviceSecurityResult.Rooted, DeviceSecurity.evaluate(signalsWithSuBinary))
assertEquals(DeviceSecurityResult.Emulator, DeviceSecurity.evaluate(qemuFingerprintSignals))
assertEquals(DeviceSecurityResult.UnsupportedAbi, DeviceSecurity.evaluate(x86Signals))
assertEquals(first, identity.deviceId(17L))
assertNotEquals(first, identity.deviceId(18L))
~~~

- [ ] **Step 2: Run RED.**

~~~powershell
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.security.*"
~~~

Expected: FAIL because security and identity classes are absent.

- [ ] **Step 3: Implement local gate and identity.** Reject test keys,
  ro.debuggable, known root packages, executable su paths, emulator/QEMU
  strings, unlocked build tags, and non-arm64 ABI. Generate a non-exportable
  stable identity key; derive ID from DER SPKI hash plus client ID; generate a
  fresh separate attestation key for every nonce.

- [ ] **Step 4: Migrate preferences.** Atomically remove old password, email,
  username, clients_json, and desktop-session keys while retaining local
  routing selections. clearEnrollment removes agent token, device session,
  identity, and selected forward.

- [ ] **Step 5: Run GREEN and commit.**

~~~powershell
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.security.*" --tests "com.leikwan.bridge.data.PrefsRoutingStateTest"
git add app/build.gradle.kts app/src/main/java/com/leikwan/bridge/security app/src/main/java/com/leikwan/bridge/data/Prefs.kt app/src/test/java/com/leikwan/bridge/security
git commit -m "feat: add Android device trust primitives"
~~~

Expected: tests PASS before the commit.

### Task 5: Replace Password Login with Token Enrollment and Device Headers

**Files:**
- Create: app/src/main/java/com/leikwan/bridge/session/DeviceControlModels.kt
- Modify: app/src/main/java/com/leikwan/bridge/api/PanelApi.kt
- Modify: app/src/main/java/com/leikwan/bridge/api/PanelModels.kt
- Modify: app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt
- Test: app/src/test/java/com/leikwan/bridge/api/AndroidEnrollmentRepositoryTest.kt
- Modify: app/src/test/java/com/leikwan/bridge/api/PanelModelsContractParsingTest.kt

**Interfaces:**
- PanelRepository.enroll(clientId: Long, agentToken: String): EnrollmentResult.
- PanelRepository.requireActiveSession refreshes via challenge/proof only while
  agent token remains locally encrypted.
- An OkHttp interceptor adds Authorization, X-Device-Id, X-Device-Name,
  X-Device-Platform=android-host, X-Client-Version, and X-Device-Session.
- PanelControlException contains a redacted error and optional command.

- [ ] **Step 1: Write MockWebServer RED tests.**

~~~kotlin
assertEquals("Bearer agent-token", request.getHeader("Authorization"))
assertEquals("android-client-17-aabb", request.getHeader("X-Device-Id"))
assertEquals("android-host", request.getHeader("X-Device-Platform"))
assertEquals("device-session", request.getHeader("X-Device-Session"))
assertEquals(17L, repository.enroll(17L, "agent-token").clientId)
~~~

Add device_kicked, device_banned, and device_untrusted response fixtures and
assert each becomes PanelControlException without body logging.

- [ ] **Step 2: Run RED.**

~~~powershell
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.api.AndroidEnrollmentRepositoryTest" --tests "com.leikwan.bridge.api.PanelModelsContractParsingTest"
~~~

Expected: FAIL because enrollment and headers are absent.

- [ ] **Step 3: Implement models/repository.** Add challenge, enroll,
  acknowledgement, protected heartbeat/pull, and one-shot Ultra lease Retrofit
  methods using current strict DoH setup. Validate expiry before persisting a
  device session. Remove LoginRequest/selectClient/password persistence and
  require positive Client ID plus unexpired session in isLoggedIn.

- [ ] **Step 4: Run GREEN and commit.**

~~~powershell
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.api.AndroidEnrollmentRepositoryTest" --tests "com.leikwan.bridge.api.PanelModelsContractParsingTest" --tests "com.leikwan.bridge.api.PanelRepositoryRouteRuleSyncTest"
git add app/src/main/java/com/leikwan/bridge/api app/src/main/java/com/leikwan/bridge/session/DeviceControlModels.kt app/src/test/java/com/leikwan/bridge/api
git commit -m "feat: enroll Android clients with agent tokens"
~~~

Expected: tests PASS before the commit.

### Task 6: Silently Drop Unsupported Modes and Add SOCKS5 Direct Routing

**Files:**
- Create: app/src/main/java/com/leikwan/bridge/runtime/ProtectedConnectivitySet.kt
- Modify: ForwardMode.kt, ForwardContract.kt, ForwardFilter.kt,
  AndroidEffectiveForwardBuilder.kt, RuntimeContext.kt, SingboxConfigBuilder.kt
- Modify: ProxyUriParser.kt, ProxyProtocolDraft.kt, ProxyNodeEditor.kt
- Test: AndroidEffectiveForwardBuilderTest.kt, ForwardFilterTest.kt,
  ProxyUriParserTest.kt, SingboxConfigBuilderTest.kt

**Interfaces:**
- AndroidEffectiveForwardBuilder returns null before permissive normalization for
  every unsupported raw family.
- ForwardCatalog has no rejection count/message/card.
- ProtectedConnectivitySet validates/merges host and CIDR direct paths.
- ProxyProtocolDraft.Socks5 compiles to sing-box socks version=5.

- [ ] **Step 1: Write RED mode, parser, and route-order tests.**

~~~kotlin
assertNotNull(build("current_mode", "single"))
assertNotNull(build("current_mode", "bond"))
assertNotNull(buildValidUltra())
listOf("cf_mode", "leigod_mode", "unknown").forEach { assertNull(build(it, "single")) }
listOf("race", "mptcp", "bogus").forEach { assertNull(build("current_mode", it)) }
val node = ProxyUriParser.parse("socks5://user:pass@[2001:db8::10]:1080#edge")!!
assertEquals("socks5", node.type)
assertRouteBeforeUserRule(config, "198.51.100.44/32", "direct")
assertRouteBeforeUserRule(config, "wg.example.test", "direct")
~~~

- [ ] **Step 2: Run RED.**

~~~powershell
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.runtime.AndroidEffectiveForwardBuilderTest" --tests "com.leikwan.bridge.runtime.ForwardFilterTest" --tests "com.leikwan.bridge.proxy.ProxyUriParserTest" --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"
~~~

Expected: FAIL because cf_mode passes and SOCKS5/CIDRs are absent.

- [ ] **Step 3: Implement strict acceptance.** Read backend_mode, client_mode,
  and raw mode before ForwardMode.normalize. Accept exact current_mode/single,
  current_mode/bond, or valid Ultra only. Remove dropped contracts from cached
  catalog/selection without exposing a reason.

- [ ] **Step 4: Implement SOCKS5/protected routes.** Parse socks:// and
  socks5:// with percent-decoded optional credentials and IPv6. Gather forward
  endpoint/CDN/SNI, active SS/VLESS/SOCKS5 hosts, and WireGuard peer endpoints.
  Never use WireGuard allowed_ips as an endpoint. Permit endpoint /32 or /128
  plus a non-default CIDR no broader than IPv4 /8 or IPv6 /32; place direct
  rules before all user/country/custom/final rules.

- [ ] **Step 5: Run GREEN and commit.**

~~~powershell
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.runtime.AndroidEffectiveForwardBuilderTest" --tests "com.leikwan.bridge.runtime.ForwardFilterTest" --tests "com.leikwan.bridge.proxy.ProxyUriParserTest" --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"
git add app/src/main/java/com/leikwan/bridge/runtime app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt app/src/main/java/com/leikwan/bridge/proxy/ProxyUriParser.kt app/src/main/java/com/leikwan/bridge/ui/proxies/ProxyProtocolDraft.kt app/src/main/java/com/leikwan/bridge/ui/proxies/ProxyNodeEditor.kt app/src/test/java/com/leikwan/bridge/runtime app/src/test/java/com/leikwan/bridge/proxy/ProxyUriParserTest.kt app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt
git commit -m "feat: add SOCKS5 and protected Android routes"
~~~

Expected: tests PASS before the commit.

### Task 7: Authorize Trusted Android Ultra and Integrate its Runtime

**Files:**
- Modify: master/src/Support/LeigodTokenBroker.php
- Modify: master/src/Support/UltraLeaseService.php
- Modify: master/src/Controllers/ClientAgentController.php
- Create: master/tests/android_ultra_policy_v2_contract_test.php
- Modify: master/tests/ultra_lease_endpoint_contract_test.php
- Create: scripts/build-ultra-android.ps1
- Create: UltraModels.kt, UltraForwardRunner.kt, UltraLeaseClient.kt
- Modify: RuntimeBinaryInstaller.kt, AndroidRuntimeJanitor.kt,
  RuntimeContext.kt, TunnelOrchestrator.kt
- Test: UltraModelsTest.kt, UltraForwardRunnerTest.kt, UltraLeaseClientTest.kt,
  TunnelOrchestratorTest.kt

**Interfaces:**
- Trusted Android receives policy-v2 ultra_access only.
- UltraLeaseService issues one lease plus validated narrow direct_ip_cidrs;
  ordinary pull has no endpoint/credential/CIDR material.
- UltraLeaseClient uses a max-64KiB 4-byte big-endian LocalSocket frame.
- UltraForwardRunner creates non-secret static JSON and a runtimeOnlyProxy plan.

- [ ] **Step 1: Write Panel and Android RED tests.**

~~~php
assert(($androidGrant['protocol'] ?? '') === UltraMode::POLICY_PROTOCOL);
assert(!array_key_exists('endpoint', $androidGrant));
assertSame(['198.51.100.44/32'], $lease['direct_ip_cidrs']);
assertSame('device_untrusted', $untrusted->errorCode());
~~~

~~~kotlin
assertNull(UltraAccessPolicy.parse(expiredPolicyJson, "target.example:443"))
assertFalse(plan.configJson.contains("provider-password"))
assertEquals(4, UltraLeaseClient.frame(validLease).copyOfRange(0, 4).size)
~~~

- [ ] **Step 2: Run RED.**

~~~powershell
php master/tests/android_ultra_policy_v2_contract_test.php
php master/tests/ultra_lease_endpoint_contract_test.php
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.runtime.UltraModelsTest" --tests "com.leikwan.bridge.runtime.UltraForwardRunnerTest" --tests "com.leikwan.bridge.runtime.UltraLeaseClientTest"
~~~

Expected: FAIL because Android Ultra contract/runtime types are absent.

- [ ] **Step 3: Gate Panel grants and leases.** Recognize only
  X-Device-Platform=android-host, require an active trusted context, bind grant
  and lease to its device ID, return endpoint /32 or /128 plus only
  provider-approved narrow CIDRs, and reject malformed/default/broad CIDRs
  before a candidate resolver executes.

- [ ] **Step 4: Build/package Android Ultra.** Set GOOS=android,
  GOARCH=arm64, CGO_ENABLED=0; verify ELF64 AArch64 and SHA-256 before copying
  the executable to runtime asset and jniLib source. Fail for x86 or host OS
  binaries and any secret-bearing input.

- [ ] **Step 5: Implement private runtime.** Add ULTRA process role,
  app-private config/socket/status paths, no lease fields in config JSON,
  listener readiness, bounded reply validation, byte-array zeroing in finally,
  and cleanup on every failure. Start it without a user proxy-node selection,
  inject one lease, and add lease CIDRs to ProtectedConnectivitySet before
  libbox config/reload.

- [ ] **Step 6: Run GREEN and commit.**

~~~powershell
php master/tests/android_ultra_policy_v2_contract_test.php
php master/tests/ultra_lease_endpoint_contract_test.php
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.runtime.UltraModelsTest" --tests "com.leikwan.bridge.runtime.UltraForwardRunnerTest" --tests "com.leikwan.bridge.runtime.UltraLeaseClientTest" --tests "com.leikwan.bridge.service.TunnelOrchestratorTest"
.\gradlew.bat :app:assembleDebug
git add ../master/src/Support/LeigodTokenBroker.php ../master/src/Support/UltraLeaseService.php ../master/src/Controllers/ClientAgentController.php ../master/tests/android_ultra_policy_v2_contract_test.php ../master/tests/ultra_lease_endpoint_contract_test.php ../scripts/build-ultra-android.ps1 app/src/main/assets/runtime/arm64-v8a/ultra-client app/src/main/jniLibs/arm64-v8a/libultra_client.so app/src/main/java/com/leikwan/bridge/runtime app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt app/src/test/java/com/leikwan/bridge/runtime app/src/test/java/com/leikwan/bridge/service/TunnelOrchestratorTest.kt
git commit -m "feat: run Ultra on trusted Android devices"
~~~

Expected: tests/build PASS before the commit.

### Task 8: Stop on Kick/Ban and Replace UI with Enrollment/Selectors

**Files:**
- Create: SessionRevocationCoordinator.kt, ClientControlLoop.kt,
  EnrollmentViewModel.kt, EnrollmentScreen.kt, BlockedDeviceScreen.kt
- Modify: LeikwanVpnService.kt, TunnelOrchestrator.kt, MainActivity.kt,
  LoginViewModel.kt, LoginScreen.kt, AddProxyDialog.kt, ProxyListSections.kt,
  TunnelsScreen.kt, AndroidManifest.xml, string resources
- Test: SessionRevocationCoordinatorTest.kt, ClientControlLoopTest.kt,
  EnrollmentScreenTest.kt, BlockedDeviceScreenTest.kt

**Interfaces:**
- SessionRevocationCoordinator.revoke(PanelControlException) is ordered and
  idempotent.
- ClientControlLoop owns at most one 30-second job while VPN is foreground.
- Startup renders a blocked screen before cached data on rejected hardware.
- Enrollment has numeric Client ID, masked Agent Token, one-time code, and no
  email, password, or editable Panel URL.

- [ ] **Step 1: Write RED lifecycle and Compose tests.**

~~~kotlin
coordinator.revoke(PanelControlException.kicked(version = 4))
assertEquals(listOf("vpn", "runtimes", "lease", "prefs", "ack"), events)
assertFalse(prefs.isLoggedIn())
assertEquals(1, loop.startCount)
composeRule.onNodeWithText("Client ID").assertExists()
composeRule.onNodeWithText("Email").assertDoesNotExist()
composeRule.onNodeWithText("Panel URL").assertDoesNotExist()
composeRule.onNodeWithText("Unsupported device").assertExists()
~~~

- [ ] **Step 2: Run RED.**

~~~powershell
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.session.SessionRevocationCoordinatorTest" --tests "com.leikwan.bridge.session.ClientControlLoopTest"
.\gradlew.bat :app:connectedDebugAndroidTest -Pandroid.testInstrumentationRunnerArguments.class=com.leikwan.bridge.ui.login.EnrollmentScreenTest
~~~

Expected: FAIL because the control lifecycle and screens are absent.

- [ ] **Step 3: Implement terminal lifecycle.** On terminal control error stop
  VPN, all ordinary/route-rule/Ultra processes, app-private lease/config/socket/
  status files, and encrypted enrollment atomically; best-effort ACK cannot
  reactivate. Start one control loop after VPN readiness, retry only transient
  network errors, and renew Ultra before refresh skew.

- [ ] **Step 4: Implement selector-first UI.** Gate Activity before navigation.
  Use fixed BuildConfig Panel URL and verified App Link code parsing only.
  Render no target/listen-port or unsupported mode detail. Use
  ExposedDropdownMenuBox, segmented controls, switches, and pickers for finite
  proxy type/cipher/security/transport/WireGuard choices and include SOCKS5.

- [ ] **Step 5: Run GREEN and commit.**

~~~powershell
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.session.SessionRevocationCoordinatorTest" --tests "com.leikwan.bridge.session.ClientControlLoopTest" --tests "com.leikwan.bridge.service.LeikwanVpnServiceTest"
.\gradlew.bat :app:connectedDebugAndroidTest -Pandroid.testInstrumentationRunnerArguments.class=com.leikwan.bridge.ui.login.EnrollmentScreenTest
.\gradlew.bat :app:connectedDebugAndroidTest -Pandroid.testInstrumentationRunnerArguments.class=com.leikwan.bridge.ui.security.BlockedDeviceScreenTest
git add app/src/main/java/com/leikwan/bridge/session app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt app/src/main/java/com/leikwan/bridge/ui app/src/main/AndroidManifest.xml app/src/main/res app/src/test/java/com/leikwan/bridge/session app/src/androidTest
git commit -m "feat: enforce Android device controls in the UI"
~~~

Expected: tests PASS on permitted hardware; emulator/root blocking is expected.

### Task 9: Verify Artifacts and Local Acceptance

**Files:**
- Create: scripts/verify-android-ultra-artifact.ps1
- Modify: app/src/test/java/com/leikwan/bridge/InfraSmokeTest.kt
- Modify: master/tests/android_client_enrollment_contract_test.php

**Interfaces:**
- The verifier scans APK entries without global extraction, redacts matches,
  rejects non-arm64 Ultra, raw agent-token URL, and lease-material keys in
  static assets.
- Smoke tests prove catalog state has no dropped-forward ID.

- [ ] **Step 1: Write the verifier RED expectation.**

~~~powershell
cd leikwan-android
.\gradlew.bat :app:assembleDebug
..\scripts\verify-android-ultra-artifact.ps1 -ApkPath (Get-ChildItem app/build/outputs/apk/debug/*.apk | Select-Object -First 1).FullName
~~~

Expected: FAIL because the verifier is absent.

- [ ] **Step 2: Implement bounded scans.** Inspect only known APK entries,
  redact match output, fail on agent_token= or provider/credential markers in
  static assets, and fail on a non-arm64 Ultra entry. Assert terminal Panel
  errors contain no forwards, endpoint, credential, or lease keys.

- [ ] **Step 3: Run complete local gate.**

~~~powershell
php master/tests/android_device_control_schema_test.php
php master/tests/trusted_client_ip_resolver_test.php
php master/tests/android_device_attestation_contract_test.php
php master/tests/android_device_control_contract_test.php
php master/tests/android_client_enrollment_contract_test.php
php master/tests/android_ultra_policy_v2_contract_test.php
php master/tests/ultra_lease_endpoint_contract_test.php
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest
.\gradlew.bat :app:assembleDebug
..\scripts\verify-android-ultra-artifact.ps1 -ApkPath (Get-ChildItem app/build/outputs/apk/debug/*.apk | Select-Object -First 1).FullName
~~~

Expected: PASS; record unrelated pre-existing failures without edits.

- [ ] **Step 4: Run physical acceptance.** On a non-rooted arm64 phone prove
  enrollment, Panel metadata/IP, single, bond, Ultra, SOCKS5, WireGuard direct
  route, kick, ban, unban, and reenrollment. On emulator/rooted/unlocked
  hardware prove blocking occurs before pull. Capture redacted evidence only.

- [ ] **Step 5: Commit.**

~~~powershell
git add ../scripts/verify-android-ultra-artifact.ps1 app/src/test/java/com/leikwan/bridge/InfraSmokeTest.kt ../master/tests/android_client_enrollment_contract_test.php
git commit -m "test: verify Android Host security boundaries"
~~~

## Plan Self-Review

- Token enrollment/password removal: Tasks 4, 5, and 8.
- Panel device identity/IP, kick, ban, and attestation: Tasks 1, 2, and 3.
- Silent unsupported-mode dropping: Task 6 and Task 9.
- SS/VLESS/WireGuard/SOCKS5 direct connectivity: Task 6.
- Policy-v2 Ultra and memory-only runtime: Task 7.
- Selector-first UI/safe links/blocked devices: Task 8.
- Build, leak scan, and non-production acceptance: Task 9.

The shared types are introduced before use: DeviceControlService,
AttestationVerificationResult, PanelControlException,
ProtectedConnectivitySet, UltraAccessPolicy, UltraLeaseClient,
UltraForwardRunner, and RuntimeKind.ULTRA. There are no unnamed interfaces or
placeholder steps. The user selected Inline Execution; execute in this
worktree only after committing this plan and the spec correction.
