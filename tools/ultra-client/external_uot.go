package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/rand"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

// ExternalUoTConfig is deliberately opt-in. The provider relay only carries
// the encrypted command-1 byte stream; the operator-owned sidecar terminates
// the framed stream and owns the UDP socket to the fixed target.
type ExternalUoTConfig struct {
	DeliveryMode          string `json:"delivery_mode,omitempty"`
	Enabled               bool   `json:"enabled"`
	SidecarTarget         string `json:"sidecar_target"`
	Token                 string `json:"token"`
	QueuePackets          int    `json:"queue_packets"`
	KeepaliveSeconds      int    `json:"keepalive_seconds"`
	ReconnectGraceSeconds int    `json:"reconnect_grace_seconds"`
	FramePayloadMax       int    `json:"frame_payload_max"`
}

const (
	externalUoTMagic                   = "UOT2"
	externalUoTVersion                 = byte(1)
	externalUoTHeaderSize              = 36 // magic/version/type/flags/sid/seq/length
	externalUoTFramePrefixSize         = 4
	externalUoTMaxDatagramBytes        = 65507
	externalUoTMinDatagramBytes        = 256
	externalUoTDefaultQueuePackets     = 4096
	externalUoTDefaultKeepalive        = 10 * time.Second
	externalUoTMinKeepalive            = 5 * time.Second
	externalUoTMaxKeepalive            = 30 * time.Second
	externalUoTDefaultReconnectGrace   = 30 * time.Second
	externalUoTReconnectBackoffInitial = 250 * time.Millisecond
	externalUoTReconnectBackoffMax     = 5 * time.Second
)

const (
	externalUoTMsgHello    = byte(1)
	externalUoTMsgHelloAck = byte(2)
	externalUoTData        = byte(3)
	externalUoTUDPData     = byte(4)
	externalUoTClose       = byte(5)
	externalUoTKeepalive   = byte(6)
)

type externalUoTFrame struct {
	msgType  byte
	flags    uint16
	session  [16]byte
	sequence uint64
	payload  []byte
}

func (config ExternalUoTConfig) normalized() (ExternalUoTConfig, error) {
	if !config.Enabled {
		return config, nil
	}
	if config.DeliveryMode == "" {
		config.DeliveryMode = "raw"
	}
	if config.DeliveryMode != "raw" && config.DeliveryMode != reliableDatagramMode {
		return ExternalUoTConfig{}, errors.New("unsupported UoT delivery_mode; guaranteed delivery requires an owned application-ACK endpoint")
	}
	config.SidecarTarget = strings.TrimSpace(config.SidecarTarget)
	config.Token = strings.TrimSpace(config.Token)
	if config.SidecarTarget == "" || config.Token == "" || len(config.Token) > 8192 {
		return ExternalUoTConfig{}, errors.New("external UoT sidecar target/token missing")
	}
	if _, _, err := parseTarget(config.SidecarTarget); err != nil {
		return ExternalUoTConfig{}, fmt.Errorf("external UoT sidecar target: %w", err)
	}
	if config.QueuePackets < 1 || config.QueuePackets > 4096 {
		config.QueuePackets = externalUoTDefaultQueuePackets
	}
	if config.KeepaliveSeconds < 5 || config.KeepaliveSeconds > 30 {
		config.KeepaliveSeconds = int(externalUoTDefaultKeepalive / time.Second)
	}
	if config.ReconnectGraceSeconds < 5 || config.ReconnectGraceSeconds > 300 {
		config.ReconnectGraceSeconds = int(externalUoTDefaultReconnectGrace / time.Second)
	}
	if config.FramePayloadMax < externalUoTMinDatagramBytes || config.FramePayloadMax > externalUoTMaxDatagramBytes {
		config.FramePayloadMax = externalUoTMaxDatagramBytes
	}
	return config, nil
}

func externalUoTEnabled(config Config) bool {
	return config.ExternalUoT.Enabled
}

func probeExternalUoTLease(ctx context.Context, lease ProviderLease, config Config, policy Policy) udpProbeResult {
	result := udpProbeResult{SelectionTier: lease.SelectionTier, TCPSent: 1}
	normalized, err := config.ExternalUoT.normalized()
	if err != nil {
		result.TCPError = wrapProbeFailure(errProbeTCPFailed, err)
		result.Err = result.TCPError
		return result
	}
	sidecarHost, sidecarPort, err := parseTarget(normalized.SidecarTarget)
	if err != nil {
		result.TCPError = wrapProbeFailure(errProbeTCPFailed, err)
		result.Err = result.TCPError
		return result
	}
	if err := ctx.Err(); err != nil {
		result.TCPError = wrapProbeFailure(errProbeTCPFailed, err)
		result.Err = result.TCPError
		return result
	}
	started := time.Now()
	connection, err := dialStreamConfig(config, lease, sidecarHost, sidecarPort, probeTimeout(config))
	if err == nil {
		defer connection.Close()
		var probeSession [16]byte
		if _, randomErr := rand.Read(probeSession[:]); randomErr != nil {
			err = randomErr
		}
		if err == nil {
			hello, marshalErr := json.Marshal(externalUoTHello{
				Token: normalized.Token, ForwardID: config.ForwardID, Target: config.Target,
				FramePayloadMax: normalized.FramePayloadMax,
			})
			if marshalErr != nil {
				err = marshalErr
			} else {
				_ = connection.SetWriteDeadline(time.Now().Add(probeTimeout(config)))
				err = writeExternalUoTCarrierFrame(connection, externalUoTFrame{msgType: externalUoTMsgHello, session: probeSession, payload: hello})
				_ = connection.SetWriteDeadline(time.Time{})
			}
		}
		if err == nil {
			reader := bufio.NewReaderSize(connection, 64*1024)
			_ = connection.SetReadDeadline(time.Now().Add(probeTimeout(config)))
			var ack externalUoTFrame
			ack, err = readExternalUoTFrame(reader)
			_ = connection.SetReadDeadline(time.Time{})
			if err == nil {
				var helloAck externalUoTHelloAck
				if ack.msgType != externalUoTMsgHelloAck || !bytes.Equal(ack.session[:], probeSession[:]) || json.Unmarshal(ack.payload, &helloAck) != nil || !helloAck.OK || helloAck.FramePayloadMax < externalUoTMinDatagramBytes || helloAck.FramePayloadMax > externalUoTMaxDatagramBytes {
					err = errors.New("external UoT sidecar probe rejected")
				}
			}
		}
		if err == nil {
			// This is a disposable HELLO verification, never a business
			// association that will reattach. Release its sidecar slot rather
			// than filling the registry with grace-period probe sessions.
			_ = connection.SetWriteDeadline(time.Now().Add(probeTimeout(config)))
			_ = writeExternalUoTCarrierFrame(connection, externalUoTFrame{
				msgType: externalUoTClose, session: probeSession, sequence: 1,
			})
		}
	}
	if err != nil {
		result.TCPError = wrapProbeFailure(errProbeTCPFailed, err)
		result.Err = result.TCPError
		return result
	}
	result.TCPReceived = 1
	result.TCPRTT = time.Since(started)
	result.MedianRTT = result.TCPRTT
	result.P95RTT = result.TCPRTT
	return result
}

