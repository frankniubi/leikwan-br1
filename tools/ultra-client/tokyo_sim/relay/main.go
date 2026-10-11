// Command relay is a minimal Ultra relay for the Tokyo game-sim experiment.
//
// Wire protocol is identical to tools/ultra-client/main.go:
//   - TCP control on :60003. Handshake: current greeting {5,5,0,1,2,0x80,0x83,1}
//     (fallback legacy {5,3,2,0x80,0x83,1}) + len(user)+user+len(cred)+cred
//   - {5,cmd,checksum} + address(type1+ip4+port2) with
//     checksum = (0x74 - (cred[6]-48) - (cred[10]-48)) & 0xff.
//   - cmd=3: reply frame {4,1,1,0,1, mode^1, sid0^1, sid1^1, port^0x0101 (2B)}.
//   - cmd=1 (legacy greeting): reply {5,2,1,0} then optional 10-byte success
//     frame {4,1,1,0,1,1,1,1,1,1}; then echo stream: client->relay bytes are
//     XOR 1, relay->client bytes are plain.
//   - UDP datagrams on :60002: envelope {1,0,mode,sid0,sid1,sidC,0,0,0,1,
//     ip4, port2, payload}. Reserved target 2.0.0.0:0 (sid scan candidate or
//     keepalive) is answered only for the selected sidC with the 10-byte
//     SOCKS frame {0,0,0,1,2,0,0,0,0,0}. Business payloads are echoed in
//     SS-none address-first form {1, ip4, port2, payload}.
package main

import (
	"bufio"
	"bytes"
	"encoding/binary"
	"flag"
	"io"
	"log"
	"net"
	"os"
	"sync/atomic"
	"time"
)

var currentHandshakeGreeting = []byte{5, 5, 0, 1, 2, 0x80, 0x83, 1}
var legacyHandshakeGreeting = []byte{5, 3, 2, 0x80, 0x83, 1}

var (
	tcpPort = flag.Int("tcp", 60003, "TCP control listen port")
	udpPort = flag.Int("udp", 60002, "UDP datagram listen port")
	user    = flag.String("user", "tokyo-sim-user", "expected handshake user")
	mode    = flag.Int("mode", 6, "datagram mode (client computes mode = reply3[0]^1)")
	sid0    = flag.Int("sid0", 0x10, "sid prefix byte 0 (client computes sid0 = reply3[1]^1)")
	sid1    = flag.Int("sid1", 0x20, "sid prefix byte 1 (client computes sid1 = reply3[2]^1)")
	selSid  = flag.Int("sel-sid", 0x20, "selected sid third byte in 0x20..0x3f (only candidate answered)")
)

var (
	controlAccepted atomic.Int64
	streamAccepted  atomic.Int64
	rejected        atomic.Int64
	sidArms         atomic.Int64
	keepalives      atomic.Int64
	businessIn      atomic.Int64
	businessEchoed  atomic.Int64
	unknownUDP      atomic.Int64
)

func credentialChecksum(secret string) byte {
	if len(secret) != 16 {
		return 0
	}
	return byte((0x74 - int(secret[6]-'0') - int(secret[10]-'0')) & 0xff)
}

func readLenPrefixed(reader *bufio.Reader) ([]byte, error) {
	length, err := reader.ReadByte()
	if err != nil {
		return nil, err
	}
	payload := make([]byte, int(length))
	_, err = io.ReadFull(reader, payload)
	return payload, err
}

