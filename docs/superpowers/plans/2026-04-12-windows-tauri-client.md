# Windows Tauri Client Implementation Plan (Part 5)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a full-featured Windows desktop client using Tauri (Rust + Web UI) that provides GUI forward management, system tray, auto-start, WireGuard tunnel management, and bond-based multi-path aggregation.

**Architecture:** Tauri 2.x app with Rust backend (shares Cargo workspace with leikwan-bond) and HTML/CSS/JS frontend. Backend handles: Panel API communication, heartbeat, config pull, leikwan-bond child process management, WireGuard (wintun) tunnel management. Frontend renders: login, forward list, live stats, settings. Bond mode only (no MPTCP on Windows).

**Tech Stack:** Rust (Tauri 2.x backend), HTML/CSS/JS (frontend), wintun crate (WireGuard), leikwan-bond (child process), tauri-plugin-autostart, system-tray API

**Depends on:** Plan A (bond parameter optimization) for optimized bond defaults.

---

### Task 1: Scaffold the Tauri app in the Cargo workspace

**Files:**
- Create: `wstunnel-main/leikwan-client-win/src-tauri/Cargo.toml`
- Create: `wstunnel-main/leikwan-client-win/src-tauri/src/main.rs`
- Create: `wstunnel-main/leikwan-client-win/src-tauri/tauri.conf.json`
- Create: `wstunnel-main/leikwan-client-win/src/index.html`
- Modify: `wstunnel-main/Cargo.toml` (workspace members)

- [ ] **Step 1: Install Tauri CLI**

```bash
cargo install create-tauri-app
cargo install tauri-cli
```

- [ ] **Step 2: Create Tauri app structure**

```bash
cd wstunnel-main
mkdir -p leikwan-client-win/src-tauri/src
mkdir -p leikwan-client-win/src
```

- [ ] **Step 3: Create Cargo.toml for Tauri backend**

```toml
[package]
name = "leikwan-client-win"
version = "0.1.0"
edition = "2021"

[dependencies]
tauri = { version = "2", features = ["tray-icon", "protocol-asset"] }
tauri-plugin-autostart = "2"
serde = { version = "1", features = ["derive"] }
serde_json = "1"
reqwest = { version = "0.12", features = ["json", "rustls-tls"], default-features = false }
tokio = { version = "1", features = ["full"] }
log = "0.4"
env_logger = "0.11"
anyhow = "1"
keyring = "3"
wintun = "0.5"

[build-dependencies]
tauri-build = { version = "2", features = [] }
```

- [ ] **Step 4: Create tauri.conf.json**

```json
{
  "$schema": "https://raw.githubusercontent.com/niceit/tauri-v2/main/crates/tauri-cli/schema.json",
  "productName": "LeiKwan Bridge",
  "identifier": "com.leikwan.bridge",
  "version": "0.1.0",
  "build": {
    "frontendDist": "../src"
  },
  "app": {
    "withGlobalTauri": true,
    "windows": [
      {
        "title": "LeiKwan Bridge",
        "width": 900,
        "height": 640,
        "resizable": true,
        "visible": true
      }
    ],
    "trayIcon": {
      "iconPath": "icons/icon.png",
      "iconAsTemplate": true
    }
  },
  "bundle": {
    "active": true,
    "targets": ["msi", "nsis"],
    "icon": [
      "icons/32x32.png",
      "icons/128x128.png",
      "icons/icon.ico"
    ]
  }
}
```

- [ ] **Step 5: Create minimal main.rs**

```rust
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_autostart::init(
            tauri_plugin_autostart::MacosLauncher::LaunchAgent,
            None,
        ))
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
```

- [ ] **Step 6: Create build.rs**

```rust
fn main() {
    tauri_build::build()
}
```

Create at `wstunnel-main/leikwan-client-win/src-tauri/build.rs`.

- [ ] **Step 7: Create minimal index.html**

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LeiKwan Bridge</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div id="app">
        <div id="login-view">
            <h1>LeiKwan Bridge</h1>
            <p>Loading...</p>
        </div>
    </div>
    <script src="app.js"></script>
