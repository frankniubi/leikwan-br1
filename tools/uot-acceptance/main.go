// Owned-target, bounded reverse-throughput test. No public speedtest services.
package main

import (
	"bufio"
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"time"
)

var (
	server    = flag.Bool("server", false, "run bounded authenticated TCP/UDP target")
	target    = flag.String("target", "185.220.238.143:60041", "owned target")
	listen    = flag.String("listen", ":60041", "target bind")
	proxy     = flag.String("proxy", "127.0.0.1:10001", "SS none address-first listener")
	tokenFile = flag.String("token-file", "", "local random test authorization token file")
	out       = flag.String("out", "summary.json", "output JSON")
	duration  = flag.Int("duration", 720, "measurement seconds")
	workers   = flag.Int("workers", 128, "TCP workers")
	bps       = flag.Int("bps", 20000000, "downstream application bit/s per protocol")
	ttl       = flag.Int("ttl", 1800, "server maximum lifetime")
)

const size = 1200
const bodySize = 131072

var body = bytes.Repeat([]byte("enterprise-uot-controlled-target-"), bodySize/32)

type result struct {
	UDPRunID      string  `json:"udp_run_id"`
	UDPServerSent *uint64 `json:"udp_server_sent"`
	mu            sync.Mutex
	Started       time.Time      `json:"started"`
	Finished      time.Time      `json:"finished"`
	Platform      string         `json:"platform"`
	Duration      int            `json:"duration_s"`
	BPS           int            `json:"requested_bps_per_protocol"`
	Workers       int            `json:"tcp_workers"`
	TCPBytes      int64          `json:"tcp_bytes"`
	TCPWithin     int64          `json:"tcp_bytes_within_window"`
	TCPOK         int            `json:"tcp_ok"`
	TCPFailures   map[string]int `json:"tcp_failures"`
	TCPBPS        float64        `json:"tcp_received_bps"`
	UDPExpected   int            `json:"udp_expected"`
	UDPReceived   int            `json:"udp_unique_received"`
	UDPDuplicates int            `json:"udp_duplicates"`
	UDPInvalid    int            `json:"udp_invalid"`
	UDPWithin     int            `json:"udp_received_within_window"`
	UDPLoss       int            `json:"udp_missing"`
	UDPBPS        float64        `json:"udp_received_bps"`
	UDPErrors     []string       `json:"udp_errors"`
	// This test deliberately does not equate successful raw target delivery with a reliable tunnel.
	ReliableVerdict string           `json:"reliable_udp_verdict"`
	Events          []map[string]any `json:"tcp_events"`
}

