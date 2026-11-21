//go:build android

package main

import (
	"fmt"
	"io"
	"net"
	"strings"
	"syscall"
	"time"

	"golang.org/x/sys/unix"
)

const androidEgressBrokerTimeout = 3 * time.Second

// configureProviderDialer installs an Android Network.bindSocket bridge on
// every socket created by the dialer. The Java broker receives the unconnected
// descriptor over SCM_RIGHTS and acknowledges only after binding it to the
// selected Network. There is deliberately no process-wide/default-route
// fallback in strict mode.
func configureProviderDialer(dialer *net.Dialer, network string, config Config) (bool, error) {
	_ = network
	if !config.DirectEgressRequired {
		return false, nil
	}
	broker := strings.TrimSpace(config.EgressSocketBroker)
	if broker == "" {
		return false, errDirectEgressUnavailable
	}
	dialer.Control = func(_network, _address string, raw syscall.RawConn) error {
		var bindErr error
		if err := raw.Control(func(fd uintptr) {
			bindErr = bindAndroidSocket(broker, int(fd))
		}); err != nil {
			return err
		}
		return bindErr
	}
	return true, nil
}

func bindAndroidSocket(broker string, socketFD int) error {
	connection, err := net.DialTimeout("unix", broker, androidEgressBrokerTimeout)
	if err != nil {
		return fmt.Errorf("direct egress broker connect: %w", err)
	}
	defer connection.Close()
	if err := connection.SetDeadline(time.Now().Add(androidEgressBrokerTimeout)); err != nil {
		return err
	}
	unixConnection, ok := connection.(*net.UnixConn)
	if !ok {
		return fmt.Errorf("direct egress broker is not unix")
	}
	written, oob, err := unixConnection.WriteMsgUnix([]byte{1}, unix.UnixRights(socketFD), nil)
	if err != nil {
		return fmt.Errorf("direct egress broker request: %w", err)
	}
	if written != 1 || oob == 0 {
		return fmt.Errorf("direct egress broker short request")
	}
	var ack [1]byte
	if _, err := io.ReadFull(connection, ack[:]); err != nil {
		return fmt.Errorf("direct egress broker acknowledgement: %w", err)
	}
	if ack[0] != 0 {
		return errDirectEgressUnavailable
	}
	return nil
}