func externalUoTFrameBytes(frame externalUoTFrame) ([]byte, error) {
	if len(frame.payload) > externalUoTMaxDatagramBytes {
		return nil, errors.New("external UoT payload too large")
	}
	encoded := make([]byte, externalUoTHeaderSize+len(frame.payload))
	copy(encoded[:4], externalUoTMagic)
	encoded[4] = externalUoTVersion
	encoded[5] = frame.msgType
	binary.BigEndian.PutUint16(encoded[6:8], frame.flags)
	copy(encoded[8:24], frame.session[:])
	binary.BigEndian.PutUint64(encoded[24:32], frame.sequence)
	binary.BigEndian.PutUint32(encoded[32:36], uint32(len(frame.payload)))
	copy(encoded[36:], frame.payload)
	return encoded, nil
}

func readExternalUoTFrame(reader *bufio.Reader) (externalUoTFrame, error) {
	var prefix [externalUoTFramePrefixSize]byte
	if _, err := io.ReadFull(reader, prefix[:]); err != nil {
		return externalUoTFrame{}, err
	}
	frameLength := binary.BigEndian.Uint32(prefix[:])
	if frameLength < externalUoTHeaderSize || frameLength > externalUoTHeaderSize+externalUoTMaxDatagramBytes {
		return externalUoTFrame{}, errors.New("external UoT frame length invalid")
	}
	encoded := make([]byte, frameLength)
	if _, err := io.ReadFull(reader, encoded); err != nil {
		return externalUoTFrame{}, err
	}
	if !bytes.Equal(encoded[:4], []byte(externalUoTMagic)) || encoded[4] != externalUoTVersion {
		return externalUoTFrame{}, errors.New("external UoT frame header invalid")
	}
	payloadLength := binary.BigEndian.Uint32(encoded[32:36])
	if int(payloadLength) != len(encoded)-externalUoTHeaderSize {
		return externalUoTFrame{}, errors.New("external UoT frame payload length invalid")
	}
	var session [16]byte
	copy(session[:], encoded[8:24])
	return externalUoTFrame{
		msgType:  encoded[5],
		flags:    binary.BigEndian.Uint16(encoded[6:8]),
		session:  session,
		sequence: binary.BigEndian.Uint64(encoded[24:32]),
		payload:  append([]byte(nil), encoded[36:]...),
	}, nil
}

func writeExternalUoTFrame(writer io.Writer, frame externalUoTFrame) error {
	encoded, err := externalUoTFrameBytes(frame)
	if err != nil {
		return err
	}
	var prefix [externalUoTFramePrefixSize]byte
	binary.BigEndian.PutUint32(prefix[:], uint32(len(encoded)))
	if err := writeAll(writer, prefix[:]); err != nil {
		return err
	}
	return writeAll(writer, encoded)
}

// Command-1 application bytes use the same XOR stream transform as the
// existing Ultra TCP forward. The handshake is plaintext; UoT frames are not.
//
// appendExternalUoTCarrierFrame appends one length-prefixed, XOR-transformed
// frame to dst. The carrier writer builds whole batches this way so that a frame
// and its length prefix, and several queued frames, leave in one socket write:
// writing the 4-byte prefix and the frame separately cost two syscalls and, with
// TCP_NODELAY, two TCP segments per datagram.
func appendExternalUoTCarrierFrame(dst []byte, frame externalUoTFrame) ([]byte, error) {
	if len(frame.payload) > externalUoTMaxDatagramBytes {
		return dst, errors.New("external UoT payload too large")
	}
	start := len(dst)
	frameLength := externalUoTHeaderSize + len(frame.payload)
	dst = append(dst, make([]byte, externalUoTFramePrefixSize+externalUoTHeaderSize)...)
	encoded := dst[start:]
	binary.BigEndian.PutUint32(encoded[0:4], uint32(frameLength))
	header := encoded[externalUoTFramePrefixSize:]
	copy(header[:4], externalUoTMagic)
	header[4] = externalUoTVersion
	header[5] = frame.msgType
	binary.BigEndian.PutUint16(header[6:8], frame.flags)
	copy(header[8:24], frame.session[:])
	binary.BigEndian.PutUint64(header[24:32], frame.sequence)
	binary.BigEndian.PutUint32(header[32:36], uint32(len(frame.payload)))
	dst = append(dst, frame.payload...)
	for index := start; index < len(dst); index++ {
		dst[index] ^= 1
	}
	return dst, nil
}

func writeExternalUoTCarrierFrame(connection net.Conn, frame externalUoTFrame) error {
	if connection == nil {
		return net.ErrClosed
	}
	encoded, err := appendExternalUoTCarrierFrame(nil, frame)
	if err != nil {
		return err
	}
	defer clearBytes(encoded)
	return writeAll(connection, encoded)
}

// externalUoTCarrierWriteStall bounds how long a carrier write may make no
// progress. A carrier whose relay stopped reading used to pin the writer, and
// with it the keepalive and every queued datagram, until the association was
// torn down; the stall now ends the carrier so run() reconnects on another
// candidate. It is the same bound as the user-TCP stall.
const externalUoTCarrierWriteStall = remoteWriteProgressTimeout

// Batching bounds for the carrier writer. A batch only ever holds datagrams
// that were already queued when the previous write finished, so an idle
// carrier still writes each datagram immediately; under backlog it catches up
// in few large writes instead of two small ones per datagram. 64 KiB is still
// ~50 datagrams per write, and it bounds how long one write can keep the
// writer from measuring a slow carrier: a 256 KiB write into a carrier moving
// 110 KB/s blocked for 2.3 s while the queue behind it overflowed.
const (
	externalUoTBatchBytes  = 64 * 1024
	externalUoTBatchFrames = 256
)

func (association *externalUoTAssociation) carrierStall() time.Duration {
	if association.writeStall > 0 {
		return association.writeStall
	}
	return externalUoTCarrierWriteStall
}

// writeCarrier writes pre-encoded carrier bytes under the carrier write lock,
// with the write-progress bound. Every carrier write goes through here, so a
// blocked keepalive can no longer hold the lock forever either.
func (association *externalUoTAssociation) writeCarrier(connection net.Conn, encoded []byte) error {
	if connection == nil {
		return net.ErrClosed
	}
	stall := association.carrierStall()
	association.writeMu.Lock()
	defer association.writeMu.Unlock()
	return writeAllProgress(connection, encoded, func() time.Time { return time.Now().Add(stall) })
}

