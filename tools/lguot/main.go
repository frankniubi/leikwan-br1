// lguot — a CPU-efficient UDP-over-TCP transport for the leigod slave<->sidecar WireGuard hop.
//
// It replaces gost's role on the leigod arm: WG UDP is framed over a single TCP stream that
// rides the LeiGod line's SOCKS5 (CONNECT) to the sidecar. The win over gost is batched
// syscalls (recvmmsg/sendmmsg via x/net/ipv4) + a lean, allocation-free hot loop, so a 2-vCPU
// slave can push toward its ~200 Mbit/s network cap instead of saturating on per-datagram
// userspace work.
//
//   client (slave):   WG --udp--> 127.0.0.1:LP  --[frame]--> TCP --(SOCKS5 line)--> sidecar:PORT
//   server (sidecar): TCP :PORT --[deframe]--> udp --> 127.0.0.1:<WG listen>
//
// Wire: a stream of [uint16 big-endian length][payload] datagrams. Up to `batch` datagrams are
// read per recvmmsg and flushed as a single TCP write (amortised syscalls, coalesced without Nagle).
package main

import (
	"bufio"
	"encoding/binary"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"os"
	"strconv"
	"sync"
	"sync/atomic"

	"golang.org/x/net/ipv4"
)

const (
	batch   = 64
	bufSize = 2048 // > max WG datagram (MTU 1500 + WG overhead)
	tcpBuf  = 1 << 20
	udpBuf  = 16 << 20
)

func fatal(err error) {
	fmt.Fprintln(os.Stderr, "lguot:", err)
	os.Exit(1)
}

func makeMsgs(n int) []ipv4.Message {
	ms := make([]ipv4.Message, n)
	for i := range ms {
		ms[i].Buffers = [][]byte{make([]byte, bufSize)}
	}
	return ms
}

// udpToTCP reads batches of datagrams from pc, length-prefixes each, and flushes per batch.
// If learn != nil, the first observed source address is stored (client learns the WG socket addr).
func udpToTCP(pc *ipv4.PacketConn, w *bufio.Writer, learn *atomic.Value) error {
	ms := makeMsgs(batch)
	hdr := make([]byte, 2)
	var last *net.UDPAddr
	for {
		n, err := pc.ReadBatch(ms, 0)
		if err != nil {
			return err
		}
		for i := 0; i < n; i++ {
			// Follow the WireGuard source address rather than latching the first one. WG's socket
			// port changes whenever the interface restarts or roams (carrier swap, agent reconcile,
			// rekey). Latching would leave us replying to a stale port forever -> 100% loss after any
			// WG source-port change. Update on change only (cheap; WG is the only sender here).
			if learn != nil && ms[i].Addr != nil {
				if ua, ok := ms[i].Addr.(*net.UDPAddr); ok {
					if last == nil || last.Port != ua.Port || !last.IP.Equal(ua.IP) {
						learn.Store(ua)
						last = ua
					}
				}
			}
			l := ms[i].N
			binary.BigEndian.PutUint16(hdr, uint16(l))
			if _, err := w.Write(hdr); err != nil {
				return err
			}
			if _, err := w.Write(ms[i].Buffers[0][:l]); err != nil {
				return err
			}
		}
		if err := w.Flush(); err != nil {
			return err
		}
	}
}

// tcpToUDP reads framed datagrams from r and sendmmsg's them to the address from getDst().
// getDst returns nil until the peer address is known (client side, before WG is learned).
func tcpToUDP(r *bufio.Reader, pc *ipv4.PacketConn, getDst func() *net.UDPAddr) error {
	ms := makeMsgs(batch)
	hdr := make([]byte, 2)
	dropLogged := false
	for {
		k := 0
		for k < batch {
			if _, err := io.ReadFull(r, hdr); err != nil {
				return err
			}
			l := int(binary.BigEndian.Uint16(hdr))
			if l > bufSize {
				return errors.New("frame too large")
			}
			if _, err := io.ReadFull(r, ms[k].Buffers[0][:l]); err != nil {
				return err
			}
			dst := getDst()
			if dst != nil {
				ms[k].Buffers[0] = ms[k].Buffers[0][:l]
				ms[k].Addr = dst
				k++
			} else if !dropLogged {
				// Observability: a sidecar->slave frame arrived before we learned the WG source
				// addr (no slave->sidecar packet seen yet). Turn the otherwise-silent black-hole
				// into a grep-able symptom. The carrier-swap handover forces a slave-first
				// handshake so this should be rare/transient.
				fmt.Fprintln(os.Stderr, "lguot: dropping return frame, WG source addr not learned yet")
				dropLogged = true
			}
			if r.Buffered() == 0 {
				break
			}
		}
		off := 0
		for off < k {
			m, err := pc.WriteBatch(ms[off:k], 0)
			if err != nil {
				return err
			}
			if m <= 0 {
				break
			}
			off += m
		}
		for i := 0; i < k; i++ {
			ms[i].Buffers[0] = ms[i].Buffers[0][:bufSize]
		}
	}
}

