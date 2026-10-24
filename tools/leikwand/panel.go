package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"runtime"
	"strings"
	"sync"
	"time"
)

// AgentProtocolVersion identifies this client to the Panel. The Panel keys
// per-platform behaviour off X-Device-Platform, so "openwrt" is the single
// switch that selects router semantics once the Panel knows about them.
const (
	platformName    = "openwrt"
	agentUserAgent  = "leikwan-openwrt-agent"
	platformArchTag = "openwrt"
)

var errNotModified = errors.New("panel: not modified")
var errNoEndpoint = errors.New("panel: no endpoint available")

// PanelClient speaks the deployed /api/client/* contract. It keeps an ordered
// endpoint pool (primary + fallback), fails over on transport errors only, and
// never treats a business error as a transport failure.
type PanelClient struct {
	mu        sync.Mutex
	endpoints []string
	active    int
	token     string
	deviceID  string
	deviceNme string
	clientID  int
	http      *http.Client
	egress    *panelEgress
	backoff   time.Duration
	lastErr   error

	// platformTag starts as "openwrt" so a Panel that knows about routers can
	// apply router semantics. When an older Panel rejects the value we degrade
	// to "linux" once, which the deployed access-policy allow-list accepts.
	platformTag     string
	platformRetried bool

	// leaseKeys supplies the device key for sealed leases. It is attached by
	// the agent; a bare client (tests, one-shot probes) sends no lease key.
	leaseKeys *leaseKeyStore
}

func newPanelClient(cfg Config, secrets SecretState, deviceID, deviceName string) *PanelClient {
	pool := []string{}
	for _, candidate := range []string{secrets.MasterURL, cfg.PanelURL, cfg.FallbackURL} {
		candidate = strings.TrimRight(strings.TrimSpace(candidate), "/")
		if candidate == "" {
			continue
		}
		duplicate := false
		for _, existing := range pool {
			if existing == candidate {
				duplicate = true
				break
			}
		}
		if !duplicate {
			pool = append(pool, candidate)
		}
	}
	egress := newPanelEgress(cfg.PanelEgressInterface)
	transport := &http.Transport{
		// The Panel is always reached directly. A router's environment may
		// carry HTTP(S)_PROXY for other tools; the control plane must not
		// depend on that proxy being up, so the environment is ignored.
		Proxy:                 nil,
		DialContext:           egress.DialContext,
		MaxIdleConns:          8,
		IdleConnTimeout:       90 * time.Second,
		TLSHandshakeTimeout:   10 * time.Second,
		ResponseHeaderTimeout: 40 * time.Second,
	}
	egress.onChange = transport.CloseIdleConnections
	return &PanelClient{
		endpoints:   pool,
		token:       secrets.AgentToken,
		deviceID:    deviceID,
		deviceNme:   deviceName,
		clientID:    secrets.ClientID,
		platformTag: platformName,
		egress:      egress,
		http: &http.Client{
			Timeout:   45 * time.Second,
			Transport: transport,
		},
	}
}

// egressView reports which device Panel traffic is pinned to, and why.
func (c *PanelClient) egressView() (device, reason, override, bindErr string) {
	device, reason, bindErr = c.egress.view()
	return device, reason, c.egress.overrideValue(), bindErr
}

func (c *PanelClient) setToken(token string, clientID int) {
	c.mu.Lock()
	c.token = strings.TrimSpace(token)
	c.clientID = clientID
	c.mu.Unlock()
}

func (c *PanelClient) hasToken() bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.token != ""
}

func (c *PanelClient) baseURL() string {
	c.mu.Lock()
	defer c.mu.Unlock()
	if len(c.endpoints) == 0 {
		return ""
	}
	if c.active >= len(c.endpoints) {
		c.active = 0
	}
	return c.endpoints[c.active]
}

func (c *PanelClient) clientIDValue() int {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.clientID
}

// rotateEndpoint moves to the next control-plane endpoint. It is only called
// after a transport-level failure so a 4xx never burns the fallback.
func (c *PanelClient) rotateEndpoint() bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	if len(c.endpoints) < 2 {
		return false
	}
	c.active = (c.active + 1) % len(c.endpoints)
	return true
}

func (c *PanelClient) endpointLabel() string { return c.baseURL() }

// degradePlatform switches from the router-specific platform tag to the
// generic Linux tag exactly once, and reports whether a retry is worthwhile.
func (c *PanelClient) degradePlatform() bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.platformRetried || c.platformTag != platformName {
		return false
	}
	c.platformRetried = true
	c.platformTag = "linux"
	return true
}

// platform returns the tag currently in use, for diagnostics.
func (c *PanelClient) platform() string {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.platformTag
}

