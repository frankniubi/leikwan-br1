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
    let mut connected = state.connected_forwards.lock().map_err(|e| e.to_string())?;
    connected.retain(|&id| id != forward_id);
    Ok(())
}
