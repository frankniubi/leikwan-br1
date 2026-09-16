package main

import (
	"bufio"
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"strings"
	"sync"
	"syscall"
	"time"
)

const (
	nativeSocks5Version         = byte(5)
	nativeSocks5CommandConnect  = byte(1)
	nativeSocks5CommandBind     = byte(2)
	nativeSocks5CommandUDP      = byte(3)
	nativeSocks5ReplySucceeded  = byte(0)
	nativeSocks5ReplyGeneralErr = byte(1)
	nativeSocks5ReplyNoCommand  = byte(7)
	nativeSocks5ReplyNoMethods  = byte(0xff)
	nativeSocks5MaxAddressBytes = 255
	nativeSocks5UDPQueuePackets = 128
)

type nativeSocks5Request struct {
	command byte
	host    string
	port    int
	reader  *bufio.Reader
}

// readNativeSocks5Request performs the local, no-auth SOCKS5 handshake and
// returns one CONNECT or UDP ASSOCIATE request. It deliberately never sends
// provider credentials to the local client.
func readNativeSocks5Request(conn net.Conn) (nativeSocks5Request, error) {
	if conn == nil {
		return nativeSocks5Request{}, errors.New("socks5_connection_missing")
	}
	reader := bufio.NewReaderSize(conn, 4096)
	version, err := reader.ReadByte()
	if err != nil {
		return nativeSocks5Request{}, err
	}
	if version != nativeSocks5Version {
		_ = writeNativeSocks5Reply(conn, nativeSocks5ReplyGeneralErr)
		return nativeSocks5Request{}, errors.New("socks5_version_invalid")
	}
	methodCount, err := reader.ReadByte()
	if err != nil || methodCount == 0 {
		_ = writeNativeSocks5MethodReply(conn, nativeSocks5ReplyNoMethods)
		if err != nil {
			return nativeSocks5Request{}, err
		}
		return nativeSocks5Request{}, errors.New("socks5_methods_missing")
	}
	methods := make([]byte, int(methodCount))
	if _, err := io.ReadFull(reader, methods); err != nil {
		return nativeSocks5Request{}, err
	}
	noAuth := false
	userPass := false
	for _, method := range methods {
		if method == 0 {
			noAuth = true
		} else if method == 2 {
			userPass = true
		}
	}
	selectedMethod := byte(0)
	if !noAuth {
		if userPass {
			selectedMethod = 2
		} else {
			_ = writeNativeSocks5MethodReply(conn, nativeSocks5ReplyNoMethods)
			return nativeSocks5Request{}, errors.New("socks5_no_acceptable_method")
		}
	}
	if err := writeNativeSocks5MethodReply(conn, selectedMethod); err != nil {
		return nativeSocks5Request{}, err
	}
	if selectedMethod == 2 {
		if err := readNativeSocks5UserPass(reader); err != nil {
			_ = writeNativeSocks5UserPassReply(conn, 1)
			return nativeSocks5Request{}, err
		}
		if err := writeNativeSocks5UserPassReply(conn, 0); err != nil {
			return nativeSocks5Request{}, err
		}
	}
	header := make([]byte, 4)
	if _, err := io.ReadFull(reader, header); err != nil {
		return nativeSocks5Request{}, err
	}
	if header[0] != nativeSocks5Version || header[2] != 0 {
		_ = writeNativeSocks5Reply(conn, nativeSocks5ReplyGeneralErr)
		return nativeSocks5Request{}, errors.New("socks5_request_invalid")
	}
	host, err := readNativeSocks5Address(reader, header[3])
	if err != nil {
		_ = writeNativeSocks5Reply(conn, nativeSocks5ReplyGeneralErr)
		return nativeSocks5Request{}, err
	}
	var portBytes [2]byte
	if _, err := io.ReadFull(reader, portBytes[:]); err != nil {
		return nativeSocks5Request{}, err
	}
	port := int(binary.BigEndian.Uint16(portBytes[:]))
	if port < 1 && header[1] != nativeSocks5CommandUDP {
		_ = writeNativeSocks5Reply(conn, nativeSocks5ReplyGeneralErr)
		return nativeSocks5Request{}, errors.New("socks5_port_invalid")
	}
	return nativeSocks5Request{command: header[1], host: host, port: port, reader: reader}, nil
}

func writeNativeSocks5MethodReply(conn net.Conn, method byte) error {
	if conn == nil {
		return errors.New("socks5_connection_missing")
	}
	_, err := conn.Write([]byte{nativeSocks5Version, method})
	return err
}

func readNativeSocks5UserPass(reader *bufio.Reader) error {
	version, err := reader.ReadByte()
	if err != nil {
		return err
	}
	if version != 1 {
		return errors.New("socks5_userpass_version_invalid")
	}
	userLength, err := reader.ReadByte()
	if err != nil || userLength == 0 || int(userLength) > nativeSocks5MaxAddressBytes {
		return errors.New("socks5_userpass_user_invalid")
	}
	user := make([]byte, int(userLength))
	if _, err := io.ReadFull(reader, user); err != nil {
		return err
	}
	passwordLength, err := reader.ReadByte()
	if err != nil || passwordLength == 0 || int(passwordLength) > nativeSocks5MaxAddressBytes {
		return errors.New("socks5_userpass_password_invalid")
	}
	password := make([]byte, int(passwordLength))
	defer clearBytes(user)
	defer clearBytes(password)
	if _, err := io.ReadFull(reader, password); err != nil {
		return err
	}
	return nil
}

