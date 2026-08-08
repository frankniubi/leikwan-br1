use std::collections::HashMap;
use std::fs;
use std::io::ErrorKind;
use std::net::{SocketAddr, UdpSocket};
use std::path::Path;
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

const MAGIC: &[u8; 4] = b"UOT1";
const HEADER_BYTES: usize = 24;
const FLAG_PROBE: u16 = 1;

#[derive(Debug)]
struct SenderResult {
    packets_sent: u64,
    bytes_sent: u64,
    send_errors: u64,
    per_session_sent: Vec<u64>,
    per_second_sent: Vec<u64>,
    probe_rtt_ms: Vec<f64>,
}

fn now_unix_ns() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or(Duration::ZERO)
        .as_nanos()
        .min(u128::from(u64::MAX)) as u64
}

fn parse_options(arguments: &[String]) -> Result<HashMap<String, String>, String> {
    if arguments.len() % 2 != 0 {
        return Err("options must be --name value pairs".to_string());
    }
    let mut options = HashMap::new();
    for pair in arguments.chunks_exact(2) {
        if !pair[0].starts_with("--") {
            return Err(format!("invalid option: {}", pair[0]));
        }
        if options.insert(pair[0].clone(), pair[1].clone()).is_some() {
            return Err(format!("duplicate option: {}", pair[0]));
        }
    }
    Ok(options)
}

fn required<'a>(options: &'a HashMap<String, String>, name: &str) -> Result<&'a str, String> {
    options
        .get(name)
        .map(String::as_str)
        .ok_or_else(|| format!("missing {name}"))
}

fn number<T>(options: &HashMap<String, String>, name: &str) -> Result<T, String>
where
    T: std::str::FromStr,
{
    required(options, name)?
        .parse()
        .map_err(|_| format!("invalid {name}"))
}

fn encode_packet(session: usize, flags: u16, sequence: u64, packet_bytes: usize) -> Vec<u8> {
    let mut packet = vec![0_u8; packet_bytes];
    packet[..4].copy_from_slice(MAGIC);
    packet[4..6].copy_from_slice(&(session as u16).to_be_bytes());
    packet[6..8].copy_from_slice(&flags.to_be_bytes());
    packet[8..16].copy_from_slice(&sequence.to_be_bytes());
    packet[16..24].copy_from_slice(&now_unix_ns().to_be_bytes());
    for (index, byte) in packet[HEADER_BYTES..].iter_mut().enumerate() {
        *byte = (sequence.wrapping_add(index as u64) & 0xff) as u8;
    }
    packet
}

fn decode_header(packet: &[u8]) -> Option<(usize, u16, u64, u64)> {
    if packet.len() < HEADER_BYTES || &packet[..4] != MAGIC {
        return None;
    }
    Some((
        u16::from_be_bytes(packet[4..6].try_into().ok()?) as usize,
        u16::from_be_bytes(packet[6..8].try_into().ok()?),
        u64::from_be_bytes(packet[8..16].try_into().ok()?),
        u64::from_be_bytes(packet[16..24].try_into().ok()?),
    ))
}

fn drain_probes(socket: &UdpSocket, buffer: &mut [u8], samples: &mut Vec<f64>) {
    loop {
        match socket.recv(buffer) {
            Ok(length) => {
                if let Some((_session, flags, _sequence, sent_ns)) =
                    decode_header(&buffer[..length])
                {
                    if flags & FLAG_PROBE != 0 {
                        let elapsed_ns = now_unix_ns().saturating_sub(sent_ns);
                        samples.push(elapsed_ns as f64 / 1_000_000.0);
                    }
                }
            }
            Err(error) if error.kind() == ErrorKind::WouldBlock => break,
            Err(_) => break,
        }
    }
}

