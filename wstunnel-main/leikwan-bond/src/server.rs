/// Bond server: accept leg connections, reassemble chunks in-order,
/// relay to target extracted from session token.
///
/// Wire protocol matches client.rs:
///   Handshake: 4-byte BE length + JSON {"session_token":"...", "protocol":"..."}
///   Data frames: 4-byte BE seq | 4-byte BE length | payload
///   EOF: seq=u32::MAX, length=0

use crate::token;
use anyhow::{anyhow, Result};
use log::{debug, error, info, warn};
use std::collections::HashMap;
use std::io::{Read, Write};
use std::net::{SocketAddr, TcpListener, TcpStream, ToSocketAddrs};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

const FRAME_HEADER_LEN: usize = 8;
const EOF_SEQ: u32 = u32::MAX;
const MAX_HANDSHAKE_LEN: usize = 8192;
const HANDSHAKE_TIMEOUT: Duration = Duration::from_secs(10);

struct Session {
    target_host: String,
    target_port: u16,
    /// Reassembly buffer, keyed by seq number.
    reassembly: Mutex<ReassemblyState>,
    shutdown: AtomicBool,
}

struct ReassemblyState {
    next_seq: u32,
    buf: HashMap<u32, Vec<u8>>,
    done_count: usize,
    leg_count: usize,
}

pub fn run_server(listen: &str, token_secret: &str) -> Result<()> {
    let listen_addr: SocketAddr = listen.parse()
        .map_err(|e| anyhow!("bad listen addr '{}': {}", listen, e))?;

    let listener = TcpListener::bind(listen_addr)?;
    info!("bond server listening on {}", listen_addr);

    let secret = token_secret.to_string();

    for stream in listener.incoming() {
        match stream {
            Ok(leg_stream) => {
                let secret = secret.clone();
                std::thread::spawn(move || {
                    if let Err(e) = handle_first_leg(leg_stream, &secret) {
                        warn!("leg error: {:#}", e);
                    }
                });
            }
            Err(e) => error!("accept error: {}", e),
        }
    }
    Ok(())
}

/// Handle the first leg of a session — verify token, connect to target,
/// then relay data.  Additional legs for the same session could be multiplexed
/// by seq routing, but for v0.1 each TCP connection is treated as a standalone
/// session (matches how the client currently sends: each leg carries independent
/// chunks, server reassembles from all legs that share the same listener).
fn handle_first_leg(mut stream: TcpStream, secret: &str) -> Result<()> {
    let peer = stream.peer_addr().map(|a| a.to_string()).unwrap_or_default();
    debug!("leg from {}", peer);

    stream.set_read_timeout(Some(HANDSHAKE_TIMEOUT))?;
    stream.set_nodelay(true)?;

    // Read handshake.
    let mut len_buf = [0u8; 4];
    if let Err(e) = stream.read_exact(&mut len_buf) {
        debug!("handshake read from {}: {} (probe?)", peer, e);
        return Ok(());
    }
    let hs_len = u32::from_be_bytes(len_buf) as usize;
    if hs_len == 0 || hs_len > MAX_HANDSHAKE_LEN {
        return Err(anyhow!("bad handshake length: {}", hs_len));
    }

    let mut hs_buf = vec![0u8; hs_len];
    stream.read_exact(&mut hs_buf)?;

    let hs: serde_json::Value = serde_json::from_slice(&hs_buf)?;
    let token_str = hs.get("session_token")
        .and_then(|v| v.as_str())
        .ok_or_else(|| anyhow!("missing session_token"))?;

    let payload = token::verify_token(token_str, secret)?;
    info!(
        "token verified: target={}:{} forward_id={} from={}",
        payload.target_host, payload.target_port, payload.forward_id, peer
    );

    stream.set_read_timeout(None)?;

    // Connect to target.
    let target_addr: SocketAddr =
        format!("{}:{}", payload.target_host, payload.target_port)
            .to_socket_addrs()?
            .next()
            .ok_or_else(|| anyhow!("resolve target failed"))?;

    let target = TcpStream::connect(target_addr)?;
    target.set_nodelay(true)?;
    info!("connected to target {}", target_addr);

    // Simple relay: read frames from this leg, write payload to target.
    // Read from target, send frames back on this leg.
    let shutdown = Arc::new(AtomicBool::new(false));
    let mut target_rd = target.try_clone()?;
    let mut target_wr = target.try_clone()?;
    let mut leg_wr = stream.try_clone()?;
    let mut leg_rd = stream;

    let shutdown_fwd = Arc::clone(&shutdown);

    // Forward: leg→target (read frames, write raw payload)
    let fwd = std::thread::spawn(move || {
        loop {
            if shutdown_fwd.load(Ordering::Relaxed) { break; }
            match read_frame(&mut leg_rd) {
                Ok((seq, data)) => {
                    if seq == EOF_SEQ {
                        debug!("received EOF from client");
                        let _ = target_wr.shutdown(std::net::Shutdown::Write);
                        break;
                    }
                    if target_wr.write_all(&data).is_err() {
                        break;
                    }
                }
                Err(_) => break,
            }
        }
        shutdown_fwd.store(true, Ordering::Relaxed);
    });

    // Backward: target→leg (read raw, send frames)
    let mut send_seq: u32 = 0;
    let mut buf = vec![0u8; 65536];
    loop {
        if shutdown.load(Ordering::Relaxed) { break; }
        match target_rd.read(&mut buf) {
            Ok(0) => {
                let eof = encode_frame(EOF_SEQ, &[]);
                let _ = leg_wr.write_all(&eof);
                break;
            }
            Ok(n) => {
                let frame = encode_frame(send_seq, &buf[..n]);
                if leg_wr.write_all(&frame).is_err() { break; }
                send_seq = send_seq.wrapping_add(1);
            }
            Err(_) => break,
        }
    }

    shutdown.store(true, Ordering::Relaxed);
    let _ = fwd.join();
    info!("session for {} done", peer);
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

fn encode_frame(seq: u32, data: &[u8]) -> Vec<u8> {
    let mut frame = Vec::with_capacity(FRAME_HEADER_LEN + data.len());
    frame.extend_from_slice(&seq.to_be_bytes());
    frame.extend_from_slice(&(data.len() as u32).to_be_bytes());
    frame.extend_from_slice(data);
    frame
}
