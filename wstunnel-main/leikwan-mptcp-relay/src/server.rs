/// Server relay: listen MPTCP, verify session token, splice to target.
use crate::bond;
use crate::config::ServerConfig;
use crate::mptcp::{self, SocketConfig};
#[cfg(target_os = "linux")]
use crate::splice;
use crate::token;
use crate::udp_frame;

use anyhow::Result;
use log::{error, info, warn, debug};
use std::io::{Read, Write};
use std::net::{SocketAddr, TcpStream, ToSocketAddrs, UdpSocket};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;
use std::time::Duration;

/// Maximum handshake frame size (4-byte length prefix allows up to 4GB,
/// but we cap at 8192 for safety).
const MAX_HANDSHAKE_LEN: usize = 8192;

/// Timeout for reading the initial handshake. MPTCP subflow probes
/// (ADD_ADDR / MP_JOIN) open connections that carry no application data;
/// a short timeout lets us close them quickly.
const HANDSHAKE_TIMEOUT: Duration = Duration::from_secs(10);

/// Run the server relay.
pub fn run_server(config: ServerConfig) -> Result<()> {
    let listen_addr: SocketAddr = format!("{}:{}", config.listen_host, config.listen_port).parse()?;

    let sock_config = SocketConfig {
        congestion_control: config.tcp_congestion_control.clone(),
        buffer_bytes: config.socket_buffer_bytes,
        allow_tcp_fallback: config.allow_tcp_fallback,
    };

    let listener = mptcp::mptcp_listen(listen_addr, &sock_config)?;
    info!("server relay listening on {} (MPTCP)", listen_addr);

    let sock_config = Arc::new(sock_config);
    let config = Arc::new(config);

    // Bond session registry for multi-leg reassembly.
    let bond_registry = Arc::new(bond::BondSessionRegistry::new());

    for stream in listener.incoming() {
        match stream {
            Ok(peer_stream) => {
                let cfg = Arc::clone(&config);
                let registry = Arc::clone(&bond_registry);
                let sc = Arc::clone(&sock_config);
                std::thread::spawn(move || {
                    if let Err(e) = handle_server_connection(peer_stream, &cfg, &registry, &sc) {
                        warn!("server connection error: {}", e);
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

fn handle_server_connection(
    mut peer_stream: TcpStream,
    config: &ServerConfig,
    bond_registry: &Arc<bond::BondSessionRegistry>,
    sock_config: &SocketConfig,
) -> Result<()> {
    let peer = peer_stream
        .peer_addr()
        .map(|a| a.to_string())
        .unwrap_or_else(|_| "unknown".to_string());
    debug!("accepted connection from {}", peer);

    // Set a read timeout for the handshake phase.  MPTCP subflow probes
    // (kernel ADD_ADDR / MP_JOIN) open connections with no application data;
    // the timeout lets us close them quickly instead of blocking forever.
    peer_stream.set_read_timeout(Some(HANDSHAKE_TIMEOUT))?;

    // Read handshake: 4-byte big-endian length prefix + JSON payload.
    // Format matches Python client's send_handshake():
    //   {"protocol":"tcp","session_token":"...","forward_id":N}
    let mut len_buf = [0u8; 4];
    if let Err(e) = peer_stream.read_exact(&mut len_buf) {
        // Empty probe connections (MPTCP subflow probes) will fail here
        // with either WouldBlock/TimedOut or UnexpectedEof. Log at debug
        // level so we don't spam the logs.
        debug!("handshake read from {}: {} (likely MPTCP probe)", peer, e);
        return Ok(());
    }
    let handshake_len = u32::from_be_bytes(len_buf) as usize;
    info!("handshake from {}: length prefix = {} bytes", peer, handshake_len);

    if handshake_len == 0 || handshake_len > MAX_HANDSHAKE_LEN {
        return Err(anyhow::anyhow!(
            "invalid handshake length: {} (max {})",
            handshake_len,
            MAX_HANDSHAKE_LEN
        ));
    }

    let mut handshake_buf = vec![0u8; handshake_len];
    peer_stream.read_exact(&mut handshake_buf)?;

    let handshake: serde_json::Value = serde_json::from_slice(&handshake_buf)
        .map_err(|e| anyhow::anyhow!("invalid handshake JSON: {}", e))?;

    let token_str = handshake
        .get("session_token")
        .and_then(|v| v.as_str())
        .ok_or_else(|| anyhow::anyhow!("missing session_token in handshake"))?;

    // Verify token.
    let payload = token::verify_token(token_str, &config.token_secret)?;
    info!(
        "token verified: target={}:{} forward_id={} from={}",
        payload.target_host, payload.target_port, payload.forward_id, peer
    );

    // Clear the read timeout before entering relay mode.
    peer_stream.set_read_timeout(None)?;

    // Resolve target address.
    let target_addr: SocketAddr =
        format!("{}:{}", payload.target_host, payload.target_port)
            .to_socket_addrs()?
            .next()
            .ok_or_else(|| anyhow::anyhow!("failed to resolve target address"))?;

    // Check for bond session ID — if present, route to bond reassembly.
    if let Some(bond_id) = handshake.get("bond_session_id").and_then(|v| v.as_u64()) {
        let leg_priority = handshake
            .get("leg_priority")
            .and_then(|v| v.as_u64())
            .unwrap_or(10) as u32;
        let leg_direct = handshake
            .get("leg_direct")
            .and_then(|v| v.as_bool())
            .unwrap_or(false);
        info!("bond leg from {} for session {:016x}", peer, bond_id);
        return bond::handle_bond_leg(
            peer_stream,
            bond_id,
            target_addr,
            bond_registry,
            sock_config,
            leg_priority,
            leg_direct,
        );
    }

    // Branch on protocol.
    let protocol = handshake
        .get("protocol")
        .and_then(|v| v.as_str())
        .unwrap_or("tcp");

    match protocol {
        "tcp" => {
            let target_stream = TcpStream::connect(target_addr)?;
            #[cfg(target_os = "linux")]
            {
                use std::os::unix::io::AsRawFd;
                mptcp::tune_fd(target_stream.as_raw_fd(), sock_config);
            }
            info!("connected to target {} (tuned)", target_addr);
            relay_bidirectional(peer_stream, target_stream)?;
        }
        "udp" => {
            info!("UDP relay to target {}", target_addr);
            relay_udp(peer_stream, target_addr, config.socket_buffer_bytes)?;
        }
        other => {
            return Err(anyhow::anyhow!("unsupported protocol: {}", other));
        }
    }

    Ok(())
}

/// Splice-based bidirectional relay.
#[cfg(target_os = "linux")]
fn relay_bidirectional(peer: TcpStream, target: TcpStream) -> Result<()> {
    use std::os::unix::io::AsRawFd;

    let peer_fd = peer.as_raw_fd();
    let target_fd = target.as_raw_fd();

    let bytes_in = Arc::new(AtomicU64::new(0));
    let bytes_out = Arc::new(AtomicU64::new(0));

    let (fwd_pipe_rd, fwd_pipe_wr) = splice::create_pipe()?;
    let (bwd_pipe_rd, bwd_pipe_wr) = splice::create_pipe()?;

    // Set fds to blocking.
    unsafe {
        let flags = libc::fcntl(peer_fd, libc::F_GETFL);
        libc::fcntl(peer_fd, libc::F_SETFL, flags & !libc::O_NONBLOCK);
        let flags = libc::fcntl(target_fd, libc::F_GETFL);
        libc::fcntl(target_fd, libc::F_SETFL, flags & !libc::O_NONBLOCK);
    }

    let bytes_out_clone = Arc::clone(&bytes_out);

    // Forward: peer -> target
    let peer_fd_fwd = peer_fd;
    let target_fd_fwd = target_fd;
    let fwd_handle = std::thread::spawn(move || {
        let mut total: u64 = 0;
        loop {
            match splice::splice_once(peer_fd_fwd, fwd_pipe_wr, fwd_pipe_rd, target_fd_fwd, splice::SPLICE_MAX_BYTES)
            {
                Ok(0) => break,
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
        unsafe { libc::shutdown(target_fd_fwd, libc::SHUT_WR); }
        splice::close_fd(fwd_pipe_rd);
        splice::close_fd(fwd_pipe_wr);
    });

    // Backward: target -> peer
    let mut total_in: u64 = 0;
    loop {
        match splice::splice_once(target_fd, bwd_pipe_wr, bwd_pipe_rd, peer_fd, splice::SPLICE_MAX_BYTES) {
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
    unsafe { libc::shutdown(peer_fd, libc::SHUT_WR); }
    splice::close_fd(bwd_pipe_rd);
    splice::close_fd(bwd_pipe_wr);

    let _ = fwd_handle.join();

    info!(
        "relay done: in={} bytes, out={} bytes",
        bytes_in.load(Ordering::Relaxed),
        bytes_out.load(Ordering::Relaxed),
    );

    drop(peer);
    drop(target);
    Ok(())
}

/// Fallback relay for non-Linux platforms.
#[cfg(not(target_os = "linux"))]
fn relay_bidirectional(peer: TcpStream, target: TcpStream) -> Result<()> {
    let bytes_in = Arc::new(AtomicU64::new(0));
    let bytes_out = Arc::new(AtomicU64::new(0));

    let mut peer_rd = peer.try_clone()?;
    let mut peer_wr = peer.try_clone()?;
    let mut target_rd = target.try_clone()?;
    let mut target_wr = target.try_clone()?;

    let bytes_out_clone = Arc::clone(&bytes_out);

    let fwd_handle = std::thread::spawn(move || {
        let mut buf = vec![0u8; 256 * 1024];
        let mut total: u64 = 0;
        loop {
            match peer_rd.read(&mut buf) {
                Ok(0) => break,
                Ok(n) => {
                    if target_wr.write_all(&buf[..n]).is_err() {
                        break;
                    }
                    total += n as u64;
                }
                Err(_) => break,
            }
        }
        bytes_out_clone.store(total, Ordering::Relaxed);
        let _ = target_wr.shutdown(std::net::Shutdown::Write);
    });

    let mut buf = vec![0u8; 256 * 1024];
    let mut total_in: u64 = 0;
    loop {
        match target_rd.read(&mut buf) {
            Ok(0) => break,
            Ok(n) => {
                if peer_wr.write_all(&buf[..n]).is_err() {
                    break;
                }
                total_in += n as u64;
            }
            Err(_) => break,
        }
    }
    bytes_in.store(total_in, Ordering::Relaxed);
    let _ = peer_wr.shutdown(std::net::Shutdown::Write);

    let _ = fwd_handle.join();

    info!(
        "relay done: in={} bytes, out={} bytes",
        bytes_in.load(Ordering::Relaxed),
        bytes_out.load(Ordering::Relaxed),
    );

    Ok(())
}

/// UDP relay: read length-framed datagrams from MPTCP stream, send as raw UDP
/// to target; receive UDP responses, write back length-framed to stream.
fn relay_udp(peer_stream: TcpStream, target_addr: SocketAddr, #[allow(unused)] buffer_bytes: usize) -> Result<()> {
    let udp_sock = UdpSocket::bind("0.0.0.0:0")?;
    udp_sock.connect(target_addr)?;

    // Set large UDP socket buffers to absorb bursts.
    #[cfg(target_os = "linux")]
    {
        use std::os::unix::io::AsRawFd;
        crate::mptcp::tune_udp_fd(udp_sock.as_raw_fd(), buffer_bytes);
    }

    let bytes_in = Arc::new(AtomicU64::new(0));
    let bytes_out = Arc::new(AtomicU64::new(0));

    let mut stream_rd = peer_stream.try_clone()?;
    let stream_wr = peer_stream;
    let udp_send = udp_sock.try_clone()?;

    let bytes_in_fwd = Arc::clone(&bytes_in);
    let bytes_out_bwd = Arc::clone(&bytes_out);

    // Thread: MPTCP stream → UDP target
    let fwd_handle = std::thread::spawn(move || {
        let mut total: u64 = 0;
        loop {
            match udp_frame::read_framed(&mut stream_rd) {
                Ok(payload) if payload.is_empty() => break,
                Ok(payload) => {
                    total += payload.len() as u64;
                    if udp_send.send(&payload).is_err() {
                        break;
                    }
                }
                Err(_) => break,
            }
        }
        bytes_in_fwd.store(total, Ordering::Relaxed);
    });

    // Current thread: UDP target → MPTCP stream (with BufWriter batching)
    let mut writer = std::io::BufWriter::with_capacity(256 * 1024, stream_wr);
    let mut recv_buf = vec![0u8; 65535];
    let mut total_out: u64 = 0;
    let mut batch_count: usize = 0;

    // Set a short read timeout so we flush periodically even if no more
    // datagrams arrive (e.g., end of burst).
    udp_sock.set_read_timeout(Some(Duration::from_millis(2)))?;

    loop {
        match udp_sock.recv(&mut recv_buf) {
            Ok(n) if n > 0 => {
                if udp_frame::write_framed_no_flush(&mut writer, &recv_buf[..n]).is_err() {
                    break;
                }
                total_out += n as u64;
                batch_count += 1;
                if batch_count >= 64 {
                    if writer.flush().is_err() {
                        break;
                    }
                    batch_count = 0;
                }
            }
            Ok(_) => break, // n == 0, unlikely for UDP
            Err(ref e) if e.kind() == std::io::ErrorKind::WouldBlock
                        || e.kind() == std::io::ErrorKind::TimedOut => {
                // Timeout — flush any pending data.
                if batch_count > 0 {
                    if writer.flush().is_err() {
                        break;
                    }
                    batch_count = 0;
                }
            }
            Err(_) => break,
        }
    }
    // Flush remaining.
    let _ = writer.flush();
    bytes_out_bwd.store(total_out, Ordering::Relaxed);

    let stream_wr = match writer.into_inner() {
        Ok(s) => s,
        Err(e) => {
            let buf_writer = e.into_inner();
            // Cannot easily recover the inner TcpStream from the BufWriter error path
            // in stable Rust without consuming it. Just drop it — shutdown happens on drop.
            drop(buf_writer);
            let _ = fwd_handle.join();
            info!(
                "UDP relay done: in={} bytes, out={} bytes",
                bytes_in.load(Ordering::Relaxed),
                bytes_out.load(Ordering::Relaxed),
            );
            return Ok(());
        }
    };
    let _ = stream_wr.shutdown(std::net::Shutdown::Write);
    let _ = fwd_handle.join();

    info!(
        "UDP relay done: in={} bytes, out={} bytes",
        bytes_in.load(Ordering::Relaxed),
        bytes_out.load(Ordering::Relaxed),
    );

    Ok(())
}