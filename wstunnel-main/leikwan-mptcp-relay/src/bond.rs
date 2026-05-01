/// Application-layer multi-path bonding: distribute chunks across N TCP legs,
/// reassemble in-order at the receiver.
///
/// Wire protocol (identical to leikwan-bond):
///   Data frame: [4-byte BE seq] [4-byte BE len] [payload]
///   EOF marker: seq=u32::MAX, len=0

use crate::config::ClientConfig;
use crate::mptcp::{self, SocketConfig};

use anyhow::{anyhow, Result};
use log::{debug, error, info, warn};
use std::collections::HashMap;
use std::io::{Read, Write};
use std::net::{SocketAddr, TcpStream};
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Condvar, Mutex};
use std::time::Duration;

const FRAME_HEADER_LEN: usize = 8; // 4-byte seq + 4-byte len
const EOF_SEQ: u32 = u32::MAX;

/// A bond leg: local WG address → peer sidecar WG address.
#[derive(Debug, Clone)]
pub struct BondLeg {
    pub local_addr: SocketAddr,
    pub peer_addr: SocketAddr,
    pub dev: String,
}

struct LegWriter {
    stream: TcpStream,
    dev: String,
    /// Consecutive timeout count. If > threshold, leg is "benched" temporarily.
    timeouts: u32,
    /// If true, this leg is excluded from distribution until cooldown resets.
    benched: bool,
    /// Cooldown: chunks until we re-probe this benched leg.
    cooldown: u32,
}

/// After this many consecutive timeouts, bench the leg.
const BENCH_THRESHOLD: u32 = 3;
/// Re-probe benched legs every N chunks (gives slow legs a chance to recover).
const REPROBE_INTERVAL: u32 = 50;

struct ReassemblyState {
    next_seq: u32,
    buf: HashMap<u32, Vec<u8>>,
    done: bool,
}

// ---- Wire protocol helpers (same as leikwan-bond) ----

fn encode_frame(seq: u32, data: &[u8]) -> Vec<u8> {
    let mut frame = Vec::with_capacity(FRAME_HEADER_LEN + data.len());
    frame.extend_from_slice(&seq.to_be_bytes());
    frame.extend_from_slice(&(data.len() as u32).to_be_bytes());
    frame.extend_from_slice(data);
    frame
}

fn read_frame(reader: &mut impl Read) -> Result<(u32, Vec<u8>)> {
    let mut hdr = [0u8; FRAME_HEADER_LEN];
    reader.read_exact(&mut hdr)?;
    let seq = u32::from_be_bytes([hdr[0], hdr[1], hdr[2], hdr[3]]);
    let len = u32::from_be_bytes([hdr[4], hdr[5], hdr[6], hdr[7]]) as usize;
    if len == 0 {
        return Ok((seq, vec![]));
    }
    let mut data = vec![0u8; len];
    reader.read_exact(&mut data)?;
    Ok((seq, data))
}

// ---- Client-side bond relay ----

