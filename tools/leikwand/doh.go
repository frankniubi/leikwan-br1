package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/tls"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"strings"
	"time"
)

// Strict DoH for Panel hostnames. Local DNS for *.leikwanhost.com is
// poisoned in parts of the mainland and fake-ip proxies hand back unroutable
// addresses, so the Panel host is resolved over HTTPS against China-first
// public resolvers, reached by fixed bootstrap IPs through the bound WAN
// device. Same providers and order as the Linux client agent.
type dohProvider struct {
	name      string
	host      string
	bootstrap []string
}

var strictDoHProviders = []dohProvider{
	{name: "AliDNS", host: "dns.alidns.com", bootstrap: []string{"223.5.5.5", "223.6.6.6"}},
	{name: "DNSPod", host: "doh.pub", bootstrap: []string{"1.12.12.12", "120.53.53.53"}},
	{name: "360", host: "doh.360.cn", bootstrap: []string{"101.226.4.6", "218.30.118.6"}},
}

const (
	protectedPanelApex    = "leikwanhost.com"
	strictDoHCacheTTL     = 60 * time.Second
	strictDoHQueryTimeout = 4 * time.Second
	dnsMessageMaxBytes    = 64 * 1024
)

// isProtectedPanelHost reports whether host is a Panel name that must never
// be resolved by the local resolver chain.
func isProtectedPanelHost(host string) bool {
	host = strings.TrimSuffix(strings.ToLower(strings.TrimSpace(host)), ".")
	if host == "" || net.ParseIP(strings.Trim(host, "[]")) != nil {
		return false
	}
	return host == protectedPanelApex || strings.HasSuffix(host, "."+protectedPanelApex)
}

type dohCacheEntry struct {
	expires time.Time
	ips     []net.IP
}

// strictDoH resolves host's A records through the providers in order, over
// device (unbound when device is empty). The first non-empty answer wins.
func (e *panelEgress) strictDoH(ctx context.Context, host, device string) ([]net.IP, error) {
	host = strings.TrimSuffix(strings.ToLower(strings.TrimSpace(host)), ".")
	now := e.now()
	e.mu.Lock()
	if entry, ok := e.dohCache[host]; ok && now.Before(entry.expires) {
		ips := append([]net.IP(nil), entry.ips...)
		e.mu.Unlock()
		return ips, nil
	}
	e.mu.Unlock()

	var failures []string
	for _, provider := range strictDoHProviders {
		for _, bootstrap := range provider.bootstrap {
			ips, err := e.queryDoH(ctx, provider, bootstrap, host, device)
			if err == nil && len(ips) > 0 {
				e.mu.Lock()
				if e.dohCache == nil {
					e.dohCache = map[string]dohCacheEntry{}
				}
				e.dohCache[host] = dohCacheEntry{expires: now.Add(strictDoHCacheTTL), ips: ips}
				e.mu.Unlock()
				return append([]net.IP(nil), ips...), nil
			}
			if err == nil {
				err = errors.New("no usable A records")
			}
			failures = append(failures, fmt.Sprintf("%s@%s: %v", provider.name, bootstrap, err))
			if ctx.Err() != nil {
				return nil, fmt.Errorf("strict DoH for %s: %w", host, ctx.Err())
			}
		}
	}
	return nil, fmt.Errorf("strict DoH failed for %s: %s", host, strings.Join(failures, "; "))
}

func (e *panelEgress) queryDoH(ctx context.Context, provider dohProvider, bootstrap, host, device string) ([]net.IP, error) {
	if e.dohExchange != nil {
		return e.dohExchange(ctx, provider, bootstrap, host, device)
	}
	var idBytes [2]byte
	if _, err := rand.Read(idBytes[:]); err != nil {
		return nil, err
	}
	id := binary.BigEndian.Uint16(idBytes[:])
	query, err := buildDNSAQuery(host, id)
	if err != nil {
		return nil, err
	}
	dialer := &net.Dialer{Timeout: strictDoHQueryTimeout, Control: e.control(device)}
	transport := &http.Transport{
		// Never through a proxy, like every other Panel request.
		Proxy: nil,
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			return dialer.DialContext(ctx, network, net.JoinHostPort(bootstrap, "443"))
		},
		TLSClientConfig:     &tls.Config{ServerName: provider.host, MinVersion: tls.VersionTLS12},
		TLSHandshakeTimeout: strictDoHQueryTimeout,
		DisableKeepAlives:   true,
	}
	defer transport.CloseIdleConnections()
	queryCtx, cancel := context.WithTimeout(ctx, strictDoHQueryTimeout)
	defer cancel()
	request, err := http.NewRequestWithContext(queryCtx, http.MethodPost, "https://"+provider.host+"/dns-query", bytes.NewReader(query))
	if err != nil {
		return nil, err
	}
	request.Header.Set("Content-Type", "application/dns-message")
	request.Header.Set("Accept", "application/dns-message")
	response, err := (&http.Client{Transport: transport}).Do(request)
	if err != nil {
		return nil, err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("http %d", response.StatusCode)
	}
	body, err := io.ReadAll(io.LimitReader(response.Body, dnsMessageMaxBytes))
	if err != nil {
		return nil, err
	}
	return parseDNSAResponse(body, id)
}

