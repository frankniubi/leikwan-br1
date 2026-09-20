package main

import (
	"context"
	"errors"
	"io"
	"net"
	"path/filepath"
	"strings"
	"sync/atomic"
	"syscall"
	"testing"
	"time"
)

func TestHATCPRotationDuringHandshakePreservesConnection(t *testing.T) {
	s, p, ls := haStore(t)
	a, b := net.Pipe()
	defer b.Close()
	conn, chosen, err := dialCandidateStream(context.Background(), s, "example.test", 443, time.Second, func(_ ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
		p.PolicyGeneration = strings.Repeat("f", 64)
		for i := range ls {
			ls[i].PolicyGeneration = p.PolicyGeneration
			ls[i].NonceHash = strings.Repeat(string(rune('d'+i)), 64)
		}
		if err := s.ReplaceCandidates(p, ls[0], ls); err != nil {
			t.Fatal(err)
		}
		return a, nil
	})
	if err != nil {
		t.Fatalf("routine rotation rejected live handshake: %v", err)
	}
	conn.Close()
	chosen.Lease.wipe()
}

func TestHACandidateTotalBudgetAndCancel(t *testing.T) {
	for _, cancelEarly := range []bool{false, true} {
		s, _, _ := haStore(t)
		ctx, cancel := context.WithCancel(context.Background())
		if cancelEarly {
			time.AfterFunc(15*time.Millisecond, cancel)
		}
		start := time.Now()
		var calls atomic.Int32
		_, _, err := dialCandidateStream(ctx, s, "example.test", 443, 100*time.Millisecond, func(_ ProviderLease, _ string, _ int, b time.Duration) (net.Conn, error) {
			calls.Add(1)
			time.Sleep(b)
			return nil, context.DeadlineExceeded
		})
		cancel()
		if err == nil {
			t.Fatal("blackhole succeeded")
		}
		if time.Since(start) > 250*time.Millisecond {
			t.Fatal("total deadline multiplied by candidate count")
		}
		if cancelEarly && calls.Load() != 1 {
			t.Fatal("cancellation tried another candidate")
		}
	}
}

func TestHAUDPFirstPacketRetriesBackup(t *testing.T) {
	provider := newTestUDPProvider(t)
	local, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer local.Close()
	config := Config{ForwardID: 42, Target: "127.0.0.1:53", Transport: "udp", ConnectTimeoutSecs: 1}
	store := testUDPStore(t, config, provider)
	defer store.Revoke("")
	snapshot, _ := store.Snapshot()
	defer snapshot.Lease.wipe()
	bad := snapshot.Lease
	bad.Port = 1
	bad.SelectionRef = "selection-reference-unreachable"
	bad.NonceHash = strings.Repeat("c", 64)
	good := snapshot.Lease
	good.NonceHash = strings.Repeat("d", 64)
	if err := store.ReplaceCandidates(snapshot.Policy, bad, []ProviderLease{bad, good}); err != nil {
		t.Fatal(err)
	}
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer status.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- serveUDPOnConn(ctx, config, store, status, local, "127.0.0.1", 53) }()
	client, err := net.DialUDP("udp4", nil, local.LocalAddr().(*net.UDPAddr))
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	client.SetDeadline(time.Now().Add(2 * time.Second))
	client.Write([]byte("first-packet-survives"))
	b := make([]byte, 100)
	n, err := client.Read(b)
	cancel()
	<-done
	if err != nil || string(b[:n]) != "first-packet-survives" {
		t.Fatalf("lost queued packet during candidate failure: n=%d err=%v", n, err)
	}
}

func TestHAStatusHistorySurvivesSuccessfulProbe(t *testing.T) {
	st := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer st.Close()
	st.RecordHAEvent("udp", "keepalive_timeout", "generation")
	st.RecordUDPAttached("actual-session", "paid")
	st.UpdateUDPProbe("selection", udpProbeResult{Sent: 30, Received: 30})
	st.mu.Lock()
	defer st.mu.Unlock()
	if st.status.HAFailures != 1 || len(st.status.HAEvents) != 1 {
		t.Fatal("probe erased failure evidence")
	}
	if st.status.UDPRuntimeSession == nil || st.status.UDPRuntimeSession.SelectionRef != "actual-session" {
		t.Fatal("probe overwrote actual UDP session")
	}
}