/// Bond TCP relay: distribute local TCP data across N legs, reassemble replies.
///
/// Each leg is an independent TCP connection bound to a different WG interface
/// and connected to the corresponding sidecar WG address.
pub fn bond_relay_tcp(
    local_stream: TcpStream,
    legs: &[BondLeg],
    config: &ClientConfig,
) -> Result<()> {
    // Bond mode: use plain TCP (not MPTCP) for each leg.
    let sock_config = SocketConfig {
        congestion_control: config.tcp_congestion_control.clone(),
        buffer_bytes: config.socket_buffer_bytes,
        allow_tcp_fallback: true,
    };

    // Generate a random bond session ID so the server can correlate legs.
    let bond_session_id: u64 = {
        use std::time::SystemTime;
        let t = SystemTime::now()
            .duration_since(SystemTime::UNIX_EPOCH)
            .unwrap_or_default();
        // Mix nanos + pid for uniqueness.
        t.as_nanos() as u64 ^ (std::process::id() as u64) << 32
    };

    // Connect all legs.
    let mut connected_legs: Vec<LegWriter> = Vec::new();
    let _timeout = Duration::from_millis(config.connect_timeout_ms);

    for leg in legs {
        match mptcp::mptcp_connect_bound(leg.local_addr, leg.peer_addr, &sock_config) {
            Ok(mut stream) => {
                stream.set_nodelay(true).ok();
                // Send handshake with bond_session_id.
                if let Err(e) = send_bond_handshake(
                    &mut stream,
                    &config.tcp_session_token,
                    config.forward_id,
                    bond_session_id,
                ) {
                    warn!("bond leg {} handshake failed: {}", leg.dev, e);
                    continue;
                }
                info!("bond leg connected: {} ({} -> {})", leg.dev, leg.local_addr, leg.peer_addr);
                connected_legs.push(LegWriter {
                    stream,
                    dev: leg.dev.clone(),
                    timeouts: 0,
                    benched: false,
                    cooldown: 0,
                });
            }
            Err(e) => {
                warn!("bond leg {} connect failed: {}", leg.dev, e);
            }
        }
    }

    if connected_legs.is_empty() {
        return Err(anyhow!("no bond legs connected"));
    }
    info!(
        "bond session {:016x}: {} of {} legs connected",
        bond_session_id,
        connected_legs.len(),
        legs.len()
    );

    // Clone streams for reading (server→client direction).
    let mut leg_readers: Vec<(String, TcpStream)> = Vec::new();
    for leg in &connected_legs {
        let rd = leg.stream.try_clone()?;
        leg_readers.push((leg.dev.clone(), rd));
    }

    let legs_arc = Arc::new(Mutex::new(connected_legs));
    let shutdown = Arc::new(AtomicBool::new(false));
    let send_seq = Arc::new(AtomicU32::new(0));

    let reassembly = Arc::new(Mutex::new(ReassemblyState {
        next_seq: 0,
        buf: HashMap::new(),
        done: false,
    }));
    let reassembly_cv = Arc::new(Condvar::new());

    let local_rd = local_stream.try_clone()?;
    let mut local_wr = local_stream;

    // ── Send thread: local → legs (weighted round-robin with backpressure) ──
    //
    // Key design:
    // 1. Short write timeouts (50ms) — skip congested legs, fast legs get more
    // 2. Backpressure: if reassembly buffer > 256 entries, pause send (slow legs
    //    are stalling the receiver → don't flood more data)
    // 3. 32KB default chunks for finer distribution granularity
    let legs_send = Arc::clone(&legs_arc);
    let shutdown_send = Arc::clone(&shutdown);
    let send_seq_clone = Arc::clone(&send_seq);
    let reassembly_bp = Arc::clone(&reassembly);
    let chunk_size = config.bond_chunk_size;

    let send_handle = std::thread::spawn(move || {
        let mut local_rd = local_rd;
        let mut buf = vec![0u8; chunk_size];
        let mut rr_idx: usize = 0;

        // Set short write timeouts on all legs.
        {
            let legs_guard = legs_send.lock().unwrap();
            for leg in legs_guard.iter() {
                leg.stream.set_write_timeout(Some(Duration::from_millis(50))).ok();
            }
        }

        loop {
            if shutdown_send.load(Ordering::Relaxed) {
                break;
            }

            // Backpressure: if reassembly buffer is too deep, pause.
            // This means the receiver is slower than the sender and we're
            // accumulating out-of-order frames. Throttle to let drain catch up.
            {
                let state = reassembly_bp.lock().unwrap();
                if state.buf.len() > 256 {
                    drop(state);
                    std::thread::sleep(Duration::from_millis(2));
                    continue;
                }
            }

            let n = match local_rd.read(&mut buf) {
                Ok(0) => {
                    // EOF — send EOF marker to all legs (with longer timeout).
                    let eof_frame = encode_frame(EOF_SEQ, &[]);
                    let mut legs_guard = legs_send.lock().unwrap();
                    for leg in legs_guard.iter_mut() {
                        leg.stream.set_write_timeout(Some(Duration::from_secs(5))).ok();
                        let _ = leg.stream.write_all(&eof_frame);
                    }
                    break;
                }
                Ok(n) => n,
                Err(_) => break,
            };

            let seq = send_seq_clone.fetch_add(1, Ordering::SeqCst);
            let frame = encode_frame(seq, &buf[..n]);

            // Round-robin with dynamic benching: skip benched legs, and bench
            // legs that timeout too many times in a row. Re-probe periodically.
            let mut legs_guard = legs_send.lock().unwrap();
            let leg_count = legs_guard.len();
            if leg_count == 0 {
                break;
            }

            // Tick cooldowns on benched legs.
            for leg in legs_guard.iter_mut() {
                if leg.benched {
                    if leg.cooldown == 0 {
                        leg.benched = false;
                        leg.timeouts = 0;
                    } else {
                        leg.cooldown -= 1;
                    }
                }
            }

            let mut sent = false;
            // First pass: try non-benched legs.
            for attempt in 0..leg_count {
                let idx = (rr_idx + attempt) % leg_count;
                if legs_guard[idx].benched {
                    continue;
                }
                match legs_guard[idx].stream.write_all(&frame) {
                    Ok(_) => {
                        legs_guard[idx].timeouts = 0;
                        rr_idx = idx + 1;
                        sent = true;
                        break;
                    }
                    Err(ref e) if e.kind() == std::io::ErrorKind::WouldBlock
                        || e.kind() == std::io::ErrorKind::TimedOut => {
                        legs_guard[idx].timeouts += 1;
                        if legs_guard[idx].timeouts >= BENCH_THRESHOLD {
                            legs_guard[idx].benched = true;
                            legs_guard[idx].cooldown = REPROBE_INTERVAL;
                            debug!("benching slow leg {}", legs_guard[idx].dev);
                        }
                        continue;
                    }
                    Err(_) => {
                        warn!("bond leg {} write failed", legs_guard[idx].dev);
                        continue;
                    }
                }
            }

            if !sent {
                // All active legs congested — try benched legs too.
                for attempt in 0..leg_count {
                    let idx = (rr_idx + attempt) % leg_count;
                    match legs_guard[idx].stream.write_all(&frame) {
                        Ok(_) => {
                            legs_guard[idx].timeouts = 0;
                            legs_guard[idx].benched = false;
                            rr_idx = idx + 1;
                            sent = true;
                            break;
                        }
                        Err(_) => continue,
                    }
                }
            }
            drop(legs_guard);

            if !sent {
                // All legs failed — pause and retry.
                std::thread::sleep(Duration::from_millis(5));
                let mut legs_guard = legs_send.lock().unwrap();
                let leg_count = legs_guard.len();
                if leg_count == 0 {
                    break;
                }
                for leg in legs_guard.iter_mut() {
                    leg.stream.set_write_timeout(Some(Duration::from_millis(200))).ok();
                }
                for attempt in 0..leg_count {
                    let idx = (rr_idx + attempt) % leg_count;
                    if legs_guard[idx].stream.write_all(&frame).is_ok() {
                        rr_idx = idx + 1;
                        sent = true;
                        break;
                    }
                }
                for leg in legs_guard.iter_mut() {
                    leg.stream.set_write_timeout(Some(Duration::from_millis(50))).ok();
                }
                drop(legs_guard);
                if !sent {
                    error!("all bond legs failed to send seq={}", seq);
                    break;
                }
            }
        }

        shutdown_send.store(true, Ordering::Relaxed);
        debug!("bond send thread done");
    });

    // ── Receive threads: one per leg → shared reassembly buffer ──
    let recv_handles: Vec<_> = leg_readers
        .into_iter()
        .map(|(dev, mut rd)| {
            let reassembly = Arc::clone(&reassembly);
            let reassembly_cv = Arc::clone(&reassembly_cv);
            let shutdown = Arc::clone(&shutdown);
            std::thread::spawn(move || {
                loop {
                    if shutdown.load(Ordering::Relaxed) {
                        break;
                    }
                    match read_frame(&mut rd) {
                        Ok((seq, data)) => {
                            if seq == EOF_SEQ {
                                debug!("bond leg {} received EOF", dev);
                                let mut state = reassembly.lock().unwrap();
                                state.done = true;
                                reassembly_cv.notify_one();
                                break;
                            }
                            {
                                let mut state = reassembly.lock().unwrap();
                                state.buf.insert(seq, data);
                            }
                            reassembly_cv.notify_one();
                        }
                        Err(_) => {
                            debug!("bond leg {} read done", dev);
                            break;
                        }
                    }
                }
            })
        })
        .collect();

    // ── Main thread: drain reassembly in-order → local ──
    // Uses condvar for immediate wake when frames arrive.
    local_wr.set_nodelay(true).ok();
    loop {
        if shutdown.load(Ordering::Relaxed) {
            flush_reassembly_batch(&reassembly, &mut local_wr);
            break;
        }

        // Wait for frames or done signal.
        {
            let state = reassembly.lock().unwrap();
            if state.buf.get(&state.next_seq).is_none() && !state.done {
                let (_state, _) = reassembly_cv
                    .wait_timeout(state, Duration::from_millis(5))
                    .unwrap();
            }
        }

        let flushed = flush_reassembly_batch(&reassembly, &mut local_wr);

        {
            let state = reassembly.lock().unwrap();
            if state.done && state.buf.is_empty() {
                drop(state);
                flush_reassembly_batch(&reassembly, &mut local_wr);
                break;
            }
        }

        if !flushed {
            // Shouldn't reach here often with condvar, but safety net.
            std::thread::sleep(Duration::from_micros(50));
        }
    }

    let _ = local_wr.shutdown(std::net::Shutdown::Write);
    shutdown.store(true, Ordering::Relaxed);
    let _ = send_handle.join();
    for h in recv_handles {
        let _ = h.join();
    }

    info!("bond session {:016x} done", bond_session_id);
    Ok(())
}

