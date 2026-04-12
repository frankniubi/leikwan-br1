/// Bond client: listen locally, distribute chunks across multiple TCP legs to
/// a bond server, reassemble replies.
///
/// Wire protocol (per leg):
///   Handshake (client→server):
///     4-byte BE length + JSON {"session_token":"...","protocol":"tcp"}
///   Data frames (bidirectional):
///     4-byte BE seq  |  4-byte BE length  |  payload bytes
///   EOF marker:
///     seq=u32::MAX   |  length=0
///
/// Scheduling: round-robin across connected legs, skipping legs that haven't
/// acked within retransmit_ms.  Chunks arriving out-of-order at the server
/// are buffered and delivered in-order to the target.

use anyhow::{anyhow, Result};
use log::{debug, error, info, warn};
use std::collections::HashMap;
use std::io::{Read, Write};
use std::net::{TcpListener, TcpStream, SocketAddr};
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

const FRAME_HEADER_LEN: usize = 8; // 4-byte seq + 4-byte len
const EOF_SEQ: u32 = u32::MAX;

pub struct ClientConfig {
    pub protocol: String,
    pub listen: String,
    pub legs: Vec<String>,
    pub session_token: String,
    pub chunk_size: usize,
    pub max_window_bytes: usize,
    pub retransmit_ms: u64,
    pub connect_timeout_ms: u64,
    pub active_leg_limit: usize,
    pub interactive_chunk_bytes: usize,
    pub latency_guard_bytes: usize,
    pub interactive_rtt_slack_ms: u64,
}

struct LegWriter {
    stream: TcpStream,
    addr: String,
    last_ack: Instant,
}

/// Shared state for reassembly of server→client data.
struct ReassemblyState {
    next_seq: u32,
    buf: HashMap<u32, Vec<u8>>,
    done: bool,
}

pub fn run_client(config: ClientConfig) -> Result<()> {
    let listen_addr: SocketAddr = config.listen.parse()
        .map_err(|e| anyhow!("bad listen addr '{}': {}", config.listen, e))?;

    let listener = TcpListener::bind(listen_addr)?;
    info!("bond client listening on {} (protocol={})", listen_addr, config.protocol);
    info!("legs: {:?}", config.legs);

    let config = Arc::new(config);

    for stream in listener.incoming() {
        match stream {
            Ok(local) => {
                let cfg = Arc::clone(&config);
                std::thread::spawn(move || {
                    if let Err(e) = handle_session(local, &cfg) {
                        error!("session error: {:#}", e);
                    }
                });
            }
            Err(e) => error!("accept error: {}", e),
        }
    }
    Ok(())
}

fn handle_session(local: TcpStream, config: &ClientConfig) -> Result<()> {
    let peer = local.peer_addr().map(|a| a.to_string()).unwrap_or_default();
    info!("new local connection from {}", peer);

    let timeout = Duration::from_millis(config.connect_timeout_ms);

    // Connect to all legs and send handshake.
    let mut legs: Vec<LegWriter> = Vec::new();
    for addr_str in &config.legs {
        let addr = parse_leg_addr(addr_str);
        match TcpStream::connect_timeout(&addr, timeout) {
            Ok(mut stream) => {
                stream.set_nodelay(true).ok();
                if let Err(e) = send_handshake(&mut stream, &config.session_token, &config.protocol) {
                    warn!("handshake to {} failed: {}", addr_str, e);
                    continue;
                }
                info!("leg connected: {}", addr_str);
                legs.push(LegWriter {
                    stream,
                    addr: addr_str.clone(),
                    last_ack: Instant::now(),
                });
            }
            Err(e) => {
                warn!("leg connect {} failed: {}", addr_str, e);
            }
        }
    }

    if legs.is_empty() {
        return Err(anyhow!("no legs connected"));
    }

    let active_limit = if config.active_leg_limit > 0 {
        config.active_leg_limit.min(legs.len())
    } else {
        legs.len()
    };
    info!("{} legs connected, active_limit={}", legs.len(), active_limit);

    // Clone leg streams for reading (server→client direction).
    let mut leg_readers: Vec<(String, TcpStream)> = Vec::new();
    for leg in &legs {
        let rd = leg.stream.try_clone()?;
        leg_readers.push((leg.addr.clone(), rd));
    }

    let legs = Arc::new(Mutex::new(legs));
    let shutdown = Arc::new(AtomicBool::new(false));
    let send_seq = Arc::new(AtomicU32::new(0));

    // Reassembly state for server→client.
    let reassembly = Arc::new(Mutex::new(ReassemblyState {
        next_seq: 0,
        buf: HashMap::new(),
        done: false,
    }));

    let local_rd = local.try_clone()?;
    let mut local_wr = local.try_clone()?;

    // ── Thread 1: local→legs (send direction) ─────────────
    let legs_send = Arc::clone(&legs);
    let shutdown_send = Arc::clone(&shutdown);
    let send_seq_clone = Arc::clone(&send_seq);
    let chunk_size = config.chunk_size;
    let retransmit_ms = config.retransmit_ms;

    let send_handle = std::thread::spawn(move || {
        let mut local_rd = local_rd;
        let mut buf = vec![0u8; chunk_size];
        let mut rr_idx: usize = 0;

        loop {
            if shutdown_send.load(Ordering::Relaxed) { break; }
            let n = match local_rd.read(&mut buf) {
                Ok(0) => {
                    // EOF from local app — send EOF marker to all legs.
                    let seq = send_seq_clone.fetch_add(1, Ordering::SeqCst);
                    let _ = send_eof_to_legs(&legs_send, seq);
                    break;
                }
                Ok(n) => n,
                Err(_) => break,
            };

            let seq = send_seq_clone.fetch_add(1, Ordering::SeqCst);
            let frame = encode_frame(seq, &buf[..n]);

            // Round-robin across legs.
            let mut legs_guard = legs_send.lock().unwrap();
            let leg_count = legs_guard.len();
            if leg_count == 0 { break; }

            let mut sent = false;
            for attempt in 0..leg_count {
                let idx = (rr_idx + attempt) % leg_count;
                if legs_guard[idx].stream.write_all(&frame).is_ok() {
                    legs_guard[idx].last_ack = Instant::now();
                    rr_idx = idx + 1;
                    sent = true;
                    break;
                } else {
                    warn!("leg {} write failed, trying next", legs_guard[idx].addr);
                }
            }
            drop(legs_guard);

            if !sent {
                error!("all legs failed to send seq={}", seq);
                break;
            }
        }

        shutdown_send.store(true, Ordering::Relaxed);
        debug!("send thread done");
    });

    // ── Thread 2+: legs→local (receive direction, one thread per leg) ──
    let recv_handles: Vec<_> = leg_readers.into_iter().map(|(addr, mut rd)| {
        let reassembly = Arc::clone(&reassembly);
        let shutdown = Arc::clone(&shutdown);
        std::thread::spawn(move || {
            loop {
                if shutdown.load(Ordering::Relaxed) { break; }
                match read_frame(&mut rd) {
                    Ok((seq, data)) => {
                        if seq == EOF_SEQ {
                            debug!("received EOF from leg {}", addr);
                            let mut state = reassembly.lock().unwrap();
                            state.done = true;
                            break;
                        }
                        let mut state = reassembly.lock().unwrap();
                        state.buf.insert(seq, data);
                    }
                    Err(_) => {
                        debug!("leg {} read done", addr);
                        break;
                    }
                }
            }
        })
    }).collect();

    // ── Main thread: drain reassembly buffer in-order to local ──
    loop {
        if shutdown.load(Ordering::Relaxed) {
            // Drain whatever is left.
            flush_reassembly(&reassembly, &mut local_wr);
            break;
        }

        let flushed = flush_reassembly(&reassembly, &mut local_wr);

        // Check if all receive threads signaled done.
        {
            let state = reassembly.lock().unwrap();
            if state.done {
                // Drain remaining.
                drop(state);
                flush_reassembly(&reassembly, &mut local_wr);
                break;
            }
        }

        if !flushed {
            std::thread::sleep(Duration::from_micros(200));
        }
    }

    let _ = local_wr.shutdown(std::net::Shutdown::Write);
    shutdown.store(true, Ordering::Relaxed);
    let _ = send_handle.join();
    for h in recv_handles { let _ = h.join(); }

    info!("session for {} done", peer);
    Ok(())
}

