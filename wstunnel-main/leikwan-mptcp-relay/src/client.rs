/// Client relay: listen TCP/UDP locally, connect MPTCP to sidecar, relay bidirectionally.
use crate::config::ClientConfig;
use crate::mptcp::{self, SocketConfig};
#[cfg(target_os = "linux")]
use crate::splice;
use crate::udp_frame;

use anyhow::Result;
use log::{error, info, warn, debug};
use std::collections::HashMap;
#[allow(unused_imports)]
use std::io::{Read, Write, BufWriter};
use std::net::{SocketAddr, TcpListener, TcpStream, ToSocketAddrs, UdpSocket};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::mpsc;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

/// Run the client TCP relay (takes ownership of config).
#[allow(dead_code)]
pub fn run_client(config: ClientConfig) -> Result<()> {
    run_client_arc(Arc::new(config))
}

/// Run the client relay (shared config).
pub fn run_client_arc(config: Arc<ClientConfig>) -> Result<()> {
    let wants_tcp = transport_includes_tcp(&config.transport);
    let wants_udp = transport_includes_udp(&config.transport);

    if !wants_tcp && !wants_udp {
        return Err(anyhow::anyhow!(
            "nothing to listen for; transport must include tcp and/or udp"
        ));
    }

    if wants_udp {
        let cfg = Arc::clone(&config);
        if wants_tcp {
            std::thread::spawn(move || {
                if let Err(e) = run_udp_client(cfg) {
                    error!("UDP client relay failed: {}", e);
                }
            });
        } else {
            return run_udp_client(cfg);
        }
    }

    if wants_tcp {
        return run_tcp_client_arc(config);
    }

    Ok(())
}

fn run_tcp_client_arc(config: Arc<ClientConfig>) -> Result<()> {
    let listen_addr = listen_addr(&config)?;
    let listener = TcpListener::bind(listen_addr)?;
    info!(
        "client relay listening on {} (forward_id={})",
        listen_addr, config.forward_id
    );

    for stream in listener.incoming() {
        match stream {
            Ok(local_stream) => {
                let cfg = Arc::clone(&config);
                std::thread::spawn(move || {
                    if let Err(e) = handle_client_connection(local_stream, &cfg) {
                        error!("client connection error: {}", e);
                    }
                });
            }
            Err(e) => {
                error!("accept error: {}", e);
            }
        }
    }

    Ok(())
}

fn listen_addr(config: &ClientConfig) -> Result<SocketAddr> {
    let host = config.listen_host.trim();
    let host = if host.is_empty() { "0.0.0.0" } else { host };
    Ok(format!("{}:{}", host, config.listen_port).parse()?)
}

fn normalized_transport(value: &str) -> String {
    value.trim().to_ascii_lowercase()
}

fn transport_includes_tcp(value: &str) -> bool {
    matches!(normalized_transport(value).as_str(), "tcp" | "tcp+udp")
}

fn transport_includes_udp(value: &str) -> bool {
    matches!(normalized_transport(value).as_str(), "udp" | "tcp+udp")
}

fn handle_client_connection(local_stream: TcpStream, config: &ClientConfig) -> Result<()> {
    let peer = local_stream
        .peer_addr()
        .map(|a| a.to_string())
        .unwrap_or_else(|_| "unknown".to_string());
    info!("accepted local connection from {}", peer);

    // Bond mode: distribute across multiple bound TCP legs.
    if config.bond_mode.contains("tcp") {
        let legs = crate::bond::derive_bond_legs_from_config(config);
        if !legs.is_empty() {
            return crate::bond::bond_relay_tcp(local_stream, &legs, config);
        }
        warn!("bond mode requested but no legs were available, falling back to MPTCP");
    }

    let mut remote_stream = open_tcp_stream_to_remote(config, "tcp")?;
    if slave_preconnect_enabled(config) {
        info!("connected plain TCP to slave pre-connect relay for tcp");
    } else {
        send_protocol_handshake(&mut remote_stream, config, "tcp", &config.tcp_session_token)?;
    }

    // Bidirectional relay using splice on Linux, fallback on other platforms.
    relay_bidirectional(local_stream, remote_stream)?;

    Ok(())
}

