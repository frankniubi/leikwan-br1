package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"os"
	"strings"
	"time"
)

const maxConfigPayload = 64 * 1024

func loadConfig(configPath string, fromStdin bool, reader io.Reader) (Config, error) {
	configPath = strings.TrimSpace(configPath)
	if fromStdin == (configPath != "") {
		return Config{}, ErrFrameInvalid
	}

	var source io.Reader
	var file *os.File
	if fromStdin {
		if reader == nil {
			return Config{}, ErrFrameInvalid
		}
		source = reader
	} else {
		var err error
		file, err = os.Open(configPath)
		if err != nil {
			return Config{}, err
		}
		defer file.Close()
		source = file
	}

	raw, err := io.ReadAll(io.LimitReader(source, maxConfigPayload+1))
	if err != nil {
		clearBytes(raw)
		return Config{}, ErrFrameInvalid
	}
	defer clearBytes(raw)
	if len(raw) > maxConfigPayload {
		return Config{}, ErrFrameTooLarge
	}

	var config Config
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&config); err != nil {
		return Config{}, ErrFrameInvalid
	}
	var trailing any
	if err := decoder.Decode(&trailing); !errors.Is(err, io.EOF) {
		return Config{}, ErrFrameInvalid
	}
	if err := validateCandidateQuarantine(config, time.Now()); err != nil {
		return Config{}, err
	}
	return config, nil
}

func clearBytes(value []byte) {
	for index := range value {
		value[index] = 0
	}
}
