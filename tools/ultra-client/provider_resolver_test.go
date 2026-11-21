package main

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"reflect"
	"sync/atomic"
	"testing"
	"time"
)

func TestDirectResolverRequiresLiteralScopedDNSServers(t *testing.T) {
	for _, servers := range [][]string{nil, {"dns.example:53"}, {"223.5.5.5"}, {"223.5.5.5:443"}, {"[::1]:0"}} {
		if _, err := providerResolver(Config{DirectEgressRequired: true, EgressDNSServers: servers}); err == nil {
			t.Fatalf("accepted unavailable or nonliteral DNS servers %v", servers)
		}
	}
	servers, err := normalizeEgressDNSServers([]string{" 223.5.5.5:53 ", "223.5.5.5:53", "[2001:db8::53]:53"})
	if err != nil || !reflect.DeepEqual(servers, []string{"223.5.5.5:53", "[2001:db8::53]:53"}) {
		t.Fatalf("normalized servers=%v error=%v", servers, err)
	}
}

func TestDirectResolversDoNotMutateSharedDefault(t *testing.T) {
	original := net.DefaultResolver
	for _, server := range []string{"223.5.5.5:53", "119.29.29.29:53"} {
		config := Config{DirectEgressRequired: true, EgressDNSServers: []string{server}}
		resolver, err := providerResolver(config)
		if err != nil || resolver == original || !resolver.PreferGo || resolver.Dial == nil {
			t.Fatalf("scoped resolver unavailable: %v", err)
		}
		installFallbackResolverIfNeeded(config)
		if net.DefaultResolver != original {
			t.Fatal("strict runtime changed the process-wide resolver")
		}
	}
	resolver, err := providerResolver(Config{})
	if err != nil || resolver != original {
		t.Fatalf("ordinary VPN/default behavior changed: %v", err)
	}
}

func TestDirectProviderWithoutBindingNeverUsesDefaultNetwork(t *testing.T) {
	config := Config{DirectEgressRequired: true, EgressDNSServers: []string{"223.5.5.5:53"}}
	for _, network := range []string{"tcp4", "tcp6", "udp4", "udp6"} {
		if _, err := providerDialer(config, network, time.Second); err == nil {
			t.Fatalf("%s accepted strict mode without a usable interface/broker", network)
		}
	}
}

func TestDirectLocationCannotFallBackToDefaultHTTPTransport(t *testing.T) {
	var requests atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		w.Write([]byte(`{"country":"China","city":"Beijing"}`))
	}))
	defer server.Close()
	previous := locationEndpoint
	locationEndpoint = server.URL
	t.Cleanup(func() { locationEndpoint = previous })
	dir := t.TempDir()
	config := Config{DirectEgressRequired: true, EgressDNSServers: []string{"223.5.5.5:53"}}
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	DetectLocationWithConfig(ctx, config, filepath.Join(dir, "location.json"), filepath.Join(dir, "location.lock"), nil)
	if requests.Load() != 0 {
		t.Fatal("strict location request escaped via the default network")
	}
}