</body>
</html>
```

Create `styles.css` and `app.js` as empty files in `leikwan-client-win/src/`.

- [ ] **Step 8: Add to workspace**

Add `"leikwan-client-win/src-tauri"` to the workspace members in `wstunnel-main/Cargo.toml`.

- [ ] **Step 9: Build to verify**

```bash
cd wstunnel-main/leikwan-client-win && cargo tauri build --debug
```

Note: This only works on Windows. For cross-compilation, verify `cargo build -p leikwan-client-win` compiles the Rust backend.

- [ ] **Step 10: Commit**

```bash
git add wstunnel-main/leikwan-client-win/ wstunnel-main/Cargo.toml
git commit -m "feat: scaffold Windows Tauri client app in workspace"
```

---

### Task 2: Implement Panel API client

**Files:**
- Create: `wstunnel-main/leikwan-client-win/src-tauri/src/api.rs`

- [ ] **Step 1: Create api.rs**

```rust
use reqwest::Client;
use serde::{Deserialize, Serialize};

#[derive(Clone)]
pub struct PanelApi {
    client: Client,
    base_url: String,
    token: Option<String>,
}

#[derive(Serialize)]
struct LoginRequest {
    email: String,
    password: String,
}

#[derive(Deserialize)]
pub struct LoginResponse {
    pub token: String,
    pub user_id: u64,
    pub username: String,
}

#[derive(Deserialize, Serialize, Clone, Debug)]
pub struct ForwardInfo {
    pub id: u64,
    pub name: String,
    pub target_host: String,
    pub target_port: u16,
    pub listen_port: u16,
    pub client_mode: String,
    pub transport_mode: String,
    pub service_status: String,
    pub enabled: bool,
}

#[derive(Deserialize, Debug)]
pub struct ForwardConfig {
    pub mode: String,
    pub transport: String,
    pub endpoints: Vec<serde_json::Value>,
    pub bonding: Option<serde_json::Value>,
    pub mptcp: Option<serde_json::Value>,
    pub connection_policy: serde_json::Value,
}

#[derive(Deserialize)]
pub struct HeartbeatResponse {
    pub config_version: u64,
    pub forwards: Vec<ForwardInfo>,
}

impl PanelApi {
    pub fn new(base_url: &str) -> Self {
        Self {
            client: Client::builder()
                .timeout(std::time::Duration::from_secs(10))
                .build()
                .expect("HTTP client"),
            base_url: base_url.trim_end_matches('/').to_string(),
            token: None,
        }
    }

    pub fn set_token(&mut self, token: String) {
        self.token = Some(token);
    }

    pub fn is_authenticated(&self) -> bool {
        self.token.is_some()
    }

    pub async fn login(&mut self, email: &str, password: &str) -> anyhow::Result<LoginResponse> {
        let resp = self.client
            .post(format!("{}/api/client/login", self.base_url))
            .json(&LoginRequest { email: email.into(), password: password.into() })
            .send()
            .await?
            .error_for_status()?
            .json::<LoginResponse>()
            .await?;
        self.token = Some(resp.token.clone());
        Ok(resp)
    }

    pub async fn heartbeat(&self) -> anyhow::Result<HeartbeatResponse> {
        let token = self.token.as_deref().ok_or_else(|| anyhow::anyhow!("not authenticated"))?;
        let resp = self.client
            .get(format!("{}/api/client/heartbeat", self.base_url))
            .bearer_auth(token)
            .send()
            .await?
            .error_for_status()?
            .json::<HeartbeatResponse>()
            .await?;
        Ok(resp)
    }

