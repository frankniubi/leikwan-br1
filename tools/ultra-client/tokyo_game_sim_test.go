//go:build tokyo

// Requires the Tokyo synthetic relay deployed and reachable
// (tools/ultra-client/tokyo_sim/relay, UDP 60002 + TCP 60003) plus the python
// echo servers on 60000/60001. Run explicitly:
//   go test -tags tokyo -run TestTokyoGameSim -count=1 -v
package main

import (
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"runtime"
	"sync"
	"testing"
	"time"
)

// ---------------------------------------------------------------------------
// Tokyo game-sim experiment (two-path comparison).
//
// Phase 1 (raw path, from this Windows machine): UDP sequence packets straight
// to the Tokyo python echo server 185.220.238.143:60000 (preflight 10pps/5s +
// 60pps/60s) and 20 sequential TCP echo connections to :60001.
//
// Phase 2 (full Ultra relay path): a minimal Ultra relay binary on Tokyo
// (UDP 60002 + TCP control 60003, tools/ultra-client/tokyo_sim/relay) is
// driven through the production serveUDP / serveTCP data plane with a
// PolicyStore.Replace-injected policy+lease. UDP phases 60pps/60s, 240pps/30s,
// 500pps/15s, then 13 cycles of 800pps x 1s every 1.5s (burst); TCP 30
// sequential echo connections. Mixed payload sizes 64/512/1200.
//
// Results are written to ../../.tmp/game-sim-tokyo-results.json and the
// per-phase numbers are also logged for `go test -v`.
//
// This file only reuses read-only helpers (phaseSpec/phaseResult/sizeStats,
// summarizeLatency, rttJitter, pacedSender, buildGamePayload, buildTCPPayload,
// freeUDPPort/freeTCPPort, installGameSimHooks) from game_sim_test.go; it does
// not modify any existing file.
// ---------------------------------------------------------------------------

const (
	tokyoRelayHost     = "185.220.238.143"
	tokyoEchoUDPPort   = 60000
	tokyoEchoTCPPort   = 60001
	tokyoRelayUDPPort  = 60002
	tokyoRelayTCPPort  = 60003
	tokyoSimUser       = "tokyo-sim-user"
	tokyoSimCredential = "1234567890123456" // 16 chars; checksum uses indexes 6 and 10
	tokyoUDPTarget     = "185.220.238.143:60000"
	tokyoTCPTarget     = "185.220.238.143:60001"
)

func tokyoStore(t *testing.T, config Config, transport string, relayIP string, relayPort int) *PolicyStore {
	t.Helper()
	store := NewPolicyStore(config)
	now := time.Now()
	policy := Policy{
		Protocol: policyProtocol, ForwardID: config.ForwardID,
		PolicyGeneration: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		SessionTicket:    "ticket", ExpiresAt: now.Add(4 * time.Minute), Transport: transport,
		RateLimitMbps: 10000, QuotaRemainingBytes: 1 << 31, TargetDigest: targetDigest(config.Target),
	}
	lease := ProviderLease{
		LeaseID: "ul2_tokyo_sim", PolicyGeneration: policy.PolicyGeneration, ExpiresAt: policy.ExpiresAt,
		Transport: transport, SelectionRef: "tokyo-game-sim-selection-reference-0001",
		NonceHash: "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
		IP:        relayIP, Port: relayPort,
		User: []byte(tokyoSimUser), Password: []byte(tokyoSimCredential),
	}
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	return store
}

// ---------------------------------------------------------------------------
// UDP client (binds 0.0.0.0 so it can reach both external Tokyo and the
// runtime's local 127.0.0.1 listener; 4MB buffers on both sides).
// ---------------------------------------------------------------------------

type tokyoUDPClient struct {
	conn        *net.UDPConn
	destination *net.UDPAddr
}

func newTokyoUDPClient(destination *net.UDPAddr) (*tokyoUDPClient, error) {
	conn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4zero, Port: 0})
	if err != nil {
		return nil, err
	}
	_ = conn.SetReadBuffer(4 << 20)
	_ = conn.SetWriteBuffer(4 << 20)
	return &tokyoUDPClient{conn: conn, destination: destination}, nil
}

func (client *tokyoUDPClient) close() {
	if client != nil && client.conn != nil {
		_ = client.conn.Close()
	}
}

