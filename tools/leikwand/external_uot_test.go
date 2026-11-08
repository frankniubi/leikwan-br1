package main

import (
	"encoding/json"
	"strings"
	"testing"
)

func TestExternalUoTBootstrapAndRotation(t *testing.T) {
	grant := &ExternalUoTGrant{Enabled: true, SidecarTarget: "sidecar.test:7443", Token: "fixture-token", DeliveryMode: "reliable_datagram_v1"}
	if !grant.valid() {
		t.Fatal("valid grant rejected")
	}
	forward := ForwardConfig{ForwardID: 1, Target: "target.test:53", Transport: "udp", UltraAccess: &UltraPolicy{ExternalUoT: grant}}
	config := buildRuntimeConfig(Config{}, forward, runtimePaths{}, forward.UltraAccess)
	wire, err := json.Marshal(config)
	if err != nil || !strings.Contains(string(wire), `"external_uot"`) || !strings.Contains(string(wire), `"reliable_datagram_v1"`) {
		t.Fatal("sidecar bootstrap missing")
	}
	before := contractSignature(forward)
	grant.Token = "rotated"
	if before == contractSignature(forward) {
		t.Fatal("token rotation must restart this runtime")
	}
	before = contractSignature(forward)
	grant.SidecarTarget = "standby.test:7443"
	if before == contractSignature(forward) {
		t.Fatal("sidecar reassignment must restart this runtime")
	}
	if strings.Contains(contractSignature(forward), grant.Token) {
		t.Fatal("applied state leaked token")
	}
}
func TestExternalUoTInvalidFailsClosed(t *testing.T) {
	for _, g := range []*ExternalUoTGrant{
		{Enabled: true, SidecarTarget: "bad:0", Token: "fixture"},
		{Enabled: true, SidecarTarget: "good:7443", Token: ""},
		{Enabled: true, SidecarTarget: "good:7443", Token: "fixture", DeliveryMode: "unknown"},
	} {
		if g.valid() {
			t.Fatal("malformed sidecar accepted")
		}
	}
	if !((*ExternalUoTGrant)(nil)).valid() {
		t.Fatal("legacy native UDP rejected")
	}
}