    pub async fn get_forward_config(&self, forward_id: u64) -> anyhow::Result<ForwardConfig> {
        let token = self.token.as_deref().ok_or_else(|| anyhow::anyhow!("not authenticated"))?;
        let resp = self.client
            .get(format!("{}/api/client/config/{forward_id}", self.base_url))
            .bearer_auth(token)
            .send()
            .await?
            .error_for_status()?
            .json::<ForwardConfig>()
            .await?;
        Ok(resp)
    }
}
```

Note: These API endpoints (`/api/client/login`, `/api/client/heartbeat`, `/api/client/config/{id}`) may not exist in the Panel yet. They need to be added as part of this plan or a follow-up. For now, define the client-side contract. See Task 8 for Panel API additions.

- [ ] **Step 2: Wire into main.rs**

Add `mod api;` to main.rs.

- [ ] **Step 3: Commit**

```bash
git add wstunnel-main/leikwan-client-win/src-tauri/src/
git commit -m "feat: implement Panel API client for Windows app (login, heartbeat, config)"
```

---

### Task 3: Implement Tauri commands for frontend-backend communication

**Files:**
- Create: `wstunnel-main/leikwan-client-win/src-tauri/src/commands.rs`
- Modify: `wstunnel-main/leikwan-client-win/src-tauri/src/main.rs`

- [ ] **Step 1: Create commands.rs with Tauri invoke handlers**

```rust
use crate::api::{ForwardInfo, PanelApi};
use serde::{Deserialize, Serialize};
use std::sync::Mutex;
use tauri::State;

pub struct AppState {
    pub api: Mutex<PanelApi>,
    pub forwards: Mutex<Vec<ForwardInfo>>,
    pub connected_forwards: Mutex<Vec<u64>>,
}

#[derive(Serialize)]
pub struct LoginResult {
    pub success: bool,
    pub username: String,
    pub error: Option<String>,
}

#[tauri::command]
pub async fn login(
    state: State<'_, AppState>,
    panel_url: String,
    email: String,
    password: String,
) -> Result<LoginResult, String> {
    let mut api = state.api.lock().map_err(|e| e.to_string())?;
    *api = PanelApi::new(&panel_url);
    match api.login(&email, &password).await {
        Ok(resp) => {
            // Store token securely
            if let Ok(entry) = keyring::Entry::new("leikwan-bridge", &email) {
                let _ = entry.set_password(&resp.token);
            }
            Ok(LoginResult { success: true, username: resp.username, error: None })
        }
        Err(e) => Ok(LoginResult { success: false, username: String::new(), error: Some(e.to_string()) }),
    }
}

#[tauri::command]
pub async fn get_forwards(state: State<'_, AppState>) -> Result<Vec<ForwardInfo>, String> {
    let api = state.api.lock().map_err(|e| e.to_string())?;
    if !api.is_authenticated() {
        return Err("not authenticated".into());
    }
    match api.heartbeat().await {
        Ok(resp) => {
            let mut forwards = state.forwards.lock().map_err(|e| e.to_string())?;
            *forwards = resp.forwards.clone();
            Ok(resp.forwards)
        }
        Err(e) => Err(e.to_string()),
    }
}

#[derive(Serialize)]
pub struct ForwardStatus {
    pub forward_id: u64,
    pub connected: bool,
    pub bytes_in: u64,
    pub bytes_out: u64,
    pub legs_active: u32,
    pub latency_ms: u32,
}

#[tauri::command]
pub async fn connect_forward(
    state: State<'_, AppState>,
    forward_id: u64,
) -> Result<ForwardStatus, String> {
    // 1. Pull config from Panel
    // 2. Set up WireGuard tunnels
    // 3. Launch leikwan-bond child process
    // 4. Return status
    let mut connected = state.connected_forwards.lock().map_err(|e| e.to_string())?;
    connected.push(forward_id);
    Ok(ForwardStatus {
        forward_id,
        connected: true,
        bytes_in: 0,
        bytes_out: 0,
        legs_active: 0,
        latency_ms: 0,
    })
}

#[tauri::command]
pub async fn disconnect_forward(
    state: State<'_, AppState>,
    forward_id: u64,
) -> Result<(), String> {
    // 1. Kill leikwan-bond child process
    // 2. Tear down WireGuard tunnels
    let mut connected = state.connected_forwards.lock().map_err(|e| e.to_string())?;
    connected.retain(|&id| id != forward_id);
    Ok(())
}
```

- [ ] **Step 2: Register commands in main.rs**

```rust
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod api;
mod commands;

