//go:build windows

package main

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"log"
	"os"
	"strings"
	"sync"
	"syscall"
	"time"
	"unsafe"
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

var (
	kernel32            = syscall.NewLazyDLL("kernel32.dll")
	createNamedPipe     = kernel32.NewProc("CreateNamedPipeW")
	connectNamedPipe    = kernel32.NewProc("ConnectNamedPipe")
	disconnectNamedPipe = kernel32.NewProc("DisconnectNamedPipe")
	closeHandle         = kernel32.NewProc("CloseHandle")
	pipeRejectRemote    = uint32(0x00000008)
	pipeAccessDuplex    = uint32(0x00000003)
	pipeTypeByte        = uint32(0x00000000)
	pipeReadModeByte    = uint32(0x00000000)
	pipeWait            = uint32(0x00000000)
	pipeUnlimited       = uint32(255)
)

type ControlServer struct {
	path      string
	config    Config
	store     *PolicyStore
	status    *StatusWriter
	close     chan struct{}
	done      chan struct{}
	once      sync.Once
	mu        sync.Mutex
	active    syscall.Handle
	listening bool
}

func NewControlServer(path string, config Config, store *PolicyStore, status ...*StatusWriter) *ControlServer {
	var statusWriter *StatusWriter
	if len(status) > 0 {
		statusWriter = status[0]
	}
	return &ControlServer{path: path, config: config, store: store, status: statusWriter, close: make(chan struct{}), done: make(chan struct{})}
}

func (server *ControlServer) Listen() error {
	if server.store == nil || !strings.HasPrefix(strings.ToLower(server.path), `\\.\pipe\`) {
		return ErrFrameInvalid
	}
	server.mu.Lock()
	server.listening = true
	server.mu.Unlock()
	return nil
}

func (server *ControlServer) Serve() error {
	defer close(server.done)
	for {
		select {
		case <-server.close:
			return nil
		default:
		}
		handle, err := server.createPipe()
		if err != nil {
			return err
		}
		server.mu.Lock()
		server.active = handle
		server.mu.Unlock()
		_, _, connectErr := connectNamedPipe.Call(uintptr(handle), 0)
		connected := namedPipeConnectSucceeded(connectErr)
		if connected {
			server.handlePipe(handle)
		}
		if connected {
			_, _, _ = disconnectNamedPipe.Call(uintptr(handle))
		} else {
			_, _, _ = closeHandle.Call(uintptr(handle))
		}
		server.mu.Lock()
		server.active = 0
		server.mu.Unlock()
	}
}

func namedPipeConnectSucceeded(err error) bool {
	return err == nil || err == syscall.Errno(0) || err == syscall.Errno(535) // ERROR_PIPE_CONNECTED
}

func (server *ControlServer) createPipe() (syscall.Handle, error) {
	name, err := syscall.UTF16PtrFromString(server.path)
	if err != nil {
		return 0, err
	}
	result, _, callErr := createNamedPipe.Call(
		uintptr(unsafe.Pointer(name)), uintptr(pipeAccessDuplex), uintptr(pipeTypeByte|pipeReadModeByte|pipeWait|pipeRejectRemote), uintptr(pipeUnlimited),
		uintptr(maxControlPayload+4), uintptr(maxControlPayload+4), uintptr(2000), 0)
	if result == uintptr(syscall.InvalidHandle) {
		return 0, callErr
	}
	return syscall.Handle(result), nil
}

func (server *ControlServer) handlePipe(handle syscall.Handle) {
	file := os.NewFile(uintptr(handle), server.path)
	if file == nil {
		return
	}
	defer file.Close()
	deadline := 2 * time.Second
	if server.config.AutoSelect {
		deadline = 20 * time.Second
	}
	_ = file.SetDeadline(time.Now().Add(deadline))
	payload, err := readFrame(file)
	if err != nil {
		_ = writeJSONFrame(file, map[string]any{"ok": false, "error": "frame_invalid"})
		return
	}
	defer clearBytes(payload)
	policy, leases, err := decodeLeaseBatchFrame(payload, server.config)
	var selected ProviderLease
	var probeResult udpProbeResult
	if err == nil {
		if len(leases) > 1 && !server.config.AutoSelect {
			err = ErrLeaseInvalid
		} else if server.config.AutoSelect {
			log.Printf("Establishing Connection with the Server ...")
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
	if err != nil && server.status != nil && server.config.AutoSelect && len(leases) > 0 {
		server.status.UpdateError("ultra_probe_failed")
	}
	selected.wipe()
	for index := range leases {
		leases[index].wipe()
	}
	if err != nil {
		_ = writeJSONFrame(file, map[string]any{"ok": false, "error": controlErrorName(err)})
		return
	}
	_ = writeJSONFrame(file, map[string]any{"ok": true, "forward_id": policy.ForwardID, "policy_generation": policy.PolicyGeneration})
}

func writeJSONFrame(writer io.Writer, value any) error {
	payload, err := json.Marshal(value)
	if err != nil {
		return err
	}
	return writeFrame(writer, payload)
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
		server.mu.Lock()
		active := server.active
		server.mu.Unlock()
		if active != 0 {
			_, _, _ = closeHandle.Call(uintptr(active))
		}
		select {
		case <-server.done:
		case <-time.After(2 * time.Second):
		}
	})
}