fn run_sender(
    target: SocketAddr,
    duration_seconds: u64,
    sessions: usize,
    rate_bps: u64,
    packet_bytes: usize,
    probe_every_ms: u64,
) -> Result<SenderResult, String> {
    if duration_seconds == 0 || sessions == 0 || sessions > u16::MAX as usize {
        return Err("duration and sessions must be positive".to_string());
    }
    if packet_bytes < HEADER_BYTES || packet_bytes > 65_507 {
        return Err(format!(
            "packet bytes must be between {HEADER_BYTES} and 65507"
        ));
    }
    if rate_bps < sessions as u64 * packet_bytes as u64 * 8 {
        return Err("rate is too low for one packet per session per second".to_string());
    }

    let mut sockets = Vec::with_capacity(sessions);
    for _ in 0..sessions {
        let socket = UdpSocket::bind("0.0.0.0:0")
            .map_err(|error| format!("sender bind failed: {error}"))?;
        socket
            .connect(target)
            .map_err(|error| format!("sender connect failed: {error}"))?;
        socket
            .set_nonblocking(true)
            .map_err(|error| format!("sender nonblocking failed: {error}"))?;
        sockets.push(socket);
    }
    let mut result = SenderResult {
        packets_sent: 0,
        bytes_sent: 0,
        send_errors: 0,
        per_session_sent: vec![0; sessions],
        per_second_sent: vec![0; duration_seconds as usize],
        probe_rtt_ms: Vec::new(),
    };
    let packet_bits = packet_bytes as u128 * 8;
    let interval_ns = ((packet_bits * 1_000_000_000_u128) / u128::from(rate_bps))
        .max(1)
        .min(u128::from(u64::MAX)) as u64;
    let interval = Duration::from_nanos(interval_ns);
    let probe_interval = Duration::from_millis(probe_every_ms.max(1));
    let mut last_probe = Instant::now()
        .checked_sub(probe_interval)
        .unwrap_or_else(Instant::now);
    let mut sequences = vec![0_u64; sessions];
    let mut response = vec![0_u8; packet_bytes.max(HEADER_BYTES)];
    let started = Instant::now();
    let finished = started + Duration::from_secs(duration_seconds);
    let mut next_send = started;
    let mut cursor = 0_usize;

    while Instant::now() < finished {
        loop {
            let now = Instant::now();
            if now >= next_send {
                break;
            }
            let remaining = next_send.duration_since(now);
            if remaining > Duration::from_micros(200) {
                thread::sleep(remaining - Duration::from_micros(100));
            } else {
                std::hint::spin_loop();
            }
        }
        let session = cursor % sessions;
        cursor += 1;
        sequences[session] += 1;
        let probe = session == 0 && last_probe.elapsed() >= probe_interval;
        if probe {
            last_probe = Instant::now();
        }
        let packet = encode_packet(
            session,
            if probe { FLAG_PROBE } else { 0 },
            sequences[session],
            packet_bytes,
        );
        match sockets[session].send(&packet) {
            Ok(length) if length == packet.len() => {
                result.packets_sent += 1;
                result.bytes_sent += length as u64;
                result.per_session_sent[session] += 1;
                let second = started.elapsed().as_secs() as usize;
                if let Some(count) = result.per_second_sent.get_mut(second) {
                    *count += 1;
                }
            }
            Ok(_) | Err(_) => result.send_errors += 1,
        }
        drain_probes(&sockets[0], &mut response, &mut result.probe_rtt_ms);
        next_send += interval;
        let now = Instant::now();
        if now.saturating_duration_since(next_send) > Duration::from_millis(100) {
            next_send = now;
        }
    }

    let drain_deadline = Instant::now() + Duration::from_millis(750);
    while Instant::now() < drain_deadline {
        drain_probes(&sockets[0], &mut response, &mut result.probe_rtt_ms);
        thread::sleep(Duration::from_millis(2));
    }
    Ok(result)
}

fn mark_unique(bits: &mut Vec<u64>, sequence: u64) -> bool {
    let word = (sequence / 64) as usize;
    let bit = sequence % 64;
    if bits.len() <= word {
        bits.resize(word + 1, 0);
    }
    let mask = 1_u64 << bit;
    let unique = bits[word] & mask == 0;
    bits[word] |= mask;
    unique
}

