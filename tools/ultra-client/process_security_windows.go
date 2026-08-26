//go:build windows

package main

func applyProcessSecurity() error {
	return nil
}

func lockSecret(value []byte) bool {
	return true
}

func clearSecret(value []byte, locked bool) {
	clearBytes(value)
}