/// Flush in-order frames: extract batch under short lock, write outside lock.
/// This prevents the reassembly lock from being held during slow socket writes,
/// which would block receive threads from inserting new frames.
fn flush_reassembly_batch(reassembly: &Arc<Mutex<ReassemblyState>>, writer: &mut TcpStream) -> bool {
    // Phase 1: extract in-order frames under lock.
    let frames = {
        let mut state = reassembly.lock().unwrap();
        let mut out = Vec::new();
        loop {
            let seq = state.next_seq;
            if let Some(data) = state.buf.remove(&seq) {
                out.push(data);
                state.next_seq += 1;
            } else {
                break;
            }
        }
        out
    };
    // Phase 2: write outside lock.
    if frames.is_empty() {
        return false;
    }
    for data in &frames {
        if writer.write_all(data).is_err() {
            return true; // partial flush
        }
    }
    true
}

fn send_bond_handshake(
    stream: &mut TcpStream,
    token: &str,
    forward_id: u64,
    bond_session_id: u64,
) -> Result<()> {
    let hs = serde_json::json!({
        "protocol": "tcp",
        "session_token": token,
        "forward_id": forward_id,
        "bond_session_id": bond_session_id,
    });
    let bytes = serde_json::to_vec(&hs)?;
    let len = (bytes.len() as u32).to_be_bytes();
    stream.write_all(&len)?;
    stream.write_all(&bytes)?;
    stream.flush()?;
    Ok(())
}