// tokyoRunMeasuredPhase mirrors the game-sim phase measurement: a reader
// goroutine collects echoed seq/timestamp payloads while the sender paces
// writes, then computes sent/recv/loss/RTT percentiles/jitter. phaseID tags
// packets so late echoes from a previous phase can never be mis-attributed.
func (client *tokyoUDPClient) tokyoRunMeasuredPhase(
	t *testing.T,
	spec phaseSpec,
	phaseID uint16,
	grace time.Duration,
	sender func(deadline time.Time, send func(seq uint64) bool) int64,
) phaseResult {
	t.Helper()
	t.Logf("phase start: %s target=%dpps window=%s sizes=%v dest=%s", spec.name, spec.pps, spec.window, spec.sizes, client.destination)
	result := phaseResult{name: spec.name, ppsTarget: spec.pps, planned: spec.window}
	rtts := make(map[uint64]time.Duration)
	recvBySize := make(map[int]int64)
	rttBySize := make(map[int][]time.Duration)
	var mutex sync.Mutex
	stopReader := make(chan struct{})
	readerDone := make(chan struct{})
	go func() {
		defer close(readerDone)
		buffer := make([]byte, 65535)
		for {
			select {
			case <-stopReader:
				return
			default:
			}
			_ = client.conn.SetReadDeadline(time.Now().Add(20 * time.Millisecond))
			count, _, err := client.conn.ReadFromUDP(buffer)
			if err != nil {
				select {
				case <-stopReader:
					return
				default:
				}
				if networkErr, ok := err.(net.Error); ok && networkErr.Timeout() {
					continue
				}
				return
			}
			if count < 22 || buffer[0] != 'G' || buffer[1] != 'M' || buffer[2] != 'S' || buffer[3] != 'I' {
				continue
			}
			if binary.BigEndian.Uint16(buffer[20:22]) != phaseID {
				continue
			}
			seq := binary.BigEndian.Uint64(buffer[4:12])
			sentAt := binary.BigEndian.Uint64(buffer[12:20])
			rtt := time.Duration(time.Now().UnixNano() - int64(sentAt))
			size := spec.sizes[int(seq)%len(spec.sizes)]
			mutex.Lock()
			if _, exists := rtts[seq]; !exists {
				rtts[seq] = rtt
				recvBySize[size]++
				rttBySize[size] = append(rttBySize[size], rtt)
			}
			mutex.Unlock()
		}
	}()

	started := time.Now()
	result.sent = sender(started.Add(spec.window), func(seq uint64) bool {
		payload := buildGamePayload(spec.sizes[int(seq)%len(spec.sizes)], seq, phaseID)
		_, err := client.conn.WriteToUDP(payload, client.destination)
		return err == nil
	})
	result.actual = time.Since(started)

	drainUntil := time.Now().Add(grace)
	for time.Now().Before(drainUntil) {
		time.Sleep(20 * time.Millisecond)
	}
	close(stopReader)
	<-readerDone

	mutex.Lock()
	result.recv = int64(len(rtts))
	mutex.Unlock()
	result.loss = result.sent - result.recv
	if result.sent > 0 {
		result.lossPct = float64(result.loss) * 100 / float64(result.sent)
	}
	ordered := make([]float64, 0, len(rtts))
	for index := uint64(0); index < uint64(result.sent); index++ {
		if rtt, ok := rtts[index]; ok {
			ordered = append(ordered, float64(rtt)/float64(time.Millisecond))
		}
	}
	result.rttMin, result.rttMean, result.rttP50, result.rttP90, result.rttP99, result.rttP999, result.rttMax = summarizeLatency(ordered)
	result.jitter = rttJitter(ordered)
	for _, size := range spec.sizes {
		perSize := sizeStats{size: size, recv: recvBySize[size]}
		for index := uint64(0); index < uint64(result.sent); index++ {
			if spec.sizes[int(index)%len(spec.sizes)] == size {
				perSize.sent++
			}
		}
		perSize.loss = perSize.sent - perSize.recv
		values := make([]float64, 0, len(rttBySize[size]))
		for _, rtt := range rttBySize[size] {
			values = append(values, float64(rtt)/float64(time.Millisecond))
		}
		_, _, perSize.rttP50, _, perSize.rttP99, _, perSize.rttMax = summarizeLatency(values)
		result.perSize = append(result.perSize, perSize)
	}
	t.Logf("phase done: %s sent=%d recv=%d loss=%d actual=%.2fs pps=%.1f meanRTT=%.3fms p99=%.3fms jitter=%.3fms",
		spec.name, result.sent, result.recv, result.loss, result.actual.Seconds(),
		float64(result.sent)/result.actual.Seconds(), result.rttMean, result.rttP99, result.jitter)
	return result
}

