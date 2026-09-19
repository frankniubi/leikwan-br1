package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
)

// Do not overwrite the first failure with the result of the final fallback.
// Unwrap preserves errors.Is/As for both failure causes and the terminal class.
type candidateDialFailure struct {
	First    error
	Last     error
	Terminal error
}

func (e *candidateDialFailure) Error() string {
	return fmt.Sprintf("%v: first=%v last=%v", e.Terminal, e.First, e.Last)
}
func (e *candidateDialFailure) Unwrap() []error {
	var out []error
	for _, v := range []error{e.Terminal, e.First, e.Last} {
		if v != nil {
			out = append(out, v)
		}
	}
	return out
}
func tcpTargetHash(target string) string {
	s := sha256.Sum256([]byte(target))
	return hex.EncodeToString(s[:])
}