// ---- Server-side bond reassembly ----

/// Registry of active bond sessions on the server side.
pub struct BondSessionRegistry {
    sessions: Mutex<HashMap<u64, Arc<BondSession>>>,
}

pub(crate) struct BondSession {
    target_wr: Mutex<TcpStream>,
    reassembly: Mutex<ReassemblyState>,
    reassembly_notify: Condvar,
    legs_wr: Mutex<Vec<TcpStream>>,
    send_seq: AtomicU32,
    shutdown: AtomicBool,
    /// How many legs have connected so far.
    leg_count: AtomicU32,
    /// How many legs have finished reading (sent EOF or disconnected).
    legs_done: AtomicU32,
}

impl BondSessionRegistry {
    pub fn new() -> Self {
        Self {
            sessions: Mutex::new(HashMap::new()),
        }
    }

    /// Get or create a bond session. Returns (session, is_first_leg).
    pub fn get_or_create(
        &self,
        bond_session_id: u64,
        target: TcpStream,
    ) -> (Arc<BondSession>, bool) {
        let mut sessions = self.sessions.lock().unwrap();
        if let Some(session) = sessions.get(&bond_session_id) {
            session.leg_count.fetch_add(1, Ordering::SeqCst);
            (Arc::clone(session), false)
        } else {
            let session = Arc::new(BondSession {
                target_wr: Mutex::new(target),
                reassembly: Mutex::new(ReassemblyState {
                    next_seq: 0,
                    buf: HashMap::new(),
                    done: false,
                }),
                reassembly_notify: Condvar::new(),
                legs_wr: Mutex::new(Vec::new()),
                send_seq: AtomicU32::new(0),
                shutdown: AtomicBool::new(false),
                leg_count: AtomicU32::new(1),
                legs_done: AtomicU32::new(0),
            });
            sessions.insert(bond_session_id, Arc::clone(&session));
            (session, true)
        }
    }