func (client *tokyoUDPClient) tokyoRunPhase(t *testing.T, spec phaseSpec, phaseID uint16, grace time.Duration) phaseResult {
	batchSize := 1
	if spec.pps >= 100 {
		batchSize = spec.pps / 100
	}
	return client.tokyoRunMeasuredPhase(t, spec, phaseID, grace, pacedSender(spec.pps, batchSize))
}

// tokyoRunBurstPhase emits 13 cycles of an 800pps burst lasting 1s every 1.5s
// (~19.5s window, matching the requested 20s burst schedule).
func (client *tokyoUDPClient) tokyoRunBurstPhase(t *testing.T, sizes []int, phaseID uint16, grace time.Duration) phaseResult {
	const burstPPS = 800
	const bursts = 13
	spec := phaseSpec{name: "burst 800pps x1s every 1.5s", pps: burstPPS,
		window: time.Duration(bursts) * 1500 * time.Millisecond, sizes: sizes}
	sender := func(deadline time.Time, send func(seq uint64) bool) int64 {
		interval := time.Duration(float64(time.Second) / float64(burstPPS))
		batchSize := burstPPS / 100
		var sent int64
		var seq uint64
		for cycle := 0; cycle < bursts; cycle++ {
			cycleStart := time.Now()
			burstDeadline := cycleStart.Add(time.Second)
			next := time.Now()
			for time.Now().Before(burstDeadline) {
				for index := 0; index < batchSize && time.Now().Before(burstDeadline); index++ {
					if !send(seq) {
						return sent
					}
					sent++
					seq++
				}
				next = next.Add(time.Duration(batchSize) * interval)
				if delay := time.Until(next); delay > 0 {
					time.Sleep(delay)
				}
			}
			if remaining := time.Until(cycleStart.Add(1500 * time.Millisecond)); remaining > 0 {
				time.Sleep(remaining)
			}
		}
		return sent
	}
	return client.tokyoRunMeasuredPhase(t, spec, phaseID, grace, sender)
}

// ---------------------------------------------------------------------------
// TCP echo probe (sequential connect + 512B echo, connect/first-byte/echo ms)
// ---------------------------------------------------------------------------

type tokyoTCPResult struct {
	attempts    int
	connectOK   int
	firstByteOK int
	echoOK      int
	connectMs   []float64
	firstByteMs []float64
	echoMs      []float64
}

func runTokyoTCPProbe(t *testing.T, host string, port int, attempts int) tokyoTCPResult {
	result := tokyoTCPResult{attempts: attempts}
	address := net.JoinHostPort(host, fmt.Sprint(port))
	for index := 0; index < attempts; index++ {
		started := time.Now()
		conn, err := net.DialTimeout("tcp", address, 5*time.Second)
		if err != nil {
			t.Logf("tcp conn %d: dial failed: %v", index, err)
			continue
		}
		result.connectOK++
		result.connectMs = append(result.connectMs, float64(time.Since(started))/float64(time.Millisecond))
		payload := buildTCPPayload(index, 512)
		writeStarted := time.Now()
		if _, err := conn.Write(payload); err != nil {
			_ = conn.Close()
			continue
		}
		_ = conn.SetReadDeadline(time.Now().Add(5 * time.Second))
		first := make([]byte, 1)
		if _, err := io.ReadFull(conn, first); err != nil {
			t.Logf("tcp conn %d: first byte failed: %v", index, err)
			_ = conn.Close()
			continue
		}
		result.firstByteOK++
		result.firstByteMs = append(result.firstByteMs, float64(time.Since(writeStarted))/float64(time.Millisecond))
		rest := make([]byte, len(payload)-1)
		if _, err := io.ReadFull(conn, rest); err != nil {
			_ = conn.Close()
			continue
		}
		echo := append(first, rest...)
		if !bytes.Equal(echo, payload) {
			t.Logf("tcp conn %d: echo mismatch", index)
			_ = conn.Close()
			continue
		}
		result.echoOK++
		result.echoMs = append(result.echoMs, float64(time.Since(writeStarted))/float64(time.Millisecond))
		_ = conn.Close()
	}
	return result
}

// ---------------------------------------------------------------------------
// JSON result shapes
// ---------------------------------------------------------------------------