fn slave_preconnect_enabled(config: &ClientConfig) -> bool {
    !config.slave_relay_host.trim().is_empty() && config.slave_relay_port > 0
}

fn remote_endpoint_for(config: &ClientConfig) -> (&str, u16) {
    remote_endpoint_for_protocol(config, "tcp")
}

fn remote_endpoint_for_protocol<'a>(config: &'a ClientConfig, protocol_label: &str) -> (&'a str, u16) {
    if slave_preconnect_enabled(config) {
        if protocol_label == "udp" && config.slave_uot_relay_port > 0 {
            let host = if config.slave_uot_relay_host.trim().is_empty() {
                config.slave_relay_host.trim()
            } else {
                config.slave_uot_relay_host.trim()
            };
            return (host, config.slave_uot_relay_port);
        }
        (config.slave_relay_host.trim(), config.slave_relay_port)
    } else {
        (config.remote_host.trim(), config.remote_port)
    }
}

fn open_tcp_stream_to_remote(config: &ClientConfig, protocol_label: &str) -> Result<TcpStream> {
    let (host, port) = remote_endpoint_for_protocol(config, protocol_label);
    let remote_addr: SocketAddr = format!("{}:{}", host, port)
        .to_socket_addrs()?
        .next()
        .ok_or_else(|| anyhow::anyhow!("failed to resolve remote address"))?;

    if slave_preconnect_enabled(config) {
        let stream = TcpStream::connect(remote_addr)?;
        info!("connected {} to slave relay {}", protocol_label, remote_addr);
        return Ok(stream);
    }

    let sock_config = SocketConfig {
        congestion_control: config.tcp_congestion_control.clone(),
        buffer_bytes: config.socket_buffer_bytes,
        allow_tcp_fallback: true,
    };
    let stream = mptcp::mptcp_connect(remote_addr, &sock_config)?;
    info!("connected {} MPTCP to {}", protocol_label, remote_addr);
    Ok(stream)
}

fn send_protocol_handshake(
    stream: &mut TcpStream,
    config: &ClientConfig,
    protocol: &str,
    session_token: &str,
) -> Result<()> {
    let handshake = serde_json::json!({
        "protocol": protocol,
        "session_token": session_token,
        "forward_id": config.forward_id,
    });
    let handshake_bytes = serde_json::to_vec(&handshake)?;
    let len_prefix = (handshake_bytes.len() as u32).to_be_bytes();
    stream.write_all(&len_prefix)?;
    stream.write_all(&handshake_bytes)?;
    stream.flush()?;
    info!("sent {} handshake ({} bytes)", protocol, handshake_bytes.len());
    Ok(())
}

