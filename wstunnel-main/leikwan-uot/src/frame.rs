use anyhow::{anyhow, bail, Result};

pub const FRAME_HEADER_BYTES: usize = 1 + 1 + 2 + 4 + 8 + 4;
/// Data-frame flag: this datagram is a first-wins replica copy. The receiver
/// consults its FirstWinsCache and suppresses it if the (session, seq) was
/// already delivered by the primary copy.
pub const DATA_FLAG_FIRST_WINS: u16 = 0x0001;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[repr(u8)]
pub enum MsgType {
    Auth = 0,
    Open = 1,
    Data = 2,
    Close = 3,
    Keepalive = 4,
    FecParity = 5,
    AuthAck = 6,
    LegProgress = 7,
}

impl TryFrom<u8> for MsgType {
    type Error = anyhow::Error;

    fn try_from(value: u8) -> Result<Self> {
        match value {
            0 => Ok(Self::Auth),
            1 => Ok(Self::Open),
            2 => Ok(Self::Data),
            3 => Ok(Self::Close),
            4 => Ok(Self::Keepalive),
            5 => Ok(Self::FecParity),
            6 => Ok(Self::AuthAck),
            7 => Ok(Self::LegProgress),
            _ => Err(anyhow!("unknown frame message type: {value}")),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct DataFrame {
    pub version: u8,
    pub msg_type: MsgType,
    pub flags: u16,
    pub session_id: u32,
    pub seq: u64,
    pub payload: Vec<u8>,
}

pub fn encode_frame(frame: &DataFrame) -> Vec<u8> {
    let payload_len = u32::try_from(frame.payload.len()).expect("payload length exceeds u32");
    let mut encoded = Vec::with_capacity(FRAME_HEADER_BYTES + frame.payload.len());
    encoded.push(frame.version);
    encoded.push(frame.msg_type as u8);
    encoded.extend_from_slice(&frame.flags.to_be_bytes());
    encoded.extend_from_slice(&frame.session_id.to_be_bytes());
    encoded.extend_from_slice(&frame.seq.to_be_bytes());
    encoded.extend_from_slice(&payload_len.to_be_bytes());
    encoded.extend_from_slice(&frame.payload);
    encoded
}

pub fn decode_frame(payload: &[u8]) -> Result<DataFrame> {
    if payload.len() < FRAME_HEADER_BYTES {
        bail!("frame too short");
    }
    let version = payload[0];
    let msg_type = MsgType::try_from(payload[1])?;
    let flags = u16::from_be_bytes([payload[2], payload[3]]);
    let session_id = u32::from_be_bytes([payload[4], payload[5], payload[6], payload[7]]);
    let seq = u64::from_be_bytes([
        payload[8],
        payload[9],
        payload[10],
        payload[11],
        payload[12],
        payload[13],
        payload[14],
        payload[15],
    ]);
    let payload_len =
        u32::from_be_bytes([payload[16], payload[17], payload[18], payload[19]]) as usize;
    if payload.len() < FRAME_HEADER_BYTES + payload_len {
        bail!("truncated frame payload");
    }
    if payload.len() != FRAME_HEADER_BYTES + payload_len {
        bail!("frame has trailing bytes");
    }
    Ok(DataFrame {
        version,
        msg_type,
        flags,
        session_id,
        seq,
        payload: payload[FRAME_HEADER_BYTES..].to_vec(),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn encode_and_decode_frame_round_trip() {
        let frame = DataFrame {
            version: 1,
            msg_type: MsgType::Data,
            flags: 0,
            session_id: 7,
            seq: 11,
            payload: b"hello".to_vec(),
        };
        let encoded = encode_frame(&frame);
        let decoded = decode_frame(&encoded).expect("decode");
        assert_eq!(decoded.session_id, 7);
        assert_eq!(decoded.seq, 11);
        assert_eq!(decoded.payload, b"hello");
    }

    #[test]
    fn decode_rejects_trailing_bytes() {
        let frame = DataFrame {
            version: 1,
            msg_type: MsgType::Data,
            flags: 0,
            session_id: 5,
            seq: 9,
            payload: b"abc".to_vec(),
        };
        let mut encoded = encode_frame(&frame);
        encoded.extend_from_slice(b"trailing");
        assert!(decode_frame(&encoded).is_err());
    }

    #[test]
    fn frame_numbers_preserve_the_experimental_fec_reservation() {
        assert_eq!(MsgType::FecParity as u8, 5);
        assert_eq!(MsgType::AuthAck as u8, 6);
        assert_eq!(MsgType::LegProgress as u8, 7);
    }
}
