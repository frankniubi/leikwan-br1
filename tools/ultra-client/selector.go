package main

import (
	"context"
	"fmt"
	"net"
	"sort"
	"time"
)

type MaterialPolicy struct {
	DestinationZone     string   `json:"destination_zone"`
	AllowedIngressZones []string `json:"allowed_ingress_zones"`
	FallbackPolicy      string   `json:"fallback_policy"`
}

type SelectionPlan struct {
	TCP []Entry
	UDP []Entry
}

type entryProbe func(context.Context, Entry) (time.Duration, error)

type rankedEntry struct {
	entry    Entry
	index    int
	group    int
	rtt      time.Duration
	probeErr error
}

func rankEntries(ctx context.Context, entries []Entry, policy MaterialPolicy, detectedZone string, maxWorkers int, probe entryProbe) []Entry {
	if len(entries) > 8 {
		entries = entries[:8]
	}
	allowed := make(map[string]bool, len(policy.AllowedIngressZones))
	for _, zone := range policy.AllowedIngressZones {
		if validZoneCode(zone) && zone != "unknown" {
			allowed[zone] = true
		}
	}
	detectedZone = normalizeRuntimeZone(detectedZone)
	fallback := policy.FallbackPolicy
	if fallback != "detected_only" && fallback != "detected_then_any" && fallback != "any" {
		fallback = "detected_then_any"
	}
	candidates := make([]rankedEntry, 0, len(entries))
	for index, entry := range entries {
		entry.IngressZone = normalizeRuntimeZone(entry.IngressZone)
		entry.DestinationZone = normalizeRuntimeZone(entry.DestinationZone)
		if policy.DestinationZone != "" && policy.DestinationZone != "unknown" && entry.DestinationZone != policy.DestinationZone {
			continue
		}
		if len(allowed) > 0 && !allowed[entry.IngressZone] {
			continue
		}
		matches := detectedZone != "unknown" && entry.IngressZone == detectedZone
		group := 0
		switch fallback {
		case "detected_only":
			if !matches {
				continue
			}
		case "detected_then_any":
			if !matches {
				group = 1
			}
		}
		candidates = append(candidates, rankedEntry{entry: entry, index: index, group: group})
	}
	if len(candidates) == 0 {
		return nil
	}
	if maxWorkers < 1 {
		maxWorkers = 1
	}
	if maxWorkers > 4 {
		maxWorkers = 4
	}
	if maxWorkers > len(candidates) {
		maxWorkers = len(candidates)
	}
	if probe == nil {
		probe = probeEntry
	}
	jobs := make(chan int)
	done := make(chan struct{})
	for range maxWorkers {
		go func() {
			for index := range jobs {
				started := time.Now()
				rtt, err := probe(ctx, candidates[index].entry)
				if rtt <= 0 {
					rtt = time.Since(started)
				}
				candidates[index].rtt = rtt
				candidates[index].probeErr = err
			}
			done <- struct{}{}
		}()
	}
	for index := range candidates {
		jobs <- index
	}
	close(jobs)
	for range maxWorkers {
		<-done
	}
	sort.SliceStable(candidates, func(left, right int) bool {
		if candidates[left].group != candidates[right].group {
			return candidates[left].group < candidates[right].group
		}
		leftFailed := candidates[left].probeErr != nil
		rightFailed := candidates[right].probeErr != nil
		if leftFailed != rightFailed {
			return !leftFailed
		}
		if !leftFailed && candidates[left].rtt != candidates[right].rtt {
			return candidates[left].rtt < candidates[right].rtt
		}
		return candidates[left].index < candidates[right].index
	})
	result := make([]Entry, 0, len(candidates))
	for _, candidate := range candidates {
		result = append(result, candidate.entry)
	}
	return result
}

func probeEntry(ctx context.Context, entry Entry) (time.Duration, error) {
	started := time.Now()
	connection, err := (&net.Dialer{}).DialContext(ctx, "tcp", net.JoinHostPort(entry.IP, fmt.Sprint(entry.Port)))
	if err != nil {
		return 0, err
	}
	_ = connection.Close()
	return time.Since(started), nil
}

func buildSelectionPlan(ranked []Entry) SelectionPlan {
	return SelectionPlan{
		TCP: append([]Entry(nil), ranked...),
		UDP: append([]Entry(nil), ranked...),
	}
}

func normalizeRuntimeZone(value string) string {
	if validZoneCode(value) {
		return value
	}
	return "unknown"
}
