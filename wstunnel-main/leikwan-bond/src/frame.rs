use anyhow::{Context, anyhow, bail};
use bytes::Bytes;
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt};
use uuid::Uuid;

const MAGIC: &[u8; 4] = b"LBND";
const VERSION: u8 = 2;
const HEADER_LEN: usize = 32;
const MAX_FRAME_LEN: usize = 4 * 1024 * 1024;
pub const HELLO_CAPABILITY_STARTUP_WARMUP: u16 = 0x0001;

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
    DeliveryC2S = 16,
    DeliveryS2C = 17,
    StartupWarmup = 18,
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
            16 => Some(Self::DeliveryC2S),
            17 => Some(Self::DeliveryS2C),
            18 => Some(Self::StartupWarmup),
            _ => None,
        }
    }
}

#[derive(Debug, Clone)]
pub struct HelloFrame {
    pub session_id: Uuid,
    pub expected_links: u16,
    pub route_id: u32,
    pub capabilities: u16,
    pub token: String,
}

#[derive(Debug, Clone)]
pub enum WireFrame {
    Hello(HelloFrame),
    HelloAck,
    StartupWarmup { payload: Bytes },
    StreamOpen { stream_id: u32 },
    StreamOpenAck { stream_id: u32 },
    DataC2S { stream_id: u32, seq: u64, payload: Bytes },
    AckC2S { stream_id: u32, ack: u64 },
    DeliveryC2S { stream_id: u32, route_id: u32, delivered_bytes: u64 },
    FinC2S { stream_id: u32, seq: u64 },
    DataS2C { stream_id: u32, seq: u64, payload: Bytes },
    AckS2C { stream_id: u32, ack: u64 },
    DeliveryS2C { stream_id: u32, route_id: u32, delivered_bytes: u64 },
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
                (
                    FrameKind::Hello,
                    hello.capabilities,
                    0,
                    0,
                    hello.route_id,
                    payload_len as u32,
                )
            }
            Self::HelloAck => (FrameKind::HelloAck, 0, 0, 0, 0, 0),
            Self::StartupWarmup { payload } => {
                (FrameKind::StartupWarmup, 0, 0, 0, 0, payload.len() as u32)
            }
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
            Self::DeliveryC2S { stream_id, route_id, delivered_bytes }
            | Self::DeliveryS2C { stream_id, route_id, delivered_bytes } => {
                (self.kind(), 0, u64::from(*route_id), *delivered_bytes, *stream_id, 0)
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
            Self::StartupWarmup { .. } => FrameKind::StartupWarmup,
            Self::StreamOpen { .. } => FrameKind::StreamOpen,
            Self::StreamOpenAck { .. } => FrameKind::StreamOpenAck,
            Self::DataC2S { .. } => FrameKind::DataC2S,
            Self::AckC2S { .. } => FrameKind::AckC2S,
            Self::DeliveryC2S { .. } => FrameKind::DeliveryC2S,
            Self::FinC2S { .. } => FrameKind::FinC2S,
            Self::DataS2C { .. } => FrameKind::DataS2C,
            Self::AckS2C { .. } => FrameKind::AckS2C,
            Self::DeliveryS2C { .. } => FrameKind::DeliveryS2C,
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
        | WireFrame::DeliveryC2S { .. }
        | WireFrame::DeliveryS2C { .. }
        | WireFrame::FinC2S { .. }
        | WireFrame::FinS2C { .. } => {}
        WireFrame::StartupWarmup { payload }
        | WireFrame::DataC2S { payload, .. }
        | WireFrame::DataS2C { payload, .. } => {
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
    let flags = u16::from_be_bytes(header[6..8].try_into().unwrap());
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
        FrameKind::Hello => decode_hello(payload, stream_id, flags),
        FrameKind::HelloAck => Ok(WireFrame::HelloAck),
        FrameKind::StartupWarmup => Ok(WireFrame::StartupWarmup {
            payload: Bytes::from(payload),
        }),
        FrameKind::StreamOpen => Ok(WireFrame::StreamOpen { stream_id }),
        FrameKind::StreamOpenAck => Ok(WireFrame::StreamOpenAck { stream_id }),
        FrameKind::DataC2S => Ok(WireFrame::DataC2S {
            stream_id,
            seq,
            payload: Bytes::from(payload),
        }),
        FrameKind::AckC2S => Ok(WireFrame::AckC2S { stream_id, ack }),
        FrameKind::DeliveryC2S => decode_tcp_delivery(stream_id, seq, ack, true),
        FrameKind::FinC2S => Ok(WireFrame::FinC2S { stream_id, seq }),
        FrameKind::DataS2C => Ok(WireFrame::DataS2C {
            stream_id,
            seq,
            payload: Bytes::from(payload),
        }),
        FrameKind::AckS2C => Ok(WireFrame::AckS2C { stream_id, ack }),
        FrameKind::DeliveryS2C => decode_tcp_delivery(stream_id, seq, ack, false),
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

fn decode_tcp_delivery(
    stream_id: u32,
    route_id: u64,
    delivered_bytes: u64,
    client_to_server: bool,
) -> anyhow::Result<WireFrame> {
    let route_id = u32::try_from(route_id).with_context(|| "tcp delivery route id exceeds u32")?;
    if route_id == 0 {
        bail!("tcp delivery route id must be non-zero");
    }
    if delivered_bytes == 0 || delivered_bytes > MAX_FRAME_LEN as u64 {
        bail!("invalid tcp delivery byte count {}", delivered_bytes);
    }

    if client_to_server {
        Ok(WireFrame::DeliveryC2S { stream_id, route_id, delivered_bytes })
    } else {
        Ok(WireFrame::DeliveryS2C { stream_id, route_id, delivered_bytes })
    }
}

fn decode_hello(payload: Vec<u8>, route_id: u32, capabilities: u16) -> anyhow::Result<WireFrame> {
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
        capabilities,
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
            capabilities: HELLO_CAPABILITY_STARTUP_WARMUP,
            token: String::from("token"),
        });

        write_frame(&mut left, &frame).await.unwrap();
        let decoded = read_frame(&mut right).await.unwrap();

        match decoded {
            WireFrame::Hello(hello) => {
                assert_eq!(hello.route_id, 453);
                assert_eq!(hello.expected_links, 5);
                assert_eq!(hello.capabilities, HELLO_CAPABILITY_STARTUP_WARMUP);
                assert_eq!(hello.token, "token");
            }
            other => panic!("unexpected frame: {other:?}"),
        }
    }

    #[tokio::test]
    async fn hello_startup_warmup_capability_only_changes_header_flags() {
        let (mut writer, mut reader) = duplex(4096);
        let frame = WireFrame::Hello(HelloFrame {
            session_id: Uuid::nil(),
            expected_links: 3,
            route_id: 693,
            capabilities: HELLO_CAPABILITY_STARTUP_WARMUP,
            token: String::from("token"),
        });

        write_frame(&mut writer, &frame).await.unwrap();
        let mut encoded = vec![0u8; HEADER_LEN + 20 + 5];
        reader.read_exact(&mut encoded).await.unwrap();

        assert_eq!(&encoded[6..8], &HELLO_CAPABILITY_STARTUP_WARMUP.to_be_bytes());
        assert_eq!(u32::from_be_bytes(encoded[28..32].try_into().unwrap()), 25);
        assert_eq!(&encoded[HEADER_LEN..HEADER_LEN + 16], Uuid::nil().as_bytes());
        assert_eq!(&encoded[HEADER_LEN + 16..HEADER_LEN + 18], &3u16.to_be_bytes());
        assert_eq!(&encoded[HEADER_LEN + 18..HEADER_LEN + 20], &5u16.to_be_bytes());
        assert_eq!(&encoded[HEADER_LEN + 20..], b"token");
    }

    #[tokio::test]
    async fn legacy_hello_without_capabilities_roundtrips_as_unsupported() {
        let (mut left, mut right) = duplex(4096);
        let frame = WireFrame::Hello(HelloFrame {
            session_id: Uuid::nil(),
            expected_links: 1,
            route_id: 0,
            capabilities: 0,
            token: String::from("legacy"),
        });

        write_frame(&mut left, &frame).await.unwrap();
        match read_frame(&mut right).await.unwrap() {
            WireFrame::Hello(hello) => assert_eq!(hello.capabilities, 0),
            other => panic!("unexpected frame: {other:?}"),
        }
    }

    #[tokio::test]
    async fn roundtrips_per_route_tcp_delivery_feedback() {
        let (mut left, mut right) = duplex(4096);
        let frame = WireFrame::DeliveryC2S {
            stream_id: 42,
            route_id: 453,
            delivered_bytes: 262_144,
        };

        write_frame(&mut left, &frame).await.unwrap();
        let decoded = read_frame(&mut right).await.unwrap();

        match decoded {
            WireFrame::DeliveryC2S { stream_id, route_id, delivered_bytes } => {
                assert_eq!(stream_id, 42);
                assert_eq!(route_id, 453);
                assert_eq!(delivered_bytes, 262_144);
            }
            other => panic!("unexpected frame: {other:?}"),
        }
    }

    #[tokio::test]
    async fn roundtrips_startup_warmup_payload() {
        let (mut left, mut right) = duplex(8192);
        let frame = WireFrame::StartupWarmup {
            payload: Bytes::from(vec![0x5a; 1024]),
        };

        write_frame(&mut left, &frame).await.unwrap();
        let decoded = read_frame(&mut right).await.unwrap();

        match decoded {
            WireFrame::StartupWarmup { payload } => {
                assert_eq!(payload, Bytes::from(vec![0x5a; 1024]));
            }
            other => panic!("unexpected frame: {other:?}"),
        }
    }
}
