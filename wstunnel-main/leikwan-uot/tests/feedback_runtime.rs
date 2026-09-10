use std::net::{SocketAddr, TcpListener as StdTcpListener, UdpSocket as StdUdpSocket};
use std::path::PathBuf;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use base64::Engine;
use hmac::{Hmac, Mac};
use leikwan_uot::config::{
    ClientConfig, ClientLegConfig, DeliveryMode, SchedulerConfig, SchedulerMode, SidecarConfig,
};
use sha2::Sha256;
use tokio::net::UdpSocket;

fn unused_tcp_port() -> u16 {
    StdTcpListener::bind("127.0.0.1:0")
        .unwrap()
        .local_addr()
        .unwrap()
        .port()
}

fn unused_udp_port() -> u16 {
    StdUdpSocket::bind("127.0.0.1:0")
        .unwrap()
        .local_addr()
        .unwrap()
        .port()
}

fn temp_stats(name: &str) -> PathBuf {
    let nonce = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_nanos();
    std::env::temp_dir().join(format!(
        "leikwan-uot-{name}-{}-{nonce}.json",
        std::process::id()
    ))
}

fn session_token(secret: &str, target: SocketAddr) -> String {
    let payload = serde_json::to_vec(&serde_json::json!({
        "forward_id": 3,
        "target_protocol": "udp",
        "target_host": target.ip().to_string(),
        "target_port": target.port(),
        "expires_at": 0,
    }))
    .unwrap();
    let mut mac = Hmac::<Sha256>::new_from_slice(secret.as_bytes()).unwrap();
    mac.update(&payload);
    let signature = mac.finalize().into_bytes();
    format!(
        "{}.{}",
        base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(payload),
        base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(signature),
    )
}

fn feedback_scheduler(mode: SchedulerMode) -> SchedulerConfig {
    SchedulerConfig {
        scheduler_mode: mode,
        aggregate_rate_bps: 100_000_000,
        ..SchedulerConfig::default()
    }
}

async fn wait_for_stats(path: &PathBuf) -> serde_json::Value {
    for _ in 0..40 {
        if let Ok(raw) = tokio::fs::read(path).await {
            if let Ok(value) = serde_json::from_slice(&raw) {
                return value;
            }
        }
        tokio::time::sleep(Duration::from_millis(50)).await;
    }
    panic!("stats were not written to {}", path.display());
}

