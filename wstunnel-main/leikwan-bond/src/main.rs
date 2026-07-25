mod client;
mod frame;
mod server;
mod session;
mod strategy;
mod token;
mod transport;

use clap::{Parser, Subcommand};
use session::SessionConfig;
use strategy::ClientStrategy;
use std::path::PathBuf;
use token::TransportProtocol;
use std::time::Duration;

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
        #[arg(long, default_value = "400")]
        join_timeout_ms: u64,
        #[arg(long, default_value = "0")]
        active_leg_limit: usize,
        #[arg(long, default_value = "16384")]
        interactive_chunk_bytes: usize,
        #[arg(long, default_value = "262144")]
        latency_guard_bytes: usize,
        #[arg(long, default_value = "30")]
        interactive_rtt_slack_ms: u64,
        #[arg(long, default_value = "65536")]
        warmup_trigger_bytes: usize,
        #[arg(long, default_value = "65536")]
        warmup_slice_bytes: usize,
        #[arg(long, default_value = "80")]
        warmup_leg_delay_ms: u64,
        #[arg(long, default_value = "262144")]
        bulk_promote_bytes: usize,
        #[arg(long, default_value = "1800000")]
        leg_cooldown_ms: u64,
        #[arg(long, default_value_t = false)]
        latency_first: bool,
        #[arg(long, default_value_t = false)]
        udp_primary_only: bool,
        #[arg(long)]
        strategy_file: Option<PathBuf>,
        #[arg(long, default_value = "latency_best")]
        default_strategy: String,
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
            join_timeout_ms,
            active_leg_limit,
            interactive_chunk_bytes,
            latency_guard_bytes,
            interactive_rtt_slack_ms,
            warmup_trigger_bytes,
            warmup_slice_bytes,
            warmup_leg_delay_ms,
            bulk_promote_bytes,
            leg_cooldown_ms,
            latency_first,
            udp_primary_only,
            strategy_file,
            default_strategy,
        } => {
            let transport = match protocol.trim().to_ascii_lowercase().as_str() {
                "tcp" => Ok(TransportProtocol::Tcp),
                "udp" => Ok(TransportProtocol::Udp),
                other => Err(anyhow::anyhow!("unsupported protocol {other}, expected tcp or udp")),
            };
            let runtime = transport.and_then(|protocol_mode| {
                ClientStrategy::parse(&default_strategy).map(|strategy| (protocol_mode, strategy))
            });
            match runtime {
                Ok((protocol_mode, default_strategy)) => {
                    let config = SessionConfig {
                        chunk_size,
                        max_window_bytes,
                        retransmit_after: Duration::from_millis(retransmit_ms),
                        join_timeout: Duration::from_millis(join_timeout_ms),
                        connect_timeout: Duration::from_millis(connect_timeout_ms),
                        interactive_chunk_bytes,
                        latency_guard_bytes,
                        interactive_rtt_slack: Duration::from_millis(interactive_rtt_slack_ms),
                        warmup_trigger_bytes,
                        warmup_slice_bytes,
                        warmup_leg_delay: Duration::from_millis(warmup_leg_delay_ms),
                        bulk_promote_bytes,
                        leg_cooldown: Duration::from_millis(leg_cooldown_ms),
                        latency_first,
                        udp_primary_only,
                        strategy_file,
                        default_strategy,
                    };
                    let rt = tokio::runtime::Builder::new_multi_thread()
                        .enable_all()
                        .build();
                    match rt {
                        Ok(rt) => rt.block_on(session::run_client_listener(
                            listen,
                            legs,
                            session_token,
                            protocol_mode,
                            config,
                        )),
                        Err(err) => Err(anyhow::anyhow!("failed to build tokio runtime: {err}")),
                    }
                }
                Err(err) => Err(err),
            }
        }
        Command::Server {
            listen,
            token_secret,
        } => {
            let config = SessionConfig {
                chunk_size: 131072,
                max_window_bytes: 16777216,
                retransmit_after: Duration::from_millis(300),
                join_timeout: Duration::from_millis(400),
                connect_timeout: Duration::from_millis(3000),
                interactive_chunk_bytes: 16384,
                latency_guard_bytes: 262144,
                interactive_rtt_slack: Duration::from_millis(30),
                warmup_trigger_bytes: 65536,
                warmup_slice_bytes: 65536,
                warmup_leg_delay: Duration::from_millis(80),
                bulk_promote_bytes: 262144,
                leg_cooldown: Duration::from_millis(1800000),
                latency_first: false,
                udp_primary_only: false,
                strategy_file: None,
                default_strategy: ClientStrategy::LatencyBest,
            };
            let rt = tokio::runtime::Builder::new_multi_thread()
                .enable_all()
                .build();
            match rt {
                Ok(rt) => rt.block_on(session::run_server_listener(
                    listen,
                    None,
                    false,
                    token_secret,
                    config,
                )),
                Err(err) => Err(anyhow::anyhow!("failed to build tokio runtime: {err}")),
            }
        }
    };

    if let Err(e) = result {
        log::error!("fatal: {:#}", e);
        std::process::exit(1);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use clap::CommandFactory;

    #[test]
    fn cli_reports_refreshed_version() {
        let cmd = Cli::command();
        assert_eq!(cmd.get_version(), Some("0.1.16"));
    }

    #[test]
    fn client_cli_accepts_join_timeout_flag() {
        let cli = Cli::try_parse_from([
            "leikwan-bond",
            "client",
            "--protocol",
            "tcp",
            "--listen",
            "0.0.0.0:11024",
            "--legs",
            "tcp://127.0.0.1:20962?priority=10",
            "--session-token",
            "token",
            "--join-timeout-ms",
            "400",
        ])
        .expect("client cli should parse join-timeout-ms");

        match cli.command {
            Command::Client { join_timeout_ms, .. } => assert_eq!(join_timeout_ms, 400),
            _ => panic!("expected client command"),
        }
    }

    #[test]
    fn client_cli_accepts_strategy_file() {
        let cli = Cli::try_parse_from([
            "leikwan-bond",
            "client",
            "--protocol",
            "tcp",
            "--listen",
            "0.0.0.0:11024",
            "--legs",
            "tcp://127.0.0.1:20962?priority=10",
            "--session-token",
            "token",
            "--strategy-file",
            "/var/lib/leikwan-client-agent/forward-strategies/77.strategy",
            "--default-strategy",
            "latency_best",
        ])
        .expect("client cli should parse strategy settings");

        match cli.command {
            Command::Client {
                strategy_file,
                default_strategy,
                ..
            } => {
                assert_eq!(
                    strategy_file,
                    Some(std::path::PathBuf::from(
                        "/var/lib/leikwan-client-agent/forward-strategies/77.strategy"
                    ))
                );
                assert_eq!(default_strategy, "latency_best");
            }
            _ => panic!("expected client command"),
        }
    }
}