func writeNativeSocks5UserPassReply(conn net.Conn, status byte) error {
	if conn == nil {
		return errors.New("socks5_connection_missing")
	}
	_, err := conn.Write([]byte{1, status})
	return err
}

func writeNativeSocks5Reply(conn net.Conn, reply byte) error {
	if conn == nil {
		return errors.New("socks5_connection_missing")
	}
	// Return an unspecified bind address. The local facade never exposes the
	// upstream relay address to the client.
	_, err := conn.Write([]byte{nativeSocks5Version, reply, 0, 1, 0, 0, 0, 0, 0, 0})
	return err
}

func writeNativeSocks5ReplyAddress(conn net.Conn, reply byte, host string, port int) error {
	if conn == nil {
		return errors.New("socks5_connection_missing")
	}
	address, err := nativeSocks5AddressBytes(host)
	if err != nil {
		address = []byte{1, 0, 0, 0, 0}
	}
	var encodedPort [2]byte
	if port < 0 || port > 65535 {
		port = 0
	}
	binary.BigEndian.PutUint16(encodedPort[:], uint16(port))
	frame := make([]byte, 0, 3+len(address)+2)
	frame = append(frame, nativeSocks5Version, reply, 0)
	frame = append(frame, address...)
	frame = append(frame, encodedPort[:]...)
	_, err = conn.Write(frame)
	return err
}

func readNativeSocks5Address(reader *bufio.Reader, addressType byte) (string, error) {
	switch addressType {
	case 1:
		address := make([]byte, net.IPv4len)
		if _, err := io.ReadFull(reader, address); err != nil {
			return "", err
		}
		return net.IP(address).String(), nil
	case 3:
		length, err := reader.ReadByte()
		if err != nil || length == 0 || int(length) > nativeSocks5MaxAddressBytes {
			return "", errors.New("socks5_domain_invalid")
		}
		address := make([]byte, int(length))
		if _, err := io.ReadFull(reader, address); err != nil {
			return "", err
		}
		value := strings.TrimSpace(string(address))
		if value == "" || strings.IndexByte(value, 0) >= 0 {
			return "", errors.New("socks5_domain_invalid")
		}
		return value, nil
	case 4:
		address := make([]byte, net.IPv6len)
		if _, err := io.ReadFull(reader, address); err != nil {
			return "", err
		}
		return net.IP(address).String(), nil
	default:
		return "", errors.New("socks5_address_type_invalid")
	}
}

func parseNativeSocks5UDPDatagram(packet []byte) (string, int, []byte, error) {
	if len(packet) < 4 || packet[0] != 0 || packet[1] != 0 || packet[2] != 0 {
		return "", 0, nil, errors.New("socks5_udp_header_invalid")
	}
	reader := bufio.NewReader(bytesReader(packet[3:]))
	addressType, err := reader.ReadByte()
	if err != nil {
		return "", 0, nil, errors.New("socks5_udp_address_missing")
	}
	host, err := readNativeSocks5Address(reader, addressType)
	if err != nil {
		return "", 0, nil, err
	}
	var portBytes [2]byte
	if _, err := io.ReadFull(reader, portBytes[:]); err != nil {
		return "", 0, nil, errors.New("socks5_udp_port_missing")
	}
	payload, err := io.ReadAll(reader)
	if err != nil {
		return "", 0, nil, err
	}
	if len(payload) == 0 {
		return "", 0, nil, errors.New("socks5_udp_payload_empty")
	}
	return host, int(binary.BigEndian.Uint16(portBytes[:])), payload, nil
}

func makeNativeSocks5UDPDatagram(host string, port int, payload []byte) ([]byte, error) {
	if strings.TrimSpace(host) == "" || port < 1 || port > 65535 || len(payload) == 0 {
		return nil, errors.New("socks5_udp_input_invalid")
	}
	address, err := nativeSocks5AddressBytes(host)
	if err != nil {
		return nil, err
	}
	packet := make([]byte, 0, 3+len(address)+2+len(payload))
	packet = append(packet, 0, 0, 0)
	packet = append(packet, address...)
	var portBytes [2]byte
	binary.BigEndian.PutUint16(portBytes[:], uint16(port))
	packet = append(packet, portBytes[:]...)
	packet = append(packet, payload...)
	return packet, nil
}

func nativeSocks5AddressBytes(host string) ([]byte, error) {
	if ip := net.ParseIP(strings.TrimSpace(host)); ip != nil {
		if v4 := ip.To4(); v4 != nil {
			return append([]byte{1}, v4...), nil
		}
		if v6 := ip.To16(); v6 != nil {
			return append([]byte{4}, v6...), nil
		}
	}
	value := strings.TrimSpace(host)
	if value == "" || len(value) > nativeSocks5MaxAddressBytes || strings.IndexByte(value, 0) >= 0 {
		return nil, errors.New("socks5_domain_invalid")
	}
	return append([]byte{3, byte(len(value))}, []byte(value)...), nil
}

