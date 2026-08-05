use anyhow::{Context, anyhow, bail};
use bytes::Bytes;
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt};
use uuid::Uuid;

const MAGIC: &[u8; 4] = b"LBND";
const VERSION: u8 = 2;
const HEADER_LEN: usize = 32;
const MAX_FRAME_LEN: usize = 4 * 1024 * 1024;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[repr(u8)]
pub enum FrameKind {
    Hello = 1,
    HelloAck = 2,
    StreamOpen = 3,
    StreamOpenAck = 4,
    DataC2S = 5,
    AckC2S = 6,
    FinC2S = 7,
    DataS2C = 8,
    AckS2C = 9,
    FinS2C = 10,
    Reset = 11,
    UdpFragC2S = 12,
    UdpFragS2C = 13,
    UdpAckC2S = 14,
    UdpAckS2C = 15,
}

impl FrameKind {
    fn from_u8(value: u8) -> Option<Self> {
        match value {
            1 => Some(Self::Hello),
            2 => Some(Self::HelloAck),
            3 => Some(Self::StreamOpen),
            4 => Some(Self::StreamOpenAck),
            5 => Some(Self::DataC2S),
            6 => Some(Self::AckC2S),
            7 => Some(Self::FinC2S),
            8 => Some(Self::DataS2C),
            9 => Some(Self::AckS2C),
            10 => Some(Self::FinS2C),
            11 => Some(Self::Reset),
            12 => Some(Self::UdpFragC2S),
            13 => Some(Self::UdpFragS2C),
            14 => Some(Self::UdpAckC2S),
            15 => Some(Self::UdpAckS2C),
            _ => None,
        }
    }
}

#[derive(Debug, Clone)]
pub struct HelloFrame {
    pub session_id: Uuid,
    pub expected_links: u16,
    pub route_id: u32,
    pub token: String,
}

#[derive(Debug, Clone)]
pub enum WireFrame {
    Hello(HelloFrame),
    HelloAck,
    StreamOpen { stream_id: u32 },
    StreamOpenAck { stream_id: u32 },
    DataC2S { stream_id: u32, seq: u64, payload: Bytes },
    AckC2S { stream_id: u32, ack: u64 },
    FinC2S { stream_id: u32, seq: u64 },
    DataS2C { stream_id: u32, seq: u64, payload: Bytes },
    AckS2C { stream_id: u32, ack: u64 },
    FinS2C { stream_id: u32, seq: u64 },
    Reset { stream_id: u32, reason: String },
    UdpFragC2S {
        peer_id: u32,
        datagram_id: u64,
        frag_index: u16,
        frag_total: u16,
        payload: Bytes,
    },
    UdpFragS2C {
        peer_id: u32,
        datagram_id: u64,
        frag_index: u16,
        frag_total: u16,
        payload: Bytes,
    },
    UdpAckC2S {
        peer_id: u32,
        datagram_id: u64,
    },
    UdpAckS2C {
        peer_id: u32,
        datagram_id: u64,
    },
}

impl WireFrame {
    fn header(&self) -> (FrameKind, u16, u64, u64, u32, u32) {
        match self {
            Self::Hello(hello) => {
                let payload_len = 16usize + 2usize + 2usize + hello.token.len();
                (FrameKind::Hello, 0, 0, 0, hello.route_id, payload_len as u32)
            }
            Self::HelloAck => (FrameKind::HelloAck, 0, 0, 0, 0, 0),
            Self::StreamOpen { stream_id } => (FrameKind::StreamOpen, 0, 0, 0, *stream_id, 0),
            Self::StreamOpenAck { stream_id } => (FrameKind::StreamOpenAck, 0, 0, 0, *stream_id, 0),
            Self::DataC2S {
                stream_id,
                seq,
                payload,
            }
            | Self::DataS2C {
                stream_id,
                seq,
                payload,
            } => (self.kind(), 0, *seq, 0, *stream_id, payload.len() as u32),
            Self::AckC2S { stream_id, ack } | Self::AckS2C { stream_id, ack } => {
                (self.kind(), 0, 0, *ack, *stream_id, 0)
            }
            Self::FinC2S { stream_id, seq } | Self::FinS2C { stream_id, seq } => {
                (self.kind(), 0, *seq, 0, *stream_id, 0)
            }
            Self::Reset { stream_id, reason } => (FrameKind::Reset, 0, 0, 0, *stream_id, reason.len() as u32),
            Self::UdpFragC2S { payload, .. } | Self::UdpFragS2C { payload, .. } => {
                (self.kind(), 0, 0, 0, 0, (16usize + payload.len()) as u32)
            }
            Self::UdpAckC2S { .. } | Self::UdpAckS2C { .. } => (self.kind(), 0, 0, 0, 0, 12),
        }
    }

