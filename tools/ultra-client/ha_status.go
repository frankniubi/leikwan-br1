package main

import "time"

func (w *StatusWriter) RecordUDPAttached(selection, tier string) {
	w.update(func(s *RuntimeStatus) { s.UDPRuntimeSession = selectionStatusWithTier(selection, tier, 0) })
}

type HAEvent struct {
	Protocol   string `json:"protocol"`
	Reason     string `json:"reason"`
	Generation string `json:"generation"`
	At         string `json:"at"`
}

func (w *StatusWriter) RecordHAEvent(protocol, reason, generation string) {
	w.update(func(s *RuntimeStatus) {
		event := HAEvent{protocol, reason, boundedStatusText(generation, 64), time.Now().UTC().Format(time.RFC3339Nano)}
		// Snapshots are encoded outside the lock: never mutate their backing array.
		s.HAEvents = append(append([]HAEvent(nil), s.HAEvents...), event)
		if len(s.HAEvents) > 16 {
			s.HAEvents = append([]HAEvent(nil), s.HAEvents[len(s.HAEvents)-16:]...)
		}
		if reason != "eof" && reason != "complete" && reason != "closed" && reason != "canceled" {
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
