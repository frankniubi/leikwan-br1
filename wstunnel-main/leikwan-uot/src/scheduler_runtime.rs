use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;
use std::time::{SystemTime, UNIX_EPOCH};

use bytes::Bytes;
use tokio::sync::{mpsc, oneshot};
use tokio::time::{Duration, Instant, MissedTickBehavior};

use crate::config::SchedulerConfig;
use crate::frame::{DataFrame, MsgType};
use crate::progress::{AuthAck, LegProgress};
use crate::scheduler::{
    Admission, LegRegistration, ReservationStage, ScheduleOutcome, Scheduler, SchedulerSessionKey,
    SentTotals, TrafficClass,
};
use crate::telemetry::{StatsSnapshot, UdpErrorKind};

#[derive(Debug)]
pub struct RuntimeChunk {
    pub leg_id: u64,
    pub connection_epoch: u64,
    pub reservation_ids: Vec<u64>,
    pub frames: Vec<DataFrame>,
}

#[derive(Clone, Debug, serde::Serialize)]
pub struct RuntimeSchedulerSnapshot {
    pub queue_packets: usize,
    pub userspace_pending_bytes: usize,
    pub ingress_channel_full: u64,
    #[serde(flatten)]
    pub telemetry: StatsSnapshot,
}

#[derive(Debug)]
pub enum SchedulerCommand {
    Admit {
        session: SchedulerSessionKey,
        sequence: u64,
        payload: Bytes,
        class: TrafficClass,
    },
    LegConnected {
        registration: LegRegistration,
        data_tx: mpsc::Sender<RuntimeChunk>,
    },
    AuthAcknowledged {
        leg_id: u64,
        ack: AuthAck,
    },
    Progress {
        leg_id: u64,
        progress: LegProgress,
    },
    InvalidControl,
    UdpEgressError {
        kind: UdpErrorKind,
        payload_bytes: usize,
        wire_bytes: usize,
    },
    WriterStarted {
        leg_id: u64,
        connection_epoch: u64,
        reservation_ids: Vec<u64>,
    },
    WriterAccepted {
        leg_id: u64,
        connection_epoch: u64,
        reservation_ids: Vec<u64>,
    },
    WriterFailed {
        leg_id: u64,
        connection_epoch: u64,
        reservation_ids: Vec<u64>,
    },
    LegDisconnected {
        leg_id: u64,
        connection_epoch: u64,
    },
    Snapshot(oneshot::Sender<RuntimeSchedulerSnapshot>),
    ReservationStage {
        reservation_id: u64,
        reply: oneshot::Sender<Option<ReservationStage>>,
    },
    Shutdown,
}

#[derive(Clone, Debug)]
pub struct SchedulerHandle {
    tx: mpsc::Sender<SchedulerCommand>,
    ingress_channel_full: Arc<AtomicU64>,
}

impl SchedulerHandle {
    pub fn try_admit(
        &self,
        session: SchedulerSessionKey,
        sequence: u64,
        payload: Bytes,
        class: TrafficClass,
    ) -> Result<(), Admission> {
        self.tx
            .try_send(SchedulerCommand::Admit {
                session,
                sequence,
                payload,
                class,
            })
            .map_err(|_| {
                self.ingress_channel_full.fetch_add(1, Ordering::Relaxed);
                Admission::Dropped(crate::telemetry::DropReason::RootQueueFull)
            })
    }

    pub async fn send(
        &self,
        command: SchedulerCommand,
    ) -> Result<(), mpsc::error::SendError<SchedulerCommand>> {
        self.tx.send(command).await
    }

    pub async fn snapshot(&self) -> Option<RuntimeSchedulerSnapshot> {
        let (reply, receive) = oneshot::channel();
        self.tx.send(SchedulerCommand::Snapshot(reply)).await.ok()?;
        receive.await.ok()
    }

    #[cfg(test)]
    async fn reservation_stage(&self, reservation_id: u64) -> Option<ReservationStage> {
        let (reply, receive) = oneshot::channel();
        self.tx
            .send(SchedulerCommand::ReservationStage {
                reservation_id,
                reply,
            })
            .await
            .ok()?;
        receive.await.ok().flatten()
    }
}

struct RuntimeClock {
    started: Instant,
}

