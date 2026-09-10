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
	"sync"
	"time"
)

var (
	errProbeUnavailable   = errors.New("udp probe unavailable")
	errProbeTCPFailed     = errors.New("probe_tcp_failed")
	errProbeUDPFailed     = errors.New("probe_udp_failed")
	errNoHealthyCandidate = errors.New("no_healthy_candidate")
)

type udpProbeResult struct {
	Index         int
	SelectionTier string
	Sent          int
	Received      int
	MedianRTT     time.Duration
	P95RTT        time.Duration
	Jitter        time.Duration
	Err           error
	TCPSent       int
	TCPReceived   int
	TCPRTT        time.Duration
	TCPError      error
}

func (result udpProbeResult) usable() bool {
	return result.usableFor("udp")
}

func (result udpProbeResult) usableFor(transport string) bool {
	transport = strings.ToLower(strings.TrimSpace(transport))
	udpUsable := result.Err == nil && result.pathUsable(result.Sent, result.Received)
	tcpUsable := result.TCPError == nil && result.pathUsable(result.TCPSent, result.TCPReceived)
	switch transport {
	case "tcp":
		return tcpUsable
	case "tcp+udp":
		return udpUsable && tcpUsable
	default:
		return udpUsable
	}
}

func (result udpProbeResult) qualityFor(transport string) (lost, sent int, jitter, p95, median time.Duration) {
	transport = strings.ToLower(strings.TrimSpace(transport))
	if transport == "tcp" {
		lost, sent := result.lossRatioFor(result.TCPSent, result.TCPReceived)
		return lost, sent, result.TCPRTT, result.TCPRTT, result.TCPRTT
	}
	udpLost, udpSent := result.lossRatio()
	if transport != "tcp+udp" {
		return udpLost, udpSent, result.Jitter, result.P95RTT, result.MedianRTT
	}
	tcpLost, tcpSent := result.lossRatioFor(result.TCPSent, result.TCPReceived)
	if tcpLost*tcpSentForCompare(udpSent) > udpLost*tcpSentForCompare(tcpSent) {
		lost, sent = tcpLost, tcpSent
	} else {
		lost, sent = udpLost, udpSent
	}
	return lost, sent, maxDuration(result.Jitter, 0), maxDuration(result.P95RTT, result.TCPRTT), maxDuration(result.MedianRTT, result.TCPRTT)
}

func (result udpProbeResult) lossRatioFor(sent, received int) (lost, normalizedSent int) {
	if sent <= 0 {
		return 1, 1
	}
	if received < 0 || received > sent {
		return sent, sent
	}
	return sent - received, sent
}

func (result udpProbeResult) pathUsable(sent, received int) bool {
	if sent <= 0 || received <= 0 || received > sent {
		return false
	}
	lost, normalizedSent := result.lossRatioFor(sent, received)
	return lost*100 <= normalizedSent*25
}

func tcpSentForCompare(value int) int {
	if value <= 0 {
		return 1
	}
	return value
}

func maxDuration(left, right time.Duration) time.Duration {
	if left >= right {
		return left
	}
	return right
}

func (result udpProbeResult) protocolFailure(transport string) error {
	transport = strings.ToLower(strings.TrimSpace(transport))
	udpUsable := result.Err == nil && result.pathUsable(result.Sent, result.Received)
	tcpUsable := result.TCPError == nil && result.pathUsable(result.TCPSent, result.TCPReceived)
	switch transport {
	case "tcp":
		if result.TCPError != nil {
			return result.TCPError
		}
		if !tcpUsable {
			return errProbeTCPFailed
		}
	case "tcp+udp":
		if !udpUsable && !tcpUsable {
			return errNoHealthyCandidate
		}
		if !udpUsable {
			if result.Err != nil {
				return result.Err
			}
			return errProbeUDPFailed
		}
		if !tcpUsable {
			if result.TCPError != nil {
				return result.TCPError
			}
			return errProbeTCPFailed
		}
	default:
		if result.Err != nil {
			return result.Err
		}
		if !udpUsable {
			return errProbeUDPFailed
		}
	}
	return errProbeUnavailable
}

func wrapProbeFailure(kind error, cause error) error {
	if cause == nil {
		return kind
	}
	return fmt.Errorf("%w: %v", kind, cause)
}

