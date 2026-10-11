package main

import (
	"context"
	"crypto/tls"
	"errors"
	"io"
	"net"
	"sync"
	"time"
)

// errProbeRelayClosedEmpty is the one probe-reply outcome that counts against a
// relay: it took the first flight and closed cleanly without a byte or the
// explicit target-refused frame.
var errProbeRelayClosedEmpty = errors.New("relay closed the probe first flight empty")

// tcpProbeServerName matches defaultTCPProbeTarget (1.1.1.1:443).
const tcpProbeServerName = "one.one.one.one"

var (
	tcpProbeHelloOnce sync.Once
	tcpProbeHello     []byte
)

// tcpProbeClientHello is a real TLS ClientHello for tcpProbeServerName. Any
// TLS server answers it with at least an alert, so a reply proves the relay
// forwarded the first flight and the target's answer came back.
func tcpProbeClientHello() []byte {
	tcpProbeHelloOnce.Do(func() {
		capture := &helloCapture{}
		_ = tls.Client(capture, &tls.Config{ServerName: tcpProbeServerName, MinVersion: tls.VersionTLS12}).Handshake()
		tcpProbeHello = capture.written
	})
	return tcpProbeHello
}

func tcpProbeReplyWait(config Config) time.Duration {
	wait := probeTimeout(config)
	if wait < 500*time.Millisecond {
		wait = 500 * time.Millisecond
	}
	return wait
}

// checkTCPProbeReply sends the ClientHello on an established probe stream and
// reads the first reply byte. Only a clean close with nothing read returns
// errProbeRelayClosedEmpty; a reply, a timeout, a reset or an explicit relay
// refusal all keep the handshake-only verdict the probe always had.
func checkTCPProbeReply(ctx context.Context, connection net.Conn, wait time.Duration) error {
	hello := tcpProbeClientHello()
	if len(hello) == 0 {
		return nil
	}
	deadline := time.Now().Add(wait)
	if d, ok := ctx.Deadline(); ok && d.Before(deadline) {
		deadline = d
	}
	if !deadline.After(time.Now()) {
		return nil
	}
	_ = connection.SetDeadline(deadline)
	defer connection.SetDeadline(time.Time{})
	if err := writeAll(connection, hello); err != nil {
		return nil
	}
	var first [1]byte
	n, err := connection.Read(first[:])
	if n == 0 && (errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF)) {
		return errProbeRelayClosedEmpty
	}
	return nil
}

// helloCapture is a net.Conn that records the first flight crypto/tls writes and
// fails the read, so the handshake stops right after the ClientHello.
type helloCapture struct {
	written []byte
}

func (c *helloCapture) Read([]byte) (int, error) { return 0, io.EOF }
func (c *helloCapture) Write(p []byte) (int, error) {
	c.written = append(c.written, p...)
	return len(p), nil
}
func (c *helloCapture) Close() error                     { return nil }
func (c *helloCapture) LocalAddr() net.Addr              { return &net.TCPAddr{} }
func (c *helloCapture) RemoteAddr() net.Addr             { return &net.TCPAddr{} }
func (c *helloCapture) SetDeadline(time.Time) error      { return nil }
func (c *helloCapture) SetReadDeadline(time.Time) error  { return nil }
func (c *helloCapture) SetWriteDeadline(time.Time) error { return nil }