use commands::AppState;
use std::sync::Mutex;

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_autostart::init(
            tauri_plugin_autostart::MacosLauncher::LaunchAgent,
            None,
        ))
        .manage(AppState {
            api: Mutex::new(api::PanelApi::new("")),
            forwards: Mutex::new(vec![]),
            connected_forwards: Mutex::new(vec![]),
        })
        .invoke_handler(tauri::generate_handler![
            commands::login,
            commands::get_forwards,
            commands::connect_forward,
            commands::disconnect_forward,
        ])
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
```

- [ ] **Step 3: Build**

```bash
cd wstunnel-main && cargo build -p leikwan-client-win
```

- [ ] **Step 4: Commit**

```bash
git add wstunnel-main/leikwan-client-win/src-tauri/src/
git commit -m "feat: implement Tauri commands for login, forward management, connect/disconnect"
```

---

### Task 4: Build the Web UI frontend

**Files:**
- Modify: `wstunnel-main/leikwan-client-win/src/index.html`
- Create: `wstunnel-main/leikwan-client-win/src/styles.css`
- Create: `wstunnel-main/leikwan-client-win/src/app.js`

- [ ] **Step 1: Create index.html with all views**

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LeiKwan Bridge</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div id="app">
        <!-- Login View -->
        <div id="login-view" class="view active">
            <div class="login-card">
                <h1>LeiKwan Bridge</h1>
                <form id="login-form">
                    <input type="url" id="panel-url" placeholder="Panel URL" value="https://v3.leikwanhost.com" required>
                    <input type="email" id="email" placeholder="Email" required>
                    <input type="password" id="password" placeholder="Password" required>
                    <button type="submit" id="login-btn">Login</button>
                    <p id="login-error" class="error"></p>
                </form>
            </div>
        </div>

        <!-- Main View -->
        <div id="main-view" class="view">
            <header>
                <h2>Forwards</h2>
                <span id="username"></span>
                <button id="refresh-btn">Refresh</button>
            </header>
            <div id="forward-list"></div>
        </div>
    </div>
    <script src="app.js"></script>
</body>
</html>
```

- [ ] **Step 2: Create styles.css**

```css
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #1a1a2e; color: #e0e0e0; }
.view { display: none; } .view.active { display: flex; }

/* Login */
#login-view { height: 100vh; align-items: center; justify-content: center; }
.login-card { background: #16213e; padding: 2rem; border-radius: 8px; width: 360px; }
.login-card h1 { text-align: center; margin-bottom: 1.5rem; color: #e94560; }
.login-card input { width: 100%; padding: 0.75rem; margin-bottom: 0.75rem; border: 1px solid #333; border-radius: 4px; background: #0f3460; color: #fff; }
.login-card button { width: 100%; padding: 0.75rem; background: #e94560; border: none; border-radius: 4px; color: #fff; cursor: pointer; font-size: 1rem; }
.login-card button:hover { background: #c73c54; }
.error { color: #e94560; text-align: center; margin-top: 0.5rem; }

/* Main */
#main-view { flex-direction: column; height: 100vh; padding: 1rem; }
header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
header h2 { flex: 1; }
#refresh-btn { padding: 0.5rem 1rem; background: #0f3460; border: 1px solid #333; color: #fff; border-radius: 4px; cursor: pointer; }

/* Forward cards */
.forward-card { background: #16213e; border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 1rem; }
.forward-info { flex: 1; }
.forward-name { font-weight: bold; font-size: 1.1rem; }
.forward-detail { color: #888; font-size: 0.85rem; margin-top: 0.25rem; }
.forward-stats { text-align: right; font-size: 0.85rem; color: #888; }
.connect-btn { padding: 0.5rem 1.25rem; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9rem; }
.connect-btn.off { background: #0f3460; color: #fff; }
.connect-btn.on { background: #e94560; color: #fff; }
.status-dot { width: 10px; height: 10px; border-radius: 50%; }
.status-dot.connected { background: #4caf50; }
.status-dot.disconnected { background: #666; }
```

- [ ] **Step 3: Create app.js**