type tokyoLatJSON struct {
	N      int     `json:"n"`
	Min    float64 `json:"min_ms"`
	Mean   float64 `json:"mean_ms"`
	P50    float64 `json:"p50_ms"`
	P90    float64 `json:"p90_ms"`
	P99    float64 `json:"p99_ms"`
	P999   float64 `json:"p999_ms"`
	Max    float64 `json:"max_ms"`
	Jitter float64 `json:"jitter_ms"`
}

type tokyoSizeJSON struct {
	Size   int     `json:"size"`
	Sent   int64   `json:"sent"`
	Recv   int64   `json:"recv"`
	Loss   int64   `json:"loss"`
	RTTP50 float64 `json:"rtt_p50_ms"`
	RTTP99 float64 `json:"rtt_p99_ms"`
	RTTMax float64 `json:"rtt_max_ms"`
}

type tokyoPhaseJSON struct {
	Name     string          `json:"name"`
	PPS      int             `json:"pps_target"`
	PlannedS float64         `json:"planned_s"`
	ActualS  float64         `json:"actual_s"`
	Sent     int64           `json:"sent"`
	Recv     int64           `json:"recv"`
	Loss     int64           `json:"loss"`
	LossPct  float64         `json:"loss_pct"`
	RTTMin   float64         `json:"rtt_min_ms"`
	RTTMean  float64         `json:"rtt_mean_ms"`
	RTTP50   float64         `json:"rtt_p50_ms"`
	RTTP90   float64         `json:"rtt_p90_ms"`
	RTTP99   float64         `json:"rtt_p99_ms"`
	RTTP999  float64         `json:"rtt_p999_ms"`
	RTTMax   float64         `json:"rtt_max_ms"`
	Jitter   float64         `json:"jitter_ms"`
	PerSize  []tokyoSizeJSON `json:"per_size"`
}

type tokyoTCPJSON struct {
	Attempts    int          `json:"attempts"`
	ConnectOK   int          `json:"connect_ok"`
	FirstByteOK int          `json:"first_byte_ok"`
	EchoOK      int          `json:"echo_ok"`
	ConnectMs   tokyoLatJSON `json:"connect_ms"`
	FirstByteMs tokyoLatJSON `json:"first_byte_ms"`
	EchoMs      tokyoLatJSON `json:"echo_ms"`
}

func tokyoLatencyOf(values []float64) tokyoLatJSON {
	min, mean, p50, p90, p99, p999, max := summarizeLatency(values)
	return tokyoLatJSON{N: len(values), Min: min, Mean: mean, P50: p50, P90: p90, P99: p99, P999: p999, Max: max, Jitter: rttJitter(values)}
}

func tokyoPhaseJSONOf(phase phaseResult) tokyoPhaseJSON {
	out := tokyoPhaseJSON{
		Name: phase.name, PPS: phase.ppsTarget, PlannedS: phase.planned.Seconds(), ActualS: phase.actual.Seconds(),
		Sent: phase.sent, Recv: phase.recv, Loss: phase.loss, LossPct: phase.lossPct,
		RTTMin: phase.rttMin, RTTMean: phase.rttMean, RTTP50: phase.rttP50, RTTP90: phase.rttP90,
		RTTP99: phase.rttP99, RTTP999: phase.rttP999, RTTMax: phase.rttMax, Jitter: phase.jitter,
	}
	for _, perSize := range phase.perSize {
		out.PerSize = append(out.PerSize, tokyoSizeJSON{
			Size: perSize.size, Sent: perSize.sent, Recv: perSize.recv, Loss: perSize.loss,
			RTTP50: perSize.rttP50, RTTP99: perSize.rttP99, RTTMax: perSize.rttMax,
		})
	}
	return out
}

func tokyoTCPJSONOf(result tokyoTCPResult) tokyoTCPJSON {
	return tokyoTCPJSON{
		Attempts: result.attempts, ConnectOK: result.connectOK, FirstByteOK: result.firstByteOK, EchoOK: result.echoOK,
		ConnectMs: tokyoLatencyOf(result.connectMs), FirstByteMs: tokyoLatencyOf(result.firstByteMs), EchoMs: tokyoLatencyOf(result.echoMs),
	}
}

func waitTokyoArmed(obs *gameSimSocketObservations, timeout time.Duration) bool {
	deadline := time.Now().Add(timeout)
	for {
		obs.mu.Lock()
		arms := obs.arms
		obs.mu.Unlock()
		if arms > 0 {
			return true
		}
		if time.Now().After(deadline) {
			return false
		}
		time.Sleep(50 * time.Millisecond)
	}
}

