/// Length-framed UDP datagram read/write over a TCP stream.
///
/// Wire format: `[4-byte big-endian length][payload]`
/// Matches the Python sidecar/client framing protocol exactly.
use std::io::{self, Read, Write};

/// Maximum datagram size we accept via the length prefix.
pub const MAX_DATAGRAM_LEN: usize = 65535;

/// Read one length-framed datagram from a TCP stream.
/// Returns the payload bytes, or an empty Vec on clean EOF (peer closed).
pub fn read_framed(reader: &mut impl Read) -> io::Result<Vec<u8>> {
    let mut len_buf = [0u8; 4];
    match reader.read_exact(&mut len_buf) {
        Ok(()) => {}
        Err(e) if e.kind() == io::ErrorKind::UnexpectedEof => return Ok(Vec::new()),
        Err(e) => return Err(e),
    }
    let len = u32::from_be_bytes(len_buf) as usize;
    if len == 0 || len > MAX_DATAGRAM_LEN {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            format!("invalid UDP frame length: {len}"),
        ));
    }
    let mut buf = vec![0u8; len];
    reader.read_exact(&mut buf)?;
    Ok(buf)
}

/// Write one length-framed datagram to a TCP stream.
#[allow(dead_code)]
pub fn write_framed(writer: &mut impl Write, data: &[u8]) -> io::Result<()> {
    let len_prefix = (data.len() as u32).to_be_bytes();
    writer.write_all(&len_prefix)?;
    writer.write_all(data)?;
    writer.flush()
}

/// Write one length-framed datagram WITHOUT flushing.
/// Caller is responsible for flushing (e.g., after batching multiple datagrams).
pub fn write_framed_no_flush(writer: &mut impl Write, data: &[u8]) -> io::Result<()> {
    let len_prefix = (data.len() as u32).to_be_bytes();
    writer.write_all(&len_prefix)?;
    writer.write_all(data)
}
