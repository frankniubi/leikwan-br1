package main

// External UoT sidecar server: authenticated UDP over TCP relay, wire and
// behaviour compatible with sidecar-agent/leikwan_external_uot.py.
//
// One session owns one connected UDP socket. A carrier reconnect with the same
// session id reuses that socket during the grace period, which preserves the
// target-visible source port. DATA and UDP_DATA are independent directions; the
// relay never waits for a response before forwarding the next datagram.

import (
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net"
	"strings"
	"sync"
	"time"
)

const (
	logDebug = iota
	logInfo
	logWarn
	logError
)

type sidecarLogger struct {
	minLevel int
	out      io.Writer
	mu       sync.Mutex
}

func newSidecarLogger(levelName string, out io.Writer) *sidecarLogger {
	levels := map[string]int{"DEBUG": logDebug, "INFO": logInfo, "WARNING": logWarn, "WARN": logWarn, "ERROR": logError}
	level, ok := levels[strings.ToUpper(strings.TrimSpace(levelName))]
	if !ok {
		level = logInfo
	}
	return &sidecarLogger{minLevel: level, out: out}
}

func (l *sidecarLogger) logf(level int, format string, args ...any) {
	if level < l.minLevel {
		return
	}
	names := map[int]string{logDebug: "DEBUG", logInfo: "INFO", logWarn: "WARNING", logError: "ERROR"}
	l.mu.Lock()
	defer l.mu.Unlock()
	fmt.Fprintf(l.out, "%s %s %s\n", time.Now().Format("2006-01-02 15:04:05,000"), names[level], fmt.Sprintf(format, args...))
}

func (l *sidecarLogger) infof(format string, args ...any)  { l.logf(logInfo, format, args...) }
func (l *sidecarLogger) warnf(format string, args ...any)  { l.logf(logWarn, format, args...) }
func (l *sidecarLogger) debugf(format string, args ...any) { l.logf(logDebug, format, args...) }

// connectionError marks a carrier/UDP failure that is not a protocol violation.
// The Python sidecar stays silent for these, so this implementation does too
// (aside from an operator-facing log line).
type connectionError struct{ err error }

func (e connectionError) Error() string { return e.err.Error() }
func (e connectionError) Unwrap() error { return e.err }

func isProtocolFailure(err error) bool {
	if err == nil {
		return false
	}
	var protocol protocolError
	return errors.As(err, &protocol)
}

func isClosedError(err error) bool {
	if err == nil {
		return false
	}
	return errors.Is(err, net.ErrClosed) || errors.Is(err, io.EOF) || errors.Is(err, io.ErrClosedPipe)
}

type sessionStats struct {
	udpIn        uint64
	udpOut       uint64
	queueDrops   uint64
	oversizeDrop uint64
	reattaches   uint64
}

type uotSession struct {
	server          *sidecarServer
	id              [sessionIDBytes]byte
	binding         forwardBinding
	target          string
	framePayloadMax int
	tuner           *adaptiveTuner
	outbound        chan frame
	udpConn         *net.UDPConn
	udpDone         chan struct{}

	mu            sync.Mutex
	carrier       net.Conn
	detachedAt    time.Time
	closed        bool
	seq           uint64
	lastClientSeq uint64
	stats         sessionStats
}

func newUoTSession(server *sidecarServer, id [sessionIDBytes]byte, binding forwardBinding, target string, framePayloadMax int) *uotSession {
	return &uotSession{
		server:          server,
		id:              id,
		binding:         binding,
		target:          target,
		framePayloadMax: framePayloadMax,
		tuner:           newAdaptiveTuner(framePayloadMax, server.config.queuePackets, server.config.framePayloadMax),
		outbound:        make(chan frame, server.config.queuePackets),
		udpDone:         make(chan struct{}),
	}
}

func (s *uotSession) shortID() string { return hex.EncodeToString(s.id[:])[:8] }

func (s *uotSession) open(dialer *net.Dialer) error {
	host, port, err := targetParts(s.target)
	if err != nil {
		return err
	}
	connection, err := dialer.Dial("udp", net.JoinHostPort(host, port))
	if err != nil {
		return err
	}
	udpConn, ok := connection.(*net.UDPConn)
	if !ok {
		_ = connection.Close()
		return fmt.Errorf("target resolution did not yield a UDP socket")
	}
	s.udpConn = udpConn
	go s.udpReader()
	return nil
}