// buildDNSAQuery encodes a recursive A/IN query for host (RFC 1035).
func buildDNSAQuery(host string, id uint16) ([]byte, error) {
	host = strings.TrimSuffix(strings.ToLower(strings.TrimSpace(host)), ".")
	if host == "" {
		return nil, errors.New("empty host")
	}
	message := make([]byte, 12, 12+len(host)+6)
	binary.BigEndian.PutUint16(message[0:], id)
	binary.BigEndian.PutUint16(message[2:], 0x0100) // recursion desired
	binary.BigEndian.PutUint16(message[4:], 1)      // one question
	for _, label := range strings.Split(host, ".") {
		if len(label) == 0 || len(label) > 63 {
			return nil, fmt.Errorf("invalid DNS label in %q", host)
		}
		for i := 0; i < len(label); i++ {
			if label[i] >= 0x80 {
				return nil, fmt.Errorf("non-ascii host %q", host)
			}
		}
		message = append(message, byte(len(label)))
		message = append(message, label...)
	}
	message = append(message, 0, 0, 1, 0, 1) // root, type A, class IN
	return message, nil
}

// parseDNSAResponse returns the A records of a response to query id,
// dropping fake-ip addresses.
func parseDNSAResponse(message []byte, id uint16) ([]net.IP, error) {
	if len(message) < 12 {
		return nil, errors.New("truncated DNS response")
	}
	if binary.BigEndian.Uint16(message[0:]) != id {
		return nil, errors.New("DNS response id mismatch")
	}
	flags := binary.BigEndian.Uint16(message[2:])
	if flags&0x8000 == 0 {
		return nil, errors.New("not a DNS response")
	}
	if rcode := flags & 0x000f; rcode != 0 {
		return nil, fmt.Errorf("DNS rcode %d", rcode)
	}
	questions := int(binary.BigEndian.Uint16(message[4:]))
	answers := int(binary.BigEndian.Uint16(message[6:]))
	offset := 12
	var err error
	for i := 0; i < questions; i++ {
		if offset, err = skipDNSName(message, offset); err != nil {
			return nil, err
		}
		offset += 4
		if offset > len(message) {
			return nil, errors.New("truncated DNS question")
		}
	}
	var ips []net.IP
	for i := 0; i < answers; i++ {
		if offset, err = skipDNSName(message, offset); err != nil {
			return nil, err
		}
		if offset+10 > len(message) {
			return nil, errors.New("truncated DNS answer")
		}
		recordType := binary.BigEndian.Uint16(message[offset:])
		recordClass := binary.BigEndian.Uint16(message[offset+2:])
		length := int(binary.BigEndian.Uint16(message[offset+8:]))
		offset += 10
		if offset+length > len(message) {
			return nil, errors.New("truncated DNS record")
		}
		if recordType == 1 && recordClass == 1 && length == 4 {
			ip := net.IPv4(message[offset], message[offset+1], message[offset+2], message[offset+3])
			if !isFakeIP(ip) {
				ips = append(ips, ip)
			}
		}
		offset += length
	}
	return ips, nil
}

func skipDNSName(message []byte, offset int) (int, error) {
	for labels := 0; labels < 128; labels++ {
		if offset >= len(message) {
			return 0, errors.New("truncated DNS name")
		}
		length := int(message[offset])
		switch {
		case length&0xc0 == 0xc0:
			if offset+1 >= len(message) {
				return 0, errors.New("truncated DNS pointer")
			}
			return offset + 2, nil
		case length&0xc0 != 0:
			return 0, errors.New("unsupported DNS label type")
		case length == 0:
			return offset + 1, nil
		}
		offset += 1 + length
	}
	return 0, errors.New("DNS name too long")
}
