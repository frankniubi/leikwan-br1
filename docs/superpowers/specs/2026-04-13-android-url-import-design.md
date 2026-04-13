# Android URL Import & QR Enhancement Design

**Date:** 2026-04-13
**Status:** Approved

## Problem

The Android app cannot recognize externally-provided URLs. Specifically:

1. **Share intent** — users cannot share a link from browser/messaging apps into LeikwanBridge
2. **Subscription URLs** — `https://` subscription links (e.g. `https://ga2-panel.mcbar.cc/s/...`) are not recognized anywhere
3. **QR scanner** — only accepts `ss://`/`vmess://`/`vless://` schemes; ignores `https://` subscription URLs
4. **Gallery QR** — no way to import a QR code from a saved screenshot/photo
5. **Clipboard** — no detection of proxy URIs or subscription URLs on the clipboard
6. **SS 2022 parsing** — the `2022-blake3-aes-256-gcm` cipher with multi-part keys breaks the current parser

### Reference inputs

- Subscription URL: `https://ga2-panel.mcbar.cc/s/ea52de2922343898a7d2e266facd52af`
- SS 2022 URI: `ss://2022-blake3-aes-256-gcm:NDE3MzQ0OTdjZDc4ZDAzMDJiNTJiNzdkMjRiYTI0NTc=:MzBkNGQ1OWMtNWY5MS00OTc5LTg5OTMtN2VmMGE0ZTY=@47.100.39.54:35812#HKG-HKG2`

## Design

### 1. ImportRouter — Unified classification

A new standalone object `ImportRouter` sits between all entry points and the existing parse/subscribe logic. Every import path feeds text into `ImportRouter.process()`.

**File:** `proxy/ImportRouter.kt`

```kotlin
object ImportRouter {
    sealed class ImportResult {
        data class ProxyNodes(val nodes: List<ProxyNode>) : ImportResult()
        data class SubscriptionUrl(val url: String) : ImportResult()
        data class Mixed(val nodes: List<ProxyNode>, val subscriptionUrls: List<String>) : ImportResult()
        data class Empty(val rawInput: String) : ImportResult()
    }

    fun process(text: String): ImportResult
}
```

**Classification logic:**

1. Trim input, split by newlines
2. For each line:
   - Starts with `ss://`, `vmess://`, `vless://` → proxy URI, parse via `ProxyUriParser.parse()`
   - Starts with `http://` or `https://` → subscription URL
   - Otherwise → try base64-decoding the entire text, re-classify decoded output (handles subscription response bodies)
3. Aggregate results:
   - Only proxy nodes → `ProxyNodes`
   - Only URLs → `SubscriptionUrl` (first URL; multiple subscription URLs in a single paste is unusual)
   - Both → `Mixed`
   - Nothing parsed → `Empty`

**Subscription URL handling:** When `ImportRouter` returns a `SubscriptionUrl`, the caller adds it to the `subscriptions` table with `autoRefresh = true`, `refreshIntervalMin = 1440` (24h default), and the URL's path as the default name. Then triggers an immediate fetch.

### 2. SS 2022 Parser Fix

**Problem:** The current `parseShadowsocks()` base64-decodes the entire userinfo before `@`. For SS 2022 URIs, the userinfo is `method:key1:key2` in plaintext — base64 decode fails or produces garbage.

**SS 2022 URI format (SIP002 variant):**
```
ss://method:server_key[:identity_key]@host:port#name
```

Where `method` is `2022-blake3-aes-256-gcm` (or `2022-blake3-aes-128-gcm`, `2022-blake3-chacha20-poly1305`), `server_key` and `identity_key` are base64-encoded.

**Fix in `parseShadowsocks()`:**

For the SIP002 branch (`mainPart.contains("@")`):

```
val userInfo = mainPart.substringBefore("@")
val hostPort = mainPart.substringAfter("@")

// Try base64 decode first (standard SIP002)
val decoded = tryBase64Decode(userInfo)
if (decoded != null && decoded.contains(":") && !decoded.startsWith("2022-")) {
    // Legacy SIP002: base64(method:password)
    method = decoded.substringBefore(":")
    password = decoded.substringAfter(":")
} else {
    // SS 2022 or plaintext: method:key[:identity_key]
    val plaintext = URLDecoder.decode(userInfo, "UTF-8")
    val firstColon = plaintext.indexOf(":")
    method = plaintext.substring(0, firstColon)
    password = plaintext.substring(firstColon + 1)  // "key1:key2" or just "key1"
}
```

