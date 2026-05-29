/// MPTCP endpoint management via `ip mptcp` CLI commands.
use anyhow::{anyhow, Result};
use log::{info, warn};
use std::process::Command;

/// Configuration for MPTCP endpoint management.
#[derive(Debug, Clone)]
#[allow(dead_code)]
pub struct MptcpEndpointConfig {
    pub subflow_limit: u32,
    pub device: String,
    pub id_base: u32,
    pub endpoints: Vec<EndpointEntry>,
}

/// A single MPTCP endpoint entry.
#[derive(Debug, Clone)]
pub struct EndpointEntry {
    pub address: String,
    pub flags: Vec<String>,
    pub device: String,
    pub id: u32,
}

/// Configure MPTCP endpoints on the system.
///
/// 1. Set global MPTCP subflow/add_addr_accepted limits.
/// 2. Parse current endpoints.
/// 3. Remove stale endpoints in our id_base range.
/// 4. Add desired endpoints.
pub fn configure_mptcp_endpoints(config: &MptcpEndpointConfig) -> Result<()> {
    // Step 1: Set global limits.
    set_mptcp_limits(config.subflow_limit)?;

    // Step 2: Parse current endpoints.
    let current = get_current_endpoints()?;

    // Step 3a: Remove endpoints left behind by old relay instances whose
    // network interface no longer exists. MPTCP endpoint slots are global, so
    // an orphan outside our id range can still make a fresh forward fail with
    // "too many addresses or duplicate one".
    let removed_orphans = remove_endpoints_with_missing_devices(&current)?;
    let current = if removed_orphans > 0 {
        get_current_endpoints()?
    } else {
        current
    };

    // Step 3b: Remove stale endpoints in our id range.
    // Only clean our own range: id_base .. id_base + max(len, 10).
    // Use a small margin (10) in case the previous run had more endpoints.
    let id_end = config.id_base + std::cmp::max(config.endpoints.len() as u32, 10);
    for ep in &current {
        if ep.id >= config.id_base && ep.id < id_end {
            remove_endpoint(ep.id)?;
        }
    }

    // Step 4: Add desired endpoints.
    let mut added = 0usize;
    let mut skipped = 0usize;
    for ep in &config.endpoints {
        if add_endpoint(ep)? {
            added += 1;
        } else {
            skipped += 1;
        }
    }

    info!(
        "configured MPTCP endpoints (id_base={} desired={} added={} skipped={})",
        config.id_base,
        config.endpoints.len(),
        added,
        skipped
    );

    Ok(())
}

/// Set MPTCP global limits, taking the max of the current value and the
/// requested value so multiple relay instances don't step on each other.
fn set_mptcp_limits(subflow_limit: u32) -> Result<()> {
    // Read current limits first.
    let current = get_current_limits().unwrap_or((0, 0));
    let new_subflow = std::cmp::max(current.0, subflow_limit);
    let new_add_addr = std::cmp::max(current.1, subflow_limit);

    let output = Command::new("ip")
        .args([
            "mptcp",
            "limits",
            "set",
            "subflow",
            &new_subflow.to_string(),
            "add_addr_accepted",
            &new_add_addr.to_string(),
        ])
        .output()
        .map_err(|e| anyhow!("failed to run 'ip mptcp limits set': {}", e))?;

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        warn!("ip mptcp limits set failed: {}", stderr.trim());
    } else {
        info!("set MPTCP limits: subflow={} add_addr_accepted={}", new_subflow, new_add_addr);
    }

    Ok(())
}

