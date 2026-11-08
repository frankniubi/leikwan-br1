package main

import (
	"encoding/json"
	"net"
	"strconv"
	"strings"
	"unicode"
)

// The token stays in memory and the private runtime config, never applied-state metadata.
type ExternalUoTGrant struct {
	Enabled               bool   `json:"enabled"`
	SidecarTarget         string `json:"sidecar_target"`
	Token                 string `json:"token"`
	DeliveryMode          string `json:"delivery_mode,omitempty"`
	QueuePackets          int    `json:"queue_packets,omitempty"`
	KeepaliveSeconds      int    `json:"keepalive_seconds,omitempty"`
	ReconnectGraceSeconds int    `json:"reconnect_grace_seconds,omitempty"`
	FramePayloadMax       int    `json:"frame_payload_max,omitempty"`
}

func (g *ExternalUoTGrant) valid() bool {
	if g == nil || !g.Enabled {
		return true
	}
	host, port, err := net.SplitHostPort(g.SidecarTarget)
	n, numberErr := strconv.Atoi(port)
	if err != nil || numberErr != nil || host == "" || n < 1 || n > 65535 ||
		len(g.SidecarTarget) > 512 || len(g.Token) == 0 || len(g.Token) > 8192 ||
		strings.IndexFunc(g.Token, unicode.IsSpace) >= 0 || strings.IndexFunc(host, unicode.IsSpace) >= 0 {
		return false
	}
	return g.DeliveryMode == "" || g.DeliveryMode == "raw" || g.DeliveryMode == "reliable_datagram_v1"
}

func (g *ExternalUoTGrant) fingerprint() string {
	if g == nil || !g.Enabled {
		return ""
	}
	payload, _ := json.Marshal(g)
	return sha256Hex(string(payload))
}

func externalGrant(policy *UltraPolicy) *ExternalUoTGrant {
	if policy == nil || policy.ExternalUoT == nil || !policy.ExternalUoT.Enabled {
		return nil
	}
	return policy.ExternalUoT
}