    fn kind(&self) -> FrameKind {
        match self {
            Self::Hello(_) => FrameKind::Hello,
            Self::HelloAck => FrameKind::HelloAck,
            Self::StreamOpen { .. } => FrameKind::StreamOpen,
            Self::StreamOpenAck { .. } => FrameKind::StreamOpenAck,
            Self::DataC2S { .. } => FrameKind::DataC2S,
            Self::AckC2S { .. } => FrameKind::AckC2S,
            Self::FinC2S { .. } => FrameKind::FinC2S,
            Self::DataS2C { .. } => FrameKind::DataS2C,
            Self::AckS2C { .. } => FrameKind::AckS2C,
            Self::FinS2C { .. } => FrameKind::FinS2C,
            Self::Reset { .. } => FrameKind::Reset,
            Self::UdpFragC2S { .. } => FrameKind::UdpFragC2S,
            Self::UdpFragS2C { .. } => FrameKind::UdpFragS2C,
            Self::UdpAckC2S { .. } => FrameKind::UdpAckC2S,
            Self::UdpAckS2C { .. } => FrameKind::UdpAckS2C,
        }
    }
}

pub async fn write_frame<W>(writer: &mut W, frame: &WireFrame) -> anyhow::Result<()>
where
    W: AsyncWrite + Unpin,
{
    let (kind, flags, seq, ack, stream_id, len) = frame.header();
    let mut header = [0u8; HEADER_LEN];
    header[0..4].copy_from_slice(MAGIC);
    header[4] = VERSION;
    header[5] = kind as u8;
    header[6..8].copy_from_slice(&flags.to_be_bytes());
    header[8..16].copy_from_slice(&seq.to_be_bytes());
    header[16..24].copy_from_slice(&ack.to_be_bytes());
    header[24..28].copy_from_slice(&stream_id.to_be_bytes());
    header[28..32].copy_from_slice(&len.to_be_bytes());

    writer.write_all(&header).await?;
    match frame {
        WireFrame::Hello(hello) => {
            writer.write_all(hello.session_id.as_bytes()).await?;
            writer.write_all(&hello.expected_links.to_be_bytes()).await?;
            writer.write_all(&(hello.token.len() as u16).to_be_bytes()).await?;
            writer.write_all(hello.token.as_bytes()).await?;
        }
        WireFrame::HelloAck
        | WireFrame::StreamOpen { .. }
        | WireFrame::StreamOpenAck { .. }
        | WireFrame::AckC2S { .. }
        | WireFrame::AckS2C { .. }
        | WireFrame::FinC2S { .. }
        | WireFrame::FinS2C { .. } => {}
        WireFrame::DataC2S { payload, .. } | WireFrame::DataS2C { payload, .. } => {
            writer.write_all(payload.as_ref()).await?;
        }
        WireFrame::Reset { reason, .. } => {
            writer.write_all(reason.as_bytes()).await?;
        }
        WireFrame::UdpFragC2S {
            peer_id,
            datagram_id,
            frag_index,
            frag_total,
            payload,
        }
        | WireFrame::UdpFragS2C {
            peer_id,
            datagram_id,
            frag_index,
            frag_total,
            payload,
        } => {
            writer.write_all(&peer_id.to_be_bytes()).await?;
            writer.write_all(&datagram_id.to_be_bytes()).await?;
            writer.write_all(&frag_index.to_be_bytes()).await?;
            writer.write_all(&frag_total.to_be_bytes()).await?;
            writer.write_all(payload.as_ref()).await?;
        }
        WireFrame::UdpAckC2S {
            peer_id,
            datagram_id,
        }
        | WireFrame::UdpAckS2C {
            peer_id,
            datagram_id,
        } => {
            writer.write_all(&peer_id.to_be_bytes()).await?;
            writer.write_all(&datagram_id.to_be_bytes()).await?;
        }
    }

    writer.flush().await?;
    Ok(())
}

