use crate::api::{ForwardInfo, PanelApi};
use crate::bond::{self, BondParams};
use crate::wireguard::{self, WgPeer};
use serde::{Deserialize, Serialize};
use std::sync::Mutex;
use tauri::State;
use tokio::sync::Mutex as AsyncMutex;

pub struct AppState {
    pub api: AsyncMutex<PanelApi>,
    pub forwards: Mutex<Vec<ForwardInfo>>,
    pub connected_forwards: Mutex<Vec<u64>>,
    pub bond: bond::BondManager,
    pub wg: Mutex<wireguard::WgManager>,
    /// Cached forward configs from last pull, keyed by forward_id
    pub forward_configs: AsyncMutex<std::collections::HashMap<u64, serde_json::Value>>,
}

#[derive(Serialize)]
pub struct LoginResult {
    pub success: bool,
    pub username: String,
    pub error: Option<String>,
}

#[derive(Serialize)]
pub struct SystemStatus {
    pub wireguard_installed: bool,
    pub wireguard_path: String,
    pub bond_installed: bool,
    pub bond_path: String,
}

#[tauri::command]
pub async fn check_system() -> Result<SystemStatus, String> {
    let wg_paths = [
        r"C:\Program Files\WireGuard\wireguard.exe",
        r"C:\Program Files (x86)\WireGuard\wireguard.exe",
    ];

    let mut wg_found = false;
    let mut wg_path = String::new();

    // Check PATH first
    if let Ok(output) = std::process::Command::new("where").arg("wireguard.exe").output() {
        if output.status.success() {
            let path = String::from_utf8_lossy(&output.stdout).trim().lines().next().unwrap_or("").to_string();
            if !path.is_empty() {
                wg_found = true;
                wg_path = path;
            }
        }
    }

    // Check known locations
    if !wg_found {
        for p in &wg_paths {
            if std::path::Path::new(p).exists() {
                wg_found = true;
                wg_path = p.to_string();
                break;
            }
        }
    }

    // Check leikwan-bond
    let mut bond_found = false;
    let mut bond_path = String::new();
    if let Ok(output) = std::process::Command::new("where").arg("leikwan-bond").output() {
        if output.status.success() {
            let path = String::from_utf8_lossy(&output.stdout).trim().lines().next().unwrap_or("").to_string();
            if !path.is_empty() {
                bond_found = true;
                bond_path = path;
            }
        }
    }
    // Also check in the same directory as the running executable
    if !bond_found {
        if let Ok(exe) = std::env::current_exe() {
            if let Some(dir) = exe.parent() {
                let bond_exe = dir.join("leikwan-bond.exe");
                if bond_exe.exists() {
                    bond_found = true;
                    bond_path = bond_exe.to_string_lossy().to_string();
                }
            }
        }
    }

    Ok(SystemStatus {
        wireguard_installed: wg_found,
        wireguard_path: wg_path,
        bond_installed: bond_found,
        bond_path: bond_path,
    })
}

