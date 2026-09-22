package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/binary"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"os/signal"
	"strings"
	"sync"
	"syscall"
	"time"
)

const keepPayloadHex = "\x00\x00\xa4\x64\x34\xc9\xb6\x2b\x82\xee\xd2\xa8\x7f\xaa\x6a\x5f\x45\xac"

// UDP candidate probing uses Cloudflare DNS because it provides a bounded,
// protocol-aware response. TCP probing is deliberately independent: a TCP
// connect to the ubiquitous HTTPS listener is enough to establish reachability
// and must not depend on an application response header.
const defaultProbeTarget = "1.1.1.1:53"
const defaultTCPProbeTarget = "1.1.1.1:443"

var runtimeBuildVersion = "development"

const (
	datagramCandidateFirst         = byte(0x20)
	datagramCandidateLimit         = byte(0x40)
	datagramCandidateTimeout       = 250 * time.Millisecond
	datagramControlReplyTimeout    = 3 * time.Second
	datagramAssociationSetupBudget = time.Duration(datagramCandidateLimit-datagramCandidateFirst)*datagramCandidateTimeout + datagramControlReplyTimeout + 2*time.Second
)

type Config struct {
	ForwardID  int    `json:"forward_id"`
	ListenHost string `json:"listen_host"`
	ListenPort int    `json:"listen_port"`
	Target     string `json:"target"`
	// Forward-local, bounded operator quarantine. It can only remove candidates
	// from authenticated grants; it never creates or extends authorization.
	CandidateQuarantineHashes   []string `json:"candidate_quarantine_hashes,omitempty"`
	CandidateQuarantineUntil    string   `json:"candidate_quarantine_until,omitempty"`
	CandidateQuarantineProtocol string   `json:"candidate_quarantine_protocol,omitempty"`
	// EgressInterface* describe an optional user-selected underlay. When
	// present, provider TCP/UDP sockets are bound before connect.
	EgressInterface      string `json:"egress_interface"`
	EgressInterfaceIndex int    `json:"egress_interface_index"`
	EgressLocalAddress   string `json:"egress_local_address"`
	// NativeSocks5 exposes a local standard SOCKS5 facade while retaining the
	// existing Ultra relay protocol on the provider leg. Ordinary Ultra
	// forwards leave this disabled and keep their fixed-target behavior.
	NativeSocks5             bool                `json:"native_socks5"`
	NativeRoutePolicy        string              `json:"native_route_policy"`
	NativePortMin            int                 `json:"native_port_min"`
	NativeFilterProfile      string              `json:"native_filter_profile"`
	ProbeTarget              string              `json:"probe_target"`
	ControlSocket            string              `json:"control_socket"`
	PolicyGeneration         string              `json:"policy_generation"`
	TargetDigest             string              `json:"target_digest"`
	Transport                string              `json:"transport"`
	MaxTCPConnections        int                 `json:"max_tcp_connections"`
	UDPPrewarm               bool                `json:"udp_prewarm"`
	UDPKeepaliveIntervalSecs int                 `json:"udp_keepalive_interval_seconds"`
	UDPQueuePackets          int                 `json:"udp_queue_packets"`
	UDPMaxAssociations       int                 `json:"udp_max_associations"`
	UDPAssociationIdleSecs   int                 `json:"udp_association_idle_seconds"`
	UDPSetupWorkers          int                 `json:"udp_setup_workers"`
	ConnectTimeoutSecs       int                 `json:"connect_timeout_seconds"`
	LocationCachePath        string              `json:"location_cache_path"`
	LocationLockPath         string              `json:"location_lock_path"`
	StatusPath               string              `json:"status_path"`
	AutoSelect               bool                `json:"auto_select"`
	ProbeDurationSecs        int                 `json:"probe_duration_seconds"`
	ProbeTimeoutMillis       int                 `json:"probe_timeout_ms"`
	ProbeWorkers             int                 `json:"probe_workers"`
	ProbeSamples             int                 `json:"probe_samples"`
	ProbeIntervalMillis      int                 `json:"probe_interval_ms"`
	ZoneAliases              map[string][]string `json:"zone_aliases"`
	// ExternalUoT is an explicit opt-in UDP-over-TCP path which terminates at
	// an operator-owned sidecar over the existing Ultra command-1 carrier.
	// Native command-3 UDP remains the default when this is disabled.
	ExternalUoT ExternalUoTConfig `json:"external_uot"`
}

func probeDuration(config Config) time.Duration {
	value := config.ProbeDurationSecs
	if value < 1 || value > 300 {
		value = 30
	}
	return time.Duration(value) * time.Second
}

type Entry struct {
	EntryRef        string
	IngressZone     string
	DestinationZone string
	IP              string
	Port            int
	User            string
	Credential      string
}

func parseTarget(value string) (string, int, error) {
	host, portText, err := net.SplitHostPort(value)
	if err != nil {
		return "", 0, err
	}
	var port int
	if _, err := fmt.Sscanf(portText, "%d", &port); err != nil || port < 1 || port > 65535 {
		return "", 0, errors.New("target port invalid")
	}
	return host, port, nil
}

func credentialChecksum(secret string) byte {
	if len(secret) != 16 {
		return 0
	}
	return byte((0x74 - int(secret[6]-'0') - int(secret[10]-'0')) & 0xff)
}

func appendAddress(buffer *bytes.Buffer, host string, port int) error {
	ip := net.ParseIP(host)
	if ip != nil {
		if v4 := ip.To4(); v4 != nil {
			buffer.WriteByte(1)
			buffer.Write(v4)
		} else {
			buffer.WriteByte(4)
			buffer.Write(ip.To16())
		}
	} else {
		if len(host) == 0 || len(host) > 255 {
			return errors.New("target host invalid")
		}
		buffer.WriteByte(3)
		buffer.WriteByte(byte(len(host)))
		buffer.WriteString(host)
	}
	var encoded [2]byte
	binary.BigEndian.PutUint16(encoded[:], uint16(port))
	buffer.Write(encoded[:])
	return nil
}

var currentHandshakeGreeting = []byte{5, 5, 0, 1, 2, 0x80, 0x83, 1}

var legacyHandshakeGreeting = []byte{5, 3, 2, 0x80, 0x83, 1}

func handshakeGreetingOrder(_ ProviderLease) [][]byte {
	// Ordinary Ultra TCP used the three-method LeiGod greeting before the
	// native-outbound work landed. Keep that wire shape first because some
	// existing relays accept the five-method greeting but never attach the
	// resulting stream to the target. The current greeting remains a bounded
	// fallback for upgraded relays.
	return [][]byte{legacyHandshakeGreeting, currentHandshakeGreeting}
}

func nativeHandshakeGreetingOrder(_ ProviderLease) [][]byte {
	// Native SOCKS5 was introduced with the five-method reference greeting.
	// Preserve that preference independently from ordinary fixed-target Ultra;
	// both paths still fall back to the other greeting on a real handshake
	// failure.
	return [][]byte{currentHandshakeGreeting, legacyHandshakeGreeting}
}

// Kept as a compatibility no-op for callers from older tests/builds. Greeting
// order is deliberately not persisted across connections.
func rememberHandshakeGreeting(_ ProviderLease, _ []byte) {}

func buildHandshakeWithGreeting(user, credential string, command byte, host string, port int, greeting []byte) ([]byte, error) {
	if len(user) == 0 || len(user) > 255 || len(credential) == 0 || len(credential) > 255 {
		return nil, errors.New("credential length invalid")
	}
	switch command {
	case 1, 3:
	default:
		return nil, errors.New("unsupported handshake command")
	}
	var buffer bytes.Buffer
	buffer.Write(greeting)
	buffer.WriteByte(byte(len(user)))
	buffer.WriteString(user)
	buffer.WriteByte(byte(len(credential)))
	buffer.WriteString(credential)
	buffer.Write([]byte{5, command, credentialChecksum(credential)})
	if err := appendAddress(&buffer, host, port); err != nil {
		return nil, err
	}
	return buffer.Bytes(), nil
}

func buildHandshake(user, credential string, command byte, host string, port int) ([]byte, error) {
	greeting := legacyHandshakeGreeting
	if command == 3 {
		greeting = currentHandshakeGreeting
	}
	return buildHandshakeWithGreeting(user, credential, command, host, port, greeting)
}

const streamPreludeSize = 14

type bufferedConn struct {
	net.Conn
	reader             *bufio.Reader
	legacyFinalOnce    sync.Once
	legacyFinalPrefix  []byte
	legacyFinalPending error
}

func (conn *bufferedConn) Read(buffer []byte) (int, error) {
	conn.legacyFinalOnce.Do(conn.consumeLegacyFinal)
	if len(conn.legacyFinalPrefix) > 0 {
		count := copy(buffer, conn.legacyFinalPrefix)
		conn.legacyFinalPrefix = conn.legacyFinalPrefix[count:]
		return count, nil
	}
	if conn.legacyFinalPending != nil {
		err := conn.legacyFinalPending
		conn.legacyFinalPending = nil
		return 0, err
	}
	return conn.reader.Read(buffer)
}

// consumeLegacyFinal handles the delayed command=1 target-connect response.
// LeiGod sends the four-byte auth ACK first, then emits a ten-byte SOCKS-shaped
// success/error frame only after the first payload opens the target connection.
// That control frame must not be exposed to the local application. If the
// first application byte is not the unambiguous 04 01 prefix, all inspected
// bytes are retained verbatim.
func (conn *bufferedConn) consumeLegacyFinal() {
	first, err := conn.reader.ReadByte()
	if err != nil {
		conn.legacyFinalPending = err
		return
	}
	prefix := []byte{first}
	if first != 4 {
		conn.legacyFinalPrefix = prefix
		return
	}
	second, err := conn.reader.ReadByte()
	if err != nil {
		conn.legacyFinalPrefix = prefix
		conn.legacyFinalPending = err
		return
	}
	prefix = append(prefix, second)
	if second != 1 {
		conn.legacyFinalPrefix = prefix
		return
	}
	// A real application stream may begin with 04 01. Bound only the
	// remaining optional-frame inspection so an incomplete application prefix
	// is preserved instead of stalling the stream indefinitely.
	_ = conn.Conn.SetReadDeadline(time.Now().Add(100 * time.Millisecond))
	defer conn.Conn.SetReadDeadline(time.Time{})
	for len(prefix) < 10 {
		item, readErr := conn.reader.ReadByte()
		if readErr != nil {
			conn.legacyFinalPrefix = prefix
			return
		}
		prefix = append(prefix, item)
	}
	if !validLegacyStreamFinal(prefix) {
		conn.legacyFinalPrefix = prefix
		return
	}
	if prefix[2] == 0 {
		conn.legacyFinalPending = errStreamCommandRejected
	}
}

