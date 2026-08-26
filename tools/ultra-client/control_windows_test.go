//go:build windows

package main

import (
	"errors"
	"syscall"
	"testing"
)

func TestNamedPipeConnectSucceeded(t *testing.T) {
	tests := []struct {
		name string
		err  error
		want bool
	}{
		{name: "success", err: syscall.Errno(0), want: true},
		{name: "client connected before wait", err: syscall.Errno(535), want: true},
		{name: "failure", err: syscall.Errno(5), want: false},
		{name: "wrapped failure", err: errors.New("access denied"), want: false},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := namedPipeConnectSucceeded(test.err); got != test.want {
				t.Fatalf("namedPipeConnectSucceeded(%v) = %v, want %v", test.err, got, test.want)
			}
		})
	}
}