// bytesReader keeps the parser allocation-free at the call site while still
// using bufio.Reader's exact-read helpers.
type byteReader struct {
	data []byte
	off  int
}

func bytesReader(data []byte) *byteReader { return &byteReader{data: data} }

func (reader *byteReader) Read(target []byte) (int, error) {
	if reader.off >= len(reader.data) {
		return 0, io.EOF
	}
	count := copy(target, reader.data[reader.off:])
	reader.off += count
	return count, nil
}

func (reader *byteReader) ReadByte() (byte, error) {
	if reader.off >= len(reader.data) {
		return 0, io.EOF
	}
	value := reader.data[reader.off]
	reader.off++
	return value, nil
}

// nativeSocks5UDPAssociation carries one local UDP client to one custom Ultra
// datagram session. A single association may address many destinations.
type nativeSocks5UDPAssociation struct {
	client           *net.UDPAddr
	clientHost       string
	controlKey       string
	ready            chan struct{}
	readyOnce        sync.Once
	mu               sync.Mutex
	writeMu          sync.Mutex
	pending          []nativeSocks5UDPRequest
	session          *DatagramSession
	keepaliveCancel  context.CancelFunc
	closed           bool
	setupStarted     bool
	candidateKeyHash string
	leaseHash        string
	selectionRef     string
	selectionTier    string
	policyGeneration string
	failureOnce      sync.Once
}

type nativeSocks5UDPRequest struct {
	host    string
	port    int
	payload []byte
}

func (association *nativeSocks5UDPAssociation) markReady(session *DatagramSession, lease ProviderLease) bool {
	association.mu.Lock()
	if association.closed {
		association.mu.Unlock()
		if session != nil {
			session.Close()
		}
		return false
	}
	association.session = session
	association.candidateKeyHash = lease.CandidateKeyHash
	association.leaseHash = lease.LeaseHash
	association.selectionRef = lease.SelectionRef
	association.selectionTier = lease.SelectionTier
	association.policyGeneration = lease.PolicyGeneration
	association.mu.Unlock()
	association.readyOnce.Do(func() { close(association.ready) })
	return true
}

func (association *nativeSocks5UDPAssociation) close() {
	if association == nil {
		return
	}
	association.mu.Lock()
	if association.closed {
		association.mu.Unlock()
		return
	}
	association.closed = true
	session := association.session
	association.session = nil
	cancel := association.keepaliveCancel
	association.keepaliveCancel = nil
	for index := range association.pending {
		clearBytes(association.pending[index].payload)
	}
	association.pending = nil
	association.mu.Unlock()
	if cancel != nil {
		cancel()
	}
	if session != nil {
		session.Close()
	}
	association.readyOnce.Do(func() { close(association.ready) })
}

func (association *nativeSocks5UDPAssociation) enqueue(request nativeSocks5UDPRequest) (*DatagramSession, bool) {
	association.mu.Lock()
	defer association.mu.Unlock()
	if association.closed {
		return nil, false
	}
	if association.session != nil {
		return association.session, true
	}
	if len(association.pending) >= nativeSocks5UDPQueuePackets {
		return nil, false
	}
	association.pending = append(association.pending, request)
	return nil, true
}

func (association *nativeSocks5UDPAssociation) takePending() []nativeSocks5UDPRequest {
	association.mu.Lock()
	defer association.mu.Unlock()
	queued := association.pending
	association.pending = nil
	return queued
}

func (association *nativeSocks5UDPAssociation) currentSession() *DatagramSession {
	association.mu.Lock()
	defer association.mu.Unlock()
	return association.session
}

func (association *nativeSocks5UDPAssociation) isClosed() bool {
	association.mu.Lock()
	defer association.mu.Unlock()
	return association.closed
}

func (association *nativeSocks5UDPAssociation) recordCandidateFailure(
	runtime *nativeSocks5Runtime,
	protocol string,
	reason string,
	err error,
) {
	if association == nil || runtime == nil || runtime.store == nil {
		return
	}
	association.failureOnce.Do(func() {
		key := association.candidateKeyHash
		if strings.TrimSpace(key) == "" {
			key = association.selectionRef
		}
		runtime.store.MarkCandidateFailure(key)
		if runtime.status != nil {
			runtime.status.RecordCandidateFailure(key, protocol, reason, association.leaseHash)
			runtime.status.RecordHAEvent(protocol, haErrorClass(err), association.policyGeneration)
		}
		log.Printf("native %s association failed forward_id=%d selection_ref=%s reason=%s",
			protocol, runtime.config.ForwardID, association.selectionRef, haErrorClass(err))
	})
}

func (association *nativeSocks5UDPAssociation) startSetup() bool {
	association.mu.Lock()
	defer association.mu.Unlock()
	if association.closed || association.setupStarted {
		return false
	}
	association.setupStarted = true
	return true
}

// resolveNativeUDPHost resolves a domain from a local SOCKS UDP packet to the
// IPv4 address required by the existing Ultra datagram envelope.
func resolveNativeUDPHost(host string) (string, error) {
	if ip := net.ParseIP(strings.TrimSpace(host)); ip != nil {
		if v4 := ip.To4(); v4 != nil {
			return v4.String(), nil
		}
		return "", fmt.Errorf("native UDP target is not IPv4")
	}
	return resolveIPv4(strings.TrimSpace(host))
}