func validStreamPrelude(prelude []byte) bool {
	if len(prelude) != streamPreludeSize || !bytes.Equal(prelude[:8], make([]byte, 8)) {
		return false
	}
	return prelude[8] != 0
}

func readStreamAcks(conn net.Conn) (*bufio.Reader, error) {
	return readStreamAcksWithTimeout(conn, 3*time.Second)
}

func readStreamAcksWithTimeout(conn net.Conn, timeout time.Duration) (*bufio.Reader, error) {
	if timeout <= 0 {
		return nil, context.DeadlineExceeded
	}
	return readStreamAcksUntil(conn, time.Now().Add(timeout))
}

func readStreamAcksUntil(conn net.Conn, deadline time.Time) (*bufio.Reader, error) {
	if time.Until(deadline) <= 0 {
		return nil, context.DeadlineExceeded
	}
	reader := bufio.NewReaderSize(conn, 64*1024)
	if err := conn.SetReadDeadline(deadline); err != nil {
		return nil, err
	}
	first := make([]byte, 2)
	if _, err := io.ReadFull(reader, first); err != nil {
		return nil, err
	}
	if bytes.Equal(first, []byte{5, 2}) {
		// LeiGod command=1 normally ends its response at 05 02 01 00. Older
		// relays appended a ten-byte SOCKS success frame, so inspect (but do not
		// consume) the next bytes briefly and discard that optional frame only
		// when its complete shape is unambiguous.
		second := make([]byte, 2)
		if _, err := io.ReadFull(reader, second); err != nil {
			return nil, err
		}
		if !(bytes.Equal(second, []byte{1, 0}) || bytes.Equal(second, []byte{5, 2})) {
			return nil, errStreamAuthenticationRejected
		}
		if bytes.Equal(second, []byte{1, 0}) {
			optionalDeadline := time.Now().Add(100 * time.Millisecond)
			if optionalDeadline.After(deadline) {
				optionalDeadline = deadline
			}
			_ = conn.SetReadDeadline(optionalDeadline)
			optional, peekErr := reader.Peek(10)
			if peekErr != nil && len(optional) == 0 {
				// An auth ACK followed by FIN/reset is not a usable stream.
				// Retry while no caller bytes have been consumed, rather than
				// handing a known-dead connection to the application.
				var timeout net.Error
				if !errors.As(peekErr, &timeout) || !timeout.Timeout() {
					return nil, peekErr
				}
			}
			if peekErr == nil && validLegacyStreamFinal(optional) {
				_, _ = reader.Discard(10)
				if optional[2] == 0 {
					return nil, errStreamCommandRejected
				}
			}
		}
	} else {
		// The Ultra TCP relay emits eight zero bytes followed by six bytes of
		// stream metadata. The metadata is framing and must not reach the local
		// application. Keep the bufio.Reader so any bytes read past the prelude
		// remain available to copyInbound.
		prelude := make([]byte, streamPreludeSize)
		copy(prelude, first)
		if _, err := io.ReadFull(reader, prelude[len(first):]); err != nil {
			return nil, err
		}
		if !validStreamPrelude(prelude) {
			return nil, errStreamPreludeRejected
		}
		// This relay dialect has no optional SOCKS final frame, but the
		// metadata ACK can still be immediately followed by FIN/reset.
		// Keep that failure in the setup phase, before any caller bytes are
		// consumed. Do not discard early server-first application payload.
		checkDeadline := time.Now().Add(100 * time.Millisecond)
		if checkDeadline.After(deadline) {
			checkDeadline = deadline
		}
		_ = conn.SetReadDeadline(checkDeadline)
		early, peekErr := reader.Peek(1)
		if peekErr != nil && len(early) == 0 {
			var timeout net.Error
			if !errors.As(peekErr, &timeout) || !timeout.Timeout() {
				return nil, peekErr
			}
		}
	}
	if err := conn.SetReadDeadline(time.Time{}); err != nil {
		return nil, err
	}
	return reader, nil
}

func validLegacyStreamFinal(final []byte) bool {
	return len(final) == 10 && final[0] == 4 && final[1] == 1 &&
		final[3] == 0 && final[4] == 1
}

func xorOne(value []byte) []byte {
	result := make([]byte, len(value))
	for index, item := range value {
		result[index] = item ^ 1
	}
	return result
}

func writeAll(writer io.Writer, payload []byte) error {
	for len(payload) > 0 {
		count, err := writer.Write(payload)
		if count < 0 || count > len(payload) {
			return errors.New("writer returned invalid count")
		}
		if count > 0 {
			payload = payload[count:]
		}
		if err != nil {
			return err
		}
		if count == 0 {
			return io.ErrShortWrite
		}
	}
	return nil
}

func writeXor(writer io.Writer, payload []byte) error {
	encoded := xorOne(payload)
	defer clearBytes(encoded)
	return writeAll(writer, encoded)
}

var (
	errStreamAuthenticationRejected   = errors.New("stream authentication rejected")
	errStreamCommandRejected          = errors.New("stream command rejected")
	errStreamPreludeRejected          = errors.New("stream prelude rejected")
	errDatagramControlReplyIncomplete = errors.New("datagram control reply incomplete")
	errDatagramAssociationFailed      = errors.New("datagram association failed")
	// The provider endpoint could not be reached at all. That is a verdict on
	// the endpoint; a connection that is established and then refused is not,
	// and the two are told apart by this sentinel.
	errProviderDial = errors.New("provider connect")
)

// Test-only observation hooks. The production runtime never assigns these;
// the in-process game-sim benchmark uses them to inspect the kernel buffer
// sizes on the sockets the runtime itself configures (4MB datagram buffers).
var (
	testHookLocalUDPListening func(local *net.UDPConn)
	testHookDatagramArmed     func(remote *net.UDPConn)
	testHookRuntimeQueueDepth func(depth int)
)

type handshakePhaseError struct {
	cause error
}

func (err *handshakePhaseError) Error() string {
	return err.cause.Error()
}

func (err *handshakePhaseError) Unwrap() error {
	return err.cause
}

func markHandshakePhase(err error) error {
	if err == nil {
		return nil
	}
	return &handshakePhaseError{cause: err}
}

func isRetryableHandshakeError(err error) bool {
	if err == nil {
		return false
	}
	if errors.Is(err, errStreamAuthenticationRejected) ||
		errors.Is(err, errStreamCommandRejected) ||
		errors.Is(err, errStreamPreludeRejected) ||
		errors.Is(err, errDatagramControlReplyIncomplete) ||
		errors.Is(err, errDatagramAssociationFailed) {
		return true
	}
	var phaseError *handshakePhaseError
	if !errors.As(err, &phaseError) {
		return false
	}
	if errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF) {
		return true
	}
	var networkError net.Error
	return errors.As(err, &networkError) && networkError.Timeout()
}

func dialStreamWithGreeting(lease ProviderLease, targetHost string, targetPort int, timeout time.Duration, greeting []byte) (net.Conn, error) {
	return dialStreamWithGreetingConfig(Config{}, lease, targetHost, targetPort, timeout, greeting)
}

func dialStreamWithGreetingConfig(config Config, lease ProviderLease, targetHost string, targetPort int, timeout time.Duration, greeting []byte) (net.Conn, error) {
	if timeout <= 0 {
		return nil, fmt.Errorf("stream handshake: %w", context.DeadlineExceeded)
	}
	return dialStreamWithGreetingUntilConfig(config, lease, targetHost, targetPort, time.Now().Add(timeout), greeting)
}

func dialStreamWithGreetingUntil(lease ProviderLease, targetHost string, targetPort int, deadline time.Time, greeting []byte) (net.Conn, error) {
	return dialStreamWithGreetingUntilConfig(Config{}, lease, targetHost, targetPort, deadline, greeting)
}

func dialStreamWithGreetingUntilConfig(config Config, lease ProviderLease, targetHost string, targetPort int, deadline time.Time, greeting []byte) (net.Conn, error) {
	remaining := time.Until(deadline)
	if remaining <= 0 {
		return nil, fmt.Errorf("stream handshake: %w", context.DeadlineExceeded)
	}
	conn, err := dialProviderTCP(config, net.JoinHostPort(lease.IP, fmt.Sprint(lease.Port)), remaining)
	if err != nil {
		return nil, fmt.Errorf("%w: %w", errProviderDial, err)
	}
	handshake, err := buildHandshakeWithGreeting(string(lease.User), string(lease.Password), 1, targetHost, targetPort, greeting)
	defer clearBytes(handshake)
	if err == nil {
		// Bound a stalled provider write by the same attempt deadline used for
		// connect/read. A TCP connection can be established while its first
		// handshake write is still blocked by a full send buffer.
		if time.Until(deadline) <= 0 {
			err = context.DeadlineExceeded
		} else if deadlineErr := conn.SetWriteDeadline(deadline); deadlineErr != nil {
			err = deadlineErr
		}
	}
	if err == nil {
		err = writeAll(conn, handshake)
	}
	var reader *bufio.Reader
	if err == nil {
		ackDeadline := deadline
		if cap := time.Now().Add(3 * time.Second); cap.Before(ackDeadline) {
			ackDeadline = cap
		}
		reader, err = readStreamAcksUntil(conn, ackDeadline)
	}
	if err != nil {
		conn.Close()
		return nil, fmt.Errorf("stream handshake: %w", markHandshakePhase(err))
	}
	_ = conn.SetWriteDeadline(time.Time{})
	return &bufferedConn{Conn: conn, reader: reader}, nil
}

func dialStreamWithGreetingOrder(
	lease ProviderLease,
	targetHost string,
	targetPort int,
	timeout time.Duration,
	order [][]byte,
) (net.Conn, error) {
	return dialStreamWithGreetingOrderConfig(Config{}, lease, targetHost, targetPort, timeout, order)
}

func dialStreamWithGreetingOrderConfig(
	config Config,
	lease ProviderLease,
	targetHost string,
	targetPort int,
	timeout time.Duration,
	order [][]byte,
) (net.Conn, error) {
	if timeout <= 0 {
		return nil, fmt.Errorf("stream handshake: %w", context.DeadlineExceeded)
	}
	if len(order) < 2 {
		return nil, errors.New("stream handshake greeting order invalid")
	}
	deadline := time.Now().Add(timeout)
	conn, err := dialStreamWithGreetingUntilConfig(config, lease, targetHost, targetPort, deadline, order[0])
	if err == nil {
		return conn, nil
	}
	if !isRetryableHandshakeError(err) {
		return conn, err
	}
	remaining := time.Until(deadline)
	if remaining <= 0 {
		return nil, fmt.Errorf("stream handshake failed after greeting fallback: initial=%v; fallback=%w", err, context.DeadlineExceeded)
	}
	fallbackConn, fallbackErr := dialStreamWithGreetingUntilConfig(config, lease, targetHost, targetPort, deadline, order[1])
	if fallbackErr == nil {
		return fallbackConn, nil
	}
	return nil, fmt.Errorf("stream handshake failed after greeting fallback: initial=%v; fallback=%w", err, fallbackErr)
}