pub async fn read_frame<R>(reader: &mut R) -> anyhow::Result<WireFrame>
where
    R: AsyncRead + Unpin,
{
    let mut header = [0u8; HEADER_LEN];
    reader
        .read_exact(&mut header)
        .await
        .with_context(|| "failed to read bond frame header")?;

    if &header[0..4] != MAGIC {
        bail!("invalid bond frame magic");
    }
    if header[4] != VERSION {
        bail!("unsupported bond frame version {}", header[4]);
    }

    let kind = FrameKind::from_u8(header[5]).ok_or_else(|| anyhow!("unknown bond frame kind {}", header[5]))?;
    let seq = u64::from_be_bytes(header[8..16].try_into().unwrap());
    let ack = u64::from_be_bytes(header[16..24].try_into().unwrap());
    let stream_id = u32::from_be_bytes(header[24..28].try_into().unwrap());
    let len = u32::from_be_bytes(header[28..32].try_into().unwrap()) as usize;
    if len > MAX_FRAME_LEN {
        bail!("bond frame too large: {}", len);
    }

    let mut payload = vec![0u8; len];
    if len > 0 {
        reader
            .read_exact(&mut payload)
            .await
            .with_context(|| "failed to read bond frame payload")?;
    }

    match kind {
        FrameKind::Hello => decode_hello(payload, stream_id),
        FrameKind::HelloAck => Ok(WireFrame::HelloAck),
        FrameKind::StreamOpen => Ok(WireFrame::StreamOpen { stream_id }),
        FrameKind::StreamOpenAck => Ok(WireFrame::StreamOpenAck { stream_id }),
        FrameKind::DataC2S => Ok(WireFrame::DataC2S {
            stream_id,
            seq,
            payload: Bytes::from(payload),
        }),
        FrameKind::AckC2S => Ok(WireFrame::AckC2S { stream_id, ack }),
        FrameKind::FinC2S => Ok(WireFrame::FinC2S { stream_id, seq }),
        FrameKind::DataS2C => Ok(WireFrame::DataS2C {
            stream_id,
            seq,
            payload: Bytes::from(payload),
        }),
        FrameKind::AckS2C => Ok(WireFrame::AckS2C { stream_id, ack }),
        FrameKind::FinS2C => Ok(WireFrame::FinS2C { stream_id, seq }),
        FrameKind::Reset => Ok(WireFrame::Reset {
            stream_id,
            reason: String::from_utf8(payload).unwrap_or_else(|_| String::from("remote_reset")),
        }),
        FrameKind::UdpFragC2S => decode_udp_fragment(payload, true),
        FrameKind::UdpFragS2C => decode_udp_fragment(payload, false),
        FrameKind::UdpAckC2S => decode_udp_ack(payload, true),
        FrameKind::UdpAckS2C => decode_udp_ack(payload, false),
    }
}

fn decode_hello(payload: Vec<u8>, route_id: u32) -> anyhow::Result<WireFrame> {
    if payload.len() < 20 {
        bail!("invalid hello payload length {}", payload.len());
    }

    let session_id = Uuid::from_slice(&payload[0..16]).with_context(|| "invalid hello session id")?;
    let expected_links = u16::from_be_bytes(payload[16..18].try_into().unwrap());
    let token_len = u16::from_be_bytes(payload[18..20].try_into().unwrap()) as usize;
    if payload.len() != 20 + token_len {
        bail!("hello token length mismatch");
    }

    let token = String::from_utf8(payload[20..].to_vec()).with_context(|| "hello token must be utf-8")?;
    Ok(WireFrame::Hello(HelloFrame {
        session_id,
        expected_links,
        route_id,
        token,
    }))
}

