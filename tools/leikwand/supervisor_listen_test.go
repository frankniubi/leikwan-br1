package main

import (
	"net"
	"os"
	"path/filepath"
	"testing"
)

const procTCPHeader = "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n"

// Port 10001 = 2711, 60625 = ECD1, 60626 = ECD2.
const fixtureTCP = procTCPHeader +
	"   0: 00000000:2711 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 4101 1 0000000000000000 100 0 0 10 0\n" +
	"   1: 0100007F:ECD2 0100007F:9C40 01 00000000:00000000 00:00000000 00000000     0        0 4102 1 0000000000000000 20 4 30 10 -1\n"

const fixtureTCP6 = procTCPHeader +
	"   0: 00000000000000000000000000000000:7F4E 00000000000000000000000000000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 4201 1 0000000000000000 100 0 0 10 0\n"

const fixtureUDP = "   sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode ref pointer drops\n" +
	"  10: 00000000:ECD1 00000000:0000 07 00000000:00000000 00:00000000 00000000     0        0 4301 2 0000000000000000 0\n"

func withProcNet(t *testing.T, tables map[string]string) {
	t.Helper()
	dir := t.TempDir()
	for name, body := range tables {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(body), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	previous := procNetDir
	procNetDir = dir
	t.Cleanup(func() { procNetDir = previous })
}

func TestPortIsListeningReadsSocketTablesWithoutConnecting(t *testing.T) {
	withProcNet(t, map[string]string{"tcp": fixtureTCP, "tcp6": fixtureTCP6, "udp": fixtureUDP, "udp6": ""})

	cases := []struct {
		name      string
		port      int
		transport string
		want      bool
	}{
		{"tcp listener", 10001, "tcp+udp", true},
		{"tcp-only forward", 10001, "tcp", true},
		{"dual-stack listener in tcp6", 32590, "tcp", true},
		{"established socket is not a listener", 60626, "tcp", false},
		{"udp-only forward is up on its bound udp socket", 60625, "udp", true},
		{"udp-only forward ignores tcp tables", 10001, "udp", false},
		{"tcp forward ignores a udp socket", 60625, "tcp+udp", false},
		{"nothing on the port", 443, "tcp+udp", false},
	}
	for _, tc := range cases {
		if got := portIsListening(tc.port, tc.transport); got != tc.want {
			t.Errorf("%s: portIsListening(%d, %q) = %v, want %v", tc.name, tc.port, tc.transport, got, tc.want)
		}
	}
}

// The health probe used to be a TCP connect, which an Ultra runtime turns into
// a relay stream. With readable socket tables nothing may connect at all.
func TestPortIsListeningNeverConnectsWhenTablesAreReadable(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	accepted := make(chan struct{}, 1)
	go func() {
		if conn, err := listener.Accept(); err == nil {
			conn.Close()
			accepted <- struct{}{}
		}
	}()
	withProcNet(t, map[string]string{"tcp": procTCPHeader, "tcp6": procTCPHeader})

	port := listener.Addr().(*net.TCPAddr).Port
	if portIsListening(port, "tcp+udp") {
		t.Fatalf("port %d is absent from the socket tables but was reported listening", port)
	}
	select {
	case <-accepted:
		t.Fatal("portIsListening connected to the listener")
	default:
	}
}

func TestPortIsListeningFallsBackToConnectWithoutSocketTables(t *testing.T) {
	withProcNet(t, map[string]string{})
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	go func() {
		for {
			conn, err := listener.Accept()
			if err != nil {
				return
			}
			conn.Close()
		}
	}()
	port := listener.Addr().(*net.TCPAddr).Port
	if !portIsListening(port, "tcp") {
		t.Fatalf("fallback connect did not see the listener on %d", port)
	}
	if portIsListening(port, "udp") {
		t.Fatal("a udp-only forward cannot be probed by connect and must not be reported up")
	}
}

func TestKernelPortHeldMatchesInode(t *testing.T) {
	withProcNet(t, map[string]string{"tcp": fixtureTCP, "udp": fixtureUDP})
	if held, ok := kernelPortHeld(10001, "tcp", "4101"); !ok || !held {
		t.Fatalf("listener inode 4101 on 10001: held=%v ok=%v", held, ok)
	}
	if held, _ := kernelPortHeld(10001, "tcp", "9999"); held {
		t.Fatal("another inode must not own the listener")
	}
	if held, _ := kernelPortHeld(60625, "udp", "4301"); !held {
		t.Fatal("udp socket inode 4301 should own 60625")
	}
}

func TestPortIsListeningOnTheRealKernelTables(t *testing.T) {
	if _, err := os.Stat("/proc/net/tcp"); err != nil {
		t.Skip("no /proc/net socket tables on this platform")
	}
	tcp, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer tcp.Close()
	udp, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer udp.Close()
	tcpPort := tcp.Addr().(*net.TCPAddr).Port
	udpPort := udp.LocalAddr().(*net.UDPAddr).Port
	if !portIsListening(tcpPort, "tcp+udp") {
		t.Errorf("tcp listener on %d not found in /proc/net", tcpPort)
	}
	if !portIsListening(udpPort, "udp") {
		t.Errorf("udp socket on %d not found in /proc/net", udpPort)
	}
	if !processListensOn(itoa(os.Getpid()), udpPort, "udp") {
		t.Errorf("this process should own udp port %d", udpPort)
	}
	if portOwnerKind(tcpPort, "tcp") != "foreign" {
		t.Errorf("a test binary is not a managed runtime; port %d should be foreign", tcpPort)
	}
}