type bufferedNativeConn struct {
	net.Conn
	reader *bufio.Reader
}

func (conn *bufferedNativeConn) Read(target []byte) (int, error) {
	return conn.reader.Read(target)
}

type nativeSocks5Runtime struct {
	config        Config
	store         *PolicyStore
	status        *StatusWriter
	udp           *net.UDPConn
	associationMu sync.Mutex
	associations  map[string]*nativeSocks5UDPAssociation
	controlMu     sync.Mutex
	controlRefs   map[string]int
}

func serveNativeSocks5(ctx context.Context, config Config, store *PolicyStore, status *StatusWriter) error {
	if !nativeSocks5ListenHostAllowed(config.ListenHost) {
		return errors.New("native_socks5_listener_not_loopback")
	}
	if store == nil {
		return errors.New("native_store_missing")
	}
	if status == nil {
		status = newStatusWriter("", config.ForwardID, time.Second)
	}
	tcpListener, err := net.Listen("tcp", net.JoinHostPort(config.ListenHost, fmt.Sprint(config.ListenPort)))
	if err != nil {
		return err
	}
	defer tcpListener.Close()
	udpAddress, err := net.ResolveUDPAddr("udp", net.JoinHostPort(config.ListenHost, fmt.Sprint(config.ListenPort)))
	if err != nil {
		return err
	}
	udpConn, err := net.ListenUDP("udp", udpAddress)
	if err != nil {
		return err
	}
	_ = udpConn.SetReadBuffer(4 << 20)
	_ = udpConn.SetWriteBuffer(4 << 20)
	defer udpConn.Close()
	runtime := &nativeSocks5Runtime{
		config: config, store: store, status: status, udp: udpConn,
		associations: make(map[string]*nativeSocks5UDPAssociation),
		controlRefs:  make(map[string]int),
	}
	defer runtime.closeAssociations()

	errorsCh := make(chan error, 2)
	go func() { errorsCh <- runtime.acceptTCP(ctx, tcpListener) }()
	go func() { errorsCh <- runtime.serveUDP(ctx) }()
	select {
	case <-ctx.Done():
		_ = tcpListener.Close()
		_ = udpConn.Close()
		return nil
	case err := <-errorsCh:
		_ = tcpListener.Close()
		_ = udpConn.Close()
		if ctx.Err() != nil {
			return nil
		}
		return err
	}
}

func nativeSocks5ListenHostAllowed(host string) bool {
	normalized := strings.TrimSpace(strings.Trim(host, "[]"))
	ip := net.ParseIP(normalized)
	return ip != nil && ip.IsLoopback()
}

func (runtime *nativeSocks5Runtime) acceptTCP(ctx context.Context, listener net.Listener) error {
	for {
		connection, err := listener.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return err
		}
		go runtime.handleTCP(ctx, connection)
	}
}

func (runtime *nativeSocks5Runtime) handleTCP(ctx context.Context, connection net.Conn) {
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(15 * time.Second))
	request, err := readNativeSocks5Request(connection)
	if err != nil {
		return
	}
	_ = connection.SetDeadline(time.Time{})
	switch request.command {
	case nativeSocks5CommandConnect:
		runtime.handleTCPConnect(ctx, connection, request)
	case nativeSocks5CommandUDP:
		runtime.handleUDPAssociate(ctx, connection)
	case nativeSocks5CommandBind:
		_ = writeNativeSocks5Reply(connection, nativeSocks5ReplyNoCommand)
	default:
		_ = writeNativeSocks5Reply(connection, nativeSocks5ReplyNoCommand)
	}
}

// dialNativeStreamWithCandidates retries a native CONNECT before replying to
// the local SOCKS client. The request header has been consumed, but no
// application payload has been read, so changing the provider cannot replay
// user data. Established streams remain bound to their original lease.
func dialNativeStreamWithCandidates(
	ctx context.Context,
	store *PolicyStore,
	snapshot RuntimeSnapshot,
	targetHost string,
	targetPort int,
	timeout time.Duration,
	dial streamCandidateDial,
	status *StatusWriter,
) (net.Conn, ProviderLease, error) {
	return dialStreamWithCandidates(
		ctx,
		store,
		snapshot.Policy,
		snapshot.Lease,
		targetHost,
		targetPort,
		timeout,
		dial,
		func(failed ProviderLease, err error) {
			key := failed.CandidateKeyHash
			if strings.TrimSpace(key) == "" {
				key = failed.SelectionRef
			}
			if status != nil {
				status.RecordCandidateFailure(key, "tcp", "relay_connect_timeout", failed.LeaseHash)
				status.RecordHAEvent("tcp", haErrorClass(err), snapshot.Policy.PolicyGeneration)
			}
			log.Printf("native tcp candidate failed forward_id=%d selection_ref=%s reason=%s",
				snapshot.Policy.ForwardID, failed.SelectionRef, haErrorClass(err))
		},
	)
}

