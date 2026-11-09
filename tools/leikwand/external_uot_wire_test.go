package main

import (
	"encoding/json"
	"strings"
	"testing"
)

// This wire-only regression compiles on the pre-parity baseline, where it fails.
func TestExternalUoTWireContract(t *testing.T) {
	var first, standby ForwardConfig
	wire := `{"forward_id":1,"target":"target.test:53","transport":"udp","ultra_access":{"external_uot":{"enabled":true,"sidecar_target":"first.test:7443","token":"fixture","delivery_mode":"reliable_datagram_v1"}}}`
	if err := json.Unmarshal([]byte(wire), &first); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal([]byte(strings.ReplaceAll(wire, "first.test", "standby.test")), &standby); err != nil {
		t.Fatal(err)
	}
	runtime := buildRuntimeConfig(Config{}, first, runtimePaths{}, first.UltraAccess)
	encoded, err := json.Marshal(runtime)
	if err != nil || !strings.Contains(string(encoded), `"external_uot"`) {
		t.Fatal("sidecar disappeared between pull and runtime bootstrap")
	}
	if contractSignature(first) == contractSignature(standby) {
		t.Fatal("standby reassignment did not change runtime identity")
	}
}