func address(hostport string) []byte {
	h, p, e := net.SplitHostPort(hostport)
	if e != nil {
		panic(e)
	}
	port, _ := strconv.Atoi(p)
	ip := net.ParseIP(h).To4()
	var a []byte
	if ip != nil {
		a = append([]byte{1}, ip...)
	} else {
		a = append([]byte{3, byte(len(h))}, []byte(h)...)
	}
	return append(a, byte(port>>8), byte(port))
}
func stripAddress(p []byte) []byte {
	if len(p) >= 3 && p[0] == 0 && p[1] == 0 && p[2] == 0 {
		p = p[3:]
	}
	if len(p) < 1 {
		return nil
	}
	n := 0
	switch p[0] {
	case 1:
		n = 7
	case 4:
		n = 19
	case 3:
		if len(p) > 1 {
			n = 4 + int(p[1])
		}
	}
	if n == 0 || len(p) < n {
		return nil
	}
	return p[n:]
}
func errorClass(stage string, e error) string {
	if n, ok := e.(net.Error); ok && n.Timeout() {
		return stage + "_timeout"
	}
	if e == io.EOF || e == io.ErrUnexpectedEOF {
		return stage + "_eof"
	}
	return stage + "_error"
}
func main() {
	flag.Parse()
	t, e := os.ReadFile(*tokenFile)
	if e != nil {
		panic(e)
	}
	token := strings.TrimSpace(string(t))
	if len(token) < 32 {
		panic("test token must contain >=32 bytes")
	}
	if *duration < 1 || *duration > 720 || *bps < 1 || *bps > 20000000 || *workers < 1 || *workers > 128 {
		panic("test bounds exceeded")
	}
	if *server {
		runServer(token)
		return
	}
	r := result{Started: time.Now().UTC(), Platform: runtime.GOOS, Duration: *duration, BPS: *bps, Workers: *workers, TCPFailures: map[string]int{}, ReliableVerdict: "NOT_TESTED"}
	end := time.Now().Add(time.Duration(*duration) * time.Second)
	var wg sync.WaitGroup
	wg.Add(1)
	go func() { defer wg.Done(); udpClient(token, end, &r) }()
	for w := 0; w < *workers; w++ {
		wg.Add(1)
		go func(id int) { defer wg.Done(); tcpClient(token, end, id, &r) }(w)
	}
	wg.Wait()
	r.Finished = time.Now().UTC()
	r.UDPLoss = r.UDPExpected - r.UDPReceived
	r.TCPBPS = float64(r.TCPWithin) * 8 / float64(*duration)
	r.UDPBPS = float64(r.UDPWithin*size) * 8 / float64(*duration)
	b, _ := json.MarshalIndent(&r, "", "  ")
	if e := os.WriteFile(*out, b, 0600); e != nil {
		panic(e)
	}
	fmt.Printf("tcp_ok=%d tcp_failures=%v tcp_mbps=%.3f udp_expected=%d udp_received=%d udp_loss=%d udp_mbps=%.3f\n", r.TCPOK, r.TCPFailures, r.TCPBPS/1e6, r.UDPExpected, r.UDPReceived, r.UDPLoss, r.UDPBPS/1e6)
}