func (runtime *nativeSocks5Runtime) handleTCPConnect(ctx context.Context, connection net.Conn, request nativeSocks5Request) {
	if !nativePortAllowed(runtime.config, request.port) {
		if runtime.status != nil {
			runtime.status.UpdateError("native_policy_port_rejected")
		}
		_ = writeNativeSocks5Reply(connection, nativeSocks5ReplyGeneralErr)
		return
	}
	snapshot, ok := runtime.store.Snapshot()
	if !ok {
		_ = writeNativeSocks5Reply(connection, nativeSocks5ReplyGeneralErr)
		return
	}
	timeout := time.Duration(runtime.config.ConnectTimeoutSecs) * time.Second
	if timeout <= 0 || timeout > 30*time.Second {
		timeout = 5 * time.Second
	}
	started := time.Now()
	remote, lease, err := dialNativeStreamWithCandidates(
		ctx,
		runtime.store,
		snapshot,
		request.host,
		request.port,
		timeout,
		dialNativeStream,
		runtime.status,
	)
	if err != nil {
		if runtime.status != nil {
			runtime.status.UpdateError("tcp_connect_failed")
		}
		_ = writeNativeSocks5Reply(connection, nativeSocks5ReplyGeneralErr)
		return
	}
	defer lease.wipe()
	defer remote.Close()
	runtime.store.MarkCandidateSuccess(lease.CandidateKeyHash)
	if err := writeNativeSocks5Reply(connection, nativeSocks5ReplySucceeded); err != nil {
		return
	}
	if runtime.status != nil {
		runtime.status.UpdateTCPWithTierAndCandidate(lease.SelectionRef, lease.SelectionTier, lease.CandidateKeyHash, time.Since(started), lease.LeaseHash)
		runtime.status.RecordCandidateSuccess(lease.CandidateKeyHash, "tcp", lease.LeaseHash)
	}
	local := net.Conn(connection)
	if request.reader != nil {
		local = &bufferedNativeConn{Conn: connection, reader: request.reader}
	}
	outboundSource := local
	inboundSource := net.Conn(remote)
	if nativeRoutePolicyForConfig(runtime.config).FilterProfile() == "opengfw_tcp" {
		inspector := newNativeOpenGFWInspector()
		outboundSource = &nativeInspectingConn{
			Conn: local, inspector: inspector, status: runtime.status,
		}
		inboundSource = &nativeInspectingConn{
			Conn: remote, inspector: inspector, reverse: true, status: runtime.status,
		}
	}
	done := make(chan struct{}, 2)
	go func() {
		_, err := copyOutbound(remote, outboundSource, runtime.store.Limiter(), runtime.status)
		if err == nil || errors.Is(err, io.EOF) {
			// A SOCKS client may half-close after sending an HTTP request. Keep
			// the reverse direction alive so the response can still drain.
			closeNativeWrite(remote)
		} else {
			_ = remote.Close()
			_ = connection.Close()
		}
		done <- struct{}{}
	}()
	go func() {
		_, err := copyInbound(local, inboundSource, runtime.store.Limiter(), runtime.status)
		if err == nil || errors.Is(err, io.EOF) {
			closeNativeWrite(local)
		} else {
			_ = remote.Close()
			_ = connection.Close()
		}
		done <- struct{}{}
	}()
	completed := 0
	for completed < 2 {
		select {
		case <-ctx.Done():
			_ = connection.Close()
			_ = remote.Close()
			completed = 2
		case <-done:
			completed++
		}
	}
}

// closeNativeWrite preserves the other half of a TCP stream after a local
// reader reaches EOF. The wrappers used for SOCKS prelude buffering and
// optional inspection intentionally keep the underlying half-close operation.
func closeNativeWrite(connection net.Conn) {
	if connection == nil {
		return
	}
	switch wrapped := connection.(type) {
	case interface{ CloseWrite() error }:
		_ = wrapped.CloseWrite()
	case *bufferedConn:
		closeNativeWrite(wrapped.Conn)
	case *bufferedNativeConn:
		closeNativeWrite(wrapped.Conn)
	case *nativeInspectingConn:
		closeNativeWrite(wrapped.Conn)
	}
}

func (runtime *nativeSocks5Runtime) handleUDPAssociate(ctx context.Context, connection net.Conn) {
	clientHost := nativeSocks5ClientHost(connection.RemoteAddr())
	if clientHost == "" {
		clientHost = "local"
	}
	runtime.addControl(clientHost)
	defer runtime.releaseControl(clientHost)
	if err := writeNativeSocks5ReplyAddress(connection, nativeSocks5ReplySucceeded, "127.0.0.1", runtime.config.ListenPort); err != nil {
		return
	}
	// Keep the control connection alive for the lifetime of the association.
	// A one-second read deadline lets context cancellation terminate promptly.
	var one [1]byte
	for {
		select {
		case <-ctx.Done():
			return
		default:
		}
		_ = connection.SetReadDeadline(time.Now().Add(time.Second))
		if _, err := connection.Read(one[:]); err != nil {
			if timeout, ok := err.(net.Error); ok && timeout.Timeout() {
				continue
			}
			return
		}
	}
}