func (c *PanelClient) lastError() error {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.lastErr
}

func (c *PanelClient) noteError(err error) {
	c.mu.Lock()
	c.lastErr = err
	c.mu.Unlock()
}

func (c *PanelClient) headers(req *http.Request) {
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", agentUserAgent+"/"+agentVersion+" ("+runtime.GOOS+"; "+runtime.GOARCH+")")
	c.mu.Lock()
	tag := c.platformTag
	c.mu.Unlock()
	req.Header.Set("X-Device-Platform", tag)
	// Form factor is additive and ignored by Panels that do not know it yet.
	req.Header.Set("X-Device-Form-Factor", "router")
	req.Header.Set("X-Device-Architecture", runtime.GOARCH)
	req.Header.Set("X-Device-Id", c.deviceID)
	req.Header.Set("X-Device-UUID", c.deviceID)
	if c.deviceNme != "" {
		req.Header.Set("X-Device-Name", c.deviceNme)
	}
	req.Header.Set("X-Client-Version", agentVersion)
	c.mu.Lock()
	token := c.token
	c.mu.Unlock()
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
}

// do performs a request against the active endpoint and transparently retries
// the next endpoint on transport failure.
func (c *PanelClient) do(ctx context.Context, method, path string, body any, out any) error {
	return c.doWithHeaders(ctx, method, path, body, out, nil)
}

// doWithHeaders is do with additional request headers for this call only.
func (c *PanelClient) doWithHeaders(ctx context.Context, method, path string, body any, out any, extra map[string]string) error {
	if c.baseURL() == "" {
		return errNoEndpoint
	}
	attempts := len(c.endpoints)
	if attempts < 1 {
		attempts = 1
	}
	var lastErr error
	for attempt := 0; attempt < attempts; attempt++ {
		base := c.baseURL()
		var reader io.Reader
		if body != nil {
			encoded, err := json.Marshal(body)
			if err != nil {
				return err
			}
			reader = bytes.NewReader(encoded)
		}
		req, err := http.NewRequestWithContext(ctx, method, base+path, reader)
		if err != nil {
			return err
		}
		c.headers(req)
		for name, value := range extra {
			req.Header.Set(name, value)
		}
		if body != nil {
			req.Header.Set("Content-Type", "application/json")
		}
		resp, err := c.http.Do(req)
		if err != nil {
			lastErr = err
			c.noteError(err)
			if !c.rotateEndpoint() {
				break
			}
			continue
		}
		payload, readErr := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
		resp.Body.Close()
		if readErr != nil {
			lastErr = readErr
			c.noteError(readErr)
			if !c.rotateEndpoint() {
				break
			}
			continue
		}
		if resp.StatusCode == http.StatusNotModified {
			return errNotModified
		}
		if resp.StatusCode >= 500 {
			lastErr = fmt.Errorf("panel %s returned %d", path, resp.StatusCode)
			c.noteError(lastErr)
			if !c.rotateEndpoint() {
				break
			}
			continue
		}
		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			businessErr := decodePanelError(resp.StatusCode, payload)
			// An older Panel validates the platform against a fixed allow-list
			// and rejects "openwrt". Degrade once to "linux" — which every
			// deployed Panel accepts — and replay the request unchanged.
			if pe, ok := businessErr.(*panelError); ok && platformRejected(pe) && c.degradePlatform() {
				// The replay targets the same endpoint, so it does not use up
				// a failover attempt (degradePlatform succeeds only once).
				attempt--
				continue
			}
			// Business error: do not rotate the endpoint, but surface the
			// Panel's own error code so LuCI can explain what happened.
			return businessErr
		}
		c.noteError(nil)
		if out == nil {
			return nil
		}
		if err := json.Unmarshal(payload, out); err != nil {
			return fmt.Errorf("panel %s: %w", path, err)
		}
		return nil
	}
	if lastErr == nil {
		lastErr = errNoEndpoint
	}
	return lastErr
}

// codeClientOnlineLimit is the Panel's refusal for a device that is not
// already online while the client's online device limit is full. Devices that
// are already online are never kicked; a slot frees when another device has
// been silent for 180s, the tenant kicks one, or the limit is raised.
const codeClientOnlineLimit = "client_online_limit_reached"

// onlineLimitRetryInterval is the steady retry cadence while the device limit
// is full. A slot cannot free faster than the Panel's 180s silence window, so
// retrying every few seconds would only add load and log noise.
const onlineLimitRetryInterval = 60 * time.Second