func dialStream(lease ProviderLease, targetHost string, targetPort int, timeout time.Duration) (net.Conn, error) {
	return dialStreamConfig(Config{}, lease, targetHost, targetPort, timeout)
}

func dialStreamConfig(config Config, lease ProviderLease, targetHost string, targetPort int, timeout time.Duration) (net.Conn, error) {
	return dialStreamWithGreetingOrderConfig(config, lease, targetHost, targetPort, timeout, handshakeGreetingOrder(lease))
}

func dialStreamForConfig(config Config) candidateStreamDial {
	return func(lease ProviderLease, targetHost string, targetPort int, timeout time.Duration) (net.Conn, error) {
		return dialStreamConfig(config, lease, targetHost, targetPort, timeout)
	}
}

type streamCandidateDial func(ProviderLease, string, int, time.Duration) (net.Conn, error)

// dialStreamWithCandidates retries a new local connection against the
// remaining leases before any application bytes are read. This preserves the
// caller's payload: a provider failure cannot cause an already-sent request to
// be replayed to another relay. The helper owns the lease snapshots it tries;
// a successful lease is returned to the caller for the stream lifetime.
func dialStreamWithCandidates(
	ctx context.Context,
	store *PolicyStore,
	policy Policy,
	initial ProviderLease,
	targetHost string,
	targetPort int,
	timeout time.Duration,
	dial streamCandidateDial,
	onFailure func(ProviderLease, error),
) (net.Conn, ProviderLease, error) {
	if store == nil || dial == nil {
		initial.wipe()
		return nil, ProviderLease{}, errNoHealthyCandidate
	}
	current := initial
	attempted := make([]string, 0, 4)
	var lastErr error
	for attempt := 0; attempt < 4; attempt++ {
		if err := ctx.Err(); err != nil {
			current.wipe()
			return nil, ProviderLease{}, err
		}
		if !current.ExpiresAt.After(time.Now()) {
			current.wipe()
			return nil, ProviderLease{}, fmt.Errorf("%w: %v", errNoHealthyCandidate, ErrPolicyExpired)
		}
		identity := strings.TrimSpace(current.CandidateKeyHash)
		if identity == "" {
			identity = strings.TrimSpace(current.SelectionRef)
		}
		if identity != "" {
			attempted = append(attempted, identity)
		}
		if operatorQuarantines(store.config, current, "tcp", time.Now()) {
			current.wipe()
			lastErr = errOperatorQuarantine
			next, ok := store.SnapshotNextCandidate(attempted...)
			if !ok {
				break
			}
			current = next.Lease
			continue
		}
		attemptTimeout := timeout
		if remaining := time.Until(current.ExpiresAt); remaining < attemptTimeout {
			attemptTimeout = remaining
		}
		connection, err := dial(current, targetHost, targetPort, attemptTimeout)
		if err == nil {
			return connection, current, nil
		}
		lastErr = err
		if onFailure != nil {
			onFailure(current, err)
		}
		failureKey := current.CandidateKeyHash
		if strings.TrimSpace(failureKey) == "" {
			failureKey = current.SelectionRef
		}
		store.MarkCandidateFailure(failureKey)
		current.wipe()
		if attempt == 3 {
			break
		}
		next, ok := store.SnapshotNextCandidate(attempted...)
		if !ok {
			break
		}
		current = next.Lease
	}
	if lastErr == nil {
		lastErr = errProbeUnavailable
	}
	return nil, ProviderLease{}, fmt.Errorf("%w: %v", errNoHealthyCandidate, lastErr)
}

func dialNativeStream(lease ProviderLease, targetHost string, targetPort int, timeout time.Duration) (net.Conn, error) {
	return dialNativeStreamConfig(Config{}, lease, targetHost, targetPort, timeout)
}

func dialNativeStreamConfig(config Config, lease ProviderLease, targetHost string, targetPort int, timeout time.Duration) (net.Conn, error) {
	return dialStreamWithGreetingOrderConfig(config, lease, targetHost, targetPort, timeout, nativeHandshakeGreetingOrder(lease))
}

// copyOutbound and copyInbound report how many bytes they moved: the relay
// health decision needs to tell "the far end answered and closed" apart from
// "the far end took the connection and returned nothing", and only the byte
// count separates those two EOFs.
func copyOutbound(destination net.Conn, source net.Conn, limiter *PolicyLimiter, status *StatusWriter) (int64, error) {
	buffer := make([]byte, 32*1024)
	var total int64
	for {
		count, readErr := source.Read(buffer)
		if count > 0 {
			if err := limiter.Consume(count); err != nil {
				return total, err
			}
			status.AddUsage(0, count)
			if err := writeXor(destination, buffer[:count]); err != nil {
				return total, err
			}
			total += int64(count)
		}
		if readErr != nil {
			return total, readErr
		}
	}
}

// inboundCopyObservation keeps the peer's reads separate from delivery to the
// local application. A local write failure must never look like a silent peer.
type inboundCopyObservation struct {
	ReadBytes    int64
	WrittenBytes int64
	ReadError    error
	WriteError   error
	PolicyError  error
}

func (o inboundCopyObservation) Err() error {
	if o.PolicyError != nil {
		return o.PolicyError
	}
	if o.WriteError != nil {
		return o.WriteError
	}
	return o.ReadError
}

func copyInbound(destination net.Conn, source net.Conn, limiter *PolicyLimiter, status *StatusWriter) (int64, error) {
	observation := copyInboundObserved(destination, source, limiter, status)
	return observation.WrittenBytes, observation.Err()
}

func copyInboundObserved(destination net.Conn, source net.Conn, limiter *PolicyLimiter, status *StatusWriter) inboundCopyObservation {
	buffer := make([]byte, 32*1024)
	var observation inboundCopyObservation
	for {
		count, readErr := source.Read(buffer)
		observation.ReadError = readErr
		if count > 0 {
			observation.ReadBytes += int64(count)
			if err := limiter.Consume(count); err != nil {
				observation.PolicyError = err
				return observation
			}
			// Usage remains the bytes read after policy admission, not the
			// application's potentially partial acceptance of those bytes.
			status.AddUsage(count, 0)
			remaining := buffer[:count]
			for len(remaining) > 0 {
				written, writeErr := destination.Write(remaining)
				if written < 0 || written > len(remaining) {
					observation.WriteError = errors.New("writer returned invalid count")
					return observation
				}
				observation.WrittenBytes += int64(written)
				remaining = remaining[written:]
				if writeErr != nil {
					observation.WriteError = writeErr
					return observation
				}
				if written == 0 {
					observation.WriteError = io.ErrShortWrite
					return observation
				}
			}
		}
		if readErr != nil {
			return observation
		}
	}
}

func serveTCP(ctx context.Context, config Config, store *PolicyStore, status *StatusWriter) error {
	targetHost, targetPort, err := parseTarget(config.Target)
	if err != nil {
		return err
	}
	listener, err := net.Listen("tcp", net.JoinHostPort(config.ListenHost, fmt.Sprint(config.ListenPort)))
	if err != nil {
		return err
	}
	defer listener.Close()
	go func() {
		<-ctx.Done()
		_ = listener.Close()
	}()
	limit := config.MaxTCPConnections
	if limit < 1 || limit > 4096 {
		limit = 512
	}
	semaphore := make(chan struct{}, limit)
	for {
		local, err := listener.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return err
		}
		select {
		case semaphore <- struct{}{}:
			go func() {
				defer func() { <-semaphore }()
				defer local.Close()
				started := time.Now()
				remote, snapshot, dialErr := dialCandidateStream(
					ctx,
					store,
					targetHost,
					targetPort,
					time.Duration(config.ConnectTimeoutSecs)*time.Second,
					dialStreamForConfig(config),
				)
				if dialErr != nil {
					status.RecordHAEvent("tcp", haErrorClass(dialErr), "")
					log.Printf("tcp stream setup failed forward_id=%d: %v", config.ForwardID, dialErr)
					status.UpdateError("tcp_connect_failed")
					return
				}
				defer snapshot.Lease.wipe()
				status.UpdateTCPWithTierAndCandidate(snapshot.Lease.SelectionRef, snapshot.Lease.SelectionTier, snapshot.Lease.CandidateKeyHash, time.Since(started), snapshot.Lease.LeaseHash)
				relayTCP(ctx, local, remote, store.Limiter(), status, store, relayIdentity{
					forward:      config.ForwardID,
					generation:   snapshot.Policy.PolicyGeneration,
					selection:    snapshot.Lease.SelectionRef,
					pathKey:      haPathKey(snapshot.Lease),
					candidateKey: snapshot.Lease.CandidateKeyHash,
					leaseHash:    snapshot.Lease.LeaseHash,
					targetHash:   tcpTargetHash(config.Target),
				})

			}()
		default:
			local.Close()
		}
	}
}

func readControlReply(conn net.Conn) ([]byte, error) {
	return readControlReplyWithTimeout(conn, 3*time.Second)
}

func readControlReplyWithTimeout(conn net.Conn, timeout time.Duration) ([]byte, error) {
	if timeout <= 0 {
		return nil, context.DeadlineExceeded
	}
	return readControlReplyUntil(conn, time.Now().Add(timeout))
}

func readControlReplyUntil(conn net.Conn, deadline time.Time) ([]byte, error) {
	if time.Until(deadline) <= 0 {
		return nil, context.DeadlineExceeded
	}
	if err := conn.SetReadDeadline(deadline); err != nil {
		return nil, err
	}
	buffer := make([]byte, 0, 128)
	temporary := make([]byte, 128)
	for len(buffer) < 128 {
		count, err := conn.Read(temporary)
		if count > 0 {
			buffer = append(buffer, temporary[:count]...)
			marker := bytes.LastIndex(buffer, []byte{4, 1, 1, 0, 1})
			if marker >= 0 && len(buffer) >= marker+10 {
				_ = conn.SetReadDeadline(time.Time{})
				return buffer, nil
			}
		}
		if err != nil {
			return nil, err
		}
	}
	return nil, errDatagramControlReplyIncomplete
}

