// Ultra acceptance harness for Windows, where neither python nor go exists on
// the host: cross-compiled from the operator machine and shipped as one exe.
//
// It is a faithful port of the two harnesses that produced the Linux/macOS
// acceptance evidence:
//   tmp/lk_stream2.py  -> TCP throughput continuity (1s windows, token bucket)
//   tmp/lk_uot.py      -> UDP rate/loss (1200B payloads, raw, no SS framing)
//
// The target answers every chunk that STARTS with LKT1. A 1400-byte frame does,
// because recv(65536) returns whatever has arrived - so the earlier "send only,
// never read" assumption was wrong and replies piled up unread until TCP flow
// control throttled the whole path. The TCP workers now drain their socket.
package main

import (
	"encoding/binary"
	"sync/atomic"
	"encoding/json"
	"flag"
	"fmt"
	"math"
	"math/rand"
	"net"
	"os"
	"sort"
	"sync"
	"time"
)

var magic = []byte("LKT1")

const tcpFrame = 1400
const udpPayload = 1200

type win struct {
	T    float64 `json:"t"`
	Mbps float64 `json:"mbps"`
}

type workerResult struct {
	idx     int
	windows []win
	sent    int64
	bytes   int64
	acked   int64
	read    int64
	errs    map[string]int64
}