impl RuntimeClock {
    fn new() -> Self {
        Self {
            started: Instant::now(),
        }
    }

    fn now_us(&self) -> u64 {
        self.started.elapsed().as_micros().min(u128::from(u64::MAX)) as u64
    }
}

struct LegSink {
    connection_epoch: u64,
    data_tx: mpsc::Sender<RuntimeChunk>,
}

pub fn spawn_scheduler(config: SchedulerConfig, process_epoch: u64) -> SchedulerHandle {
    let capacity = config.global_queue_packets.max(128);
    let (tx, rx) = mpsc::channel(capacity);
    let ingress_channel_full = Arc::new(AtomicU64::new(0));
    tokio::spawn(run_scheduler(
        Scheduler::new(config, process_epoch, 0),
        rx,
        ingress_channel_full.clone(),
    ));
    SchedulerHandle {
        tx,
        ingress_channel_full,
    }
}

async fn run_scheduler(
    mut scheduler: Scheduler,
    mut rx: mpsc::Receiver<SchedulerCommand>,
    ingress_channel_full: Arc<AtomicU64>,
) {
    let clock = RuntimeClock::new();
    let mut legs = HashMap::<u64, LegSink>::new();
    let mut tick = tokio::time::interval(Duration::from_millis(1));
    tick.set_missed_tick_behavior(MissedTickBehavior::Delay);

    loop {
        tokio::select! {
            command = rx.recv() => {
                let Some(command) = command else { break; };
                if !handle_command(command, &mut scheduler, &mut legs, &clock, &ingress_channel_full) {
                    break;
                }
            }
            _ = tick.tick() => scheduler.tick(clock.now_us()),
        }
        dispatch_ready(&mut scheduler, &mut legs, clock.now_us());
    }
}

fn current_connection_epoch(scheduler: &Scheduler, leg_id: u64) -> Option<u64> {
    scheduler.leg(leg_id).map(|leg| leg.connection_epoch)
}

fn handle_command(
    command: SchedulerCommand,
    scheduler: &mut Scheduler,
    legs: &mut HashMap<u64, LegSink>,
    clock: &RuntimeClock,
    ingress_channel_full: &AtomicU64,
) -> bool {
    let now_us = clock.now_us();
    match command {
        SchedulerCommand::Admit {
            session,
            sequence,
            payload,
            class,
        } => {
            scheduler.admit_frame(session, sequence, payload, class, now_us);
        }
        SchedulerCommand::LegConnected {
            registration,
            data_tx,
        } => {
            let leg_id = registration.leg_id;
            let connection_epoch = registration.connection_epoch;
            scheduler.register_leg(registration);
            legs.insert(
                leg_id,
                LegSink {
                    connection_epoch,
                    data_tx,
                },
            );
        }
        SchedulerCommand::AuthAcknowledged { leg_id, ack } => {
            let _ = scheduler.acknowledge_leg(leg_id, &ack, now_us);
        }
        SchedulerCommand::Progress { leg_id, progress } => {
            let _ = scheduler.on_progress(leg_id, &progress, now_us);
        }
        SchedulerCommand::InvalidControl => scheduler.record_invalid_control(),
        SchedulerCommand::UdpEgressError {
            kind,
            payload_bytes,
            wire_bytes,
        } => scheduler.record_udp_egress_error(kind, payload_bytes, wire_bytes),
        SchedulerCommand::WriterStarted {
            leg_id,
            connection_epoch,
            reservation_ids,
        } => {
            if current_connection_epoch(scheduler, leg_id) == Some(connection_epoch) {
                scheduler.set_leg_dispatch_available(leg_id, true);
                scheduler.writer_started(leg_id, &reservation_ids, now_us);
            }
        }
        SchedulerCommand::WriterAccepted {
            leg_id,
            connection_epoch,
            reservation_ids,
        } => {
            if current_connection_epoch(scheduler, leg_id) == Some(connection_epoch) {
                scheduler.set_leg_dispatch_available(leg_id, true);
                let _: SentTotals = scheduler.writer_accepted(leg_id, &reservation_ids, now_us);
            }
        }
        SchedulerCommand::WriterFailed {
            leg_id,
            connection_epoch,
            reservation_ids,
        } => {
            if current_connection_epoch(scheduler, leg_id) == Some(connection_epoch) {
                scheduler.writer_failed(leg_id, &reservation_ids);
            }
        }
        SchedulerCommand::LegDisconnected {
            leg_id,
            connection_epoch,
        } => {
            if current_connection_epoch(scheduler, leg_id) == Some(connection_epoch) {
                scheduler.disconnect_leg(leg_id);
                legs.remove(&leg_id);
            }
        }
        SchedulerCommand::Snapshot(reply) => {
            let _ = reply.send(RuntimeSchedulerSnapshot {
                queue_packets: scheduler.queue_packets(),
                userspace_pending_bytes: scheduler.userspace_pending_bytes(),
                ingress_channel_full: ingress_channel_full.load(Ordering::Relaxed),
                telemetry: scheduler.stats_snapshot(now_us),
            });
        }
        SchedulerCommand::ReservationStage {
            reservation_id,
            reply,
        } => {
            let _ = reply.send(scheduler.reservation_stage(reservation_id));
        }
        SchedulerCommand::Shutdown => return false,
    }
    true
}

