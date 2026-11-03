package main

// adaptiveTuner is the bounded AIMD/EMA controller from the Python sidecar.
// TCP already provides ordering and retransmission, so the tuner never invents
// packet retransmission: it only adapts the maximum complete UDP payload the
// session accepts and the recommended queue capacity.

import (
	"sync"
	"time"
)

const (
	tunerMinQueuePackets = 32
	tunerMaxQueuePackets = 4096
	tunerStableSamples   = 8
	tunerStableRTTMS     = 250.0
)

type adaptiveTuner struct {
	mu              sync.Mutex
	framePayloadMax int
	queuePackets    int
	minFramePayload int
	maxFramePayload int
	ewmaRTTMillis   float64
	hasEwma         bool
	stableSamples   int
	pressureEvents  int
	reconnectEvents int
}

func newAdaptiveTuner(framePayloadMax int, queuePackets int, maxFramePayload int) *adaptiveTuner {
	tuner := &adaptiveTuner{
		minFramePayload: minFramePayload,
		maxFramePayload: maxFramePayload,
	}
	tuner.framePayloadMax = clampInt(framePayloadMax, tuner.minFramePayload, tuner.maxFramePayload)
	tuner.queuePackets = clampInt(queuePackets, tunerMinQueuePackets, tunerMaxQueuePackets)
	return tuner
}

func clampInt(value int, low int, high int) int {
	if value < low {
		return low
	}
	if value > high {
		return high
	}
	return value
}

func (t *adaptiveTuner) payloadLimit() int {
	t.mu.Lock()
	defer t.mu.Unlock()
	return t.framePayloadMax
}

func (t *adaptiveTuner) observeRTT(rtt time.Duration) {
	t.mu.Lock()
	defer t.mu.Unlock()
	milliseconds := float64(rtt.Milliseconds())
	if milliseconds < 0 {
		milliseconds = 0
	}
	if !t.hasEwma {
		t.ewmaRTTMillis = milliseconds
		t.hasEwma = true
	} else {
		t.ewmaRTTMillis = 0.8*t.ewmaRTTMillis + 0.2*milliseconds
	}
	if t.ewmaRTTMillis <= tunerStableRTTMS {
		t.stableSamples++
		if t.stableSamples >= tunerStableSamples {
			growth := t.framePayloadMax / 4
			if growth < 64 {
				growth = 64
			}
			t.framePayloadMax = minInt(t.maxFramePayload, t.framePayloadMax+growth)
			shrink := t.queuePackets / 16
			if shrink < 1 {
				shrink = 1
			}
			t.queuePackets = maxInt(tunerMinQueuePackets, t.queuePackets-shrink)
			t.stableSamples = 0
		}
		return
	}
	t.stableSamples = 0
}

func (t *adaptiveTuner) observePayload(payloadBytes int) {
	t.mu.Lock()
	defer t.mu.Unlock()
	if payloadBytes <= t.framePayloadMax {
		return
	}
	doubled := t.framePayloadMax * 2
	t.framePayloadMax = minInt(t.maxFramePayload, maxInt(payloadBytes, doubled))
}

func (t *adaptiveTuner) observePressure() {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.pressureEvents++
	t.stableSamples = 0
	t.framePayloadMax = maxInt(t.minFramePayload, t.framePayloadMax/2)
	t.queuePackets = minInt(tunerMaxQueuePackets, maxInt(t.queuePackets+1, t.queuePackets*2))
}

func (t *adaptiveTuner) observeReconnect() {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.reconnectEvents++
	t.stableSamples = 0
	t.framePayloadMax = maxInt(t.minFramePayload, t.framePayloadMax/2)
	t.queuePackets = minInt(tunerMaxQueuePackets, t.queuePackets*2)
}

func minInt(a int, b int) int {
	if a < b {
		return a
	}
	return b
}

func maxInt(a int, b int) int {
	if a > b {
		return a
	}
	return b
}