func main() {
	tcpPort := flag.Int("tcp-port", 60502, "local TCP listen port of the forward")
	udpPort := flag.Int("udp-port", 60501, "local UDP listen port of the forward")
	host := flag.String("host", "127.0.0.1", "forward host")
	seconds := flag.Int("seconds", 600, "run duration")
	tcpMbps := flag.Float64("tcp-mbps", 20.0, "aggregate TCP rate to hold, 0 = unthrottled")
	udpMbps := flag.Float64("udp-mbps", 20.0, "aggregate UDP rate to hold")
	tcpStreams := flag.Int("tcp-streams", 4, "number of TCP connections")
	udpStreams := flag.Int("udp-streams", 4, "number of UDP senders")
	window := flag.Float64("window", 1.0, "accounting window seconds")
	writeTimeout := flag.Float64("write-timeout", 10.0, "seconds a single socket write may block before it is counted as a stall")
	label := flag.String("label", "", "free-form label recorded in the result")
	flag.Parse()

	start := time.Now()
	stopAt := start.Add(time.Duration(*seconds) * time.Second)

	tcpAddr := &net.TCPAddr{IP: net.ParseIP(*host), Port: *tcpPort}
	udpAddr := &net.UDPAddr{IP: net.ParseIP(*host), Port: *udpPort}

	var mu sync.Mutex
	var tcpRes, udpRes []workerResult

	perTCP := 0.0
	if *tcpMbps > 0 && *tcpStreams > 0 {
		perTCP = *tcpMbps * 1e6 / float64(*tcpStreams)
	}
	perUDP := 0.0
	if *udpMbps > 0 && *udpStreams > 0 {
		perUDP = *udpMbps * 1e6 / float64(*udpStreams)
	}

	var wg sync.WaitGroup
	// The UDP workers drain replies for 2s past the send deadline, so elapsed
	// time is longer than the TCP send window. Measure the TCP aggregate over
	// the TCP workers' own span, or a correctly paced run reads ~6% low.
	var tcpDoneAt time.Time
	var tcpDoneMu sync.Mutex
	tcpLeft := *tcpStreams
	for i := 0; i < *tcpStreams; i++ {
		wg.Add(1)
		go func(idx int) {
			defer wg.Done()
			r := tcpWorker(idx, tcpAddr, stopAt, *window, perTCP, *writeTimeout)
			tcpDoneMu.Lock()
			tcpLeft--
			if tcpLeft == 0 {
				tcpDoneAt = time.Now()
			}
			tcpDoneMu.Unlock()
			mu.Lock()
			tcpRes = append(tcpRes, r)
			mu.Unlock()
		}(i)
	}
	for i := 0; i < *udpStreams; i++ {
		wg.Add(1)
		go func(idx int) {
			defer wg.Done()
			r := udpWorker(idx, udpAddr, stopAt, perUDP, *writeTimeout)
			mu.Lock()
			udpRes = append(udpRes, r)
			mu.Unlock()
		}(i)
	}
	// Progress on stderr so a run that wedges says where it wedged.
	progressDone := make(chan struct{})
	go func() {
		ticker := time.NewTicker(10 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-progressDone:
				return
			case <-ticker.C:
				mu.Lock()
				var sent, bytes int64
				for _, r := range tcpRes {
					sent += r.sent
					bytes += r.bytes
				}
				var usent int64
				for _, r := range udpRes {
					usent += r.sent
				}
				fmt.Fprintf(os.Stderr, "progress t=%4.0fs tcp_frames=%d tcp_MB=%.1f udp_sent=%d\n",
					time.Since(start).Seconds(), sent, float64(bytes)/1e6, usent)
				mu.Unlock()
			}
		}
	}()
	wg.Wait()
	close(progressDone)
	elapsed := time.Since(start).Seconds()
	tcpElapsed := elapsed
	if !tcpDoneAt.IsZero() {
		tcpElapsed = tcpDoneAt.Sub(start).Seconds()
	}

	// Merge every TCP stream's windows onto a shared timeline: a wall-clock
	// second in which NO stream delivered anything is what "the stream broke"
	// means. Pad to the REQUESTED duration so a stream that dies early cannot
	// masquerade as a perfectly continuous run.
	buckets := map[int]float64{}
	for _, r := range tcpRes {
		for _, w := range r.windows {
			b := int(w.T / *window)
			buckets[b] += w.Mbps
		}
	}
	expected := int(math.Round(float64(*seconds) / *window))
	observed := 0
	for b := range buckets {
		if b+1 > observed {
			observed = b + 1
		}
	}
	n := expected
	if observed > n {
		n = observed
	}
	series := make([]float64, n)
	for i := 0; i < n; i++ {
		series[i] = math.Round(buckets[i]*1000) / 1000
	}
	idle := []int{}
	nonzero := []float64{}
	for i, v := range series {
		if v <= 0 {
			idle = append(idle, i)
		} else {
			nonzero = append(nonzero, v)
		}
	}
	// Report the REAL count. Truncating the slice before taking its length
	// under-reported every badly broken run as exactly 40 idle windows.
	idleCount := len(idle)
	if len(idle) > 40 {
		idle = idle[:40]
	}
	var tcpBytes, tcpReplies int64
	errs := map[string]int64{}
	for _, r := range tcpRes {
		tcpBytes += r.bytes
		tcpReplies += r.read
		for k, v := range r.errs {
			errs[k] += v
		}
	}
	var uSent, uAcked int64
	for _, r := range udpRes {
		uSent += r.sent
		uAcked += r.acked
		for k, v := range r.errs {
			errs[k] += v
		}
	}

	res := map[string]interface{}{
		"label":                  *label,
		"variant":                "windows-go-port-of-streaming-v2-continuity",
		"host":                   *host,
		"tcp_port":               *tcpPort,
		"udp_port":               *udpPort,
		"seconds":                math.Round(elapsed*100)/100,
		"tcp_streams":            *tcpStreams,
		"udp_streams":            *udpStreams,
		"window_seconds":         *window,
		"tcp_rate_limit_mbps":    *tcpMbps,
		"udp_requested_mbps":     *udpMbps,
		"tcp_active_seconds":     math.Round(tcpElapsed*100)/100,
		"tcp_aggregate_mbps":     math.Round(float64(tcpBytes)*8/tcpElapsed/1e6*1000) / 1000,
		"tcp_bytes_total":        tcpBytes,
		"tcp_reply_bytes":        tcpReplies,
		"windows_total":          n,
		"windows_idle":           idleCount,
		"idle_window_indices":    idle,
		"window_mbps_min_nonzero": minOrNil(nonzero),
		"window_mbps_median":     medianOrNil(nonzero),
		"window_mbps_max":        maxOrNil(nonzero),
		"window_cv_pct":          cvOrNil(nonzero),
		"windows_expected":       expected,
		"windows_observed":       observed,
		"streams_ended_early":    observed < expected,
		"delivered_fraction":     round4(float64(observed) / float64(expected)),
		"interruptions":          idleCount,
		"udp_sent_packets":       uSent,
		"udp_acked_packets":      uAcked,
		"udp_loss_pct":           lossPct(uSent, uAcked),
		"udp_achieved_mbps":      math.Round(float64(uSent)*udpPayload*8/elapsed/1e6*1000) / 1000,
		"error_classes":          errs,
		"per_stream_windows":     perStreamWindows(tcpRes),
	}
	b, _ := json.MarshalIndent(res, "", " ")
	fmt.Println(string(b))
	if p := os.Getenv("LKWIN_OUT"); p != "" {
		_ = os.WriteFile(p, b, 0644)
	}
}

