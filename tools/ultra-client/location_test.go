package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func TestParseZoneResponse(t *testing.T) {
	zone, err := parseZoneResponse(strings.NewReader(`{"code":0,"data":{"country":"\u4e2d\u56fd","province":"\u4e0a\u6d77","city":"\u4e0a\u6d77\u5e02","isp":"\u7535\u4fe1"}}`))
	if err != nil || zone.Code != "shanghai" {
		t.Fatalf("zone=%+v err=%v", zone, err)
	}
}

func TestParseZoneResponseRejectsOversizedBody(t *testing.T) {
	_, err := parseZoneResponse(strings.NewReader(`{"code":0,"data":{"province":"` + strings.Repeat("x", 17*1024) + `"}}`))
	if err == nil {
		t.Fatal("oversized response was accepted")
	}
}

func TestDetectLocationCachesAndUsesSingleRefreshWinner(t *testing.T) {
	var hits atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		hits.Add(1)
		time.Sleep(60 * time.Millisecond)
		_, _ = w.Write([]byte(`{"code":0,"data":{"country":"China","province":"Shanghai","city":"Shanghai","isp":"CT"}}`))
	}))
	defer server.Close()
	oldEndpoint := locationEndpoint
	locationEndpoint = server.URL
	defer func() { locationEndpoint = oldEndpoint }()

	root := t.TempDir()
	cachePath := filepath.Join(root, "location.json")
	lockPath := filepath.Join(root, "location.lock")
	aliases := map[string][]string{"shanghai": {"Shanghai"}}
	var wait sync.WaitGroup
	results := make(chan LocationResult, 8)
	for range 8 {
		wait.Add(1)
		go func() {
			defer wait.Done()
			results <- DetectLocation(context.Background(), cachePath, lockPath, aliases)
		}()
	}
	wait.Wait()
	close(results)
	for result := range results {
		if result.Code != "shanghai" {
			t.Fatalf("unexpected concurrent result: %+v", result)
		}
	}
	if hits.Load() != 1 {
		t.Fatalf("refresh requests=%d want=1", hits.Load())
	}
	if runtime.GOOS != "windows" {
		info, err := os.Stat(cachePath)
		if err != nil || info.Mode().Perm() != 0o600 {
			t.Fatalf("cache mode=%v err=%v", info.Mode().Perm(), err)
		}
	}
	result := DetectLocation(context.Background(), cachePath, lockPath, aliases)
	if result.Code != "shanghai" || result.Source != "cache" || hits.Load() != 1 {
		t.Fatalf("fresh cache result=%+v hits=%d", result, hits.Load())
	}
}

func TestDetectLocationUsesSevenDayStaleFallback(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		http.Error(w, "unavailable", http.StatusServiceUnavailable)
	}))
	defer server.Close()
	oldEndpoint := locationEndpoint
	locationEndpoint = server.URL
	defer func() { locationEndpoint = oldEndpoint }()
	root := t.TempDir()
	cachePath := filepath.Join(root, "location.json")
	encoded, _ := json.Marshal(LocationResult{Province: "Shanghai", Code: "shanghai", Source: "network", ObservedAt: time.Now().Add(-48 * time.Hour)})
	if err := os.WriteFile(cachePath, encoded, 0o600); err != nil {
		t.Fatal(err)
	}
	result := DetectLocation(context.Background(), cachePath, filepath.Join(root, "lock"), nil)
	if result.Code != "shanghai" || result.Source != "stale-cache" {
		t.Fatalf("stale fallback=%+v", result)
	}
}

func TestLocationRequestTimeoutIsBounded(t *testing.T) {
	if locationRequestTimeout != 2500*time.Millisecond {
		t.Fatalf("timeout=%s", locationRequestTimeout)
	}
}
