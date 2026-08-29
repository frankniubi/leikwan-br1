package main

import (
	"context"
	"errors"
	"fmt"
	"sort"
	"time"
)

var errProbeUnavailable = errors.New("udp probe unavailable")

type udpProbeResult struct {
	Index     int
	Sent      int
	Received  int
	MedianRTT time.Duration
	P95RTT    time.Duration
	Jitter    time.Duration
	Err       error
}

func (result udpProbeResult) usable() bool {
	return result.Err == nil && result.Sent > 0 && result.Received > 0
}

func (result udpProbeResult) lossRatio() (lost, sent int) {
	if result.Sent <= 0 {
		return 1, 1
	}
	lost = result.Sent - result.Received
	if lost < 0 {
		lost = result.Sent
	}
	return lost, result.Sent
}

// rankUDPProbeResults uses loss as the primary ordering key. Latency is only
// compared between candidates with the same delivery ratio, which prevents a
// very fast but lossy leg from winning over a stable leg.
func rankUDPProbeResults(results []udpProbeResult) []udpProbeResult {
	ranked := append([]udpProbeResult(nil), results...)
	sort.SliceStable(ranked, func(left, right int) bool {
		l := ranked[left]
		r := ranked[right]
		if l.usable() != r.usable() {
			return l.usable()
		}
		llost, lsent := l.lossRatio()
		rlost, rsent := r.lossRatio()
		if llost*rsent != rlost*lsent {
			return llost*rsent < rlost*lsent
		}
		if l.P95RTT != r.P95RTT {
			return l.P95RTT < r.P95RTT
		}
		if l.MedianRTT != r.MedianRTT {
			return l.MedianRTT < r.MedianRTT
		}
		if l.Jitter != r.Jitter {
			return l.Jitter < r.Jitter
		}
		return l.Index < r.Index
	})
	return ranked
}

func probeLease(ctx context.Context, lease ProviderLease, config Config, policy Policy) udpProbeResult {
	if stringsContainsUDP(policy.Transport) {
		return probeUDPLease(ctx, lease, config)
	}
	return probeTCPLease(ctx, lease, config, policy)
}

func stringsContainsUDP(transport string) bool {
	return transport == "udp" || transport == "tcp+udp"
}

func probeTCPLease(ctx context.Context, lease ProviderLease, config Config, policy Policy) udpProbeResult {
	result := udpProbeResult{Sent: 1}
	started := time.Now()
	host, port, err := parseTarget(config.Target)
	if err != nil {
		result.Err = err
		return result
	}
	select {
	case <-ctx.Done():
		result.Err = ctx.Err()
		return result
	default:
	}
	connection, err := dialStream(lease, host, port, probeTimeout(config))
	if err != nil {
		result.Err = err
		return result
	}
	_ = connection.Close()
	result.Received = 1
	result.MedianRTT = time.Since(started)
	result.P95RTT = result.MedianRTT
	return result
}

func probeUDPLease(ctx context.Context, lease ProviderLease, config Config) udpProbeResult {
	result := udpProbeResult{Sent: 0}
	session, err := armDatagram(lease, probeTimeout(config))
	if err != nil {
		result.Err = err
		return result
	}
	defer session.Close()
	packetTimeout := probeTimeout(config)
	samples := config.ProbeSamples
	if samples < 3 || samples > 64 {
		samples = 20
	}
	interval := time.Duration(config.ProbeIntervalMillis) * time.Millisecond
	if interval < 0 || interval > time.Second {
		interval = 100 * time.Millisecond
	}
	readBuffer := make([]byte, 2048)
	rtts := make([]time.Duration, 0, samples)
	for sequence := 0; sequence < samples; sequence++ {
		if err := ctx.Err(); err != nil {
			result.Err = err
			return result
		}
		payload := make([]byte, len(keepPayloadHex)+8)
		copy(payload, keepPayloadHex)
		putUint64(payload[len(keepPayloadHex):], uint64(sequence))
		packet, packetErr := makeDatagramEnvelope(session.mode, session.sid, "2.0.0.0", 0, payload)
		clearBytes(payload)
		if packetErr != nil {
			result.Err = packetErr
			return result
		}
		result.Sent++
		started := time.Now()
		_ = session.remote.SetReadDeadline(time.Now().Add(packetTimeout))
		if _, writeErr := session.remote.Write(packet); writeErr == nil {
			for {
				count, readErr := session.remote.Read(readBuffer)
				if readErr != nil {
					break
				}
				response, valid := unwrapDatagram(readBuffer[:count])
				if valid && len(response) >= len(keepPayloadHex)+8 {
					result.Received++
					rtts = append(rtts, time.Since(started))
					break
				}
			}
		}
		if interval > 0 && sequence+1 < samples {
			timer := time.NewTimer(interval)
			select {
			case <-ctx.Done():
				timer.Stop()
				result.Err = ctx.Err()
				return result
			case <-timer.C:
			}
		}
	}
	_ = session.remote.SetReadDeadline(time.Time{})
	if result.Received == 0 {
		result.Err = errProbeUnavailable
		return result
	}
	result.MedianRTT, result.P95RTT, result.Jitter = summarizeRTTs(rtts)
	return result
}

func putUint64(buffer []byte, value uint64) {
	for index := 7; index >= 0; index-- {
		buffer[index] = byte(value)
		value >>= 8
	}
}

func summarizeRTTs(rtts []time.Duration) (time.Duration, time.Duration, time.Duration) {
	if len(rtts) == 0 {
		return 0, 0, 0
	}
	sorted := append([]time.Duration(nil), rtts...)
	sort.Slice(sorted, func(left, right int) bool { return sorted[left] < sorted[right] })
	median := sorted[len(sorted)/2]
	p95 := sorted[(len(sorted)*95+99)/100-1]
	if p95 < median {
		p95 = median
	}
	var deviation time.Duration
	for _, rtt := range sorted {
		if rtt > median {
			deviation += rtt - median
		} else {
			deviation += median - rtt
		}
	}
	return median, p95, deviation / time.Duration(len(sorted))
}

func probeTimeout(config Config) time.Duration {
	value := config.ProbeTimeoutMillis
	if value < 100 || value > 3000 {
		value = 300
	}
	return time.Duration(value) * time.Millisecond
}

func selectBestLease(ctx context.Context, config Config, policy Policy, leases []ProviderLease) (ProviderLease, udpProbeResult, error) {
	if len(leases) == 0 {
		return ProviderLease{}, udpProbeResult{}, errProbeUnavailable
	}
	workers := config.ProbeWorkers
	if workers < 1 || workers > 4 {
		workers = 4
	}
	if workers > len(leases) {
		workers = len(leases)
	}
	results := make([]udpProbeResult, len(leases))
	jobs := make(chan int)
	done := make(chan struct{}, workers)
	for range workers {
		go func() {
			defer func() { done <- struct{}{} }()
			for index := range jobs {
				results[index] = probeLease(ctx, leases[index], config, policy)
				results[index].Index = index
			}
		}()
	}
	for index := range leases {
		jobs <- index
	}
	close(jobs)
	for range workers {
		<-done
	}
	ranked := rankUDPProbeResults(results)
	if len(ranked) == 0 || !ranked[0].usable() {
		return ProviderLease{}, ranked[0], fmt.Errorf("%w: all candidates failed", errProbeUnavailable)
	}
	return leases[ranked[0].Index], ranked[0], nil
}