func TestHACeilingUnusableAssociationMovesToNextCandidate(t *testing.T) {
	config := testPolicyConfig()
	store := NewPolicyStore(config)
	policy := testPolicy(time.Now(), strings.Repeat("a", 64))
	policy.Transport = "udp"
	leases := []ProviderLease{testLease(policy, strings.Repeat("b", 64)), testLease(policy, strings.Repeat("c", 64))}
	leases[0].CandidateKeyHash = strings.Repeat("d", 64)
	leases[1].CandidateKeyHash = strings.Repeat("e", 64)
	leases[1].IP = "203.0.113.21"
	leases[1].SelectionRef = "selection-reference-second-path"
	if err := store.ReplaceCandidates(policy, leases[0], leases); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { store.Revoke("") })

	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer status.Close()
	runtime := &nativeSocks5Runtime{
		config: config, store: store, status: status,
		associations: map[string]*nativeSocks5UDPAssociation{},
	}
	request := nativeSocks5UDPRequest{host: "1.1.1.1", port: 53, payload: make([]byte, defaultPayloadCeiling)}

	// Two consecutive client datagrams, exactly as the send path sees them: the
	// first association arms, measures no echo, and is torn down; the second
	// verdict isolates the relay through the existing candidate failure path.
	for attempt := 0; attempt < 2; attempt++ {
		association := runtime.ensureAssociation("local", nil)
		session := &DatagramSession{} // no socket: nothing echoes
		if !association.markReady(session, leases[0]) {
			t.Fatal("association did not accept the lease")
		}
		runtime.sendUDPRequest(association, session, request)
		if runtime.associations["local"] != nil {
			t.Fatalf("association attempt %d survived an unusable ceiling", attempt+1)
		}
	}

	candidates := store.CandidateSnapshots("udp")
	defer wipeSnapshots(candidates)
	if len(candidates) != 2 {
		t.Fatalf("candidates after migration = %d, want 2", len(candidates))
	}
	if candidates[0].Lease.SelectionRef != leases[1].SelectionRef {
		t.Fatalf("HA did not migrate: first candidate = %s", candidates[0].Lease.SelectionRef)
	}

	status.mu.Lock()
	defer status.mu.Unlock()
	feedback := status.status.CandidateFeedback
	if len(feedback) == 0 {
		t.Fatal("no candidate feedback published for the unusable ceiling")
	}
	last := feedback[len(feedback)-1]
	if last.Reason != udpPayloadCeilingUnusableReason || last.Outcome != "failure" {
		t.Fatalf("feedback = %+v, want reason %q", last, udpPayloadCeilingUnusableReason)
	}
	if status.status.UDPOversizeDrops != 2 {
		t.Fatalf("udp_oversize_drops = %d, want 2", status.status.UDPOversizeDrops)
	}
}

func haStore(t *testing.T) (*PolicyStore, Policy, []ProviderLease) {
	t.Helper()
	s := NewPolicyStore(testPolicyConfig())
	p := testPolicy(time.Now(), strings.Repeat("a", 64))
	leases := []ProviderLease{testLease(p, strings.Repeat("b", 64)), testLease(p, strings.Repeat("c", 64))}
	leases[1].IP = "203.0.113.21"
	leases[1].SelectionRef = "selection-reference-second-path"
	if err := s.ReplaceCandidates(p, leases[0], leases); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { s.Revoke("") })
	return s, p, leases
}

func TestHACandidateRetryAndProtocolIsolation(t *testing.T) {
	s, _, leases := haStore(t)
	a, b := net.Pipe()
	defer b.Close()
	calls := 0
	conn, chosen, err := dialCandidateStream(context.Background(), s, "example.test", 443, 80*time.Millisecond, func(l ProviderLease, _ string, _ int, budget time.Duration) (net.Conn, error) {
		calls++
		if l.IP == leases[0].IP {
			if budget > 45*time.Millisecond {
				t.Error("first candidate consumed whole budget")
			}
			return nil, errors.New("refused")
		}
		return a, nil
	})
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	defer chosen.Lease.wipe()
	if calls != 2 || chosen.Lease.IP != leases[1].IP {
		t.Fatal("did not retry backup")
	}
	tcp := s.CandidateSnapshots("tcp")
	defer wipeSnapshots(tcp)
	udp := s.CandidateSnapshots("udp")
	defer wipeSnapshots(udp)
	if len(tcp) != 1 || len(udp) != 2 {
		t.Fatalf("protocol isolation tcp=%d udp=%d", len(tcp), len(udp))
	}
}