fn dispatch_ready(scheduler: &mut Scheduler, legs: &mut HashMap<u64, LegSink>, now_us: u64) {
    loop {
        let assignment = match scheduler.schedule_next(now_us) {
            ScheduleOutcome::Assignment(assignment) => assignment,
            ScheduleOutcome::WaitUntilUs(_) | ScheduleOutcome::Idle => break,
        };
        let Some((connection_epoch, data_tx)) = legs
            .get(&assignment.leg_id)
            .map(|sink| (sink.connection_epoch, sink.data_tx.clone()))
        else {
            scheduler.writer_failed(assignment.leg_id, &assignment.reservation_ids);
            continue;
        };
        let chunk = RuntimeChunk {
            leg_id: assignment.leg_id,
            connection_epoch,
            reservation_ids: assignment.reservation_ids.clone(),
            frames: assignment
                .datagrams
                .iter()
                .map(|datagram| DataFrame {
                    version: 1,
                    msg_type: MsgType::Data,
                    flags: 0,
                    session_id: datagram.session.session_id as u32,
                    seq: datagram.sequence,
                    payload: datagram.payload.to_vec(),
                })
                .collect(),
        };
        match data_tx.try_send(chunk) {
            Ok(()) => {
                if data_tx.capacity() == 0 {
                    scheduler.set_leg_dispatch_available(assignment.leg_id, false);
                }
            }
            Err(mpsc::error::TrySendError::Full(_)) => {
                scheduler.set_leg_dispatch_available(assignment.leg_id, false);
                scheduler.return_assignment(assignment);
                break;
            }
            Err(mpsc::error::TrySendError::Closed(_)) => {
                scheduler.disconnect_leg(assignment.leg_id);
                legs.remove(&assignment.leg_id);
            }
        }
    }
}

pub fn process_epoch() -> u64 {
    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_nanos();
    let folded = (nanos as u64) ^ ((nanos >> 64) as u64) ^ u64::from(std::process::id());
    mix64(folded).max(1)
}

pub fn connection_epoch(process_epoch: u64, reconnect_counter: u64) -> u64 {
    mix64(process_epoch ^ reconnect_counter.rotate_left(17)).max(1)
}

