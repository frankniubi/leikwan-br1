pub mod client_runtime;
pub mod config;
pub mod delivery;
pub mod frame;
pub mod merge;
pub mod progress;
pub mod queue;
pub mod scheduler;
pub mod scheduler_runtime;
pub mod sidecar_runtime;
pub mod telemetry;
pub mod udp_socket;
pub mod wireguard;

pub fn build_info(binary: &str) -> serde_json::Value {
    serde_json::json!({
        "binary": binary,
        "version": env!("CARGO_PKG_VERSION"),
        "protocol_version": progress::PROTOCOL_VERSION,
        "capabilities": [progress::LEG_PROGRESS_CAPABILITY],
        "scheduler_modes": ["legacy", "feedback_drr"],
    })
}