func tune(uc *net.UDPConn) *ipv4.PacketConn {
	_ = uc.SetReadBuffer(udpBuf)
	_ = uc.SetWriteBuffer(udpBuf)
	return ipv4.NewPacketConn(uc)
}

// writeFrame writes a single length-prefixed message (used for the auth-token handshake).
func writeFrame(w *bufio.Writer, b []byte) error {
	hdr := make([]byte, 2)
	binary.BigEndian.PutUint16(hdr, uint16(len(b)))
	if _, err := w.Write(hdr); err != nil {
		return err
	}
	if _, err := w.Write(b); err != nil {
		return err
	}
	return w.Flush()
}

// readFrame reads a single length-prefixed message.
func readFrame(r *bufio.Reader) ([]byte, error) {
	hdr := make([]byte, 2)
	if _, err := io.ReadFull(r, hdr); err != nil {
		return nil, err
	}
	l := int(binary.BigEndian.Uint16(hdr))
	if l > bufSize {
		return nil, errors.New("frame too large")
	}
	b := make([]byte, l)
	if _, err := io.ReadFull(r, b); err != nil {
		return nil, err
	}
	return b, nil
}

// pump runs the bidirectional UDP<->TCP relay. If token != "", a shared-secret handshake is
// performed first (client sends the token as the first frame; server validates) so the public
// server port is not an open UDP injector. The token rides the same [len][payload] framing as data.
func pump(c net.Conn, pc *ipv4.PacketConn, learn *atomic.Value, getDst func() *net.UDPAddr, token string, server bool) error {
	if tc, ok := c.(*net.TCPConn); ok {
		_ = tc.SetNoDelay(true)
	}
	r := bufio.NewReaderSize(c, tcpBuf)
	w := bufio.NewWriterSize(c, tcpBuf)
	if token != "" {
		if server {
			got, err := readFrame(r)
			if err != nil {
				return err
			}
			if string(got) != token {
				return errors.New("token mismatch")
			}
		} else if err := writeFrame(w, []byte(token)); err != nil {
			return err
		}
	}
	var wg sync.WaitGroup
	wg.Add(2)
	go func() { defer wg.Done(); _ = udpToTCP(pc, w, learn); c.Close() }()
	go func() { defer wg.Done(); _ = tcpToUDP(r, pc, getDst); c.Close() }()
	wg.Wait()
	return nil
}

func runServer(listen, wg, token string) {
	wgAddr, err := net.ResolveUDPAddr("udp", wg)
	if err != nil {
		fatal(err)
	}
	ln, err := net.Listen("tcp", listen)
	if err != nil {
		fatal(err)
	}
	fmt.Printf("lguot server listen=%s -> wg=%s\n", listen, wg)
	for {
		c, err := ln.Accept()
		if err != nil {
			fatal(err)
		}
		go func(c net.Conn) {
			defer c.Close()
			uc, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4zero, Port: 0})
			if err != nil {
				return
			}
			defer uc.Close()
			pc := tune(uc)
			_ = pump(c, pc, nil, func() *net.UDPAddr { return wgAddr }, token, true)
		}(c)
	}
}