func dualProbeResult(udp, tcp udpProbeResult) udpProbeResult {
	result := udp
	result.TCPSent = tcp.TCPSent
	result.TCPReceived = tcp.TCPReceived
	result.TCPRTT = tcp.TCPRTT
	result.TCPError = tcp.TCPError
	udpFailure := udp.Err
	if udpFailure == nil && !udp.pathUsable(udp.Sent, udp.Received) {
		udpFailure = errProbeUnavailable
	}
	tcpFailure := tcp.TCPError
	if tcpFailure == nil && !tcp.pathUsable(tcp.TCPSent, tcp.TCPReceived) {
		tcpFailure = errProbeUnavailable
	}
	if udpFailure != nil {
		result.Err = wrapProbeFailure(errProbeUDPFailed, udpFailure)
	}
	if tcpFailure != nil {
		if result.Err != nil {
			result.Err = fmt.Errorf("%w: udp=%v tcp=%v", errNoHealthyCandidate, udpFailure, tcpFailure)
		} else {
			result.Err = wrapProbeFailure(errProbeTCPFailed, tcpFailure)
		}
	}
	return result
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
	return rankProbeResults(results, "udp")
}

func rankProbeResults(results []udpProbeResult, transport string) []udpProbeResult {
	ranked := append([]udpProbeResult(nil), results...)
	sort.SliceStable(ranked, func(left, right int) bool {
		l := ranked[left]
		r := ranked[right]
		_, lQualitySent, _, _, _ := l.qualityFor(transport)
		_, rQualitySent, _, _, _ := r.qualityFor(transport)
		lUsable := l.usableFor(transport) && lQualitySent > 0
		rUsable := r.usableFor(transport) && rQualitySent > 0
		if lUsable != rUsable {
			return lUsable
		}
		if lTier, rTier := selectionTierRank(l.SelectionTier), selectionTierRank(r.SelectionTier); lTier != rTier {
			return lTier < rTier
		}
		llost, lsent, ljitter, lp95, lmedian := l.qualityFor(transport)
		rlost, rsent, rjitter, rp95, rmedian := r.qualityFor(transport)
		if llost*rsent != rlost*lsent {
			return llost*rsent < rlost*lsent
		}
		if ljitter != rjitter {
			return ljitter < rjitter
		}
		if lp95 != rp95 {
			return lp95 < rp95
		}
		if lmedian != rmedian {
			return lmedian < rmedian
		}
		return l.Index < r.Index
	})
	return ranked
}

func probeLease(ctx context.Context, lease ProviderLease, config Config, policy Policy) udpProbeResult {
	switch policy.Transport {
	case "tcp":
		return probeTCPLease(ctx, lease, config, policy)
	case "tcp+udp":
		var udpResult, tcpResult udpProbeResult
		var wait sync.WaitGroup
		wait.Add(2)
		go func() {
			defer wait.Done()
			udpResult = probeUDPLease(ctx, lease, config)
		}()
		go func() {
			defer wait.Done()
			tcpResult = probeTCPLease(ctx, lease, config, policy)
		}()
		wait.Wait()
		return dualProbeResult(udpResult, tcpResult)
	default:
		return probeUDPLease(ctx, lease, config)
	}
}

func stringsContainsUDP(transport string) bool {
	return transport == "udp" || transport == "tcp+udp"
}

func probeTCPLease(ctx context.Context, lease ProviderLease, config Config, policy Policy) udpProbeResult {
	result := udpProbeResult{SelectionTier: lease.SelectionTier, Sent: 1, TCPSent: 1}
	started := time.Now()
	host, port, err := probeTarget(config)
	if err != nil {
		result.TCPError = wrapProbeFailure(errProbeTCPFailed, err)
		result.Err = result.TCPError
		return result
	}
	select {
	case <-ctx.Done():
		result.TCPError = wrapProbeFailure(errProbeTCPFailed, ctx.Err())
		result.Err = result.TCPError
		return result
	default:
	}
	connection, err := dialStream(lease, host, port, probeTimeout(config))
	if err != nil {
		result.TCPError = wrapProbeFailure(errProbeTCPFailed, err)
		result.Err = result.TCPError
		return result
	}
	_ = connection.Close()
	result.Received = 1
	result.TCPReceived = 1
	result.TCPRTT = time.Since(started)
	result.MedianRTT = result.TCPRTT
	result.P95RTT = result.TCPRTT
	return result
}

