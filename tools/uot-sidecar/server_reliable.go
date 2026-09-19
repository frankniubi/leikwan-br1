package main

import (
	"errors"
	"net"
)

func (s *uotSession) runReliableCarrier(conn net.Conn) error {
	return runReliableTransport(s.ctx, conn, s.reliable,
		func() (reliableWireFrame, error) {
			f, e := readFrame(conn, s.server.config.maxFrameBytes)
			if e != nil {
				return reliableWireFrame{}, e
			}
			if f.session != s.id {
				return reliableWireFrame{}, s.reliable.fail(errors.New("reliable session mismatch"))
			}
			return reliableWireFrame{kind: f.msgType, seq: f.seq, body: f.payload}, nil
		},
		func(f reliableWireFrame) error {
			return writeFrame(conn, frame{msgType: f.kind, session: s.id, seq: f.seq, payload: f.body}, s.server.config.maxFrameBytes)
		},
		func(p []byte) error {
			if len(p) > s.framePayloadMax {
				return errors.New("reliable payload exceeds limit")
			}
			return s.sendToUDP(p)
		},
		msgUDPData, msgData,
		func(stats reliableStats) {
			s.server.logger.debugf("external UoT reliable sid=%s stats=%s", s.shortID(), encodeJSON(map[string]any{"reliable": stats}))
		})
}
