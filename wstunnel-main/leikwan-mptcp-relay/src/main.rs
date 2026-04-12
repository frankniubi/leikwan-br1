mod client;
mod config;
mod endpoint;
mod mptcp;
mod server;
mod splice;
mod token;

use clap::{Parser, Subcommand};

#[derive(Parser)]
#[command(name = "leikwan-mptcp-relay", about = "MPTCP relay with splice(2) forwarding")]
struct Cli {
    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand)]
enum Commands {
    /// Run in client mode: listen TCP locally, relay via MPTCP to sidecar
    Client {
        /// Path to JSON config file
        #[arg(long)]
        config: String,
    },
    /// Run in server mode: listen MPTCP, verify token, relay to target
    Server {
        /// Path to JSON config file
        #[arg(long)]
        config: String,
    },
}

fn main() -> anyhow::Result<()> {
    env_logger::Builder::from_env(env_logger::Env::default().default_filter_or("info")).init();

    let cli = Cli::parse();

    match cli.command {
        Commands::Client { config: path } => {
            let cfg = config::load_client_config(&path)?;
            log::info!("loaded client config from {}", path);

            // Optionally configure MPTCP endpoints.
            if cfg.manage_mptcp_endpoints {
                let mut endpoints = parse_endpoint_entries(
                    &cfg.mptcp_endpoints,
                    &cfg.mptcp_endpoint_device,
                    cfg.mptcp_endpoint_id_base,
                );

                // If no explicit endpoints configured, auto-derive subflow
                // endpoints from underlay legs. Each leg's local_address is a
                // WG address the client can use as a subflow source.
                if endpoints.is_empty() {
                    endpoints = derive_subflow_endpoints_from_underlays(
                        &cfg.underlays,
                        &cfg.underlay,
                        &cfg.mptcp_endpoint_device,
                        cfg.mptcp_endpoint_id_base,
                    );
                    if !endpoints.is_empty() {
                        log::info!(
                            "auto-derived {} subflow endpoints from underlay legs",
                            endpoints.len()
                        );
                    }
                }

                let ep_config = endpoint::MptcpEndpointConfig {
                    subflow_limit: cfg.subflow_limit,
                    device: cfg.mptcp_endpoint_device.clone(),
                    id_base: cfg.mptcp_endpoint_id_base,
                    endpoints,
                };
                endpoint::configure_mptcp_endpoints(&ep_config)?;

                // Spawn endpoint watchdog: re-registers endpoints if they vanish.
                let watchdog_config = ep_config.clone();
                std::thread::spawn(move || {
                    let interval = std::time::Duration::from_secs(30);
                    loop {
                        std::thread::sleep(interval);
                        match endpoint::check_and_restore_endpoints(&watchdog_config) {
                            Ok(n) if n > 0 => {
                                log::info!("endpoint watchdog restored {} endpoints", n);
                            }
                            Err(e) => {
                                log::warn!("endpoint watchdog error: {}", e);
                            }
                            _ => {}
                        }
                    }
                });
            }

            client::run_client(cfg)
        }
        Commands::Server { config: path } => {
            let cfg = config::load_server_config(&path)?;
            log::info!("loaded server config from {}", path);
            server::run_server(cfg)
        }
    }
}

/// Parse endpoint JSON entries into EndpointEntry structs.
///
/// Supports two field-naming conventions:
///   - Panel format: `"dev"` for device, individual booleans (`"subflow"`, `"signal"`, `"backup_only"`, `"fullmesh"`)
///   - Legacy/explicit: `"device"` for device, `"flags"` as a string array
fn parse_endpoint_entries(
    entries: &[serde_json::Value],
    default_device: &str,
    id_base: u32,
) -> Vec<endpoint::EndpointEntry> {
    entries
        .iter()
        .enumerate()
        .filter_map(|(i, v)| {
            let address = v.get("address")?.as_str()?.to_string();

            // Parse flags: try "flags" array first, then individual boolean fields
            let flags: Vec<String> = v
                .get("flags")
                .and_then(|f| f.as_array())
                .map(|arr| {
                    arr.iter()
                        .filter_map(|x| x.as_str().map(String::from))
                        .collect()
                })
                .unwrap_or_else(|| {
                    let mut f = Vec::new();
                    for (key, flag) in &[("subflow", "subflow"), ("signal", "signal"), ("backup_only", "backup"), ("fullmesh", "fullmesh")] {
                        if v.get(key).and_then(|b| b.as_bool()).unwrap_or(false) {
                            f.push(flag.to_string());
                        }
                    }
                    f
                });

            // Parse device: try "dev" first (Panel format), then "device" (legacy)
            let device = v
                .get("dev")
                .or_else(|| v.get("device"))
                .and_then(|d| d.as_str())
                .unwrap_or(default_device)
                .to_string();

            let id = v
                .get("id")
                .and_then(|d| d.as_u64())
                .map(|x| x as u32)
                .unwrap_or(id_base + i as u32);

            Some(endpoint::EndpointEntry {
                address,
                flags,
                device,
                id,
            })
        })
        .collect()
}

/// Derive MPTCP subflow endpoints from `underlays[].legs[].local_address`.
///
/// When the Panel does not pre-populate `mptcp_endpoints`, we can extract
/// each leg's local WG address and register it as a `subflow` endpoint so
/// the kernel creates subflows from all available paths.
///
/// Each underlay carries its own `interface_name` which becomes the
/// per-endpoint device (instead of a single global device string).
fn derive_subflow_endpoints_from_underlays(
    underlays: &[serde_json::Value],
    fallback_underlay: &serde_json::Value,
    fallback_device: &str,
    id_base: u32,
) -> Vec<endpoint::EndpointEntry> {
    // Use underlays[] if non-empty, else fall back to single underlay
    let effective: Vec<&serde_json::Value> = if !underlays.is_empty() {
        underlays.iter().collect()
    } else if fallback_underlay.is_object() {
        vec![fallback_underlay]
    } else {
        return Vec::new();
    };

    let mut seen = std::collections::HashSet::new();
    let mut endpoints = Vec::new();

    for underlay in &effective {
        let iface_name = underlay
            .get("interface_name")
            .and_then(|v| v.as_str())
            .unwrap_or(fallback_device);

        let legs = match underlay.get("legs").and_then(|l| l.as_array()) {
            Some(arr) => arr,
            None => continue,
        };

        for leg in legs {
            let addr = match leg.get("local_address").and_then(|a| a.as_str()) {
                Some(a) => a.to_string(),
                None => continue,
            };
            if seen.contains(&addr) {
                continue;
            }
            seen.insert(addr.clone());

            let id = id_base + endpoints.len() as u32 + 1;
            endpoints.push(endpoint::EndpointEntry {
                address: addr,
                flags: vec!["subflow".to_string()],
                device: iface_name.to_string(),
                id,
            });
        }
    }

    endpoints
}