type DatagramSession struct {
	receiver         datagramReceiver
	closeOnce        sync.Once
	onRuntimeFailure func(error)
	onRuntimeACK     func()
	onRuntimeDrop    func()
	// onCeilingMeasured publishes the payload ceiling of this association to the
	// status file together with its provenance (declared or measured).
	onCeilingMeasured func(ceiling int, source string)

	control *net.TCPConn
	remote  *net.UDPConn
	mode    byte
	sid     []byte

	// Payload ceiling (path MTU) of this association. ceilingMu single-flights
	// the lazy ladder measurement, which is why it is not a sync.Once: a
	// panel-declared ceiling is applied first and verified later.
	ceilingMu sync.Mutex
	// declaredCeiling is the panel hint for this relay, 0 when unknown.
	declaredCeiling int
	payloadCeiling  int
	ceilingSource   string
	ceilingMeasured bool
}

// Native-UDP payload ceiling self-measurement.
//
// Field sweep 2026-09-12 (189 lines from the source host): the ceiling belongs to
// the relay association, not to the line -- some relays carry 2000-byte payloads,
// others drop everything above ~1450 wire bytes, and one relay of line 946
// returned nothing at all. The runtime had no MTU concept, so a 1500-byte TUN
// frame became a 1516-byte datagram and vanished without a trace.
const (
	// defaultPayloadCeiling keeps envelope+IP+UDP at 1444 bytes, under every
	// ceiling observed in the sweep; payloads at or below it never pay for a probe.
	defaultPayloadCeiling = 1400
	// floorPayloadCeiling is the smallest ceiling still considered usable.
	floorPayloadCeiling = 1280
	// payloadCeilingAttempts guards against the 1-in-12 flapping seen at 1440.
	payloadCeilingAttempts = 2
	// datagramEnvelopeSize is the fixed Ultra envelope that prefixes a payload.
	datagramEnvelopeSize = 16
	// maxPayloadCeilingHint bounds a panel-declared ceiling: the largest payload
	// one IPv4 UDP datagram can carry after the envelope.
	maxPayloadCeilingHint = 65507 - datagramEnvelopeSize
	// Payload ceiling provenance published in status: a value the panel declared
	// for the relay versus one the runtime measured on the path.
	payloadCeilingSourceDeclared = "declared"
	payloadCeilingSourceMeasured = "measured"
	// udpPayloadCeilingUnusableReason is the candidate feedback reason for an
	// association that completes its handshake but cannot echo a floor-sized
	// payload. It is deliberately distinct so status shows why a relay left.
	udpPayloadCeilingUnusableReason = "udp_payload_ceiling_unusable"
)

var (
	errDatagramOversize   = errors.New("datagram exceeds path payload ceiling")
	errDatagramUnusable   = errors.New("association cannot carry a floor-sized payload")
	payloadCeilingLadder  = []int{1500, 1472, 1440, 1400, 1360, 1280}
	payloadCeilingTimeout = 300 * time.Millisecond
	// payloadCeilingBudget bounds the one-time measurement on the send path. It
	// must cover the whole ladder: below the floor a verdict of 0 means "the
	// floor rung did not echo", so a rung skipped for time would report a
	// narrow-but-working relay as unusable and make HA migrate away from it.
	payloadCeilingBudget = payloadCeilingAttempts * time.Duration(len(payloadCeilingLadder)) * payloadCeilingTimeout
)

// probeEcho sends payload to the relay keep-echo target (2.0.0.0:0), the only
// destination guaranteed to answer on an armed association, and reports whether
// the identical bytes came back.
//
// Socket ownership rule (2026-09-16 reader-race fix): while a receive loop
// owns the socket, the probe never calls Read or SetReadDeadline here. A probe
// deadline used to wake the runtime reader's pending Read and collapse a
// healthy association into errUDPReceiveTimeout ("keepalive_timeout" in the
// canary) long before its own tolerance. Instead the probe registers a waiter
// and the receive loop dispatches the matching echo frame. Before any reader
// starts (candidate arms, warm-up and availability checks) this call is the
// socket's only reader and reads it directly.
func (session *DatagramSession) probeEcho(payload []byte, timeout time.Duration) bool {
	if session.remote == nil {
		return false
	}
	packet, err := makeDatagramEnvelope(session.mode, session.sid, "2.0.0.0", 0, payload)
	if err != nil {
		return false
	}
	if waiter := session.registerProbeWaiter(payload); waiter != nil {
		defer session.unregisterProbeWaiter(waiter)
		if _, err := writeAllToUDP(session.remote, packet); err != nil {
			return false
		}
		timer := time.NewTimer(timeout)
		defer timer.Stop()
		select {
		case <-waiter.echo:
			return true
		case <-timer.C:
			return false
		case <-session.runtimeDone():
			// The reader died on its own terms; the session is closed and this
			// probe must not outlive it. (A nil done channel simply never fires,
			// so the native path still relies on its own timer.)
			return false
		}
	}
	if _, err := writeAllToUDP(session.remote, packet); err != nil {
		return false
	}
	deadline := time.Now().Add(timeout)
	buffer := make([]byte, 65535)
	for {
		if err := session.remote.SetReadDeadline(deadline); err != nil {
			return false
		}
		count, err := session.remote.Read(buffer)
		if err != nil {
			return false
		}
		if unwrapped, ok := unwrapDatagramForSession(buffer[:count]); ok && bytes.Equal(unwrapped, payload) {
			return true
		}
		if !time.Now().Before(deadline) {
			return false
		}
	}
}

// measurePayloadCeiling walks the ladder from large to small and returns the first
// size that echoed back on every attempt. ok is false when even the smallest rung
// failed: the 2026-09-12 sweep found relays that complete the handshake but never
// echo a real payload (10 of 180), and those must count as unusable instead of
// silently swallowing traffic.
func measurePayloadCeiling(echo func([]byte, time.Duration) bool) (int, bool) {
	budget := time.Now().Add(payloadCeilingBudget)
	for index, size := range payloadCeilingLadder {
		// The floor rung is always probed: it is the rung that decides whether
		// the association is unusable at all.
		if index < len(payloadCeilingLadder)-1 && time.Now().After(budget) {
			break
		}
		accepted := true
		for attempt := 0; attempt < payloadCeilingAttempts && accepted; attempt++ {
			payload := make([]byte, size)
			for index := range payload {
				payload[index] = byte(size + attempt + index)
			}
			if !echo(payload, payloadCeilingTimeout) {
				accepted = false
			}
		}
		if accepted {
			return size, true
		}
	}
	return 0, false
}

// payloadLimit is the ceiling currently trusted for this association. A
// panel-declared hint is used as the initial ceiling so a relay the panel
// already measured does not pay for a local ladder probe; without a hint the
// path is measured once, lazily. 0 means the path cannot carry real payloads.
func (session *DatagramSession) payloadLimit() int {
	session.ceilingMu.Lock()
	defer session.ceilingMu.Unlock()
	if session.ceilingSource == "" {
		if session.declaredCeiling > 0 {
			session.applyCeilingLocked(session.declaredCeiling, payloadCeilingSourceDeclared)
		} else {
			session.applyMeasuredCeilingLocked()
		}
	}
	return session.payloadCeiling
}

// verifyPayloadCeiling replaces a panel-declared ceiling with the value measured
// on the path. It runs at most once, when a datagram exceeds the declared
// ceiling, so a stale hint is corrected in either direction instead of
// silently black-holing traffic the relay can carry.
func (session *DatagramSession) verifyPayloadCeiling() int {
	session.ceilingMu.Lock()
	defer session.ceilingMu.Unlock()
	if !session.ceilingMeasured {
		session.applyMeasuredCeilingLocked()
	}
	return session.payloadCeiling
}

func (session *DatagramSession) applyMeasuredCeilingLocked() {
	size, ok := measurePayloadCeiling(session.probeEcho)
	if !ok || size < floorPayloadCeiling {
		size = 0
	}
	session.ceilingMeasured = true
	session.applyCeilingLocked(size, payloadCeilingSourceMeasured)
}

func (session *DatagramSession) applyCeilingLocked(ceiling int, source string) {
	session.payloadCeiling = ceiling
	session.ceilingSource = source
	if session.onCeilingMeasured != nil {
		session.onCeilingMeasured(ceiling, source)
	}
}

// payloadUnusable reports an association the runtime measured as unable to carry
// a floor-sized payload. It must migrate to another candidate instead of
// dropping every large datagram until the policy expires.
func (session *DatagramSession) payloadUnusable() bool {
	return session.payloadLimit() == 0
}

// allowsPayload keeps sub-MTU traffic free of probes and only measures the path
// for datagrams that could plausibly hit the ceiling.
func (session *DatagramSession) allowsPayload(size int) bool {
	if size <= floorPayloadCeiling {
		return true
	}
	if limit := session.payloadLimit(); limit > 0 && size <= limit {
		return true
	}
	// The datagram is above the known ceiling. Verify it against the path before
	// refusing: a low panel hint is corrected upward instead of dropping traffic
	// this relay can actually carry.
	limit := session.verifyPayloadCeiling()
	return limit > 0 && size <= limit
}

func (session *DatagramSession) Close() {
	session.closeOnce.Do(func() {
		if session.remote != nil {
			session.remote.Close()
		}
		if session.control != nil {
			session.control.Close()
		}
	})
}

func makeDatagramEnvelope(mode byte, sid []byte, targetIP string, targetPort int, payload []byte) ([]byte, error) {
	ip := net.ParseIP(targetIP).To4()
	if ip == nil || len(sid) != 3 {
		return nil, errors.New("datagram target invalid")
	}
	packet := make([]byte, 16+len(payload))
	copy(packet[:6], []byte{1, 0, mode, sid[0], sid[1], sid[2]})
	packet[9] = 1
	copy(packet[10:14], ip)
	binary.BigEndian.PutUint16(packet[14:16], uint16(targetPort))
	copy(packet[16:], payload)
	return packet, nil
}

// decodeLocalUDPRequest accepts the standard Shadowsocks/SOCKS5 UDP request
// envelope used by local clients. Raw UDP callers (for example game clients
// using a fixed forward target) remain supported and are returned unchanged.
// The bool pair is (isSocksUDP, valid). A packet that does not look like a
// SOCKS5 envelope is intentionally treated as raw data for compatibility.
func decodeLocalUDPRequest(packet []byte) ([]byte, bool, bool) {
	if len(packet) < 4 || packet[0] != 0 || packet[1] != 0 || packet[2] != 0 {
		return packet, false, true
	}
	offset := 3
	addressType := packet[offset]
	offset++
	switch addressType {
	case 1: // IPv4
		if len(packet) < offset+4+2 {
			return packet, false, true
		}
		offset += 4
	case 3: // domain name
		if len(packet) < offset+1 {
			return packet, false, true
		}
		length := int(packet[offset])
		if length == 0 || len(packet) < offset+1+length+2 {
			return packet, false, true
		}
		offset += 1 + length
	case 4: // IPv6
		if len(packet) < offset+16+2 {
			return packet, false, true
		}
		offset += 16
	default:
		// A raw game datagram can begin with zero bytes. Only classify a
		// syntactically recognizable ATYP as a SOCKS packet.
		return packet, false, true
	}
	if len(packet) <= offset+2 {
		return nil, true, false
	}
	offset += 2 // destination port
	// The Ultra provider forwards the Shadowsocks wire payload unchanged.
	// Keep ATYP/address/port and remove only the local SOCKS RSV/FRAG bytes.
	return packet[3:], true, true
}

