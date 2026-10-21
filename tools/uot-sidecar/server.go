package main

// External UoT sidecar server: authenticated UDP over TCP relay, wire and
// behaviour compatible with sidecar-agent/leikwan_external_uot.py.
//
// One session owns one connected UDP socket. A carrier reconnect with the same
// session id reuses that socket during the grace period, which preserves the
// target-visible source port. DATA and UDP_DATA are independent directions; the
// relay never waits for a response before forwarding the next datagram.

import (
	"context"
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
	queueBlocked uint64
	queueTimeout uint64
	oversizeDrop uint64
	reattaches   uint64
}

type uotSession struct {
	reliable     *reliableDatagrams
	carrierRunMu sync.Mutex
	server       *sidecarServer
	// config is the snapshot this session was admitted under. It is immutable
	// and supplies the session's frame limits and timers for its whole life.
	// Authorisation is NOT frozen with it: every successful reload re-verifies
	// the session's HELLO credentials against the new snapshot and closes the
	// session when they no longer verify (sessionRegistry.revokeUnauthorized).
	config          *sidecarConfig
	id              [sessionIDBytes]byte
	binding         forwardBinding
	target          string
	framePayloadMax int
	tuner           *adaptiveTuner
	outbound        chan frame
	budget          *datagramBudget
	ctx             context.Context
	cancel          context.CancelFunc
	udpConn         *net.UDPConn
	udpDone         chan struct{}
	// meter is the forward's counters and rate limiter, shared by all of the
	// forward's sessions.
	meter *forwardMeter

	mu            sync.Mutex
	carrier       net.Conn
	detachedAt    time.Time
	closed        bool
	seq           uint64
	lastClientSeq uint64
	stats         sessionStats
	// helloToken is the token of the latest HELLO this session was admitted or
	// reattached with. With binding.forwardID and target it is the credential
	// a reload re-verifies. It is only written with the registry lock held.
	helloToken string
}

// newUoTSession binds a new session to one configuration snapshot: every
// limit and timeout it uses for its whole life comes from that snapshot, so a
// concurrent reload can never be observed half-applied by this session.
func newUoTSession(config *sidecarConfig, server *sidecarServer, id [sessionIDBytes]byte, binding forwardBinding, target string, framePayloadMax int) *uotSession {
	ctx, cancel := context.WithCancel(context.Background())
	return &uotSession{
		ctx: ctx, cancel: cancel, budget: newDatagramBudget(datagramQueueBytes),
		server:          server,
		config:          config,
		id:              id,
		binding:         binding,
		target:          target,
		framePayloadMax: framePayloadMax,
		meter:           server.meters.forward(binding.forwardID),
		tuner:           newAdaptiveTuner(framePayloadMax, config.queuePackets, config.framePayloadMax),
		outbound:        make(chan frame, config.queuePackets),
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
	recvErr := udpConn.SetReadBuffer(16 << 20)
	sendErr := udpConn.SetWriteBuffer(16 << 20)
	recv, send, bufferErr := datagramSocketBuffers(udpConn)
	s.server.logger.infof("external UoT socket sid=%s rcvbuf=%d sndbuf=%d buffer_error=%t socket_receive_drop=unknown", s.shortID(), recv, send, recvErr != nil || sendErr != nil || bufferErr != nil)
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
			if !s.enqueueUDP(buffer[:count]) && s.reliable != nil {
				// Reliable admission failure is terminal. Continuing to read
				// generated a warning for every subsequent datagram forever.
				// Do not wait for udpDone from this reader itself.
				s.closeSockets()
				return
			}
		}
	}
}

// credentials returns the forward, token and target the session's latest HELLO
// presented.
func (s *uotSession) credentials() (int64, string, string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.binding.forwardID, s.helloToken, s.target
}

