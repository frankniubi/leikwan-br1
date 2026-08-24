package main

import (
	"bytes"
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
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
	"sync/atomic"
	"syscall"
	"time"
)

const keepPayloadHex = "\x00\x00\xa4\x64\x34\xc9\xb6\x2b\x82\xee\xd2\xa8\x7f\xaa\x6a\x5f\x45\xac"

type Config struct {
	ForwardID          int                 `json:"forward_id"`
	ListenHost         string              `json:"listen_host"`
	ListenPort         int                 `json:"listen_port"`
	Target             string              `json:"target"`
	AccessFile         string              `json:"access_file"`
	Transport          string              `json:"transport"`
	RateLimitMbps      int                 `json:"rate_limit_mbps"`
	MaxTCPConnections  int                 `json:"max_tcp_connections"`
	UDPQueuePackets    int                 `json:"udp_queue_packets"`
	ConnectTimeoutSecs int                 `json:"connect_timeout_seconds"`
	LocationCachePath  string              `json:"location_cache_path"`
	LocationLockPath   string              `json:"location_lock_path"`
	StatusPath         string              `json:"status_path"`
	ProbeTimeoutMillis int                 `json:"probe_timeout_ms"`
	ProbeWorkers       int                 `json:"probe_workers"`
	ZoneAliases        map[string][]string `json:"zone_aliases"`
}

type Entry struct {
	EntryRef        string `json:"entry_ref"`
	IngressZone     string `json:"ingress_zone"`
	DestinationZone string `json:"destination_zone"`
	IP              string `json:"ip"`
	Port            int    `json:"port"`
	User            string `json:"user"`
	Credential      string `json:"credential"`
}

type Access struct {
	Token          string         `json:"token"`
	ExpiresAt      string         `json:"expires_at"`
	SealedMaterial string         `json:"sealed_material"`
	Entries        []Entry        `json:"-"`
	Policy         MaterialPolicy `json:"-"`
	Location       LocationResult `json:"-"`
}

type SealedPayload struct {
	Entries []Entry        `json:"entries"`
	Policy  MaterialPolicy `json:"policy"`
}

func (a Access) validate() error {
	if strings.TrimSpace(a.Token) == "" || len(a.Token) > 8192 {
		return errors.New("access token invalid")
	}
	expires, err := time.Parse(time.RFC3339, a.ExpiresAt)
	if err != nil || !expires.After(time.Now()) {
		return errors.New("access expired")
	}
	if !strings.HasPrefix(a.SealedMaterial, "um1.") || len(a.SealedMaterial) > 65536 {
		return errors.New("sealed material invalid")
	}
	payload, err := decryptMaterial(a.Token, a.SealedMaterial)
	if err != nil || len(payload.Entries) == 0 || len(payload.Entries) > 8 {
		return errors.New("access entries invalid")
	}
	if payload.Policy.FallbackPolicy != "detected_only" && payload.Policy.FallbackPolicy != "detected_then_any" && payload.Policy.FallbackPolicy != "any" {
		return errors.New("access policy invalid")
	}
	for _, entry := range payload.Entries {
		if len(entry.EntryRef) < 16 || len(entry.EntryRef) > 128 || !validZoneCode(entry.IngressZone) || !validZoneCode(entry.DestinationZone) || net.ParseIP(entry.IP) == nil || entry.Port < 1 || entry.Port > 65535 || entry.User == "" || entry.Credential == "" || len(entry.User) > 255 || len(entry.Credential) > 255 {
			return errors.New("access entry invalid")
		}
	}
	return nil
}

func decodeURLBase64(value string) ([]byte, error) {
	if remainder := len(value) % 4; remainder != 0 {
		value += strings.Repeat("=", 4-remainder)
	}
	return base64.URLEncoding.DecodeString(value)
}

func decryptMaterial(token, encoded string) (SealedPayload, error) {
	if token == "" || !strings.HasPrefix(encoded, "um1.") {
		return SealedPayload{}, errors.New("sealed material invalid")
	}
	raw, err := decodeURLBase64(strings.TrimPrefix(encoded, "um1."))
	if err != nil || len(raw) <= 28 {
		return SealedPayload{}, errors.New("sealed material invalid")
	}
	key := sha256.Sum256([]byte(token))
	block, err := aes.NewCipher(key[:])
	if err != nil {
		return SealedPayload{}, err
	}
	gcm, err := cipher.NewGCMWithTagSize(block, 16)
	if err != nil {
		return SealedPayload{}, err
	}
	combined := append(append([]byte(nil), raw[28:]...), raw[12:28]...)
	plain, err := gcm.Open(nil, raw[:12], combined, nil)
	if err != nil {
		return SealedPayload{}, err
	}
	var payload SealedPayload
	if err := json.Unmarshal(plain, &payload); err != nil {
		return SealedPayload{}, err
	}
	return payload, nil
}

