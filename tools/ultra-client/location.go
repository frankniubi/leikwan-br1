package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

const (
	locationBodyLimit      = 16 * 1024
	locationRequestTimeout = 2500 * time.Millisecond
	locationFreshAge       = 24 * time.Hour
	locationStaleAge       = 7 * 24 * time.Hour
)

var locationEndpoint = "https://api.bilibili.com/x/web-interface/zone"

type LocationResult struct {
	Country    string    `json:"country"`
	Province   string    `json:"province"`
	City       string    `json:"city"`
	ISP        string    `json:"isp"`
	Code       string    `json:"code"`
	Source     string    `json:"source"`
	ObservedAt time.Time `json:"observed_at"`
}

func parseZoneResponse(reader io.Reader) (LocationResult, error) {
	limited := io.LimitReader(reader, locationBodyLimit+1)
	raw, err := io.ReadAll(limited)
	if err != nil {
		return LocationResult{}, err
	}
	if len(raw) > locationBodyLimit {
		return LocationResult{}, errors.New("location response too large")
	}
	var response struct {
		Code int `json:"code"`
		Data struct {
			Country  string `json:"country"`
			Province string `json:"province"`
			City     string `json:"city"`
			ISP      string `json:"isp"`
		} `json:"data"`
	}
	if err := json.Unmarshal(raw, &response); err != nil || response.Code != 0 {
		return LocationResult{}, errors.New("location response invalid")
	}
	result := LocationResult{
		Country:  boundedLocationText(response.Data.Country),
		Province: boundedLocationText(response.Data.Province),
		City:     boundedLocationText(response.Data.City),
		ISP:      boundedLocationText(response.Data.ISP),
	}
	result.Code = classifyLocation(result, nil)
	return result, nil
}

func DetectLocation(ctx context.Context, cachePath, lockPath string, aliases map[string][]string) LocationResult {
	now := time.Now().UTC()
	if cached, ok := readLocationCache(cachePath, now, locationFreshAge); ok {
		cached.Source = "cache"
		return cached
	}
	lock, winner := acquireLocationLock(lockPath)
	if !winner {
		deadline := time.NewTimer(locationRequestTimeout + time.Second)
		defer deadline.Stop()
		ticker := time.NewTicker(25 * time.Millisecond)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return staleOrUnknown(cachePath, now)
			case <-deadline.C:
				return staleOrUnknown(cachePath, now)
			case <-ticker.C:
				if cached, ok := readLocationCache(cachePath, time.Now().UTC(), locationFreshAge); ok {
					cached.Source = "cache"
					return cached
				}
			}
		}
	}
	defer func() {
		_ = lock.Close()
		_ = os.Remove(lockPath)
	}()

	requestContext, cancel := context.WithTimeout(ctx, locationRequestTimeout)
	defer cancel()
	request, err := http.NewRequestWithContext(requestContext, http.MethodGet, locationEndpoint, nil)
	if err == nil {
		client := &http.Client{Timeout: locationRequestTimeout}
		var response *http.Response
		response, err = client.Do(request)
		if err == nil {
			defer response.Body.Close()
			if response.StatusCode != http.StatusOK {
				err = fmt.Errorf("location status %d", response.StatusCode)
			} else {
				var result LocationResult
				result, err = parseZoneResponse(response.Body)
				if err == nil {
					result.Code = classifyLocation(result, aliases)
					result.Source = "network"
					result.ObservedAt = time.Now().UTC()
					if writeLocationCache(cachePath, result) == nil {
						return result
					}
					return result
				}
			}
		}
	}
	return staleOrUnknown(cachePath, now)
}

