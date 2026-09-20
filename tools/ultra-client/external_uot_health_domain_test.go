package main

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"path/filepath"
	"testing"
	"time"
)

type uotHealthXORReader struct{ io.Reader }

func (r uotHealthXORReader) Read(p []byte) (int, error) {
	n, err := r.Reader.Read(p)
	for i := 0; i < n; i++ {
		p[i] ^= 1
	}
	return n, err
}

func uotHealthAssociation(t *testing.T, store *PolicyStore) (*externalUoTAssociation, *StatusWriter) {
	t.Helper()
	config := testPolicyConfig()
	config.ExternalUoT = ExternalUoTConfig{
		Enabled: true, SidecarTarget: "192.0.2.40:7443", Token: "offline-test",
		KeepaliveSeconds: 10, FramePayloadMax: externalUoTMaxDatagramBytes,
	}
	status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Millisecond)
	t.Cleanup(status.Close)
	association, err := newExternalUoTAssociation(context.Background(), config, store, status, nil, nil, false)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(association.cancel)
	return association, status
}

func TestExternalUoTHelloDoesNotHealOrScoreOrdinaryTCP(t *testing.T) {
	store, policy, leases := haStore(t)
	key := haPathKey(leases[0])
	store.FailSilentPath("tcp", key, policy.PolicyGeneration)
	store.FailSilentPath("tcp", key, policy.PolicyGeneration)
	store.FailCandidate(externalUoTHealthDomain, leases[0])
	association, status := uotHealthAssociation(t, store)
	client, sidecar := net.Pipe()
	defer client.Close()
	defer sidecar.Close()
	serverDone := make(chan error, 1)
	go func() {
		hello, err := readExternalUoTFrame(bufio.NewReader(uotHealthXORReader{sidecar}))
		if err != nil {
			serverDone <- err
			return
		}
		payload, _ := json.Marshal(externalUoTHelloAck{OK: true, FramePayloadMax: externalUoTMaxDatagramBytes})
		if err := writeExternalUoTFrame(sidecar, externalUoTFrame{
			msgType: externalUoTMsgHelloAck, session: hello.session, payload: payload,
		}); err != nil {
			serverDone <- err
			return
		}
		serverDone <- writeExternalUoTFrame(sidecar, externalUoTFrame{
			msgType: externalUoTClose, session: hello.session,
		})
	}()
	lease := leases[0]
	lease.CandidateKeyHash = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
	_ = association.runCarrier(client, lease)
	association.cancel()
	if err := <-serverDone; err != nil {
		t.Fatal(err)
	}
	if !store.businessPathIsIsolated("tcp", key) {
		t.Error("UoT HELLO cleared ordinary TCP business-failure isolation")
	}
	store.mu.RLock()
	uotStrikes := store.pathFailures[externalUoTHealthDomain+":"+key]
	store.mu.RUnlock()
	if uotStrikes != 1 {
		t.Errorf("successful HELLO did not clear old carrier failures before its later CLOSE: %d", uotStrikes)
	}
	status.mu.Lock()
	defer status.mu.Unlock()
	if len(status.status.CandidateFeedback) != 0 {
		t.Errorf("UoT HELLO produced global candidate feedback: %+v", status.status.CandidateFeedback)
	}
	if status.status.LatestTCP != nil {
		t.Error("UoT HELLO overwrote ordinary TCP selected-path status")
	}
}