func TestHACandidateRefreshPreservesIsolationAndRevoke(t *testing.T) {
	s, p, ls := haStore(t)
	s.FailCandidate("tcp", ls[0])
	ls[0].NonceHash = strings.Repeat("d", 64)
	ls[1].NonceHash = strings.Repeat("e", 64)
	if err := s.ReplaceCandidates(p, ls[0], ls); err != nil {
		t.Fatal(err)
	}
	ss := s.CandidateSnapshots("tcp")
	defer wipeSnapshots(ss)
	if len(ss) != 1 || ss[0].Lease.IP != ls[1].IP {
		t.Fatal("refresh erased isolation")
	}
	s.Revoke(p.PolicyGeneration)
	if ss := s.CandidateSnapshots("tcp"); len(ss) != 0 {
		wipeSnapshots(ss)
		t.Fatal("revoked candidates available")
	}
}

func TestHACandidateBatchInvalidIsAtomic(t *testing.T) {
	s, p, ls := haStore(t)
	ls[0].NonceHash = strings.Repeat("d", 64)
	ls[1].NonceHash = ls[0].NonceHash
	if err := s.ReplaceCandidates(p, ls[0], ls); err == nil {
		t.Fatal("duplicate nonce accepted")
	}
	ss := s.CandidateSnapshots("tcp")
	defer wipeSnapshots(ss)
	if len(ss) != 2 || ss[0].Lease.NonceHash != strings.Repeat("b", 64) {
		t.Fatal("invalid batch mutated live policy")
	}
}

func tcpPair(t *testing.T) (*net.TCPConn, *net.TCPConn) {
	t.Helper()
	l, e := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if e != nil {
		t.Fatal(e)
	}
	defer l.Close()
	a, e := net.DialTCP("tcp4", nil, l.Addr().(*net.TCPAddr))
	if e != nil {
		t.Fatal(e)
	}
	b, e := l.AcceptTCP()
	if e != nil {
		a.Close()
		t.Fatal(e)
	}
	t.Cleanup(func() { a.Close(); b.Close() })
	a.SetDeadline(time.Now().Add(3 * time.Second))
	b.SetDeadline(time.Now().Add(3 * time.Second))
	return a, b
}

func TestHATCPHalfCloseDrainsResponse(t *testing.T) {
	client, local := tcpPair(t)
	remote, provider := tcpPair(t)
	st := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer st.Close()
	limiter := NewPolicyLimiter()
	if err := limiter.Replace(20, 1024, time.Now().Add(time.Minute), "test"); err != nil {
		t.Fatal(err)
	}
	done := make(chan struct{})
	go func() {
		relayTCP(context.Background(), local, remote, limiter, st, nil, relayIdentity{forward: 42, generation: "generation", selection: "selection"})
		close(done)
	}()
	client.Write([]byte("request"))
	client.CloseWrite()
	got, e := io.ReadAll(provider)
	if e != nil {
		t.Fatal(e)
	}
	if string(xorOne(got)) != "request" {
		t.Fatalf("request=%x", got)
	}
	provider.Write([]byte("response-after-client-eof"))
	provider.CloseWrite()
	got, e = io.ReadAll(client)
	if e != nil {
		t.Fatal(e)
	}
	if string(got) != "response-after-client-eof" {
		t.Fatalf("truncated response %q", got)
	}
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("relay leaked")
	}
}

func TestHATCPRelayDoesNotScoreLocalSilentStream(t *testing.T) {
	now := time.Now()
	policy := testPolicy(now, strings.Repeat("a", 64))
	lease := testLease(policy, strings.Repeat("b", 64))
	lease.CandidateKeyHash = strings.Repeat("c", 64)
	lease.LeaseHash = strings.Repeat("d", 64)
	store := NewPolicyStore(testPolicyConfig())
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	st := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer st.Close()
	ident := relayIdentity{
		forward: 42, generation: policy.PolicyGeneration, selection: lease.SelectionRef,
		pathKey: haPathKey(lease), candidateKey: lease.CandidateKeyHash, leaseHash: lease.LeaseHash,
	}
	noteRelayOutcome(
		store,
		st,
		ident,
		time.Now(),
		7,
		0,
		relayStreamOrder{
			upstreamEnded:   time.Now(),
			downstreamEnded: time.Now().Add(100 * time.Millisecond),
		},
	)
	store.mu.RLock()
	until, isolated := store.isolated["tcp:"+ident.pathKey]
	store.mu.RUnlock()
	if isolated && until.After(time.Now()) {
		t.Fatal("local-first silent streams must not isolate the path")
	}
}