func makeLocalUDPResponse(targetIP string, targetPort int, payload []byte) ([]byte, error) {
	var buffer bytes.Buffer
	buffer.Write([]byte{0, 0, 0}) // RSV, FRAG
	if err := appendAddress(&buffer, targetIP, targetPort); err != nil {
		return nil, err
	}
	buffer.Write(payload)
	return buffer.Bytes(), nil
}

func makeLocalUDPResponseFromDatagram(info datagramResponseInfo, payload []byte) ([]byte, error) {
	var buffer bytes.Buffer
	buffer.Write([]byte{0, 0, 0}) // RSV, FRAG
	switch info.addressType {
	case 1:
		if len(info.address) != net.IPv4len {
			return nil, errors.New("local UDP response address invalid")
		}
		buffer.WriteByte(1)
		buffer.Write(info.address)
	case 3:
		if len(info.address) == 0 || len(info.address) > 255 {
			return nil, errors.New("local UDP response domain invalid")
		}
		buffer.WriteByte(3)
		buffer.WriteByte(byte(len(info.address)))
		buffer.Write(info.address)
	case 4:
		if len(info.address) != net.IPv6len {
			return nil, errors.New("local UDP response address invalid")
		}
		buffer.WriteByte(4)
		buffer.Write(info.address)
	default:
		return nil, errors.New("local UDP response address type invalid")
	}
	var port [2]byte
	binary.BigEndian.PutUint16(port[:], info.port)
	buffer.Write(port[:])
	buffer.Write(payload)
	return buffer.Bytes(), nil
}

func unwrapNestedLocalUDPResponse(info datagramResponseInfo, payload []byte) (datagramResponseInfo, []byte) {
	if nested, valid := decodeDatagramAddressFirst(payload); valid && len(nested.payload) > 0 {
		return nested, nested.payload
	}
	return info, payload
}

type datagramResponseInfo struct {
	addressType byte
	address     []byte
	port        uint16
	payload     []byte
}

// decodeDatagramResponse parses either provider response representation. The
// reference relay normally returns a SOCKS UDP frame with RSV/FRAG, while a
// Shadowsocks `none` target returns the address-first wire form (ATYP/address/
// port/payload). Both are bound to the connected provider socket.
func decodeDatagramResponse(packet []byte) (datagramResponseInfo, bool) {
	if len(packet) >= 4 && packet[0] == 0 && packet[1] == 0 && packet[2] == 0 {
		info, valid := decodeDatagramAddressFirst(packet[3:])
		if valid {
			return info, true
		}
	}
	return decodeDatagramAddressFirst(packet)
}

func decodeDatagramAddressFirst(packet []byte) (datagramResponseInfo, bool) {
	if len(packet) < 1 {
		return datagramResponseInfo{}, false
	}
	info := datagramResponseInfo{addressType: packet[0]}
	offset := 1
	var addressEnd int
	switch info.addressType {
	case 1: // IPv4
		if len(packet) < offset+4+2 {
			return datagramResponseInfo{}, false
		}
		addressEnd = offset + 4
		info.address = packet[offset:addressEnd]
	case 3: // domain name
		if len(packet) < offset+1 {
			return datagramResponseInfo{}, false
		}
		domainLength := int(packet[offset])
		if domainLength == 0 {
			return datagramResponseInfo{}, false
		}
		addressEnd = offset + 1 + domainLength
		if len(packet) < addressEnd+2 {
			return datagramResponseInfo{}, false
		}
		info.address = packet[offset+1 : addressEnd]
	case 4: // IPv6
		if len(packet) < offset+16+2 {
			return datagramResponseInfo{}, false
		}
		addressEnd = offset + 16
		info.address = packet[offset:addressEnd]
	default:
		return datagramResponseInfo{}, false
	}
	if len(packet) < addressEnd+2 {
		return datagramResponseInfo{}, false
	}
	info.port = binary.BigEndian.Uint16(packet[addressEnd : addressEnd+2])
	info.payload = packet[addressEnd+2:]
	return info, true
}

func unwrapDatagram(packet []byte) ([]byte, bool) {
	info, valid := decodeDatagramResponse(packet)
	if !valid {
		return nil, false
	}
	return info.payload, true
}

func validDatagramSessionResponse(packet, sid []byte) bool {
	// The reference response omits SID and instead returns the reserved
	// association address. Validate the caller's SID shape, then require the
	// complete reserved SOCKS envelope; payload bytes are intentionally opaque.
	if len(sid) != 3 {
		return false
	}
	info, valid := decodeDatagramResponse(packet)
	return valid && info.addressType == 1 &&
		bytes.Equal(info.address, []byte{2, 0, 0, 0}) && info.port == 0
}

func isDatagramKeepaliveResponse(packet, _ []byte) bool {
	info, valid := decodeDatagramResponse(packet)
	return valid && info.addressType == 1 &&
		bytes.Equal(info.address, []byte{2, 0, 0, 0}) && info.port == 0
}

func unwrapDatagramForSession(packet []byte) ([]byte, bool) {
	// A DatagramSession owns a connected UDP socket, so source association is
	// enforced by the socket rather than by a SID field (which the reference
	// relay does not include in responses).
	return unwrapDatagram(packet)
}

func sendDatagramKeepalive(session *DatagramSession) error {
	if session == nil {
		return errors.New("datagram session missing")
	}
	packet, err := makeDatagramEnvelope(session.mode, session.sid, "2.0.0.0", 0, []byte(keepPayloadHex))
	if err != nil {
		return err
	}
	_, err = session.remote.Write(packet)
	return err
}

func logLeaseSelectionStart(forwardID, candidateCount int) {
	log.Printf("ultra lease selection started forward_id=%d candidates=%d", forwardID, candidateCount)
}

func openDatagramControl(lease ProviderLease, timeout time.Duration, greeting []byte) (*net.TCPConn, []byte, error) {
	return openDatagramControlConfig(Config{}, lease, timeout, greeting)
}

func openDatagramControlConfig(config Config, lease ProviderLease, timeout time.Duration, greeting []byte) (*net.TCPConn, []byte, error) {
	if timeout <= 0 {
		return nil, nil, context.DeadlineExceeded
	}
	return openDatagramControlUntilConfig(config, lease, time.Now().Add(timeout), greeting)
}

func openDatagramControlUntil(lease ProviderLease, deadline time.Time, greeting []byte) (*net.TCPConn, []byte, error) {
	return openDatagramControlUntilConfig(Config{}, lease, deadline, greeting)
}

func openDatagramControlUntilConfig(config Config, lease ProviderLease, deadline time.Time, greeting []byte) (*net.TCPConn, []byte, error) {
	remaining := time.Until(deadline)
	if remaining <= 0 {
		return nil, nil, context.DeadlineExceeded
	}
	address := net.JoinHostPort(lease.IP, fmt.Sprint(lease.Port))
	conn, err := dialProviderTCP(config, address, remaining)
	if err != nil {
		return nil, nil, fmt.Errorf("%w: %w", errProviderDial, err)
	}
	control, ok := conn.(*net.TCPConn)
	if !ok {
		conn.Close()
		return nil, nil, fmt.Errorf("provider control dial returned non-TCP connection")
	}
	handshake, buildErr := buildHandshakeWithGreeting(string(lease.User), string(lease.Password), 3, lease.IP, lease.Port, greeting)
	defer clearBytes(handshake)
	if buildErr != nil {
		control.Close()
		return nil, nil, buildErr
	}
	if time.Until(deadline) <= 0 {
		control.Close()
		return nil, nil, fmt.Errorf("datagram handshake: %w", markHandshakePhase(context.DeadlineExceeded))
	}
	if err = control.SetWriteDeadline(deadline); err != nil {
		control.Close()
		return nil, nil, fmt.Errorf("datagram handshake: %w", markHandshakePhase(err))
	}
	if err = writeAll(control, handshake); err != nil {
		control.Close()
		return nil, nil, fmt.Errorf("datagram handshake: %w", markHandshakePhase(err))
	}
	readDeadline := deadline
	if cap := time.Now().Add(datagramControlReplyTimeout); cap.Before(readDeadline) {
		readDeadline = cap
	}
	reply, err := readControlReplyUntil(control, readDeadline)
	if err != nil {
		control.Close()
		return nil, nil, fmt.Errorf("datagram handshake: %w", markHandshakePhase(err))
	}
	_ = control.SetWriteDeadline(time.Time{})
	return control, reply, nil
}

func armDatagram(lease ProviderLease, timeout time.Duration) (*DatagramSession, error) {
	return armDatagramConfig(Config{}, lease, timeout)
}

func armDatagramConfig(config Config, lease ProviderLease, timeout time.Duration) (*DatagramSession, error) {
	if timeout <= 0 {
		return nil, context.DeadlineExceeded
	}
	deadline := time.Now().Add(timeout)
	var lastErr error
	var firstErr error
	fallbackTried := false
	for index, greeting := range nativeHandshakeGreetingOrder(lease) {
		remaining := time.Until(deadline)
		if remaining <= 0 {
			break
		}
		if index == 1 {
			fallbackTried = true
		}
		control, reply, err := openDatagramControlUntilConfig(config, lease, deadline, greeting)
		if err != nil {
			if firstErr == nil {
				firstErr = err
			}
			lastErr = err
			if !isRetryableHandshakeError(err) {
				return nil, err
			}
			continue
		}
		session, err := armDatagramWithControlConfig(config, lease, control, reply, deadline)
		if err == nil {
			return session, nil
		}
		lastErr = err
		if firstErr == nil {
			firstErr = err
		}
		if !isRetryableHandshakeError(err) {
			return nil, err
		}
	}
	if lastErr == nil {
		lastErr = context.DeadlineExceeded
	}
	if fallbackTried && firstErr != nil {
		return nil, fmt.Errorf("datagram handshake failed after greeting fallback: initial=%v; legacy=%w", firstErr, lastErr)
	}
	return nil, fmt.Errorf("datagram handshake failed after greeting fallback: %w", lastErr)
}

func armDatagramWithControl(lease ProviderLease, control *net.TCPConn, reply []byte, deadline time.Time) (*DatagramSession, error) {
	return armDatagramWithControlConfig(Config{}, lease, control, reply, deadline)
}

