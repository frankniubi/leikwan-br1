package main

import "time"

// External UoT consumes a remote UDP socket and TCP carrier per local peer.
// Unlike native UDP, its budget is shared with other clients at the sidecar.
func externalUoTAssociationLimits(config Config) (int, time.Duration) {
	limit := config.UDPMaxAssociations
	if limit < 1 || limit > 8192 {
		limit = 256
	}
	idle := config.UDPAssociationIdleSecs
	if idle < 1 || idle > 3600 {
		idle = 120
	}
	return limit, time.Duration(idle) * time.Second
}

// Called with the association map lock held. Carrier keepalives deliberately
// do not count as user activity, and active sessions are never evicted merely
// to admit a new peer.
func pruneIdleExternalUoTAssociations(associations map[string]*externalUoTAssociation, now time.Time, idle time.Duration) {
	for key, association := range associations {
		if association.idleSince(now) >= idle {
			delete(associations, key)
			association.close()
		}
	}
}
