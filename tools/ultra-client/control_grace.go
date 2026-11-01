package main

import (
	"encoding/json"
	"time"
)

// controlCommand is an out-of-band instruction the local router sends on the
// same control socket that carries lease batches. A lease batch never has an
// "op" member, so the two cannot be confused.
type controlCommand struct {
	Op               string `json:"op"`
	PolicyGeneration string `json:"policy_generation"`
	GraceUntil       string `json:"grace_until"`
}

// handleControlCommand answers a grace command. handled is false when the
// payload is an ordinary lease batch and must go through the normal path.
func handleControlCommand(payload []byte, store *PolicyStore) (reply map[string]any, handled bool) {
	var command controlCommand
	if err := json.Unmarshal(payload, &command); err != nil || command.Op == "" {
		return nil, false
	}
	switch command.Op {
	case "extend_grace":
		until, err := time.Parse(time.RFC3339, command.GraceUntil)
		if err != nil {
			return map[string]any{"ok": false, "error": "lease_invalid"}, true
		}
		if err := store.ExtendGrace(command.PolicyGeneration, until); err != nil {
			return map[string]any{"ok": false, "error": controlErrorName(err)}, true
		}
	case "stop_grace":
		if err := store.StopGrace(command.PolicyGeneration); err != nil {
			return map[string]any{"ok": false, "error": controlErrorName(err)}, true
		}
	default:
		return map[string]any{"ok": false, "error": "control_rejected"}, true
	}
	return map[string]any{"ok": true, "policy_generation": command.PolicyGeneration}, true
}
