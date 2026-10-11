package main

import (
	"context"
	"net"
	"syscall"
	"testing"
	"time"
)

// tcpPairWithBuffers is tcpPair with the socket buffers fixed before the
// connection exists: send on the dialed end, receive on the accepted end (the
// listener's buffer is inherited by the sockets it accepts).
//
// Tests that need a relay to block quickly must size buffers this way, never
// with SetReadBuffer/SetWriteBuffer on a connected socket. Linux (and macOS)
// size the receive window from the buffer at handshake time and never shrink a
// window they already advertised, so a receive buffer shrunk after connect
// accepts more than it can hold, prunes it and lets the sender recover only by
// retransmission (RTO >= 200 ms, backing off). A writer then sees progress
// arrive in RTO-spaced bursts: a reading peer looks stalled for any bound
// under ~200 ms, and a peer that stopped reading keeps trickling bytes in for
// seconds. Windows loopback does not behave this way, which is why such tests
// passed there and failed on Linux and macOS runners.
//
// The returned connections have no deadlines.
func tcpPairWithBuffers(t *testing.T, send, receive int) (dialed, accepted *net.TCPConn) {
	t.Helper()
	control := func(receive, send int) func(string, string, syscall.RawConn) error {
		return func(_, _ string, raw syscall.RawConn) error {
			var optionErr error
			if err := raw.Control(func(fd uintptr) { optionErr = setRawSocketBuffers(fd, receive, send) }); err != nil {
				return err
			}
			return optionErr
		}
	}
	listenConfig := net.ListenConfig{Control: control(receive, 0)}
	listener, err := listenConfig.Listen(context.Background(), "tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	dialer := net.Dialer{Control: control(0, send), Timeout: 3 * time.Second}
	connection, err := dialer.Dial("tcp4", listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	peer, err := listener.Accept()
	if err != nil {
		connection.Close()
		t.Fatal(err)
	}
	dialed, accepted = connection.(*net.TCPConn), peer.(*net.TCPConn)
	t.Cleanup(func() { dialed.Close(); accepted.Close() })
	return dialed, accepted
}