func (s *uotSession) udpReader() {
	defer close(s.udpDone)
	buffer := make([]byte, maxDatagram)
	for {
		count, err := s.udpConn.Read(buffer)
		if err != nil {
			if !s.isClosed() {
				s.server.logger.warnf("external UoT UDP reader stopped sid=%s: %v", s.shortID(), err)
			}
			return
		}
		if count > 0 {
			s.enqueueUDP(buffer[:count])
		}
	}
}

func (s *uotSession) isClosed() bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.closed
}

func (s *uotSession) nextSequence() uint64 {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.seq++
	return s.seq
}

func (s *uotSession) enqueueUDP(payload []byte) bool {
	if s.isClosed() || len(payload) > s.framePayloadMax {
		s.mu.Lock()
		s.stats.oversizeDrop++
		s.mu.Unlock()
		s.tuner.observePressure()
		return false
	}
	s.tuner.observePayload(len(payload))
	encoded := append([]byte(nil), payload...)
	select {
	case s.outbound <- frame{msgType: msgUDPData, session: s.id, seq: s.nextSequence(), payload: encoded}:
		s.mu.Lock()
		s.stats.udpIn++
		s.mu.Unlock()
		return true
	default:
		s.mu.Lock()
		s.stats.queueDrops++
		s.mu.Unlock()
		s.tuner.observePressure()
		return false
	}
}

func (s *uotSession) sendToUDP(payload []byte) error {
	if s.isClosed() {
		return connectionError{err: fmt.Errorf("session closed")}
	}
	if len(payload) > s.framePayloadMax {
		s.mu.Lock()
		s.stats.oversizeDrop++
		s.mu.Unlock()
		s.tuner.observePressure()
		return nil
	}
	if _, err := s.udpConn.Write(payload); err != nil {
		return connectionError{err: err}
	}
	s.mu.Lock()
	s.stats.udpOut++
	s.mu.Unlock()
	s.tuner.observePayload(len(payload))
	s.touch()
	return nil
}

func (s *uotSession) touch() {
	s.mu.Lock()
	s.detachedAt = time.Time{}
	s.mu.Unlock()
}

func (s *uotSession) attach(carrier net.Conn) bool {
	s.mu.Lock()
	previous := s.carrier
	reattached := previous != nil || !s.detachedAt.IsZero()
	s.carrier = carrier
	s.detachedAt = time.Time{}
	if reattached {
		s.stats.reattaches++
	}
	s.mu.Unlock()
	if reattached {
		s.tuner.observeReconnect()
	}
	if previous != nil && previous != carrier {
		_ = previous.Close()
	}
	return reattached
}

func (s *uotSession) detach(carrier net.Conn) {
	s.mu.Lock()
	if s.carrier == carrier {
		s.carrier = nil
		s.detachedAt = time.Now()
	}
	s.mu.Unlock()
}

func (s *uotSession) currentCarrier() net.Conn {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.carrier
}

func (s *uotSession) acceptClientSequence(seq uint64) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if seq <= s.lastClientSeq {
		// A carrier write can fail after the relay accepted the frame. The
		// client retries the same sequence on reattach; suppress that replay
		// before it reaches the UDP target.
		return false
	}
	s.lastClientSeq = seq
	return true
}

func (s *uotSession) snapshotStats() sessionStats {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.stats
}

func (s *uotSession) graceExpired(now time.Time, grace time.Duration) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.closed {
		return true
	}
	return s.carrier == nil && !s.detachedAt.IsZero() && now.Sub(s.detachedAt) >= grace
}

func (s *uotSession) close() {
	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		return
	}
	s.closed = true
	carrier := s.carrier
	s.carrier = nil
	s.mu.Unlock()
	if carrier != nil {
		_ = carrier.Close()
	}
	if s.udpConn != nil {
		_ = s.udpConn.Close()
		<-s.udpDone
	}
}

// carrierReader consumes one carrier connection until it ends.
func (s *uotSession) carrierReader(carrier net.Conn) error {
	for {
		_ = carrier.SetReadDeadline(time.Now().Add(time.Duration(s.server.config.idleTimeoutSeconds * float64(time.Second))))
		incoming, err := readFrame(carrier, s.server.config.maxFrameBytes)
		if err != nil {
			if isTimeout(err) {
				return protocolError{reason: "carrier idle timeout"}
			}
			if isProtocolFailure(err) {
				return err
			}
			return connectionError{err: err}
		}
		if incoming.session != s.id {
			return protocolError{reason: "session id mismatch"}
		}
		s.touch()
		switch incoming.msgType {
		case msgData:
			if !s.acceptClientSequence(incoming.seq) {
				continue
			}
			if len(incoming.payload) > s.framePayloadMax {
				s.mu.Lock()
				s.stats.oversizeDrop++
				s.mu.Unlock()
				s.tuner.observePressure()
				continue
			}
			if err := s.sendToUDP(incoming.payload); err != nil {
				return err
			}
		case msgKeepalive:
			continue
		case msgClose:
			return nil
		default:
			return protocolError{reason: "unexpected frame type"}
		}
	}
}