fn run_receiver(
    bind: SocketAddr,
    duration_seconds: u64,
    grace_seconds: u64,
    sessions: usize,
) -> Result<String, String> {
    if duration_seconds == 0 || sessions == 0 || sessions > u16::MAX as usize {
        return Err("duration and sessions must be positive".to_string());
    }
    let socket = UdpSocket::bind(bind).map_err(|error| format!("bind failed: {error}"))?;
    socket
        .set_read_timeout(Some(Duration::from_millis(100)))
        .map_err(|error| format!("read timeout failed: {error}"))?;
    let waiting_deadline = Instant::now() + Duration::from_secs(15);
    let mut started = None::<Instant>;
    let mut buffer = vec![0_u8; 65_535];
    let mut seen = vec![Vec::<u64>::new(); sessions];
    let mut max_sequence = vec![0_u64; sessions];
    let mut per_session_packets = vec![0_u64; sessions];
    let mut per_second_useful_bytes = vec![0_u64; duration_seconds as usize];
    let mut unique_packets = 0_u64;
    let mut window_unique_packets = 0_u64;
    let mut duplicates = 0_u64;
    let mut out_of_order_events = 0_u64;
    let mut invalid_packets = 0_u64;
    let mut echoed_probes = 0_u64;
    let mut echo_errors = 0_u64;

    loop {
        if let Some(start) = started {
            if start.elapsed() >= Duration::from_secs(duration_seconds + grace_seconds) {
                break;
            }
        } else if Instant::now() >= waiting_deadline {
            return Err("receiver timed out waiting for first packet".to_string());
        }

        match socket.recv_from(&mut buffer) {
            Ok((length, peer)) => {
                let Some((session, flags, sequence, _sent_ns)) =
                    decode_header(&buffer[..length])
                else {
                    invalid_packets += 1;
                    continue;
                };
                if session >= sessions || sequence == 0 {
                    invalid_packets += 1;
                    continue;
                }
                let start = *started.get_or_insert_with(Instant::now);
                if mark_unique(&mut seen[session], sequence) {
                    if sequence < max_sequence[session] {
                        out_of_order_events += 1;
                    }
                    max_sequence[session] = max_sequence[session].max(sequence);
                    unique_packets += 1;
                    per_session_packets[session] += 1;
                    let second = start.elapsed().as_secs() as usize;
                    if let Some(bytes) = per_second_useful_bytes.get_mut(second) {
                        *bytes += length as u64;
                        window_unique_packets += 1;
                    }
                } else {
                    duplicates += 1;
                }
                if flags & FLAG_PROBE != 0 {
                    match socket.send_to(&buffer[..length], peer) {
                        Ok(sent) if sent == length => echoed_probes += 1,
                        _ => echo_errors += 1,
                    }
                }
            }
            Err(error)
                if matches!(error.kind(), ErrorKind::WouldBlock | ErrorKind::TimedOut) => {}
            Err(error) => return Err(format!("receive failed: {error}")),
        }
    }

    Ok(format!(
        concat!(
            "{{\n",
            "  \"duration_seconds\": {duration},\n",
            "  \"grace_seconds\": {grace},\n",
            "  \"sessions\": {sessions},\n",
            "  \"unique_packets\": {unique},\n",
            "  \"window_unique_packets\": {window_unique},\n",
            "  \"duplicates\": {duplicates},\n",
            "  \"out_of_order_events\": {out_of_order},\n",
            "  \"invalid_packets\": {invalid},\n",
            "  \"echoed_probes\": {echoed},\n",
            "  \"echo_errors\": {echo_errors},\n",
            "  \"per_session_packets\": [{per_session}],\n",
            "  \"per_second_useful_bytes\": [{per_second}]\n",
            "}}\n"
        ),
        duration = duration_seconds,
        grace = grace_seconds,
        sessions = sessions,
        unique = unique_packets,
        window_unique = window_unique_packets,
        duplicates = duplicates,
        out_of_order = out_of_order_events,
        invalid = invalid_packets,
        echoed = echoed_probes,
        echo_errors = echo_errors,
        per_session = join_u64(&per_session_packets),
        per_second = join_u64(&per_second_useful_bytes),
    ))
}

