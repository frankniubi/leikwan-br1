use anyhow::{Context, Result};
use clap::Parser;
use leikwan_bond::pathd::{DesiredState, PathDaemon, MAX_DESIRED_STATE_BYTES};
use log::{error, info};
use std::path::{Path, PathBuf};
use tokio::signal::unix::{signal, SignalKind};

#[derive(Debug, Parser)]
#[command(
    name = "leikwan-pathd",
    version,
    about = "Managed multi-forward path runtime"
)]
struct Cli {
    #[arg(long)]
    config: PathBuf,
    #[arg(long)]
    status: PathBuf,
}

#[tokio::main(flavor = "multi_thread", worker_threads = 2)]
async fn main() -> Result<()> {
    env_logger::Builder::from_env(env_logger::Env::default().default_filter_or("info")).init();
    let cli = Cli::parse();
    let mut daemon = PathDaemon::new(&cli.status);
    daemon
        .apply(load_desired(&cli.config).await?)
        .await
        .context("initial desired state was rejected")?;
    info!("path runtime generation {} is ready", daemon.generation());

    let mut hangup = signal(SignalKind::hangup()).context("register SIGHUP handler")?;
    let mut terminate = signal(SignalKind::terminate()).context("register SIGTERM handler")?;
    let mut interrupt = signal(SignalKind::interrupt()).context("register SIGINT handler")?;
    loop {
        tokio::select! {
            _ = hangup.recv() => {
                match load_desired(&cli.config).await {
                    Ok(desired) => match daemon.apply(desired).await {
                        Ok(()) => info!("path runtime generation {} is ready", daemon.generation()),
                        Err(err) if daemon.generation() == 0 => {
                            return Err(err).context("desired state reload failed closed");
                        }
                        Err(err) => error!("desired state reload rejected: {err:#}"),
                    },
                    Err(err) => error!("desired state reload failed: {err:#}"),
                }
            }
            _ = terminate.recv() => break,
            _ = interrupt.recv() => break,
        }
    }

    daemon.shutdown().await?;
    Ok(())
}

async fn load_desired(path: &Path) -> Result<DesiredState> {
    let metadata = tokio::fs::metadata(path)
        .await
        .with_context(|| format!("stat desired state at {}", path.display()))?;
    if metadata.len() > MAX_DESIRED_STATE_BYTES as u64 {
        anyhow::bail!("desired state exceeds {} bytes", MAX_DESIRED_STATE_BYTES);
    }
    let bytes = tokio::fs::read(path)
        .await
        .with_context(|| format!("read desired state from {}", path.display()))?;
    if bytes.len() > MAX_DESIRED_STATE_BYTES {
        anyhow::bail!("desired state exceeds {} bytes", MAX_DESIRED_STATE_BYTES);
    }
    serde_json::from_slice(&bytes).context("parse desired state JSON")
}