#[tauri::command]
pub async fn login(
    state: State<'_, AppState>,
    panel_url: String,
    email: String,
    password: String,
) -> Result<LoginResult, String> {
    let mut api = state.api.lock().await;
    *api = PanelApi::new(&panel_url);
    match api.login(&email, &password).await {
        Ok(resp) => {
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
    let api = state.api.lock().await;
    if !api.is_authenticated() {
        return Err("not authenticated".into());
    }
    match api.pull_configs().await {
        Ok(resp) => {
            let forward_list: Vec<ForwardInfo> = resp.forwards.iter().filter_map(|v| {
                match serde_json::from_value(v.clone()) {
                    Ok(f) => Some(f),
                    Err(e) => {
                        log::warn!("failed to parse forward: {e}  raw: {v}");
                        None
                    }
                }
            }).collect();
            // Cache raw forward configs keyed by forward_id
            {
                let mut configs = state.forward_configs.lock().await;
                configs.clear();
                for v in &resp.forwards {
                    if let Some(id) = v.get("forward_id").and_then(|x| x.as_u64()) {
                        configs.insert(id, v.clone());
                    }
                }
            }
            let mut forwards = state.forwards.lock().map_err(|e| e.to_string())?;
            *forwards = forward_list.clone();
            Ok(forward_list)
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
    // 1. Get forward config from cache
    let config = {
        let configs = state.forward_configs.lock().await;
        configs.get(&forward_id).cloned()
            .ok_or_else(|| format!("no config for forward {forward_id}, refresh first"))?
    };

    // 2. Extract WireGuard peers and bond legs from config
    let endpoints = config.get("endpoints")
        .and_then(|v| v.as_array())
        .cloned()
        .unwrap_or_default();

    let listen_port = config.get("listen_port")
        .and_then(|v| v.as_u64())
        .unwrap_or(0) as u16;

    let protocol = config.get("target_protocol")
        .and_then(|v| v.as_str())
        .or_else(|| config.get("transport").and_then(|v| v.as_str()))
        .unwrap_or("tcp")
        .to_string();

    // 3. Set up WireGuard tunnels for each endpoint
    let mut leg_addresses = Vec::new();
    {
        let mut wg = state.wg.lock().map_err(|e| e.to_string())?;
        for (i, ep) in endpoints.iter().enumerate() {
            let wg_config = ep.get("wireguard").or_else(|| ep.get("wg"));
            if let Some(wg_cfg) = wg_config {
                let iface_name = format!("lkw-{forward_id}-{i}");
                let private_key = wg_cfg.get("private_key")
                    .and_then(|v| v.as_str())
                    .unwrap_or("");
                let address = wg_cfg.get("address")
                    .and_then(|v| v.as_str())
                    .unwrap_or("10.0.0.2");
                let peer_pub = wg_cfg.get("peer_public_key")
                    .and_then(|v| v.as_str())
                    .unwrap_or("");
                let peer_endpoint = ep.get("host")
                    .and_then(|v| v.as_str())
                    .unwrap_or("127.0.0.1");
                let peer_port = ep.get("port")
                    .and_then(|v| v.as_u64())
                    .unwrap_or(51820) as u16;
                let allowed_ips = wg_cfg.get("allowed_ips")
                    .and_then(|v| v.as_str())
                    .unwrap_or("0.0.0.0/0")
                    .to_string();

                let peer = WgPeer {
                    public_key: peer_pub.to_string(),
                    endpoint_host: peer_endpoint.to_string(),
                    endpoint_port: peer_port,
                    allowed_ips: vec![allowed_ips],
                    persistent_keepalive: 25,
                };

                if let Err(e) = wg.create_tunnel(&iface_name, private_key, address, &[peer], 1382) {
                    log::error!("failed to create WG tunnel {iface_name}: {e}");
                }
            }

            // Collect bond leg addresses (sidecar/relay endpoints via WG overlay)
            if let Some(bond_leg) = ep.get("bond_leg").and_then(|v| v.as_str()) {
                leg_addresses.push(bond_leg.to_string());
            } else if let Some(host) = ep.get("relay_host").and_then(|v| v.as_str()) {
                let port = ep.get("relay_port").and_then(|v| v.as_u64()).unwrap_or(0);
                leg_addresses.push(format!("{host}:{port}"));
            }
        }
    }

    // 4. Extract bond params from config
    let bond_cfg = config.get("bonding");
    let bond_params = if let Some(bc) = bond_cfg {
        BondParams {
            chunk_size: bc.get("chunk_size").and_then(|v| v.as_u64()).unwrap_or(131072),
            max_window_bytes: bc.get("max_window_bytes").and_then(|v| v.as_u64()).unwrap_or(16777216),
            retransmit_ms: bc.get("retransmit_ms").and_then(|v| v.as_u64()).unwrap_or(300),
            connect_timeout_ms: bc.get("connect_timeout_ms").and_then(|v| v.as_u64()).unwrap_or(3000),
            active_leg_limit: bc.get("active_leg_limit").and_then(|v| v.as_u64()).unwrap_or(0),
            interactive_chunk_bytes: bc.get("interactive_chunk_bytes").and_then(|v| v.as_u64()).unwrap_or(16384),
            latency_guard_bytes: bc.get("latency_guard_bytes").and_then(|v| v.as_u64()).unwrap_or(262144),
            interactive_rtt_slack_ms: bc.get("interactive_rtt_slack_ms").and_then(|v| v.as_u64()).unwrap_or(30),
        }
    } else {
        BondParams::default()
    };

    // 5. Extract session token
    let session_token = config.get("session_token")
        .or_else(|| bond_cfg.and_then(|bc| bc.get("session_token")))
        .and_then(|v| v.as_str())
        .unwrap_or("");

    // 6. Start bond process
    let listen_addr = format!("127.0.0.1:{listen_port}");
    if !leg_addresses.is_empty() {
        state.bond.start_bond(
            forward_id,
            &protocol,
            &listen_addr,
            &leg_addresses,
            session_token,
            &bond_params,
        ).map_err(|e| format!("bond start failed: {e}"))?;
    }

    // 7. Track connected forward
    {
        let mut connected = state.connected_forwards.lock().map_err(|e| e.to_string())?;
        connected.push(forward_id);
    }

    Ok(ForwardStatus {
        forward_id,
        connected: true,
        bytes_in: 0,
        bytes_out: 0,
        legs_active: leg_addresses.len() as u32,
        latency_ms: 0,
    })
}

#[tauri::command]
pub async fn disconnect_forward(
    state: State<'_, AppState>,
    forward_id: u64,
) -> Result<(), String> {
    // 1. Stop bond process
    state.bond.stop_bond(forward_id).map_err(|e| e.to_string())?;

    // 2. Remove WireGuard tunnels for this forward
    {
        let mut wg = state.wg.lock().map_err(|e| e.to_string())?;
        // Find and remove all tunnels matching this forward's naming pattern
        let prefix = format!("lkw-{forward_id}-");
        let names_to_remove: Vec<String> = (0..32)
            .map(|i| format!("{prefix}{i}"))
            .collect();
        for name in &names_to_remove {
            let _ = wg.remove_tunnel(name);
        }
    }

    // 3. Update connected list
    {
        let mut connected = state.connected_forwards.lock().map_err(|e| e.to_string())?;
        connected.retain(|&id| id != forward_id);
    }

    Ok(())
}
