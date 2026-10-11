package main

import (
	"io"
	"net"
	"testing"
	"time"
)

func queueFixture() *uotSession {
	config := &sidecarConfig{queuePackets: 1, framePayloadMax: maxDatagram}
	server := newSidecarServer(newConfigStore("", config), newSidecarLogger("ERROR", io.Discard))
	return newUoTSession(config, server, [16]byte{}, forwardBinding{}, "127.0.0.1:1", maxDatagram)
}
func TestSidecarBlockedEnqueueShutdown(t *testing.T) {
	s := queueFixture()
	if !s.enqueueUDP([]byte("one")) {
		t.Fatal("initial admission")
	}
	done := make(chan bool)
	go func() { done <- s.enqueueUDP([]byte("two")) }()
	time.Sleep(10 * time.Millisecond)
	s.close()
	select {
	case ok := <-done:
		if ok {
			t.Fatal("admitted after shutdown")
		}
	case <-time.After(time.Second):
		t.Fatal("shutdown blocked")
	}
	if s.budget.bytes() != 3 {
		t.Fatal("admission reservation leak")
	}
}
func TestSidecarQueueTimeoutCountersMonotonic(t *testing.T) {
	s := queueFixture()
	defer s.close()
	if !s.enqueueUDP([]byte("one")) {
		t.Fatal("initial admission")
	}
	for i := uint64(1); i <= 2; i++ {
		if s.enqueueUDP([]byte("two")) {
			t.Fatal("admitted to full queue")
		}
		v := s.snapshotStats()
		if v.queueTimeout != i || v.queueBlocked != i || v.queueDrops != i {
			t.Fatalf("%+v", v)
		}
	}
}

func TestReliableFatalEnqueueStopsUDPReader(t *testing.T) {
	s := queueFixture()
	s.reliable = newReliableDatagrams()
	s.framePayloadMax = 1
	conn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	s.udpConn = conn
	go s.udpReader()
	defer s.close()
	sender, err := net.Dial("udp4", conn.LocalAddr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer sender.Close()
	if _, err := sender.Write([]byte("oversize")); err != nil {
		t.Fatal(err)
	}
	select {
	case <-s.udpDone:
		if !s.isClosed() || s.ctx.Err() == nil {
			t.Fatal("fatal reliable reader did not close its session")
		}
	case <-time.After(time.Second):
		t.Fatal("fatal reader continued after admission failure")
	}
}