func (runtime *nativeSocks5Runtime) serveUDP(ctx context.Context) error {
	buffer := make([]byte, 65535)
	for {
		_ = runtime.udp.SetReadDeadline(time.Now().Add(time.Second))
		count, client, err := runtime.udp.ReadFromUDP(buffer)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			if timeout, ok := err.(net.Error); ok && timeout.Timeout() {
				continue
			}
			// Windows can surface an ICMP error for a previous datagram as
			// WSAECONNRESET/WSAECONNREFUSED on the next ReadFromUDP. These
			// per-datagram errors must not tear down the TCP SOCKS listener.
			if isTransientNativeUDPReadError(err) {
				continue
			}
			return err
		}
		host, port, payload, err := parseNativeSocks5UDPDatagram(buffer[:count])
		if err != nil {
			if runtime.status != nil {
				runtime.status.UpdateError("udp_packet_invalid")
			}
			continue
		}
		if !nativePortAllowed(runtime.config, port) {
			if runtime.status != nil {
				runtime.status.UpdateError("native_policy_port_rejected")
			}
			continue
		}
		if err := runtime.store.Limiter().Consume(len(payload)); err != nil {
			if runtime.status != nil {
				runtime.status.UpdateError("policy_stopped")
			}
			continue
		}
		key := nativeSocks5UDPClientKey(client)
		association := runtime.ensureAssociation(key, client)
		if association == nil {
			continue
		}
		request := nativeSocks5UDPRequest{host: host, port: port, payload: append([]byte(nil), payload...)}
		session, accepted := association.enqueue(request)
		if !accepted {
			if runtime.status != nil {
				runtime.status.UpdateError("udp_queue_full")
			}
			continue
		}
		if session != nil {
			runtime.sendUDPRequest(association, session, request)
		} else if association.startSetup() {
			go runtime.setupUDPAssociation(ctx, association)
		}
	}
}

// isTransientNativeUDPReadError identifies errors that describe one failed
// datagram rather than a closed/broken UDP socket. Keep net.ErrClosed fatal so
// the runtime still exits when its listener is deliberately shut down.
func isTransientNativeUDPReadError(err error) bool {
	if err == nil || errors.Is(err, net.ErrClosed) {
		return false
	}
	var errno syscall.Errno
	if !errors.As(err, &errno) {
		if temporary, ok := err.(interface{ Temporary() bool }); ok {
			return temporary.Temporary()
		}
		return false
	}
	switch uint32(errno) {
	case 4, // EINTR
		90,    // EMSGSIZE
		104,   // ECONNRESET (Linux)
		111,   // ECONNREFUSED (Linux)
		113,   // EHOSTUNREACH (Linux)
		10004, // WSAEINTR
		10040, // WSAEMSGSIZE
		10054, // WSAECONNRESET
		10061, // WSAECONNREFUSED
		10065: // WSAEHOSTUNREACH
		return true
	default:
		return false
	}
}

func (runtime *nativeSocks5Runtime) ensureAssociation(key string, client *net.UDPAddr) *nativeSocks5UDPAssociation {
	if key == "" {
		key = "local"
	}
	runtime.associationMu.Lock()
	defer runtime.associationMu.Unlock()
	if existing := runtime.associations[key]; existing != nil {
		if client != nil {
			existing.mu.Lock()
			if existing.client == nil {
				existing.client = cloneUDPAddr(client)
			}
			existing.mu.Unlock()
		}
		return existing
	}
	association := &nativeSocks5UDPAssociation{
		client: cloneUDPAddr(client), clientHost: nativeSocks5ClientHost(client), controlKey: key,
		ready: make(chan struct{}), pending: make([]nativeSocks5UDPRequest, 0, nativeSocks5UDPQueuePackets),
	}
	runtime.associations[key] = association
	return association
}

// armNativeAssociationCandidates establishes a native UDP association from
// the current candidate batch. A failed provider is marked before trying the
// next sibling, so the next local packet also starts from the newly healthy
// candidate. The returned lease contains metadata only after the session is
// established and is owned by the caller.
func armNativeAssociationCandidates(
	ctx context.Context,
	store *PolicyStore,
	config Config,
	status *StatusWriter,
	arm func(ProviderLease, time.Duration) (*DatagramSession, error),
) (*DatagramSession, ProviderLease, error) {
	candidates := store.CandidateSnapshots("udp")
	defer wipeSnapshots(candidates)
	if len(candidates) == 0 {
		return nil, ProviderLease{}, errHACandidatesUnavailable
	}
	timeout := time.Duration(config.ConnectTimeoutSecs) * time.Second
	if timeout < 5*time.Second || timeout > 30*time.Second {
		timeout = 15 * time.Second
	}
	deadline := time.Now().Add(timeout)
	var lastErr error
	for index, snapshot := range candidates {
		if err := ctx.Err(); err != nil {
			return nil, ProviderLease{}, err
		}
		if !store.acceptsInFlight(snapshot) {
			return nil, ProviderLease{}, ErrPolicyExpired
		}
		budget := time.Until(deadline) / time.Duration(len(candidates)-index)
		if expiry := time.Until(snapshot.Lease.ExpiresAt); expiry < budget {
			budget = expiry
		}
		if budget <= 0 {
			break
		}
		session, err := arm(snapshot.Lease, budget)
		if err == nil {
			if ctx.Err() != nil || !store.acceptsInFlight(snapshot) {
				if session != nil {
					session.Close()
				}
				return nil, ProviderLease{}, ErrPolicyExpired
			}
			return session, cloneHALease(snapshot.Lease), nil
		}
		if ctx.Err() != nil {
			return nil, ProviderLease{}, ctx.Err()
		}
		key := snapshot.Lease.CandidateKeyHash
		if strings.TrimSpace(key) == "" {
			key = snapshot.Lease.SelectionRef
		}
		store.MarkCandidateFailure(key)
		if status != nil {
			status.RecordCandidateFailure(key, "udp", udpArmFailureReason(err), snapshot.Lease.LeaseHash)
			status.RecordHAEvent("udp", haErrorClass(err), snapshot.Policy.PolicyGeneration)
		}
		log.Printf("native udp candidate failed forward_id=%d selection_ref=%s attempt=%d reason=%s",
			snapshot.Policy.ForwardID, snapshot.Lease.SelectionRef, index+1, haErrorClass(err))
		lastErr = err
	}
	if lastErr == nil {
		lastErr = context.DeadlineExceeded
	}
	return nil, ProviderLease{}, fmt.Errorf("%w: %v", errHACandidatesUnavailable, lastErr)
}

