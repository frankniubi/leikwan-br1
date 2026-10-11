package main

import (
	"fmt"
	"os"
	"strings"
	"sync"
	"time"
)

// Event is one operator-visible line in the LuCI log view. It carries enough
// structure for the GUI to filter without re-parsing free text.
type Event struct {
	At      time.Time `json:"at"`
	Level   string    `json:"level"`
	Scope   string    `json:"scope"`
	Message string    `json:"message"`
}

// Logger writes human-readable lines to stdout (captured by procd into
// logd/journal) and keeps a bounded ring buffer that LuCI can read over the
// control socket. Secrets are redacted before either destination.
type Logger struct {
	mu       sync.Mutex
	ring     []Event
	capacity int
	level    int
}

var logLevels = map[string]int{"debug": 0, "info": 1, "warn": 2, "error": 3}

func newLogger(level string) *Logger {
	parsed, ok := logLevels[strings.ToLower(strings.TrimSpace(level))]
	if !ok {
		parsed = 1
	}
	return &Logger{ring: make([]Event, 0, 256), capacity: 256, level: parsed}
}

func (l *Logger) emit(level, scope, format string, args ...any) {
	rank, ok := logLevels[level]
	if !ok {
		rank = 1
	}
	message := redactSecrets(fmt.Sprintf(format, args...))
	if rank >= l.level {
		fmt.Fprintf(os.Stdout, "%s [%s] %s: %s\n", time.Now().Format("2006-01-02T15:04:05"), strings.ToUpper(level), scope, message)
	}
	event := Event{At: time.Now().UTC(), Level: level, Scope: scope, Message: message}
	l.mu.Lock()
	l.ring = append(l.ring, event)
	if len(l.ring) > l.capacity {
		l.ring = l.ring[len(l.ring)-l.capacity:]
	}
	l.mu.Unlock()
}

func (l *Logger) Debug(scope, format string, args ...any) { l.emit("debug", scope, format, args...) }
func (l *Logger) Info(scope, format string, args ...any)  { l.emit("info", scope, format, args...) }
func (l *Logger) Warn(scope, format string, args ...any)  { l.emit("warn", scope, format, args...) }
func (l *Logger) Error(scope, format string, args ...any) { l.emit("error", scope, format, args...) }

// Events returns the most recent events, newest last.
func (l *Logger) Events(limit int) []Event {
	l.mu.Lock()
	defer l.mu.Unlock()
	if limit <= 0 || limit > len(l.ring) {
		limit = len(l.ring)
	}
	out := make([]Event, limit)
	copy(out, l.ring[len(l.ring)-limit:])
	return out
}