// ---------------------------------------------------------------------------
// The experiment
// ---------------------------------------------------------------------------

func TestTokyoGameSim(t *testing.T) {
	results := make(map[string]any)
	defer func() {
		data, err := json.MarshalIndent(results, "", "  ")
		if err != nil {
			t.Logf("marshal results: %v", err)
			return
		}
		_ = os.MkdirAll("../../.tmp", 0o755)
		path := "../../.tmp/game-sim-tokyo-results.json"
		if err := os.WriteFile(path, append(data, []byte("\n")...), 0o644); err != nil {
			t.Logf("write results: %v", err)
			return
		}
		t.Logf("results written to %s", path)
	}()

	results["meta"] = map[string]any{
		"generated_utc":  time.Now().UTC().Format("2006-01-02T15:04:05Z"),
		"go":             runtime.Version(),
		"os":             runtime.GOOS + "/" + runtime.GOARCH,
		"relay_host":     tokyoRelayHost,
		"echo_udp_port":  tokyoEchoUDPPort,
		"echo_tcp_port":  tokyoEchoTCPPort,
		"relay_udp_port": tokyoRelayUDPPort,
		"relay_tcp_port": tokyoRelayTCPPort,
		"user":           tokyoSimUser,
		"credential":     tokyoSimCredential,
		"udp_target":     tokyoUDPTarget,
		"tcp_target":     tokyoTCPTarget,
	}

	sizes := []int{64, 512, 1200}

	// ---- Phase 1: raw path, Windows -> Tokyo echo server ----------------
	rawClient, err := newTokyoUDPClient(&net.UDPAddr{IP: net.ParseIP(tokyoRelayHost), Port: tokyoEchoUDPPort})
	if err != nil {
		t.Fatalf("udp client: %v", err)
	}
	preflight := rawClient.tokyoRunPhase(t, phaseSpec{name: "raw udp preflight 10pps/5s", pps: 10, window: 5 * time.Second, sizes: sizes}, 0x01, 2*time.Second)
	rawUDP := rawClient.tokyoRunPhase(t, phaseSpec{name: "raw udp 60pps/60s", pps: 60, window: 60 * time.Second, sizes: sizes}, 0x02, 2*time.Second)
	rawClient.close()
	rawTCP := runTokyoTCPProbe(t, tokyoRelayHost, tokyoEchoTCPPort, 20)
	results["phase1_windows_raw"] = map[string]any{
		"host":          tokyoRelayHost,
		"udp_preflight": tokyoPhaseJSONOf(preflight),
		"udp_60pps_60s": tokyoPhaseJSONOf(rawUDP),
		"tcp_20":        tokyoTCPJSONOf(rawTCP),
	}

	// ---- Phase 2: serveUDP + Tokyo Ultra relay ---------------------------
	udpConfig := Config{
		ForwardID:                4343,
		ListenHost:               "127.0.0.1",
		ListenPort:               freeUDPPort(t),
		Target:                   tokyoUDPTarget,
		Transport:                "udp",
		UDPPrewarm:               true,
		UDPKeepaliveIntervalSecs: 5,
		UDPQueuePackets:          512,
		UDPMaxAssociations:       16,
		UDPSetupWorkers:          8,
		ConnectTimeoutSecs:       5,
	}
	udpStore := tokyoStore(t, udpConfig, "udp", tokyoRelayHost, tokyoRelayTCPPort)
	udpStatus := newTestStatusWriter(t, filepath.Join(t.TempDir(), "tokyo-udp-status.json"), udpConfig.ForwardID, 100*time.Millisecond)
	obs := &gameSimSocketObservations{}
	uninstall := installGameSimHooks(obs)
	udpCtx, udpCancel := context.WithCancel(context.Background())
	udpServeErr := make(chan error, 1)
	go func() { udpServeErr <- serveUDP(udpCtx, udpConfig, udpStore, udpStatus) }()

	phase2UDP := make(map[string]any)
	armed := waitTokyoArmed(obs, 25*time.Second)
	if !armed {
		t.Errorf("UDP datagram association never armed against Tokyo relay (cmd=3 control handshake + sid scan failed within 25s)")
		phase2UDP["arm_failed"] = true
		phase2UDP["note"] = "serveUDP prewarm could not complete the cmd=3 control handshake + sid scan against 185.220.238.143:60003/60002 within 25s"
	} else {
		time.Sleep(300 * time.Millisecond) // let the armed session settle before client traffic
		localClient, clientErr := newTokyoUDPClient(&net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: udpConfig.ListenPort})
		if clientErr != nil {
			t.Fatalf("local udp client: %v", clientErr)
		}
		phases := []phaseResult{
			localClient.tokyoRunPhase(t, phaseSpec{name: "relay udp 60pps/60s", pps: 60, window: 60 * time.Second, sizes: sizes}, 0x11, 2*time.Second),
			localClient.tokyoRunPhase(t, phaseSpec{name: "relay udp 240pps/30s", pps: 240, window: 30 * time.Second, sizes: sizes}, 0x12, 2*time.Second),
			localClient.tokyoRunPhase(t, phaseSpec{name: "relay udp 500pps/15s", pps: 500, window: 15 * time.Second, sizes: sizes}, 0x13, 2*time.Second),
			localClient.tokyoRunBurstPhase(t, sizes, 0x14, 2*time.Second),
		}
		localClient.close()
		jsonPhases := make([]tokyoPhaseJSON, 0, len(phases))
		for _, phase := range phases {
			jsonPhases = append(jsonPhases, tokyoPhaseJSONOf(phase))
		}
		obs.mu.Lock()
		arms := obs.arms
		obs.mu.Unlock()
		udpStatus.mu.Lock()
		queueDrops := udpStatus.status.UDPQueueDrops
		keepaliveACKs := udpStatus.status.UDPKeepaliveACKs
		udpStatus.mu.Unlock()
		phase2UDP["phases"] = jsonPhases
		phase2UDP["armed_sessions"] = arms
		phase2UDP["queue_drops"] = queueDrops
		phase2UDP["keepalive_acks"] = keepaliveACKs
	}
	uninstall()
	udpCancel()
	select {
	case err := <-udpServeErr:
		if err != nil {
			t.Logf("UDP runtime stopped with error: %v", err)
		}
	case <-time.After(3 * time.Second):
		t.Log("UDP runtime did not stop promptly")
	}
	udpStatus.Close()
	results["phase2_relay_udp"] = phase2UDP

	// ---- Phase 2: serveTCP + Tokyo Ultra relay ---------------------------
	tcpConfig := Config{
		ForwardID:          4344,
		ListenHost:         "127.0.0.1",
		ListenPort:         freeTCPPort(t),
		Target:             tokyoTCPTarget,
		Transport:          "tcp",
		MaxTCPConnections:  512,
		ConnectTimeoutSecs: 5,
	}
	tcpStore := tokyoStore(t, tcpConfig, "tcp", tokyoRelayHost, tokyoRelayTCPPort)
	tcpStatus := newTestStatusWriter(t, filepath.Join(t.TempDir(), "tokyo-tcp-status.json"), tcpConfig.ForwardID, 100*time.Millisecond)
	tcpCtx, tcpCancel := context.WithCancel(context.Background())
	tcpServeErr := make(chan error, 1)
	go func() { tcpServeErr <- serveTCP(tcpCtx, tcpConfig, tcpStore, tcpStatus) }()

	address := net.JoinHostPort(tcpConfig.ListenHost, fmt.Sprint(tcpConfig.ListenPort))
	waitDeadline := time.Now().Add(5 * time.Second)
	for {
		probe, probeErr := net.DialTimeout("tcp", address, 200*time.Millisecond)
		if probeErr == nil {
			_ = probe.Close()
			break
		}
		if time.Now().After(waitDeadline) {
			t.Fatal("TCP runtime listener never became ready")
		}
		time.Sleep(20 * time.Millisecond)
	}
	relayTCP := runTokyoTCPProbe(t, "127.0.0.1", tcpConfig.ListenPort, 30)
	tcpCancel()
	select {
	case err := <-tcpServeErr:
		if err != nil {
			t.Logf("TCP runtime stopped with error: %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Log("TCP runtime did not stop promptly")
	}
	tcpStatus.Close()
	results["phase2_relay_tcp"] = tokyoTCPJSONOf(relayTCP)

	t.Logf("SUMMARY rawUDP sent=%d recv=%d loss=%d p50=%.3fms | rawTCP echoOK=%d/%d | relayTCP echoOK=%d/%d",
		rawUDP.sent, rawUDP.recv, rawUDP.loss, rawUDP.rttP50, rawTCP.echoOK, rawTCP.attempts, relayTCP.echoOK, relayTCP.attempts)
}