func (association *externalUoTAssociation) writeCarrierFrame(connection net.Conn, frame externalUoTFrame) error {
	encoded, err := appendExternalUoTCarrierFrame(nil, frame)
	if err != nil {
		return err
	}
	defer clearBytes(encoded)
	return association.writeCarrier(connection, encoded)
}

type externalUoTHello struct {
	Token           string `json:"token"`
	ForwardID       int    `json:"forward_id"`
	Target          string `json:"target"`
	FramePayloadMax int    `json:"frame_payload_max"`
	LastSequence    uint64 `json:"last_sequence,omitempty"`
	DeliveryMode    string `json:"delivery_mode,omitempty"`
}

type externalUoTHelloAck struct {
	OK              bool   `json:"ok"`
	Session         string `json:"session"` // OKN for new, OKR for reattach
	FramePayloadMax int    `json:"frame_payload_max"`
	QueuePackets    int    `json:"queue_packets"`
	Error           string `json:"error,omitempty"`
	DeliveryMode    string `json:"delivery_mode,omitempty"`
}

type externalUoTTuner struct {
	mu            sync.Mutex
	maxDatagram   int
	keepalive     time.Duration
	rttEMA        time.Duration
	stableSamples int
	reconnects    int
	drops         int
}

func newExternalUoTTuner(config ExternalUoTConfig) *externalUoTTuner {
	return &externalUoTTuner{maxDatagram: config.FramePayloadMax, keepalive: time.Duration(config.KeepaliveSeconds) * time.Second}
}

func (t *externalUoTTuner) observeRTT(rtt time.Duration) {
	if t == nil || rtt <= 0 {
		return
	}
	t.mu.Lock()
	defer t.mu.Unlock()
	if t.rttEMA <= 0 {
		t.rttEMA = rtt
	} else {
		t.rttEMA = (t.rttEMA*7 + rtt) / 8
	}
	t.stableSamples++
	if t.stableSamples >= 8 && t.keepalive > externalUoTMinKeepalive {
		t.keepalive -= time.Second
		t.stableSamples = 0
	}
}

func (t *externalUoTTuner) observeReconnect() {
	if t == nil {
		return
	}
	t.mu.Lock()
	t.reconnects++
	t.stableSamples = 0
	if t.keepalive < externalUoTMaxKeepalive {
		t.keepalive += 2 * time.Second
	}
	t.mu.Unlock()
}

func (t *externalUoTTuner) observeDrop() {
	if t == nil {
		return
	}
	t.mu.Lock()
	t.drops++
	if t.keepalive > externalUoTMinKeepalive {
		t.keepalive -= time.Second
	}
	t.mu.Unlock()
}

func (t *externalUoTTuner) snapshot() (time.Duration, int, int, int) {
	if t == nil {
		return externalUoTDefaultKeepalive, externalUoTMaxDatagramBytes, 0, 0
	}
	t.mu.Lock()
	defer t.mu.Unlock()
	return t.keepalive, t.maxDatagram, t.reconnects, t.drops
}

func (t *externalUoTTuner) setMaxDatagram(value int) {
	if t == nil || value < externalUoTMinDatagramBytes || value > externalUoTMaxDatagramBytes {
		return
	}
	t.mu.Lock()
	t.maxDatagram = value
	t.mu.Unlock()
}

func (t *externalUoTTuner) acceptsPayload(size int) bool {
	if t == nil {
		return size >= 0 && size <= externalUoTMaxDatagramBytes
	}
	t.mu.Lock()
	limit := t.maxDatagram
	t.mu.Unlock()
	return size >= 0 && size <= limit
}

type externalUoTAssociation struct {
	ctx               context.Context
	cancel            context.CancelFunc
	config            Config
	store             *PolicyStore
	status            *StatusWriter
	local             *net.UDPConn
	client            *net.UDPAddr
	socksUDP          bool
	target            string
	sidecar           string
	session           [16]byte
	outbound          chan externalUoTQueued
	budget            *datagramBudget
	reliable          *reliableDatagrams
	reliableAttached  bool
	carrierAttachedAt time.Time // owned by run/runCarrier, not packet workers
	done              chan struct{}
	lastMu            sync.Mutex
	lastSeen          time.Time
	writeMu           sync.Mutex
	sequenceMu        sync.Mutex
	sequence          uint64
	tuner             *externalUoTTuner
	retryMu           sync.Mutex
	retry             []externalUoTPending
	// unconfirmed holds the datagrams most recently written to the carrier
	// (bounded by externalUoTResendBytes / externalUoTResendAge). A carrier
	// can take a write and still lose it: the kernel and the provider relay
	// buffer it, and the sidecar drops the old carrier as soon as the session
	// reattaches on a new one. The next carrier resends them; the sidecar
	// suppresses every sequence it already delivered.
	unconfirmedMu    sync.Mutex
	unconfirmed      []externalUoTSent
	unconfirmedBytes int
	// queueOverflows counts datagrams refused because the queue was full. The
	// lag detector reads it to skip the attach grace once data is being lost.
	queueOverflows atomic.Int64
	// lag bounds the standing queue a carrier may build before the
	// association moves to another carrier. Zero disables the detector
	// (unit tests that drive the writer directly).
	lag externalUoTLagBounds
	// lagStreak counts consecutive carriers left for lagging. Owned by run();
	// the writer of the next carrier reads it after run() started it.
	lagStreak int
	// writeStall overrides externalUoTCarrierWriteStall (tests only).
	writeStall time.Duration
}

// externalUoTQueued is one admitted datagram and the time it was admitted, so
// the writer can tell how long datagrams wait for the carrier.
type externalUoTQueued struct {
	payload  []byte
	queuedAt time.Time
}

// externalUoTPending is a datagram the writer took from the queue together
// with the sequence number it was assigned.
type externalUoTPending struct {
	payload  []byte
	sequence uint64
}

// externalUoTSent is a datagram the carrier accepted, kept for a resend.
type externalUoTSent struct {
	externalUoTPending
	writtenAt time.Time
}

// Resend window. What a lagging carrier holds below the writer is bounded by
// the kernel send buffer and the relay's forwarding buffers; 2 MiB covers
// 6.7 s of a 2.5 Mbit/s association, and datagrams older than 10 s are not
// worth delivering. All associations of the runtime together keep at most
// externalUoTResendTotalBytes; one that pushes the total over it evicts its own
// oldest datagrams.
const (
	externalUoTResendBytes      = 2 << 20
	externalUoTResendTotalBytes = 32 << 20
	externalUoTResendAge        = 10 * time.Second
)

var externalUoTResendHeld atomic.Int64