    /// Remove a session from the registry.
    pub fn remove(&self, bond_session_id: u64) {
        let mut sessions = self.sessions.lock().unwrap();
        sessions.remove(&bond_session_id);
    }
}

/// Handle a bond leg on the server side.
///
/// If this is the first leg for the session, connects to the target and spawns
/// the target→legs send thread. All legs feed into shared reassembly which
/// drains in-order to the target.
pub fn handle_bond_leg(
    mut leg_stream: TcpStream,
    bond_session_id: u64,
    target_addr: SocketAddr,
    registry: &Arc<BondSessionRegistry>,
    sock_config: &crate::mptcp::SocketConfig,
) -> Result<()> {
    let leg_peer = leg_stream
        .peer_addr()
        .map(|a| a.to_string())
        .unwrap_or_default();

    // Check if session already exists before connecting to target.
    let (session, is_first) = {
        let sessions = registry.sessions.lock().unwrap();
        if let Some(existing) = sessions.get(&bond_session_id) {
            existing.leg_count.fetch_add(1, Ordering::SeqCst);
            (Arc::clone(existing), false)
        } else {
            drop(sessions);
            // First leg — connect to the real target.
            let target_stream = TcpStream::connect(target_addr)?;
            #[cfg(target_os = "linux")]
            {
                use std::os::unix::io::AsRawFd;
                crate::mptcp::tune_fd(target_stream.as_raw_fd(), sock_config);
            }
            registry.get_or_create(bond_session_id, target_stream)
        }
    };

    // Register this leg's write stream for the backward direction.
    {
        let leg_wr = leg_stream.try_clone()?;
        let mut legs = session.legs_wr.lock().unwrap();
        legs.push(leg_wr);
    }

    if is_first {
        info!(
            "bond session {:016x}: first leg from {}, target={}",
            bond_session_id, leg_peer, target_addr
        );

        // Spawn target→legs sender thread.
        let session_bwd = Arc::clone(&session);
        std::thread::spawn(move || {
            bond_server_backward(session_bwd);
        });

        // Spawn reassembly drainer: reassembly buffer → target.
        let session_drain = Arc::clone(&session);
        std::thread::spawn(move || {
            bond_server_drain(session_drain);
        });
    } else {
        info!(
            "bond session {:016x}: additional leg from {} (total={})",
            bond_session_id,
            leg_peer,
            session.leg_count.load(Ordering::Relaxed)
        );
    }

    // This thread: read frames from this leg → reassembly buffer.
    loop {
        if session.shutdown.load(Ordering::Relaxed) {
            break;
        }
        match read_frame(&mut leg_stream) {
            Ok((seq, data)) => {
                if seq == EOF_SEQ {
                    debug!("bond leg {} sent EOF", leg_peer);
                    let done_count = session.legs_done.fetch_add(1, Ordering::SeqCst) + 1;
                    let total = session.leg_count.load(Ordering::Relaxed);
                    if done_count >= total {
                        let mut state = session.reassembly.lock().unwrap();
                        state.done = true;
                    }
                    session.reassembly_notify.notify_one();
                    break;
                }
                {
                    let mut state = session.reassembly.lock().unwrap();
                    state.buf.insert(seq, data);
                }
                session.reassembly_notify.notify_one();
            }
            Err(_) => {
                debug!("bond leg {} read error/disconnect", leg_peer);
                let done_count = session.legs_done.fetch_add(1, Ordering::SeqCst) + 1;
                let total = session.leg_count.load(Ordering::Relaxed);
                if done_count >= total {
                    let mut state = session.reassembly.lock().unwrap();
                    state.done = true;
                }
                session.reassembly_notify.notify_one();
                break;
            }
        }
    }

    // If all legs are done, clean up.
    let remaining = session.leg_count.fetch_sub(1, Ordering::SeqCst);
    if remaining <= 1 {
        session.shutdown.store(true, Ordering::Relaxed);
        registry.remove(bond_session_id);
        info!("bond session {:016x} cleaned up", bond_session_id);
    }

    Ok(())
}