The `password` field for 2022 ciphers contains the full `server_key:identity_key` compound — this is the correct format that sing-box expects in the `password` config field.

**`tryBase64Decode()` helper:** Attempts base64 decode with `URL_SAFE | NO_PADDING | NO_WRAP`. Returns `null` on any exception or if the result contains non-printable characters.

### 3. Share Intent

**AndroidManifest.xml** — Add to `MainActivity`'s existing `<activity>` block:

```xml
<intent-filter>
    <action android:name="android.intent.action.SEND" />
    <category android:name="android.intent.category.DEFAULT" />
    <data android:mimeType="text/plain" />
</intent-filter>
```

**MainActivity.kt** changes:

- `onCreate()`: After setting up Compose, check if `intent.action == Intent.ACTION_SEND` and extract `intent.getStringExtra(Intent.EXTRA_TEXT)`.
- `onNewIntent()`: Same check (handles `singleTop` re-launch).
- Pass the extracted text to the import flow via a shared state holder or navigation argument.

**UX flow:** When a user shares text into the app:
1. App opens (or comes to foreground)
2. Text is routed through `ImportRouter.process()`
3. If `ProxyNodes` → add all nodes, show snackbar "Added N proxy nodes"
4. If `SubscriptionUrl` → add subscription, trigger fetch, show snackbar "Subscription added"
5. If `Mixed` → add both, show snackbar summarizing
6. If `Empty` → show snackbar "No proxy links found in shared text"

### 4. Clipboard Detection

**Location:** `MainActivity.kt` — `onResume()` lifecycle callback.

**Logic:**
1. Read primary clip from `ClipboardManager`
2. Extract text content
3. Quick-check: does it match any proxy URI scheme or look like an `https://` URL?
4. Compare against `lastProcessedClipHash` (stored in-memory) to avoid re-prompting for the same content
5. If new matching content found: update `lastProcessedClipHash` and emit it to a `SharedFlow`

**UI:** A Compose `Snackbar` at the scaffold level:
- Text: "Proxy link detected in clipboard"
- Action: "Import" button
- On action: route text through `ImportRouter`, show result snackbar
- On dismiss: do nothing, won't re-prompt until clipboard changes

**State tracking:** Use an in-memory hash (SHA-256 of clipboard text) to prevent re-prompting. Resets on app kill — acceptable because the clipboard likely changed too.

### 5. Gallery QR Import

**Location:** `QrScanScreen.kt` — add a "Gallery" button alongside the camera view.

**Implementation:**
1. Add `PickVisualMedia` launcher (`ActivityResultContracts.PickVisualMedia()`) for selecting an image
2. On image selected: create `InputImage.fromFilePath(context, uri)` (or `fromBitmap`)
3. Process with the same `BarcodeScanning.getClient().process(inputImage)` already used for camera frames
4. Route decoded text through `ImportRouter`

**UI placement:** A small icon button (gallery/photo icon) in the top-right corner of the QR scan screen, or as an alternative action in the bottom bar. Consistent with how most proxy apps handle this.

### 6. QR Scanner Subscription URL Support

**Current:** `QrScanScreen.kt` line checks only `ss://`, `vmess://`, `vless://` prefixes.

**Change:** Accept any text that `ImportRouter` can classify as non-empty:

```kotlin
val result = ImportRouter.process(value)
if (!scanned && result !is ImportRouter.ImportResult.Empty) {
    scanned = true
    // Handle result
}
```

This naturally supports subscription URLs, proxy URIs, and even base64-encoded subscription bodies in QR codes.

## Files Changed

| File | Change |
|------|--------|
| `proxy/ImportRouter.kt` | **New** — unified import classification |
| `proxy/ProxyUriParser.kt` | Fix SS 2022 parsing in `parseShadowsocks()` |
| `AndroidManifest.xml` | Add `ACTION_SEND` intent filter to MainActivity |
| `ui/MainActivity.kt` | Handle share intent in `onCreate()`/`onNewIntent()`, clipboard detection in `onResume()` |
| `ui/proxies/ProxiesViewModel.kt` | Add `importAny(text)` method that delegates to `ImportRouter` and handles results |
| `ui/proxies/QrScanScreen.kt` | Add gallery picker button, use `ImportRouter` for result classification, accept subscription URLs |
| `ui/proxies/ProxiesScreen.kt` | Possibly add snackbar host for import feedback if not already present |

## Out of Scope

- Deep links (URI scheme `ss://` etc. opening the app directly from browser) — user elected to skip
- `ssr://`, `trojan://`, `hysteria://` protocol support
- SIP003 plugin parameters
- Auto-start on boot
