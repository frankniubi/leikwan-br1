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