// panelError carries the Panel's structured error envelope. It is deliberately
// not retried: the code tells the operator exactly what to fix.
type panelError struct {
	Status int
	Code   string
	Msg    string

	// OnlineDeviceLimit and ActiveOnlineDevices are filled for
	// client_online_limit_reached; zero means the Panel did not send them.
	OnlineDeviceLimit   int
	ActiveOnlineDevices int
}

func (e *panelError) Error() string {
	if e.Code != "" {
		return fmt.Sprintf("panel error %d: %s", e.Status, e.Code)
	}
	return fmt.Sprintf("panel error %d", e.Status)
}

// onlineLimitReached reports whether the Panel refused this device because the
// client's online device limit is full.
func (e *panelError) onlineLimitReached() bool {
	return e != nil && e.Code == codeClientOnlineLimit
}

// platformRejected reports a Panel refusal of the "openwrt" platform tag. A
// Panel whose exception handler does not know location_platform_invalid masks
// it as a generic 422 validation_failed, so that shape counts too; the degrade
// it triggers happens at most once and only while the tag is still "openwrt".
func platformRejected(e *panelError) bool {
	if e == nil {
		return false
	}
	return e.Code == "location_platform_invalid" ||
		(e.Status == http.StatusUnprocessableEntity && e.Code == "validation_failed")
}

func decodePanelError(status int, payload []byte) error {
	out := &panelError{Status: status, Code: "http_" + itoa(status)}
	var envelope struct {
		OK      bool            `json:"ok"`
		Error   json.RawMessage `json:"error"`
		Limit   *int            `json:"online_device_limit"`
		Active  *int            `json:"active_online_devices"`
		Details struct {
			Limit  *int `json:"online_device_limit"`
			Active *int `json:"active_online_devices"`
		} `json:"details"`
		Detail struct {
			Code    string `json:"code"`
			Message string `json:"message"`
		} `json:"error_detail"`
	}
	if err := json.Unmarshal(payload, &envelope); err == nil {
		// "error" is a bare code on the agent endpoints, but the Panel's
		// generic exception handler answers {"code","message","reference_id"}.
		var code string
		var object struct {
			Code    string `json:"code"`
			Message string `json:"message"`
		}
		if json.Unmarshal(envelope.Error, &code) == nil && code != "" {
			out.Code = code
		} else if json.Unmarshal(envelope.Error, &object) == nil && object.Code != "" {
			out.Code = object.Code
			out.Msg = object.Message
		}
		if envelope.Detail.Code != "" {
			out.Code = envelope.Detail.Code
		}
		if envelope.Detail.Message != "" {
			out.Msg = envelope.Detail.Message
		}
		if out.onlineLimitReached() {
			// The flat fields are canonical; details mirrors them for clients
			// that only read the nested object.
			out.OnlineDeviceLimit = firstCount(envelope.Limit, envelope.Details.Limit)
			out.ActiveOnlineDevices = firstCount(envelope.Active, envelope.Details.Active)
		}
	}
	return out
}

func firstCount(values ...*int) int {
	for _, value := range values {
		if value != nil && *value >= 0 {
			return *value
		}
	}
	return 0
}

// asOnlineLimitError extracts a client_online_limit_reached refusal from err.
func asOnlineLimitError(err error) (*panelError, bool) {
	var pe *panelError
	if errors.As(err, &pe) && pe.onlineLimitReached() {
		return pe, true
	}
	return nil, false
}

// onlineLimitMessage is the operator-facing explanation shown in LuCI, in
// "leikwand ctl status" and in the log. Without it a refused router looks
// like a bug rather than a full device quota.
func onlineLimitMessage(pe *panelError) string {
	counts := ""
	if pe != nil && pe.OnlineDeviceLimit > 0 {
		counts = fmt.Sprintf(" (%d of %d devices online)", pe.ActiveOnlineDevices, pe.OnlineDeviceLimit)
	}
	return "Device limit reached" + counts + ". Disconnect another device or ask your provider to raise the device limit. " +
		"This router reconnects automatically about 3 minutes after another device disconnects."
}

// describePanelFailure turns a Panel call failure into the text an operator
// reads plus a stable machine-readable code ("" for transport failures).
func describePanelFailure(err error) (message, code string) {
	if err == nil {
		return "", ""
	}
	if pe, ok := asOnlineLimitError(err); ok {
		return onlineLimitMessage(pe), pe.Code
	}
	var pe *panelError
	if errors.As(err, &pe) {
		return redactSecrets(err.Error()), pe.Code
	}
	return redactSecrets(err.Error()), ""
}

