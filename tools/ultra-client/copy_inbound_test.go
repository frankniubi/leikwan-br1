package main

import (
	"bytes"
	"errors"
	"io"
	"net"
	"path/filepath"
	"strconv"
	"syscall"
	"testing"
	"time"
)

type observedInboundConn struct {
	net.Conn
	read  func([]byte) (int, error)
	write func([]byte) (int, error)
}

func (c observedInboundConn) Read(b []byte) (int, error)  { return c.read(b) }
func (c observedInboundConn) Write(b []byte) (int, error) { return c.write(b) }

func observedInboundTestState(t *testing.T, quota int64) (*PolicyLimiter, *StatusWriter) {
	t.Helper()
	limiter := NewPolicyLimiter()
	if err := limiter.Replace(0, quota, time.Now().Add(time.Minute), "copy-inbound-test"); err != nil {
		t.Fatal(err)
	}
	status := newTestStatusWriter(t, filepath.Join(t.TempDir(), "status.json"), 42, time.Hour)
	t.Cleanup(status.Close)
	return limiter, status
}

func TestCopyInboundObservedDistinguishesRemoteReadReset(t *testing.T) {
	limiter, status := observedInboundTestState(t, 100)
	source := observedInboundConn{read: func([]byte) (int, error) { return 0, syscall.ECONNRESET }}
	destination := observedInboundConn{write: func([]byte) (int, error) {
		t.Fatal("unexpected local write")
		return 0, nil
	}}
	got := copyInboundObserved(destination, source, limiter, status)
	if got.ReadBytes != 0 || got.WrittenBytes != 0 || !errors.Is(got.ReadError, syscall.ECONNRESET) ||
		got.WriteError != nil || got.PolicyError != nil {
		t.Fatalf("unexpected observation: %+v", got)
	}
}

func TestCopyInboundObservedLocalResetRetainsRemoteReply(t *testing.T) {
	limiter, status := observedInboundTestState(t, 100)
	source := observedInboundConn{read: bytes.NewReader([]byte("reply")).Read}
	destination := observedInboundConn{write: func([]byte) (int, error) { return 0, syscall.ECONNRESET }}
	got := copyInboundObserved(destination, source, limiter, status)
	if got.ReadBytes != 5 || got.WrittenBytes != 0 || got.ReadError != nil ||
		!errors.Is(got.WriteError, syscall.ECONNRESET) || got.PolicyError != nil {
		t.Fatalf("local reset misclassified: %+v", got)
	}
	if status.status.BytesIn != 5 {
		t.Fatalf("usage=%d, want admitted read bytes 5", status.status.BytesIn)
	}
}

func TestCopyInboundObservedCompletesShortWrites(t *testing.T) {
	limiter, status := observedInboundTestState(t, 100)
	payload := []byte("complete reply")
	var received bytes.Buffer
	destination := observedInboundConn{write: func(b []byte) (int, error) {
		if len(b) > 2 {
			b = b[:2]
		}
		return received.Write(b)
	}}
	source := observedInboundConn{read: bytes.NewReader(payload).Read}
	got := copyInboundObserved(destination, source, limiter, status)
	if !bytes.Equal(received.Bytes(), payload) || got.ReadBytes != int64(len(payload)) ||
		got.WrittenBytes != int64(len(payload)) || !errors.Is(got.Err(), io.EOF) ||
		got.WriteError != nil || got.PolicyError != nil {
		t.Fatalf("incomplete short writes: %+v received=%q", got, received.Bytes())
	}
}

func TestCopyInboundObservedDataAndReadErrorStillRecordsReply(t *testing.T) {
	limiter, status := observedInboundTestState(t, 100)
	source := observedInboundConn{read: func(b []byte) (int, error) {
		return copy(b, "reply"), syscall.ECONNRESET
	}}
	var received bytes.Buffer
	destination := observedInboundConn{write: received.Write}
	got := copyInboundObserved(destination, source, limiter, status)
	if got.ReadBytes != 5 || got.WrittenBytes != 5 || received.String() != "reply" ||
		!errors.Is(got.ReadError, syscall.ECONNRESET) || got.WriteError != nil || got.PolicyError != nil {
		t.Fatalf("data accompanying read error lost: %+v", got)
	}
}

func TestCopyInboundObservedCountsPartialWriteWithError(t *testing.T) {
	limiter, status := observedInboundTestState(t, 100)
	source := observedInboundConn{read: func(b []byte) (int, error) {
		return copy(b, "reply"), io.EOF
	}}
	destination := observedInboundConn{write: func([]byte) (int, error) { return 2, syscall.EPIPE }}
	got := copyInboundObserved(destination, source, limiter, status)
	if got.ReadBytes != 5 || got.WrittenBytes != 2 || !errors.Is(got.ReadError, io.EOF) ||
		!errors.Is(got.WriteError, syscall.EPIPE) || !errors.Is(got.Err(), syscall.EPIPE) {
		t.Fatalf("partial write accounting lost: %+v", got)
	}
	if status.status.BytesIn != 5 {
		t.Fatalf("usage=%d, want 5", status.status.BytesIn)
	}
}

func TestCopyInboundObservedRejectsZeroProgressAndInvalidWriteCounts(t *testing.T) {
	for _, count := range []int{0, -1, 6} {
		t.Run(strconv.Itoa(count), func(t *testing.T) {
			limiter, status := observedInboundTestState(t, 100)
			source := observedInboundConn{read: bytes.NewReader([]byte("reply")).Read}
			destination := observedInboundConn{write: func([]byte) (int, error) { return count, nil }}
			got := copyInboundObserved(destination, source, limiter, status)
			if got.ReadBytes != 5 || got.WrittenBytes != 0 || got.WriteError == nil || got.ReadError != nil {
				t.Fatalf("invalid write not bounded: %+v", got)
			}
			if count == 0 && !errors.Is(got.WriteError, io.ErrShortWrite) {
				t.Fatalf("zero progress error=%v", got.WriteError)
			}
		})
	}
}

func TestCopyInboundObservedPolicyRejectionIsNotRemoteFailure(t *testing.T) {
	limiter, status := observedInboundTestState(t, 1)
	source := observedInboundConn{read: bytes.NewReader([]byte("reply")).Read}
	destination := observedInboundConn{write: func([]byte) (int, error) {
		t.Fatal("policy-rejected bytes were written")
		return 0, nil
	}}
	got := copyInboundObserved(destination, source, limiter, status)
	if got.ReadBytes != 5 || got.WrittenBytes != 0 || !errors.Is(got.PolicyError, ErrQuotaExhausted) ||
		got.ReadError != nil || got.WriteError != nil || !errors.Is(got.Err(), ErrQuotaExhausted) {
		t.Fatalf("policy failure misclassified: %+v", got)
	}
	if status.status.BytesIn != 0 {
		t.Fatalf("rejected usage counted: %d", status.status.BytesIn)
	}
}

func TestCopyInboundLegacyWrapperReturnsDeliveredBytesAndError(t *testing.T) {
	limiter, status := observedInboundTestState(t, 100)
	source := observedInboundConn{read: bytes.NewReader([]byte("reply")).Read}
	destination := observedInboundConn{write: func([]byte) (int, error) { return 2, syscall.EPIPE }}
	count, err := copyInbound(destination, source, limiter, status)
	if count != 2 || !errors.Is(err, syscall.EPIPE) {
		t.Fatalf("legacy result=(%d,%v), want (2,EPIPE)", count, err)
	}
}
