use std::collections::VecDeque;
use std::fmt;
use std::time::Duration;

use serde::{Deserialize, Serialize};
use tokio::time::Instant;

pub const PROTOCOL_VERSION: u8 = 2;
pub const LEG_PROGRESS_VERSION: u8 = 1;
pub const LEG_PROGRESS_BYTES: usize = 44;
pub const LEG_PROGRESS_CAPABILITY: &str = "leg-progress-v1";

#[derive(Clone, Copy, Debug, Eq, Hash, PartialEq)]
#[repr(u8)]
pub enum Direction {
    ClientToSidecar = 0,
    SidecarToClient = 1,
}

impl TryFrom<u8> for Direction {
    type Error = ProgressError;

    fn try_from(value: u8) -> Result<Self, Self::Error> {
        match value {
            0 => Ok(Self::ClientToSidecar),
            1 => Ok(Self::SidecarToClient),
            _ => Err(ProgressError::InvalidDirection),
        }
    }
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
pub struct AuthCapabilities {
    pub protocol_version: u8,
    pub capabilities: Vec<String>,
    pub connection_epoch: u64,
}

impl AuthCapabilities {
    pub fn leg_progress(connection_epoch: u64) -> Self {
        Self {
            protocol_version: PROTOCOL_VERSION,
            capabilities: vec![LEG_PROGRESS_CAPABILITY.to_string()],
            connection_epoch,
        }
    }

    pub fn requests_leg_progress(&self) -> bool {
        self.protocol_version == PROTOCOL_VERSION
            && self.connection_epoch != 0
            && self
                .capabilities
                .iter()
                .any(|capability| capability == LEG_PROGRESS_CAPABILITY)
    }
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
pub struct AuthAck {
    pub protocol_version: u8,
    pub capabilities: Vec<String>,
    pub connection_epoch: u64,
}

impl AuthAck {
    pub fn leg_progress(connection_epoch: u64) -> Self {
        Self {
            protocol_version: PROTOCOL_VERSION,
            capabilities: vec![LEG_PROGRESS_CAPABILITY.to_string()],
            connection_epoch,
        }
    }

