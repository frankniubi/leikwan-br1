package main

import (
	"os"
	"time"
)

// watchCredentials adopts a credential file that appeared or changed while the
// agent was already running. Without this, onboarding would require a service
// restart, and a router enrolled through a path the daemon did not initiate
// would sit idle until the next reboot.
//
// The check is a single stat() per pull cycle, so it costs nothing on the hot
// path.
func (a *Agent) watchCredentials() {
	path := secretPath(a.cfg.DataDir)
	info, err := os.Stat(path)
	if err != nil {
		// The file was removed: an explicit sign-out must actually take
		// effect, including on a running agent.
		a.mu.Lock()
		hadToken := a.panel.hasToken()
		a.secretStamp = time.Time{}
		a.secretSeenAt = time.Now()
		a.mu.Unlock()
		if hadToken {
			a.log.Warn("ctl", "credential file removed; signing out")
			a.signOut()
		}
		return
	}
	a.mu.Lock()
	unchanged := !a.secretStamp.IsZero() && !info.ModTime().After(a.secretStamp)
	a.secretStamp = info.ModTime()
	a.secretSeenAt = time.Now()
	a.mu.Unlock()
	if unchanged {
		return
	}

	secrets := loadSecrets(a.cfg.DataDir)
	if secrets.AgentToken == "" {
		return
	}
	if secrets.AgentToken == a.secrets.AgentToken {
		return
	}
	a.secrets = secrets
	a.deviceID = deviceIdentity(a.cfg, secrets)
	a.deviceNm = deviceDisplayName(a.cfg, secrets)
	a.panel.setToken(secrets.AgentToken, secrets.ClientID)
	a.log.Info("ctl", "adopted credentials for client %d (%s)", secrets.ClientID, secrets.ClientName)
	a.triggerReconcile("credentials_changed")
	if a.cfg.gatewayActive() {
		a.triggerGatewayReconcile()
	}
}

// signOut clears every credential and stops all supervised work.
func (a *Agent) signOut() {
	if err := clearSecrets(a.cfg.DataDir); err != nil {
		a.log.Warn("ctl", "could not remove credentials: %v", err)
	}
	a.mu.Lock()
	a.secrets = SecretState{}
	a.panel.setToken("", 0)
	a.mu.Unlock()
	a.shutdownRuntimes()
	a.log.Warn("ctl", "signed out; all runtimes stopped and credentials removed")
}