func (s *uotSession) setHelloToken(token string) {
	s.mu.Lock()
	s.helloToken = token
	s.mu.Unlock()
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
	if s.isClosed() {
		return false
	}
	if len(payload) > s.framePayloadMax {
		s.mu.Lock()
		s.stats.oversizeDrop++
		s.mu.Unlock()
		s.tuner.observePressure()
		if s.reliable != nil {
			s.reliable.fail(errors.New("reliable payload too large"))
			s.cancel()
		}
		return false
	}
	s.tuner.observePayload(len(payload))
	// The forward's target -> client limiter runs before admission. Waiting
	// blocks only this session's UDP reader; the socket receive buffer absorbs
	// the burst meanwhile.
	if err := s.meter.fromTarget.wait(s.ctx, len(payload)); err != nil {
		if errors.Is(err, errRateLimited) {
			s.meter.countRateLimitedDrop()
			// A policy drop is not an admission failure: report it as handled
			// so the UDP reader does not tear a reliable session down.
			return true
		}
		return false
	}
	if s.reliable != nil {
		if err := s.reliable.enqueue(s.ctx, payload); err != nil {
			s.server.logger.warnf("external UoT reliable session failed sid=%s reason=%v", s.shortID(), err)
			s.cancel()
			if c := s.currentCarrier(); c != nil {
				c.Close()
			}
			return false
		}
		s.meter.countFromTarget(len(payload))
		return true
	}
	ctx, cancel := context.WithTimeout(s.ctx, datagramQueueDeadline)
	defer cancel()
	blocked, err := s.budget.reserve(ctx, len(payload))
	if blocked {
		s.mu.Lock()
		s.stats.queueBlocked++
		s.mu.Unlock()
	}
	if err == nil {
		outgoing := frame{msgType: msgUDPData, session: s.id, seq: s.nextSequence(), payload: append([]byte(nil), payload...)}
		admitted := false
		select {
		case s.outbound <- outgoing:
			admitted = true
		default:
		}
		if !admitted {
			if !blocked {
				s.mu.Lock()
				s.stats.queueBlocked++
				s.mu.Unlock()
			}
			select {
			case s.outbound <- outgoing:
				admitted = true
			case <-ctx.Done():
				err = ctx.Err()
			}
		}
		if admitted {
			s.mu.Lock()
			s.stats.udpIn++
			s.mu.Unlock()
			s.meter.countFromTarget(len(payload))
			return true
		}
		s.budget.release(len(payload))
	}
	if errors.Is(err, context.Canceled) {
		return false
	}
	s.mu.Lock()
	s.stats.queueDrops++
	s.stats.queueTimeout++
	s.mu.Unlock()
	s.server.logger.warnf("external UoT queue_timeout sid=%s", s.shortID())
	s.tuner.observePressure()
	return false
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
	// The forward's client -> target limiter. Waiting here stalls the carrier
	// reader, which is the intended TCP backpressure on the client; a datagram
	// that would wait longer than rateLimitMaxWait is dropped instead.
	if err := s.meter.toTarget.wait(s.ctx, len(payload)); err != nil {
		if errors.Is(err, errRateLimited) {
			s.meter.countRateLimitedDrop()
			return nil
		}
		return connectionError{err: fmt.Errorf("session closed")}
	}
	if _, err := s.udpConn.Write(payload); err != nil {
		return connectionError{err: err}
	}
	s.meter.countToTarget(len(payload))
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
	if s.closed {
		s.mu.Unlock()
		_ = carrier.Close()
		return false
	}
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

// graceExpired reports whether a detached session has outlived the grace
// period of the snapshot it was admitted under, so a reload cannot shorten or
// extend the life of a session that is already running.
func (s *uotSession) graceExpired(now time.Time) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.closed {
		return true
	}
	grace := time.Duration(s.config.sessionGraceSeconds * float64(time.Second))
	return s.carrier == nil && !s.detachedAt.IsZero() && now.Sub(s.detachedAt) >= grace
}