fn decode_udp_fragment(payload: Vec<u8>, client_to_server: bool) -> anyhow::Result<WireFrame> {
    if payload.len() < 16 {
        bail!("invalid udp fragment payload length {}", payload.len());
    }
    let peer_id = u32::from_be_bytes(payload[0..4].try_into().unwrap());
    let datagram_id = u64::from_be_bytes(payload[4..12].try_into().unwrap());
    let frag_index = u16::from_be_bytes(payload[12..14].try_into().unwrap());
    let frag_total = u16::from_be_bytes(payload[14..16].try_into().unwrap());
    let payload = Bytes::from(payload[16..].to_vec());
    if frag_total == 0 {
        bail!("udp fragment total cannot be zero");
    }
    if frag_index >= frag_total {
        bail!("udp fragment index {} out of range {}", frag_index, frag_total);
    }

    if client_to_server {
        Ok(WireFrame::UdpFragC2S {
            peer_id,
            datagram_id,
            frag_index,
            frag_total,
            payload,
        })
    } else {
        Ok(WireFrame::UdpFragS2C {
            peer_id,
            datagram_id,
            frag_index,
            frag_total,
            payload,
        })
    }
}

fn decode_udp_ack(payload: Vec<u8>, client_to_server: bool) -> anyhow::Result<WireFrame> {
    if payload.len() != 12 {
        bail!("invalid udp ack payload length {}", payload.len());
    }
    let peer_id = u32::from_be_bytes(payload[0..4].try_into().unwrap());
    let datagram_id = u64::from_be_bytes(payload[4..12].try_into().unwrap());
    if client_to_server {
        Ok(WireFrame::UdpAckC2S {
            peer_id,
            datagram_id,
        })
    } else {
        Ok(WireFrame::UdpAckS2C {
            peer_id,
            datagram_id,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tokio::io::duplex;

    #[tokio::test]
    async fn roundtrips_stream_frames_with_stream_id() {
        let (mut left, mut right) = duplex(4096);
        let frame = WireFrame::DataC2S {
            stream_id: 42,
            seq: 128,
            payload: Bytes::from_static(b"hello"),
        };

        write_frame(&mut left, &frame).await.unwrap();
        let decoded = read_frame(&mut right).await.unwrap();

        match decoded {
            WireFrame::DataC2S {
                stream_id,
                seq,
                payload,
            } => {
                assert_eq!(stream_id, 42);
                assert_eq!(seq, 128);
                assert_eq!(payload, Bytes::from_static(b"hello"));
            }
            other => panic!("unexpected frame: {other:?}"),
        }
    }

    #[tokio::test]
    async fn roundtrips_transport_reset() {
        let (mut left, mut right) = duplex(4096);
        let frame = WireFrame::Reset {
            stream_id: 0,
            reason: String::from("transport_reset"),
        };

        write_frame(&mut left, &frame).await.unwrap();
        let decoded = read_frame(&mut right).await.unwrap();

        match decoded {
            WireFrame::Reset { stream_id, reason } => {
                assert_eq!(stream_id, 0);
                assert_eq!(reason, "transport_reset");
            }
            other => panic!("unexpected frame: {other:?}"),
        }
    }

    #[tokio::test]
    async fn hello_route_id_roundtrips_in_header_without_changing_payload() {
        let (mut left, mut right) = duplex(4096);
        let frame = WireFrame::Hello(HelloFrame {
            session_id: Uuid::nil(),
            expected_links: 5,
            route_id: 453,
            token: String::from("token"),
        });

        write_frame(&mut left, &frame).await.unwrap();
        let decoded = read_frame(&mut right).await.unwrap();

        match decoded {
            WireFrame::Hello(hello) => {
                assert_eq!(hello.route_id, 453);
                assert_eq!(hello.expected_links, 5);
                assert_eq!(hello.token, "token");
            }
            other => panic!("unexpected frame: {other:?}"),
        }
    }
}
