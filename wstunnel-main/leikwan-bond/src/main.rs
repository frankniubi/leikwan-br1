use clap::{Args, Parser, Subcommand};
use leikwan_bond::session::{self, SessionConfig};
use leikwan_bond::strategy::ClientStrategy;
use leikwan_bond::token::TransportProtocol;
use std::path::PathBuf;
use std::time::Duration;

#[derive(Parser)]
#[command(name = "leikwan-bond", version, about = "Multi-leg TCP/UDP bonding relay")]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

fn parse_positive_f64(value: &str) -> Result<f64, String> {
    let parsed = value
        .parse::<f64>()
        .map_err(|_| format!("{value:?} must be a number"))?;
    if !parsed.is_finite() || parsed <= 0.0 {
        return Err(format!("{value:?} must be finite and greater than zero"));
    }
    Ok(parsed)
}

fn parse_unit_interval(value: &str) -> Result<f64, String> {
    let parsed = parse_positive_f64(value)?;
    if parsed > 1.0 {
        return Err(format!("{value:?} must not exceed one"));
    }
    Ok(parsed)
}

fn parse_positive_u64(value: &str) -> Result<u64, String> {
    let parsed = value
        .parse::<u64>()
        .map_err(|_| format!("{value:?} must be an integer"))?;
    if parsed == 0 {
        return Err(format!("{value:?} must be greater than zero"));
    }
    Ok(parsed)
}

fn parse_positive_u32(value: &str) -> Result<u32, String> {
    let parsed = parse_positive_u64(value)?;
    u32::try_from(parsed).map_err(|_| format!("{value:?} exceeds the supported range"))
}

#[derive(Args, Debug, Clone)]
struct AdaptiveLineArgs {
    #[arg(long, default_value_t = false)]
    adaptive_line_admission: bool,
    #[arg(long, default_value = "10", value_parser = parse_positive_f64)]
    throughput_min_mbps: f64,
    #[arg(long, default_value = "0.35", value_parser = parse_unit_interval)]
    throughput_relative_floor: f64,
    #[arg(long, default_value = "1048576", value_parser = parse_positive_u64)]
    throughput_min_sample_bytes: u64,
    #[arg(long, default_value = "1000", value_parser = parse_positive_u64)]
    throughput_min_sample_ms: u64,
    #[arg(long, default_value = "3", value_parser = parse_positive_u32)]
    throughput_bad_windows: u32,
    #[arg(long, default_value = "2", value_parser = parse_positive_u32)]
    throughput_recovery_windows: u32,
    #[arg(long, default_value = "60000", value_parser = parse_positive_u64)]
    throughput_quarantine_ms: u64,
    #[arg(long, default_value = "30000", value_parser = parse_positive_u64)]
    throughput_probe_interval_ms: u64,
    #[arg(long, default_value = "1048576", value_parser = parse_positive_u64)]
    throughput_probe_bytes: u64,
}

