package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"sync"
	"time"
)

// AppliedForward records what is actually running so unrelated Panel updates
// never cause an unnecessary restart of a healthy forward.
type AppliedForward struct {
	ForwardID        int       `json:"forward_id"`
	Contract         string    `json:"contract_signature"`
	PolicyGeneration string    `json:"policy_generation"`
	ListenPort       int       `json:"listen_port"`
	Target           string    `json:"target"`
	Transport        string    `json:"transport"`
	ConfigPath       string    `json:"config_path"`
	ControlSocket    string    `json:"control_socket"`
	StatusPath       string    `json:"status_path"`
	UserStopped      bool      `json:"user_stopped"`
	LastApplyReason  string    `json:"last_apply_reason"`
	UpdatedAt        time.Time `json:"updated_at"`
}

// UsageState carries the byte counters that are sent to the Panel. Ultra usage
// is only cleared once the Panel acknowledges the batch, so a failed heartbeat
// never loses billable traffic.
type UsageState struct {
	PendingUltra    []UltraUsageSnapshot     `json:"pending_ultra_usage"`
	PendingFeedback []UltraCandidateFeedback `json:"pending_candidate_feedback"`
	Sequence        int64                    `json:"sequence"`
	LastTotals      map[string]int64         `json:"last_totals"`
	ReportedIn      int64                    `json:"reported_bytes_in"`
	ReportedOut     int64                    `json:"reported_bytes_out"`
	LastHeartbeatAt time.Time                `json:"last_heartbeat_at"`
}

// PullState records the long-poll cursor so a daemon restart resumes without a
// full re-fetch storm.
type PullState struct {
	ConfigRevision string    `json:"config_revision"`
	ForwardRev     string    `json:"forward_revision"`
	LastPullAt     time.Time `json:"last_pull_at"`
	LastStatus     string    `json:"status"`
	LastError      string    `json:"last_error"`
	Failures       int       `json:"consecutive_failures"`
}

type Store struct {
	dir string

	mu      sync.Mutex
	applied map[int]AppliedForward
	usage   UsageState
	pull    PullState
}

func newStore(dataDir string) (*Store, error) {
	stateDir := filepath.Join(dataDir, "state")
	if err := ensurePrivateDir(stateDir); err != nil {
		return nil, err
	}
	store := &Store{
		dir:     stateDir,
		applied: map[int]AppliedForward{},
		usage:   UsageState{LastTotals: map[string]int64{}},
	}
	_ = store.readJSON("applied.json", &store.applied)
	_ = store.readJSON("usage_state.json", &store.usage)
	_ = store.readJSON("config_pull_state.json", &store.pull)
	if store.applied == nil {
		store.applied = map[int]AppliedForward{}
	}
	if store.usage.LastTotals == nil {
		store.usage.LastTotals = map[string]int64{}
	}
	return store, nil
}

func (s *Store) readJSON(name string, target any) error {
	raw, err := os.ReadFile(filepath.Join(s.dir, name))
	if err != nil {
		return err
	}
	return json.Unmarshal(raw, target)
}

func (s *Store) writeJSON(name string, value any) error {
	raw, err := json.MarshalIndent(value, "", "  ")
	if err != nil {
		return err
	}
	return writeFileAtomic(filepath.Join(s.dir, name), raw, 0o600)
}

func (s *Store) Applied() map[int]AppliedForward {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := make(map[int]AppliedForward, len(s.applied))
	for k, v := range s.applied {
		out[k] = v
	}
	return out
}

func (s *Store) AppliedForward(id int) (AppliedForward, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	value, ok := s.applied[id]
	return value, ok
}

func (s *Store) PutApplied(value AppliedForward) {
	s.mu.Lock()
	s.applied[value.ForwardID] = value
	snapshot := make(map[int]AppliedForward, len(s.applied))
	for k, v := range s.applied {
		snapshot[k] = v
	}
	s.mu.Unlock()
	_ = s.writeJSON("applied.json", snapshot)
}

func (s *Store) DeleteApplied(id int) {
	s.mu.Lock()
	delete(s.applied, id)
	snapshot := make(map[int]AppliedForward, len(s.applied))
	for k, v := range s.applied {
		snapshot[k] = v
	}
	s.mu.Unlock()
	_ = s.writeJSON("applied.json", snapshot)
}

func (s *Store) Usage() UsageState {
	s.mu.Lock()
	defer s.mu.Unlock()
	copyState := s.usage
	copyState.PendingUltra = append([]UltraUsageSnapshot(nil), s.usage.PendingUltra...)
	copyState.PendingFeedback = append([]UltraCandidateFeedback(nil), s.usage.PendingFeedback...)
	return copyState
}

func (s *Store) UpdateUsage(mutate func(*UsageState)) {
	s.mu.Lock()
	if s.usage.LastTotals == nil {
		s.usage.LastTotals = map[string]int64{}
	}
	mutate(&s.usage)
	snapshot := s.usage
	s.mu.Unlock()
	_ = s.writeJSON("usage_state.json", snapshot)
}

func (s *Store) Pull() PullState {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.pull
}

func (s *Store) UpdatePull(mutate func(*PullState)) {
	s.mu.Lock()
	mutate(&s.pull)
	snapshot := s.pull
	s.mu.Unlock()
	_ = s.writeJSON("config_pull_state.json", snapshot)
}
