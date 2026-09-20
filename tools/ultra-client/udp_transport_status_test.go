package main

import (
	"path/filepath"
	"testing"
	"time"
)

func TestRuntimeUDPTransportStatusUsesEffectiveBootstrap(t *testing.T) {
	for _, tc := range []struct {
		transport string
		external  bool
		want      string
	}{
		{"tcp", false, "disabled"},
		{"tcp", true, "disabled"},
		{"udp", false, "native_udp"},
		{"tcp+udp", false, "native_udp"},
		{"udp", true, "external_uot"},
		{"tcp+udp", true, "external_uot"},
	} {
		t.Run(tc.transport+"/"+tc.want, func(t *testing.T) {
			status := newStatusWriter(filepath.Join(t.TempDir(), "status.json"), 42, time.Hour)
			defer status.Close()
			status.RecordTransportConfig(Config{Transport: tc.transport, ExternalUoT: ExternalUoTConfig{Enabled: tc.external}})
			status.mu.Lock()
			got := status.status.UDPTransport
			status.mu.Unlock()
			if got != tc.want {
				t.Fatalf("got %s, want %s", got, tc.want)
			}
		})
	}
}
