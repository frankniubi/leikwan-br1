#![cfg(unix)]
#![recursion_limit = "256"]

use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use hmac::{Hmac, Mac};
use leikwan_bond::frame::{read_frame, write_frame, WireFrame};
use leikwan_bond::pathd::{DesiredState, PathDaemon};
use leikwan_bond::session::{run_server_listener, SessionConfig};
use sha2::{Digest, Sha256};
use std::collections::HashSet;
use std::fs;
use std::net::{Ipv4Addr, SocketAddr};
use std::os::unix::fs::PermissionsExt;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::{Duration, SystemTime, UNIX_EPOCH};
use tokio::io::{copy_bidirectional, AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use tokio::sync::mpsc;
use tokio::task::JoinHandle;
use tokio::time::{sleep, timeout};

type HmacSha256 = Hmac<Sha256>;

fn unique_temp_dir(label: &str) -> PathBuf {
    std::env::temp_dir().join(format!(
        "leikwan-{label}-{}-{}",
        std::process::id(),
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos()
    ))
}

fn sha256_hex(value: &str) -> String {
    format!("{:x}", Sha256::digest(value.as_bytes()))
}

fn sign_target_token(secret: &str, target: SocketAddr, forward_id: u64) -> String {
    let now = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_secs();
    let payload = serde_json::to_vec(&serde_json::json!({
        "target_host": target.ip().to_string(),
        "target_port": target.port(),
        "target_protocol": "tcp",
        "service_id": 9,
        "forward_id": forward_id,
        "issued_at": now,
        "expires_at": now + 300,
    }))
    .unwrap();
    let mut mac = HmacSha256::new_from_slice(secret.as_bytes()).unwrap();
    mac.update(&payload);
    format!(
        "{}.{}",
        URL_SAFE_NO_PAD.encode(&payload),
        URL_SAFE_NO_PAD.encode(mac.finalize().into_bytes())
    )
}

fn free_addr() -> SocketAddr {
    let listener = std::net::TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).unwrap();
    listener.local_addr().unwrap()
}

fn free_ingress_addr() -> SocketAddr {
    for port in 7000..=9000 {
        let addr = SocketAddr::from((Ipv4Addr::LOCALHOST, port));
        if let Ok(listener) = std::net::TcpListener::bind(addr) {
            drop(listener);
            return addr;
        }
    }
    panic!("no free authenticated ingress port in 7000-9000");
}

async fn observed_bond_relay(
    mut inbound: TcpStream,
    mut outbound: TcpStream,
    route_tx: mpsc::UnboundedSender<u32>,
) -> anyhow::Result<()> {
    let hello = read_frame(&mut inbound).await?;
    let route_id = match &hello {
        WireFrame::Hello(hello) => hello.route_id,
        other => anyhow::bail!("expected LBND hello, got {other:?}"),
    };
    route_tx.send(route_id).ok();
    write_frame(&mut outbound, &hello).await?;
    copy_bidirectional(&mut inbound, &mut outbound).await?;
    Ok(())
}

async fn read_socks_target(stream: &mut TcpStream) -> anyhow::Result<SocketAddr> {
    let version = stream.read_u8().await?;
    let method_count = stream.read_u8().await? as usize;
    anyhow::ensure!(
        version == 5 && method_count > 0 && method_count <= 16,
        "invalid SOCKS greeting"
    );
    let mut methods = vec![0u8; method_count];
    stream.read_exact(&mut methods).await?;
    anyhow::ensure!(methods.contains(&0), "SOCKS client did not offer no-auth");
    stream.write_all(&[5, 0]).await?;

    let version = stream.read_u8().await?;
    let command = stream.read_u8().await?;
    let reserved = stream.read_u8().await?;
    let address_type = stream.read_u8().await?;
    anyhow::ensure!(
        version == 5 && command == 1 && reserved == 0,
        "invalid SOCKS CONNECT request"
    );
    let ip = match address_type {
        1 => {
            let mut bytes = [0u8; 4];
            stream.read_exact(&mut bytes).await?;
            std::net::IpAddr::V4(bytes.into())
        }
        4 => {
            let mut bytes = [0u8; 16];
            stream.read_exact(&mut bytes).await?;
            std::net::IpAddr::V6(bytes.into())
        }
        other => anyhow::bail!("unexpected SOCKS address type {other}"),
    };
    let port = stream.read_u16().await?;
    Ok(SocketAddr::new(ip, port))
}

async fn start_socks_relay(
    route_tx: mpsc::UnboundedSender<u32>,
) -> anyhow::Result<(SocketAddr, JoinHandle<()>)> {
    let listener = TcpListener::bind("127.0.0.1:0").await?;
    let addr = listener.local_addr()?;
    let task = tokio::spawn(async move {
        while let Ok((mut inbound, _)) = listener.accept().await {
            let route_tx = route_tx.clone();
            tokio::spawn(async move {
                let result = async {
                    let target = read_socks_target(&mut inbound).await?;
                    let outbound = TcpStream::connect(target).await?;
                    inbound.write_all(&[5, 0, 0, 1, 127, 0, 0, 1, 0, 0]).await?;
                    observed_bond_relay(inbound, outbound, route_tx).await
                }
                .await;
                if let Err(error) = result {
                    let _ = error;
                }
            });
        }
    });
    Ok((addr, task))
}

