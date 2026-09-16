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
	"time"
)

// ExternalUoTConfig is deliberately opt-in. The provider relay only carries
// the encrypted command-1 byte stream; the operator-owned sidecar terminates
// the framed stream and owns the UDP socket to the fixed target.
type ExternalUoTConfig struct {
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
	externalUoTDefaultQueuePackets     = 256
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
	connection, err := dialStream(lease, sidecarHost, sidecarPort, probeTimeout(config))
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
type externalUoTXorWriter struct {
	connection net.Conn
}

func (writer externalUoTXorWriter) Write(payload []byte) (int, error) {
	encoded := xorOne(payload)
	defer clearBytes(encoded)
	return writer.connection.Write(encoded)
}

func writeExternalUoTCarrierFrame(connection net.Conn, frame externalUoTFrame) error {
	if connection == nil {
		return net.ErrClosed
	}
	return writeExternalUoTFrame(externalUoTXorWriter{connection: connection}, frame)
}

type externalUoTHello struct {
	Token           string `json:"token"`
	ForwardID       int    `json:"forward_id"`
	Target          string `json:"target"`
	FramePayloadMax int    `json:"frame_payload_max"`
	LastSequence    uint64 `json:"last_sequence,omitempty"`
}

type externalUoTHelloAck struct {
	OK              bool   `json:"ok"`
	Session         string `json:"session"` // OKN for new, OKR for reattach
	FramePayloadMax int    `json:"frame_payload_max"`
	QueuePackets    int    `json:"queue_packets"`
	Error           string `json:"error,omitempty"`
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
	ctx        context.Context
	cancel     context.CancelFunc
	config     Config
	store      *PolicyStore
	status     *StatusWriter
	local      *net.UDPConn
	client     *net.UDPAddr
	socksUDP   bool
	target     string
	sidecar    string
	session    [16]byte
	outbound   chan []byte
	done       chan struct{}
	lastMu     sync.Mutex
	lastSeen   time.Time
	writeMu    sync.Mutex
	sequenceMu sync.Mutex
	sequence   uint64
	tuner      *externalUoTTuner
	retryMu    sync.Mutex
	retryBody  []byte
	retrySeq   uint64
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
	association.outbound = make(chan []byte, normalized.QueuePackets)
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

func (association *externalUoTAssociation) enqueue(payload []byte) bool {
	association.touch()
	if !association.tuner.acceptsPayload(len(payload)) {
		association.tuner.observeDrop()
		association.status.AddUDPOversizeDrop()
		return false
	}
	copyPayload := append([]byte(nil), payload...)
	select {
	case association.outbound <- copyPayload:
		return true
	default:
		association.tuner.observeDrop()
		association.status.AddUDPQueueDrop()
		return false
	}
}

func (association *externalUoTAssociation) nextSequence() uint64 {
	association.sequenceMu.Lock()
	defer association.sequenceMu.Unlock()
	association.sequence++
	return association.sequence
}

func (association *externalUoTAssociation) takeRetry() ([]byte, uint64) {
	association.retryMu.Lock()
	defer association.retryMu.Unlock()
	if association.retryBody == nil {
		return nil, 0
	}
	payload, sequence := association.retryBody, association.retrySeq
	association.retryBody = nil
	association.retrySeq = 0
	return payload, sequence
}

func (association *externalUoTAssociation) preserveRetry(payload []byte, sequence uint64) {
	if len(payload) == 0 || sequence == 0 {
		return
	}
	association.retryMu.Lock()
	if association.retryBody == nil {
		association.retryBody = append([]byte(nil), payload...)
		association.retrySeq = sequence
	}
	association.retryMu.Unlock()
}

func (association *externalUoTAssociation) run() {
	defer close(association.done)
	defer association.cancel()
	backoff := externalUoTReconnectBackoffInitial
	var disconnectedAt time.Time
	for association.ctx.Err() == nil {
		if !disconnectedAt.IsZero() && time.Since(disconnectedAt) >= time.Duration(association.config.ExternalUoT.ReconnectGraceSeconds)*time.Second {
			if _, rotateErr := rand.Read(association.session[:]); rotateErr == nil {
				association.sequenceMu.Lock()
				association.sequence = 0
				association.sequenceMu.Unlock()
				log.Printf("external UoT session rotated after grace forward_id=%d", association.config.ForwardID)
			}
			disconnectedAt = time.Time{}
		}
		connection, snapshot, err := dialCandidateStream(
			association.ctx,
			association.store,
			association.sidecarHost(), association.sidecarPort(),
			time.Duration(association.config.ConnectTimeoutSecs)*time.Second,
			dialStream,
		)
		if err == nil {
			started := time.Now()
			err = association.runCarrier(connection, snapshot.Lease)
			snapshot.Lease.wipe()
			connection.Close()
			backoff = externalUoTReconnectBackoffInitial
			if err == nil || association.ctx.Err() != nil {
				return
			}
			if disconnectedAt.IsZero() {
				disconnectedAt = time.Now()
			}
			association.tuner.observeReconnect()
			_, _, reconnects, drops := association.tuner.snapshot()
			log.Printf("external UoT carrier reconnect forward_id=%d elapsed=%s reconnects=%d drops=%d reason=%v", association.config.ForwardID, time.Since(started), reconnects, drops, err)
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
	association.tuner.setMaxDatagram(helloAck.FramePayloadMax)
	_ = connection.SetReadDeadline(time.Time{})
	_ = connection.SetWriteDeadline(time.Time{})
	statusRTT := time.Since(helloStarted)
	association.tuner.observeRTT(statusRTT)
	association.status.UpdateTCPWithTierAndCandidate(lease.SelectionRef, lease.SelectionTier, lease.CandidateKeyHash, statusRTT, lease.LeaseHash)
	association.status.RecordCandidateSuccess(lease.CandidateKeyHash, "tcp", lease.LeaseHash)
	association.store.ClearPathFailure("tcp", haPathKey(lease))

	errCh := make(chan error, 2)
	go association.externalUoTWriter(connection, errCh)
	go association.externalUoTReader(reader, connection, errCh)
	keepalive, _, _, _ := association.tuner.snapshot()
	ticker := time.NewTicker(keepalive)
	defer ticker.Stop()
	for {
		select {
		case <-association.ctx.Done():
			return association.ctx.Err()
		case err := <-errCh:
			return err
		case <-ticker.C:
			frame := externalUoTFrame{msgType: externalUoTKeepalive, session: association.session, sequence: association.nextSequence()}
			association.writeMu.Lock()
			writeErr := writeExternalUoTCarrierFrame(connection, frame)
			association.writeMu.Unlock()
			if writeErr != nil {
				return writeErr
			}
		}
	}
}

func (association *externalUoTAssociation) externalUoTWriter(connection net.Conn, errorsOut chan<- error) {
	var pending []byte
	var pendingSequence uint64
	for {
		if pending == nil {
			pending, pendingSequence = association.takeRetry()
			if pending == nil {
				select {
				case <-association.ctx.Done():
					return
				case pending = <-association.outbound:
					pendingSequence = association.nextSequence()
				}
			}
		}
		frame := externalUoTFrame{msgType: externalUoTData, session: association.session, sequence: pendingSequence, payload: pending}
		association.writeMu.Lock()
		err := writeExternalUoTCarrierFrame(connection, frame)
		association.writeMu.Unlock()
		if err != nil {
			association.preserveRetry(pending, pendingSequence)
			select {
			case errorsOut <- err:
			default:
			}
			return
		}
		association.status.AddUsage(0, len(pending))
		pending = nil
		pendingSequence = 0
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
			association.writeMu.Lock()
			_ = writeExternalUoTCarrierFrame(connection, externalUoTFrame{msgType: externalUoTKeepalive, session: association.session, sequence: frame.sequence})
			association.writeMu.Unlock()
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
	_ = local.SetReadBuffer(4 << 20)
	_ = local.SetWriteBuffer(4 << 20)
	if testHookLocalUDPListening != nil {
		testHookLocalUDPListening(local)
	}
	associations := make(map[string]*externalUoTAssociation)
	var associationMu sync.Mutex
	remove := func(key string, association *externalUoTAssociation) {
		associationMu.Lock()
		if associations[key] == association {
			delete(associations, key)
		}
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
		ticker := time.NewTicker(30 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case now := <-ticker.C:
				associationMu.Lock()
				for key, association := range associations {
					if association.idleSince(now) > 10*time.Minute {
						delete(associations, key)
						association.close()
					}
				}
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
			clientCopy := *client
			association, err = newExternalUoTAssociation(ctx, config, store, status, local, &clientCopy, socksUDP)
			if err == nil {
				associations[key] = association
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