// errExternalUoTCarrierLagging ends a carrier that keeps a standing queue: it
// still moves data, so it is not a write stall, but it moves less than the
// association offers.
var errExternalUoTCarrierLagging = errors.New("external UoT carrier lagging")

// externalUoTLagBounds decides when a carrier that still makes progress is too
// slow to keep.
//
// The 2026-09-24 SHA->LAX probes (combo 55, Windows host, 1200 B datagrams)
// showed the loss is per carrier, not per client OS: one carrier on line 694
// fell from 2.5 to 1.3-2.0 Mbit/s for more than 50 s and its datagrams waited
// up to 15 s, while its sibling through the same relay IP at the same time
// stayed at 2.5 Mbit/s with 160 ms latency; the same four-sender load through
// line 431 ran 180 s without a single loss. The carrier leg to the relay is a
// 20 ms TCP connection the relay terminates, so no client socket option changes
// what the relay's own per-connection path does. One UDP association is one
// carrier, so the only remedy the client has is to leave a carrier that has
// fallen behind: the association keeps its session (the sidecar reattaches the
// same UDP socket) and its queue, and the path is isolated so the next carrier
// prefers a sibling.
//
// A fresh carrier through that relay can also take up to ~7 s to reach
// 2.5 Mbit/s, queueing up to ~2.8 s on the way, and then catches up. The
// attach grace keeps that ramp from being mistaken for a lagging carrier,
// unless the queue already overflowed.
type externalUoTLagBounds struct {
	// target is the queueing delay a datagram may see without counting
	// against the carrier.
	target time.Duration
	// interval is how long every datagram the writer takes must have waited
	// at least target before the carrier is declared lagging. A carrier that
	// drains its queue even once resets the clock.
	interval time.Duration
	// grace is how long after attach a carrier may ramp up.
	grace time.Duration
	// drain bounds how long the lagging carrier may keep delivering what the
	// relay already holds after its write side is closed.
	drain time.Duration
}

var externalUoTDefaultLagBounds = externalUoTLagBounds{
	target:   time.Second,
	interval: 3 * time.Second,
	grace:    10 * time.Second,
	drain:    3 * time.Second,
}

func (bounds externalUoTLagBounds) enabled() bool {
	return bounds.target > 0 && bounds.interval > 0
}

// externalUoTLagStreakReset is how long a carrier must carry traffic without
// lagging before a lag streak is forgotten.
const externalUoTLagStreakReset = time.Minute

// forStreak stretches the interval and the grace after consecutive lagging
// carriers (x2, x4, x8). When every carrier lags, the association offers more
// than any path carries; leaving carriers then only adds reconnects to the
// loss the queue already shows, so the detector backs off instead.
func (bounds externalUoTLagBounds) forStreak(streak int) externalUoTLagBounds {
	if streak > 3 {
		streak = 3
	}
	if streak > 0 {
		bounds.interval <<= streak
		bounds.grace <<= streak
	}
	return bounds
}

// externalUoTLagTracker is the writer's view of one carrier's standing queue.
type externalUoTLagTracker struct {
	bounds         externalUoTLagBounds
	attachedAt     time.Time
	overflowsAtAtt int64
	aboveSince     time.Time
	worst          time.Duration
}

// observe records the queueing delay of the oldest datagram of a batch that was
// just written, and reports whether the carrier has kept a standing queue for
// the whole interval, or keeps one while the queue is three quarters full: a
// fast association fills its 4096 datagrams sooner than the interval (3.9 s at
// 10 Mbit/s), and waiting longer would only turn the lag into drops.
func (tracker *externalUoTLagTracker) observe(now time.Time, waited time.Duration, overflows int64, queued, capacity int) bool {
	if !tracker.bounds.enabled() {
		return false
	}
	if waited < tracker.bounds.target {
		tracker.aboveSince = time.Time{}
		tracker.worst = 0
		return false
	}
	if waited > tracker.worst {
		tracker.worst = waited
	}
	if tracker.aboveSince.IsZero() {
		tracker.aboveSince = now
	}
	nearlyFull := capacity > 0 && 4*queued >= 3*capacity
	if now.Sub(tracker.aboveSince) < tracker.bounds.interval && !nearlyFull {
		return false
	}
	return now.Sub(tracker.attachedAt) >= tracker.bounds.grace || overflows != tracker.overflowsAtAtt
}

func newExternalUoTAssociation(parent context.Context, config Config, store *PolicyStore, status *StatusWriter, local *net.UDPConn, client *net.UDPAddr, socksUDP bool) (*externalUoTAssociation, error) {
	normalized, err := config.ExternalUoT.normalized()
	if err != nil {
		return nil, err
	}
	host, port, err := parseTarget(config.Target)
	if err != nil {
		return nil, err
	}
	ctx, cancel := context.WithCancel(parent)
	association := &externalUoTAssociation{
		ctx: ctx, cancel: cancel, config: config, store: store, status: status,
		local: local, client: client, socksUDP: socksUDP, target: net.JoinHostPort(host, fmt.Sprint(port)),
		sidecar: normalized.SidecarTarget, done: make(chan struct{}),
		lastSeen: time.Now(), tuner: newExternalUoTTuner(normalized),
	}
	if _, err := rand.Read(association.session[:]); err != nil {
		cancel()
		return nil, err
	}
	association.outbound = make(chan externalUoTQueued, normalized.QueuePackets)
	association.budget = newDatagramBudget(datagramQueueBytes)
	association.lag = externalUoTDefaultLagBounds
	association.config.ExternalUoT = normalized
	if normalized.DeliveryMode == reliableDatagramMode {
		association.reliable = newReliableDatagrams()
	}
	return association, nil
}

func (association *externalUoTAssociation) touch() {
	association.lastMu.Lock()
	association.lastSeen = time.Now()
	association.lastMu.Unlock()
}

func (association *externalUoTAssociation) idleSince(now time.Time) time.Duration {
	association.lastMu.Lock()
	last := association.lastSeen
	association.lastMu.Unlock()
	return now.Sub(last)
}