/// Splice-based bidirectional relay.
#[cfg(target_os = "linux")]
fn relay_bidirectional(local: TcpStream, mptcp: TcpStream) -> Result<()> {
    use std::os::unix::io::AsRawFd;

    let local_fd = local.as_raw_fd();
    let mptcp_fd = mptcp.as_raw_fd();

    let bytes_in = Arc::new(AtomicU64::new(0));
    let bytes_out = Arc::new(AtomicU64::new(0));

    // Forward direction: local -> mptcp
    let (fwd_pipe_rd, fwd_pipe_wr) = splice::create_pipe()?;
    // Backward direction: mptcp -> local
    let (bwd_pipe_rd, bwd_pipe_wr) = splice::create_pipe()?;

    // Set fds to blocking for the splice loop.
    unsafe {
        let flags = libc::fcntl(local_fd, libc::F_GETFL);
        libc::fcntl(local_fd, libc::F_SETFL, flags & !libc::O_NONBLOCK);
        let flags = libc::fcntl(mptcp_fd, libc::F_GETFL);
        libc::fcntl(mptcp_fd, libc::F_SETFL, flags & !libc::O_NONBLOCK);
    }

    let bytes_out_clone = Arc::clone(&bytes_out);

    // Spawn forward direction in a separate thread.
    let local_fd_fwd = local_fd;
    let mptcp_fd_fwd = mptcp_fd;
    let fwd_handle = std::thread::spawn(move || {
        let mut total: u64 = 0;
        loop {
            match splice::splice_once(local_fd_fwd, fwd_pipe_wr, fwd_pipe_rd, mptcp_fd_fwd, splice::SPLICE_MAX_BYTES)
            {
                Ok(0) => break,     // EOF
                Ok(n) => total += n as u64,
                Err(e) => {
                    if e.kind() != std::io::ErrorKind::BrokenPipe
                        && e.kind() != std::io::ErrorKind::ConnectionReset
                    {
                        error!("forward splice error: {}", e);
                    }
                    break;
                }
            }
        }
        bytes_out_clone.store(total, Ordering::Relaxed);
        // Shutdown the write side of mptcp to signal EOF.
        unsafe { libc::shutdown(mptcp_fd_fwd, libc::SHUT_WR); }
        splice::close_fd(fwd_pipe_rd);
        splice::close_fd(fwd_pipe_wr);
    });

    // Backward direction in the current thread: mptcp -> local
    let mut total_in: u64 = 0;
    loop {
        match splice::splice_once(mptcp_fd, bwd_pipe_wr, bwd_pipe_rd, local_fd, splice::SPLICE_MAX_BYTES) {
            Ok(0) => break,
            Ok(n) => total_in += n as u64,
            Err(e) => {
                if e.kind() != std::io::ErrorKind::BrokenPipe
                    && e.kind() != std::io::ErrorKind::ConnectionReset
                {
                    error!("backward splice error: {}", e);
                }
                break;
            }
        }
    }
    bytes_in.store(total_in, Ordering::Relaxed);

    // Shutdown local write to signal EOF to the client.
    unsafe { libc::shutdown(local_fd, libc::SHUT_WR); }
    splice::close_fd(bwd_pipe_rd);
    splice::close_fd(bwd_pipe_wr);

    let _ = fwd_handle.join();

    info!(
        "relay done: in={} bytes, out={} bytes",
        bytes_in.load(Ordering::Relaxed),
        bytes_out.load(Ordering::Relaxed),
    );

    // Drop the TcpStreams — this closes the fds.
    drop(local);
    drop(mptcp);
    Ok(())
}

/// Fallback relay for non-Linux platforms using std Read/Write.
#[cfg(not(target_os = "linux"))]
fn relay_bidirectional(local: TcpStream, mptcp: TcpStream) -> Result<()> {
    let bytes_in = Arc::new(AtomicU64::new(0));
    let bytes_out = Arc::new(AtomicU64::new(0));

    let mut local_rd = local.try_clone()?;
    let mut local_wr = local.try_clone()?;
    let mut mptcp_rd = mptcp.try_clone()?;
    let mut mptcp_wr = mptcp.try_clone()?;

    let bytes_out_clone = Arc::clone(&bytes_out);

    let fwd_handle = std::thread::spawn(move || {
        let mut buf = vec![0u8; 65536];
        let mut total: u64 = 0;
        loop {
            match local_rd.read(&mut buf) {
                Ok(0) => break,
                Ok(n) => {
                    if mptcp_wr.write_all(&buf[..n]).is_err() {
                        break;
                    }
                    total += n as u64;
                }
                Err(_) => break,
            }
        }
        bytes_out_clone.store(total, Ordering::Relaxed);
        let _ = mptcp_wr.shutdown(std::net::Shutdown::Write);
    });

    let mut buf = vec![0u8; 65536];
    let mut total_in: u64 = 0;
    loop {
        match mptcp_rd.read(&mut buf) {
            Ok(0) => break,
            Ok(n) => {
                if local_wr.write_all(&buf[..n]).is_err() {
                    break;
                }
                total_in += n as u64;
            }
            Err(_) => break,
        }
    }
    bytes_in.store(total_in, Ordering::Relaxed);
    let _ = local_wr.shutdown(std::net::Shutdown::Write);

    let _ = fwd_handle.join();

    info!(
        "relay done: in={} bytes, out={} bytes",
        bytes_in.load(Ordering::Relaxed),
        bytes_out.load(Ordering::Relaxed),
    );

    Ok(())
}

