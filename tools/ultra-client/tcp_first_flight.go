package main

import (
	"context"
	"io"
	"net"
	"time"
)

// TCPFirstFlightConfig is a reversible, forward-local compatibility measure,
// not a health verdict. No default route is delayed and this never creates
// authorization: it only reshapes the first upstream write of a stream whose
// candidate was selected from a currently authenticated lease.
//
// The bootstrap config is read once, while grants and policy generations
// renew every few minutes without restarting the runtime. PolicyGeneration
// and ExpiresAt are therefore issue-time checks made by the renderer; gating
// streams on them would silently switch the compatibility off minutes after
// start. Per stream, only the target and the stable candidate (line) hash
// are matched.
type TCPFirstFlightConfig struct {
	Target           string   `json:"target"`
	PolicyGeneration string   `json:"policy_generation"`
	CandidateHashes  []string `json:"candidate_hashes"`
	ExpiresAt        string   `json:"expires_at"`
	PrefixBytes      int      `json:"prefix_bytes"`
	DelayMillis      int      `json:"delay_ms"`
}

func (c TCPFirstFlightConfig) matches(target, candidate string) bool {
	if c.Target == "" || c.Target != target ||
		c.PrefixBytes < 1 || c.PrefixBytes > 16 || c.DelayMillis < 1 || c.DelayMillis > 1000 {
		return false
	}
	for _, hash := range c.CandidateHashes {
		if candidate != "" && hash == candidate {
			return true
		}
	}
	return false
}

// firstFlightConn is used only by the ordinary TCP upstream copy, after the
// provider handshake. Bytes are already encoded by writeXor. Splitting them
// changes neither encoding nor content. One upstream goroutine owns Write.
// Read/downstream, native SOCKS, and external-UoT are not routed through this.
type firstFlightConn struct {
	net.Conn
	ctx       context.Context
	prefix    int
	delay     time.Duration
	started   bool
	notBefore time.Time
}

func (c *firstFlightConn) Write(p []byte) (int, error) {
	if len(p) == 0 {
		return 0, nil
	}
	if err := c.ctx.Err(); err != nil {
		return 0, err
	}
	total := 0
	if !c.started {
		c.started = true
		n := min(c.prefix, len(p))
		// Do not wait for a short application read to fill the prefix.
		for total < n {
			written, err := c.Conn.Write(p[total:n])
			if written < 0 || written > n-total {
				return total, io.ErrShortWrite
			}
			total += written
			if err != nil {
				return total, err
			}
			if written == 0 {
				return total, io.ErrShortWrite
			}
		}
		c.notBefore = time.Now().Add(c.delay)
		p = p[total:]
	}
	if len(p) == 0 {
		return total, nil
	}
	if remaining := time.Until(c.notBefore); remaining > 0 {
		timer := time.NewTimer(remaining)
		defer timer.Stop()
		select {
		case <-c.ctx.Done():
			return total, c.ctx.Err()
		case <-timer.C:
		}
	}
	if err := c.ctx.Err(); err != nil {
		return total, err
	}
	n, err := c.Conn.Write(p)
	return total + n, err
}