// enqueue admits one datagram without blocking. It runs on the single read loop
// that serves every association of the forward, so it must never wait: the
// previous bounded wait (up to 250 ms per packet) let one association
// whose carrier had fallen behind stall reading for all of them, and the
// datagrams behind it were then dropped silently by the kernel (152,499 socket
// receive drops next to 707 counted ones on Linux in the 2026-09-24 matrix,
// uncountable on Windows and macOS). A full queue now drops only that
// association's datagram, and the drop is counted.
func (association *externalUoTAssociation) enqueue(payload []byte) bool {
	association.touch()
	if !association.tuner.acceptsPayload(len(payload)) {
		association.tuner.observeDrop()
		association.status.AddUDPOversizeDrop()
		if association.reliable != nil {
			association.reliable.fail(errors.New("reliable payload too large"))
			association.status.UpdateError("external_uot_reliable_failed")
			association.close()
		}
		return false
	}
	if association.reliable != nil {
		if err := association.reliable.enqueue(association.ctx, payload); err != nil {
			association.status.UpdateError("external_uot_reliable_failed")
			association.close()
			return false
		}
		return true
	}
	if association.ctx.Err() != nil {
		return false
	}
	if association.budget.tryReserve(len(payload)) {
		copyPayload := append([]byte(nil), payload...)
		select {
		case association.outbound <- externalUoTQueued{payload: copyPayload, queuedAt: time.Now()}:
			return true
		default:
		}
		association.budget.release(len(payload))
	}
	if association.ctx.Err() != nil {
		return false
	}
	association.queueOverflows.Add(1)
	association.status.AddUoTQueueBlocked()
	association.tuner.observeDrop()
	association.status.AddUoTQueueTimeout()
	association.status.UpdateError("external_uot_queue_timeout")
	return false
}

func (association *externalUoTAssociation) nextSequence() uint64 {
	association.sequenceMu.Lock()
	defer association.sequenceMu.Unlock()
	association.sequence++
	return association.sequence
}

// takeRetry hands the next carrier the batch the previous carrier failed to
// write, in order and with its original sequence numbers.
func (association *externalUoTAssociation) takeRetry() []externalUoTPending {
	association.retryMu.Lock()
	defer association.retryMu.Unlock()
	batch := association.retry
	association.retry = nil
	return batch
}

// preserveRetry keeps a batch that was taken from the queue but not written, so
// a disconnect does not lose it. The batch is bounded by the writer's batching
// limits, and only one batch is ever held.
func (association *externalUoTAssociation) preserveRetry(batch []externalUoTPending) {
	kept := make([]externalUoTPending, 0, len(batch))
	for _, item := range batch {
		if len(item.payload) == 0 || item.sequence == 0 {
			continue
		}
		kept = append(kept, externalUoTPending{payload: append([]byte(nil), item.payload...), sequence: item.sequence})
	}
	if len(kept) == 0 {
		return
	}
	association.retryMu.Lock()
	if association.retry == nil {
		association.retry = kept
	}
	association.retryMu.Unlock()
}

// confirmWritten records a batch the carrier accepted in the resend window and
// evicts what falls outside it.
func (association *externalUoTAssociation) confirmWritten(batch []externalUoTPending, now time.Time) {
	association.unconfirmedMu.Lock()
	defer association.unconfirmedMu.Unlock()
	for _, item := range batch {
		if len(item.payload) == 0 || item.sequence == 0 {
			continue
		}
		association.unconfirmed = append(association.unconfirmed, externalUoTSent{externalUoTPending: item, writtenAt: now})
		association.unconfirmedBytes += len(item.payload)
		externalUoTResendHeld.Add(int64(len(item.payload)))
	}
	drop := 0
	for drop < len(association.unconfirmed) &&
		(association.unconfirmedBytes > externalUoTResendBytes || externalUoTResendHeld.Load() > externalUoTResendTotalBytes ||
			now.Sub(association.unconfirmed[drop].writtenAt) > externalUoTResendAge) {
		size := len(association.unconfirmed[drop].payload)
		association.unconfirmedBytes -= size
		externalUoTResendHeld.Add(-int64(size))
		association.unconfirmed[drop] = externalUoTSent{}
		drop++
	}
	if drop > 0 {
		association.unconfirmed = append(association.unconfirmed[:0], association.unconfirmed[drop:]...)
	}
}

// requeueUnconfirmed puts the resend window ahead of the retry batch, so the
// next carrier writes it first and in sequence order.
func (association *externalUoTAssociation) requeueUnconfirmed(now time.Time) {
	association.unconfirmedMu.Lock()
	window := association.unconfirmed
	association.unconfirmed = nil
	externalUoTResendHeld.Add(-int64(association.unconfirmedBytes))
	association.unconfirmedBytes = 0
	association.unconfirmedMu.Unlock()
	resend := make([]externalUoTPending, 0, len(window))
	for _, item := range window {
		if now.Sub(item.writtenAt) <= externalUoTResendAge {
			resend = append(resend, item.externalUoTPending)
		}
	}
	if len(resend) == 0 {
		return
	}
	association.retryMu.Lock()
	defer association.retryMu.Unlock()
	for _, item := range association.retry {
		if item.sequence > resend[len(resend)-1].sequence {
			resend = append(resend, item)
		}
	}
	association.retry = resend
}

func (association *externalUoTAssociation) discardUnconfirmed() {
	association.unconfirmedMu.Lock()
	association.unconfirmed = nil
	externalUoTResendHeld.Add(-int64(association.unconfirmedBytes))
	association.unconfirmedBytes = 0
	association.unconfirmedMu.Unlock()
}

// rotateSession starts a new sidecar session (and so a new UDP socket there)
// after the reconnect grace ran out. Sequence numbers restart with it, so a
// retry batch or resend window numbered in the old space is dropped: sent
// first, it would set the new session's replay floor above every new datagram
// and have all of them suppressed as replays.
func (association *externalUoTAssociation) rotateSession() bool {
	if _, err := rand.Read(association.session[:]); err != nil {
		return false
	}
	association.sequenceMu.Lock()
	association.sequence = 0
	association.sequenceMu.Unlock()
	association.takeRetry()
	association.discardUnconfirmed()
	return true
}

