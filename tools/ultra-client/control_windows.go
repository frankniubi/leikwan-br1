//go:build windows

package main

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
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
	if err := writeAll(writer, header[:]); err != nil {
		return err
	}
	return writeAll(writer, payload)
}

var (
	kernel32            = syscall.NewLazyDLL("kernel32.dll")
	advapi32            = syscall.NewLazyDLL("advapi32.dll")
	createNamedPipe     = kernel32.NewProc("CreateNamedPipeW")
	connectNamedPipe    = kernel32.NewProc("ConnectNamedPipe")
	disconnectNamedPipe = kernel32.NewProc("DisconnectNamedPipe")
	closeHandle         = kernel32.NewProc("CloseHandle")
	localFree           = kernel32.NewProc("LocalFree")
	convertStringSDDL   = advapi32.NewProc("ConvertStringSecurityDescriptorToSecurityDescriptorW")
	pipeRejectRemote    = uint32(0x00000008)
	pipeAccessDuplex    = uint32(0x00000003)
	pipeTypeByte        = uint32(0x00000000)
	pipeReadModeByte    = uint32(0x00000000)
	pipeWait            = uint32(0x00000000)
	pipeUnlimited       = uint32(255)
)

type windowsSecurityAttributes struct {
	length             uint32
	securityDescriptor uintptr
	inheritHandle      int32
}

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
	security, release, err := newPipeSecurityAttributes()
	if err != nil {
		return 0, err
	}
	defer release()
	result, _, callErr := createNamedPipe.Call(
		uintptr(unsafe.Pointer(name)), uintptr(pipeAccessDuplex), uintptr(pipeTypeByte|pipeReadModeByte|pipeWait|pipeRejectRemote), uintptr(pipeUnlimited),
		uintptr(maxControlPayload+4), uintptr(maxControlPayload+4), uintptr(2000), uintptr(unsafe.Pointer(security)))
	if result == uintptr(syscall.InvalidHandle) {
		return 0, callErr
	}
	return syscall.Handle(result), nil
}

func newPipeSecurityAttributes() (*windowsSecurityAttributes, func(), error) {
	sddl, err := syscall.UTF16PtrFromString(controlPipeSecurityDescriptorForCurrentUser())
	if err != nil {
		return nil, func() {}, err
	}
	var descriptor uintptr
	result, _, callErr := convertStringSDDL.Call(
		uintptr(unsafe.Pointer(sddl)), 1, uintptr(unsafe.Pointer(&descriptor)), 0,
	)
	if result == 0 || descriptor == 0 {
		if callErr == nil {
			callErr = syscall.EINVAL
		}
		return nil, func() {}, callErr
	}
	attributes := &windowsSecurityAttributes{
		length:             uint32(unsafe.Sizeof(windowsSecurityAttributes{})),
		securityDescriptor: descriptor,
	}
	return attributes, func() {
		_, _, _ = localFree.Call(descriptor)
	}, nil
}

func (server *ControlServer) handlePipe(handle syscall.Handle) {
	file := os.NewFile(uintptr(handle), server.path)
	if file == nil {
		return
	}
	defer file.Close()
	deadline := time.Now().Add(controlDeadlineDuration(server.config))
	_ = file.SetDeadline(deadline)
	payload, err := readFrame(file)
	if err != nil {
		_ = writeJSONFrame(file, map[string]any{"ok": false, "error": "frame_invalid"})
		return
	}
	defer clearBytes(payload)
	policy, leases, err := decodeLeaseBatchFrame(payload, server.config)
	var selected ProviderLease
	var probeResult udpProbeResult
	var selectionEpoch uint64
	if err == nil {
		coordinator := selectionCoordinatorFor(policy.ForwardID)
		selectionEpoch = coordinator.begin()
		coordinator.runMu.Lock()
		defer coordinator.runMu.Unlock()
		if !coordinator.isLatest(selectionEpoch) {
			err = errSelectionSuperseded
		}
		if err != nil {
			goto selectionDone
		}
		if storeHasGeneration(server.store, policy.PolicyGeneration) {
			var stickyMatch bool
			selected, stickyMatch = stickyLeaseForPolicy(server.store, policy, leases)
			if !stickyMatch {
				// Do not silently ACK a refresh that cannot prove the selected
				// path. That would leave an expiring credential in the runtime.
				err = ErrLeaseInvalid
			}
		} else if len(leases) > 1 && !server.config.AutoSelect {
			err = ErrLeaseInvalid
		} else if server.config.AutoSelect && len(leases) > 1 {
			logLeaseSelectionStart(policy.ForwardID, len(leases))
			remaining := time.Until(deadline) - selectionResponseGrace
			if remaining <= 0 {
				err = context.DeadlineExceeded
			} else {
				selectionContext, cancel := context.WithTimeout(context.Background(), remaining)
				selected, probeResult, err = selectBestLease(selectionContext, server.config, policy, leases)
				cancel()
			}
		} else {
			selected = leases[0]
		}
	}
selectionDone:
	if err == nil && selectionEpoch != 0 && !selectionCoordinatorFor(policy.ForwardID).isLatest(selectionEpoch) {
		err = errSelectionSuperseded
	}
	if err == nil {
		err = server.store.ReplaceCandidates(policy, selected, leases)
		if err == nil && server.status != nil {
			server.status.UpdatePolicy(policy.PolicyGeneration)
		}
		if err == nil && server.status != nil && probeResult.Sent > 0 {
			if stringsContainsUDP(policy.Transport) {
				server.status.UpdateUDPProbeWithTierAndCandidate(selected.SelectionRef, selected.SelectionTier, selected.CandidateKeyHash, probeResult, selected.LeaseHash)
				server.status.RecordCandidateSuccess(selected.CandidateKeyHash, "udp", selected.LeaseHash)
				server.store.MarkCandidateSuccess(selected.CandidateKeyHash)
			} else {
				server.status.UpdateTCPWithTierAndCandidate(selected.SelectionRef, selected.SelectionTier, selected.CandidateKeyHash, probeResult.MedianRTT, selected.LeaseHash)
				server.status.RecordCandidateSuccess(selected.CandidateKeyHash, "tcp", selected.LeaseHash)
				server.store.MarkCandidateSuccess(selected.CandidateKeyHash)
			}
		}
	}
	if err != nil && server.status != nil && server.config.AutoSelect && len(leases) > 0 {
		server.status.UpdateError(controlErrorName(err))
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
	case errors.Is(err, errProbeTCPFailed):
		return "probe_tcp_failed"
	case errors.Is(err, errProbeUDPFailed):
		return "probe_udp_failed"
	case errors.Is(err, errNoHealthyCandidate):
		return "no_healthy_candidate"
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
