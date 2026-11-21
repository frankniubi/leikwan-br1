//go:build android

package main

import (
	"fmt"

	"golang.org/x/sys/unix"
)

// runDirectEgressSmoke exercises only the local fd broker. It creates an
// unconnected IPv4 stream socket, transfers it for Network.bindSocket, and
// closes it without connecting to any network or panel endpoint.
func runDirectEgressSmoke(broker string) error {
	fd, err := unix.Socket(unix.AF_INET, unix.SOCK_STREAM, 0)
	if err != nil {
		return fmt.Errorf("create unconnected socket: %w", err)
	}
	defer unix.Close(fd)
	if err := bindAndroidSocket(broker, fd); err != nil {
		return err
	}
	return nil
}
