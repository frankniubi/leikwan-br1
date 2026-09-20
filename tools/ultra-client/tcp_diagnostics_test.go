package main

import (
	"context"
	"errors"
	"strings"
	"testing"
)

func TestCandidateFailurePreservesFirstCause(t *testing.T) {
	first := errors.New("first refused")
	last := errors.New("last reset")
	err := &candidateDialFailure{First: first, Last: last, Terminal: context.DeadlineExceeded}
	for _, want := range []error{first, last, context.DeadlineExceeded} {
		if !errors.Is(err, want) {
			t.Fatalf("lost cause: %v", want)
		}
	}
	if !strings.Contains(err.Error(), "first refused") {
		t.Fatal("first cause missing")
	}
}
func TestTCPDiagnosticTargetIsOpaqueStableHash(t *testing.T) {
	a := tcpTargetHash("example.test:443")
	if len(a) != 64 || a != tcpTargetHash("example.test:443") || a == tcpTargetHash("different.test:443") {
		t.Fatal("target hash")
	}
}