type AccessStore struct {
	path string
	mu   sync.RWMutex
	data Access
}

func (s *AccessStore) Load() error {
	raw, err := os.ReadFile(s.path)
	if err != nil {
		return err
	}
	var access Access
	if err := json.Unmarshal(raw, &access); err != nil {
		return err
	}
	if err := access.validate(); err != nil {
		return err
	}
	payload, err := decryptMaterial(access.Token, access.SealedMaterial)
	if err != nil {
		return err
	}
	access.Entries = payload.Entries
	access.Policy = payload.Policy
	s.mu.Lock()
	s.data = access
	s.mu.Unlock()
	return nil
}

func (s *AccessStore) Snapshot() Access {
	s.mu.RLock()
	defer s.mu.RUnlock()
	copyValue := s.data
	copyValue.Entries = append([]Entry(nil), s.data.Entries...)
	copyValue.Policy.AllowedIngressZones = append([]string(nil), s.data.Policy.AllowedIngressZones...)
	if expires, err := time.Parse(time.RFC3339, copyValue.ExpiresAt); err != nil || !expires.After(time.Now()) {
		copyValue.Entries = nil
	}
	return copyValue
}

func (s *AccessStore) SetRanked(entries []Entry, location LocationResult) {
	s.mu.Lock()
	s.data.Entries = append([]Entry(nil), entries...)
	s.data.Location = location
	s.mu.Unlock()
}

type ByteLimiter struct {
	mu       sync.Mutex
	rate     float64
	capacity float64
	tokens   float64
	last     time.Time
}

func newByteLimiter(mbps int) *ByteLimiter {
	if mbps <= 0 {
		return &ByteLimiter{}
	}
	rate := float64(mbps) * 1_000_000 / 8
	return &ByteLimiter{rate: rate, capacity: rate, tokens: rate, last: time.Now()}
}

