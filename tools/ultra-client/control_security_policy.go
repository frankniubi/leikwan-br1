package main

import (
	"os/user"
	"strings"
)

// The control channel is local IPC. Keep its DACL protected and limited to
// the runtime owner plus the operating-system administrators.
const controlPipeSecuritySDDL = "D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;GA;;;CO)"

func controlPipeSecurityDescriptor() string {
	return controlPipeSecuritySDDL
}

// Creator-owner is not reliable for a pipe created by an elevated helper:
// the GUI may use the same account's unelevated token, while Windows assigns
// the pipe's owner to the elevated token. Bind the DACL to the actual account
// SID as well, so lease delivery works across that integrity-level boundary.
func controlPipeSecurityDescriptorForCurrentUser() string {
	current, err := user.Current()
	if err != nil {
		return controlPipeSecurityDescriptor()
	}
	sid := strings.TrimSpace(current.Uid)
	if !strings.HasPrefix(sid, "S-1-") || strings.ContainsAny(sid, "();") {
		return controlPipeSecurityDescriptor()
	}
	return "D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;GA;;;" + sid + ")(A;;GA;;;CO)"
}