/// Server backward: read from target, chunk into frames, round-robin to legs.
/// Uses write timeouts to skip congested legs and avoid head-of-line blocking.
fn bond_server_backward(session: Arc<BondSession>) {
    let mut target_rd = match session.target_wr.lock().unwrap().try_clone() {
        Ok(s) => s,
        Err(_) => return,
    };

    // Set short write timeouts on all legs.
    {
        let legs = session.legs_wr.lock().unwrap();
        for leg in legs.iter() {
            leg.set_write_timeout(Some(Duration::from_millis(50))).ok();
        }
    }

    let mut buf = vec![0u8; 32768]; // Match chunk size for consistent framing
    let mut rr_idx: usize = 0;

    loop {
        if session.shutdown.load(Ordering::Relaxed) {
            break;
        }
        match target_rd.read(&mut buf) {
            Ok(0) => {
                // Target EOF — send EOF to all legs.
                let eof = encode_frame(EOF_SEQ, &[]);
                let mut legs = session.legs_wr.lock().unwrap();
                for leg in legs.iter_mut() {
                    let _ = leg.write_all(&eof);
                }
                break;
            }
            Ok(n) => {
                let seq = session.send_seq.fetch_add(1, Ordering::SeqCst);
                let frame = encode_frame(seq, &buf[..n]);

                let mut legs = session.legs_wr.lock().unwrap();
                let leg_count = legs.len();
                if leg_count == 0 {
                    break;
                }

                let mut sent = false;
                for attempt in 0..leg_count {
                    let idx = (rr_idx + attempt) % leg_count;
                    match legs[idx].write_all(&frame) {
                        Ok(_) => {
                            rr_idx = idx + 1;
                            sent = true;
                            break;
                        }
                        Err(ref e) if e.kind() == std::io::ErrorKind::WouldBlock
                            || e.kind() == std::io::ErrorKind::TimedOut => {
                            continue; // Skip congested leg.
                        }
                        Err(_) => continue,
                    }
                }
                if !sent {
                    // All legs congested — wait and retry once.
                    drop(legs);
                    std::thread::sleep(Duration::from_millis(10));
                    let mut legs = session.legs_wr.lock().unwrap();
                    let leg_count = legs.len();
                    for attempt in 0..leg_count {
                        let idx = (rr_idx + attempt) % leg_count;
                        if legs[idx].write_all(&frame).is_ok() {
                            rr_idx = idx + 1;
                            sent = true;
                            break;
                        }
                    }
                    if !sent {
                        break;
                    }
                }
            }
            Err(_) => break,
        }
    }

    session.shutdown.store(true, Ordering::Relaxed);
}