func armDatagramWithControlConfig(config Config, lease ProviderLease, control *net.TCPConn, reply []byte, deadline time.Time) (*DatagramSession, error) {
	marker := bytes.LastIndex(reply, []byte{4, 1, 1, 0, 1})
	if marker < 0 || marker+10 > len(reply) {
		control.Close()
		return nil, errDatagramControlReplyIncomplete
	}
	reply3 := reply[marker+5 : marker+8]
	remotePort := int(binary.BigEndian.Uint16(reply[marker+8:marker+10]) ^ 0x0101)
	remoteAddress, err := net.ResolveUDPAddr("udp4", net.JoinHostPort(lease.IP, fmt.Sprint(remotePort)))
	if err != nil || remotePort < 1 || remotePort > 65535 {
		control.Close()
		if err != nil {
			return nil, err
		}
		return nil, errors.New("datagram relay port invalid")
	}
	mode := reply3[0] ^ 1
	sidPrefix := []byte{reply3[1] ^ 1, reply3[2] ^ 1}
	keep := []byte(keepPayloadHex)
	buffer := make([]byte, 2048)
	for candidate := datagramCandidateFirst; candidate < datagramCandidateLimit; candidate++ {
		remaining := time.Until(deadline)
		if remaining <= 0 {
			break
		}
		sid := append(append([]byte(nil), sidPrefix...), candidate)
		// Use one socket per candidate. A response that arrives late for a
		// rejected SID then remains queued on the closed socket and cannot arm
		// the next candidate's association.
		remote, dialErr := dialProviderUDP(config, remoteAddress.String(), remaining)
		if dialErr != nil {
			continue
		}
		// Provider-side datagram sockets carry game bursts too; the OS default
		// receive buffer overflows long before the reader loop can drain it.
		_ = remote.SetReadBuffer(4 << 20)
		_ = remote.SetWriteBuffer(4 << 20)
		packet, packetErr := makeDatagramEnvelope(mode, sid, "2.0.0.0", 0, keep)
		if packetErr != nil {
			remote.Close()
			continue
		}
		candidateTimeout := datagramCandidateTimeout
		if remaining < candidateTimeout {
			candidateTimeout = remaining
		}
		candidateDeadline := time.Now().Add(candidateTimeout)
		if candidateDeadline.After(deadline) {
			candidateDeadline = deadline
		}
		_ = remote.SetWriteDeadline(candidateDeadline)
		_ = remote.SetReadDeadline(candidateDeadline)
		if _, writeErr := writeAllToUDP(remote, packet); writeErr != nil {
			remote.Close()
			continue
		}
		for {
			count, readErr := remote.Read(buffer)
			if readErr != nil {
				break
			}
			if validDatagramSessionResponse(buffer[:count], sid) {
				_ = remote.SetReadDeadline(time.Time{})
				_ = remote.SetWriteDeadline(time.Time{})
				if testHookDatagramArmed != nil {
					testHookDatagramArmed(remote)
				}
				// The lease carries the panel's ceiling hint for this relay; the
				// session applies it lazily on the first payload that needs it.
				return &DatagramSession{
					control: control, remote: remote, mode: mode, sid: sid,
					declaredCeiling: normalizeRelayPayloadCeiling(lease.RelayPayloadCeiling),
				}, nil
			}
		}
		remote.Close()
	}
	control.Close()
	return nil, fmt.Errorf("%w: session response rejected", errDatagramAssociationFailed)
}

func writeAllToUDP(conn *net.UDPConn, payload []byte) (int, error) {
	// UDP writes are atomic; keep this helper separate so callers cannot
	// accidentally treat a short datagram write as a successful handshake.
	count, err := conn.Write(payload)
	if err == nil && count != len(payload) {
		return count, io.ErrShortWrite
	}
	return count, err
}

func resolveIPv4(host string) (string, error) {
	if ip := net.ParseIP(host); ip != nil {
		if v4 := ip.To4(); v4 != nil {
			return v4.String(), nil
		}
		return "", errors.New("datagram target requires IPv4")
	}
	addresses, err := net.LookupIP(host)
	if err != nil {
		return "", err
	}
	for _, address := range addresses {
		if v4 := address.To4(); v4 != nil {
			return v4.String(), nil
		}
	}
	return "", errors.New("datagram target has no IPv4 address")
}

// policyGapGrace bounds how long the runtime keeps its listeners bound while
// no valid policy is held.
//
// Cancelling the runtime the moment the policy lapses is what closes the local
// listeners, and the outer loop then parks in store.WaitValid until a
// replacement lease arrives. Measured on 2026-09-14: a panel pull returning
// ultra_access = null cost 37 seconds in which every local connection was
// refused (ports 30522/32469/10002 returned ECONNREFUSED while the runtime
// processes stayed alive and later re-bound the same sockets). Serving needs no
// policy - dialCandidateStream already fails closed with "no healthy authorized
// candidate" while CandidateSnapshots has nothing valid - so the listeners may
// as well stay bound and the forward resumes the instant the lease lands.
// A gap longer than this grace still tears the runtime down, which keeps the
// fail-safe for an account that really is gone.
const policyGapGrace = 90 * time.Second

func watchRuntimePolicy(ctx context.Context, cancel context.CancelFunc, store *PolicyStore) {
	ticker := time.NewTicker(250 * time.Millisecond)
	defer ticker.Stop()
	var lostAt time.Time
	for {
		select {
		case <-ctx.Done():
			return
		case <-store.Updates():
			if store.HasValid() {
				lostAt = time.Time{}
				continue
			}
			if store.RevokedAny() {
				cancel()
				return
			}
			if lostAt.IsZero() {
				lostAt = time.Now()
			}
		case <-ticker.C:
			store.ClearExpired()
			if store.HasValid() {
				lostAt = time.Time{}
				continue
			}
			if store.RevokedAny() {
				cancel()
				return
			}
			if lostAt.IsZero() {
				lostAt = time.Now()
				continue
			}
			if time.Since(lostAt) >= policyGapGrace {
				log.Printf("policy has been missing for %s; stopping the runtime", policyGapGrace)
				cancel()
				return
			}
		}
	}
}

type udpAssociation struct {
	session          *DatagramSession
	client           *net.UDPAddr
	socksUDP         bool
	localModeSet     bool
	lastSeen         time.Time
	lastUserActivity time.Time
	packetCnt        int
	pending          [][]byte
	writeMu          sync.Mutex
	sessionMu        sync.Mutex
	keepaliveCancel  context.CancelFunc
	closed           bool
	setupStarted     bool
}

func (association *udpAssociation) close() {
	if association == nil {
		return
	}
	association.writeMu.Lock()
	association.sessionMu.Lock()
	if association.closed {
		association.sessionMu.Unlock()
		association.writeMu.Unlock()
		return
	}
	association.closed = true
	session := association.session
	cancel := association.keepaliveCancel
	association.keepaliveCancel = nil
	association.sessionMu.Unlock()
	association.writeMu.Unlock()
	if cancel != nil {
		cancel()
	}
	if session != nil {
		session.Close()
	}
}

type udpWarmSession struct {
	session          *DatagramSession
	selectionRef     string
	selectionTier    string
	candidateKeyHash string
	leaseHash        string
	started          time.Time
	keepaliveCancel  context.CancelFunc
	claimed          chan struct{}
	err              error
	// payloadCeiling is the panel-declared ceiling of the armed candidate, 0
	// when the panel declared none.
	payloadCeiling int
}

// runPersistentWarmWorker keeps one unclaimed UDP association ready for the
// first local client. A failed keepalive invalidates the association and
// causes a fresh handshake; a claimed session is handed to the association
// owner, which starts its own keepalive loop.
func runPersistentWarmWorker(
	ctx context.Context,
	sessions chan udpWarmSession,
	factory func() (udpWarmSession, error),
	keepalive func(*DatagramSession) error,
	interval time.Duration,
	retryDelay time.Duration,
) {
	if interval <= 0 {
		interval = safeUDPKeepaliveInterval
	}
	if retryDelay <= 0 {
		retryDelay = time.Second
	}
	if keepalive == nil {
		keepalive = func(*DatagramSession) error { return nil }
	}
	waitRetry := func() bool {
		timer := time.NewTimer(retryDelay)
		defer timer.Stop()
		select {
		case <-ctx.Done():
			return false
		case <-timer.C:
			return true
		}
	}
	removeWarm := func(expected *DatagramSession) {
		select {
		case stale := <-sessions:
			if stale.session == expected {
				if stale.keepaliveCancel != nil {
					stale.keepaliveCancel()
				}
				if stale.session != nil {
					stale.session.Close()
				}
				return
			}
			select {
			case sessions <- stale:
			default:
				if stale.session != nil {
					stale.session.Close()
				}
			}
		default:
		}
	}

	for ctx.Err() == nil {
		warm, err := factory()
		if err != nil || warm.session == nil {
			if !waitRetry() {
				return
			}
			continue
		}
		warm.claimed = make(chan struct{})
		keepaliveContext, keepaliveCancel := context.WithCancel(ctx)
		warm.keepaliveCancel = keepaliveCancel
		failed := make(chan struct{}, 1)
		go func(session *DatagramSession) {
			ticker := time.NewTicker(interval)
			defer ticker.Stop()
			for {
				select {
				case <-keepaliveContext.Done():
					return
				case <-ticker.C:
					if err := keepalive(session); err != nil {
						select {
						case failed <- struct{}{}:
						default:
						}
						return
					}
				}
			}
		}(warm.session)

		select {
		case sessions <- warm:
		case <-ctx.Done():
			keepaliveCancel()
			warm.session.Close()
			return
		}
		select {
		case <-warm.claimed:
			// The consumer now owns this session. Keep arming a replacement
			// so later local associations also start warm.
			keepaliveCancel()
			continue
		case <-warm.session.runtimeDone():
			keepaliveCancel()
			removeWarm(warm.session)
			warm.session.Close()
			if !waitRetry() {
				return
			}
		case <-failed:
			keepaliveCancel()
			removeWarm(warm.session)
			warm.session.Close()
			if !waitRetry() {
				return
			}
		case <-ctx.Done():
			keepaliveCancel()
			removeWarm(warm.session)
			warm.session.Close()
			return
		}
	}
}

type udpRuntimeLimits struct {
	queuePackets    int
	maxAssociations int
	setupWorkers    int
}

func resolveUDPLimits(config Config) udpRuntimeLimits {
	queuePackets := config.UDPQueuePackets
	if queuePackets < 1 || queuePackets > 4096 {
		queuePackets = 256
	}
	maxAssociations := config.UDPMaxAssociations
	if maxAssociations < 1 || maxAssociations > 8192 {
		maxAssociations = 1024
	}
	setupWorkers := config.UDPSetupWorkers
	if setupWorkers < 1 || setupWorkers > 256 {
		setupWorkers = 64
	}
	if setupWorkers > maxAssociations {
		setupWorkers = maxAssociations
	}
	return udpRuntimeLimits{
		queuePackets:    queuePackets,
		maxAssociations: maxAssociations,
		setupWorkers:    setupWorkers,
	}
}