impl AdaptiveLineArgs {
    fn apply_to(&self, config: &mut SessionConfig) {
        config.adaptive_line_admission = self.adaptive_line_admission;
        config.throughput_min_mbps = self.throughput_min_mbps;
        config.throughput_relative_floor = self.throughput_relative_floor;
        config.throughput_min_sample_bytes = self.throughput_min_sample_bytes;
        config.throughput_min_sample = Duration::from_millis(self.throughput_min_sample_ms);
        config.throughput_bad_windows = self.throughput_bad_windows;
        config.throughput_recovery_windows = self.throughput_recovery_windows;
        config.throughput_quarantine = Duration::from_millis(self.throughput_quarantine_ms);
        config.throughput_probe_interval = Duration::from_millis(self.throughput_probe_interval_ms);
        config.throughput_probe_bytes = self.throughput_probe_bytes;
    }
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
        #[command(flatten)]
        adaptive: AdaptiveLineArgs,
    },
    /// Run as server: accept leg connections, reassemble and forward to target
    Server {
        #[arg(long)]
        listen: String,
        #[arg(long)]
        token_secret: String,
        #[command(flatten)]
        adaptive: AdaptiveLineArgs,
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
            adaptive,
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
                    let mut config = SessionConfig {
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
                        latency_first,
                        udp_primary_only,
                        strategy_file,
                        default_strategy,
                        ..SessionConfig::client_defaults()
                    };
                    adaptive.apply_to(&mut config);
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
            adaptive,
        } => {
            let mut config = SessionConfig::server_defaults();
            adaptive.apply_to(&mut config);
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
        assert_eq!(cmd.get_version(), Some("0.1.41"));
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

    fn assert_adaptive_settings(settings: &AdaptiveLineArgs) {
        assert!(settings.adaptive_line_admission);
        assert_eq!(settings.throughput_min_mbps, 12.5);
        assert_eq!(settings.throughput_relative_floor, 0.4);
        assert_eq!(settings.throughput_min_sample_bytes, 2_097_152);
        assert_eq!(settings.throughput_min_sample_ms, 1_500);
        assert_eq!(settings.throughput_bad_windows, 4);
        assert_eq!(settings.throughput_recovery_windows, 3);
        assert_eq!(settings.throughput_quarantine_ms, 75_000);
        assert_eq!(settings.throughput_probe_interval_ms, 35_000);
        assert_eq!(settings.throughput_probe_bytes, 524_288);
    }

    #[test]
    fn client_and_server_cli_accept_adaptive_line_settings() {
        let adaptive_flags = [
            "--adaptive-line-admission",
            "--throughput-min-mbps",
            "12.5",
            "--throughput-relative-floor",
            "0.4",
            "--throughput-min-sample-bytes",
            "2097152",
            "--throughput-min-sample-ms",
            "1500",
            "--throughput-bad-windows",
            "4",
            "--throughput-recovery-windows",
            "3",
            "--throughput-quarantine-ms",
            "75000",
            "--throughput-probe-interval-ms",
            "35000",
            "--throughput-probe-bytes",
            "524288",
        ];

        let mut client_args = vec![
            "leikwan-bond",
            "client",
            "--protocol",
            "tcp",
            "--listen",
            "127.0.0.1:58316",
            "--legs",
            "tcp://127.0.0.1:20962?leg_route_id=451",
            "--session-token",
            "token",
        ];
        client_args.extend(adaptive_flags);
        let client = Cli::try_parse_from(client_args).expect("client adaptive flags should parse");
        match client.command {
            Command::Client { adaptive, .. } => assert_adaptive_settings(&adaptive),
            _ => panic!("expected client command"),
        }

        let mut server_args = vec![
            "leikwan-bond",
            "server",
            "--listen",
            "0.0.0.0:11024",
            "--token-secret",
            "secret",
        ];
        server_args.extend(adaptive_flags);
        let server = Cli::try_parse_from(server_args).expect("server adaptive flags should parse");
        match server.command {
            Command::Server { adaptive, .. } => assert_adaptive_settings(&adaptive),
            _ => panic!("expected server command"),
        }
    }

    #[test]
    fn adaptive_line_admission_is_opt_in_on_both_ends() {
        let client = Cli::try_parse_from([
            "leikwan-bond",
            "client",
            "--protocol",
            "tcp",
            "--listen",
            "127.0.0.1:58316",
            "--legs",
            "tcp://127.0.0.1:20962?leg_route_id=451",
            "--session-token",
            "token",
        ])
        .expect("client defaults should parse");
        let server = Cli::try_parse_from([
            "leikwan-bond",
            "server",
            "--listen",
            "0.0.0.0:11024",
            "--token-secret",
            "secret",
        ])
        .expect("server defaults should parse");

        match client.command {
            Command::Client { adaptive, .. } => assert!(!adaptive.adaptive_line_admission),
            _ => panic!("expected client command"),
        }
        match server.command {
            Command::Server { adaptive, .. } => assert!(!adaptive.adaptive_line_admission),
            _ => panic!("expected server command"),
        }
    }

    #[test]
    fn adaptive_line_cli_rejects_invalid_thresholds() {
        for invalid in [
            ["--throughput-min-mbps", "0"],
            ["--throughput-min-mbps", "NaN"],
            ["--throughput-relative-floor", "0"],
            ["--throughput-relative-floor", "1.1"],
            ["--throughput-min-sample-bytes", "0"],
            ["--throughput-min-sample-ms", "0"],
            ["--throughput-bad-windows", "0"],
            ["--throughput-recovery-windows", "0"],
            ["--throughput-quarantine-ms", "0"],
            ["--throughput-probe-interval-ms", "0"],
            ["--throughput-probe-bytes", "0"],
        ] {
            let mut args = vec![
                "leikwan-bond",
                "server",
                "--listen",
                "0.0.0.0:11024",
                "--token-secret",
                "secret",
            ];
            args.extend(invalid);
            assert!(
                Cli::try_parse_from(args).is_err(),
                "invalid setting should be rejected: {invalid:?}"
            );
        }
    }
}