// Pull performs one long-poll cycle. A 304 is reported as errNotModified so
// the caller can keep the currently running runtime untouched.
func (c *PanelClient) Pull(ctx context.Context, sinceRevision string, waitSeconds int) (*PullResponse, error) {
	path := "/api/client/configs/pull"
	query := []string{}
	if sinceRevision != "" {
		query = append(query, "since_revision="+urlEncode(sinceRevision))
	}
	if waitSeconds > 0 {
		query = append(query, "wait_seconds="+itoa(clampInt(waitSeconds, 0, 25)))
	}
	if len(query) > 0 {
		path += "?" + strings.Join(query, "&")
	}
	var out PullResponse
	if err := c.do(ctx, http.MethodGet, path, nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// Lease requests a candidate batch. When the agent has a device lease key the
// request carries its public half and sealed leases in the reply are opened
// with it (docs/specs/2026-10-02-ultra-sealed-lease-v1.md). Leases that fail
// to open are dropped and listed in UnsealFailures.
func (c *PanelClient) Lease(ctx context.Context, request LeaseRequest) (*LeaseResponse, error) {
	const path = "/api/client/ultra/lease"
	key := c.leaseKeys.get()
	var extra map[string]string
	if key != nil {
		extra = map[string]string{leaseKeyHeader: leaseKeyHeaderValue(key.PublicKey())}
	}
	var raw json.RawMessage
	if err := c.doWithHeaders(ctx, http.MethodPost, path, request, &raw, extra); err != nil {
		return nil, err
	}
	out, failures, err := decodeLeaseResponse(raw, key)
	if err != nil {
		return nil, fmt.Errorf("panel %s: %w", path, err)
	}
	out.UnsealFailures = failures
	return out, nil
}

func (c *PanelClient) Heartbeat(ctx context.Context, payload HeartbeatPayload) (*HeartbeatResponse, error) {
	var out HeartbeatResponse
	if err := c.do(ctx, http.MethodPost, "/api/client/heartbeat", payload, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *PanelClient) DiagnosticsResult(ctx context.Context, payload any) error {
	return c.do(ctx, http.MethodPost, "/api/client/diagnostics/result", payload, nil)
}

// Login exchanges operator credentials for a client list, then selects the
// target client and returns its agent token. This is the same two-step flow
// the desktop app uses, so no Panel change is required for basic onboarding.
func (c *PanelClient) Login(ctx context.Context, email, password string) (*loginResponse, error) {
	var out loginResponse
	err := c.do(ctx, http.MethodPost, "/api/client/login", map[string]string{
		"email":    email,
		"password": password,
	}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *PanelClient) SelectClient(ctx context.Context, sessionToken string, clientID int) (*selectResponse, error) {
	var out selectResponse
	err := c.do(ctx, http.MethodPost, "/api/client/select", map[string]any{
		"session_token": sessionToken,
		"client_id":     clientID,
	}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// PairWithCode redeems a router pairing code. The Panel endpoint is additive:
// when it is absent (older Panel) the error is surfaced verbatim so LuCI can
// fall back to the account flow.
func (c *PanelClient) PairWithCode(ctx context.Context, code, deviceID, deviceName string) (*pairResponse, error) {
	var out pairResponse
	err := c.do(ctx, http.MethodPost, "/api/client/devices/openwrt/pair", map[string]string{
		"pairing_code": code,
		"device_id":    deviceID,
		"device_name":  deviceName,
		"platform":     platformName,
		"architecture": runtime.GOARCH,
	}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// Probe performs an authenticated pull with no wait. It is the "Test
// connection" action in LuCI and intentionally costs one round trip only.
func (c *PanelClient) Probe(ctx context.Context) error {
	probeCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	_, err := c.Pull(probeCtx, "", 0)
	return err
}

type loginResponse struct {
	OK           bool   `json:"ok"`
	SessionToken string `json:"session_token"`
	UserID       int    `json:"user_id"`
	Username     string `json:"username"`
	Email        string `json:"email"`
	Clients      []struct {
		ID   int    `json:"id"`
		Name string `json:"name"`
	} `json:"clients"`
}

type selectResponse struct {
	OK         bool   `json:"ok"`
	ClientID   int    `json:"client_id"`
	ClientName string `json:"client_name"`
	AgentToken string `json:"agent_token"`
}

type pairResponse struct {
	OK         bool   `json:"ok"`
	ClientID   int    `json:"client_id"`
	ClientName string `json:"client_name"`
	AgentToken string `json:"agent_token"`
}

// urlEncode escapes the small set of characters that appear in revisions.
func urlEncode(value string) string {
	var b strings.Builder
	for i := 0; i < len(value); i++ {
		c := value[i]
		if (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') ||
			c == '-' || c == '_' || c == '.' || c == '~' {
			b.WriteByte(c)
			continue
		}
		b.WriteString(fmt.Sprintf("%%%02X", c))
	}
	return b.String()
}