// ---------------------------------------------------------------------------
// UDP relay: per-source-address association tracking over MPTCP
// ---------------------------------------------------------------------------

struct UdpAssociation {
    /// Channel sender: main recv loop pushes datagrams here without blocking on I/O.
    sender: mpsc::SyncSender<Vec<u8>>,
    last_seen: Instant,
    closed: bool,
}

struct UdpRelayState {
    associations: Mutex<HashMap<SocketAddr, UdpAssociation>>,
}

/// Run the client-side UDP relay.
///
/// Listens on `listen_port` for UDP datagrams. For each unique source address,
/// opens an MPTCP stream to the remote sidecar with a `"udp"` protocol handshake.
/// Datagrams are length-framed inside the MPTCP stream in both directions.
pub fn run_udp_client(config: Arc<ClientConfig>) -> Result<()> {
    let listen_addr = listen_addr(&config)?;
    let udp_sock = UdpSocket::bind(listen_addr)?;

    // Set large UDP socket buffers to absorb bursts while MPTCP writes block.
    #[cfg(target_os = "linux")]
    {
        use std::os::unix::io::AsRawFd;
        mptcp::tune_udp_fd(udp_sock.as_raw_fd(), config.socket_buffer_bytes);
    }

    info!(
        "UDP client relay listening on {} (forward_id={})",
        listen_addr, config.forward_id
    );

    let state = Arc::new(UdpRelayState {
        associations: Mutex::new(HashMap::new()),
    });

    // Spawn idle-association reaper.
    {
        let state = Arc::clone(&state);
        let idle_timeout = Duration::from_secs(config.relay_idle_timeout_seconds);
        std::thread::spawn(move || reap_idle_associations(state, idle_timeout));
    }

    let mut recv_buf = vec![0u8; 65535];

    loop {
        let (n, src_addr) = match udp_sock.recv_from(&mut recv_buf) {
            Ok(result) => result,
            Err(e) => {
                error!("UDP recv error: {}", e);
                continue;
            }
        };
        let payload = recv_buf[..n].to_vec();

        // Try to send to existing association via channel (non-blocking).
        let send_result = {
            let mut assocs = state.associations.lock().unwrap();
            match assocs.get_mut(&src_addr) {
                Some(assoc) if !assoc.closed => {
                    assoc.last_seen = Instant::now();
                    match assoc.sender.try_send(payload.clone()) {
                        Ok(()) => Some(true),
                        Err(mpsc::TrySendError::Full(_)) => {
                            // Channel full — drop the datagram (better than blocking recv).
                            debug!("UDP write channel full for {}, dropping datagram", src_addr);
                            Some(true)
                        }
                        Err(mpsc::TrySendError::Disconnected(_)) => {
                            // Writer thread died.
                            assocs.remove(&src_addr);
                            None
                        }
                    }
                }
                Some(_) => {
                    assocs.remove(&src_addr);
                    None
                }
                None => None,
            }
        };

        if send_result.is_none() {
            // Need a new association.
            match create_udp_association(
                src_addr,
                &config,
                Arc::clone(&state),
                udp_sock.try_clone()?,
            ) {
                Ok(sender) => {
                    if sender.try_send(payload).is_err() {
                        warn!("failed to enqueue first datagram for {}", src_addr);
                        let mut assocs = state.associations.lock().unwrap();
                        if let Some(a) = assocs.get_mut(&src_addr) {
                            a.closed = true;
                        }
                    }
                }
                Err(e) => {
                    warn!("failed to create UDP association for {}: {}", src_addr, e);
                }
            }
        }
    }
}