func (runtime *nativeSocks5Runtime) setupUDPAssociation(ctx context.Context, association *nativeSocks5UDPAssociation) {
	started := time.Now()
	session, lease, err := armNativeAssociationCandidates(ctx, runtime.store, runtime.config, runtime.status, armDatagram)
	if err != nil {
		if runtime.status != nil {
			runtime.status.AddUDPSetupFailure()
			runtime.status.UpdateError("udp_association_failed")
		}
		association.close()
		runtime.removeAssociation(association)
		return
	}
	// The response loop owns this socket's reads from here on. Mark ownership
	// and spawn the reader before the association becomes visible to direct
	// sends, so a send-path ceiling probe always waits on the reader instead of
	// racing it for SetReadDeadline/Read (2026-09-16 reader-race fix).
	if runtime.status != nil {
		session.onCeilingMeasured = runtime.status.UpdateUDPPayloadCeiling
	}
	session.markSocketReaderOwned()
	go runtime.readUDPResponses(association, session)
	if !association.markReady(session, lease) {
		lease.wipe()
		return
	}
	if runtime.status != nil {
		// Publish the measured ceiling and the panel hint of this association so
		// the panel can see which relay is narrow.
		runtime.status.UpdateUDPWithTierAndCandidate(lease.SelectionRef, lease.SelectionTier, lease.CandidateKeyHash, time.Since(started), lease.LeaseHash)
		runtime.status.UpdateUDPPayloadCeiling(normalizeRelayPayloadCeiling(lease.RelayPayloadCeiling), payloadCeilingSourceDeclared)
		runtime.status.RecordCandidateSuccess(lease.CandidateKeyHash, "udp", lease.LeaseHash)
	}
	runtime.store.MarkCandidateSuccess(lease.CandidateKeyHash)
	lease.wipe()
	runtime.startNativeAssociationKeepalive(ctx, association)
	for _, request := range association.takePending() {
		runtime.sendUDPRequest(association, session, request)
	}
}

func (runtime *nativeSocks5Runtime) startNativeAssociationKeepalive(ctx context.Context, association *nativeSocks5UDPAssociation) {
	interval := resolveUDPKeepaliveInterval(runtime.config)
	if interval <= 0 {
		return
	}
	keepaliveContext, keepaliveCancel := context.WithCancel(ctx)
	association.mu.Lock()
	if association.closed || association.session == nil {
		association.mu.Unlock()
		keepaliveCancel()
		return
	}
	association.keepaliveCancel = keepaliveCancel
	association.mu.Unlock()
	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		for {
			select {
			case <-keepaliveContext.Done():
				return
			case <-ticker.C:
				association.writeMu.Lock()
				association.mu.Lock()
				if association.closed || association.session == nil {
					association.mu.Unlock()
					association.writeMu.Unlock()
					return
				}
				session := association.session
				association.mu.Unlock()
				err := sendDatagramKeepalive(session)
				association.writeMu.Unlock()
				if err != nil {
					if !association.isClosed() {
						association.recordCandidateFailure(runtime, "udp", "udp_association_failed", err)
					}
					association.close()
					runtime.removeAssociation(association)
					return
				}
			}
		}
	}()
}

func (runtime *nativeSocks5Runtime) sendUDPRequest(association *nativeSocks5UDPAssociation, session *DatagramSession, request nativeSocks5UDPRequest) {
	defer clearBytes(request.payload)
	targetIP, err := resolveNativeUDPHost(request.host)
	if err != nil {
		log.Printf("native UDP target resolve failed: %v", err)
		return
	}
	if !session.allowsPayload(len(request.payload)) {
		// Refuse instead of handing the path a datagram it will silently drop.
		if runtime.status != nil {
			runtime.status.AddUDPOversizeDrop()
		}
		if session.payloadUnusable() {
			// Nothing echoes on this association. Reuse the existing candidate
			// failure path so the next packet arms a healthy sibling instead of
			// black-holing every large datagram until the policy expires.
			association.recordCandidateFailure(runtime, "udp", udpPayloadCeilingUnusableReason, errDatagramUnusable)
			association.close()
			runtime.removeAssociation(association)
			return
		}
		if session.onRuntimeDrop != nil {
			session.onRuntimeDrop()
		}
		return
	}
	packet, err := makeDatagramEnvelope(session.mode, session.sid, targetIP, request.port, request.payload)
	if err != nil {
		return
	}
	association.writeMu.Lock()
	defer association.writeMu.Unlock()
	if association.currentSession() != session {
		return
	}
	if _, err := session.remote.Write(packet); err != nil {
		if !association.isClosed() {
			association.recordCandidateFailure(runtime, "udp", "udp_association_failed", err)
		}
		association.close()
		runtime.removeAssociation(association)
	}
}