func (s *uotSession) close() {
	s.closeSockets()
	if s.udpConn != nil {
		<-s.udpDone
	}
}

func (s *uotSession) closeSockets() {
	s.closeSocketsForCarrier(nil)
}

// A stale carrier must not terminate a newer attachment of this session.
// nil is reserved for unconditional shutdown/reaping.
func (s *uotSession) closeSocketsForCarrier(expected net.Conn) bool {
	s.mu.Lock()
	if s.closed || (expected != nil && s.carrier != expected) {
		s.mu.Unlock()
		return false
	}
	s.closed = true
	s.cancel()
	carrier := s.carrier
	s.carrier = nil
	s.mu.Unlock()
	if carrier != nil {
		_ = carrier.Close()
	}
	if s.udpConn != nil {
		_ = s.udpConn.Close()
	}
	return true
}

// carrierReader consumes one carrier connection until it ends.
func (s *uotSession) carrierReader(carrier net.Conn) error {
	for {
		_ = carrier.SetReadDeadline(time.Now().Add(time.Duration(s.config.idleTimeoutSeconds * float64(time.Second))))
		incoming, err := readFrame(carrier, s.config.maxFrameBytes)
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
			s.server.registry.closeCarrierSession(s, carrier)
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
	keepalive := time.Duration(s.config.keepaliveSeconds * float64(time.Second))
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
			s.budget.release(len(outgoing.payload))
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
	_ = carrier.SetWriteDeadline(time.Now().Add(time.Duration(s.config.idleTimeoutSeconds * float64(time.Second))))
	if err := writeFrame(carrier, outgoing, s.config.maxFrameBytes); err != nil {
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

// CLOSE means the client is finished, not a recoverable carrier outage.
// Release the slot now; HELLO-only probes otherwise consume the full grace
// period and can prevent business sessions and lease renewals from starting.
func (r *sessionRegistry) closeCarrierSession(session *uotSession, carrier net.Conn) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.sessions[session.id] == session && session.closeSocketsForCarrier(carrier) {
		delete(r.sessions, session.id)
	}
}

// liveSession returns the session established under this id when it is still
// open and belongs to the requested forward.
func (r *sessionRegistry) liveSession(id [sessionIDBytes]byte, forwardID int64) *uotSession {
	r.mu.Lock()
	defer r.mu.Unlock()
	session, present := r.sessions[id]
	if !present || session.isClosed() || session.binding.forwardID != forwardID {
		return nil
	}
	return session
}

// announceMode installs the session for one HELLO using exactly the snapshot
// that authorised it. token is that HELLO's token: it becomes the credential a
// later reload re-verifies, and a reattach replaces it with the token the
// reattaching HELLO has just proved valid.
func (r *sessionRegistry) announceMode(snapshot *sidecarConfig, id [sessionIDBytes]byte, binding forwardBinding, target string, requestedMax int, mode string, token string) (*uotSession, bool, error) {
	r.mu.Lock()
	existing, present := r.sessions[id]
	if present && !existing.isClosed() {
		if existing.binding.forwardID != binding.forwardID || existing.target != target || (existing.reliable != nil) != (mode == reliableDatagramMode) {
			r.mu.Unlock()
			return nil, false, protocolError{reason: "session binding mismatch"}
		}
		existing.setHelloToken(token)
		r.mu.Unlock()
		return existing, true, nil
	}
	if len(r.sessions) >= snapshot.sessionLimit {
		r.mu.Unlock()
		return nil, false, protocolError{reason: "session limit reached"}
	}
	negotiated := minInt(requestedMax, snapshot.framePayloadMax)
	if negotiated < minFramePayload {
		r.mu.Unlock()
		return nil, false, protocolError{reason: "no usable frame payload size"}
	}
	session := newUoTSession(snapshot, r.server, id, binding, target, negotiated)
	session.helloToken = token
	if mode == reliableDatagramMode {
		session.reliable = newReliableDatagrams()
	}
	r.mu.Unlock()
	// The UDP dialer is built from the same snapshot, so a reloaded
	// udp_connect_timeout_seconds is applied whole rather than half.
	if err := session.open(r.server.dialer(snapshot)); err != nil {
		return nil, false, connectionError{err: err}
	}
	r.mu.Lock()
	// Opening a UDP socket must not hold the registry lock. Another HELLO may
	// have installed the same session while open was in progress; preserve that
	// session's sequence state and close our unused socket.
	if existing, present := r.sessions[id]; present && !existing.isClosed() {
		mismatch := existing.binding.forwardID != binding.forwardID || existing.target != target || (existing.reliable != nil) != (mode == reliableDatagramMode)
		if !mismatch {
			existing.setHelloToken(token)
		}
		r.mu.Unlock()
		session.close()
		if mismatch {
			return nil, false, protocolError{reason: "session binding mismatch"}
		}
		return existing, true, nil
	}
	if len(r.sessions) >= snapshot.sessionLimit {
		r.mu.Unlock()
		session.close()
		return nil, false, protocolError{reason: "session limit reached"}
	}
	r.sessions[id] = session
	r.mu.Unlock()
	r.server.logger.infof("external UoT session opened sid=%s forward=%d target=%s frame_payload_max=%d",
		session.shortID(), binding.forwardID, target, negotiated)
	return session, false, nil
}

func (r *sessionRegistry) reap() {
	now := time.Now()
	doomed := make([]*uotSession, 0)
	r.mu.Lock()
	for id, session := range r.sessions {
		if session.graceExpired(now) {
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

// revokeUnauthorized closes every session whose HELLO credentials no longer
// verify against snapshot, carriers included, and returns how many it closed.
// The check is exactly the one a new HELLO gets (authorizeCredentials), so a
// forward that a reload removed, or whose secret or static token it rotated,
// loses its live sessions while every other session is left untouched.
func (r *sessionRegistry) revokeUnauthorized(snapshot *sidecarConfig) int {
	doomed := make([]*uotSession, 0)
	r.mu.Lock()
	for id, session := range r.sessions {
		forwardID, token, target := session.credentials()
		if _, err := authorizeCredentials(snapshot, forwardID, token, target); err != nil {
			delete(r.sessions, id)
			doomed = append(doomed, session)
		}
	}
	r.mu.Unlock()
	for _, session := range doomed {
		r.logRevoked(session)
		session.close()
	}
	return len(doomed)
}

// revoke removes and closes one session if it is still the registered one.
func (r *sessionRegistry) revoke(session *uotSession) {
	r.mu.Lock()
	if r.sessions[session.id] == session {
		delete(r.sessions, session.id)
	}
	r.mu.Unlock()
	r.logRevoked(session)
	session.close()
}

func (r *sessionRegistry) logRevoked(session *uotSession) {
	r.server.logger.infof("external UoT session revoked sid=%s forward=%d reason=credentials-no-longer-valid",
		session.shortID(), session.binding.forwardID)
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
	// config is the published snapshot store; every request loads it once and
	// then uses that value for its whole lifetime.
	config   *configStore
	logger   *sidecarLogger
	registry *sessionRegistry
	meters   *meterRegistry
	listener net.Listener

	// enforceMu serialises enforceSnapshot: the signal loop and the marker loop
	// can both trigger a reload.
	enforceMu sync.Mutex

	mu       sync.Mutex
	carriers map[net.Conn]struct{}
	closed   bool
	wg       sync.WaitGroup
}

func newSidecarServer(config *configStore, logger *sidecarLogger) *sidecarServer {
	server := &sidecarServer{
		config:   config,
		logger:   logger,
		carriers: make(map[net.Conn]struct{}),
	}
	server.registry = newSessionRegistry(server)
	server.meters = newMeterRegistry(func(forwardID int64) float64 {
		return server.config.snapshot().forwards[forwardID].rateLimitMbps
	})
	return server
}

// enforceSnapshot applies the published configuration to state that outlives a
// HELLO: rate limits are updated in place and sessions whose credentials no
// longer verify are closed. It runs after every successful reload and always
// reads the latest snapshot rather than the one a particular reload produced,
// so two overlapping reloads cannot leave the older one's limits applied last.
func (s *sidecarServer) enforceSnapshot() {
	s.enforceMu.Lock()
	defer s.enforceMu.Unlock()
	snapshot := s.config.snapshot()
	s.meters.applyLimits(snapshot)
	if revoked := s.registry.revokeUnauthorized(snapshot); revoked > 0 {
		s.logger.infof("external UoT reload revoked sessions=%d", revoked)
	}
}

// dialer builds the UDP dialer for one HELLO from the snapshot that authorised
// that HELLO, so a reloaded value is never applied half-way.
func (s *sidecarServer) dialer(snapshot *sidecarConfig) *net.Dialer {
	return &net.Dialer{Timeout: time.Duration(snapshot.udpConnectTimeoutSeconds * float64(time.Second))}
}

func (s *sidecarServer) start() error {
	snapshot := s.config.snapshot()
	listener, err := net.Listen("tcp", net.JoinHostPort(snapshot.listenHost, fmt.Sprintf("%d", snapshot.listenPort)))
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
	interval := time.Duration(minFloat(1.0, maxFloat(0.05, s.config.snapshot().sessionGraceSeconds/4)) * float64(time.Second))
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

func (s *sidecarServer) rejectionAck(snapshot *sidecarConfig, sessionID [sessionIDBytes]byte, reason string) frame {
	payload := map[string]any{
		"ok":                false,
		"frame_payload_max": 0,
		"queue_packets":     snapshot.queuePackets,
		"error":             reason,
	}
	return frame{msgType: msgHelloAck, session: sessionID, payload: encodeJSON(payload)}
}

func (s *sidecarServer) acceptAck(snapshot *sidecarConfig, sessionID [sessionIDBytes]byte, session *uotSession, reattached bool) frame {
	marker := "OKN"
	if reattached {
		marker = "OKR"
	}
	payload := map[string]any{
		"ok":                true,
		"frame_payload_max": session.framePayloadMax,
		"queue_packets":     snapshot.queuePackets,
		"session":           marker,
	}
	if session.reliable != nil {
		payload["delivery_mode"] = reliableDatagramMode
	}
	return frame{msgType: msgHelloAck, session: sessionID, payload: encodeJSON(payload)}
}

func (s *sidecarServer) writeRejection(snapshot *sidecarConfig, carrier net.Conn, sessionID [sessionIDBytes]byte, reason string) {
	s.logger.infof("external UoT connection rejected: %s", reason)
	_ = carrier.SetWriteDeadline(time.Now().Add(time.Duration(snapshot.idleTimeoutSeconds * float64(time.Second))))
	if err := writeFrame(carrier, s.rejectionAck(snapshot, sessionID, reason), snapshot.maxFrameBytes); err != nil {
		s.logger.debugf("external UoT rejection ack failed: %v", err)
	}
}

// authorizeCredentials is the single credential check: the forward must exist
// in snapshot and its binding must verify token for target. A new HELLO, a
// carrier reattach and the post-reload revocation sweep all use it, so a
// session never outlives credentials that a fresh HELLO would be refused with.
func authorizeCredentials(snapshot *sidecarConfig, forwardID int64, token string, target string) (forwardBinding, error) {
	binding, present := snapshot.forwards[forwardID]
	if !present {
		return forwardBinding{}, protocolError{reason: "forward is not allowed"}
	}
	if !binding.verify(token, target) {
		return forwardBinding{}, protocolError{reason: "authentication or target binding failed"}
	}
	return binding, nil
}

// authorizeHello selects the binding that authorises one HELLO. A reattach is
// authorised exactly like a new session, against the snapshot and never
// against the binding a live session was once admitted under, so a removed or
// rotated forward cannot keep a session alive by reconnecting.
func (s *sidecarServer) authorizeHello(snapshot *sidecarConfig, hello helloPayload) (forwardBinding, error) {
	return authorizeCredentials(snapshot, hello.forwardID, hello.token, hello.target)
}

func (s *sidecarServer) handleConnection(carrier net.Conn) {
	defer func() {
		_ = carrier.Close()
		s.releaseCarrier(carrier)
	}()
	// Take the configuration exactly once for this connection: a reload landing
	// while the HELLO is negotiated must not be observable half-way through it.
	snapshot := s.config.snapshot()
	var session *uotSession
	errorSessionID := [sessionIDBytes]byte{}
	readTimeout := time.Duration(snapshot.idleTimeoutSeconds * float64(time.Second))
	_ = carrier.SetReadDeadline(time.Now().Add(readTimeout))
	hello, err := readFrame(carrier, snapshot.maxFrameBytes)
	if err != nil {
		if isProtocolFailure(err) {
			s.writeRejection(snapshot, carrier, errorSessionID, err.Error())
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
		s.writeRejection(snapshot, carrier, errorSessionID, err.Error())
		return
	}
	binding, err := s.authorizeHello(snapshot, helloData)
	if err != nil {
		s.writeRejection(snapshot, carrier, errorSessionID, err.Error())
		return
	}
	announced, reattached, err := s.registry.announceMode(snapshot, hello.session, binding, helloData.target, helloData.framePayloadMax, helloData.deliveryMode, helloData.token)
	if err != nil {
		if isProtocolFailure(err) {
			s.writeRejection(snapshot, carrier, errorSessionID, err.Error())
			return
		}
		// The Python sidecar stays silent when the UDP target cannot be dialled.
		s.logger.warnf("external UoT session target %s unreachable: %v", helloData.target, err)
		return
	}
	// The HELLO was authorised against the snapshot this connection took when
	// it was accepted. If a reload has published a newer one since, that
	// reload's revocation sweep may have run before this session was
	// registered, so check the current snapshot again. From here on the session
	// is registered and every later sweep sees it.
	if current := s.config.snapshot(); current != snapshot {
		if _, err := authorizeCredentials(current, helloData.forwardID, helloData.token, helloData.target); err != nil {
			s.registry.revoke(announced)
			s.writeRejection(snapshot, carrier, errorSessionID, err.Error())
			return
		}
	}
	session = announced
	if session.reliable != nil {
		// Publish before waiting: a newer attachment must close both an active
		// carrier and a pending replacement, not wait behind an unclosed stream.
		session.attach(carrier)
		session.carrierRunMu.Lock()
		defer session.carrierRunMu.Unlock()
		if session.currentCarrier() != carrier {
			return
		}
	} else {
		session.attach(carrier)
	}
	if session.currentCarrier() != carrier {
		return
	}
	_ = carrier.SetWriteDeadline(time.Now().Add(readTimeout))
	if err := writeFrame(carrier, s.acceptAck(snapshot, hello.session, session, reattached), snapshot.maxFrameBytes); err != nil {
		session.detach(carrier)
		s.logger.debugf("external UoT hello ack failed sid=%s: %v", session.shortID(), err)
		return
	}
	s.logger.infof("external UoT carrier attached sid=%s forward=%d session=%s", session.shortID(), helloData.forwardID, ackMarker(reattached))

	if session.reliable != nil {
		err := session.runReliableCarrier(carrier)
		session.detach(carrier)
		s.logger.infof("external UoT reliable sid=%s stats=%s carrier_error=%v", session.shortID(), encodeJSON(map[string]any{"reliable": session.reliable.snapshot()}), err)
		return
	}
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
		s.writeRejection(snapshot, carrier, session.id, firstErr.Error())
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
