//go:build windows

package main

import (
	"os"
	"strings"
	"syscall"
	"unsafe"
)

// Named separately from control_windows.go's kernel32 handle so the two files
// stay independent.
var (
	attestKernel32          = syscall.NewLazyDLL("kernel32.dll")
	attestIsDebuggerPresent = attestKernel32.NewProc("IsDebuggerPresent")
	attestRemoteDebugger    = attestKernel32.NewProc("CheckRemoteDebuggerPresent")
)

// debuggerAttached asks the kernel whether this process is being debugged.
func debuggerAttached() bool {
	if present, _, _ := attestIsDebuggerPresent.Call(); present != 0 {
		return true
	}
	handle, err := syscall.GetCurrentProcess()
	if err != nil {
		return false
	}
	var remote int32
	ok, _, _ := attestRemoteDebugger.Call(uintptr(handle), uintptr(unsafe.Pointer(&remote)))
	return ok != 0 && remote != 0
}

// instrumentationDetected reports DLL-injection style tampering.
func instrumentationDetected() bool {
	for _, name := range []string{"LD_PRELOAD", "DYLD_INSERT_LIBRARIES"} {
		if strings.TrimSpace(os.Getenv(name)) != "" {
			return true
		}
	}
	return false
}
