package main

import (
	"os/exec"
	"sync"
)

// AppState holds all mutable runtime state, guarded by mu.
type AppState struct {
	mu sync.Mutex

	// Streaming state
	running       bool
	mode          string // "lowlatency" | "stable"
	windowTitle   string // "" means whole desktop
	bitrate       string
	lastError     string
	firewallOK    bool
	firewallTried bool

	ffmpegCmd     *exec.Cmd
	mediamtxCmd   *exec.Cmd
	stopDiscovery chan struct{}

	// First-run setup state
	preparing      bool
	prepareMessage string
}

var state = &AppState{
	mode:    "lowlatency",
	bitrate: "6M",
}

func (s *AppState) snapshot() map[string]interface{} {
	s.mu.Lock()
	defer s.mu.Unlock()
	return map[string]interface{}{
		"running":        s.running,
		"mode":           s.mode,
		"windowTitle":    s.windowTitle,
		"bitrate":        s.bitrate,
		"lastError":      s.lastError,
		"firewallOK":     s.firewallOK,
		"firewallTried":  s.firewallTried,
		"preparing":      s.preparing,
		"prepareMessage": s.prepareMessage,
	}
}