func TestHATCPRelayAvoidsRepeatedRemoteReadResetLocally(t *testing.T) {
	now := time.Now()
	policy := testPolicy(now, strings.Repeat("a", 64))
	lease := testLease(policy, strings.Repeat("b", 64))
	lease.CandidateKeyHash = strings.Repeat("c", 64)
	lease.LeaseHash = strings.Repeat("d", 64)
	store := NewPolicyStore(testPolicyConfig())
	if err := store.Replace(policy, lease); err != nil {
		t.Fatal(err)
	}
	st := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer st.Close()
	ident := relayIdentity{
		forward: 42, generation: policy.PolicyGeneration, selection: lease.SelectionRef,
		pathKey: haPathKey(lease), candidateKey: lease.CandidateKeyHash, leaseHash: lease.LeaseHash,
	}
	for range 2 {
		noteRelayOutcome(
			store,
			st,
			ident,
			time.Now(),
			7,
			0,
			relayStreamOrder{
				upstreamEnded:   time.Now().Add(10 * time.Millisecond),
				downstreamEnded: time.Now(),
				remoteReadError: syscall.ECONNRESET,
			},
		)
	}
	store.mu.RLock()
	until, isolated := store.isolated["tcp:"+ident.pathKey]
	store.mu.RUnlock()
	if !isolated || !until.After(time.Now()) {
		t.Fatal("repeated remote-first silent streams did not isolate the path")
	}
	st.mu.Lock()
	defer st.mu.Unlock()
	if len(st.status.CandidateFeedback) != 0 {
		t.Fatal("target-specific resets must not penalize global candidate reputation")
	}
	if store.isolated["udp:"+ident.pathKey].After(time.Now()) {
		t.Fatal("TCP resets must not isolate UDP")
	}
}

func TestHATCPRelayAmbiguousSilentStreamsRemainEligible(t *testing.T) {
	now := time.Now()
	for name, order := range map[string]relayStreamOrder{
		"local_first_20ms":  {upstreamEnded: now, downstreamEnded: now.Add(20 * time.Millisecond), remoteReadError: syscall.ECONNRESET},
		"tie":               {upstreamEnded: now, downstreamEnded: now, remoteReadError: syscall.ECONNRESET},
		"remote_eof":        {upstreamEnded: now.Add(time.Millisecond), downstreamEnded: now, remoteReadError: io.EOF},
		"local_write_error": {upstreamEnded: now.Add(time.Millisecond), downstreamEnded: now},
		"unknown_order":     {remoteReadError: syscall.ECONNRESET},
	} {
		t.Run(name, func(t *testing.T) {
			store, policy, leases := haStore(t)
			key := haPathKey(leases[0])
			status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
			defer status.Close()
			for range 3 {
				noteRelayOutcome(store, status, relayIdentity{pathKey: key, generation: policy.PolicyGeneration}, time.Now(), 7, 0, order)
			}
			store.mu.RLock()
			isolated := store.isolated["tcp:"+key].After(time.Now())
			store.mu.RUnlock()
			if isolated {
				t.Fatal("ambiguous/normal end isolated the path")
			}
			status.mu.Lock()
			defer status.mu.Unlock()
			if len(status.status.CandidateFeedback) != 0 {
				t.Fatal("ambiguous/normal end emitted global candidate failure")
			}
		})
	}
}

func TestHADatagramReaderDrainsWarmAndDetectsSilence(t *testing.T) {
	peer, e := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if e != nil {
		t.Fatal(e)
	}
	defer peer.Close()
	c, e := net.DialUDP("udp4", nil, peer.LocalAddr().(*net.UDPAddr))
	if e != nil {
		t.Fatal(e)
	}
	s := &DatagramSession{remote: c, mode: 0x20, sid: []byte{1, 2, 3}}
	defer s.Close()
	s.startRuntimeReader(80 * time.Millisecond)
	ack := append([]byte{0, 0, 0, 1, 2, 0, 0, 0, 0, 0}, []byte(keepPayloadHex)...)
	for i := 0; i < 4; i++ {
		peer.WriteToUDP(ack, c.LocalAddr().(*net.UDPAddr))
		time.Sleep(30 * time.Millisecond)
	}
	if s.runtimeError() != nil {
		t.Fatal("valid warm ACKs did not keep session alive")
	}
	select {
	case <-s.runtimeDone():
	case <-time.After(350 * time.Millisecond):
		t.Fatal("silent session was never invalidated")
	}
	if !errors.Is(s.runtimeError(), errUDPReceiveTimeout) {
		t.Fatalf("reason=%v", s.runtimeError())
	}
}