func (association *externalUoTAssociation) run() {
	defer close(association.done)
	defer association.cancel()
	// Release this association's share of the runtime-wide resend budget.
	defer association.discardUnconfirmed()
	if association.reliable != nil {
		defer func() {
			association.status.SetReliableStats(association.session, association.reliable.snapshot(), true)
		}()
	}
	backoff := externalUoTReconnectBackoffInitial
	var disconnectedAt time.Time
	for association.ctx.Err() == nil {
		lagged := false
		if !disconnectedAt.IsZero() && time.Since(disconnectedAt) >= time.Duration(association.config.ExternalUoT.ReconnectGraceSeconds)*time.Second {
			if association.reliable != nil {
				association.reliable.fail(errors.New("reliable reconnect grace exhausted"))
				association.status.UpdateError("external_uot_reliable_failed")
				return
			}
			if association.rotateSession() {
				log.Printf("external UoT session rotated after grace forward_id=%d", association.config.ForwardID)
			}
			disconnectedAt = time.Time{}
		}
		connection, snapshot, err := dialCandidateStreamForProtocol(
			association.ctx,
			association.store,
			"udp",
			association.sidecarHost(), association.sidecarPort(),
			time.Duration(association.config.ConnectTimeoutSecs)*time.Second,
			dialStreamForConfig(association.config),
		)
		if err == nil {
			started := time.Now()
			err = association.runCarrier(connection, snapshot.Lease)
			if !errors.Is(err, errExternalUoTCarrierLagging) && !association.carrierAttachedAt.Before(started) &&
				time.Since(association.carrierAttachedAt) >= externalUoTLagStreakReset {
				association.lagStreak = 0
			}
			if err != nil && association.ctx.Err() == nil && association.carrierAttachedAt.Before(started) {
				// A relay may authenticate successfully yet reset the actual
				// sidecar HELLO. Do not keep retrying that same path forever.
				association.store.FailCandidate("udp", snapshot.Lease)
				association.status.RecordCandidateFailure(snapshot.Lease.CandidateKeyHash, "udp", "external_uot_hello_failed", snapshot.Lease.LeaseHash)
			} else if errors.Is(err, errRemoteWriteStall) && association.ctx.Err() == nil {
				// The relay stopped reading the carrier. Move the reconnect to
				// another candidate instead of re-dialling the same dead path.
				// Local isolation only, like every data-plane verdict.
				association.store.FailCandidate("udp", snapshot.Lease)
				association.status.UpdateError("external_uot_carrier_write_stall")
				log.Printf("external UoT carrier write stalled forward_id=%d stage=remote_write reason=stall stall_ms=%d candidate_hash=%s",
					association.config.ForwardID, association.carrierStall().Milliseconds(), snapshot.Lease.CandidateKeyHash)
			} else if errors.Is(err, errExternalUoTCarrierLagging) && association.ctx.Err() == nil {
				// The carrier still moves data, only too little of it. Local
				// isolation steers the replacement to a sibling path when there
				// is one; a single-path forward redials the same relay, and a
				// fresh connection through it is usually healthy again.
				lagged = true
				association.lagStreak++
				association.store.FailCandidate("udp", snapshot.Lease)
				association.status.AddUoTCarrierLagFailover()
				association.status.UpdateError("external_uot_carrier_lagging")
				log.Printf("external UoT carrier lagging forward_id=%d stage=remote_write reason=lagging queued=%d streak=%d candidate_hash=%s: %v",
					association.config.ForwardID, len(association.outbound), association.lagStreak, snapshot.Lease.CandidateKeyHash, err)
			}
			snapshot.Lease.wipe()
			connection.Close()
			if association.reliable != nil && association.reliable.snapshot().Failed != "" {
				association.status.UpdateError("external_uot_reliable_failed")
				return
			}
			if !association.carrierAttachedAt.Before(started) {
				// A completed HELLO resets the outage budget. Failed HELLOs
				// must not reset backoff merely because TCP dial succeeded.
				disconnectedAt = time.Now()
				backoff = externalUoTReconnectBackoffInitial
			}
			if err == nil || association.ctx.Err() != nil {
				return
			}
			if disconnectedAt.IsZero() {
				disconnectedAt = time.Now()
			}
			association.tuner.observeReconnect()
			_, _, reconnects, drops := association.tuner.snapshot()
			log.Printf("external UoT carrier reconnect forward_id=%d elapsed=%s reconnects=%d drops=%d reason=%v", association.config.ForwardID, time.Since(started), reconnects, drops, err)
			if lagged {
				// Datagrams are queueing right now; the backoff is for dead
				// paths, not for leaving a slow one.
				continue
			}
		} else if association.ctx.Err() != nil {
			return
		} else {
			if disconnectedAt.IsZero() {
				disconnectedAt = time.Now()
			}
			association.tuner.observeReconnect()
			association.status.UpdateError("external_uot_no_healthy_candidate")
		}
		timer := time.NewTimer(backoff)
		select {
		case <-association.ctx.Done():
			timer.Stop()
			return
		case <-timer.C:
		}
		if backoff < externalUoTReconnectBackoffMax {
			backoff *= 2
			if backoff > externalUoTReconnectBackoffMax {
				backoff = externalUoTReconnectBackoffMax
			}
		}
	}
}

func (association *externalUoTAssociation) sidecarHost() string {
	host, _, _ := net.SplitHostPort(association.sidecar)
	return host
}

func (association *externalUoTAssociation) sidecarPort() int {
	_, port, _ := parseTarget(association.sidecar)
	return port
}