    pub fn matches(&self, connection_epoch: u64) -> bool {
        self.protocol_version == PROTOCOL_VERSION
            && self.connection_epoch == connection_epoch
            && self
                .capabilities
                .iter()
                .any(|capability| capability == LEG_PROGRESS_CAPABILITY)
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct LegProgress {
    pub progress_version: u8,
    pub direction: Direction,
    pub connection_epoch: u64,
    pub decoded_frames: u64,
    pub decoded_wire_bytes: u64,
    pub udp_accepted_frames: u64,
    pub udp_accepted_wire_bytes: u64,
}

impl LegProgress {
    pub const fn accepted(
        direction: Direction,
        connection_epoch: u64,
        decoded_frames: u64,
        decoded_wire_bytes: u64,
        udp_accepted_frames: u64,
        udp_accepted_wire_bytes: u64,
    ) -> Self {
        Self {
            progress_version: LEG_PROGRESS_VERSION,
            direction,
            connection_epoch,
            decoded_frames,
            decoded_wire_bytes,
            udp_accepted_frames,
            udp_accepted_wire_bytes,
        }
    }

    pub fn encode(self) -> [u8; LEG_PROGRESS_BYTES] {
        let mut bytes = [0_u8; LEG_PROGRESS_BYTES];
        bytes[0] = self.progress_version;
        bytes[1] = self.direction as u8;
        bytes[4..12].copy_from_slice(&self.connection_epoch.to_be_bytes());
        bytes[12..20].copy_from_slice(&self.decoded_frames.to_be_bytes());
        bytes[20..28].copy_from_slice(&self.decoded_wire_bytes.to_be_bytes());
        bytes[28..36].copy_from_slice(&self.udp_accepted_frames.to_be_bytes());
        bytes[36..44].copy_from_slice(&self.udp_accepted_wire_bytes.to_be_bytes());
        bytes
    }

    pub fn decode(bytes: &[u8]) -> Result<Self, ProgressError> {
        if bytes.len() != LEG_PROGRESS_BYTES {
            return Err(ProgressError::InvalidLength);
        }
        if bytes[0] != LEG_PROGRESS_VERSION {
            return Err(ProgressError::InvalidVersion);
        }
        if bytes[2] != 0 || bytes[3] != 0 {
            return Err(ProgressError::ReservedBits);
        }
        let direction = Direction::try_from(bytes[1])?;
        Ok(Self {
            progress_version: bytes[0],
            direction,
            connection_epoch: u64::from_be_bytes(bytes[4..12].try_into().unwrap()),
            decoded_frames: u64::from_be_bytes(bytes[12..20].try_into().unwrap()),
            decoded_wire_bytes: u64::from_be_bytes(bytes[20..28].try_into().unwrap()),
            udp_accepted_frames: u64::from_be_bytes(bytes[28..36].try_into().unwrap()),
            udp_accepted_wire_bytes: u64::from_be_bytes(bytes[36..44].try_into().unwrap()),
        })
    }
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum ProgressError {
    InvalidLength,
    InvalidVersion,
    ReservedBits,
    InvalidDirection,
    DirectionMismatch,
    EpochMismatch,
    Regression,
    AboveLocalSentTotal,
    AcceptedAboveDecoded,
}

impl fmt::Display for ProgressError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str(match self {
            Self::InvalidLength => "invalid progress length",
            Self::InvalidVersion => "invalid progress version",
            Self::ReservedBits => "progress reserved bits are nonzero",
            Self::InvalidDirection => "invalid progress direction",
            Self::DirectionMismatch => "progress direction mismatch",
            Self::EpochMismatch => "progress epoch mismatch",
            Self::Regression => "progress counters regressed",
            Self::AboveLocalSentTotal => "progress exceeds local sent total",
            Self::AcceptedAboveDecoded => "accepted progress exceeds decoded progress",
        })
    }
}

impl std::error::Error for ProgressError {}

#[derive(Clone, Debug)]
pub struct ProgressValidator {
    expected_direction: Direction,
    connection_epoch: u64,
    locally_sent_frames: u64,
    locally_sent_wire_bytes: u64,
    accepted: Option<LegProgress>,
}

#[derive(Clone, Debug)]
pub struct ProgressEmitter {
    direction: Direction,
    connection_epoch: u64,
    decoded_frames: u64,
    decoded_wire_bytes: u64,
    udp_accepted_frames: u64,
    udp_accepted_wire_bytes: u64,
    emitted_decoded_frames: u64,
    emitted_decoded_wire_bytes: u64,
    emitted_udp_accepted_frames: u64,
    emitted_udp_accepted_wire_bytes: u64,
    last_emit_us: u64,
}

impl ProgressEmitter {
    pub const fn new(direction: Direction, connection_epoch: u64, now_us: u64) -> Self {
        Self {
            direction,
            connection_epoch,
            decoded_frames: 0,
            decoded_wire_bytes: 0,
            udp_accepted_frames: 0,
            udp_accepted_wire_bytes: 0,
            emitted_decoded_frames: 0,
            emitted_decoded_wire_bytes: 0,
            emitted_udp_accepted_frames: 0,
            emitted_udp_accepted_wire_bytes: 0,
            last_emit_us: now_us,
        }
    }

    pub fn record_decoded(&mut self, wire_bytes: usize) {
        self.decoded_frames = self.decoded_frames.saturating_add(1);
        self.decoded_wire_bytes = self.decoded_wire_bytes.saturating_add(wire_bytes as u64);
    }

    pub fn record_udp_accepted(&mut self, wire_bytes: usize) {
        self.udp_accepted_frames = self
            .udp_accepted_frames
            .saturating_add(1)
            .min(self.decoded_frames);
        self.udp_accepted_wire_bytes = self
            .udp_accepted_wire_bytes
            .saturating_add(wire_bytes as u64)
            .min(self.decoded_wire_bytes);
    }

    pub fn maybe_emit(
        &mut self,
        now_us: u64,
        interval_bytes: u64,
        interval_frames: u64,
        interval_ms: u64,
    ) -> Option<LegProgress> {
        let dirty = self.decoded_frames != self.emitted_decoded_frames
            || self.decoded_wire_bytes != self.emitted_decoded_wire_bytes
            || self.udp_accepted_frames != self.emitted_udp_accepted_frames
            || self.udp_accepted_wire_bytes != self.emitted_udp_accepted_wire_bytes;
        if !dirty {
            return None;
        }
        let bytes_due = self
            .decoded_wire_bytes
            .saturating_sub(self.emitted_decoded_wire_bytes)
            >= interval_bytes;
        let frames_due = self
            .decoded_frames
            .saturating_sub(self.emitted_decoded_frames)
            >= interval_frames;
        let timer_due =
            now_us.saturating_sub(self.last_emit_us) >= interval_ms.saturating_mul(1_000);
        if !(bytes_due || frames_due || timer_due) {
            return None;
        }
        let progress = LegProgress::accepted(
            self.direction,
            self.connection_epoch,
            self.decoded_frames,
            self.decoded_wire_bytes,
            self.udp_accepted_frames,
            self.udp_accepted_wire_bytes,
        );
        self.emitted_decoded_frames = self.decoded_frames;
        self.emitted_decoded_wire_bytes = self.decoded_wire_bytes;
        self.emitted_udp_accepted_frames = self.udp_accepted_frames;
        self.emitted_udp_accepted_wire_bytes = self.udp_accepted_wire_bytes;
        self.last_emit_us = now_us;
        Some(progress)
    }

