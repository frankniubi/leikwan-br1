package main

import (
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"log"
	"net"
	"sort"
	"strings"
	"time"
)

var errProbeUnavailable = errors.New("udp probe unavailable")

type udpProbeResult struct {
	Index         int
	SelectionTier string
	Sent          int
	Received      int
	MedianRTT     time.Duration
	P95RTT        time.Duration
	Jitter        time.Duration
	Err           error
}

func (result udpProbeResult) usable() bool {
	if result.Err != nil || result.Sent <= 0 || result.Received <= 0 {
		return false
	}
	lost, sent := result.lossRatio()
	return lost*100 <= sent*25
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

// rankUDPProbeResults uses loss as the primary ordering key, then prefers the
// lowest jitter before comparing tail and median latency. This keeps a fast
// but unstable leg from winning over a slightly slower stable leg.
func rankUDPProbeResults(results []udpProbeResult) []udpProbeResult {
	ranked := append([]udpProbeResult(nil), results...)
	sort.SliceStable(ranked, func(left, right int) bool {
		l := ranked[left]
		r := ranked[right]
		if l.usable() != r.usable() {
			return l.usable()
		}
		if lTier, rTier := selectionTierRank(l.SelectionTier), selectionTierRank(r.SelectionTier); lTier != rTier {
			return lTier < rTier
		}
		llost, lsent := l.lossRatio()
		rlost, rsent := r.lossRatio()
		if llost*rsent != rlost*lsent {
			return llost*rsent < rlost*lsent
		}
		if l.Jitter != r.Jitter {
			return l.Jitter < r.Jitter
		}
		if l.P95RTT != r.P95RTT {
			return l.P95RTT < r.P95RTT
		}
		if l.MedianRTT != r.MedianRTT {
			return l.MedianRTT < r.MedianRTT
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
	result := udpProbeResult{SelectionTier: lease.SelectionTier, Sent: 1}
	started := time.Now()
	host, port, err := probeTarget(config)
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
	result := udpProbeResult{SelectionTier: lease.SelectionTier, Sent: 0}
	targetHost, targetPort, targetErr := probeTarget(config)
	if targetErr != nil {
		result.Err = targetErr
		return result
	}
	// Association setup scans the relay's bounded SID range and is distinct
	// from the per-packet measurement timeout. Do not let an 800ms DNS reply
	// timeout truncate a cold association before the quality window starts.
	session, err := armDatagram(lease, probeAssociationTimeout(config))
	if err != nil {
		result.Err = err
		return result
	}
	defer session.Close()
	packetTimeout := probeTimeout(config)
	samples := config.ProbeSamples
	if samples < 3 || samples > 300 {
		samples = 30
	}
	interval := time.Duration(config.ProbeIntervalMillis) * time.Millisecond
	if interval < 100*time.Millisecond || interval > 5*time.Second {
		interval = time.Second
	}
	duration := probeDuration(config)
	minimumSamples := int((duration + interval - 1) / interval)
	if samples < minimumSamples {
		samples = minimumSamples
	}
	deadline := time.Now().Add(duration)
	readBuffer := make([]byte, 2048)
	rtts := make([]time.Duration, 0, samples)
	nextSend := time.Now()
	for sequence := 0; sequence < samples; sequence++ {
		if err := ctx.Err(); err != nil {
			result.Err = err
			return result
		}
		if remaining := time.Until(deadline); remaining <= 0 {
			break
		}
		if wait := time.Until(nextSend); wait > 0 {
			timer := time.NewTimer(wait)
			select {
			case <-ctx.Done():
				timer.Stop()
				result.Err = ctx.Err()
				return result
			case <-timer.C:
			}
		}
		payload, transactionID := buildDNSProbePayload(uint16(sequence))
		packet, packetErr := makeDatagramEnvelope(session.mode, session.sid, targetHost, targetPort, payload)
		clearBytes(payload)
		if packetErr != nil {
			result.Err = packetErr
			return result
		}
		result.Sent++
		started := time.Now()
		readTimeout := packetTimeout
		if remaining := time.Until(deadline); remaining < readTimeout {
			readTimeout = remaining
		}
		_ = session.remote.SetReadDeadline(time.Now().Add(readTimeout))
		if _, writeErr := session.remote.Write(packet); writeErr == nil {
			for {
				count, readErr := session.remote.Read(readBuffer)
				if readErr != nil {
					break
				}
				response, valid := unwrapDatagramForSession(readBuffer[:count])
				if valid && probeResponseMatches(response, transactionID) {
					result.Received++
					rtts = append(rtts, time.Since(started))
					break
				}
			}
		}
		nextSend = nextSend.Add(interval)
	}
	// Keep the selection window's wall-clock length stable even when the
	// configured sample count is reached just before the deadline. This makes
	// the reported loss/jitter comparable across candidates.
	if remaining := time.Until(deadline); remaining > 0 {
		timer := time.NewTimer(remaining)
		select {
		case <-ctx.Done():
			timer.Stop()
			result.Err = ctx.Err()
			return result
		case <-timer.C:
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

func probeAssociationTimeout(config Config) time.Duration {
	value := config.ConnectTimeoutSecs
	if value < 1 || value > 30 {
		value = 5
	}
	configured := time.Duration(value) * time.Second
	if configured < datagramAssociationSetupBudget {
		return datagramAssociationSetupBudget
	}
	return configured
}

func probeResponseMatches(response []byte, transactionID uint16) bool {
	if len(response) < 12 {
		return false
	}
	return binary.BigEndian.Uint16(response[:2]) == transactionID && response[2]&0x80 != 0
}

func buildDNSProbePayload(sequence uint16) ([]byte, uint16) {
	transactionID := uint16(time.Now().UnixNano()) ^ sequence
	payload := make([]byte, 0, 64)
	var header [12]byte
	binary.BigEndian.PutUint16(header[0:2], transactionID)
	binary.BigEndian.PutUint16(header[2:4], 0x0100)
	binary.BigEndian.PutUint16(header[4:6], 1)
	payload = append(payload, header[:]...)
	for _, label := range []string{"cloudflare", "com"} {
		payload = append(payload, byte(len(label)))
		payload = append(payload, label...)
	}
	payload = append(payload, 0, 0, 1, 0, 1)
	return payload, transactionID
}

func probeTarget(config Config) (string, int, error) {
	if strings.TrimSpace(config.ProbeTarget) != "" && strings.TrimSpace(config.ProbeTarget) != defaultProbeTarget {
		return "", 0, errors.New("probe target is fixed to 1.1.1.1:53")
	}
	return "1.1.1.1", 53, nil
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
	return selectBestLeaseWithProbe(ctx, config, policy, leases, probeLease)
}

type leaseProbe func(context.Context, ProviderLease, Config, Policy) udpProbeResult

func selectBestLeaseWithProbe(
	ctx context.Context,
	config Config,
	policy Policy,
	leases []ProviderLease,
	probe leaseProbe,
) (ProviderLease, udpProbeResult, error) {
	if len(leases) == 0 {
		return ProviderLease{}, udpProbeResult{}, errProbeUnavailable
	}
	if probe == nil {
		probe = probeLease
	}
	workers := config.ProbeWorkers
	if workers < 1 || workers > 4 {
		workers = 4
	}
	// A Panel response can contain repeated material for one logical line, while
	// distinct lines may intentionally share a relay IP:port. Use the opaque
	// selection reference (and a credential-user fallback for test/legacy input)
	// as the logical-leg identity so shared relays are not silently discarded.
	unique := make([]ProviderLease, 0, len(leases))
	originalIndices := make([]int, 0, len(leases))
	seenEndpoints := make(map[string]struct{}, len(leases))
	for index, lease := range leases {
		key := leaseSelectionKey(lease)
		if _, seen := seenEndpoints[key]; seen {
			continue
		}
		seenEndpoints[key] = struct{}{}
		unique = append(unique, lease)
		originalIndices = append(originalIndices, index)
	}
	if len(unique) == 0 {
		return ProviderLease{}, udpProbeResult{}, errProbeUnavailable
	}
	if workers > len(unique) {
		workers = len(unique)
	}
	results := make([]udpProbeResult, len(unique))
	jobs := make(chan int)
	done := make(chan struct{}, workers)
	for range workers {
		go func() {
			defer func() { done <- struct{}{} }()
			for index := range jobs {
				results[index] = probe(ctx, unique[index], config, policy)
				// The lease is authoritative for tier metadata. A custom probe
				// may return quality only, so carry the candidate tier into the
				// result used by ranking and status reporting.
				results[index].SelectionTier = unique[index].SelectionTier
				results[index].Index = index
			}
		}()
	}
	for index := range unique {
		jobs <- index
	}
	close(jobs)
	for range workers {
		<-done
	}
	ranked := rankUDPProbeResults(results)
	for index, result := range results {
		if result.Err != nil {
			lease := unique[index]
			log.Printf("ultra UDP candidate probe failed endpoint=%s:%d selection_ref=%s error=%v", lease.IP, lease.Port, lease.SelectionRef, result.Err)
		}
	}
	if len(ranked) == 0 || !ranked[0].usable() {
		return ProviderLease{}, ranked[0], fmt.Errorf("%w: all candidates failed", errProbeUnavailable)
	}
	return leases[originalIndices[ranked[0].Index]], ranked[0], nil
}

func leaseSelectionKey(lease ProviderLease) string {
	endpoint := strings.ToLower(net.JoinHostPort(strings.TrimSpace(lease.IP), fmt.Sprint(lease.Port)))
	ref := strings.TrimSpace(lease.SelectionRef)
	if ref == "" {
		ref = strings.ToLower(strings.TrimSpace(string(lease.User)))
	}
	return endpoint + "\x00" + ref
}
