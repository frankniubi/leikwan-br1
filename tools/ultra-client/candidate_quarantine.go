package main

import (
	"errors"
	"strings"
	"time"
)

var errOperatorQuarantine = errors.New("all candidates are operator quarantined")

func validateCandidateQuarantine(config Config, now time.Time) error {
	if config.CandidateQuarantineProtocol != "" && config.CandidateQuarantineProtocol != "tcp" &&
		config.CandidateQuarantineProtocol != "tcp+udp" {
		return ErrFrameInvalid
	}
	if len(config.CandidateQuarantineHashes) == 0 && config.CandidateQuarantineUntil == "" {
		return nil
	}
	if len(config.CandidateQuarantineHashes) == 0 || len(config.CandidateQuarantineHashes) > 64 {
		return ErrFrameInvalid
	}
	until, err := time.Parse(time.RFC3339, config.CandidateQuarantineUntil)
	if err != nil || until.After(now.Add(24*time.Hour)) {
		return ErrFrameInvalid
	}
	for _, hash := range config.CandidateQuarantineHashes {
		if len(hash) != 64 || !isLowerHex(hash) {
			return ErrFrameInvalid
		}
	}
	return nil // An expired quarantine is harmless and must not prevent startup.
}

func operatorQuarantines(config Config, lease ProviderLease, protocol string, now time.Time) bool {
	if config.CandidateQuarantineProtocol == "tcp" && protocol != "tcp" {
		return false
	}
	until, err := time.Parse(time.RFC3339, config.CandidateQuarantineUntil)
	if err != nil || !until.After(now) {
		return false
	}
	for _, hash := range config.CandidateQuarantineHashes {
		if strings.EqualFold(hash, lease.CandidateKeyHash) {
			return true
		}
	}
	return false
}

func applyCandidateQuarantine(config Config, leases []ProviderLease, now time.Time, status *StatusWriter) ([]ProviderLease, error) {
	if err := validateCandidateQuarantine(config, now); err != nil {
		for index := range leases {
			leases[index].wipe()
		}
		return nil, err
	}
	until, _ := time.Parse(time.RFC3339, config.CandidateQuarantineUntil)
	active := len(config.CandidateQuarantineHashes) > 0 && until.After(now)
	allowed := make([]ProviderLease, 0, len(leases))
	excluded := 0
	for _, lease := range leases {
		blocked := false
		if active {
			for _, hash := range config.CandidateQuarantineHashes {
				if strings.EqualFold(hash, lease.CandidateKeyHash) {
					blocked = true
					break
				}
			}
		}
		if blocked {
			excluded++
			if config.CandidateQuarantineProtocol == "tcp" {
				// Retain authorization for the independent logical UDP/UoT
				// carrier. The TCP pool and its rescue path enforce exclusion.
				allowed = append(allowed, lease)
			} else {
				lease.wipe()
			}
		} else {
			allowed = append(allowed, lease)
		}
	}
	if status != nil {
		status.update(func(s *RuntimeStatus) {
			s.OperatorQuarantineActive = active
			s.OperatorQuarantineUntil = config.CandidateQuarantineUntil
			s.OperatorQuarantineExcluded = excluded
			s.OperatorQuarantineProtocol = config.CandidateQuarantineProtocol
		})
	}
	if len(allowed) == 0 && excluded > 0 {
		return nil, errOperatorQuarantine
	}
	return allowed, nil
}