    pub const fn connection_epoch(&self) -> u64 {
        self.connection_epoch
    }

    pub fn retry_cumulative(&mut self) {
        self.emitted_decoded_frames = 0;
        self.emitted_decoded_wire_bytes = 0;
        self.emitted_udp_accepted_frames = 0;
        self.emitted_udp_accepted_wire_bytes = 0;
        self.last_emit_us = 0;
    }
}

impl ProgressValidator {
    pub const fn new(expected_direction: Direction, connection_epoch: u64) -> Self {
        Self {
            expected_direction,
            connection_epoch,
            locally_sent_frames: 0,
            locally_sent_wire_bytes: 0,
            accepted: None,
        }
    }

    pub fn set_locally_sent_totals(&mut self, frames: u64, wire_bytes: u64) {
        self.locally_sent_frames = frames;
        self.locally_sent_wire_bytes = wire_bytes;
    }

    pub fn accept(&mut self, progress: &LegProgress) -> Result<(), ProgressError> {
        if progress.progress_version != LEG_PROGRESS_VERSION {
            return Err(ProgressError::InvalidVersion);
        }
        if progress.direction != self.expected_direction {
            return Err(ProgressError::DirectionMismatch);
        }
        if progress.connection_epoch != self.connection_epoch {
            return Err(ProgressError::EpochMismatch);
        }
        if progress.udp_accepted_frames > progress.decoded_frames
            || progress.udp_accepted_wire_bytes > progress.decoded_wire_bytes
        {
            return Err(ProgressError::AcceptedAboveDecoded);
        }
        if progress.decoded_frames > self.locally_sent_frames
            || progress.decoded_wire_bytes > self.locally_sent_wire_bytes
            || progress.udp_accepted_frames > self.locally_sent_frames
            || progress.udp_accepted_wire_bytes > self.locally_sent_wire_bytes
        {
            return Err(ProgressError::AboveLocalSentTotal);
        }
        if let Some(previous) = self.accepted {
            if progress.decoded_frames < previous.decoded_frames
                || progress.decoded_wire_bytes < previous.decoded_wire_bytes
                || progress.udp_accepted_frames < previous.udp_accepted_frames
                || progress.udp_accepted_wire_bytes < previous.udp_accepted_wire_bytes
            {
                return Err(ProgressError::Regression);
            }
        }
        self.accepted = Some(*progress);
        Ok(())
    }

    pub const fn snapshot(&self) -> Option<LegProgress> {
        self.accepted
    }
}

#[derive(Clone, Debug)]
pub struct RttCheckpoints {
    entries: VecDeque<(u64, Instant)>,
    capacity: usize,
}

impl RttCheckpoints {
    pub fn new(capacity: usize) -> Self {
        Self {
            entries: VecDeque::with_capacity(capacity.max(1)),
            capacity: capacity.max(1),
        }
    }

    pub fn record(&mut self, cumulative_wire_bytes: u64, now: Instant) {
        if let Some((last, _)) = self.entries.back() {
            if cumulative_wire_bytes <= *last {
                return;
            }
        }
        while self.entries.len() >= self.capacity {
            self.entries.pop_front();
        }
        self.entries.push_back((cumulative_wire_bytes, now));
    }

    pub fn acknowledge(&mut self, cumulative_wire_bytes: u64, now: Instant) -> Option<Duration> {
        let mut crossed = None;
        while self
            .entries
            .front()
            .is_some_and(|(position, _)| *position <= cumulative_wire_bytes)
        {
            crossed = self.entries.pop_front();
        }
        crossed.map(|(_, sent_at)| now.saturating_duration_since(sent_at))
    }

    pub fn len(&self) -> usize {
        self.entries.len()
    }

    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }
}

#[cfg(test)]
mod tests {
    use std::time::Duration;

    use tokio::time::Instant;

    use super::*;

