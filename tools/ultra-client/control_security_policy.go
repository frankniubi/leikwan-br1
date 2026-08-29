package main

// The control channel is local IPC. Keep its DACL protected and limited to
// the runtime owner plus the operating-system administrators.
const controlPipeSecuritySDDL = "D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;GA;;;CO)"

func controlPipeSecurityDescriptor() string {
	return controlPipeSecuritySDDL
}