const safeUDPKeepaliveInterval = 5 * time.Second

func resolveUDPKeepaliveInterval(config Config) time.Duration {
	if !stringsContainsUDP(strings.ToLower(strings.TrimSpace(config.Transport))) {
		return 0
	}
	configured := time.Duration(config.UDPKeepaliveIntervalSecs) * time.Second
	if configured != safeUDPKeepaliveInterval {
		return safeUDPKeepaliveInterval
	}
	return configured
}

func serveUDP(ctx context.Context, config Config, store *PolicyStore, status *StatusWriter) error {
	targetHost, targetPort, err := parseTarget(config.Target)
	if err != nil {
		return err
	}
	localAddress, err := net.ResolveUDPAddr("udp", net.JoinHostPort(config.ListenHost, fmt.Sprint(config.ListenPort)))
	if err != nil {
		return err
	}
	local, err := net.ListenUDP("udp", localAddress)
	if err != nil {
		return err
	}
	// Game clients burst well above the OS-default datagram receive buffer;
	// the kernel drop counter (UdpRcvbufErrors) climbs silently otherwise.
	_ = local.SetReadBuffer(4 << 20)
	_ = local.SetWriteBuffer(4 << 20)
	if testHookLocalUDPListening != nil {
		testHookLocalUDPListening(local)
	}
	targetIP, err := resolveIPv4(targetHost)
	if err != nil {
		local.Close()
		return err
	}
	return serveUDPOnConn(ctx, config, store, status, local, targetIP, targetPort)
}

func serveUDPOnConn(
	ctx context.Context,
	config Config,
	store *PolicyStore,
	status *StatusWriter,
	local *net.UDPConn,
	targetIP string,
	targetPort int,
) error {
	defer local.Close()
	limits := resolveUDPLimits(config)
	queuePackets := limits.queuePackets
	maxAssociations := limits.maxAssociations
	setupSlots := make(chan struct{}, limits.setupWorkers)
	associations := make(map[string]*udpAssociation)
	// Associations are bounded by maxAssociations eviction and the idle GC
	// below; a hard per-association packet cap caused a visible hiccup (a
	// dropped packet plus a full re-handshake) every 100k packets.
	const associationIdle = 10 * time.Minute
	prewarmEnabled := config.UDPPrewarm && stringsContainsUDP(strings.ToLower(strings.TrimSpace(config.Transport)))
	var associationMu sync.Mutex
	warmSessions := make(chan udpWarmSession, 1)
	var warmWorker sync.WaitGroup
	warmContext, cancelWarm := context.WithCancel(ctx)
	closeWarmSession := func() {
		select {
		case warm := <-warmSessions:
			if warm.keepaliveCancel != nil {
				warm.keepaliveCancel()
			}
			if warm.session != nil {
				warm.session.Close()
			}
		default:
		}
	}
	defer func() {
		cancelWarm()
		warmWorker.Wait()
		closeWarmSession()
	}()
	publishAssociationCounts := func() {
		associationMu.Lock()
		active := 0
		pending := 0
		for _, association := range associations {
			association.sessionMu.Lock()
			hasSession := association.session != nil
			association.sessionMu.Unlock()
			if !hasSession {
				pending++
			} else {
				active++
			}
		}
		associationMu.Unlock()
		status.UpdateUDPAssociations(active, pending)
	}
	closeAssociation := func(key string, expected *udpAssociation) {
		associationMu.Lock()
		association := associations[key]
		if association == nil || (expected != nil && association != expected) {
			associationMu.Unlock()
			return
		}
		delete(associations, key)
		associationMu.Unlock()
		association.close()
		publishAssociationCounts()
	}
	defer func() {
		associationMu.Lock()
		all := make([]*udpAssociation, 0, len(associations))
		for key, association := range associations {
			delete(associations, key)
			if association != nil {
				all = append(all, association)
			}
		}
		associationMu.Unlock()
		for _, association := range all {
			association.close()
		}
		status.UpdateUDPAssociations(0, 0)
	}()
	startSessionLoops := func(key string, association *udpAssociation, session *DatagramSession) {
		session.startRuntimeReader(3 * safeUDPKeepaliveInterval)
		go func() {
			readBuffer := make([]byte, 65535)
			for {
				responseCount, responseErr := session.readRuntime(readBuffer)
				if responseErr != nil {
					closeAssociation(key, association)
					return
				}
				if isDatagramKeepaliveResponse(readBuffer[:responseCount], nil) {
					continue
				}
				info, valid := decodeDatagramResponse(readBuffer[:responseCount])
				if !valid {
					continue
				}
				payload := info.payload
				if len(payload) == 0 {
					continue
				}
				if store.Limiter().Consume(len(payload)) != nil {
					continue
				}
				status.AddUsage(len(payload), 0)
				association.sessionMu.Lock()
				localSocksUDP := association.socksUDP
				association.sessionMu.Unlock()
				response := payload
				if localSocksUDP {
					// A `none` Shadowsocks target can return its address-first
					// response inside the relay's outer response envelope. Unwrap
					// that inner frame before rebuilding the local SOCKS header;
					// raw UDP associations must continue to receive opaque payloads.
					info, payload = unwrapNestedLocalUDPResponse(info, payload)
					var responseErr error
					response, responseErr = makeLocalUDPResponseFromDatagram(info, payload)
					if responseErr != nil {
						continue
					}
				}
				_, _ = local.WriteToUDP(response, association.client)
			}
		}()

		interval := resolveUDPKeepaliveInterval(config)
		if interval <= 0 {
			return
		}
		keepaliveContext, keepaliveCancel := context.WithCancel(ctx)
		association.sessionMu.Lock()
		if association.closed {
			association.sessionMu.Unlock()
			keepaliveCancel()
			return
		}
		association.keepaliveCancel = keepaliveCancel
		association.sessionMu.Unlock()
		go func() {
			ticker := time.NewTicker(interval)
			defer ticker.Stop()
			for {
				select {
				case <-keepaliveContext.Done():
					return
				case <-ticker.C:
					association.writeMu.Lock()
					association.sessionMu.Lock()
					closed := association.closed
					current := association.session
					association.sessionMu.Unlock()
					if closed || current == nil {
						association.writeMu.Unlock()
						return
					}
					writeErr := sendDatagramKeepalive(current)
					association.writeMu.Unlock()
					if writeErr != nil {
						closeAssociation(key, association)
						return
					}
				}
			}
		}()
	}
	attachSession := func(key string, association *udpAssociation, warm udpWarmSession) {
		if warm.session == nil {
			return
		}
		next := warm.session
		if warm.keepaliveCancel != nil {
			warm.keepaliveCancel()
		}
		association.writeMu.Lock()
		associationMu.Lock()
		if associations[key] != association {
			associationMu.Unlock()
			association.writeMu.Unlock()
			next.Close()
			return
		}
		association.sessionMu.Lock()
		if association.closed {
			association.sessionMu.Unlock()
			associationMu.Unlock()
			association.writeMu.Unlock()
			next.Close()
			return
		}
		association.session = next
		association.sessionMu.Unlock()
		queued := association.pending
		association.pending = nil
		associationMu.Unlock()
		publishAssociationCounts()
		status.UpdateUDPWithTierAndCandidate(warm.selectionRef, warm.selectionTier, warm.candidateKeyHash, time.Since(warm.started), warm.leaseHash)
		status.UpdateUDPPayloadCeiling(warm.payloadCeiling, payloadCeilingSourceDeclared)
		status.RecordUDPAttached(warm.selectionRef, warm.selectionTier)
		for _, queuedPayload := range queued {
			packet, packetErr := makeDatagramEnvelope(next.mode, next.sid, targetIP, targetPort, queuedPayload)
			if packetErr != nil {
				association.writeMu.Unlock()
				closeAssociation(key, association)
				return
			}
			if _, writeErr := next.remote.Write(packet); writeErr != nil {
				association.writeMu.Unlock()
				closeAssociation(key, association)
				return
			}
		}
		association.writeMu.Unlock()
		startSessionLoops(key, association, next)
	}
	startNativeSetup := func(key string, association *udpAssociation, lease ProviderLease) {
		lease.wipe()
		go func() {
			select {
			case setupSlots <- struct{}{}:
			case <-ctx.Done():
				closeAssociation(key, association)
				return
			}
			associationMu.Lock()
			active := associations[key] == association
			associationMu.Unlock()
			if !active {
				<-setupSlots
				return
			}
			warm, armErr := armRuntimeCandidates(ctx, store, config, status)
			<-setupSlots
			if armErr != nil {
				status.AddUDPSetupFailure()
				status.UpdateError("udp_association_failed")
				closeAssociation(key, association)
				return
			}
			attachSession(key, association, warm)

		}()
	}
	if prewarmEnabled {
		warmWorker.Add(1)
		go func() {
			defer warmWorker.Done()
			runPersistentWarmWorker(
				warmContext,
				warmSessions,
				func() (udpWarmSession, error) {
					select {
					case setupSlots <- struct{}{}:
					case <-warmContext.Done():
						return udpWarmSession{}, warmContext.Err()
					}
					defer func() { <-setupSlots }()
					return armRuntimeCandidates(warmContext, store, config, status)

				},
				sendDatagramKeepalive,
				resolveUDPKeepaliveInterval(config),
				time.Second,
			)
		}()
	}
	publishAssociationCounts()
	go func() {
		<-ctx.Done()
		_ = local.Close()
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
				stale := make(map[string]*udpAssociation)
				for key, association := range associations {
					lastActivity := association.lastUserActivity
					if lastActivity.IsZero() {
						lastActivity = association.lastSeen
					}
					if now.Sub(lastActivity) > associationIdle {
						stale[key] = association
					}
				}
				associationMu.Unlock()
				for key, association := range stale {
					closeAssociation(key, association)
				}
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
		payload, packetIsSocksUDP, packetValid := decodeLocalUDPRequest(buffer[:count])
		if !packetValid {
			status.UpdateError("udp_packet_invalid")
			continue
		}
		payloadCount := len(payload)
		if err := store.Limiter().Consume(payloadCount); err != nil {
			status.UpdateError("policy_stopped")
			continue
		}
		snapshot, ok := store.Snapshot()
		if !ok {
			status.UpdateError("policy_stopped")
			continue
		}
		lease := snapshot.Lease
		key := client.String()
		associationMu.Lock()
		association := associations[key]
		pending := false
		queueDropped := false
		needsNativeSetup := false
		if association != nil {
			association.lastSeen = time.Now()
			association.lastUserActivity = association.lastSeen
			association.packetCnt++
			association.sessionMu.Lock()
			modeSet := association.localModeSet
			modeMatches := !modeSet || association.socksUDP == packetIsSocksUDP
			hasSession := association.session != nil
			association.sessionMu.Unlock()
			if !modeMatches {
				associationMu.Unlock()
				lease.wipe()
				status.UpdateError("udp_packet_mode_changed")
				continue
			}
			if !hasSession {
				pending = true
				if len(association.pending) < queuePackets {
					association.pending = append(association.pending, append([]byte(nil), payload...))
				} else {
					queueDropped = true
				}
				if !association.setupStarted {
					association.setupStarted = true
					needsNativeSetup = true
				}
			}
		}
		associationMu.Unlock()
		if queueDropped {
			status.AddUDPQueueDrop()
		}
		if association == nil {
			clientCopy := *client
			now := time.Now()
			association = &udpAssociation{
				client: &clientCopy, lastSeen: now, lastUserActivity: now, packetCnt: 1,
				pending:      [][]byte{append([]byte(nil), payload...)},
				setupStarted: true,
			}
			association.sessionMu.Lock()
			association.localModeSet = true
			association.socksUDP = packetIsSocksUDP
			association.sessionMu.Unlock()
			var evicted *udpAssociation
			associationMu.Lock()
			if len(associations) >= maxAssociations {
				oldestKey := ""
				oldest := time.Now()
				for candidateKey, candidate := range associations {
					if candidate.lastSeen.Before(oldest) {
						oldestKey, oldest = candidateKey, candidate.lastSeen
					}
				}
				if oldestKey != "" {
					evicted = associations[oldestKey]
					delete(associations, oldestKey)
				}
			}
			associations[key] = association
			associationMu.Unlock()
			if evicted != nil {
				evicted.close()
			}
			publishAssociationCounts()
			if prewarmEnabled {
				// Claim only an already-armed warm session. During a retry gap,
				// use an isolated native setup so this client's first packet is
				// not held behind the warm worker.
				select {
				case warm := <-warmSessions:
					if warm.err != nil || warm.session == nil || warm.session.runtimeError() != nil {
						startNativeSetup(key, association, lease)
					} else {
						if warm.claimed != nil {
							close(warm.claimed)
						}
						lease.wipe()
						attachSession(key, association, warm)
					}
				default:
					startNativeSetup(key, association, lease)
				}
			} else {
				startNativeSetup(key, association, lease)
			}
			lease = ProviderLease{}
			continue
		}
		if needsNativeSetup {
			startNativeSetup(key, association, lease)
			lease = ProviderLease{}
		} else {
			lease.wipe()
		}
		if pending {
			continue
		}
		associationMu.Lock()
		current := associations[key]
		var session *DatagramSession
		if current != nil {
			current.lastSeen = time.Now()
			current.lastUserActivity = current.lastSeen
			current.sessionMu.Lock()
			session = current.session
			current.sessionMu.Unlock()
		}
		associationMu.Unlock()
		if session == nil {
			continue
		}
		current.writeMu.Lock()
		if !session.allowsPayload(len(payload)) {
			// Refuse instead of handing the path a datagram it will silently drop.
			status.AddUDPOversizeDrop()
			if session.payloadUnusable() {
				// Nothing echoes on this association. Report it through the
				// existing runtime failure path so HA arms another candidate
				// instead of black-holing every large datagram until expiry.
				current.writeMu.Unlock()
				if session.onRuntimeFailure != nil {
					session.onRuntimeFailure(errDatagramUnusable)
				}
				closeAssociation(key, current)
				continue
			}
			if session.onRuntimeDrop != nil {
				session.onRuntimeDrop()
			}
			current.writeMu.Unlock()
			continue
		}
		packet, packetErr := makeDatagramEnvelope(session.mode, session.sid, targetIP, targetPort, payload)
		if packetErr == nil {
			if _, writeErr := session.remote.Write(packet); writeErr != nil {
				current.writeMu.Unlock()
				closeAssociation(key, current)
				continue
			}
		}
		current.writeMu.Unlock()
	}
}