func (association *externalUoTAssociation) runCarrier(connection net.Conn, lease ProviderLease) error {
	if connection == nil {
		return errors.New("external UoT carrier missing")
	}
	reader := bufio.NewReaderSize(connection, 128*1024)
	hello := externalUoTHello{
		Token: association.config.ExternalUoT.Token, ForwardID: association.config.ForwardID,
		Target: association.target, FramePayloadMax: association.config.ExternalUoT.FramePayloadMax,
		DeliveryMode: association.config.ExternalUoT.DeliveryMode,
	}
	association.sequenceMu.Lock()
	hello.LastSequence = association.sequence
	association.sequenceMu.Unlock()
	payload, err := json.Marshal(hello)
	if err != nil {
		return err
	}
	if err := connection.SetWriteDeadline(time.Now().Add(3 * time.Second)); err != nil {
		return err
	}
	helloStarted := time.Now()
	association.writeMu.Lock()
	err = writeExternalUoTCarrierFrame(connection, externalUoTFrame{msgType: externalUoTMsgHello, session: association.session, payload: payload})
	association.writeMu.Unlock()
	if err != nil {
		return err
	}
	if err := connection.SetReadDeadline(time.Now().Add(3 * time.Second)); err != nil {
		return err
	}
	ack, err := readExternalUoTFrame(reader)
	if err != nil {
		return err
	}
	if ack.msgType != externalUoTMsgHelloAck || !bytes.Equal(ack.session[:], association.session[:]) {
		return errors.New("external UoT hello rejected")
	}
	var helloAck externalUoTHelloAck
	if err := json.Unmarshal(ack.payload, &helloAck); err != nil || !helloAck.OK {
		if helloAck.Error == "" {
			helloAck.Error = "sidecar rejected hello"
		}
		return errors.New(helloAck.Error)
	}
	if helloAck.FramePayloadMax < externalUoTMinDatagramBytes || helloAck.FramePayloadMax > externalUoTMaxDatagramBytes {
		return errors.New("external UoT sidecar frame limit invalid")
	}
	if association.reliable != nil && helloAck.DeliveryMode != reliableDatagramMode {
		return association.reliable.fail(errors.New("sidecar did not negotiate reliable datagrams"))
	}
	if association.reliable != nil && helloAck.Session == "OKN" && association.reliableAttached {
		return association.reliable.fail(errors.New("reliable peer session state lost"))
	}
	association.tuner.setMaxDatagram(helloAck.FramePayloadMax)
	if helloAck.Session == "OKR" {
		// The sidecar kept the session and its replay floor: resend what the
		// previous carrier accepted but may not have delivered.
		association.requeueUnconfirmed(time.Now())
	} else {
		// A new sidecar session has no delivery history, so a resend could
		// only duplicate.
		association.discardUnconfirmed()
	}
	_ = connection.SetReadDeadline(time.Time{})
	_ = connection.SetWriteDeadline(time.Time{})
	statusRTT := time.Since(helloStarted)
	association.tuner.observeRTT(statusRTT)
	association.status.UpdateUDPWithTierAndCandidate(lease.SelectionRef, lease.SelectionTier, lease.CandidateKeyHash, statusRTT, lease.LeaseHash)
	association.status.RecordCandidateSuccess(lease.CandidateKeyHash, "udp", lease.LeaseHash)
	association.store.ClearPathFailure("udp", haPathKey(lease))
	carrierSendBuffer, carrierNotSentLowat := tuneExternalUoTCarrier(connection)
	log.Printf("external UoT carrier attached forward_id=%d sid=%x logical_protocol=udp carrier=tcp carrier_sndbuf=%d carrier_notsent_lowat=%d candidate_hash=%s lease_hash=%s target_hash=%s",
		association.config.ForwardID, association.session[:4], carrierSendBuffer, carrierNotSentLowat, lease.CandidateKeyHash, lease.LeaseHash, tcpTargetHash(association.config.Target))
	association.carrierAttachedAt = time.Now()

	if association.reliable != nil {
		association.reliableAttached = true
		return association.runReliableCarrier(reader, connection)
	}
	errCh := make(chan error, 2)
	carrierContext, cancelCarrier := context.WithCancel(association.ctx)
	workersDone := make(chan struct{}, 2)
	readerDone := make(chan struct{})
	go func() {
		defer func() { workersDone <- struct{}{} }()
		association.externalUoTWriter(carrierContext, connection, errCh)
	}()
	go func() {
		defer func() { workersDone <- struct{}{} }()
		defer close(readerDone)
		association.externalUoTReader(reader, connection, errCh)
	}()
	defer func() {
		// A disconnected writer waiting on outbound must not survive and steal
		// a packet from the next carrier. Close unblocks socket I/O; join both
		// workers before run() may attach a replacement.
		cancelCarrier()
		_ = connection.Close()
		<-workersDone
		<-workersDone
	}()
	keepalive, _, _, _ := association.tuner.snapshot()
	ticker := time.NewTicker(keepalive)
	defer ticker.Stop()
	for {
		select {
		case <-association.ctx.Done():
			return association.ctx.Err()
		case err := <-errCh:
			if errors.Is(err, errExternalUoTCarrierLagging) {
				association.drainLaggingCarrier(connection, readerDone)
			}
			return err
		case <-ticker.C:
			frame := externalUoTFrame{msgType: externalUoTKeepalive, session: association.session, sequence: association.nextSequence()}
			if writeErr := association.writeCarrierFrame(connection, frame); writeErr != nil {
				return writeErr
			}
		}
	}
}

// externalUoTWriter drains the association queue onto the carrier. Each write
// carries the datagram it waited for plus whatever else was already queued (up
// to externalUoTBatchFrames / externalUoTBatchBytes): it never waits to fill a
// batch, so it adds no latency, but a backlog is cleared in a few large writes.
//
// It also measures how long the oldest datagram of each batch waited until its
// write completed. A carrier that keeps every datagram waiting longer than the
// lag target for the lag interval ends with errExternalUoTCarrierLagging, and
// the queue it leaves behind goes to the next carrier.
func (association *externalUoTAssociation) externalUoTWriter(ctx context.Context, connection net.Conn, errorsOut chan<- error) {
	var encoded []byte
	defer func() { clearBytes(encoded) }()
	lag := externalUoTLagTracker{
		bounds: association.lag.forStreak(association.lagStreak), attachedAt: association.carrierAttachedAt,
		overflowsAtAtt: association.queueOverflows.Load(),
	}
	for {
		var oldest time.Time
		take := func(queued externalUoTQueued) {
			association.budget.release(len(queued.payload))
			if oldest.IsZero() {
				oldest = queued.queuedAt
			}
		}
		batch := association.takeRetry()
		if len(batch) == 0 {
			select {
			case <-ctx.Done():
				return
			case queued := <-association.outbound:
				take(queued)
				batch = append(batch, externalUoTPending{payload: queued.payload, sequence: association.nextSequence()})
			}
		}
		size := 0
		for _, item := range batch {
			size += externalUoTFramePrefixSize + externalUoTHeaderSize + len(item.payload)
		}
	collect:
		for len(batch) < externalUoTBatchFrames && size < externalUoTBatchBytes {
			select {
			case queued := <-association.outbound:
				take(queued)
				batch = append(batch, externalUoTPending{payload: queued.payload, sequence: association.nextSequence()})
				size += externalUoTFramePrefixSize + externalUoTHeaderSize + len(queued.payload)
			default:
				break collect
			}
		}
		if ctx.Err() != nil {
			association.preserveRetry(batch)
			return
		}
		encoded = encoded[:0]
		usage := 0
		for _, item := range batch {
			next, encodeErr := appendExternalUoTCarrierFrame(encoded, externalUoTFrame{
				msgType: externalUoTData, session: association.session, sequence: item.sequence, payload: item.payload,
			})
			if encodeErr != nil {
				// Admission already bounds datagram size; a datagram that still
				// cannot be framed is dropped, never retried forever.
				association.status.AddUDPOversizeDrop()
				continue
			}
			encoded = next
			usage += len(item.payload)
		}
		err := association.writeCarrier(connection, encoded)
		clearBytes(encoded)
		if err != nil {
			association.preserveRetry(batch)
			select {
			case errorsOut <- err:
			default:
			}
			return
		}
		association.status.AddUsage(0, usage)
		association.confirmWritten(batch, time.Now())
		if cap(encoded) > 2*externalUoTBatchBytes {
			// One batch of very large datagrams must not pin its buffer for the
			// carrier's lifetime.
			clearBytes(encoded)
			encoded = nil
		}
		if !oldest.IsZero() {
			now := time.Now()
			if lag.observe(now, now.Sub(oldest), association.queueOverflows.Load(), len(association.outbound), cap(association.outbound)) {
				select {
				case errorsOut <- fmt.Errorf("%w: every datagram waited at least %s for %s (worst %s, carrier age %s)",
					errExternalUoTCarrierLagging, lag.bounds.target, now.Sub(lag.aboveSince).Round(time.Millisecond),
					lag.worst.Round(time.Millisecond), now.Sub(lag.attachedAt).Round(time.Second)):
				default:
				}
				return
			}
		}
	}
}