func TestExternalUoTDialFailureSwitchesWithoutChangingTCPOrUDP(t *testing.T) {
	store, _, leases := haStore(t)
	association, _ := uotHealthAssociation(t, store)
	var attempts []string
	conn, selected, err := association.dialCarrier(func(lease ProviderLease, host string, port int, _ time.Duration) (net.Conn, error) {
		if host != "192.0.2.40" || port != 7443 {
			t.Errorf("carrier dial target changed: %s:%d", host, port)
		}
		attempts = append(attempts, lease.IP)
		if lease.IP == leases[0].IP {
			return nil, errors.New("synthetic sidecar connect failure")
		}
		local, peer := net.Pipe()
		peer.Close()
		return local, nil
	})
	if err != nil {
		t.Fatal(err)
	}
	conn.Close()
	defer selected.Lease.wipe()
	if len(attempts) != 2 || selected.Lease.IP != leases[1].IP {
		t.Fatalf("carrier did not switch to sibling: attempts=%v selected=%s", attempts, selected.Lease.IP)
	}
	for domain, want := range map[string]int{"tcp": 2, "udp": 2, externalUoTHealthDomain: 1} {
		snapshots := store.CandidateSnapshots(domain)
		got := len(snapshots)
		wipeSnapshots(snapshots)
		if got != want {
			t.Errorf("%s candidates=%d, want %d", domain, got, want)
		}
	}
	conn, tcp, err := dialCandidateStream(context.Background(), store, "198.51.100.20", 443, time.Second,
		func(lease ProviderLease, _ string, _ int, _ time.Duration) (net.Conn, error) {
			local, peer := net.Pipe()
			peer.Close()
			return local, nil
		})
	if err != nil {
		t.Fatal(err)
	}
	conn.Close()
	defer tcp.Lease.wipe()
	if tcp.Lease.IP != leases[0].IP {
		t.Fatal("sidecar failure changed ordinary TCP path choice")
	}
}

func TestExternalUoTHelloFailureIsolatesCarrierAndExpires(t *testing.T) {
	store, _, leases := haStore(t)
	association, _ := uotHealthAssociation(t, store)
	client, sidecar := net.Pipe()
	defer client.Close()
	defer sidecar.Close()
	serverDone := make(chan error, 1)
	go func() {
		hello, err := readExternalUoTFrame(bufio.NewReader(uotHealthXORReader{sidecar}))
		if err != nil {
			serverDone <- err
			return
		}
		payload, _ := json.Marshal(externalUoTHelloAck{OK: false, Error: "synthetic rejection"})
		serverDone <- writeExternalUoTFrame(sidecar, externalUoTFrame{
			msgType: externalUoTMsgHelloAck, session: hello.session, payload: payload,
		})
	}()
	if err := association.runCarrier(client, leases[0]); err == nil {
		t.Fatal("rejected HELLO was accepted")
	}
	if err := <-serverDone; err != nil {
		t.Fatal(err)
	}
	candidates := store.CandidateSnapshots(externalUoTHealthDomain)
	if len(candidates) != 1 || candidates[0].Lease.IP != leases[1].IP {
		wipeSnapshots(candidates)
		t.Fatal("HELLO rejection did not leave the UoT sibling available")
	}
	wipeSnapshots(candidates)
	store.mu.Lock()
	store.isolated[externalUoTHealthDomain+":"+haPathKey(leases[0])] = time.Now().Add(-time.Second)
	store.mu.Unlock()
	candidates = store.CandidateSnapshots(externalUoTHealthDomain)
	defer wipeSnapshots(candidates)
	if len(candidates) != 2 {
		t.Fatal("expired carrier isolation prevented recovery")
	}
	if tcp := store.CandidateSnapshots("tcp"); len(tcp) != 2 {
		wipeSnapshots(tcp)
		t.Fatal("HELLO rejection isolated ordinary TCP")
	} else {
		wipeSnapshots(tcp)
	}
}

func TestExternalUoTOutageAndCancellationKeepTCPFailureState(t *testing.T) {
	store, policy, leases := haStore(t)
	key := haPathKey(leases[0])
	store.FailSilentPath("tcp", key, policy.PolicyGeneration)
	for _, lease := range leases {
		store.FailCandidate(externalUoTHealthDomain, lease)
	}
	store.mu.RLock()
	tcpStrikes := store.pathFailures["tcp:"+key]
	store.mu.RUnlock()
	if tcpStrikes != 1 {
		t.Fatalf("all-carrier outage changed ordinary TCP failure streak: %d", tcpStrikes)
	}
	association, _ := uotHealthAssociation(t, store)
	association.cancel()
	conn, selected, err := association.dialCarrier(func(ProviderLease, string, int, time.Duration) (net.Conn, error) {
		t.Error("canceled carrier dial was attempted")
		return nil, errors.New("should not dial")
	})
	if conn != nil {
		conn.Close()
		t.Error("canceled carrier returned a connection")
	}
	selected.Lease.wipe()
	// The helper may report an unavailable pool if already canceled before
	// any candidate remains; either way it must not start a dial or alter TCP.
	if err == nil {
		t.Error("canceled carrier unexpectedly succeeded")
	}
}
