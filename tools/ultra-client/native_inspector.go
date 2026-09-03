package main

import (
	"errors"
	"net"
	"sync"

	"github.com/apernet/OpenGFW/analyzer"
	"github.com/apernet/OpenGFW/analyzer/tcp"
)

var errNativeOpenGFWBlocked = errors.New("native_opengfw_blocked")

type nativeAnalyzerLogger struct{}

func (nativeAnalyzerLogger) Debugf(string, ...interface{}) {}
func (nativeAnalyzerLogger) Infof(string, ...interface{})  {}
func (nativeAnalyzerLogger) Errorf(string, ...interface{}) {}

type nativeAnalyzerStream struct {
	name   string
	stream analyzer.TCPStream
	limit  int
	seen   int
	done   bool
	sample []byte
}

type nativeOpenGFWInspector struct {
	mu        sync.Mutex
	streams   []*nativeAnalyzerStream
	blocked   bool
	upBytes   int
	downBytes int
}

func newNativeOpenGFWInspector() *nativeOpenGFWInspector {
	return newNativeOpenGFWInspectorWithAnalyzers([]analyzer.TCPAnalyzer{
		&tcp.FETAnalyzer{},
		&tcp.TrojanAnalyzer{},
	})
}

func newNativeOpenGFWInspectorWithAnalyzers(analyzers []analyzer.TCPAnalyzer) *nativeOpenGFWInspector {
	info := analyzer.TCPInfo{SrcIP: net.IPv4zero, DstIP: net.IPv4zero}
	streams := make([]*nativeAnalyzerStream, 0, len(analyzers))
	for _, item := range analyzers {
		if item == nil {
			continue
		}
		limit := item.Limit()
		if limit < 1 {
			limit = 16384
		}
		streams = append(streams, &nativeAnalyzerStream{
			name:   item.Name(),
			stream: item.NewTCP(info, nativeAnalyzerLogger{}),
			limit:  limit,
		})
	}
	return &nativeOpenGFWInspector{streams: streams}
}

// Inspect returns true only for a confirmed analyzer positive. All analyzer
// panics and malformed updates are treated as inconclusive and fail open.
func (inspector *nativeOpenGFWInspector) Inspect(reverse bool, data []byte) bool {
	if inspector == nil || len(data) == 0 {
		return false
	}
	inspector.mu.Lock()
	defer inspector.mu.Unlock()
	if inspector.blocked {
		return true
	}
	if reverse {
		inspector.downBytes += len(data)
	} else {
		inspector.upBytes += len(data)
	}
	for _, state := range inspector.streams {
		if state == nil || state.done || state.stream == nil {
			continue
		}
		// FET is intended to classify the first client payload. Waiting for a
		// bounded sample avoids treating a tiny TCP fragment from a normal game
		// as an encrypted-protocol verdict; server-to-client bytes are ignored.
		if state.name == "fet" && reverse {
			continue
		}
		remaining := state.limit - state.seen
		if remaining <= 0 {
			state.done = true
			continue
		}
		chunk := data
		if len(chunk) > remaining {
			chunk = chunk[:remaining]
		}
		if state.name == "fet" && state.seen == 0 && len(state.sample) < 32 {
			state.sample = append(state.sample, chunk...)
			if len(state.sample) < 32 {
				continue
			}
			chunk = state.sample
			if len(chunk) > remaining {
				chunk = chunk[:remaining]
			}
			state.sample = nil
		}
		update, done, panicked := feedNativeAnalyzer(state.stream, reverse, state.seen == 0, chunk)
		state.seen += len(chunk)
		if panicked {
			state.done = true
			continue
		}
		if update != nil {
			if yes, ok := update.M["yes"].(bool); ok && yes {
				inspector.blocked = true
				return true
			}
		}
		if done || state.seen >= state.limit {
			state.done = true
		}
	}
	return false
}

func feedNativeAnalyzer(stream analyzer.TCPStream, reverse, start bool, data []byte) (update *analyzer.PropUpdate, done, panicked bool) {
	defer func() {
		if recover() != nil {
			panicked = true
			update = nil
			done = true
		}
	}()
	update, done = stream.Feed(reverse, start, false, 0, data)
	return update, done, false
}

type nativeInspectingConn struct {
	net.Conn
	inspector *nativeOpenGFWInspector
	reverse   bool
	status    *StatusWriter
	blocked   bool
}

func (conn *nativeInspectingConn) Read(buffer []byte) (int, error) {
	count, readErr := conn.Conn.Read(buffer)
	if count > 0 && conn.inspector != nil && conn.inspector.Inspect(conn.reverse, buffer[:count]) {
		if !conn.blocked && conn.status != nil {
			conn.status.UpdateError("native_opengfw_blocked")
		}
		conn.blocked = true
		return 0, errNativeOpenGFWBlocked
	}
	return count, readErr
}
