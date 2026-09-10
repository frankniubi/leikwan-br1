#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod api;
mod bond;
mod commands;
mod wireguard;

use commands::AppState;
use std::sync::Mutex;
use tauri::{
    menu::{Menu, MenuItem},
    tray::TrayIconBuilder,
    Manager,
};

fn main() {
    env_logger::init();

    tauri::Builder::default()
        .plugin(tauri_plugin_autostart::init(
            tauri_plugin_autostart::MacosLauncher::LaunchAgent,
            None,
        ))
        .manage(AppState {
            api: tokio::sync::Mutex::new(api::PanelApi::new("")),
            forwards: Mutex::new(vec![]),
            connected_forwards: Mutex::new(vec![]),
            bond: bond::BondManager::new(),
            wg: Mutex::new(wireguard::WgManager::new()),
            forward_configs: tokio::sync::Mutex::new(std::collections::HashMap::new()),
        })
        .invoke_handler(tauri::generate_handler![
            commands::check_system,
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
                            // Graceful cleanup: stop all bonds and remove WG tunnels
                            if let Some(state) = app.try_state::<AppState>() {
                                state.bond.stop_all();
                                if let Ok(mut wg) = state.wg.lock() {
                                    wg.remove_all();
                                }
                            }
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
                let _ = window.hide();
                api.prevent_close();
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