func perStreamWindows(rs []workerResult) map[string]int {
	m := map[string]int{}
	for _, r := range rs {
		m[fmt.Sprintf("stream_%d_windows", r.idx)] = len(r.windows)
		m[fmt.Sprintf("stream_%d_frames", r.idx)] = int(r.sent)
	}
	return m
}

func tcpWorker(idx int, addr *net.TCPAddr, stopAt time.Time, windowSec, rateBps, writeTimeoutSec float64) workerResult {
	r := workerResult{idx: idx, errs: map[string]int64{}}
	conn, err := net.DialTimeout("tcp", addr.String(), 5*time.Second)
	if err != nil {
		r.errs[errClass(err)] = 1
		return r
	}
	defer conn.Close()
	if tc, ok := conn.(*net.TCPConn); ok {
		_ = tc.SetNoDelay(true)
	}
	// Drain the responder's replies. The target answers EVERY chunk that starts
	// with LKT1, and a 1400-byte frame does, because recv(65536) returns whatever
	// has arrived. Not reading let roughly 0.5% of the offered rate accumulate
	// unread in the receive buffer until TCP flow control pushed back through the
	// entire path: throughput collapsed, the "path capacity" being measured was
	// really the reply backlog filling, and the stream was eventually reset.
	var replyBytes int64
	readerStop := make(chan struct{})
	readerDone := make(chan struct{})
	go func() {
		defer close(readerDone)
		buf := make([]byte, 65536)
		for {
			select {
			case <-readerStop:
				return
			default:
			}
			_ = conn.SetReadDeadline(time.Now().Add(300 * time.Millisecond))
			n, err := conn.Read(buf)
			if n > 0 {
				atomic.AddInt64(&replyBytes, int64(n))
			}
			if err != nil {
				if ne, ok := err.(net.Error); ok && ne.Timeout() {
					continue
				}
				return
			}
		}
	}()
	defer func() {
		close(readerStop)
		<-readerDone
	}()

	frame := make([]byte, tcpFrame)
	copy(frame, magic)
	rnd := rand.New(rand.NewSource(int64(idx)*7919 + time.Now().UnixNano()))
	_, _ = rnd.Read(frame[4:])

	capacity := math.Max(rateBps*0.05, float64(tcpFrame*8*4))
	if rateBps == 0 {
		capacity = 0
	}
	tokens := capacity
	tStart := time.Now()
	last := tStart
	winStart := tStart
	var winBytes int64
	for time.Now().Before(stopAt) {
		now := time.Now()
		if rateBps > 0 {
			tokens = math.Min(capacity, tokens+now.Sub(last).Seconds()*rateBps)
			last = now
			if tokens < float64(tcpFrame*8) {
				time.Sleep(time.Millisecond)
				continue
			}
		}
		// A stalled forward leaves the socket buffer full and Write blocks
		// forever. Without this deadline the harness never returns and the run
		// has to be killed, which loses the evidence. A write that blocks longer
		// than the timeout is itself the interruption the metric is looking for.
		if writeTimeoutSec > 0 {
			_ = conn.SetWriteDeadline(time.Now().Add(time.Duration(writeTimeoutSec * float64(time.Second))))
		}
		if _, err := conn.Write(frame); err != nil {
			if ne, ok := err.(net.Error); ok && ne.Timeout() {
				r.errs["write_stall_timeout"]++
			} else {
				r.errs[errClass(err)]++
			}
			break
		}
		r.sent++
		winBytes += tcpFrame
		if rateBps > 0 {
			tokens -= float64(tcpFrame * 8)
		}
		now = time.Now()
		if now.Sub(winStart).Seconds() >= windowSec {
			r.windows = append(r.windows, win{
				T:    round2(winStart.Sub(tStart).Seconds()),
				Mbps: math.Round(float64(winBytes)*8/now.Sub(winStart).Seconds()/1e6*1000) / 1000,
			})
			winStart = now
			winBytes = 0
		}
	}
	if winBytes > 0 && time.Since(winStart) > 0 {
		r.windows = append(r.windows, win{
			T:    round2(winStart.Sub(tStart).Seconds()),
			Mbps: math.Round(float64(winBytes)*8/time.Since(winStart).Seconds()/1e6*1000) / 1000,
		})
	}
	r.bytes = r.sent * tcpFrame
	r.read = atomic.LoadInt64(&replyBytes)
	return r
}

