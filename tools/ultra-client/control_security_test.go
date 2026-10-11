package main

import (
	"strings"
	"testing"
)

func TestControlPipeSecurityDescriptorIsOwnerBound(t *testing.T) {
	sddl := controlPipeSecurityDescriptor()
	if !strings.HasPrefix(sddl, "D:P") {
		t.Fatalf("control pipe DACL must be protected: %q", sddl)
	}
	for _, trustee := range []string{"SY", "BA", "CO"} {
		if !strings.Contains(sddl, ";;;"+trustee+")") {
			t.Fatalf("control pipe DACL missing %s: %q", trustee, sddl)
		}
	}
}