func runClient(listen, socks, target, token string) {
	la, err := net.ResolveUDPAddr("udp", listen)
	if err != nil {
		fatal(err)
	}
	uc, err := net.ListenUDP("udp", la)
	if err != nil {
		fatal(err)
	}
	defer uc.Close()
	pc := tune(uc)
	host, ports, err := net.SplitHostPort(target)
	if err != nil {
		fatal(err)
	}
	port, err := strconv.Atoi(ports)
	if err != nil {
		fatal(err)
	}
	var c net.Conn
	if socks == "" {
		c, err = net.Dial("tcp", target) // direct (testing / non-line)
	} else {
		c, err = socks5Connect(socks, host, port)
	}
	if err != nil {
		fatal(err)
	}
	defer c.Close()
	fmt.Printf("lguot client udp=%s socks=%s target=%s\n", listen, socks, target)
	var wgv atomic.Value
	_ = pump(c, pc, &wgv, func() *net.UDPAddr {
		if v := wgv.Load(); v != nil {
			return v.(*net.UDPAddr)
		}
		return nil
	}, token, false)
}

// socks5Connect performs a no-auth SOCKS5 CONNECT through proxyAddr to host:port.
func socks5Connect(proxyAddr, host string, port int) (net.Conn, error) {
	c, err := net.Dial("tcp", proxyAddr)
	if err != nil {
		return nil, err
	}
	if _, err := c.Write([]byte{0x05, 0x01, 0x00}); err != nil {
		c.Close()
		return nil, err
	}
	rep := make([]byte, 2)
	if _, err := io.ReadFull(c, rep); err != nil {
		c.Close()
		return nil, err
	}
	if rep[0] != 0x05 || rep[1] != 0x00 {
		c.Close()
		return nil, fmt.Errorf("socks method reject: %v", rep)
	}
	var req []byte
	if ip4 := net.ParseIP(host).To4(); ip4 != nil {
		req = []byte{0x05, 0x01, 0x00, 0x01, ip4[0], ip4[1], ip4[2], ip4[3], byte(port >> 8), byte(port)}
	} else {
		req = append([]byte{0x05, 0x01, 0x00, 0x03, byte(len(host))}, host...)
		req = append(req, byte(port>>8), byte(port))
	}
	if _, err := c.Write(req); err != nil {
		c.Close()
		return nil, err
	}
	head := make([]byte, 4)
	if _, err := io.ReadFull(c, head); err != nil {
		c.Close()
		return nil, err
	}
	if head[1] != 0x00 {
		c.Close()
		return nil, fmt.Errorf("socks connect failed rep=%d", head[1])
	}
	var alen int
	switch head[3] {
	case 0x01:
		alen = 4
	case 0x04:
		alen = 16
	case 0x03:
		lb := make([]byte, 1)
		if _, err := io.ReadFull(c, lb); err != nil {
			c.Close()
			return nil, err
		}
		alen = int(lb[0])
	default:
		c.Close()
		return nil, errors.New("socks bad atyp")
	}
	if _, err := io.ReadFull(c, make([]byte, alen+2)); err != nil {
		c.Close()
		return nil, err
	}
	return c, nil
}

func main() {
	if len(os.Args) < 2 {
		fmt.Println("usage: lguot server -listen :PORT -wg 127.0.0.1:WGPORT")
		fmt.Println("       lguot client -listen 127.0.0.1:LP -socks 127.0.0.1:20453 -target HOST:PORT")
		os.Exit(1)
	}
	mode := os.Args[1]
	fs := flag.NewFlagSet(mode, flag.ExitOnError)
	switch mode {
	case "server":
		listen := fs.String("listen", ":0", "TCP listen address")
		wg := fs.String("wg", "127.0.0.1:0", "local WireGuard UDP address")
		token := fs.String("token", "", "shared-secret auth token (empty = none)")
		_ = fs.Parse(os.Args[2:])
		runServer(*listen, *wg, *token)
	case "client":
		listen := fs.String("listen", "127.0.0.1:0", "local UDP listen (WG endpoint)")
		socks := fs.String("socks", "", "SOCKS5 proxy (LeiGod line)")
		target := fs.String("target", "", "sidecar host:port")
		token := fs.String("token", "", "shared-secret auth token (empty = none)")
		_ = fs.Parse(os.Args[2:])
		runClient(*listen, *socks, *target, *token)
	default:
		fatal(fmt.Errorf("unknown mode %q", mode))
	}
}