/// Depth of the per-association write channel. At ~1500 bytes/datagram,
/// 2048 slots ≈ 3 MB buffer — enough for ~20ms at 1 Gbps.
const UDP_CHANNEL_DEPTH: usize = 2048;

/// Maximum datagrams to batch before flushing the BufWriter.
const UDP_FLUSH_BATCH: usize = 64;

/// Open MPTCP stream, send UDP handshake, spawn reader + writer threads.
fn create_udp_association(
    client_addr: SocketAddr,
    config: &ClientConfig,
    state: Arc<UdpRelayState>,
    local_udp_sock: UdpSocket,
) -> Result<mpsc::SyncSender<Vec<u8>>> {
    let mut remote_stream = open_tcp_stream_to_remote(config, "udp")?;
    if !slave_preconnect_enabled(config) {
        send_protocol_handshake(&mut remote_stream, config, "udp", &config.udp_session_token)?;
    }
    let (remote_host, remote_port) = remote_endpoint_for_protocol(config, "udp");
    info!("created UDP association for {} -> {}:{}", client_addr, remote_host, remote_port);

    let stream_rd = remote_stream.try_clone()?;
    let stream_wr = remote_stream;

    // Bounded channel: main recv loop → writer thread (non-blocking send).
    let (tx, rx) = mpsc::sync_channel::<Vec<u8>>(UDP_CHANNEL_DEPTH);

    {
        let mut assocs = state.associations.lock().unwrap();
        assocs.insert(client_addr, UdpAssociation {
            sender: tx.clone(),
            last_seen: Instant::now(),
            closed: false,
        });
    }

    // Spawn writer thread: drains channel → BufWriter → MPTCP stream.
    {
        let state_w = Arc::clone(&state);
        std::thread::spawn(move || {
            udp_writer(rx, stream_wr, client_addr, state_w);
        });
    }

    // Spawn reader: MPTCP stream → UDP sendto client.
    std::thread::spawn(move || {
        udp_reader(stream_rd, local_udp_sock, client_addr, state);
    });

    Ok(tx)
}

/// Writer thread: drains datagrams from channel, writes length-framed to
/// MPTCP stream via BufWriter. Flushes every `UDP_FLUSH_BATCH` datagrams
/// or when the channel is temporarily empty — this batches small writes
/// and avoids per-packet flush overhead.
fn udp_writer(
    rx: mpsc::Receiver<Vec<u8>>,
    stream: TcpStream,
    client_addr: SocketAddr,
    state: Arc<UdpRelayState>,
) {
    // 256 KB BufWriter — large enough to hold ~170 full-size (1500B) datagrams.
    let mut writer = BufWriter::with_capacity(256 * 1024, stream);

    loop {
        // Block on first datagram (saves CPU when idle).
        let first = match rx.recv() {
            Ok(data) => data,
            Err(_) => break, // channel closed
        };

        // Write the first datagram.
        if udp_frame::write_framed_no_flush(&mut writer, &first).is_err() {
            break;
        }
        let mut batch_count: usize = 1;

        // Drain any queued datagrams without blocking.
        while batch_count < UDP_FLUSH_BATCH {
            match rx.try_recv() {
                Ok(data) => {
                    if udp_frame::write_framed_no_flush(&mut writer, &data).is_err() {
                        // Mark closed and exit.
                        let mut assocs = state.associations.lock().unwrap();
                        if let Some(a) = assocs.get_mut(&client_addr) {
                            a.closed = true;
                        }
                        return;
                    }
                    batch_count += 1;
                }
                Err(mpsc::TryRecvError::Empty) => break,
                Err(mpsc::TryRecvError::Disconnected) => {
                    // Flush remaining then exit.
                    let _ = writer.flush();
                    return;
                }
            }
        }

        // Flush the batch.
        if writer.flush().is_err() {
            break;
        }
    }

    let mut assocs = state.associations.lock().unwrap();
    if let Some(a) = assocs.get_mut(&client_addr) {
        a.closed = true;
    }
}

