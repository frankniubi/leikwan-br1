//go:build !windows

package main

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net"
	"os"
	"path/filepath"
	"sync"
	"time"
)

const maxControlPayload = 64 * 1024

func readFrame(reader io.Reader) ([]byte, error) {
	var header [4]byte
	if _, err := io.ReadFull(reader, header[:]); err != nil {
		return nil, err
	}
	length := binary.BigEndian.Uint32(header[:])
	if length == 0 || length > maxControlPayload {
		return nil, ErrFrameTooLarge
	}
	payload := make([]byte, length)
	if _, err := io.ReadFull(reader, payload); err != nil {
		return nil, err
	}
	return payload, nil
}

func writeFrame(writer io.Writer, payload []byte) error {
	if len(payload) == 0 || len(payload) > maxControlPayload {
		return ErrFrameTooLarge
	}
	var header [4]byte
	binary.BigEndian.PutUint32(header[:], uint32(len(payload)))
	if _, err := writer.Write(header[:]); err != nil {
		return err
	}
	_, err := writer.Write(payload)
	return err
}

type ControlServer struct {
	path        string
	config      Config
	store       *PolicyStore
	status      *StatusWriter
	ln          *net.UnixListener
	sem         chan struct{}
	close       chan struct{}
	done        chan struct{}
	once        sync.Once
	peerAllowed func(*net.UnixConn) bool
}

func NewControlServer(path string, config Config, store *PolicyStore, status ...*StatusWriter) *ControlServer {
	var statusWriter *StatusWriter
	if len(status) > 0 {
		statusWriter = status[0]
	}
	return &ControlServer{
		path: path, config: config, store: store, status: statusWriter,
		sem: make(chan struct{}, 4), close: make(chan struct{}), done: make(chan struct{}),
		peerAllowed: sameUserPeer,
	}
}

func (server *ControlServer) Listen() error {
	if server.path == "" || server.store == nil {
		return ErrFrameInvalid
	}
	parent := filepath.Dir(server.path)
	if err := os.MkdirAll(parent, 0o700); err != nil {
		return err
	}
	if err := os.Chmod(parent, 0o700); err != nil {
		return err
	}
	if info, err := os.Lstat(parent); err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		return errors.New("control socket parent is not private")
	}
	if info, err := os.Lstat(server.path); err == nil {
		if info.Mode()&os.ModeSocket == 0 || info.Mode().Perm()&0o077 != 0 {
			return errors.New("control socket is not a private stale socket")
		}
		if err := os.Remove(server.path); err != nil {
			return err
		}
	} else if !os.IsNotExist(err) {
		return err
	}
	listener, err := net.ListenUnix("unix", &net.UnixAddr{Name: server.path, Net: "unix"})
	if err != nil {
		return err
	}
	if err := os.Chmod(server.path, 0o600); err != nil {
		listener.Close()
		_ = os.Remove(server.path)
		return err
	}
	server.ln = listener
	return nil
}

func (server *ControlServer) Serve() error {
	if server.ln == nil {
		return ErrFrameInvalid
	}
	defer close(server.done)
	defer os.Remove(server.path)
	for {
		connection, err := server.ln.AcceptUnix()
		if err != nil {
			select {
			case <-server.close:
				return nil
			default:
			}
			if errors.Is(err, net.ErrClosed) {
				return nil
			}
			return err
		}
		select {
		case server.sem <- struct{}{}:
			go func() {
				defer func() { <-server.sem }()
				server.handle(connection)
			}()
		default:
			_ = connection.Close()
		}
	}
}

func (server *ControlServer) handle(connection *net.UnixConn) {
	defer connection.Close()
	deadline := 2 * time.Second
	if server.config.AutoSelect {
		deadline = 20 * time.Second
	}
	_ = connection.SetDeadline(time.Now().Add(deadline))
	if server.peerAllowed == nil || !server.peerAllowed(connection) {
		_ = writeJSONFrame(connection, map[string]any{"ok": false, "error": "peer_rejected"})
		return
	}
	payload, err := readFrame(connection)
	if err != nil {
		_ = writeJSONFrame(connection, map[string]any{"ok": false, "error": "frame_invalid"})
		return
	}
	defer clearBytes(payload)
	policy, leases, err := decodeLeaseBatchFrame(payload, server.config)
	var selected ProviderLease
	var probeResult udpProbeResult
	if err == nil {
		if len(leases) > 1 && !server.config.AutoSelect {
			err = ErrLeaseInvalid
		} else if server.config.AutoSelect && len(leases) > 1 {
			selectionContext, cancel := context.WithTimeout(context.Background(), deadline)
			selected, probeResult, err = selectBestLease(selectionContext, server.config, policy, leases)
			cancel()
		} else {
			selected = leases[0]
		}
	}
	if err == nil {
		err = server.store.Replace(policy, selected)
		if err == nil && server.status != nil && probeResult.Sent > 0 {
			if stringsContainsUDP(policy.Transport) {
				server.status.UpdateUDPProbe(selected.SelectionRef, probeResult)
			} else {
				server.status.UpdateTCP(selected.SelectionRef, probeResult.MedianRTT)
			}
		}
	}
	selected.wipe()
	for index := range leases {
		leases[index].wipe()
	}
	if err != nil {
		_ = writeJSONFrame(connection, map[string]any{"ok": false, "error": controlErrorName(err)})
		return
	}
	_ = writeJSONFrame(connection, map[string]any{"ok": true, "forward_id": policy.ForwardID, "policy_generation": policy.PolicyGeneration})
}

func writeJSONFrame(connection io.Writer, value any) error {
	payload, err := json.Marshal(value)
	if err != nil {
		return err
	}
	return writeFrame(connection, payload)
}

func controlErrorName(err error) string {
	switch {
	case errors.Is(err, ErrPolicyRollback):
		return "policy_generation_invalid"
	case errors.Is(err, ErrLeaseReplay):
		return "lease_replayed"
	case errors.Is(err, ErrPolicyExpired):
		return "policy_expired"
	case errors.Is(err, ErrPolicyInvalid), errors.Is(err, ErrLeaseInvalid):
		return "lease_invalid"
	default:
		return "control_rejected"
	}
}

func (server *ControlServer) Close() {
	server.once.Do(func() {
		close(server.close)
		if server.ln != nil {
			_ = server.ln.Close()
		}
		select {
		case <-server.done:
		case <-time.After(2 * time.Second):
		}
		_ = os.Remove(server.path)
	})
}
