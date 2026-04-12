#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod api;
mod bond;
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
            bond: bond::BondManager::new(),
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