func udpWorker(idx int, addr *net.UDPAddr, stopAt time.Time, targetBps float64, writeTimeoutSec float64) workerResult {
	r := workerResult{idx: idx, errs: map[string]int64{}}
	conn, err := net.DialUDP("udp", nil, addr)
	if err != nil {
		r.errs[errClass(err)] = 1
		return r
	}
	defer conn.Close()
	_ = conn.SetReadBuffer(8 << 20)

	done := make(chan struct{})
	var ackMu sync.Mutex
	go func() {
		buf := make([]byte, 2048)
		for {
			select {
			case <-done:
				return
			default:
			}
			_ = conn.SetReadDeadline(time.Now().Add(200 * time.Millisecond))
			n, err := conn.Read(buf)
			if err == nil && n > 0 {
				ackMu.Lock()
				r.acked++
				ackMu.Unlock()
			}
		}
	}()

	perPktBits := float64(udpPayload * 8)
	pps := math.Max(1, targetBps/perPktBits)
	interval := time.Second / time.Duration(pps)
	if interval <= 0 {
		interval = time.Microsecond
	}
	body := make([]byte, udpPayload)
	copy(body, magic)
	rnd := rand.New(rand.NewSource(int64(idx)*104729 + time.Now().UnixNano()))
	_, _ = rnd.Read(body[8:])
	nxt := time.Now()
	for time.Now().Before(stopAt) {
		now := time.Now()
		if now.Before(nxt) {
			d := nxt.Sub(now)
			if d > interval {
				d = interval
			}
			time.Sleep(d)
			continue
		}
		binary.BigEndian.PutUint32(body[4:8], uint32(r.sent))
		// A UDP send blocks once the socket send buffer is full, which is what a
		// stalled forward looks like from here. Without a deadline the worker
		// never returns and the run has to be killed, losing the evidence.
		if writeTimeoutSec > 0 {
			_ = conn.SetWriteDeadline(time.Now().Add(time.Duration(writeTimeoutSec * float64(time.Second))))
		}
		if _, err := conn.Write(body); err != nil {
			if ne, ok := err.(net.Error); ok && ne.Timeout() {
				r.errs["udp_write_stall_timeout"]++
			} else {
				r.errs[errClass(err)]++
			}
		} else {
			r.sent++
		}
		nxt = nxt.Add(interval)
		if nxt.Before(now) {
			nxt = now
		}
	}
	// Drain window so replies in flight are still counted.
	time.Sleep(2 * time.Second)
	close(done)
	ackMu.Lock()
	defer ackMu.Unlock()
	return r
}

func errClass(err error) string {
	if err == nil {
		return "none"
	}
	s := err.Error()
	if len(s) > 80 {
		s = s[:80]
	}
	return s
}

func round2(f float64) float64 { return math.Round(f*100) / 100 }
func round4(f float64) float64 { return math.Round(f*10000) / 10000 }

func minOrNil(v []float64) interface{} {
	if len(v) == 0 {
		return nil
	}
	m := v[0]
	for _, x := range v {
		if x < m {
			m = x
		}
	}
	return m
}

func maxOrNil(v []float64) interface{} {
	if len(v) == 0 {
		return nil
	}
	m := v[0]
	for _, x := range v {
		if x > m {
			m = x
		}
	}
	return m
}

func medianOrNil(v []float64) interface{} {
	if len(v) == 0 {
		return nil
	}
	c := append([]float64(nil), v...)
	sort.Float64s(c)
	n := len(c)
	if n%2 == 1 {
		return math.Round(c[n/2]*1000) / 1000
	}
	return math.Round((c[n/2-1]+c[n/2])/2*1000) / 1000
}

func cvOrNil(v []float64) interface{} {
	if len(v) < 2 {
		return nil
	}
	var sum float64
	for _, x := range v {
		sum += x
	}
	mean := sum / float64(len(v))
	var sq float64
	for _, x := range v {
		sq += (x - mean) * (x - mean)
	}
	sd := math.Sqrt(sq / float64(len(v)))
	return math.Round(100*sd/mean*100) / 100
}

func lossPct(sent, acked int64) interface{} {
	if sent == 0 {
		return nil
	}
	return math.Round(100*(float64(sent-acked)/float64(sent))*10000) / 10000
}