func tcpClient(token string, end time.Time, id int, r *result) {
	time.Sleep(time.Duration(id) * 2 * time.Millisecond)
	for time.Now().Before(end) {
		start := time.Now()
		stage := "connect"
		errText := ""
		count := int64(0)
		status := 0
		e := func() error {
			c, e := net.DialTimeout("tcp", *proxy, 10*time.Second)
			if e != nil {
				return e
			}
			defer c.Close()
			c.SetDeadline(time.Now().Add(20 * time.Second))
			stage = "headers"
			_, e = c.Write(append(address(*target), []byte(fmt.Sprintf("GET /data HTTP/1.1\r\nHost: owned-test\r\nAuthorization: Bearer %s\r\nX-Rate: %d\r\nConnection: close\r\n\r\n", token, *bps/8 / *workers))...))
			if e != nil {
				return e
			}
			resp, e := http.ReadResponse(bufio.NewReader(c), nil)
			if e != nil {
				return e
			}
			defer resp.Body.Close()
			status = resp.StatusCode
			if status != 200 {
				stage = "http_" + strconv.Itoa(status)
				return fmt.Errorf("HTTP status %d", status)
			}
			if resp.ContentLength != int64(len(body)) {
				stage = "content_length"
				return fmt.Errorf("wrong target length")
			}
			stage = "body"
			h := sha256.New()
			buf := make([]byte, 16384)
			for {
				n, e := resp.Body.Read(buf)
				if n > 0 {
					count += int64(n)
					h.Write(buf[:n])
					r.mu.Lock()
					if time.Now().Before(end) {
						r.TCPWithin += int64(n)
					}
					r.mu.Unlock()
				}
				if e == io.EOF {
					break
				}
				if e != nil {
					return e
				}
			}
			if count != int64(len(body)) || hex.EncodeToString(h.Sum(nil)) != resp.Header.Get("X-Body-SHA256") {
				stage = "integrity"
				return fmt.Errorf("body mismatch")
			}
			return nil
		}()
		r.mu.Lock()
		r.TCPBytes += count
		if e == nil {
			r.TCPOK++
		} else {
			key := errorClass(stage, e)
			r.TCPFailures[key]++
			errText = e.Error()
		}
		r.Events = append(r.Events, map[string]any{"at": start.UTC(), "worker": id, "stage": stage, "status": status, "bytes": count, "error": errText, "elapsed_ms": time.Since(start).Milliseconds()})
		r.mu.Unlock()
		if e != nil {
			time.Sleep(200 * time.Millisecond)
		}
	}
}
func udpClient(token string, end time.Time, r *result) {
	addr, e := net.ResolveUDPAddr("udp", *proxy)
	if e != nil {
		r.UDPErrors = append(r.UDPErrors, e.Error())
		return
	}
	network, loopback := "udp4", net.IPv4(127, 0, 0, 1)
	if addr.IP.To4() == nil && addr.IP.To16() != nil {
		network, loopback = "udp6", net.IPv6loopback
	}
	conn, e := net.ListenUDP(network, &net.UDPAddr{IP: loopback})
	if e != nil {
		r.UDPErrors = append(r.UDPErrors, e.Error())
		return
	}
	defer conn.Close()
	if e = conn.SetReadBuffer(16 << 20); e != nil {
		r.UDPErrors = append(r.UDPErrors, e.Error())
	}
	total := int(int64(*bps) * int64(*duration) / 8 / size)
	r.UDPExpected = total
	seen := make([]bool, total)
	id := make([]byte, 8)
	if _, e := rand.Read(id); e != nil {
		panic(e)
	}
	r.UDPRunID = hex.EncodeToString(id)
	req := []byte(fmt.Sprintf("EUOT2 %s %d %d %s", token, *duration, *bps, r.UDPRunID))
	packet := append(address(*target), req...)
	_, e = conn.WriteToUDP(packet, addr)
	if e != nil {
		r.UDPErrors = append(r.UDPErrors, e.Error())
		return
	}
	lastRequest := time.Now()
	gotFirst := false
	buf := make([]byte, 65535)
	for time.Now().Before(end.Add(12 * time.Second)) {
		// Refresh the application UDP association with an idempotent owned-target
		// control request. It does not restart the downstream sequence.
		if time.Since(lastRequest) > 10*time.Second && time.Now().Before(end) {
			conn.WriteToUDP(packet, addr)
			lastRequest = time.Now()
		}
		conn.SetReadDeadline(time.Now().Add(time.Second))
		n, peer, e := conn.ReadFromUDP(buf)
		if e != nil {
			if !gotFirst && time.Since(lastRequest) > 2*time.Second && time.Now().Before(end) {
				conn.WriteToUDP(packet, addr)
				lastRequest = time.Now()
			}
			continue
		}
		if peer.Port != addr.Port {
			r.UDPInvalid++
			continue
		}
		p := stripAddress(buf[:n])
		if len(p) == size && string(p[:4]) == "EUE2" && bytes.Equal(p[4:12], id) {
			sent := binary.BigEndian.Uint64(p[12:20])
			r.UDPServerSent = &sent
			continue
		}
		if len(p) != size || string(p[:4]) != "EUD2" || !bytes.Equal(p[4:12], id) {
			r.UDPInvalid++
			continue
		}
		seq := binary.BigEndian.Uint64(p[12:20])
		if seq >= uint64(total) {
			r.UDPInvalid++
			continue
		}
		gotFirst = true
		if seen[seq] {
			r.UDPDuplicates++
			continue
		}
		seen[seq] = true
		r.UDPReceived++
		if time.Now().Before(end) {
			r.UDPWithin++
		}
		if r.UDPReceived == total && r.UDPServerSent != nil {
			break
		}
	}
}

