package main

import "testing"

// The vectors below were produced independently (Python hashlib) from the
// Panel's own construction rules in master/src/Support/UltraMode.php. They are
// the contract boundary with the control plane: if any of these change, the
// Panel will reject every lease this agent asks for.
func TestTargetDigestMatchesPanelContract(t *testing.T) {
	cases := map[string]string{
		"example.com:443":     "2d92752e69614799ea8467c10d252c76f79c85845cf23d54406ef49ab463ff0c",
		"  example.com:443  ": "2d92752e69614799ea8467c10d252c76f79c85845cf23d54406ef49ab463ff0c",
	}
	for input, want := range cases {
		if got := targetDigest(input); got != want {
			t.Fatalf("targetDigest(%q) = %s, want %s", input, got, want)
		}
	}
}

func TestNativeScopeDigestMatchesPanelContract(t *testing.T) {
	const (
		forwardID  = 7
		direction  = "cn_to_intl"
		transport  = "tcp+udp"
		routeScope = "global"
	)
	if got, want := nativeScopeDigestV1(forwardID, direction, transport, routeScope),
		"0c85c93e3ac6a0558af9e6bfb3b7418485b83bd29e562b71384719a79ca546d1"; got != want {
		t.Fatalf("nativeScopeDigestV1 = %s, want %s", got, want)
	}
	if got, want := nativeScopeDigestV2(forwardID, direction, transport, routeScope, "restricted"),
		"713bc338a375fae9a79078fbf811c74cdd969fcd3f98edbb93c82bb2517d3941"; got != want {
		t.Fatalf("nativeScopeDigestV2(restricted) = %s, want %s", got, want)
	}
	if got, want := nativeScopeDigestV2(forwardID, direction, transport, routeScope, "unrestricted"),
		"b8c637eee62946dba005f268e182b6e00edb5228e802f668fdc5cf652807955a"; got != want {
		t.Fatalf("nativeScopeDigestV2(unrestricted) = %s, want %s", got, want)
	}
}

// The Panel lowercases direction, transport and scope before hashing, so the
// agent must normalise identically or the digest silently stops matching.
func TestNativeScopeDigestNormalisesInputs(t *testing.T) {
	canonical := nativeScopeDigestV2(7, "cn_to_intl", "tcp+udp", "global", "restricted")
	if got := nativeScopeDigestV2(7, " CN_To_Intl ", "TCP+UDP", " Global ", "RESTRICTED"); got != canonical {
		t.Fatalf("digest is not input-normalised: %s != %s", got, canonical)
	}
}

func TestIsHex64(t *testing.T) {
	if !isHex64("713bc338a375fae9a79078fbf811c74cdd969fcd3f98edbb93c82bb2517d3941") {
		t.Fatal("valid digest rejected")
	}
	for _, bad := range []string{"", "abc", "713BC338A375FAE9A79078FBF811C74CDD969FCD3F98EDBB93C82BB2517D3941", "g13bc338a375fae9a79078fbf811c74cdd969fcd3f98edbb93c82bb2517d3941"} {
		if isHex64(bad) {
			t.Fatalf("invalid digest %q accepted", bad)
		}
	}
}