fn join_u64(values: &[u64]) -> String {
    values
        .iter()
        .map(u64::to_string)
        .collect::<Vec<_>>()
        .join(", ")
}

fn join_f64(values: &[f64]) -> String {
    values
        .iter()
        .filter(|value| value.is_finite())
        .map(|value| format!("{value:.6}"))
        .collect::<Vec<_>>()
        .join(", ")
}

fn write_output(path: &str, contents: &str) -> Result<(), String> {
    let path = Path::new(path);
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent).map_err(|error| format!("create output dir failed: {error}"))?;
    }
    fs::write(path, contents).map_err(|error| format!("write output failed: {error}"))
}

fn real_main() -> Result<(), String> {
    let arguments = std::env::args().collect::<Vec<_>>();
    let command = arguments
        .get(1)
        .ok_or_else(|| "usage: uot-traffic sender|receiver --name value ...".to_string())?;
    let options = parse_options(&arguments[2..])?;
    match command.as_str() {
        "sender" => {
            let target: SocketAddr = required(&options, "--target")?
                .parse()
                .map_err(|_| "invalid --target".to_string())?;
            let duration_seconds = number(&options, "--duration-seconds")?;
            let sessions = number(&options, "--sessions")?;
            let rate_bps = number(&options, "--rate-bps")?;
            let packet_bytes = number(&options, "--packet-bytes")?;
            let probe_every_ms = number(&options, "--probe-every-ms")?;
            let output = required(&options, "--output")?;
            let result = run_sender(
                target,
                duration_seconds,
                sessions,
                rate_bps,
                packet_bytes,
                probe_every_ms,
            )?;
            let actual_rate = if duration_seconds == 0 {
                0
            } else {
                result.bytes_sent.saturating_mul(8) / duration_seconds
            };
            let json = format!(
                concat!(
                    "{{\n",
                    "  \"duration_seconds\": {duration},\n",
                    "  \"sessions\": {sessions},\n",
                    "  \"target_rate_bps\": {target_rate},\n",
                    "  \"actual_offered_rate_bps\": {actual_rate},\n",
                    "  \"packet_bytes\": {packet_bytes},\n",
                    "  \"packets_sent\": {packets_sent},\n",
                    "  \"bytes_sent\": {bytes_sent},\n",
                    "  \"send_errors\": {send_errors},\n",
                    "  \"per_session_sent\": [{per_session}],\n",
                    "  \"per_second_sent\": [{per_second}],\n",
                    "  \"probe_rtt_ms\": [{probe_rtt}]\n",
                    "}}\n"
                ),
                duration = duration_seconds,
                sessions = sessions,
                target_rate = rate_bps,
                actual_rate = actual_rate,
                packet_bytes = packet_bytes,
                packets_sent = result.packets_sent,
                bytes_sent = result.bytes_sent,
                send_errors = result.send_errors,
                per_session = join_u64(&result.per_session_sent),
                per_second = join_u64(&result.per_second_sent),
                probe_rtt = join_f64(&result.probe_rtt_ms),
            );
            write_output(output, &json)
        }
        "receiver" => {
            let bind: SocketAddr = required(&options, "--bind")?
                .parse()
                .map_err(|_| "invalid --bind".to_string())?;
            let duration_seconds = number(&options, "--duration-seconds")?;
            let grace_seconds = number(&options, "--grace-seconds")?;
            let sessions = number(&options, "--sessions")?;
            let output = required(&options, "--output")?;
            let json = run_receiver(bind, duration_seconds, grace_seconds, sessions)?;
            write_output(output, &json)
        }
        _ => Err(format!("unsupported command: {command}")),
    }
}

fn main() {
    if let Err(error) = real_main() {
        eprintln!("error: {error}");
        std::process::exit(2);
    }
}