func probeUDPLease(ctx context.Context, lease ProviderLease, config Config) udpProbeResult {
	result := udpProbeResult{SelectionTier: lease.SelectionTier, Sent: 0}
	defer func() {
		if result.Err != nil && !errors.Is(result.Err, errProbeUDPFailed) {
			result.Err = wrapProbeFailure(errProbeUDPFailed, result.Err)
		}
	}()
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
	if len(response) < 12 || binary.BigEndian.Uint16(response[:2]) != transactionID {
		return false
	}
	flags := binary.BigEndian.Uint16(response[2:4])
	if flags&0x8000 == 0 || flags&0x7800 != 0 || flags&0x0200 != 0 || flags&0x000f != 0 {
		return false
	}
	if binary.BigEndian.Uint16(response[4:6]) != 1 {
		return false
	}
	offset, ok := skipDNSName(response, 12)
	if !ok || offset+4 > len(response) {
		return false
	}
	offset += 4
	counts := []int{
		int(binary.BigEndian.Uint16(response[6:8])),
		int(binary.BigEndian.Uint16(response[8:10])),
		int(binary.BigEndian.Uint16(response[10:12])),
	}
	for _, count := range counts {
		for range count {
			var nameOK bool
			offset, nameOK = skipDNSName(response, offset)
			if !nameOK || offset+10 > len(response) {
				return false
			}
			rdataLength := int(binary.BigEndian.Uint16(response[offset+8 : offset+10]))
			offset += 10
			if rdataLength > len(response)-offset {
				return false
			}
			offset += rdataLength
		}
	}
	return offset == len(response)
}

// skipDNSName validates a DNS name and returns the first byte after its
// encoded form. Compression pointers are followed only for validation; the
// caller continues after the pointer in the original message.
func skipDNSName(message []byte, offset int) (int, bool) {
	if offset < 0 || offset >= len(message) {
		return 0, false
	}
	cursor := offset
	next := offset
	jumped := false
	for jumps := 0; jumps <= 16; jumps++ {
		if cursor >= len(message) {
			return 0, false
		}
		length := message[cursor]
		switch {
		case length == 0:
			if !jumped {
				next = cursor + 1
			}
			return next, next <= len(message)
		case length&0xc0 == 0xc0:
			if cursor+1 >= len(message) {
				return 0, false
			}
			pointer := int(length&0x3f)<<8 | int(message[cursor+1])
			if pointer >= len(message) || !jumped && cursor+2 > len(message) {
				return 0, false
			}
			if !jumped {
				next = cursor + 2
				jumped = true
			}
			cursor = pointer
		case length&0xc0 != 0 || length > 63:
			return 0, false
		default:
			cursor += 1 + int(length)
			if cursor > len(message) {
				return 0, false
			}
		}
	}
	return 0, false
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
	var wait sync.WaitGroup
	wait.Add(workers)
	for range workers {
		go func() {
			defer wait.Done()
			for {
				var index int
				var ok bool
				select {
				case <-ctx.Done():
					return
				case index, ok = <-jobs:
					if !ok {
						return
					}
				}
				results[index] = probe(ctx, unique[index], config, policy)
				// The lease is authoritative for tier metadata. A custom probe
				// may return quality only, so carry the candidate tier into the
				// result used by ranking and status reporting.
				results[index].SelectionTier = unique[index].SelectionTier
				results[index].Index = index
			}
		}()
	}
dispatch:
	for index := range unique {
		select {
		case jobs <- index:
		case <-ctx.Done():
			break dispatch
		}
	}
	close(jobs)
	wait.Wait()
	ranked := rankProbeResults(results, policy.Transport)
	for index, result := range results {
		if result.Err != nil {
			lease := unique[index]
			log.Printf("ultra UDP candidate probe failed endpoint=%s:%d selection_ref=%s error=%v", lease.IP, lease.Port, lease.SelectionRef, result.Err)
		}
	}
	if ctx.Err() != nil {
		return ProviderLease{}, udpProbeResult{}, ctx.Err()
	}
	if len(ranked) == 0 || !ranked[0].usableFor(policy.Transport) {
		if len(ranked) == 0 {
			return ProviderLease{}, udpProbeResult{}, errNoHealthyCandidate
		}
		return ProviderLease{}, ranked[0], fmt.Errorf("%w: %v", errNoHealthyCandidate, ranked[0].protocolFailure(policy.Transport))
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