/// Server drain: flush reassembly buffer in-order to target.
///
/// Uses condvar to wake immediately when a new frame arrives, instead of
/// polling with fixed sleep. Locks reassembly briefly to extract a batch,
/// then writes to target outside the lock.
fn bond_server_drain(session: Arc<BondSession>) {
    // Set TCP_NODELAY on target for low-latency writes.
    if let Ok(target) = session.target_wr.lock().unwrap().try_clone() {
        target.set_nodelay(true).ok();
        drop(target);
    }

    loop {
        if session.shutdown.load(Ordering::Relaxed) {
            // Final drain.
            let frames = {
                let mut state = session.reassembly.lock().unwrap();
                let mut out = Vec::new();
                loop {
                    let seq = state.next_seq;
                    if let Some(data) = state.buf.remove(&seq) {
                        out.push(data);
                        state.next_seq += 1;
                    } else {
                        break;
                    }
                }
                out
            };
            if !frames.is_empty() {
                let mut target = session.target_wr.lock().unwrap();
                for data in frames {
                    if target.write_all(&data).is_err() {
                        break;
                    }
                }
            }
            break;
        }

        // Grab a batch of in-order frames (short lock).
        let (frames, is_done) = {
            let mut state = session.reassembly.lock().unwrap();

            // If nothing ready, wait for notification (with timeout to check shutdown).
            if state.buf.get(&state.next_seq).is_none() && !state.done {
                let (state_after, _) = session
                    .reassembly_notify
                    .wait_timeout(state, Duration::from_millis(5))
                    .unwrap();
                state = state_after;
            }

            let mut out = Vec::new();
            loop {
                let seq = state.next_seq;
                if let Some(data) = state.buf.remove(&seq) {
                    out.push(data);
                    state.next_seq += 1;
                } else {
                    break;
                }
            }
            (out, state.done && state.buf.is_empty())
        };

        if !frames.is_empty() {
            let mut target = session.target_wr.lock().unwrap();
            for data in &frames {
                if target.write_all(data).is_err() {
                    session.shutdown.store(true, Ordering::Relaxed);
                    break;
                }
            }
        } else if is_done {
            session.shutdown.store(true, Ordering::Relaxed);
        }
    }
}

/// Derive bond legs from the client config's underlays[].
///
/// Each underlay has `local_address` (bind addr) and `mptcp_remote_address`
/// (peer sidecar addr). Combined with `remote_port` from config.
pub fn derive_bond_legs_from_config(config: &ClientConfig) -> Vec<BondLeg> {
    let port = config.remote_port;
    let mut legs = Vec::new();

    for underlay in &config.underlays {
        let iface = underlay
            .get("interface_name")
            .and_then(|v| v.as_str())
            .unwrap_or("");

        let ul_legs = match underlay.get("legs").and_then(|l| l.as_array()) {
            Some(arr) => arr,
            None => continue,
        };

        for leg in ul_legs {
            let local = match leg.get("local_address").and_then(|a| a.as_str()) {
                Some(a) => a,
                None => continue,
            };
            let remote = match leg.get("mptcp_remote_address").and_then(|a| a.as_str()) {
                Some(a) => a,
                None => continue,
            };

            let local_addr: SocketAddr = match format!("{}:0", local).parse() {
                Ok(a) => a,
                Err(_) => continue,
            };
            let peer_addr: SocketAddr = match format!("{}:{}", remote, port).parse() {
                Ok(a) => a,
                Err(_) => continue,
            };

            legs.push(BondLeg {
                local_addr,
                peer_addr,
                dev: iface.to_string(),
            });
        }
    }

    legs
}
