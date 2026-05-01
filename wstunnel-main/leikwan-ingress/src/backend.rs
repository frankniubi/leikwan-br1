use anyhow::{Context, Result};
use tokio::io::copy_bidirectional;
use tokio::net::{TcpListener, TcpStream};
use tracing::{info, warn};

async fn relay_one_connection(mut inbound: TcpStream, target: &str) -> Result<()> {
    let mut outbound = TcpStream::connect(target)
        .await
        .with_context(|| format!("failed to connect backend target {target}"))?;
    let _ = copy_bidirectional(&mut inbound, &mut outbound)
        .await
        .context("backend relay failed")?;
    Ok(())
}

pub async fn run_backend(listen: &str, target: &str) -> Result<()> {
    let listener = TcpListener::bind(listen)
        .await
        .with_context(|| format!("failed to bind backend listener {listen}"))?;
    info!("leikwan-ingress backend listening on {listen}, target {target}");

    loop {
        let (stream, peer) = listener
            .accept()
            .await
            .context("failed to accept backend connection")?;
        let target = target.to_string();
        tokio::spawn(async move {
            if let Err(err) = relay_one_connection(stream, &target).await {
                warn!("backend relay for {peer:?} failed: {err:#}");
            }
        });
    }
}