// carrierWriter drains the session queue onto one carrier connection and emits
// keepalives while the queue is idle. Because the queue belongs to the session,
// a reattach resumes where the previous carrier stopped.
func (s *uotSession) carrierWriter(carrier net.Conn, stop <-chan struct{}) error {
	keepalive := time.Duration(s.server.config.keepaliveSeconds * float64(time.Second))
	timer := time.NewTimer(keepalive)
	defer timer.Stop()
	for {
		if s.currentCarrier() != carrier {
			return nil
		}
		select {
		case <-stop:
			return nil
		case outgoing := <-s.outbound:
			if s.currentCarrier() != carrier {
				return nil
			}
			if err := s.writeFrame(carrier, outgoing); err != nil {
				return connectionError{err: err}
			}
			// Match the Python sidecar: any traffic restarts the keepalive timer.
			if !timer.Stop() {
				select {
				case <-timer.C:
				default:
				}
			}
			timer.Reset(keepalive)
		case <-timer.C:
			if s.currentCarrier() != carrier {
				return nil
			}
			keepaliveFrame := frame{msgType: msgKeepalive, session: s.id, seq: s.nextSequence()}
			if err := s.writeFrame(carrier, keepaliveFrame); err != nil {
				return connectionError{err: err}
			}
			timer.Reset(keepalive)
		}
	}
}

func (s *uotSession) writeFrame(carrier net.Conn, outgoing frame) error {
	_ = carrier.SetWriteDeadline(time.Now().Add(time.Duration(s.server.config.idleTimeoutSeconds * float64(time.Second))))
	if err := writeFrame(carrier, outgoing, s.server.config.maxFrameBytes); err != nil {
		s.server.logger.debugf("external UoT carrier write failed sid=%s: %v", s.shortID(), err)
		return err
	}
	return nil
}

func isTimeout(err error) bool {
	var netErr net.Error
	return errors.As(err, &netErr) && netErr.Timeout()
}

type sessionRegistry struct {
	server   *sidecarServer
	mu       sync.Mutex
	sessions map[[sessionIDBytes]byte]*uotSession
}

func newSessionRegistry(server *sidecarServer) *sessionRegistry {
	return &sessionRegistry{server: server, sessions: make(map[[sessionIDBytes]byte]*uotSession)}
}

func (r *sessionRegistry) announce(id [sessionIDBytes]byte, binding forwardBinding, target string, requestedMax int) (*uotSession, bool, error) {
	r.mu.Lock()
	existing, present := r.sessions[id]
	if present && !existing.isClosed() {
		if existing.binding.forwardID != binding.forwardID || existing.target != target {
			r.mu.Unlock()
			return nil, false, protocolError{reason: "session binding mismatch"}
		}
		r.mu.Unlock()
		return existing, true, nil
	}
	if len(r.sessions) >= r.server.config.sessionLimit {
		r.mu.Unlock()
		return nil, false, protocolError{reason: "session limit reached"}
	}
	negotiated := minInt(requestedMax, r.server.config.framePayloadMax)
	if negotiated < minFramePayload {
		r.mu.Unlock()
		return nil, false, protocolError{reason: "no usable frame payload size"}
	}
	session := newUoTSession(r.server, id, binding, target, negotiated)
	r.mu.Unlock()
	if err := session.open(r.server.dialer()); err != nil {
		return nil, false, connectionError{err: err}
	}
	r.mu.Lock()
	r.sessions[id] = session
	r.mu.Unlock()
	r.server.logger.infof("external UoT session opened sid=%s forward=%d target=%s frame_payload_max=%d",
		session.shortID(), binding.forwardID, target, negotiated)
	return session, false, nil
}