func (runtime *nativeSocks5Runtime) readUDPResponses(association *nativeSocks5UDPAssociation, session *DatagramSession) {
	buffer := make([]byte, 65535)
	for {
		count, err := session.remote.Read(buffer)
		if err != nil {
			if !association.isClosed() {
				association.recordCandidateFailure(runtime, "udp", "udp_association_failed", err)
			}
			association.close()
			runtime.removeAssociation(association)
			return
		}
		packet := buffer[:count]
		if session.dispatchProbeEcho(packet) {
			continue
		}
		if isDatagramKeepaliveResponse(packet, nil) {
			continue
		}
		info, valid := decodeDatagramResponse(packet)
		if !valid || len(info.payload) == 0 {
			continue
		}
		if err := runtime.store.Limiter().Consume(len(info.payload)); err != nil {
			if runtime.status != nil {
				runtime.status.UpdateError("policy_stopped")
			}
			continue
		}
		if runtime.status != nil {
			runtime.status.AddUsage(len(info.payload), 0)
		}
		client := association.clientAddress()
		if client == nil {
			continue
		}
		host := datagramInfoHost(info)
		if host == "" {
			continue
		}
		response, err := makeNativeSocks5UDPDatagram(host, int(info.port), info.payload)
		if err != nil {
			continue
		}
		_, _ = runtime.udp.WriteToUDP(response, client)
	}
}

func datagramInfoHost(info datagramResponseInfo) string {
	switch info.addressType {
	case 1:
		if len(info.address) == net.IPv4len {
			return net.IP(info.address).String()
		}
	case 3:
		return string(info.address)
	case 4:
		if len(info.address) == net.IPv6len {
			return net.IP(info.address).String()
		}
	}
	return ""
}

func (runtime *nativeSocks5Runtime) removeAssociation(association *nativeSocks5UDPAssociation) {
	if association == nil {
		return
	}
	runtime.associationMu.Lock()
	if current := runtime.associations[association.controlKey]; current == association {
		delete(runtime.associations, association.controlKey)
	}
	runtime.associationMu.Unlock()
}

func (runtime *nativeSocks5Runtime) closeAssociations() {
	runtime.associationMu.Lock()
	all := make([]*nativeSocks5UDPAssociation, 0, len(runtime.associations))
	for key, association := range runtime.associations {
		delete(runtime.associations, key)
		all = append(all, association)
	}
	runtime.associationMu.Unlock()
	for _, association := range all {
		association.close()
	}
}

func nativeSocks5ClientKey(address net.Addr) string {
	return nativeSocks5ClientHost(address)
}

func nativeSocks5ClientHost(address net.Addr) string {
	if address == nil {
		return ""
	}
	if host, _, err := net.SplitHostPort(address.String()); err == nil {
		return host
	}
	return strings.TrimSpace(address.String())
}

func nativeSocks5UDPClientKey(address *net.UDPAddr) string {
	if address == nil {
		return ""
	}
	host := nativeSocks5ClientHost(address)
	if host == "" {
		return ""
	}
	return net.JoinHostPort(host, fmt.Sprint(address.Port))
}

func (runtime *nativeSocks5Runtime) addControl(host string) {
	runtime.controlMu.Lock()
	runtime.controlRefs[host]++
	runtime.controlMu.Unlock()
}

func (runtime *nativeSocks5Runtime) releaseControl(host string) {
	runtime.controlMu.Lock()
	count := runtime.controlRefs[host]
	if count <= 1 {
		delete(runtime.controlRefs, host)
	} else {
		runtime.controlRefs[host] = count - 1
	}
	remaining := count - 1
	runtime.controlMu.Unlock()
	if remaining > 0 {
		return
	}

	runtime.associationMu.Lock()
	stale := make([]*nativeSocks5UDPAssociation, 0)
	for key, association := range runtime.associations {
		if association == nil || association.clientHost != host {
			continue
		}
		delete(runtime.associations, key)
		stale = append(stale, association)
	}
	runtime.associationMu.Unlock()
	for _, association := range stale {
		association.close()
	}
}

func cloneUDPAddr(address *net.UDPAddr) *net.UDPAddr {
	if address == nil {
		return nil
	}
	copy := *address
	copy.IP = append(net.IP(nil), address.IP...)
	return &copy
}

func (association *nativeSocks5UDPAssociation) clientAddress() *net.UDPAddr {
	association.mu.Lock()
	defer association.mu.Unlock()
	return cloneUDPAddr(association.client)
}