func handleTCP(conn *net.TCPConn, udp *net.UDPConn) {
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(15 * time.Second))
	reader := bufio.NewReader(conn)
	prefix := make([]byte, 2)
	if _, err := io.ReadFull(reader, prefix); err != nil {
		rejected.Add(1)
		return
	}
	switch {
	case bytes.Equal(prefix, []byte{5, 5}):
		tail := make([]byte, 6)
		if _, err := io.ReadFull(reader, tail); err != nil || !bytes.Equal(tail, currentHandshakeGreeting[2:]) {
			rejected.Add(1)
			return
		}
	case bytes.Equal(prefix, []byte{5, 3}):
		tail := make([]byte, 4)
		if _, err := io.ReadFull(reader, tail); err != nil || !bytes.Equal(tail, legacyHandshakeGreeting[2:]) {
			rejected.Add(1)
			return
		}
	default:
		rejected.Add(1)
		return
	}
	gotUser, err := readLenPrefixed(reader)
	if err != nil || string(gotUser) != *user {
		rejected.Add(1)
		return
	}
	credential, err := readLenPrefixed(reader)
	if err != nil || len(credential) != 16 {
		rejected.Add(1)
		return
	}
	tail := make([]byte, 3)
	if _, err := io.ReadFull(reader, tail); err != nil || tail[0] != 5 {
		rejected.Add(1)
		return
	}
	command := tail[1]
	if tail[2] != credentialChecksum(string(credential)) {
		rejected.Add(1)
		return
	}
	addressType, err := reader.ReadByte()
	if err != nil || addressType != 1 {
		rejected.Add(1)
		return
	}
	if _, err := io.CopyN(io.Discard, reader, 6); err != nil { // ip4 + port2
		rejected.Add(1)
		return
	}
	switch command {
	case 3:
		controlAccepted.Add(1)
		encodedPort := uint16(*udpPort) ^ 0x0101
		reply := []byte{4, 1, 1, 0, 1, byte(*mode) ^ 1, byte(*sid0) ^ 1, byte(*sid1) ^ 1, 0, 0}
		binary.BigEndian.PutUint16(reply[8:], encodedPort)
		_, _ = conn.Write(reply)
		log.Printf("control cmd=3 accepted user=%s remote=%s", gotUser, conn.RemoteAddr())
	case 1:
		streamAccepted.Add(1)
		if _, err := conn.Write([]byte{5, 2, 1, 0}); err != nil {
			return
		}
		// Optional ten-byte SOCKS-shaped success frame. Byte 2 must be
		// non-zero or the client classifies the stream as command-rejected.
		if _, err := conn.Write([]byte{4, 1, 1, 0, 1, 1, 1, 1, 1, 1}); err != nil {
			return
		}
		_ = conn.SetDeadline(time.Time{})
		echoStream(conn)
	default:
		rejected.Add(1)
	}
}

func echoStream(conn net.Conn) {
	buffer := make([]byte, 32*1024)
	for {
		count, err := conn.Read(buffer)
		if count > 0 {
			decoded := make([]byte, count)
			for index := 0; index < count; index++ {
				decoded[index] = buffer[index] ^ 1
			}
			if _, writeErr := conn.Write(decoded); writeErr != nil {
				return
			}
		}
		if err != nil {
			return
		}
	}
}

func serveUDP(conn *net.UDPConn) {
	buffer := make([]byte, 65535)
	for {
		count, client, err := conn.ReadFromUDP(buffer)
		if err != nil {
			return
		}
		packet := buffer[:count]
		if count < 16 || packet[0] != 1 || packet[1] != 0 || packet[9] != 1 {
			unknownUDP.Add(1)
			continue
		}
		if packet[3] != byte(*sid0) || packet[4] != byte(*sid1) {
			unknownUDP.Add(1)
			continue
		}
		if bytes.Equal(packet[10:14], net.IPv4(2, 0, 0, 0).To4()) && binary.BigEndian.Uint16(packet[14:16]) == 0 {
			if packet[5] != byte(*selSid) {
				continue
			}
			if sidArms.Load() == 0 {
				sidArms.Add(1)
			} else {
				keepalives.Add(1)
			}
			_, _ = conn.WriteToUDP([]byte{0, 0, 0, 1, 2, 0, 0, 0, 0, 0}, client)
			continue
		}
		businessIn.Add(1)
		payload := append([]byte(nil), packet[16:]...)
		response := make([]byte, 0, 7+len(payload))
		response = append(response, 1)
		response = append(response, packet[10:14]...)
		response = append(response, packet[14:16]...)
		response = append(response, payload...)
		if _, err := conn.WriteToUDP(response, client); err == nil {
			businessEchoed.Add(1)
		}
	}
}

func main() {
	flag.Parse()
	log.SetOutput(os.Stdout)
	log.SetFlags(log.LstdFlags | log.Lmicroseconds)

	udp, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4zero, Port: *udpPort})
	if err != nil {
		log.Fatalf("udp listen :%d failed: %v", *udpPort, err)
	}
	_ = udp.SetReadBuffer(4 << 20)
	_ = udp.SetWriteBuffer(4 << 20)
	tcp, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.IPv4zero, Port: *tcpPort})
	if err != nil {
		log.Fatalf("tcp listen :%d failed: %v", *tcpPort, err)
	}
	log.Printf("ultra relay up udp=%s tcp=%s mode=%d sid=%02x%02x sel=%02x",
		udp.LocalAddr(), tcp.Addr(), *mode, *sid0, *sid1, *selSid)
	go serveUDP(udp)
	go func() {
		ticker := time.NewTicker(10 * time.Second)
		defer ticker.Stop()
		for range ticker.C {
			log.Printf("counters control=%d stream=%d rejected=%d arms=%d keepalives=%d businessIn=%d echoed=%d unknownUDP=%d",
				controlAccepted.Load(), streamAccepted.Load(), rejected.Load(),
				sidArms.Load(), keepalives.Load(), businessIn.Load(), businessEchoed.Load(), unknownUDP.Load())
		}
	}()
	for {
		conn, err := tcp.AcceptTCP()
		if err != nil {
			log.Printf("accept: %v", err)
			continue
		}
		go handleTCP(conn, udp)
	}
}