```javascript
const { invoke } = window.__TAURI__.core;

let currentForwards = [];

// Login
document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const panelUrl = document.getElementById('panel-url').value;
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    const errorEl = document.getElementById('login-error');
    errorEl.textContent = '';

    try {
        const result = await invoke('login', { panelUrl, email, password });
        if (result.success) {
            document.getElementById('username').textContent = result.username;
            showView('main-view');
            loadForwards();
        } else {
            errorEl.textContent = result.error || 'Login failed';
        }
    } catch (err) {
        errorEl.textContent = String(err);
    }
});

// Refresh
document.getElementById('refresh-btn').addEventListener('click', loadForwards);

async function loadForwards() {
    try {
        currentForwards = await invoke('get_forwards');
        renderForwards();
    } catch (err) {
        console.error('Failed to load forwards:', err);
    }
}

function renderForwards() {
    const container = document.getElementById('forward-list');
    container.innerHTML = currentForwards.map(f => `
        <div class="forward-card" data-id="${f.id}">
            <div class="status-dot disconnected" id="dot-${f.id}"></div>
            <div class="forward-info">
                <div class="forward-name">${f.name || 'Forward #' + f.id}</div>
                <div class="forward-detail">${f.target_host}:${f.target_port} | ${f.client_mode} | ${f.transport_mode}</div>
            </div>
            <div class="forward-stats" id="stats-${f.id}">
                <div>-- Mbps</div>
            </div>
            <button class="connect-btn off" onclick="toggleForward(${f.id})" id="btn-${f.id}">Connect</button>
        </div>
    `).join('');
}

async function toggleForward(id) {
    const btn = document.getElementById('btn-' + id);
    const dot = document.getElementById('dot-' + id);
    if (btn.classList.contains('off')) {
        try {
            await invoke('connect_forward', { forwardId: id });
            btn.textContent = 'Disconnect';
            btn.classList.remove('off');
            btn.classList.add('on');
            dot.classList.remove('disconnected');
            dot.classList.add('connected');
        } catch (err) {
            console.error('Connect failed:', err);
        }
    } else {
        try {
            await invoke('disconnect_forward', { forwardId: id });
            btn.textContent = 'Connect';
            btn.classList.remove('on');
            btn.classList.add('off');
            dot.classList.remove('connected');
            dot.classList.add('disconnected');
        } catch (err) {
            console.error('Disconnect failed:', err);
        }
    }
}

function showView(viewId) {
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    document.getElementById(viewId).classList.add('active');
}
```

- [ ] **Step 4: Build and test**

```bash
cd wstunnel-main/leikwan-client-win && cargo tauri dev
```

This opens the app window. Verify login form renders.

- [ ] **Step 5: Commit**

```bash
git add wstunnel-main/leikwan-client-win/src/
git commit -m "feat: implement Web UI with login, forward list, connect/disconnect"
```

---

### Task 5: Implement bond child process management

**Files:**
- Create: `wstunnel-main/leikwan-client-win/src-tauri/src/bond.rs`
- Modify: `wstunnel-main/leikwan-client-win/src-tauri/src/commands.rs`

- [ ] **Step 1: Create bond.rs**