async fn run_round_trip(client_mode: SchedulerMode) -> (serde_json::Value, serde_json::Value) {
    let echo = UdpSocket::bind("127.0.0.1:0").await.unwrap();
    let echo_addr = echo.local_addr().unwrap();
    let echo_task = tokio::spawn(async move {
        let mut buffer = [0_u8; 2048];
        loop {
            let (length, peer) = echo.recv_from(&mut buffer).await.unwrap();
            echo.send_to(&buffer[..length], peer).await.unwrap();
        }
    });

    let secret = "feedback-runtime-secret";
    let sidecar_port = unused_tcp_port();
    let client_port = unused_udp_port();
    let sidecar_stats = temp_stats("sidecar");
    let client_stats = temp_stats("client");
    let sidecar = SidecarConfig {
        listen_host: "127.0.0.1".to_string(),
        listen_port: sidecar_port,
        token_secret: secret.to_string(),
        allowed_forward_ids: vec![3],
        reorder_window_packets: 64,
        reorder_window_bytes: 1_048_576,
        reorder_timeout_ms: 10,
        delivery_mode: DeliveryMode::Unordered,
        wireguard_aware: true,
        max_leg_queue_packets: 64,
        max_leg_queue_bytes: 1_048_576,
        max_datagram_bytes: 65_535,
        udp_socket_buffer_bytes: 1_048_576,
        stats_path: sidecar_stats.to_string_lossy().into_owned(),
        session_idle_timeout_ms: 30_000,
        scheduler: feedback_scheduler(SchedulerMode::FeedbackDrr),
    };
    let client = ClientConfig {
        forward_id: 3,
        listen_udp: format!("127.0.0.1:{client_port}"),
        route_key: "runtime-test".to_string(),
        session_token: session_token(secret, echo_addr),
        reorder_window_packets: 64,
        reorder_window_bytes: 1_048_576,
        reorder_timeout_ms: 10,
        delivery_mode: DeliveryMode::Unordered,
        wireguard_aware: true,
        max_leg_queue_packets: 64,
        max_leg_queue_bytes: 1_048_576,
        max_datagram_bytes: 65_535,
        udp_socket_buffer_bytes: 1_048_576,
        legs: vec![ClientLegConfig {
            endpoint_id: 1,
            host: "127.0.0.1".to_string(),
            port: sidecar_port,
            auth_token: "unused-direct-token".to_string(),
            dynamic_priority: 0,
            health_role: "healthy".to_string(),
            weight: 1,
            path_group: 0,
            route_key: None,
            direct_tcp: true,
            socks5_proxy: None,
        }],
        stats_path: client_stats.to_string_lossy().into_owned(),
        session_idle_timeout_ms: 30_000,
        scheduler: feedback_scheduler(client_mode),
    };

    let sidecar_task = tokio::spawn(leikwan_uot::sidecar_runtime::run(sidecar));
    tokio::time::sleep(Duration::from_millis(50)).await;
    let client_task = tokio::spawn(leikwan_uot::client_runtime::run(client));
    tokio::time::sleep(Duration::from_millis(250)).await;

    let app = UdpSocket::bind("127.0.0.1:0").await.unwrap();
    app.send_to(b"round-trip", ("127.0.0.1", client_port))
        .await
        .unwrap();
    let mut response = [0_u8; 64];
    let (length, _) =
        match tokio::time::timeout(Duration::from_secs(3), app.recv_from(&mut response)).await {
            Ok(result) => result.unwrap(),
            Err(_) => {
                let client_debug = tokio::fs::read_to_string(&client_stats)
                    .await
                    .unwrap_or_else(|error| format!("client stats unavailable: {error}"));
                let sidecar_debug = tokio::fs::read_to_string(&sidecar_stats)
                    .await
                    .unwrap_or_else(|error| format!("sidecar stats unavailable: {error}"));
                panic!("round-trip timeout\nclient={client_debug}\nsidecar={sidecar_debug}");
            }
        };
    assert_eq!(&response[..length], b"round-trip");

    tokio::time::sleep(Duration::from_millis(1_100)).await;
    let client_value = wait_for_stats(&client_stats).await;
    let sidecar_value = wait_for_stats(&sidecar_stats).await;
    client_task.abort();
    sidecar_task.abort();
    echo_task.abort();
    let _ = std::fs::remove_file(client_stats);
    let _ = std::fs::remove_file(sidecar_stats);
    (client_value, sidecar_value)
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn matching_native_runtimes_negotiate_feedback_and_pass_both_directions() {
    let (client, sidecar) = run_round_trip(SchedulerMode::FeedbackDrr).await;
    assert_eq!(client["scheduler_mode"], "feedback_drr");
    assert_eq!(client["scheduler"]["feedback_capable_active_legs"], 1);
    assert_eq!(client["scheduler"]["legacy_fallback_active_legs"], 0);
    assert_eq!(sidecar["scheduler"]["feedback_capable_active_legs"], 1);
    assert_eq!(sidecar["scheduler"]["legacy_fallback_active_legs"], 0);
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn legacy_client_to_new_sidecar_passes_data_without_unknown_control_frames() {
    let (client, sidecar) = run_round_trip(SchedulerMode::Legacy).await;
    assert_eq!(client["scheduler_mode"], "legacy");
    assert!(client["scheduler"].is_null());
    assert_eq!(sidecar["scheduler"]["feedback_capable_active_legs"], 0);
    assert_eq!(sidecar["scheduler"]["legacy_fallback_active_legs"], 1);
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn first_wins_delivers_one_datagram_and_releases_both_leg_ledgers() {
    let echo = UdpSocket::bind("127.0.0.1:0").await.unwrap();
    let echo_addr = echo.local_addr().unwrap();
    let echo_packets = Arc::new(AtomicUsize::new(0));
    let echo_counter = echo_packets.clone();
    let echo_task = tokio::spawn(async move {
        let mut buffer = [0_u8; 2048];
        loop {
            let (length, peer) = echo.recv_from(&mut buffer).await.unwrap();
            echo_counter.fetch_add(1, Ordering::Relaxed);
            echo.send_to(&buffer[..length], peer).await.unwrap();
        }
    });

    let secret = "first-wins-runtime-secret";
    let sidecar_port = unused_tcp_port();
    let client_port = unused_udp_port();
    let sidecar_stats = temp_stats("first-wins-sidecar");
    let client_stats = temp_stats("first-wins-client");
    let mut scheduler = feedback_scheduler(SchedulerMode::FeedbackDrr);
    scheduler.wireguard_latency_sensitive_max_bytes = 256;
    scheduler.wireguard_latency_sensitive_copies = 2;
    let sidecar = SidecarConfig {
        listen_host: "127.0.0.1".to_string(),
        listen_port: sidecar_port,
        token_secret: secret.to_string(),
        allowed_forward_ids: vec![3],
        reorder_window_packets: 64,
        reorder_window_bytes: 1_048_576,
        reorder_timeout_ms: 10,
        delivery_mode: DeliveryMode::Unordered,
        wireguard_aware: true,
        max_leg_queue_packets: 256,
        max_leg_queue_bytes: 1_048_576,
        max_datagram_bytes: 65_535,
        udp_socket_buffer_bytes: 1_048_576,
        stats_path: sidecar_stats.to_string_lossy().into_owned(),
        session_idle_timeout_ms: 30_000,
        scheduler: scheduler.clone(),
    };
    let client = ClientConfig {
        forward_id: 3,
        listen_udp: format!("127.0.0.1:{client_port}"),
        route_key: "first-wins-runtime-test".to_string(),
        session_token: session_token(secret, echo_addr),
        reorder_window_packets: 64,
        reorder_window_bytes: 1_048_576,
        reorder_timeout_ms: 10,
        delivery_mode: DeliveryMode::Unordered,
        wireguard_aware: true,
        max_leg_queue_packets: 256,
        max_leg_queue_bytes: 1_048_576,
        max_datagram_bytes: 65_535,
        udp_socket_buffer_bytes: 1_048_576,
        legs: (1..=2)
            .map(|endpoint_id| ClientLegConfig {
                endpoint_id,
                host: "127.0.0.1".to_string(),
                port: sidecar_port,
                auth_token: "unused-direct-token".to_string(),
                dynamic_priority: 0,
                health_role: "healthy".to_string(),
                weight: 1,
                path_group: endpoint_id,
                route_key: None,
                direct_tcp: true,
                socks5_proxy: None,
            })
            .collect(),
        stats_path: client_stats.to_string_lossy().into_owned(),
        session_idle_timeout_ms: 30_000,
        scheduler,
    };

    let sidecar_task = tokio::spawn(leikwan_uot::sidecar_runtime::run(sidecar));
    tokio::time::sleep(Duration::from_millis(50)).await;
    let client_task = tokio::spawn(leikwan_uot::client_runtime::run(client));
    tokio::time::sleep(Duration::from_millis(350)).await;
    let app = UdpSocket::bind("127.0.0.1:0").await.unwrap();
    let mut response = [0_u8; 2048];

    for sequence in 0_u32..32 {
        let mut payload = vec![0_u8; 512];
        payload[..4].copy_from_slice(&4_u32.to_le_bytes());
        payload[4..8].copy_from_slice(&sequence.to_le_bytes());
        app.send_to(&payload, ("127.0.0.1", client_port))
            .await
            .unwrap();
        let (length, _) =
            tokio::time::timeout(Duration::from_secs(3), app.recv_from(&mut response))
                .await
                .expect("warmup response")
                .unwrap();
        assert_eq!(&response[..length], payload);
    }
    tokio::time::sleep(Duration::from_millis(200)).await;

    for sequence in 32_u32..96 {
        let mut payload = vec![0_u8; 128];
        payload[..4].copy_from_slice(&4_u32.to_le_bytes());
        payload[4..8].copy_from_slice(&sequence.to_le_bytes());
        app.send_to(&payload, ("127.0.0.1", client_port))
            .await
            .unwrap();
        let (length, _) =
            tokio::time::timeout(Duration::from_secs(3), app.recv_from(&mut response))
                .await
                .expect("first-wins response")
                .unwrap();
        assert_eq!(&response[..length], payload);
    }
    assert!(
        tokio::time::timeout(Duration::from_millis(200), app.recv_from(&mut response))
            .await
            .is_err(),
        "the application must not receive a redundant copy"
    );

    tokio::time::sleep(Duration::from_millis(1_100)).await;
    let client_value = wait_for_stats(&client_stats).await;
    let sidecar_value = wait_for_stats(&sidecar_stats).await;
    assert_eq!(echo_packets.load(Ordering::Relaxed), 96);
    assert!(
        client_value["first_wins_duplicates_suppressed"]
            .as_u64()
            .unwrap()
            > 0
    );
    assert!(
        sidecar_value["first_wins_duplicates_suppressed"]
            .as_u64()
            .unwrap()
            > 0
    );
    assert_eq!(client_value["scheduler"]["outstanding_bytes"], 0);
    assert_eq!(sidecar_value["scheduler"]["outstanding_bytes"], 0);
    assert_eq!(client_value["scheduler"]["invalid_progress_ratio"], 0.0);
    assert_eq!(sidecar_value["scheduler"]["invalid_progress_ratio"], 0.0);

    client_task.abort();
    sidecar_task.abort();
    echo_task.abort();
    let _ = std::fs::remove_file(client_stats);
    let _ = std::fs::remove_file(sidecar_stats);
}
