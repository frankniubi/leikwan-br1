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
        #[arg(long, default_value = "2")]
        preferred_initial_legs: usize,
        #[arg(long, default_value = "1")]
        minimum_initial_legs: usize,
        #[arg(long, default_value = "600")]
        initial_leg_wait_ms: u64,
        #[arg(long, default_value = "1800000")]
        leg_reconnect_backoff_ms: u64,
        #[arg(long, default_value = "1800000")]
        leg_reconnect_backoff_max_ms: u64,
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

fn validate_leg_readiness_settings(
    preferred_initial_legs: usize,
    minimum_initial_legs: usize,
    initial_leg_wait_ms: u64,
    leg_reconnect_backoff_ms: u64,
    leg_reconnect_backoff_max_ms: u64,
) -> anyhow::Result<()> {
    if preferred_initial_legs == 0 || minimum_initial_legs == 0 {
        return Err(anyhow::anyhow!("initial bond leg counts must be positive"));
    }
    if minimum_initial_legs > preferred_initial_legs {
        return Err(anyhow::anyhow!(
            "minimum initial bond legs cannot exceed preferred initial legs"
        ));
    }
    if initial_leg_wait_ms == 0 {
        return Err(anyhow::anyhow!("initial bond leg wait must be positive"));
    }
    if leg_reconnect_backoff_ms == 0 || leg_reconnect_backoff_max_ms == 0 {
        return Err(anyhow::anyhow!("bond leg reconnect backoff must be positive"));
    }
    if leg_reconnect_backoff_ms > leg_reconnect_backoff_max_ms {
        return Err(anyhow::anyhow!(
            "bond leg reconnect backoff cannot exceed its maximum"
        ));
    }
    Ok(())
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
            preferred_initial_legs,
            minimum_initial_legs,
            initial_leg_wait_ms,
            leg_reconnect_backoff_ms,
            leg_reconnect_backoff_max_ms,
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
            }).and_then(|runtime| {
                validate_leg_readiness_settings(
                    preferred_initial_legs,
                    minimum_initial_legs,
                    initial_leg_wait_ms,
                    leg_reconnect_backoff_ms,
                    leg_reconnect_backoff_max_ms,
                )?;
                Ok(runtime)
            });
            match runtime {
                Ok((protocol_mode, default_strategy)) => {
                    let config = SessionConfig {
                        chunk_size,
                        max_window_bytes,
                        retransmit_after: Duration::from_millis(retransmit_ms),
                        join_timeout: Duration::from_millis(join_timeout_ms),
                        connect_timeout: Duration::from_millis(connect_timeout_ms),
                        preferred_initial_legs,
                        minimum_initial_legs,
                        initial_leg_wait: Duration::from_millis(initial_leg_wait_ms),
                        leg_reconnect_backoff: Duration::from_millis(leg_reconnect_backoff_ms),
                        leg_reconnect_backoff_max: Duration::from_millis(leg_reconnect_backoff_max_ms),
                        interactive_chunk_bytes,
                        latency_guard_bytes,
                        interactive_rtt_slack: Duration::from_millis(interactive_rtt_slack_ms),
                        warmup_trigger_bytes,
                        warmup_slice_bytes,
                        warmup_leg_delay: Duration::from_millis(warmup_leg_delay_ms),
                        bulk_promote_bytes,
                        leg_cooldown: Duration::from_millis(leg_cooldown_ms),
                        throughput_min_sample_bytes: 1024 * 1024,
                        throughput_min_sample: Duration::from_secs(1),
                        adaptive_line_admission: false,
                        throughput_min_mbps: 10.0,
                        throughput_relative_floor: 0.35,
                        throughput_bad_windows: 3,
                        throughput_recovery_windows: 2,
                        throughput_quarantine: Duration::from_secs(60),
                        throughput_probe_interval: Duration::from_secs(30),
                        throughput_probe_bytes: 1024 * 1024,
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
                preferred_initial_legs: 2,
                minimum_initial_legs: 1,
                initial_leg_wait: Duration::from_millis(600),
                leg_reconnect_backoff: Duration::from_secs(30 * 60),
                leg_reconnect_backoff_max: Duration::from_secs(30 * 60),
                interactive_chunk_bytes: 16384,
                latency_guard_bytes: 262144,
                interactive_rtt_slack: Duration::from_millis(30),
                warmup_trigger_bytes: 65536,
                warmup_slice_bytes: 65536,
                warmup_leg_delay: Duration::from_millis(80),
                bulk_promote_bytes: 262144,
                leg_cooldown: Duration::from_millis(1800000),
                throughput_min_sample_bytes: 1024 * 1024,
                throughput_min_sample: Duration::from_secs(1),
                adaptive_line_admission: false,
                throughput_min_mbps: 10.0,
                throughput_relative_floor: 0.35,
                throughput_bad_windows: 3,
                throughput_recovery_windows: 2,
                throughput_quarantine: Duration::from_secs(60),
                throughput_probe_interval: Duration::from_secs(30),
                throughput_probe_bytes: 1024 * 1024,
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
        assert_eq!(cmd.get_version(), Some("0.1.31"));
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
    fn client_cli_accepts_fixed_leg_readiness_and_retry_flags() {
        let cli = Cli::try_parse_from([
            "leikwan-bond",
            "client",
            "--protocol",
            "tcp",
            "--listen",
            "0.0.0.0:11024",
            "--legs",
            "tcp://127.0.0.1:20962?leg_route_id=451,tcp://127.0.0.1:20962?leg_route_id=453",
            "--session-token",
            "token",
            "--preferred-initial-legs",
            "5",
            "--minimum-initial-legs",
            "2",
            "--initial-leg-wait-ms",
            "3000",
            "--leg-reconnect-backoff-ms",
            "5000",
            "--leg-reconnect-backoff-max-ms",
            "60000",
        ])
        .expect("client cli should parse fixed leg readiness and retry settings");

        match cli.command {
            Command::Client {
                preferred_initial_legs,
                minimum_initial_legs,
                initial_leg_wait_ms,
                leg_reconnect_backoff_ms,
                leg_reconnect_backoff_max_ms,
                ..
            } => {
                assert_eq!(preferred_initial_legs, 5);
                assert_eq!(minimum_initial_legs, 2);
                assert_eq!(initial_leg_wait_ms, 3000);
                assert_eq!(leg_reconnect_backoff_ms, 5000);
                assert_eq!(leg_reconnect_backoff_max_ms, 60000);
            }
            _ => panic!("expected client command"),
        }
    }

    #[test]
    fn client_cli_keeps_legacy_leg_readiness_and_retry_defaults() {
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
        ])
        .expect("client cli should retain legacy defaults");

        match cli.command {
            Command::Client {
                preferred_initial_legs,
                minimum_initial_legs,
                initial_leg_wait_ms,
                leg_reconnect_backoff_ms,
                leg_reconnect_backoff_max_ms,
                ..
            } => {
                assert_eq!(preferred_initial_legs, 2);
                assert_eq!(minimum_initial_legs, 1);
                assert_eq!(initial_leg_wait_ms, 600);
                assert_eq!(leg_reconnect_backoff_ms, 30 * 60 * 1000);
                assert_eq!(leg_reconnect_backoff_max_ms, 30 * 60 * 1000);
            }
            _ => panic!("expected client command"),
        }
    }

    #[test]
    fn leg_readiness_settings_reject_invalid_bounds() {
        for settings in [
            (0, 1, 600, 1_800_000, 1_800_000),
            (2, 0, 600, 1_800_000, 1_800_000),
            (1, 2, 600, 1_800_000, 1_800_000),
            (2, 1, 0, 1_800_000, 1_800_000),
            (2, 1, 600, 0, 1_800_000),
            (2, 1, 600, 60_000, 5_000),
        ] {
            assert!(
                validate_leg_readiness_settings(
                    settings.0,
                    settings.1,
                    settings.2,
                    settings.3,
                    settings.4,
                )
                .is_err(),
                "settings should be rejected: {settings:?}"
            );
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