// drainLaggingCarrier half-closes a lagging carrier and lets it deliver what
// the kernel and the relay already hold before the replacement attaches: the
// sidecar drops the old carrier the moment the same session says HELLO on a
// new one, so switching at once would discard everything still in flight. A
// relay that forwards the half-close lets the sidecar read to the end and
// close, which the reader sees as EOF; the resend window is then redundant and
// is dropped, so the next carrier does not spend its ramp re-sending datagrams
// the sidecar already has. When the drain bound expires first, the next
// carrier resends the window instead, and so it does as soon as half of the
// queue has filled while waiting, so that waiting never costs a drop. Datagrams
// arriving meanwhile stay queued for the next carrier, and replies keep being
// delivered from this one.
func (association *externalUoTAssociation) drainLaggingCarrier(connection net.Conn, readerDone <-chan struct{}) {
	tcp := carrierTCPConn(connection)
	if tcp == nil || association.lag.drain <= 0 {
		return
	}
	association.writeMu.Lock()
	err := tcp.CloseWrite()
	association.writeMu.Unlock()
	if err != nil {
		return
	}
	timer := time.NewTimer(association.lag.drain)
	defer timer.Stop()
	headroom := time.NewTicker(20 * time.Millisecond)
	defer headroom.Stop()
	for {
		select {
		case <-readerDone:
			association.discardUnconfirmed()
			return
		case <-timer.C:
			return
		case <-association.ctx.Done():
			return
		case <-headroom.C:
			if 2*len(association.outbound) >= cap(association.outbound) ||
				(association.budget != nil && 2*association.budget.bytes() >= association.budget.limit) {
				return
			}
		}
	}
}

func (association *externalUoTAssociation) externalUoTReader(reader *bufio.Reader, connection net.Conn, errorsOut chan<- error) {
	for {
		frame, err := readExternalUoTFrame(reader)
		if err != nil {
			select {
			case errorsOut <- err:
			default:
			}
			return
		}
		if !bytes.Equal(frame.session[:], association.session[:]) {
			continue
		}
		switch frame.msgType {
		case externalUoTUDPData:
			association.deliver(frame.payload)
		case externalUoTKeepalive:
			association.tuner.observeRTT(time.Millisecond)
			_ = association.writeCarrierFrame(connection, externalUoTFrame{msgType: externalUoTKeepalive, session: association.session, sequence: frame.sequence})
		case externalUoTClose:
			select {
			case errorsOut <- errors.New("sidecar closed external UoT session"):
			default:
			}
			return
		}
	}
}

func (association *externalUoTAssociation) deliver(payload []byte) {
	association.touch()
	if len(payload) == 0 || len(payload) > externalUoTMaxDatagramBytes {
		return
	}
	if association.socksUDP {
		if info, valid := decodeDatagramAddressFirst(payload); valid && len(info.payload) > 0 {
			if response, err := makeLocalUDPResponseFromDatagram(info, info.payload); err == nil {
				payload = response
			}
		}
	}
	if association.store.Limiter().Consume(len(payload)) != nil {
		return
	}
	association.status.AddUsage(len(payload), 0)
	_, _ = association.local.WriteToUDP(payload, association.client)
}

func (association *externalUoTAssociation) close() {
	if association.reliable != nil && association.reliable.snapshot().WindowPackets > 0 {
		association.reliable.fail(errors.New("reliable session closed with outstanding frames"))
	}
	association.cancel()
}

func serveExternalUoT(ctx context.Context, config Config, store *PolicyStore, status *StatusWriter) error {
	normalized, err := config.ExternalUoT.normalized()
	if err != nil {
		return err
	}
	config.ExternalUoT = normalized
	localAddress, err := net.ResolveUDPAddr("udp", net.JoinHostPort(config.ListenHost, fmt.Sprint(config.ListenPort)))
	if err != nil {
		return err
	}
	local, err := net.ListenUDP("udp", localAddress)
	if err != nil {
		return err
	}
	defer local.Close()
	recvErr := local.SetReadBuffer(16 << 20)
	sendErr := local.SetWriteBuffer(16 << 20)
	recv, send, bufferErr := datagramSocketBuffers(local)
	status.SetUoTSocketBuffers(recv, send, recvErr != nil || sendErr != nil || bufferErr != nil)
	go func() {
		ticker := time.NewTicker(time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				status.SetUoTSocketDrops(datagramSocketDrops(local))
			}
		}
	}()
	if testHookLocalUDPListening != nil {
		testHookLocalUDPListening(local)
	}
	associations := make(map[string]*externalUoTAssociation)
	maxAssociations, associationIdle := externalUoTAssociationLimits(config)
	var associationMu sync.Mutex
	remove := func(key string, association *externalUoTAssociation) {
		associationMu.Lock()
		if associations[key] == association {
			delete(associations, key)
		}
		status.UpdateUDPAssociations(len(associations), 0)
		associationMu.Unlock()
	}
	go func() {
		<-ctx.Done()
		_ = local.Close()
		associationMu.Lock()
		all := make([]*externalUoTAssociation, 0, len(associations))
		for key, association := range associations {
			delete(associations, key)
			all = append(all, association)
		}
		associationMu.Unlock()
		for _, association := range all {
			association.close()
		}
	}()
	go func() {
		ticker := time.NewTicker(time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case now := <-ticker.C:
				associationMu.Lock()
				pruneIdleExternalUoTAssociations(associations, now, associationIdle)
				status.UpdateUDPAssociations(len(associations), 0)
				associationMu.Unlock()
			}
		}
	}()
	buffer := make([]byte, 65535)
	for {
		count, client, readErr := local.ReadFromUDP(buffer)
		if readErr != nil {
			if ctx.Err() != nil {
				return nil
			}
			return readErr
		}
		payload, socksUDP, valid := decodeLocalUDPRequest(buffer[:count])
		if !valid || len(payload) > normalized.FramePayloadMax {
			status.UpdateError("external_uot_packet_invalid")
			continue
		}
		if err := store.Limiter().Consume(len(payload)); err != nil {
			status.UpdateError("policy_stopped")
			continue
		}
		key := client.String()
		associationMu.Lock()
		association := associations[key]
		if association != nil && association.socksUDP != socksUDP {
			association.close()
			delete(associations, key)
			association = nil
		}
		if association == nil {
			if len(associations) >= maxAssociations {
				pruneIdleExternalUoTAssociations(associations, time.Now(), associationIdle)
				if len(associations) >= maxAssociations {
					associationMu.Unlock()
					status.AddUDPQueueDrop()
					status.UpdateError("external_uot_association_limit")
					continue
				}
			}
			clientCopy := *client
			association, err = newExternalUoTAssociation(ctx, config, store, status, local, &clientCopy, socksUDP)
			if err == nil {
				associations[key] = association
				status.UpdateUDPAssociations(len(associations), 0)
				go func(key string, current *externalUoTAssociation) {
					current.run()
					remove(key, current)
				}(key, association)
			}
		}
		associationMu.Unlock()
		if err != nil {
			status.UpdateError("external_uot_config_invalid")
			continue
		}
		association.enqueue(payload)
	}
}
