package main

import (
	"bufio"
	"errors"
	"io"
	"net"
)

func (a *externalUoTAssociation) runReliableCarrier(reader *bufio.Reader, conn net.Conn) error {
	e := runReliableTransport(a.ctx, conn, a.reliable,
		func() (reliableWireFrame, error) {
			f, e := readExternalUoTFrame(reader)
			if e != nil {
				return reliableWireFrame{}, e
			}
			if f.session != a.session {
				return reliableWireFrame{}, a.reliable.fail(errors.New("reliable session mismatch"))
			}
			return reliableWireFrame{kind: f.msgType, seq: f.sequence, body: f.payload}, nil
		},
		func(f reliableWireFrame) error {
			return writeExternalUoTCarrierFrame(conn, externalUoTFrame{msgType: f.kind, session: a.session, sequence: f.seq, payload: f.body})
		},
		a.deliverReliable, externalUoTData, externalUoTUDPData, func(s reliableStats) {
			a.status.SetReliableStats(a.session, s, false)
		})
	if a.reliable.snapshot().Failed != "" {
		a.status.UpdateError("external_uot_reliable_failed")
		a.cancel()
	}
	return e
}
func (a *externalUoTAssociation) deliverReliable(payload []byte) error {
	if len(payload) > externalUoTMaxDatagramBytes {
		return errors.New("reliable payload exceeds limit")
	}
	a.touch()
	if a.socksUDP {
		info, ok := decodeDatagramAddressFirst(payload)
		if !ok {
			return errors.New("invalid reliable SOCKS response")
		}
		var e error
		payload, e = makeLocalUDPResponseFromDatagram(info, info.payload)
		if e != nil {
			return e
		}
	}
	if e := a.ctx.Err(); e != nil {
		return e
	}
	if e := a.store.Limiter().Consume(len(payload)); e != nil {
		return e
	}
	n, e := a.local.WriteToUDP(payload, a.client)
	if e != nil {
		return e
	}
	if n != len(payload) {
		return io.ErrShortWrite
	}
	a.status.AddUsage(n, 0)
	return nil
}