func (r *sessionRegistry) reap() {
	now := time.Now()
	grace := time.Duration(r.server.config.sessionGraceSeconds * float64(time.Second))
	doomed := make([]*uotSession, 0)
	r.mu.Lock()
	for id, session := range r.sessions {
		if session.graceExpired(now, grace) {
			delete(r.sessions, id)
			doomed = append(doomed, session)
		}
	}
	r.mu.Unlock()
	for _, session := range doomed {
		stats := session.snapshotStats()
		r.server.logger.infof("external UoT session closed sid=%s udp_in=%d udp_out=%d queue_drops=%d oversize_drops=%d reattaches=%d",
			session.shortID(), stats.udpIn, stats.udpOut, stats.queueDrops, stats.oversizeDrop, stats.reattaches)
		session.close()
	}
}

func (r *sessionRegistry) closeAll() {
	r.mu.Lock()
	sessions := make([]*uotSession, 0, len(r.sessions))
	for id, session := range r.sessions {
		delete(r.sessions, id)
		sessions = append(sessions, session)
	}
	r.mu.Unlock()
	for _, session := range sessions {
		session.close()
	}
}

type sidecarServer struct {
	config    sidecarConfig
	logger    *sidecarLogger
	registry  *sessionRegistry
	listener  net.Listener
	udpDialer net.Dialer

	mu       sync.Mutex
	carriers map[net.Conn]struct{}
	closed   bool
	wg       sync.WaitGroup
}

func newSidecarServer(config sidecarConfig, logger *sidecarLogger) *sidecarServer {
	server := &sidecarServer{
		config:   config,
		logger:   logger,
		carriers: make(map[net.Conn]struct{}),
	}
	server.registry = newSessionRegistry(server)
	return server
}

func (s *sidecarServer) dialer() *net.Dialer {
	s.udpDialer = net.Dialer{Timeout: time.Duration(s.config.udpConnectTimeoutSeconds * float64(time.Second))}
	return &s.udpDialer
}

func (s *sidecarServer) start() error {
	listener, err := net.Listen("tcp", net.JoinHostPort(s.config.listenHost, fmt.Sprintf("%d", s.config.listenPort)))
	if err != nil {
		return err
	}
	s.listener = listener
	go s.acceptLoop()
	go s.reaperLoop()
	return nil
}

func (s *sidecarServer) address() string {
	if s.listener == nil {
		return ""
	}
	return s.listener.Addr().String()
}

func (s *sidecarServer) acceptLoop() {
	for {
		carrier, err := s.listener.Accept()
		if err != nil {
			if isClosedError(err) {
				return
			}
			s.logger.warnf("external UoT accept failed: %v", err)
			return
		}
		s.mu.Lock()
		if s.closed {
			s.mu.Unlock()
			_ = carrier.Close()
			return
		}
		s.carriers[carrier] = struct{}{}
		s.mu.Unlock()
		s.wg.Add(1)
		go func() {
			defer s.wg.Done()
			s.handleConnection(carrier)
		}()
	}
}

func (s *sidecarServer) reaperLoop() {
	interval := time.Duration(minFloat(1.0, maxFloat(0.05, s.config.sessionGraceSeconds/4)) * float64(time.Second))
	if interval <= 0 {
		interval = time.Second
	}
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for range ticker.C {
		s.mu.Lock()
		closed := s.closed
		s.mu.Unlock()
		if closed {
			return
		}
		s.registry.reap()
	}
}

func minFloat(a float64, b float64) float64 {
	if a < b {
		return a
	}
	return b
}

func maxFloat(a float64, b float64) float64 {
	if a > b {
		return a
	}
	return b
}

func (s *sidecarServer) releaseCarrier(carrier net.Conn) {
	s.mu.Lock()
	delete(s.carriers, carrier)
	s.mu.Unlock()
}

func (s *sidecarServer) rejectionAck(sessionID [sessionIDBytes]byte, reason string) frame {
	payload := map[string]any{
		"ok":                false,
		"frame_payload_max": 0,
		"queue_packets":     s.config.queuePackets,
		"error":             reason,
	}
	return frame{msgType: msgHelloAck, session: sessionID, payload: encodeJSON(payload)}
}

func (s *sidecarServer) acceptAck(sessionID [sessionIDBytes]byte, session *uotSession, reattached bool) frame {
	marker := "OKN"
	if reattached {
		marker = "OKR"
	}
	payload := map[string]any{
		"ok":                true,
		"frame_payload_max": session.framePayloadMax,
		"queue_packets":     s.config.queuePackets,
		"session":           marker,
	}
	return frame{msgType: msgHelloAck, session: sessionID, payload: encodeJSON(payload)}
}