    #[test]
    fn leg_progress_is_exactly_44_network_order_bytes() {
        let progress = LegProgress {
            progress_version: 1,
            direction: Direction::ClientToSidecar,
            connection_epoch: 7,
            decoded_frames: 11,
            decoded_wire_bytes: 13,
            udp_accepted_frames: 17,
            udp_accepted_wire_bytes: 19,
        };
        let encoded = progress.encode();
        assert_eq!(encoded.len(), 44);
        assert_eq!(LegProgress::decode(&encoded).unwrap(), progress);
    }

    #[test]
    fn progress_validation_does_not_mutate_on_invalid_input() {
        let mut validator = ProgressValidator::new(Direction::ClientToSidecar, 9);
        validator.set_locally_sent_totals(10, 200);
        validator
            .accept(&LegProgress::accepted(
                Direction::ClientToSidecar,
                9,
                1,
                100,
                1,
                100,
            ))
            .unwrap();
        let before = validator.snapshot();
        let bad = LegProgress::accepted(Direction::ClientToSidecar, 8, 2, 150, 2, 150);
        assert_eq!(
            validator.accept(&bad).unwrap_err(),
            ProgressError::EpochMismatch
        );
        assert_eq!(validator.snapshot(), before);
    }

    #[test]
    fn progress_validation_rejects_regression_and_impossible_credit() {
        let mut validator = ProgressValidator::new(Direction::ClientToSidecar, 5);
        validator.set_locally_sent_totals(10, 500);
        validator
            .accept(&LegProgress::accepted(
                Direction::ClientToSidecar,
                5,
                2,
                200,
                1,
                100,
            ))
            .unwrap();

        assert_eq!(
            validator
                .accept(&LegProgress::accepted(
                    Direction::ClientToSidecar,
                    5,
                    1,
                    150,
                    1,
                    100,
                ))
                .unwrap_err(),
            ProgressError::Regression
        );
        assert_eq!(
            validator
                .accept(&LegProgress::accepted(
                    Direction::ClientToSidecar,
                    5,
                    3,
                    300,
                    4,
                    400,
                ))
                .unwrap_err(),
            ProgressError::AcceptedAboveDecoded
        );
        assert_eq!(
            validator
                .accept(&LegProgress::accepted(
                    Direction::ClientToSidecar,
                    5,
                    3,
                    501,
                    2,
                    250,
                ))
                .unwrap_err(),
            ProgressError::AboveLocalSentTotal
        );
    }

    #[test]
    fn progress_codec_rejects_reserved_bits_and_invalid_direction() {
        let mut bytes = LegProgress::accepted(Direction::ClientToSidecar, 1, 1, 1, 1, 1).encode();
        bytes[2] = 1;
        assert_eq!(
            LegProgress::decode(&bytes).unwrap_err(),
            ProgressError::ReservedBits
        );
        bytes[2] = 0;
        bytes[1] = 9;
        assert_eq!(
            LegProgress::decode(&bytes).unwrap_err(),
            ProgressError::InvalidDirection
        );
    }

    #[test]
    fn rtt_checkpoint_returns_newest_crossed_sample_and_stays_bounded() {
        let start = Instant::now();
        let mut checkpoints = RttCheckpoints::new(2);
        checkpoints.record(100, start);
        checkpoints.record(200, start + Duration::from_millis(2));
        checkpoints.record(300, start + Duration::from_millis(4));
        assert_eq!(checkpoints.len(), 2);
        assert_eq!(
            checkpoints.acknowledge(250, start + Duration::from_millis(12)),
            Some(Duration::from_millis(10))
        );
        assert_eq!(checkpoints.len(), 1);
    }

    #[test]
    fn progress_emits_on_bytes_frames_or_timer_whichever_is_first() {
        let mut bytes = ProgressEmitter::new(Direction::ClientToSidecar, 1, 0);
        bytes.record_decoded(65_536);
        bytes.record_udp_accepted(65_536);
        assert!(bytes.maybe_emit(1, 65_536, 32, 5).is_some());

        let mut frames = ProgressEmitter::new(Direction::ClientToSidecar, 2, 0);
        for _ in 0..32 {
            frames.record_decoded(1);
            frames.record_udp_accepted(1);
        }
        assert!(frames.maybe_emit(1, 65_536, 32, 5).is_some());

        let mut timer = ProgressEmitter::new(Direction::ClientToSidecar, 3, 0);
        timer.record_decoded(1);
        assert!(timer.maybe_emit(4_999, 65_536, 32, 5).is_none());
        assert!(timer.maybe_emit(5_000, 65_536, 32, 5).is_some());
    }
}