async fn start_observed_ingress_backend(
    aggregator: SocketAddr,
    route_tx: mpsc::UnboundedSender<u32>,
) -> anyhow::Result<(SocketAddr, JoinHandle<()>)> {
    let listener = TcpListener::bind("127.0.0.1:0").await?;
    let addr = listener.local_addr()?;
    let task = tokio::spawn(async move {
        while let Ok((inbound, _)) = listener.accept().await {
            let route_tx = route_tx.clone();
            tokio::spawn(async move {
                if let Ok(outbound) = TcpStream::connect(aggregator).await {
                    let _ = observed_bond_relay(inbound, outbound, route_tx).await;
                }
            });
        }
    });
    Ok((addr, task))
}

fn write_fake_provider(root: &Path) -> PathBuf {
    fs::create_dir_all(root).unwrap();
    let binary = root.join("provider-runtime");
    fs::write(
        &binary,
        "#!/bin/sh\ntrap 'exit 0' TERM INT\nwhile :; do sleep 1; done\n",
    )
    .unwrap();
    fs::set_permissions(&binary, fs::Permissions::from_mode(0o700)).unwrap();
    binary
}

fn desired_state(
    provider: &Path,
    socks_addr: SocketAddr,
    ingress_addr: SocketAddr,
    public_addr: SocketAddr,
    aggregator: SocketAddr,
    token: &str,
    auth_token: &str,
) -> DesiredState {
    let now = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_secs();
    let session_json = "provider-session-material";
    let config_toml = "provider-line-material";
    serde_json::from_value(serde_json::json!({
        "generation": 1,
        "provider": {
            "binary": provider,
            "session_json": session_json,
            "session_sha256": sha256_hex(session_json),
        },
        "auth_refs": {
            "slave-316": auth_token,
        },
        "local_leases": [{
            "line_id": 451,
            "lease_id": "00000000-0000-4000-8000-000000000451",
            "lease_generation": 7,
            "max_lanes": 1,
            "config_sha256": sha256_hex(config_toml),
            "config_toml": config_toml,
            "expires_at_unix": now + 300,
            "socks_addr": socks_addr,
        }],
        "forwards": [{
            "forward_id": 17,
            "listen": public_addr,
            "egress": aggregator,
            "session_token": token,
            "legs": [
                {
                    "uri": format!(
                        "socks5://{socks_addr}?leg_route_id=1004511&line_id=451&lease_generation=7"
                    ),
                    "execution_group": "client:20:device",
                    "line_id": 451,
                    "lease_id": "00000000-0000-4000-8000-000000000451",
                    "lease_generation": 7,
                    "leg_route_id": 1_004_511,
                },
                {
                    "uri": format!(
                        "ingress+tcp://{ingress_addr}?leg_route_id=1004531&endpoint_id=316&route_key=fixed&auth_ref=slave-316"
                    ),
                    "execution_group": "node:101",
                    "line_id": 453,
                    "lease_id": "00000000-0000-4000-8000-000000000453",
                    "lease_generation": 3,
                    "leg_route_id": 1_004_531,
                }
            ],
            "policy": {
                "chunk_size": 32768,
                "max_window_bytes": 1048576,
                "retransmit_ms": 100,
                "join_timeout_ms": 400,
                "connect_timeout_ms": 3000,
                "preferred_initial_legs": 2,
                "minimum_initial_legs": 2,
                "initial_leg_wait_ms": 600,
                "leg_reconnect_backoff_ms": 30000,
                "leg_reconnect_backoff_max_ms": 30000,
                "interactive_chunk_bytes": 4096,
                "latency_guard_bytes": 65536,
                "interactive_rtt_slack_ms": 30,
                "warmup_trigger_bytes": 16384,
                "warmup_slice_bytes": 16384,
                "warmup_leg_delay_ms": 10,
                "bulk_promote_bytes": 32768,
                "leg_cooldown_ms": 30000,
                "throughput_min_sample_bytes": 65536,
                "throughput_min_sample_ms": 100,
                "adaptive_line_admission": true,
                "throughput_min_mbps": 10.0,
                "throughput_relative_floor": 0.35,
                "throughput_bad_windows": 3,
                "throughput_recovery_windows": 2,
                "throughput_quarantine_ms": 60000,
                "throughput_probe_interval_ms": 30000,
                "throughput_probe_bytes": 65536,
                "latency_first": true,
                "udp_primary_only": false,
                "default_strategy": "latency_best"
            }
        }]
    }))
    .unwrap()
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn pathd_joins_local_socks_and_authenticated_slave_in_one_flat_session() {
    let root = unique_temp_dir("pathd-flat-hybrid");
    let provider = write_fake_provider(&root.join("provider"));
    let status_path = root.join("status.json");
    let runtime_root = root.join("runtime");
    let secret = "bond-target-signing-secret";
    let ingress_auth = "opaque-ingress-auth-token";
    let aggregator = free_addr();
    let public_addr = free_addr();
    let target_addr = free_addr();
    let ingress_addr = free_ingress_addr();
    let upload: Vec<u8> = (0..384 * 1024).map(|index| (index % 251) as u8).collect();
    let reverse: Vec<u8> = (0..320 * 1024)
        .map(|index| (250 - index % 251) as u8)
        .collect();

    let target_connections = Arc::new(AtomicUsize::new(0));
    let target_connections_task = Arc::clone(&target_connections);
    let expected_upload = upload.clone();
    let expected_reverse = reverse.clone();
    let target_listener = TcpListener::bind(target_addr).await.unwrap();
    let target_task = tokio::spawn(async move {
        let (mut stream, _) = timeout(Duration::from_secs(10), target_listener.accept())
            .await
            .expect("target connection timeout")
            .unwrap();
        target_connections_task.fetch_add(1, Ordering::SeqCst);
        let mut received = Vec::new();
        stream.read_to_end(&mut received).await.unwrap();
        assert_eq!(
            expected_upload, received,
            "upload must arrive once and in order"
        );
        stream.write_all(&expected_reverse).await.unwrap();
        stream.shutdown().await.unwrap();
        if timeout(Duration::from_millis(300), target_listener.accept())
            .await
            .is_ok()
        {
            target_connections_task.fetch_add(1, Ordering::SeqCst);
        }
    });

    let server_task = tokio::spawn(run_server_listener(
        aggregator.to_string(),
        None,
        false,
        secret.to_string(),
        SessionConfig::server_defaults(),
    ));
    sleep(Duration::from_millis(50)).await;

    let (route_tx, mut route_rx) = mpsc::unbounded_channel();
    let (socks_addr, socks_task) = start_socks_relay(route_tx.clone()).await.unwrap();
    let (ingress_backend, ingress_backend_task) =
        start_observed_ingress_backend(aggregator, route_tx)
            .await
            .unwrap();
    fs::create_dir_all(&root).unwrap();
    let routes_path = root.join("routes.json");
    fs::write(
        &routes_path,
        serde_json::to_vec(&serde_json::json!({
            "routes": [{
                "route_key": "fixed",
                "backend_host": ingress_backend.ip().to_string(),
                "backend_port": ingress_backend.port(),
                "service_name": "flat-hybrid-test",
                "ingress_protocol": "plain_tcp_mux",
                "ingress_auth_token": ingress_auth,
            }]
        }))
        .unwrap(),
    )
    .unwrap();
    let ingress_task = tokio::spawn({
        let routes_path = routes_path.clone();
        async move { leikwan_ingress::mux::run_mux(&[ingress_addr.to_string()], &routes_path).await }
    });
    sleep(Duration::from_millis(50)).await;

    let token = sign_target_token(secret, target_addr, 17);
    let desired = desired_state(
        &provider,
        socks_addr,
        ingress_addr,
        public_addr,
        aggregator,
        &token,
        ingress_auth,
    );
    let mut daemon = PathDaemon::new_with_runtime_root(&status_path, &runtime_root);
    daemon.apply(desired).await.unwrap();

    let mut acknowledged = HashSet::new();
    for _ in 0..2 {
        acknowledged.insert(
            timeout(Duration::from_secs(2), route_rx.recv())
                .await
                .expect("route acknowledgement timeout")
                .expect("route observer closed"),
        );
    }
    assert_eq!(HashSet::from([1_004_511, 1_004_531]), acknowledged);

    let status: serde_json::Value =
        serde_json::from_slice(&fs::read(&status_path).unwrap()).unwrap();
    assert_eq!(1, status["forwards"].as_object().unwrap().len());
    assert_eq!("ready", status["forwards"]["17"]["listener"]);
    assert!(std::net::TcpListener::bind(public_addr).is_err());
    let status_text = serde_json::to_string(&status).unwrap();
    assert!(!status_text.contains(ingress_auth));

    let mut client = TcpStream::connect(public_addr).await.unwrap();
    client.write_all(&upload).await.unwrap();
    client.shutdown().await.unwrap();
    let mut received_reverse = Vec::new();
    timeout(
        Duration::from_secs(15),
        client.read_to_end(&mut received_reverse),
    )
    .await
    .expect("reverse read timeout")
    .unwrap();
    assert_eq!(
        reverse, received_reverse,
        "reverse must arrive once and in order"
    );
    target_task.await.unwrap();
    assert_eq!(1, target_connections.load(Ordering::SeqCst));

    daemon.shutdown().await.unwrap();
    server_task.abort();
    ingress_task.abort();
    ingress_backend_task.abort();
    socks_task.abort();
    let _ = fs::remove_dir_all(root);
}
