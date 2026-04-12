use std::collections::HashMap;
use std::path::PathBuf;
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
        let bond_path = find_bond_binary();
        let mut cmd = Command::new(&bond_path);
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

        log::info!("starting bond: {:?}", bond_path);
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

/// Locate the leikwan-bond binary.
/// Priority: 1) same directory as this exe  2) PATH
fn find_bond_binary() -> PathBuf {
    if let Ok(exe) = std::env::current_exe() {
        if let Some(dir) = exe.parent() {
            let candidate = dir.join("leikwan-bond.exe");
            if candidate.exists() {
                return candidate;
            }
        }
    }
    PathBuf::from("leikwan-bond")
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