func main() {
	version := flag.Bool("version", false, "print runtime build version")
	configPath := flag.String("config", "", "runtime configuration file")
	configStdin := flag.Bool("config-stdin", false, "read runtime configuration from stdin")
	flag.Parse()
	if *version {
		fmt.Println(runtimeBuildVersion)
		return
	}
	if err := applyProcessSecurity(); err != nil {
		log.Fatal("process security unavailable")
	}
	if !*configStdin && strings.TrimSpace(*configPath) == "" {
		*configPath = "/etc/ultra/forward.json"
	}
	config, err := loadConfig(*configPath, *configStdin, os.Stdin)
	if err != nil {
		log.Fatal("runtime configuration unavailable")
	}
	if config.ListenHost == "" {
		config.ListenHost = "127.0.0.1"
	}
	if config.ForwardID <= 0 || config.ListenPort < 1 || config.ListenPort > 65535 || strings.TrimSpace(config.Target) == "" {
		log.Fatal("runtime configuration invalid")
	}
	if config.ControlSocket == "" {
		config.ControlSocket = fmt.Sprintf("/run/ultra/forward-%d.control.sock", config.ForwardID)
	}
	if config.ConnectTimeoutSecs < 1 || config.ConnectTimeoutSecs > 30 {
		config.ConnectTimeoutSecs = 5
	}
	if config.ExternalUoT.Enabled {
		normalizedExternalUoT, externalErr := config.ExternalUoT.normalized()
		if externalErr != nil {
			log.Fatal("external UoT configuration invalid")
		}
		config.ExternalUoT = normalizedExternalUoT
		if config.NativeSocks5 {
			log.Fatal("external UoT is not supported by native SOCKS5 mode")
		}
	}
	if config.NativeSocks5 {
		routePolicy, policyErr := ParseNativeRoutePolicy(config.NativeRoutePolicy)
		if policyErr != nil {
			log.Fatal("native route policy invalid")
		}
		config.NativeRoutePolicy = string(routePolicy)
		if config.NativePortMin < nativeDefaultPortMin || config.NativePortMin > 65535 {
			config.NativePortMin = nativeDefaultPortMin
		}
		if config.NativeFilterProfile == "" {
			config.NativeFilterProfile = routePolicy.FilterProfile()
		}
	}
	if config.LocationCachePath == "" {
		config.LocationCachePath = "/var/lib/ultra/location.json"
	}
	if config.LocationLockPath == "" {
		config.LocationLockPath = "/run/ultra/location.lock"
	}
	if config.StatusPath == "" && config.ForwardID > 0 {
		config.StatusPath = fmt.Sprintf("/run/ultra/forward-%d.status.json", config.ForwardID)
	}
	if config.ProbeTimeoutMillis < 100 || config.ProbeTimeoutMillis > 3000 {
		config.ProbeTimeoutMillis = 800
	}
	if config.ProbeWorkers < 1 || config.ProbeWorkers > 4 {
		config.ProbeWorkers = 4
	}
	if config.ProbeDurationSecs < 1 || config.ProbeDurationSecs > 300 {
		config.ProbeDurationSecs = 30
	}
	if config.ProbeSamples < 3 || config.ProbeSamples > 300 {
		config.ProbeSamples = 30
	}
	if config.ProbeIntervalMillis < 100 || config.ProbeIntervalMillis > 5000 {
		config.ProbeIntervalMillis = 1000
	}
	if _, _, err := parseTarget(config.Target); err != nil {
		log.Fatal(err)
	}
	store := NewPolicyStore(config)
	status := newStatusWriter(config.StatusPath, config.ForwardID, 5*time.Second)
	status.RecordTransportConfig(config)
	status.UpdateError("awaiting_policy")
	control := NewControlServer(config.ControlSocket, config, store, status)
	if err := control.Listen(); err != nil {
		status.UpdateError("control_socket_failed")
		status.Close()
		log.Fatal(err)
	}
	rootContext, cancelRoot := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT, syscall.SIGHUP)
	defer cancelRoot()
	go func() {
		if err := control.Serve(); err != nil && rootContext.Err() == nil {
			log.Printf("control socket stopped: %v", err)
			cancelRoot()
		}
	}()
	defer func() {
		store.Revoke("")
		control.Close()
		status.UpdateError("runtime_stopped")
		status.Close()
	}()

	for rootContext.Err() == nil {
		snapshot, err := store.WaitValid(rootContext)
		if err != nil {
			break
		}
		policy := snapshot.Policy
		snapshot.Lease.wipe()
		locationContext, cancelLocation := context.WithTimeout(rootContext, locationRequestTimeout+time.Second)
		location := DetectLocation(locationContext, config.LocationCachePath, config.LocationLockPath, config.ZoneAliases)
		cancelLocation()
		status.UpdateLocation(location)
		status.UpdatePolicy(policy.PolicyGeneration)
		runtimeContext, cancelRuntime := context.WithCancel(rootContext)
		runtimeErrors := make(chan error, 2)
		var runtimeWait sync.WaitGroup
		transport := strings.ToLower(policy.Transport)
		if config.NativeSocks5 {
			// Native mode exposes a local standard SOCKS5 facade and translates
			// each request to the existing Ultra relay protocol. The policy is
			// always tcp+udp for a native lease.
			runtimeWait.Add(1)
			go func() {
				defer runtimeWait.Done()
				runtimeErrors <- serveNativeSocks5(runtimeContext, config, store, status)
			}()
		} else if transport == "tcp" || transport == "tcp+udp" {
			runtimeWait.Add(1)
			go func() {
				defer runtimeWait.Done()
				runtimeErrors <- serveTCP(runtimeContext, config, store, status)
			}()
		}
		if !config.NativeSocks5 && (transport == "udp" || transport == "tcp+udp") {
			runtimeWait.Add(1)
			go func() {
				defer runtimeWait.Done()
				if externalUoTEnabled(config) {
					runtimeErrors <- serveExternalUoT(runtimeContext, config, store, status)
				} else {
					runtimeErrors <- serveUDP(runtimeContext, config, store, status)
				}
			}()
		}
		go watchRuntimePolicy(runtimeContext, cancelRuntime, store)
		select {
		case <-rootContext.Done():
		case <-runtimeContext.Done():
		case err := <-runtimeErrors:
			if err != nil {
				log.Printf("runtime stopped: %v", err)
			}
			if err != nil && rootContext.Err() == nil {
				status.UpdateError("runtime_stopped")
			}
			cancelRuntime()
		}
		cancelRuntime()
		runtimeWait.Wait()
		store.ClearExpired()
	}
}