func (l *ByteLimiter) Wait(n int) {
	if l.rate <= 0 || n <= 0 {
		return
	}
	for {
		l.mu.Lock()
		now := time.Now()
		l.tokens += now.Sub(l.last).Seconds() * l.rate
		if l.tokens > l.capacity {
			l.tokens = l.capacity
		}
		l.last = now
		if l.tokens >= float64(n) {
			l.tokens -= float64(n)
			l.mu.Unlock()
			return
		}
		missing := float64(n) - l.tokens
		l.tokens = 0
		l.mu.Unlock()
		time.Sleep(time.Duration(missing/l.rate*float64(time.Second)) + time.Millisecond)
	}
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

func buildHandshake(user, credential string, command byte, host string, port int) ([]byte, error) {
	if len(user) == 0 || len(user) > 255 || len(credential) == 0 || len(credential) > 255 {
		return nil, errors.New("credential length invalid")
	}
	var buffer bytes.Buffer
	buffer.Write([]byte{5, 3, 2, 0x80, 0x83, 1, byte(len(user))})
	buffer.WriteString(user)
	buffer.WriteByte(byte(len(credential)))
	buffer.WriteString(credential)
	buffer.Write([]byte{5, command, credentialChecksum(credential)})
	if err := appendAddress(&buffer, host, port); err != nil {
		return nil, err
	}
	return buffer.Bytes(), nil
}

func readStreamAcks(conn net.Conn) error {
	if err := conn.SetReadDeadline(time.Now().Add(3 * time.Second)); err != nil {
		return err
	}
	first := make([]byte, 2)
	if _, err := io.ReadFull(conn, first); err != nil || !bytes.Equal(first, []byte{5, 2}) {
		return errors.New("stream method rejected")
	}
	second := make([]byte, 2)
	if _, err := io.ReadFull(conn, second); err != nil || !(bytes.Equal(second, []byte{1, 0}) || bytes.Equal(second, []byte{5, 2})) {
		return errors.New("stream authentication rejected")
	}
	final := make([]byte, 10)
	if _, err := io.ReadFull(conn, final); err != nil || len(final) < 3 || final[2] == 0 {
		return errors.New("stream command rejected")
	}
	return conn.SetReadDeadline(time.Time{})
}

func xorOne(value []byte) []byte {
	result := make([]byte, len(value))
	for index, item := range value {
		result[index] = item ^ 1
	}
	return result
}

func dialStream(entry Entry, targetHost string, targetPort int, timeout time.Duration) (net.Conn, error) {
	conn, err := net.DialTimeout("tcp", net.JoinHostPort(entry.IP, fmt.Sprint(entry.Port)), timeout)
	if err != nil {
		return nil, err
	}
	handshake, err := buildHandshake(entry.User, entry.Credential, 1, targetHost, targetPort)
	if err == nil {
		_, err = conn.Write(handshake)
	}
	if err == nil {
		err = readStreamAcks(conn)
	}
	if err != nil {
		conn.Close()
		return nil, err
	}
	return conn, nil
}

func copyOutbound(destination net.Conn, source net.Conn, limiter *ByteLimiter) error {
	buffer := make([]byte, 32*1024)
	for {
		count, readErr := source.Read(buffer)
		if count > 0 {
			limiter.Wait(count)
			if _, err := destination.Write(xorOne(buffer[:count])); err != nil {
				return err
			}
		}
		if readErr != nil {
			return readErr
		}
	}
}

func copyInbound(destination net.Conn, source net.Conn, limiter *ByteLimiter) error {
	buffer := make([]byte, 32*1024)
	for {
		count, readErr := source.Read(buffer)
		if count > 0 {
			limiter.Wait(count)
			if _, err := destination.Write(buffer[:count]); err != nil {
				return err
			}
		}
		if readErr != nil {
			return readErr
		}
	}
}

func serveTCP(config Config, store *AccessStore, limiter *ByteLimiter, targetHost string, targetPort int, status *StatusWriter) error {
	listener, err := net.Listen("tcp", net.JoinHostPort(config.ListenHost, fmt.Sprint(config.ListenPort)))
	if err != nil {
		return err
	}
	defer listener.Close()
	limit := config.MaxTCPConnections
	if limit < 1 || limit > 4096 {
		limit = 512
	}
	semaphore := make(chan struct{}, limit)
	for {
		local, err := listener.Accept()
		if err != nil {
			return err
		}
		select {
		case semaphore <- struct{}{}:
			go func() {
				defer func() { <-semaphore }()
				defer local.Close()
				access := store.Snapshot()
				if len(access.Entries) == 0 {
					return
				}
				for _, entry := range access.Entries {
					started := time.Now()
					remote, dialErr := dialStream(entry, targetHost, targetPort, time.Duration(config.ConnectTimeoutSecs)*time.Second)
					if dialErr != nil {
						continue
					}
					status.UpdateTCP(entry, time.Since(started))
					defer remote.Close()
					done := make(chan struct{}, 2)
					go func() { _ = copyOutbound(remote, local, limiter); done <- struct{}{} }()
					go func() { _ = copyInbound(local, remote, limiter); done <- struct{}{} }()
					<-done
					return
				}
				status.UpdateError("tcp_connect_failed")
			}()
		default:
			local.Close()
		}
	}
}

func readControlReply(conn net.Conn) ([]byte, error) {
	if err := conn.SetReadDeadline(time.Now().Add(3 * time.Second)); err != nil {
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
	return nil, errors.New("datagram control reply incomplete")
}

type DatagramSession struct {
	control *net.TCPConn
	remote  *net.UDPConn
	mode    byte
	sid     []byte
}

func (session *DatagramSession) Close() {
	if session.remote != nil {
		session.remote.Close()
	}
	if session.control != nil {
		session.control.Close()
	}
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

func unwrapDatagram(packet []byte) ([]byte, bool) {
	if len(packet) < 10 || !bytes.Equal(packet[:4], []byte{0, 0, 0, 1}) {
		return nil, false
	}
	return packet[10:], true
}

func armDatagram(entry Entry, timeout time.Duration) (*DatagramSession, error) {
	address := net.JoinHostPort(entry.IP, fmt.Sprint(entry.Port))
	conn, err := net.DialTimeout("tcp", address, timeout)
	if err != nil {
		return nil, err
	}
	control := conn.(*net.TCPConn)
	handshake, err := buildHandshake(entry.User, entry.Credential, 3, entry.IP, entry.Port)
	if err == nil {
		_, err = control.Write(handshake)
	}
	var reply []byte
	if err == nil {
		reply, err = readControlReply(control)
	}
	if err != nil {
		control.Close()
		return nil, err
	}
	marker := bytes.LastIndex(reply, []byte{4, 1, 1, 0, 1})
	if marker < 0 || marker+10 > len(reply) {
		control.Close()
		return nil, errors.New("datagram control marker missing")
	}
	reply3 := reply[marker+5 : marker+8]
	remotePort := int(binary.BigEndian.Uint16(reply[marker+8:marker+10]) ^ 0x0101)
	remoteAddress, err := net.ResolveUDPAddr("udp4", net.JoinHostPort(entry.IP, fmt.Sprint(remotePort)))
	if err != nil {
		control.Close()
		return nil, err
	}
	remote, err := net.DialUDP("udp4", nil, remoteAddress)
	if err != nil {
		control.Close()
		return nil, err
	}
	mode := reply3[0] ^ 1
	sidPrefix := []byte{reply3[1] ^ 1, reply3[2] ^ 1}
	keep := []byte(keepPayloadHex)
	buffer := make([]byte, 2048)
	for candidate := byte(0x20); candidate < 0x40; candidate++ {
		sid := append(append([]byte(nil), sidPrefix...), candidate)
		packet, _ := makeDatagramEnvelope(mode, sid, "2.0.0.0", 0, keep)
		_ = remote.SetReadDeadline(time.Now().Add(250 * time.Millisecond))
		if _, err := remote.Write(packet); err != nil {
			continue
		}
		if _, err := remote.Read(buffer); err == nil {
			_ = remote.SetReadDeadline(time.Time{})
			return &DatagramSession{control: control, remote: remote, mode: mode, sid: sid}, nil
		}
	}
	remote.Close()
	control.Close()
	return nil, errors.New("datagram session could not be established")
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

func serveUDP(config Config, store *AccessStore, limiter *ByteLimiter, targetHost string, targetPort int, reload <-chan struct{}, status *StatusWriter) error {
	localAddress, err := net.ResolveUDPAddr("udp", net.JoinHostPort(config.ListenHost, fmt.Sprint(config.ListenPort)))
	if err != nil {
		return err
	}
	local, err := net.ListenUDP("udp", localAddress)
	if err != nil {
		return err
	}
	defer local.Close()
	targetIP, err := resolveIPv4(targetHost)
	if err != nil {
		return err
	}
	var session *DatagramSession
	var lastClient atomic.Pointer[net.UDPAddr]
	sessionMu := sync.RWMutex{}
	arm := func() error {
		access := store.Snapshot()
		if len(access.Entries) == 0 {
			return errors.New("access expired")
		}
		var next *DatagramSession
		var armErr error
		var selected Entry
		var selectedRTT time.Duration
		for _, entry := range access.Entries {
			started := time.Now()
			next, armErr = armDatagram(entry, time.Duration(config.ConnectTimeoutSecs)*time.Second)
			if armErr == nil {
				selected = entry
				selectedRTT = time.Since(started)
				break
			}
		}
		if next == nil {
			status.UpdateError("udp_association_failed")
			return armErr
		}
		sessionMu.Lock()
		previous := session
		session = next
		sessionMu.Unlock()
		if previous != nil {
			previous.Close()
		}
		status.UpdateUDP(selected, selectedRTT)
		go func(current *DatagramSession) {
			buffer := make([]byte, 65535)
			for {
				count, readErr := current.remote.Read(buffer)
				if readErr != nil {
					return
				}
				payload, ok := unwrapDatagram(buffer[:count])
				client := lastClient.Load()
				if ok && client != nil {
					limiter.Wait(len(payload))
					_, _ = local.WriteToUDP(payload, client)
				}
			}
		}(next)
		return nil
	}
	if err := arm(); err != nil {
		return err
	}
	defer func() {
		if session != nil {
			session.Close()
		}
	}()
	go func() {
		for range reload {
			if err := arm(); err != nil {
				log.Printf("datagram reload failed: %v", err)
			}
		}
	}()
	buffer := make([]byte, 65535)
	for {
		count, client, readErr := local.ReadFromUDP(buffer)
		if readErr != nil {
			return readErr
		}
		clientCopy := *client
		lastClient.Store(&clientCopy)
		limiter.Wait(count)
		sessionMu.RLock()
		current := session
		if current != nil {
			packet, packetErr := makeDatagramEnvelope(current.mode, current.sid, targetIP, targetPort, buffer[:count])
			if packetErr == nil {
				_, _ = current.remote.Write(packet)
			}
		}
		sessionMu.RUnlock()
	}
}

func loadAndRankAccess(config Config, store *AccessStore) (LocationResult, error) {
	if err := store.Load(); err != nil {
		return LocationResult{}, err
	}
	locationContext, cancelLocation := context.WithTimeout(context.Background(), locationRequestTimeout+time.Second)
	location := DetectLocation(locationContext, config.LocationCachePath, config.LocationLockPath, config.ZoneAliases)
	cancelLocation()
	access := store.Snapshot()
	probeTimeout := time.Duration(config.ProbeTimeoutMillis) * time.Millisecond
	probeContext, cancelProbe := context.WithTimeout(context.Background(), probeTimeout*3)
	defer cancelProbe()
	probe := func(parent context.Context, entry Entry) (time.Duration, error) {
		entryContext, cancel := context.WithTimeout(parent, probeTimeout)
		defer cancel()
		return probeEntry(entryContext, entry)
	}
	ranked := rankEntries(probeContext, access.Entries, access.Policy, location.Code, config.ProbeWorkers, probe)
	if len(ranked) == 0 {
		return location, errors.New("no eligible access entries")
	}
	store.SetRanked(ranked, location)
	return location, nil
}

func main() {
	configPath := flag.String("config", "/etc/ultra/forward.json", "runtime configuration")
	flag.Parse()
	raw, err := os.ReadFile(*configPath)
	if err != nil {
		log.Fatal(err)
	}
	var config Config
	if err := json.Unmarshal(raw, &config); err != nil {
		log.Fatal(err)
	}
	if config.ListenHost == "" {
		config.ListenHost = "0.0.0.0"
	}
	if config.ListenPort < 1 || config.ListenPort > 65535 || config.AccessFile == "" {
		log.Fatal("runtime configuration invalid")
	}
	if config.ConnectTimeoutSecs < 1 || config.ConnectTimeoutSecs > 30 {
		config.ConnectTimeoutSecs = 5
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
	targetHost, targetPort, err := parseTarget(config.Target)
	if err != nil {
		log.Fatal(err)
	}
	store := &AccessStore{path: config.AccessFile}
	status := newStatusWriter(config.StatusPath, config.ForwardID, 5*time.Second)
	location, err := loadAndRankAccess(config, store)
	if err != nil {
		status.UpdateError("access_load_failed")
		status.Close()
		log.Fatal(err)
	}
	status.UpdateLocation(location)
	reloadRequests := make(chan struct{}, 1)
	datagramReload := make(chan struct{}, 1)
	signals := make(chan os.Signal, 1)
	signal.Notify(signals, syscall.SIGHUP)
	go func() {
		for range signals {
			select {
			case reloadRequests <- struct{}{}:
			default:
			}
		}
	}()
	go func() {
		for range reloadRequests {
			location, err := loadAndRankAccess(config, store)
			if err != nil {
				status.UpdateError("access_reload_failed")
				log.Printf("access reload failed: %v", err)
				continue
			}
			status.UpdateLocation(location)
			select {
			case datagramReload <- struct{}{}:
			default:
			}
		}
	}()
	limiter := newByteLimiter(config.RateLimitMbps)
	transport := strings.ToLower(config.Transport)
	errorsOut := make(chan error, 2)
	if transport == "tcp" || transport == "tcp+udp" {
		go func() { errorsOut <- serveTCP(config, store, limiter, targetHost, targetPort, status) }()
	}
	if transport == "udp" || transport == "tcp+udp" {
		go func() { errorsOut <- serveUDP(config, store, limiter, targetHost, targetPort, datagramReload, status) }()
	}
	if transport != "tcp" && transport != "udp" && transport != "tcp+udp" {
		log.Fatal("transport invalid")
	}
	runtimeErr := <-errorsOut
	status.UpdateError("runtime_stopped")
	status.Close()
	log.Fatal(runtimeErr)
}
