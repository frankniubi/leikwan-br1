package main

import (
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"
)

const (
	// Default candidate association setup (TCP control plus SID negotiation)
	// happens before the measurement window. Configured association budgets are
	// resolved through probeAssociationTimeout below.
	selectionSetupGrace    = datagramAssociationSetupBudget
	selectionResponseGrace = 5 * time.Second
)

var errSelectionSuperseded = errors.New("selection superseded")

type selectionCoordinator struct {
	runMu   sync.Mutex
	stateMu sync.Mutex
	next    uint64
	latest  uint64
}

var selectionCoordinators sync.Map // forward_id -> *selectionCoordinator

func selectionCoordinatorFor(forwardID int) *selectionCoordinator {
	// Serialize generation rotation with refreshes for one forward as well as
	// concurrent batches for the same generation. This prevents an older
	// probe from replacing a newer policy after the generation changes.
	key := fmt.Sprintf("%d", forwardID)
	value, _ := selectionCoordinators.LoadOrStore(key, &selectionCoordinator{})
	return value.(*selectionCoordinator)
}

func (coordinator *selectionCoordinator) begin() uint64 {
	coordinator.stateMu.Lock()
	defer coordinator.stateMu.Unlock()
	coordinator.next++
	coordinator.latest = coordinator.next
	return coordinator.next
}

func (coordinator *selectionCoordinator) isLatest(epoch uint64) bool {
	coordinator.stateMu.Lock()
	defer coordinator.stateMu.Unlock()
	return coordinator.latest == epoch
}

func controlDeadlineDuration(config Config) time.Duration {
	// Even a single external-UoT lease performs authenticated carrier HELLO.
	// Giving it 2s and subtracting the 5s response grace creates an already
	// expired probe context, rejecting every cold start/renewal in this mode.
	if !config.AutoSelect && !(externalUoTEnabled(config) && stringsContainsUDP(config.Transport)) {
		return 2 * time.Second
	}
	// Keep the control deadline at least as long as the association budget used
	// by probeUDPLease. A configured connect timeout can exceed the default
	// thirteen-second SID scan budget, and a fixed grace would cancel a valid
	// probe before its measurement window starts.
	return probeDuration(config) + probeAssociationTimeout(config) + selectionResponseGrace + activeCandidateFallbackBudget(config)
}

func sameLeaseSelection(left, right ProviderLease) bool {
	if left.CandidateKeyHash != "" && right.CandidateKeyHash != "" &&
		!strings.EqualFold(left.CandidateKeyHash, right.CandidateKeyHash) {
		return false
	}
	if !strings.EqualFold(strings.TrimSpace(left.IP), strings.TrimSpace(right.IP)) || left.Port != right.Port {
		return false
	}
	leftRef := strings.TrimSpace(left.SelectionRef)
	rightRef := strings.TrimSpace(right.SelectionRef)
	if leftRef != "" && rightRef != "" && leftRef == rightRef {
		return true
	}
	// A refreshed lease may carry a new selection reference while an older
	// client is still running (for example during the first material rotation
	// after this release). The relay plus logical credential user is the safest
	// compatibility identity available in that case. Do not match an empty user:
	// two lines can legitimately share one relay socket.
	leftUser := strings.TrimSpace(string(left.User))
	rightUser := strings.TrimSpace(string(right.User))
	return leftUser != "" && rightUser != "" && leftUser == rightUser
}

// stickyLeaseForPolicy returns a fresh candidate only when it represents the
// already-selected network path. The boolean says whether the caller should
// replace the store. A zero value with replace=false means that the existing
// snapshot must remain untouched.
func stickyLeaseForPolicy(store *PolicyStore, policy Policy, leases []ProviderLease) (ProviderLease, bool) {
	if store == nil || len(leases) == 0 {
		return ProviderLease{}, false
	}
	snapshot, ok := store.Snapshot()
	if !ok {
		return ProviderLease{}, false
	}
	defer snapshot.Lease.wipe()
	if snapshot.Policy.PolicyGeneration != policy.PolicyGeneration {
		return ProviderLease{}, false
	}
	// Prefer the opaque stable reference whenever it is available. This avoids
	// treating two logical lines that share a relay and username as one path.
	for _, lease := range leases {
		if strings.TrimSpace(snapshot.Lease.SelectionRef) != "" &&
			strings.TrimSpace(lease.SelectionRef) != "" &&
			strings.TrimSpace(snapshot.Lease.SelectionRef) == strings.TrimSpace(lease.SelectionRef) &&
			strings.EqualFold(strings.TrimSpace(snapshot.Lease.IP), strings.TrimSpace(lease.IP)) &&
			snapshot.Lease.Port == lease.Port {
			return lease, true
		}
	}
	// During a rollout from the old generation-scoped reference, a unique
	// endpoint/user candidate is a safe compatibility match. Require uniqueness
	// so a shared relay cannot silently select a different logical line.
	var fallback ProviderLease
	fallbackCount := 0
	for _, lease := range leases {
		if sameLeaseSelection(snapshot.Lease, lease) {
			fallback = lease
			fallbackCount++
		}
	}
	if fallbackCount == 1 {
		return fallback, true
	}
	return ProviderLease{}, false
}

func storeHasGeneration(store *PolicyStore, generation string) bool {
	if store == nil || strings.TrimSpace(generation) == "" {
		return false
	}
	snapshot, ok := store.Snapshot()
	if !ok {
		return false
	}
	defer snapshot.Lease.wipe()
	return snapshot.Policy.PolicyGeneration == generation
}