fn mix64(mut value: u64) -> u64 {
    value ^= value >> 30;
    value = value.wrapping_mul(0xbf58_476d_1ce4_e5b9);
    value ^= value >> 27;
    value = value.wrapping_mul(0x94d0_49bb_1331_11eb);
    value ^ (value >> 31)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::SchedulerMode;
    use crate::progress::{AuthAck, Direction, LegProgress};
    use crate::scheduler::{SchedulerScopeKey, SchedulerSessionKey};

    fn scope() -> SchedulerScopeKey {
        SchedulerScopeKey::Client { forward_id: 3 }
    }

    #[tokio::test]
    async fn actor_dispatches_and_accounts_a_feedback_datagram() {
        let config = SchedulerConfig {
            scheduler_mode: SchedulerMode::FeedbackDrr,
            ..SchedulerConfig::default()
        };
        let handle = spawn_scheduler(config, 77);
        let (data_tx, mut data_rx) = mpsc::channel(4);
        handle
            .send(SchedulerCommand::LegConnected {
                registration: LegRegistration {
                    leg_id: 1,
                    scope_key: scope(),
                    connection_epoch: 10,
                    progress_direction: Direction::ClientToSidecar,
                    weight: 1,
                    now_us: 0,
                },
                data_tx,
            })
            .await
            .unwrap();
        handle
            .send(SchedulerCommand::AuthAcknowledged {
                leg_id: 1,
                ack: AuthAck::leg_progress(10),
            })
            .await
            .unwrap();
        handle
            .try_admit(
                SchedulerSessionKey {
                    scope_key: scope(),
                    session_id: 41,
                    direction: Direction::ClientToSidecar,
                    process_epoch: 77,
                },
                9_001,
                Bytes::from_static(b"payload"),
                TrafficClass::Regular,
            )
            .unwrap();

        let chunk = data_rx.recv().await.expect("scheduled chunk");
        assert_eq!(chunk.frames[0].seq, 9_001);
        assert_eq!(chunk.frames[0].payload, b"payload");
        let reservation_id = chunk.reservation_ids[0];
        handle
            .send(SchedulerCommand::WriterStarted {
                leg_id: 1,
                connection_epoch: 10,
                reservation_ids: chunk.reservation_ids.clone(),
            })
            .await
            .unwrap();
        handle
            .send(SchedulerCommand::WriterAccepted {
                leg_id: 1,
                connection_epoch: 10,
                reservation_ids: chunk.reservation_ids,
            })
            .await
            .unwrap();
        handle
            .send(SchedulerCommand::Progress {
                leg_id: 1,
                progress: LegProgress::accepted(Direction::ClientToSidecar, 10, 1, 31, 1, 31),
            })
            .await
            .unwrap();

        assert_eq!(
            handle.reservation_stage(reservation_id).await,
            Some(ReservationStage::RemoteUdpAccepted)
        );
        let snapshot = handle.snapshot().await.unwrap();
        assert_eq!(snapshot.userspace_pending_bytes, 0);
        assert_eq!(snapshot.telemetry.feedback_capable_active_legs, 1);
        assert_eq!(
            snapshot
                .telemetry
                .lifecycle
                .get("remote_udp_accepted")
                .unwrap()
                .packets,
            1
        );
        let json = serde_json::to_value(snapshot).unwrap();
        assert!(json.get("scheduler_drop_ratio").is_some());
        assert!(json.get("legs").is_some());
    }

    #[tokio::test]
    async fn full_writer_channel_backpressures_without_dropping_the_next_datagram() {
        let config = SchedulerConfig {
            scheduler_mode: SchedulerMode::FeedbackDrr,
            ..SchedulerConfig::default()
        };
        let handle = spawn_scheduler(config, 77);
        let (data_tx, mut data_rx) = mpsc::channel(1);
        handle
            .send(SchedulerCommand::LegConnected {
                registration: LegRegistration {
                    leg_id: 1,
                    scope_key: scope(),
                    connection_epoch: 10,
                    progress_direction: Direction::ClientToSidecar,
                    weight: 1,
                    now_us: 0,
                },
                data_tx,
            })
            .await
            .unwrap();
        for sequence in [1, 2] {
            handle
                .try_admit(
                    SchedulerSessionKey {
                        scope_key: scope(),
                        session_id: 41,
                        direction: Direction::ClientToSidecar,
                        process_epoch: 77,
                    },
                    sequence,
                    Bytes::from_static(b"payload"),
                    TrafficClass::Regular,
                )
                .unwrap();
        }

        let snapshot = handle.snapshot().await.unwrap();
        assert_eq!(snapshot.queue_packets, 1);
        let first = data_rx.recv().await.unwrap();
        assert_eq!(first.frames[0].seq, 1);
        handle
            .send(SchedulerCommand::WriterStarted {
                leg_id: 1,
                connection_epoch: 10,
                reservation_ids: first.reservation_ids,
            })
            .await
            .unwrap();
        let second = data_rx.recv().await.unwrap();
        assert_eq!(second.frames[0].seq, 2);
    }

    #[test]
    fn generated_epochs_are_nonzero_and_reconnect_specific() {
        let process = process_epoch();
        let first = connection_epoch(process, 1);
        let second = connection_epoch(process, 2);
        assert_ne!(process, 0);
        assert_ne!(first, 0);
        assert_ne!(first, second);
    }
}