func classifyLocation(result LocationResult, aliases map[string][]string) string {
	combined := map[string][]string{
		"beijing":   {"beijing", "\u5317\u4eac", "\u5317\u4eac\u5e02"},
		"shanghai":  {"shanghai", "\u4e0a\u6d77", "\u4e0a\u6d77\u5e02"},
		"guangzhou": {"guangzhou", "\u5e7f\u5dde", "\u5ee3\u5dde", "\u5e7f\u5dde\u5e02"},
	}
	for code, values := range aliases {
		code = strings.ToLower(strings.TrimSpace(code))
		if validZoneCode(code) {
			combined[code] = append(combined[code], values...)
		}
	}
	haystack := strings.ToLower(strings.Join([]string{result.Country, result.Province, result.City, result.ISP}, " "))
	type match struct {
		code   string
		length int
	}
	matches := []match{}
	for code, values := range combined {
		longest := 0
		for _, value := range values {
			alias := strings.ToLower(strings.TrimSpace(value))
			if alias != "" && strings.Contains(haystack, alias) && len([]rune(alias)) > longest {
				longest = len([]rune(alias))
			}
		}
		if longest > 0 {
			matches = append(matches, match{code: code, length: longest})
		}
	}
	if len(matches) == 0 {
		return "unknown"
	}
	sort.Slice(matches, func(i, j int) bool {
		if matches[i].length == matches[j].length {
			return matches[i].code < matches[j].code
		}
		return matches[i].length > matches[j].length
	})
	if len(matches) > 1 && matches[0].length == matches[1].length {
		return "unknown"
	}
	return matches[0].code
}

func acquireLocationLock(path string) (*os.File, bool) {
	if path == "" {
		return nil, false
	}
	_ = os.MkdirAll(filepath.Dir(path), 0o700)
	file, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
	if err == nil {
		return file, true
	}
	if info, statErr := os.Stat(path); statErr == nil && time.Since(info.ModTime()) > 10*time.Second {
		_ = os.Remove(path)
		file, err = os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
		if err == nil {
			return file, true
		}
	}
	return nil, false
}

func readLocationCache(path string, now time.Time, maxAge time.Duration) (LocationResult, bool) {
	file, err := os.Open(path)
	if err != nil {
		return LocationResult{}, false
	}
	defer file.Close()
	raw, err := io.ReadAll(io.LimitReader(file, locationBodyLimit+1))
	if err != nil || len(raw) > locationBodyLimit {
		return LocationResult{}, false
	}
	var result LocationResult
	if json.Unmarshal(raw, &result) != nil || !validZoneCode(result.Code) || result.ObservedAt.IsZero() {
		return LocationResult{}, false
	}
	age := now.Sub(result.ObservedAt)
	if age < -5*time.Minute || age > maxAge {
		return LocationResult{}, false
	}
	return result, true
}

func staleOrUnknown(path string, now time.Time) LocationResult {
	if cached, ok := readLocationCache(path, now, locationStaleAge); ok {
		cached.Source = "stale-cache"
		return cached
	}
	return LocationResult{Code: "unknown", Source: "unknown", ObservedAt: now}
}

func writeLocationCache(path string, result LocationResult) error {
	if path == "" {
		return errors.New("cache path missing")
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	temporary := filepath.Join(filepath.Dir(path), "."+filepath.Base(path)+fmt.Sprintf(".%d.tmp", os.Getpid()))
	file, err := os.OpenFile(temporary, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	encoded, encodeErr := json.Marshal(result)
	if encodeErr == nil {
		_, encodeErr = file.Write(append(encoded, '\n'))
	}
	if encodeErr == nil {
		encodeErr = file.Sync()
	}
	closeErr := file.Close()
	if encodeErr != nil {
		_ = os.Remove(temporary)
		return encodeErr
	}
	if closeErr != nil {
		_ = os.Remove(temporary)
		return closeErr
	}
	_ = os.Chmod(temporary, 0o600)
	if err := os.Rename(temporary, path); err != nil {
		_ = os.Remove(temporary)
		return err
	}
	return os.Chmod(path, 0o600)
}

func boundedLocationText(value string) string {
	value = strings.TrimSpace(value)
	runes := []rune(value)
	if len(runes) > 128 {
		value = string(runes[:128])
	}
	return value
}

func validZoneCode(value string) bool {
	if value == "unknown" {
		return true
	}
	if value == "" || len(value) > 32 {
		return false
	}
	for _, character := range value {
		if (character < 'a' || character > 'z') && (character < '0' || character > '9') && character != '-' {
			return false
		}
	}
	return true
}