func (s *sidecarServer) writeRejection(carrier net.Conn, sessionID [sessionIDBytes]byte, reason string) {
	s.logger.infof("external UoT connection rejected: %s", reason)
	_ = carrier.SetWriteDeadline(time.Now().Add(time.Duration(s.config.idleTimeoutSeconds * float64(time.Second))))
	if err := writeFrame(carrier, s.rejectionAck(sessionID, reason), s.config.maxFrameBytes); err != nil {
		s.logger.debugf("external UoT rejection ack failed: %v", err)
	}
}

func (s *sidecarServer) handleConnection(carrier net.Conn) {
	defer func() {
		_ = carrier.Close()
		s.releaseCarrier(carrier)
	}()
	var session *uotSession
	errorSessionID := [sessionIDBytes]byte{}
	readTimeout := time.Duration(s.config.idleTimeoutSeconds * float64(time.Second))
	_ = carrier.SetReadDeadline(time.Now().Add(readTimeout))
	hello, err := readFrame(carrier, s.config.maxFrameBytes)
	if err != nil {
		if isProtocolFailure(err) {
			s.writeRejection(carrier, errorSessionID, err.Error())
			return
		}
		if isTimeout(err) {
			s.logger.warnf("external UoT carrier closed without HELLO: no frame within %s", readTimeout)
			return
		}
		s.logger.debugf("external UoT carrier closed before HELLO: %v", err)
		return
	}
	errorSessionID = hello.session
	helloData, err := parseHelloPayload(hello)
	if err != nil {
		s.writeRejection(carrier, errorSessionID, err.Error())
		return
	}
	binding, present := s.config.forwards[helloData.forwardID]
	if !present {
		s.writeRejection(carrier, errorSessionID, "forward is not allowed")
		return
	}
	if !binding.verify(helloData.token, helloData.target) {
		s.writeRejection(carrier, errorSessionID, "authentication or target binding failed")
		return
	}
	announced, reattached, err := s.registry.announce(hello.session, binding, helloData.target, helloData.framePayloadMax)
	if err != nil {
		if isProtocolFailure(err) {
			s.writeRejection(carrier, errorSessionID, err.Error())
			return
		}
		// The Python sidecar stays silent when the UDP target cannot be dialled.
		s.logger.warnf("external UoT session target %s unreachable: %v", helloData.target, err)
		return
	}
	session = announced
	session.attach(carrier)
	_ = carrier.SetWriteDeadline(time.Now().Add(readTimeout))
	if err := writeFrame(carrier, s.acceptAck(hello.session, session, reattached), s.config.maxFrameBytes); err != nil {
		session.detach(carrier)
		s.logger.debugf("external UoT hello ack failed sid=%s: %v", session.shortID(), err)
		return
	}
	s.logger.infof("external UoT carrier attached sid=%s forward=%d session=%s", session.shortID(), helloData.forwardID, ackMarker(reattached))

	stop := make(chan struct{})
	readerErr := make(chan error, 1)
	writerDone := make(chan struct{})
	go func() { readerErr <- session.carrierReader(carrier) }()
	go func() {
		defer close(writerDone)
		if err := session.carrierWriter(carrier, stop); err != nil {
			s.logger.debugf("external UoT carrier writer stopped sid=%s: %v", session.shortID(), err)
		}
	}()
	var firstErr error
	select {
	case firstErr = <-readerErr:
	case <-writerDone:
	}
	close(stop)
	<-writerDone
	if isProtocolFailure(firstErr) {
		s.writeRejection(carrier, session.id, firstErr.Error())
	}
	session.detach(carrier)
	stats := session.snapshotStats()
	s.logger.debugf("external UoT carrier detached sid=%s udp_in=%d udp_out=%d queue_drops=%d oversize_drops=%d reattaches=%d",
		session.shortID(), stats.udpIn, stats.udpOut, stats.queueDrops, stats.oversizeDrop, stats.reattaches)
}

func ackMarker(reattached bool) string {
	if reattached {
		return "OKR"
	}
	return "OKN"
}

func (s *sidecarServer) close() {
	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		return
	}
	s.closed = true
	listener := s.listener
	carriers := make([]net.Conn, 0, len(s.carriers))
	for carrier := range s.carriers {
		carriers = append(carriers, carrier)
	}
	s.mu.Unlock()
	if listener != nil {
		_ = listener.Close()
	}
	for _, carrier := range carriers {
		_ = carrier.Close()
	}
	s.registry.closeAll()
	s.wg.Wait()
}