```rust
use std::collections::HashMap;
use std::process::{Child, Command};
use std::sync::Mutex;

pub struct BondManager {
    processes: Mutex<HashMap<u64, Child>>,
}

impl BondManager {
    pub fn new() -> Self {
        Self { processes: Mutex::new(HashMap::new()) }
    }

    pub fn start_bond(
        &self,
        forward_id: u64,
        protocol: &str,
        listen: &str,
        legs: &[String],
        session_token: &str,
        bond_params: &BondParams,
    ) -> anyhow::Result<()> {
        let legs_str = legs.join(",");
        let mut cmd = Command::new("leikwan-bond");
        cmd.arg("client")
            .arg("--protocol").arg(protocol)
            .arg("--listen").arg(listen)
            .arg("--legs").arg(&legs_str)
            .arg("--session-token").arg(session_token)
            .arg("--chunk-size").arg(bond_params.chunk_size.to_string())
            .arg("--max-window-bytes").arg(bond_params.max_window_bytes.to_string())
            .arg("--retransmit-ms").arg(bond_params.retransmit_ms.to_string())
            .arg("--connect-timeout-ms").arg(bond_params.connect_timeout_ms.to_string())
            .arg("--interactive-chunk-bytes").arg(bond_params.interactive_chunk_bytes.to_string())
            .arg("--latency-guard-bytes").arg(bond_params.latency_guard_bytes.to_string())
            .arg("--interactive-rtt-slack-ms").arg(bond_params.interactive_rtt_slack_ms.to_string());

        if bond_params.active_leg_limit > 0 {
            cmd.arg("--active-leg-limit").arg(bond_params.active_leg_limit.to_string());
        }

        let child = cmd.spawn()?;
        let mut procs = self.processes.lock().map_err(|e| anyhow::anyhow!("{e}"))?;
        procs.insert(forward_id, child);
        log::info!("bond started for forward {forward_id}");
        Ok(())
    }

    pub fn stop_bond(&self, forward_id: u64) -> anyhow::Result<()> {
        let mut procs = self.processes.lock().map_err(|e| anyhow::anyhow!("{e}"))?;
        if let Some(mut child) = procs.remove(&forward_id) {
            let _ = child.kill();
            let _ = child.wait();
            log::info!("bond stopped for forward {forward_id}");
        }
        Ok(())
    }

    pub fn stop_all(&self) {
        if let Ok(mut procs) = self.processes.lock() {
            for (id, mut child) in procs.drain() {
                let _ = child.kill();
                let _ = child.wait();
                log::info!("bond stopped for forward {id}");
            }
        }
    }

    pub fn is_running(&self, forward_id: u64) -> bool {
        self.processes.lock().map(|p| p.contains_key(&forward_id)).unwrap_or(false)
    }
}

pub struct BondParams {
    pub chunk_size: u64,
    pub max_window_bytes: u64,
    pub retransmit_ms: u64,
    pub connect_timeout_ms: u64,
    pub active_leg_limit: u64,
    pub interactive_chunk_bytes: u64,
    pub latency_guard_bytes: u64,
    pub interactive_rtt_slack_ms: u64,
}

impl Default for BondParams {
    fn default() -> Self {
        Self {
            chunk_size: 131072,
            max_window_bytes: 16777216,
            retransmit_ms: 300,
            connect_timeout_ms: 3000,
            active_leg_limit: 0,
            interactive_chunk_bytes: 16384,
            latency_guard_bytes: 262144,
            interactive_rtt_slack_ms: 30,
        }
    }
}
```

- [ ] **Step 2: Integrate BondManager into AppState**

In `commands.rs`, add `pub bond: bond::BondManager` to `AppState`. Initialize in main.rs with `bond::BondManager::new()`.

Update `connect_forward` to:
1. Pull forward config via Panel API
2. Extract bond leg info and session token
3. Call `state.bond.start_bond(...)`

Update `disconnect_forward` to call `state.bond.stop_bond(forward_id)`.

- [ ] **Step 3: Build and commit**

```bash
cd wstunnel-main && cargo build -p leikwan-client-win
git add wstunnel-main/leikwan-client-win/src-tauri/src/
git commit -m "feat: implement bond child process manager for Windows client"
```

---

### Task 6: Implement system tray

**Files:**
- Modify: `wstunnel-main/leikwan-client-win/src-tauri/src/main.rs`

- [ ] **Step 1: Add tray icon and menu**

Update main.rs to set up the system tray:

```rust
use tauri::{
    menu::{Menu, MenuItem},
    tray::TrayIconBuilder,
    Manager,
};

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_autostart::init(
            tauri_plugin_autostart::MacosLauncher::LaunchAgent,
            None,
        ))
        .manage(AppState { /* ... */ })
        .invoke_handler(tauri::generate_handler![
            commands::login,
            commands::get_forwards,
            commands::connect_forward,
            commands::disconnect_forward,
        ])
        .setup(|app| {
            let show = MenuItem::with_id(app, "show", "Show Window", true, None::<&str>)?;
            let quit = MenuItem::with_id(app, "quit", "Quit", true, None::<&str>)?;
            let menu = Menu::with_items(app, &[&show, &quit])?;
            let _tray = TrayIconBuilder::new()
                .menu(&menu)
                .tooltip("LeiKwan Bridge")
                .on_menu_event(|app, event| {
                    match event.id.as_ref() {
                        "show" => {
                            if let Some(window) = app.get_webview_window("main") {
                                let _ = window.show();
                                let _ = window.set_focus();
                            }
                        }
                        "quit" => {
                            app.exit(0);
                        }
                        _ => {}
                    }
                })
                .build(app)?;
            Ok(())
        })
        .on_window_event(|window, event| {
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                // Hide to tray instead of closing
                let _ = window.hide();
                api.prevent_close();
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
```

- [ ] **Step 2: Build and test tray behavior**

Build and verify: clicking X hides to tray, tray menu shows "Show Window" and "Quit".

- [ ] **Step 3: Commit**

```bash
git add wstunnel-main/leikwan-client-win/src-tauri/src/main.rs
git commit -m "feat: add system tray with hide-to-tray on close"
```

---

### Task 7: Implement WireGuard tunnel management

**Files:**
- Create: `wstunnel-main/leikwan-client-win/src-tauri/src/wireguard.rs`

- [ ] **Step 1: Create wireguard.rs**

This manages WireGuard tunnels on Windows using the wintun driver.

```rust
use std::collections::HashMap;
use std::process::Command;

pub struct WgManager {
    interfaces: HashMap<String, WgInterface>,
}

struct WgInterface {
    name: String,
    config_path: String,
}

impl WgManager {
    pub fn new() -> Self {
        Self { interfaces: HashMap::new() }
    }

    /// Set up a WireGuard interface using wireguard.exe (Windows service)
    pub fn create_tunnel(
        &mut self,
        name: &str,
        private_key: &str,
        address: &str,
        peers: &[WgPeer],
        mtu: u32,
    ) -> anyhow::Result<()> {
        // Write WG config to %APPDATA%\leikwan\wireguard\{name}.conf
        let config_dir = dirs::config_dir()
            .ok_or_else(|| anyhow::anyhow!("no config dir"))?
            .join("leikwan")
            .join("wireguard");
        std::fs::create_dir_all(&config_dir)?;

        let config_path = config_dir.join(format!("{name}.conf"));
        let mut config = format!(
            "[Interface]\nPrivateKey = {private_key}\nAddress = {address}/32\nMTU = {mtu}\n\n"
        );
        for peer in peers {
            config.push_str(&format!(
                "[Peer]\nPublicKey = {}\nEndpoint = {}:{}\nAllowedIPs = {}\n",
                peer.public_key, peer.endpoint_host, peer.endpoint_port,
                peer.allowed_ips.join(", ")
            ));
            if peer.persistent_keepalive > 0 {
                config.push_str(&format!("PersistentKeepalive = {}\n", peer.persistent_keepalive));
            }
            config.push('\n');
        }
        std::fs::write(&config_path, &config)?;

        // Install and start tunnel via wireguard.exe
        let config_str = config_path.to_string_lossy().to_string();
        Command::new("wireguard.exe")
            .args(["/installtunnelservice", &config_str])
            .status()?;

        self.interfaces.insert(name.to_string(), WgInterface {
            name: name.to_string(),
            config_path: config_str,
        });

        log::info!("WireGuard tunnel {name} created");
        Ok(())
    }

    pub fn remove_tunnel(&mut self, name: &str) -> anyhow::Result<()> {
        Command::new("wireguard.exe")
            .args(["/uninstalltunnelservice", name])
            .status()?;
        self.interfaces.remove(name);
        log::info!("WireGuard tunnel {name} removed");
        Ok(())
    }

    pub fn remove_all(&mut self) {
        let names: Vec<String> = self.interfaces.keys().cloned().collect();
        for name in names {
            let _ = self.remove_tunnel(&name);
        }
    }
}

pub struct WgPeer {
    pub public_key: String,
    pub endpoint_host: String,
    pub endpoint_port: u16,
    pub allowed_ips: Vec<String>,
    pub persistent_keepalive: u32,
}
```

