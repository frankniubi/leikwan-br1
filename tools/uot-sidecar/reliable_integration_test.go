package main

import (
	"encoding/json"
	"sync"
	"testing"
	"time"
)

func TestReliableConcurrentAnnouncePreservesOneSession(t *testing.T) {
	echo := startEchoServer(t)
	server, _ := fixtureServer(t, echo, func(c *sidecarConfig) { c.sessionGraceSeconds = 10 })
	sid := randomSession(t)
	const count = 32
	start := make(chan struct{})
	results := make(chan *uotSession, count)
	var wg sync.WaitGroup
	for i := 0; i < count; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			<-start
			snapshot := server.config.snapshot()
			s, _, err := server.registry.announceMode(snapshot, sid, snapshot.forwards[170], echo.address(), 1200, reliableDatagramMode, true)
			if err != nil {
				t.Errorf("announce: %v", err)
			}
			results <- s
		}()
	}
	close(start)
	wg.Wait()
	close(results)
	var first *uotSession
	for s := range results {
		if s == nil {
			t.Fatal("nil session")
		}
		if first == nil {
			first = s
		}
		if s != first {
			t.Fatal("concurrent HELLO created independent sequence state")
		}
	}
}

func TestReliablePendingReattachIsSuperseded(t *testing.T) {
	echo := startEchoServer(t)
	server, address := fixtureServer(t, echo, func(c *sidecarConfig) { c.sessionGraceSeconds = 10 })
	sid := randomSession(t)
	snapshot := server.config.snapshot()
	s, _, err := server.registry.announceMode(snapshot, sid, snapshot.forwards[170], echo.address(), 1200, reliableDatagramMode, true)
	if err != nil {
		t.Fatal(err)
	}
	// Hold the carrier runner so both HELLO handlers queue behind it.
	s.carrierRunMu.Lock()
	locked := true
	defer func() {
		if locked {
			s.carrierRunMu.Unlock()
		}
	}()
	hello := reliableHelloFor(t, echo.address())
	first := dialCarrier(t, address, sid)
	first.send(t, msgHello, 0, hello)
	deadline := time.Now().Add(time.Second)
	for s.currentCarrier() == nil && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if s.currentCarrier() == nil {
		t.Fatal("pending carrier not published")
	}
	second := dialCarrier(t, address, sid)
	second.send(t, msgHello, 0, hello)
	if _, err := first.read(t, time.Second); err == nil || isTimeout(err) {
		t.Fatalf("superseded pending carrier not closed: %v", err)
	}
	s.carrierRunMu.Unlock()
	locked = false
	if ack := decodeAck(t, second.mustRead(t, time.Second).payload); ack["ok"] != true {
		t.Fatal(ack)
	}
	second.send(t, msgData, 1, []byte("Ealive"))
	for i := 0; i < 5; i++ {
		f := second.mustRead(t, time.Second)
		if f.msgType == msgUDPData && string(f.payload) == "alive" {
			return
		}
	}
	t.Fatal("latest carrier did not deliver")
}

func reliableHelloFor(t *testing.T, target string) []byte {
	raw := decodeAck(t, helloPayloadFor(t, "fixture-secret", 170, target, 1200))
	raw["delivery_mode"] = reliableDatagramMode
	b, e := json.Marshal(raw)
	if e != nil {
		t.Fatal(e)
	}
	return b
}
func TestReliableSidecarReconnectReplaysAndDeduplicates(t *testing.T) {
	echo := startEchoServer(t)
	_, address := fixtureServer(t, echo, func(c *sidecarConfig) { c.sessionGraceSeconds = 10 })
	sid := randomSession(t)
	first := dialCarrier(t, address, sid)
	hello := reliableHelloFor(t, echo.address())
	first.send(t, msgHello, 0, hello)
	if ack := decodeAck(t, first.mustRead(t, time.Second).payload); ack["delivery_mode"] != reliableDatagramMode {
		t.Fatal(ack)
	}
	first.send(t, msgData, 1, []byte("Eone"))
	gotData, gotAck := false, false
	for i := 0; i < 5 && (!gotData || !gotAck); i++ {
		f := first.mustRead(t, time.Second)
		if f.msgType == reliableACKType && f.seq == 1 {
			gotAck = true
		}
		if f.msgType == msgUDPData && f.seq == 1 && string(f.payload) == "one" {
			gotData = true
		}
	}
	if !gotData || !gotAck {
		t.Fatal("missing data or cumulative ACK")
	}
	first.conn.Close() // Deliberately lose the downstream ACK.
	second := dialCarrier(t, address, sid)
	second.send(t, msgHello, 0, hello)
	if ack := decodeAck(t, second.mustRead(t, time.Second).payload); ack["session"] != "OKR" {
		t.Fatal(ack)
	}
	second.send(t, msgData, 1, []byte("Eduplicate-must-not-reach-target"))
	second.send(t, msgData, 2, []byte("Etwo"))
	seen := map[uint64]string{}
	for i := 0; i < 12 && len(seen) < 2; i++ {
		f := second.mustRead(t, time.Second)
		if f.msgType == msgUDPData {
			seen[f.seq] = string(f.payload)
		}
	}
	if seen[1] != "one" || seen[2] != "two" {
		t.Fatalf("replay=%v", seen)
	}
	second.send(t, reliableACKType, 2, make([]byte, 8))
	if echo.receivedCount() != 2 {
		t.Fatalf("duplicate delivery: %d", echo.receivedCount())
	}
	ports := echo.peerPorts()
	if ports[0] != ports[1] {
		t.Fatal("reattach replaced target socket")
	}
}

func TestReliableModeCannotReattachAsRaw(t *testing.T) {
	echo := startEchoServer(t)
	_, address := fixtureServer(t, echo, nil)
	sid := randomSession(t)
	c := dialCarrier(t, address, sid)
	c.send(t, msgHello, 0, reliableHelloFor(t, echo.address()))
	c.mustRead(t, time.Second)
	raw := dialCarrier(t, address, sid)
	raw.send(t, msgHello, 0, helloPayloadFor(t, "fixture-secret", 170, echo.address(), 1200))
	if ack := decodeAck(t, raw.mustRead(t, time.Second).payload); ack["ok"] != false {
		t.Fatalf("downgrade accepted: %v", ack)
	}
}