/// Read current MPTCP limits → (subflow, add_addr_accepted).
fn get_current_limits() -> Result<(u32, u32)> {
    let output = Command::new("ip")
        .args(["mptcp", "limits", "show"])
        .output()
        .map_err(|e| anyhow!("failed to run 'ip mptcp limits show': {}", e))?;

    let stdout = String::from_utf8_lossy(&output.stdout);
    // Line format: "add_addr_accepted N subflows M"
    let mut subflow: u32 = 0;
    let mut add_addr: u32 = 0;
    let parts: Vec<&str> = stdout.split_whitespace().collect();
    for i in 0..parts.len() {
        if parts[i] == "subflows" || parts[i] == "subflow" {
            if i + 1 < parts.len() {
                subflow = parts[i + 1].parse().unwrap_or(0);
            }
        }
        if parts[i] == "add_addr_accepted" {
            if i + 1 < parts.len() {
                add_addr = parts[i + 1].parse().unwrap_or(0);
            }
        }
    }
    Ok((subflow, add_addr))
}

/// Parse currently configured MPTCP endpoints.
fn get_current_endpoints() -> Result<Vec<EndpointEntry>> {
    let output = Command::new("ip")
        .args(["mptcp", "endpoint", "show"])
        .output()
        .map_err(|e| anyhow!("failed to run 'ip mptcp endpoint show': {}", e))?;

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        return Err(anyhow!("ip mptcp endpoint show failed: {}", stderr.trim()));
    }

    let stdout = String::from_utf8_lossy(&output.stdout);
    let mut endpoints = Vec::new();

    // Parse lines like:
    // 10.0.0.1 id 1 subflow dev eth0
    // 10.0.0.2 id 2 signal dev eth1
    for line in stdout.lines() {
        let line = line.trim();
        if line.is_empty() {
            continue;
        }

        let parts: Vec<&str> = line.split_whitespace().collect();
        if parts.len() < 3 {
            continue;
        }

        let address = parts[0].to_string();
        let mut id: u32 = 0;
        let mut flags = Vec::new();
        let mut device = String::new();

        let mut i = 1;
        while i < parts.len() {
            match parts[i] {
                "id" => {
                    if i + 1 < parts.len() {
                        id = parts[i + 1].parse().unwrap_or(0);
                        i += 2;
                    } else {
                        i += 1;
                    }
                }
                "dev" => {
                    if i + 1 < parts.len() {
                        device = parts[i + 1].to_string();
                        i += 2;
                    } else {
                        i += 1;
                    }
                }
                "subflow" | "signal" | "backup" | "fullmesh" => {
                    flags.push(parts[i].to_string());
                    i += 1;
                }
                _ => {
                    i += 1;
                }
            }
        }

        endpoints.push(EndpointEntry {
            address,
            flags,
            device,
            id,
        });
    }

    Ok(endpoints)
}

/// Remove an MPTCP endpoint by id.
fn remove_endpoint(id: u32) -> Result<()> {
    let output = Command::new("ip")
        .args(["mptcp", "endpoint", "delete", "id", &id.to_string()])
        .output()
        .map_err(|e| anyhow!("failed to run 'ip mptcp endpoint delete': {}", e))?;

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        warn!("failed to remove MPTCP endpoint id {}: {}", id, stderr.trim());
    } else {
        info!("removed MPTCP endpoint id {}", id);
    }

    Ok(())
}

/// Remove endpoints whose bound interface no longer exists.
fn remove_endpoints_with_missing_devices(current: &[EndpointEntry]) -> Result<usize> {
    let mut removed = 0usize;

    for ep in current {
        if endpoint_references_missing_device(ep, device_exists) {
            warn!(
                "removing orphaned MPTCP endpoint id {} address={} dev={}",
                ep.id, ep.address, ep.device
            );
            remove_endpoint(ep.id)?;
            removed += 1;
        }
    }

    if removed > 0 {
        info!("removed {} orphaned MPTCP endpoints with missing devices", removed);
    }

    Ok(removed)
}

fn endpoint_references_missing_device<F>(ep: &EndpointEntry, mut device_exists_fn: F) -> bool
where
    F: FnMut(&str) -> bool,
{
    !ep.device.is_empty() && !device_exists_fn(&ep.device)
}