Note: This assumes `wireguard.exe` is installed on the system (from the official WireGuard Windows installer). The Tauri app installer should check for this dependency or bundle it.

- [ ] **Step 2: Integrate WgManager into AppState and connect_forward flow**

Add `pub wg: Mutex<wireguard::WgManager>` to AppState.

In `connect_forward`, before starting bond:
1. Parse underlay leg configs from forward config
2. Call `wg.create_tunnel()` for each leg
3. Then start bond with leg addresses pointing through WG interfaces

In `disconnect_forward`, after stopping bond:
1. Call `wg.remove_tunnel()` for each leg

- [ ] **Step 3: Build and commit**

```bash
cd wstunnel-main && cargo build -p leikwan-client-win
git add wstunnel-main/leikwan-client-win/src-tauri/src/
git commit -m "feat: implement WireGuard tunnel management for Windows (wireguard.exe integration)"
```

---

### Task 8: Add Panel client API endpoints (if not existing)

**Files:**
- Create or modify: `master/src/Controllers/ClientApiController.php` (new controller)
- Modify: `master/routes.php` or equivalent routing file

- [ ] **Step 1: Check if client API endpoints exist**

Search the Panel codebase for existing `/api/client/` routes. If they exist, adapt the Windows client to use them. If not, create them.

- [ ] **Step 2: Create ClientApiController.php**

```php
<?php

namespace App\Controllers;

class ClientApiController extends BaseController
{
    public function login(): void
    {
        $email = $this->input('email');
        $password = $this->input('password');
        // Authenticate against users table
        // Return JSON: { "token": "...", "user_id": N, "username": "..." }
    }

    public function heartbeat(): void
    {
        $token = $this->bearerToken();
        // Validate token, return forward list
        // { "config_version": N, "forwards": [...] }
    }

    public function forwardConfig(int $forwardId): void
    {
        $token = $this->bearerToken();
        // Validate token, return full forward config
        // Same structure as buildClientConfig()
    }
}
```

This task depends on the Panel's existing authentication system. The exact implementation needs to be adapted to the Panel's MVC framework.

- [ ] **Step 3: Register routes**

Add routes for `/api/client/login`, `/api/client/heartbeat`, `/api/client/config/{id}`.

- [ ] **Step 4: Commit**

```bash
git add master/src/Controllers/ClientApiController.php master/routes.php
git commit -m "feat: add Panel client API endpoints for Windows app (login, heartbeat, config)"
```

---

### Task 9: Build installer and test end-to-end

**Files:** None (build + testing)

- [ ] **Step 1: Create app icons**

Create placeholder icons at:
- `wstunnel-main/leikwan-client-win/src-tauri/icons/32x32.png`
- `wstunnel-main/leikwan-client-win/src-tauri/icons/128x128.png`
- `wstunnel-main/leikwan-client-win/src-tauri/icons/icon.ico`
- `wstunnel-main/leikwan-client-win/src-tauri/icons/icon.png`

- [ ] **Step 2: Build Windows installer**

```bash
cd wstunnel-main/leikwan-client-win && cargo tauri build
```

This produces an MSI and/or NSIS installer in `target/release/bundle/`.

- [ ] **Step 3: Test login flow**

Install the app. Launch. Enter Panel URL and credentials. Verify forward list loads.

- [ ] **Step 4: Test forward connection**

Select a forward and click Connect. Verify:
- WireGuard tunnel created (check `wireguard.exe /dumplog`)
- leikwan-bond process running (Task Manager)
- Local port forwarding works

- [ ] **Step 5: Test system tray**

Close window → verify hides to tray. Right-click tray → Show Window / Quit.

- [ ] **Step 6: Test auto-start**

Enable auto-start in settings. Reboot. Verify app starts.

- [ ] **Step 7: Commit any fixes**

```bash
git add -A
git commit -m "fix: address issues found during Windows client end-to-end testing"
```