func TestHADatagramInvalidFramesCannotKeepAlive(t *testing.T) {
	peer, e := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if e != nil {
		t.Fatal(e)
	}
	defer peer.Close()
	c, e := net.DialUDP("udp4", nil, peer.LocalAddr().(*net.UDPAddr))
	if e != nil {
		t.Fatal(e)
	}
	s := &DatagramSession{remote: c}
	defer s.Close()
	s.startRuntimeReader(60 * time.Millisecond)
	for i := 0; i < 8; i++ {
		peer.WriteToUDP([]byte{255}, c.LocalAddr().(*net.UDPAddr))
		time.Sleep(15 * time.Millisecond)
	}
	select {
	case <-s.runtimeDone():
	case <-time.After(200 * time.Millisecond):
		t.Fatal("invalid packet extended deadline")
	}
}

func TestHAUDPSilentPathRecoversWithoutListenerRestart(t *testing.T) {
	first, backup := newTestUDPProvider(t), newTestUDPProvider(t)
	local, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer local.Close()
	config := Config{ForwardID: 42, Target: "127.0.0.1:53", Transport: "udp", UDPPrewarm: true, ConnectTimeoutSecs: 1}
	store := testUDPStore(t, config, first)
	defer store.Revoke("")
	ss, _ := store.Snapshot()
	defer ss.Lease.wipe()
	a, b := ss.Lease, ss.Lease
	a.NonceHash = strings.Repeat("c", 64)
	b.NonceHash = strings.Repeat("d", 64)
	b.Port = backup.tcp.Addr().(*net.TCPAddr).Port
	b.SelectionRef = "selection-reference-healthy-backup"
	if err := store.ReplaceCandidates(ss.Policy, a, []ProviderLease{a, b}); err != nil {
		t.Fatal(err)
	}
	st := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	defer st.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- serveUDPOnConn(ctx, config, store, st, local, "127.0.0.1", 53) }()
	defer func() { cancel(); <-done }()
	c, err := net.DialUDP("udp4", nil, local.LocalAddr().(*net.UDPAddr))
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	echo := func(payload string) bool {
		c.SetDeadline(time.Now().Add(200 * time.Millisecond))
		c.Write([]byte(payload))
		buf := make([]byte, 128)
		n, e := c.Read(buf)
		return e == nil && string(buf[:n]) == payload
	}
	ready := time.Now().Add(3 * time.Second)
	for !echo("before-failure") {
		if time.Now().After(ready) {
			t.Fatal("initial path failed")
		}
	}
	// Refresh healthy authority with a new generation. Same source tuple must
	// continue working; then silently drop both business responses and ACKs.
	ss.Policy.PolicyGeneration = strings.Repeat("f", 64)
	a.PolicyGeneration = ss.Policy.PolicyGeneration
	b.PolicyGeneration = ss.Policy.PolicyGeneration
	a.NonceHash = strings.Repeat("e", 64)
	b.NonceHash = strings.Repeat("f", 64)
	if err := store.ReplaceCandidates(ss.Policy, a, []ProviderLease{a, b}); err != nil {
		t.Fatal(err)
	}
	if !echo("after-rotation") {
		t.Fatal("generation rotation interrupted healthy flow")
	}
	first.silent.Store(true)
	start := time.Now()
	deadline := start.Add(20 * time.Second)
	for !echo("after-failure") {
		if time.Now().After(deadline) {
			t.Fatal("silent path never recovered on same listener and client tuple")
		}
	}
	st.mu.Lock()
	failures := st.status.HAFailures
	st.mu.Unlock()
	if failures == 0 {
		t.Fatal("recovery lacks diagnostic event")
	}
	t.Logf("UDP silent-path recovery=%s; original listener=%s", time.Since(start), local.LocalAddr())
}

func TestHACandidateRevokeDuringDialClosesResult(t *testing.T) {
	s, p, _ := haStore(t)
	a, b := net.Pipe()
	defer b.Close()
	_, _, err := dialCandidateStream(context.Background(), s, "example.test", 443, time.Second, func(_ ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
		s.Revoke(p.PolicyGeneration)
		return a, nil
	})
	if err == nil {
		t.Fatal("revoked handshake published")
	}
	b.SetReadDeadline(time.Now().Add(time.Second))
	buf := make([]byte, 1)
	if _, err := b.Read(buf); !errors.Is(err, io.EOF) {
		t.Fatalf("revoked connection not closed: %v", err)
	}
}
