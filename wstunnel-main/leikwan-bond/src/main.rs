mod client;
mod server;
mod token;

use clap::{Parser, Subcommand};

#[derive(Parser)]
#[command(name = "leikwan-bond", version, about = "Multi-leg TCP/UDP bonding relay")]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Run as client: listen locally, distribute chunks across legs
    Client {
        #[arg(long)]
        protocol: String,
        #[arg(long)]
        listen: String,
        #[arg(long, value_delimiter = ',')]
        legs: Vec<String>,
        #[arg(long)]
        session_token: String,
        #[arg(long, default_value = "131072")]
        chunk_size: usize,
        #[arg(long, default_value = "16777216")]
        max_window_bytes: usize,
        #[arg(long, default_value = "300")]
        retransmit_ms: u64,
        #[arg(long, default_value = "3000")]
        connect_timeout_ms: u64,
        #[arg(long, default_value = "0")]
        active_leg_limit: usize,
        #[arg(long, default_value = "16384")]
        interactive_chunk_bytes: usize,
        #[arg(long, default_value = "262144")]
        latency_guard_bytes: usize,
        #[arg(long, default_value = "30")]
        interactive_rtt_slack_ms: u64,
    },
    /// Run as server: accept leg connections, reassemble and forward to target
    Server {
        #[arg(long)]
        listen: String,
        #[arg(long)]
        token_secret: String,
    },
}

fn main() {
    env_logger::Builder::from_env(env_logger::Env::default().default_filter_or("info")).init();

    let cli = Cli::parse();
    let result = match cli.command {
        Command::Client {
            protocol,
            listen,
            legs,
            session_token,
            chunk_size,
            max_window_bytes,
            retransmit_ms,
            connect_timeout_ms,
            active_leg_limit,
            interactive_chunk_bytes,
            latency_guard_bytes,
            interactive_rtt_slack_ms,
        } => {
            let config = client::ClientConfig {
                protocol,
                listen,
                legs,
                session_token,
                chunk_size,
                max_window_bytes,
                retransmit_ms,
                connect_timeout_ms,
                active_leg_limit,
                interactive_chunk_bytes,
                latency_guard_bytes,
                interactive_rtt_slack_ms,
            };
            client::run_client(config)
        }
        Command::Server {
            listen,
            token_secret,
        } => server::run_server(&listen, &token_secret),
    };

    if let Err(e) = result {
        log::error!("fatal: {:#}", e);
        std::process::exit(1);
    }
}
