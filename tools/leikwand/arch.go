package main

import "runtime"

func runtimeArch() string { return runtime.GOOS + "/" + runtime.GOARCH }