/// Flush as many in-order frames as possible from reassembly to local writer.
/// Returns true if at least one frame was flushed.
fn flush_reassembly(reassembly: &Arc<Mutex<ReassemblyState>>, writer: &mut TcpStream) -> bool {
    let mut state = reassembly.lock().unwrap();
    let mut flushed = false;
    loop {
        let seq = state.next_seq;
        if let Some(data) = state.buf.remove(&seq) {
            if writer.write_all(&data).is_err() {
                break;
            }
            state.next_seq += 1;
            flushed = true;
        } else {
            break;
        }
    }
    flushed
}

fn send_handshake(stream: &mut TcpStream, token: &str, protocol: &str) -> Result<()> {
    let hs = serde_json::json!({
        "session_token": token,
        "protocol": protocol,
    });
    let bytes = serde_json::to_vec(&hs)?;
    let len = (bytes.len() as u32).to_be_bytes();
    stream.write_all(&len)?;
    stream.write_all(&bytes)?;
    stream.flush()?;
    Ok(())
}

fn encode_frame(seq: u32, data: &[u8]) -> Vec<u8> {
    let mut frame = Vec::with_capacity(FRAME_HEADER_LEN + data.len());
    frame.extend_from_slice(&seq.to_be_bytes());
    frame.extend_from_slice(&(data.len() as u32).to_be_bytes());
    frame.extend_from_slice(data);
    frame
}

fn send_eof_to_legs(legs: &Arc<Mutex<Vec<LegWriter>>>, _seq: u32) -> Result<()> {
    let eof_frame = encode_frame(EOF_SEQ, &[]);
    let mut legs_guard = legs.lock().unwrap();
    for leg in legs_guard.iter_mut() {
        let _ = leg.stream.write_all(&eof_frame);
    }
    Ok(())
}

fn read_frame(stream: &mut TcpStream) -> Result<(u32, Vec<u8>)> {
    let mut hdr = [0u8; FRAME_HEADER_LEN];
    stream.read_exact(&mut hdr)?;
    let seq = u32::from_be_bytes([hdr[0], hdr[1], hdr[2], hdr[3]]);
    let len = u32::from_be_bytes([hdr[4], hdr[5], hdr[6], hdr[7]]) as usize;
    if len == 0 {
        return Ok((seq, vec![]));
    }
    let mut data = vec![0u8; len];
    stream.read_exact(&mut data)?;
    Ok((seq, data))
}

fn parse_leg_addr(addr: &str) -> SocketAddr {
    // Strip scheme prefix if present (e.g. "tcp://", "quic://")
    let stripped = if let Some(rest) = addr.strip_prefix("tcp://") {
        rest
    } else if let Some(rest) = addr.strip_prefix("quic://") {
        rest
    } else {
        addr
    };
    // Strip query string (e.g. "?priority=10&...")
    let clean = stripped.split('?').next().unwrap_or(stripped);
    clean.parse().unwrap_or_else(|_| {
        warn!("failed to parse leg addr '{}', using fallback", addr);
        "127.0.0.1:0".parse().unwrap()
    })
}