/// Reader thread: reads length-framed datagrams from MPTCP, sends to local client.
fn udp_reader(
    mut stream_rd: TcpStream,
    udp_sock: UdpSocket,
    client_addr: SocketAddr,
    state: Arc<UdpRelayState>,
) {
    loop {
        match udp_frame::read_framed(&mut stream_rd) {
            Ok(payload) if payload.is_empty() => {
                debug!("UDP reader for {} got EOF", client_addr);
                break;
            }
            Ok(payload) => {
                if udp_sock.send_to(&payload, client_addr).is_err() {
                    break;
                }
            }
            Err(e) => {
                debug!("UDP reader for {} error: {}", client_addr, e);
                break;
            }
        }
    }
    let mut assocs = state.associations.lock().unwrap();
    assocs.remove(&client_addr);
}

/// Reaper: every 5s, remove associations idle > timeout.
/// Dropping the association drops the SyncSender, which disconnects the
/// channel and causes the writer thread to exit gracefully.
fn reap_idle_associations(state: Arc<UdpRelayState>, idle_timeout: Duration) {
    loop {
        std::thread::sleep(Duration::from_secs(5));
        let now = Instant::now();
        let mut assocs = state.associations.lock().unwrap();
        let expired: Vec<SocketAddr> = assocs
            .iter()
            .filter(|(_, a)| now.duration_since(a.last_seen) > idle_timeout)
            .map(|(addr, _)| *addr)
            .collect();
        for addr in &expired {
            if let Some(_assoc) = assocs.remove(addr) {
                info!("reaped idle UDP association for {}", addr);
                // Dropping _assoc.sender disconnects the channel → writer thread exits.
            }
        }
    }
}


#[cfg(test)]
mod tests {
    use super::*;

    fn parse_config(transport: &str) -> ClientConfig {
        serde_json::from_value(serde_json::json!({
            "forward_id": 24,
            "listen_host": "0.0.0.0",
            "listen_port": 11023,
            "transport": transport,
            "remote_host": "127.0.0.1",
            "remote_port": 14969
        })).expect("client config")
    }

    #[test]
    fn listener_address_uses_configured_listen_host() {
        let config = parse_config("tcp");

        assert_eq!(
            "0.0.0.0:11023".parse::<SocketAddr>().unwrap(),
            listen_addr(&config).expect("listen addr")
        );
    }


    #[test]
    fn slave_preconnect_endpoint_overrides_remote_for_tcp_and_udp() {
        let mut config = parse_config("tcp+udp");
        config.remote_host = "10.203.201.135".to_string();
        config.remote_port = 14969;
        config.slave_relay_host = "10.203.201.134".to_string();
        config.slave_relay_port = 8076;
        config.slave_uot_relay_port = 8077;

        assert!(slave_preconnect_enabled(&config));
        assert_eq!(("10.203.201.134", 8076), remote_endpoint_for(&config));
        assert_eq!(("10.203.201.134", 8077), remote_endpoint_for_protocol(&config, "udp"));
    }

    #[test]
    fn transport_helpers_enable_tcp_and_udp_for_tcp_udp_mode() {
        assert!(transport_includes_tcp("tcp+udp"));
        assert!(transport_includes_udp("tcp+udp"));
        assert!(transport_includes_tcp("tcp"));
        assert!(!transport_includes_udp("tcp"));
        assert!(!transport_includes_tcp("udp"));
        assert!(transport_includes_udp("udp"));
    }
}
