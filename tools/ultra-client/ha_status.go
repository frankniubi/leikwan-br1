package main

import "time"

func (w *StatusWriter) RecordUDPAttached(selection, tier string) {
	w.update(func(s *RuntimeStatus) { s.UDPRuntimeSession = selectionStatusWithTier(selection, tier, 0) })
}

type HAEvent struct {
	Protocol           string `json:"protocol"`
	Reason             string `json:"reason"`
	Generation         string `json:"generation"`
	At                 string `json:"at"`
	CandidateKeyHash   string `json:"candidate_key_hash,omitempty"`
	LeaseHash          string `json:"lease_hash,omitempty"`
	UpstreamBytes      *int64 `json:"upstream_bytes,omitempty"`
	RemoteReadBytes    *int64 `json:"remote_read_bytes,omitempty"`
	RemoteReadReason   string `json:"remote_read_reason,omitempty"`
	RemoteStoppedFirst *bool  `json:"remote_stopped_first,omitempty"`
	LocalPathAvoided   *bool  `json:"local_path_avoided,omitempty"`
}

func (w *StatusWriter) RecordHAEvent(protocol, reason, generation string) {
	w.update(func(s *RuntimeStatus) {
		// Keep the bounded heartbeat history for failures. Ordinary stream
		// completion is still logged by the relay, but must not evict faults.
		switch reason {
		case "eof", "complete", "closed", "canceled":
			return
		}
		event := HAEvent{Protocol: protocol, Reason: reason, Generation: boundedStatusText(generation, 64), At: time.Now().UTC().Format(time.RFC3339Nano)}
		// Snapshots are encoded outside the lock: never mutate their backing array.
		s.HAEvents = append(append([]HAEvent(nil), s.HAEvents...), event)
		if len(s.HAEvents) > 16 {
			s.HAEvents = append([]HAEvent(nil), s.HAEvents[len(s.HAEvents)-16:]...)
		}
		s.HAFailures++
	})
}

// RecordTCPOutcome retains only opaque lease identities and classified remote
// read evidence. It is diagnostic history, never global candidate feedback.
func (w *StatusWriter) RecordTCPOutcome(ident relayIdentity, upstreamBytes, remoteReadBytes int64, remoteReadReason string, remoteStoppedFirst, localPathAvoided bool) {
	upstreamBytes = maxInt64(upstreamBytes, 0)
	remoteReadBytes = maxInt64(remoteReadBytes, 0)
	event := HAEvent{
		Protocol: "tcp", Reason: "no_response",
		Generation: boundedStatusText(ident.generation, 64), At: time.Now().UTC().Format(time.RFC3339Nano),
		CandidateKeyHash: normalizedCandidateKeyHash(ident.candidateKey), LeaseHash: normalizedLeaseHash(ident.leaseHash),
		UpstreamBytes: &upstreamBytes, RemoteReadBytes: &remoteReadBytes,
		RemoteReadReason:   boundedStatusText(remoteReadReason, 32),
		RemoteStoppedFirst: &remoteStoppedFirst, LocalPathAvoided: &localPathAvoided,
	}
	w.update(func(s *RuntimeStatus) {
		s.HAEvents = append(append([]HAEvent(nil), s.HAEvents...), event)
		if len(s.HAEvents) > 16 {
			s.HAEvents = append([]HAEvent(nil), s.HAEvents[len(s.HAEvents)-16:]...)
		}
		s.HAFailures++
		if localPathAvoided {
			// Preserve the previous no_response + remote_reset_path_avoided
			// counter semantics while retaining a single contextual event.
			s.HAFailures++
		}
	})
}

func (w *StatusWriter) RecordUDPKeepaliveACK() {
	w.update(func(s *RuntimeStatus) {
		s.UDPKeepaliveACKs++
		s.UDPLastACKAt = time.Now().UTC().Format(time.RFC3339Nano)
	})
}