fn device_exists(device: &str) -> bool {
    match Command::new("ip")
        .args(["link", "show", "dev", device])
        .output()
    {
        Ok(output) => output.status.success(),
        Err(err) => {
            warn!(
                "failed to check network device {} existence; preserving endpoint: {}",
                device, err
            );
            true
        }
    }
}

fn is_skip_safe_endpoint_add_error(detail: &str) -> bool {
    let lower = detail.to_ascii_lowercase();
    lower.contains("too many addresses")
        || lower.contains("duplicate")
        || lower.contains("file exists")
        || lower.contains("object already exists")
}

/// Add an MPTCP endpoint.
fn add_endpoint(ep: &EndpointEntry) -> Result<bool> {
    let mut args = vec![
        "mptcp".to_string(),
        "endpoint".to_string(),
        "add".to_string(),
        ep.address.clone(),
        "id".to_string(),
        ep.id.to_string(),
    ];

    for flag in &ep.flags {
        args.push(flag.clone());
    }

    if !ep.device.is_empty() {
        args.push("dev".to_string());
        args.push(ep.device.clone());
    }

    let str_args: Vec<&str> = args.iter().map(|s| s.as_str()).collect();

    let output = Command::new("ip")
        .args(&str_args)
        .output()
        .map_err(|e| anyhow!("failed to run 'ip mptcp endpoint add': {}", e))?;

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        let detail = stderr.trim();
        if is_skip_safe_endpoint_add_error(detail) {
            warn!(
                "skipping MPTCP endpoint {} id {} flags={:?} dev={} after kernel rejected add: {}",
                ep.address, ep.id, ep.flags, ep.device, detail
            );
            return Ok(false);
        }
        return Err(anyhow!(
            "failed to add MPTCP endpoint {} (id {}): {}",
            ep.address,
            ep.id,
            detail
        ));
    }

    info!(
        "added MPTCP endpoint {} id {} flags={:?} dev={}",
        ep.address, ep.id, ep.flags, ep.device
    );

    Ok(true)
}

/// Check if managed endpoints are still present and re-register any
/// that have disappeared. Returns the number of endpoints restored.
pub fn check_and_restore_endpoints(config: &MptcpEndpointConfig) -> Result<usize> {
    let current = get_current_endpoints()?;
    let current_ids: std::collections::HashSet<u32> =
        current.iter().map(|ep| ep.id).collect();

    let mut restored = 0;
    for ep in &config.endpoints {
        if !current_ids.contains(&ep.id) {
            warn!(
                "MPTCP endpoint id {} ({}) missing, re-registering",
                ep.id, ep.address
            );
            if add_endpoint(ep)? {
                restored += 1;
            }
        }
    }

    if restored > 0 {
        set_mptcp_limits(config.subflow_limit)?;
        info!("restored {} MPTCP endpoints", restored);
    }

    Ok(restored)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn endpoint_add_capacity_or_duplicate_errors_are_skip_safe() {
        assert!(is_skip_safe_endpoint_add_error(
            "Error: too many addresses or duplicate one."
        ));
        assert!(is_skip_safe_endpoint_add_error(
            "RTNETLINK answers: File exists"
        ));
        assert!(is_skip_safe_endpoint_add_error(
            "RTNETLINK answers: Object already exists"
        ));
        assert!(!is_skip_safe_endpoint_add_error(
            "Cannot find device \"lw-mptcp-47-1\""
        ));
    }

    #[test]
    fn endpoint_with_missing_named_device_is_stale() {
        let ep = EndpointEntry {
            address: "10.204.61.137".to_string(),
            flags: vec!["subflow".to_string()],
            device: "if44603".to_string(),
            id: 92,
        };

        assert!(endpoint_references_missing_device(&ep, |dev| dev == "eth0"));
        assert!(!endpoint_references_missing_device(&ep, |dev| dev == "if44603"));
    }
}
