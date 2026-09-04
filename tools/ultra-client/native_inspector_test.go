package main

import (
	"testing"

	"github.com/apernet/OpenGFW/analyzer"
)

func TestNativeOpenGFWInspectorBlocksFET(t *testing.T) {
	inspector := newNativeOpenGFWInspector()
	payload := make([]byte, 256)
	for index := range payload {
		if index%2 == 0 {
			payload[index] = 0xaa
		} else {
			payload[index] = 0x55
		}
	}
	if !inspector.Inspect(false, payload) {
		t.Fatal("FET-positive payload was not blocked")
	}
}

func TestNativeOpenGFWInspectorBlocksTrojan(t *testing.T) {
	inspector := newNativeOpenGFWInspector()
	if inspector.Inspect(false, []byte{20, 3, 3, 0, 1, 1}) {
		t.Fatal("Trojan CCS prefix blocked before a positive length sequence")
	}
	if inspector.Inspect(true, make([]byte, 175)) {
		t.Fatal("Trojan response fragment blocked too early")
	}
	if inspector.Inspect(false, make([]byte, 650)) {
		t.Fatal("Trojan first client length blocked too early")
	}
	if !inspector.Inspect(false, make([]byte, 10)) {
		t.Fatal("Trojan-positive length sequence was not blocked")
	}
}

func TestNativeOpenGFWInspectorAllowsTLSAndHTTP(t *testing.T) {
	inspector := newNativeOpenGFWInspector()
	payload := append([]byte{0x16, 0x03, 0x03, 0x00, 0x20}, make([]byte, 64)...)
	if inspector.Inspect(false, payload) {
		t.Fatal("ordinary TLS handshake was blocked")
	}
	inspector = newNativeOpenGFWInspector()
	if inspector.Inspect(false, []byte("GET /health HTTP/1.1\r\nHost: example.com\r\n\r\n")) {
		t.Fatal("ordinary HTTP request was blocked")
	}
}

func TestNativeOpenGFWInspectorFailsOpenOnAnalyzerPanic(t *testing.T) {
	inspector := newNativeOpenGFWInspectorWithAnalyzers([]analyzer.TCPAnalyzer{panicAnalyzer{}})
	if inspector.Inspect(false, []byte("payload")) {
		t.Fatal("analyzer panic must fail open")
	}
}

type panicAnalyzer struct{}

func (panicAnalyzer) Name() string { return "panic" }
func (panicAnalyzer) Limit() int   { return 64 }
func (panicAnalyzer) NewTCP(analyzer.TCPInfo, analyzer.Logger) analyzer.TCPStream {
	return panicStream{}
}

type panicStream struct{}

func (panicStream) Feed(bool, bool, bool, int, []byte) (*analyzer.PropUpdate, bool) {
	panic("synthetic analyzer error")
}
func (panicStream) Close(bool) *analyzer.PropUpdate { return nil }