func runServer(token string) {
	time.AfterFunc(time.Duration(*ttl)*time.Second, func() { os.Exit(0) })
	digest := sha256.Sum256(body)
	http.HandleFunc("/data", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+token {
			http.Error(w, "unauthorized", 403)
			return
		}
		rate, _ := strconv.Atoi(r.Header.Get("X-Rate"))
		if rate < 1 || rate > 2500000 {
			http.Error(w, "rate", 400)
			return
		}
		w.Header().Set("Content-Length", strconv.Itoa(len(body)))
		w.Header().Set("X-Body-SHA256", hex.EncodeToString(digest[:]))
		start := time.Now()
		for pos := 0; pos < len(body); {
			n := 4096
			if pos+n > len(body) {
				n = len(body) - pos
			}
			if _, e := w.Write(body[pos : pos+n]); e != nil {
				return
			}
			if f, ok := w.(http.Flusher); ok {
				f.Flush()
			}
			pos += n
			delay := time.Until(start.Add(time.Duration(float64(pos) / float64(rate) * float64(time.Second))))
			if delay > 0 {
				time.Sleep(delay)
			}
		}
	})
	a, e := net.ResolveUDPAddr("udp", *listen)
	if e != nil {
		panic(e)
	}
	u, e := net.ListenUDP("udp", a)
	if e != nil {
		panic(e)
	}
	u.SetWriteBuffer(16 << 20)
	u.SetReadBuffer(16 << 20)
	go func() {
		buf := make([]byte, 2048)
		var mu sync.Mutex
		active := map[string]bool{}
		running := 0
		for {
			n, peer, e := u.ReadFromUDP(buf)
			if e != nil {
				return
			}
			parts := strings.Fields(string(buf[:n]))
			if len(parts) != 5 || parts[0] != "EUOT2" || parts[1] != token {
				continue
			}
			seconds, _ := strconv.Atoi(parts[2])
			rate, _ := strconv.Atoi(parts[3])
			if seconds < 1 || seconds > 720 || rate < 1 || rate > 20000000 {
				continue
			}
			id, e := hex.DecodeString(parts[4])
			if e != nil || len(id) != 8 {
				continue
			}
			runID := parts[4]
			mu.Lock()
			if active[runID] || running >= 8 || len(active) >= 256 {
				mu.Unlock()
				continue
			}
			active[runID] = true
			running++
			mu.Unlock()
			go func(peer *net.UDPAddr, seconds, rate int, id []byte, runID string) {
				packet := make([]byte, size)
				copy(packet, "EUD2")
				copy(packet[4:12], id)
				total := int64(rate) * int64(seconds) / 8 / size
				start := time.Now()
				sent := uint64(0)
				sendErrors := uint64(0)
				defer func() {
					receipt, _ := json.Marshal(map[string]any{"run_id": runID, "requested": total, "sent_ok": sent, "send_errors": sendErrors, "elapsed_s": time.Since(start).Seconds()})
					fmt.Println(string(receipt))
					copy(packet[:4], "EUE2")
					binary.BigEndian.PutUint64(packet[12:20], sent)
					for i := 0; i < 5; i++ {
						u.WriteToUDP(packet, peer)
						time.Sleep(20 * time.Millisecond)
					}
					mu.Lock()
					running--
					mu.Unlock()
				}()
				for seq := int64(0); seq < total; seq++ {
					binary.BigEndian.PutUint64(packet[12:20], uint64(seq))
					if _, e := u.WriteToUDP(packet, peer); e != nil {
						sendErrors++
					} else {
						sent++
					}
					if seq%8 == 7 {
						wait := time.Until(start.Add(time.Duration(float64((seq+1)*size*8) / float64(rate) * float64(time.Second))))
						if wait > 0 {
							time.Sleep(wait)
						}
					}
				}
				// Keep the peer reserved: retrying the start request must never duplicate the stream.
			}(peer, seconds, rate, append([]byte(nil), id...), runID)
		}
	}()
	s := &http.Server{Addr: *listen, ReadHeaderTimeout: 10 * time.Second, IdleTimeout: 10 * time.Second, WriteTimeout: 120 * time.Second}
	if e := s.ListenAndServe(); e != nil {
		panic(e)
	}
}
