//go:build !android

package main

import "errors"

func runDirectEgressSmoke(_ string) error {
	return errors.New("direct egress smoke test unsupported on this platform")
}
