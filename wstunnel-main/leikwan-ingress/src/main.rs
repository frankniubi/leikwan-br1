use std::path::PathBuf;

use anyhow::Result;
use clap::{Parser, Subcommand};
use tracing_subscriber::EnvFilter;

#[derive(Parser, Debug)]
#[command(name = "leikwan-ingress", version, about = "Leikwan plain TCP ingress helper")]
struct Cli {
    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand, Debug)]
enum Commands {
    Client {
        #[arg(long)]
        listen: String,
        #[arg(long)]
        remote: String,
        #[arg(long = "route-key")]
        route_key: String,
        #[arg(long = "auth-token")]
        auth_token: String,
    },
    Probe {
        #[arg(long)]
        remote: String,
        #[arg(long = "route-key")]
        route_key: String,
        #[arg(long = "auth-token")]
        auth_token: String,
    },
    Mux {
        #[arg(long, required = true)]
        listen: Vec<String>,
        #[arg(long = "routes-file")]
        routes_file: PathBuf,
    },
    Backend {
        #[arg(long)]
        listen: String,
        #[arg(long)]
        target: String,
    },
}

#[tokio::main]
async fn main() -> Result<()> {
    tracing_subscriber::fmt()
        .with_env_filter(EnvFilter::from_default_env())
        .with_target(false)
        .init();

    let cli = Cli::parse();
    match cli.command {
        Commands::Client {
            listen,
            remote,
            route_key,
            auth_token,
        } => leikwan_ingress::client::run_client(&listen, &remote, &route_key, &auth_token).await,
        Commands::Probe {
            remote,
            route_key,
            auth_token,
        } => leikwan_ingress::client::run_probe(&remote, &route_key, &auth_token).await,
        Commands::Mux { listen, routes_file } => leikwan_ingress::mux::run_mux(&listen, &routes_file).await,
        Commands::Backend { listen, target } => leikwan_ingress::backend::run_backend(&listen, &target).await,
    }
}
