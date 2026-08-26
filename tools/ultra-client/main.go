package main

import (
	"bytes"
	"context"
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
	"syscall"
	"time"
)

const keepPayloadHex = "\x00\x00\xa4\x64\x34\xc9\xb6\x2b\x82\xee\xd2\xa8\x7f\xaa\x6a\x5f\x45\xac"

type Config struct {
	ForwardID          int                 `json:"forward_id"`
	ListenHost         string              `json:"listen_host"`
	ListenPort         int                 `json:"listen_port"`
	Target             string              `json:"target"`
	ControlSocket      string              `json:"control_socket"`
	PolicyGeneration   string              `json:"policy_generation"`
	TargetDigest       string              `json:"target_digest"`
	Transport          string              `json:"transport"`
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

func dialStream(lease ProviderLease, targetHost string, targetPort int, timeout time.Duration) (net.Conn, error) {
	conn, err := net.DialTimeout("tcp", net.JoinHostPort(lease.IP, fmt.Sprint(lease.Port)), timeout)
	if err != nil {
		return nil, err
	}
	handshake, err := buildHandshake(string(lease.User), string(lease.Password), 1, targetHost, targetPort)
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

func copyOutbound(destination net.Conn, source net.Conn, limiter *PolicyLimiter, status *StatusWriter) error {
	buffer := make([]byte, 32*1024)
	for {
		count, readErr := source.Read(buffer)
		if count > 0 {
			if err := limiter.Consume(count); err != nil {
				return err
			}
			status.AddUsage(0, count)
			if _, err := destination.Write(xorOne(buffer[:count])); err != nil {
				return err
			}
		}
		if readErr != nil {
			return readErr
		}
	}
}

func copyInbound(destination net.Conn, source net.Conn, limiter *PolicyLimiter, status *StatusWriter) error {
	buffer := make([]byte, 32*1024)
	for {
		count, readErr := source.Read(buffer)
		if count > 0 {
			if err := limiter.Consume(count); err != nil {
				return err
			}
			status.AddUsage(count, 0)
			if _, err := destination.Write(buffer[:count]); err != nil {
				return err
			}
		}
		if readErr != nil {
			return readErr
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
				snapshot, ok := store.Snapshot()
				if !ok {
					status.UpdateError("policy_stopped")
					return
				}
				lease := snapshot.Lease
				defer lease.wipe()
				started := time.Now()
				remote, dialErr := dialStream(lease, targetHost, targetPort, time.Duration(config.ConnectTimeoutSecs)*time.Second)
				if dialErr != nil {
					status.UpdateError("tcp_connect_failed")
					return
				}
				defer remote.Close()
				connectionDone := make(chan struct{})
				go func() {
					select {
					case <-ctx.Done():
						_ = local.Close()
						_ = remote.Close()
					case <-connectionDone:
					}
				}()
				defer close(connectionDone)
				status.UpdateTCP(lease.SelectionRef, time.Since(started))
				done := make(chan struct{}, 2)
				go func() { _ = copyOutbound(remote, local, store.Limiter(), status); done <- struct{}{} }()
				go func() { _ = copyInbound(local, remote, store.Limiter(), status); done <- struct{}{} }()
				<-done
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

func armDatagram(lease ProviderLease, timeout time.Duration) (*DatagramSession, error) {
	address := net.JoinHostPort(lease.IP, fmt.Sprint(lease.Port))
	conn, err := net.DialTimeout("tcp", address, timeout)
	if err != nil {
		return nil, err
	}
	control := conn.(*net.TCPConn)
	handshake, err := buildHandshake(string(lease.User), string(lease.Password), 3, lease.IP, lease.Port)
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
	remoteAddress, err := net.ResolveUDPAddr("udp4", net.JoinHostPort(lease.IP, fmt.Sprint(remotePort)))
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

func watchRuntimePolicy(ctx context.Context, cancel context.CancelFunc, store *PolicyStore) {
	ticker := time.NewTicker(250 * time.Millisecond)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-store.Updates():
			if _, ok := store.Snapshot(); !ok {
				cancel()
				return
			}
		case <-ticker.C:
			store.ClearExpired()
			if _, ok := store.Snapshot(); !ok {
				cancel()
				return
			}
		}
	}
}

type udpAssociation struct {
	session   *DatagramSession
	client    *net.UDPAddr
	lastSeen  time.Time
	packetCnt int
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
	defer local.Close()
	targetIP, err := resolveIPv4(targetHost)
	if err != nil {
		return err
	}
	associations := make(map[string]*udpAssociation)
	const maxAssociations = 64
	const maxAssociationPackets = 100000
	const associationIdle = 2 * time.Minute
	var associationMu sync.Mutex
	closeAssociation := func(key string) {
		associationMu.Lock()
		association := associations[key]
		delete(associations, key)
		associationMu.Unlock()
		if association != nil && association.session != nil {
			association.session.Close()
		}
	}
	defer func() {
		associationMu.Lock()
		all := make([]*DatagramSession, 0, len(associations))
		for key, association := range associations {
			delete(associations, key)
			if association != nil && association.session != nil {
				all = append(all, association.session)
			}
		}
		associationMu.Unlock()
		for _, session := range all {
			session.Close()
		}
	}()
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
				stale := make([]string, 0)
				for key, association := range associations {
					if now.Sub(association.lastSeen) > associationIdle {
						stale = append(stale, key)
					}
				}
				associationMu.Unlock()
				for _, key := range stale {
					closeAssociation(key)
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
		if err := store.Limiter().Consume(count); err != nil {
			status.UpdateError("policy_stopped")
			continue
		}
		snapshot, ok := store.Snapshot()
		if !ok {
			status.UpdateError("policy_stopped")
			continue
		}
		key := client.String()
		associationMu.Lock()
		association := associations[key]
		if association != nil {
			association.lastSeen = time.Now()
			association.packetCnt++
			if association.packetCnt > maxAssociationPackets {
				associationMu.Unlock()
				closeAssociation(key)
				continue
			}
		}
		associationMu.Unlock()
		if association == nil {
			started := time.Now()
			lease := snapshot.Lease
			next, armErr := armDatagram(lease, time.Duration(config.ConnectTimeoutSecs)*time.Second)
			lease.wipe()
			if armErr != nil {
				status.UpdateError("udp_association_failed")
				continue
			}
			clientCopy := *client
			association = &udpAssociation{session: next, client: &clientCopy, lastSeen: time.Now(), packetCnt: 1}
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
					if old := associations[oldestKey]; old != nil && old.session != nil {
						old.session.Close()
					}
					delete(associations, oldestKey)
				}
			}
			associations[key] = association
			associationMu.Unlock()
			status.UpdateUDP(snapshot.Lease.SelectionRef, time.Since(started))
			go func(key string, current *udpAssociation) {
				readBuffer := make([]byte, 65535)
				for {
					responseCount, responseErr := current.session.remote.Read(readBuffer)
					if responseErr != nil {
						closeAssociation(key)
						return
					}
					payload, valid := unwrapDatagram(readBuffer[:responseCount])
					if !valid || store.Limiter().Consume(len(payload)) != nil {
						continue
					}
					status.AddUsage(len(payload), 0)
					_, _ = local.WriteToUDP(payload, current.client)
				}
			}(key, association)
		}
		associationMu.Lock()
		current := associations[key]
		if current != nil {
			current.lastSeen = time.Now()
		}
		associationMu.Unlock()
		if current == nil {
			continue
		}
		packet, packetErr := makeDatagramEnvelope(current.session.mode, current.session.sid, targetIP, targetPort, buffer[:count])
		if packetErr == nil {
			if _, writeErr := current.session.remote.Write(packet); writeErr != nil {
				closeAssociation(key)
			}
		}
	}
}

func main() {
	configPath := flag.String("config", "/etc/ultra/forward.json", "runtime configuration")
	flag.Parse()
	raw, err := os.ReadFile(*configPath)
	if err != nil {
		log.Fatal(err)
	}
	var config Config
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&config); err != nil {
		log.Fatal(err)
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
	if _, _, err := parseTarget(config.Target); err != nil {
		log.Fatal(err)
	}
	store := NewPolicyStore(config)
	status := newStatusWriter(config.StatusPath, config.ForwardID, 5*time.Second)
	status.UpdateError("awaiting_policy")
	control := NewControlServer(config.ControlSocket, config, store)
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
		locationContext, cancelLocation := context.WithTimeout(rootContext, locationRequestTimeout+time.Second)
		location := DetectLocation(locationContext, config.LocationCachePath, config.LocationLockPath, config.ZoneAliases)
		cancelLocation()
		status.UpdateLocation(location)
		status.UpdatePolicy(snapshot.Policy.PolicyGeneration)
		runtimeContext, cancelRuntime := context.WithCancel(rootContext)
		runtimeErrors := make(chan error, 2)
		var runtimeWait sync.WaitGroup
		transport := strings.ToLower(snapshot.Policy.Transport)
		if transport == "tcp" || transport == "tcp+udp" {
			runtimeWait.Add(1)
			go func() {
				defer runtimeWait.Done()
				runtimeErrors <- serveTCP(runtimeContext, config, store, status)
			}()
		}
		if transport == "udp" || transport == "tcp+udp" {
			runtimeWait.Add(1)
			go func() {
				defer runtimeWait.Done()
				runtimeErrors <- serveUDP(runtimeContext, config, store, status)
			}()
		}
		go watchRuntimePolicy(runtimeContext, cancelRuntime, store)
		select {
		case <-rootContext.Done():
		case <-runtimeContext.Done():
		case err := <-runtimeErrors:
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
