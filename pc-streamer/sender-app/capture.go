package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"syscall"
	"time"
)

const (
	rtspPort   = 8554
	streamPath = "pc"
)

var mediamtxYAML = `# Generado automáticamente por HR Media Tube Sender.
rtspAddress: :8554
protocols: [tcp]
paths:
  pc:
    source: publisher
`

func hidden(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
}

// StartStreaming launches MediaMTX + ffmpeg with the requested settings and
// starts the LAN discovery broadcast. windowTitle == "" captures the whole
// desktop; otherwise ffmpeg captures just that window. Video only — no audio
// is captured or sent, by design (the TVs don't need sound from the PC).
func StartStreaming(mode, windowTitle, bitrate string) error {
	state.mu.Lock()
	if state.running {
		state.mu.Unlock()
		return fmt.Errorf("ya hay una transmisión en curso")
	}
	state.mu.Unlock()

	if err := EnsureBinaries(); err != nil {
		return err
	}

	yamlPath := filepath.Join(binDir(), "mediamtx.yml")
	if !fileExists(yamlPath) {
		if err := os.WriteFile(yamlPath, []byte(mediamtxYAML), 0o644); err != nil {
			return fmt.Errorf("no se pudo escribir mediamtx.yml: %w", err)
		}
	}

	mediamtxCmd := exec.Command(mediamtxPath(), yamlPath)
	mediamtxCmd.Dir = binDir()
	hidden(mediamtxCmd)
	if err := mediamtxCmd.Start(); err != nil {
		return fmt.Errorf("no se pudo iniciar MediaMTX: %w", err)
	}

	if !waitForTCP(fmt.Sprintf("127.0.0.1:%d", rtspPort), 8*time.Second) {
		_ = mediamtxCmd.Process.Kill()
		return fmt.Errorf("MediaMTX no arrancó a tiempo")
	}

	// Best-effort: open the RTSP port to the LAN in Windows Firewall. Without
	// this, MediaMTX listens fine locally but other devices (the TVs) on the
	// network get silently blocked. Needs admin rights; if it fails we still
	// continue streaming (it might already be allowed) but flag it in the UI.
	firewallErr := ensureFirewallRule(rtspPort)
	state.mu.Lock()
	state.firewallTried = true
	state.firewallOK = firewallErr == nil
	state.mu.Unlock()

	fps := 30
	var preset string
	var gop int
	if mode == "stable" {
		preset = "veryfast"
		gop = fps * 4
	} else {
		mode = "lowlatency"
		preset = "ultrafast"
		gop = fps
	}

	var args []string
	if windowTitle == "" {
		args = append(args, "-f", "gdigrab", "-framerate", strconv.Itoa(fps), "-i", "desktop")
	} else {
		args = append(args, "-f", "gdigrab", "-framerate", strconv.Itoa(fps), "-i", "title="+windowTitle)
	}

	args = append(args,
		"-an",
		"-c:v", "libx264", "-preset", preset, "-tune", "zerolatency", "-bf", "0",
		"-b:v", bitrate, "-g", strconv.Itoa(gop),
	)

	rtspURL := fmt.Sprintf("rtsp://127.0.0.1:%d/%s", rtspPort, streamPath)
	args = append(args, "-rtsp_transport", "tcp", "-f", "rtsp", rtspURL)

	ffmpegCmd := exec.Command(ffmpegPath(), args...)
	hidden(ffmpegCmd)
	logFile, _ := os.Create(filepath.Join(binDir(), "ffmpeg.log"))
	if logFile != nil {
		ffmpegCmd.Stderr = logFile
	}

	if err := ffmpegCmd.Start(); err != nil {
		_ = mediamtxCmd.Process.Kill()
		return fmt.Errorf("no se pudo iniciar ffmpeg: %w", err)
	}

	stopDiscovery := startDiscoveryBroadcast(rtspPort, streamPath)

	state.mu.Lock()
	state.running = true
	state.mode = mode
	state.windowTitle = windowTitle
	state.bitrate = bitrate
	state.lastError = ""
	state.ffmpegCmd = ffmpegCmd
	state.mediamtxCmd = mediamtxCmd
	state.stopDiscovery = stopDiscovery
	state.mu.Unlock()

	// Watch ffmpeg: if it exits on its own (crash, bad window title, etc.)
	// reflect that in the UI instead of silently looking "running" forever.
	go func(cmd *exec.Cmd) {
		err := cmd.Wait()
		state.mu.Lock()
		if state.ffmpegCmd == cmd { // only react if this is still the active process
			state.running = false
			if err != nil {
				state.lastError = "ffmpeg se detuvo inesperadamente: " + err.Error() + " (revisa bin/ffmpeg.log)"
			}
		}
		state.mu.Unlock()
	}(ffmpegCmd)

	return nil
}

// StopStreaming kills ffmpeg and MediaMTX and stops the discovery broadcast.
func StopStreaming() {
	state.mu.Lock()
	ffmpegCmd := state.ffmpegCmd
	mediamtxCmd := state.mediamtxCmd
	stopDiscovery := state.stopDiscovery
	state.running = false
	state.ffmpegCmd = nil
	state.mediamtxCmd = nil
	state.stopDiscovery = nil
	state.mu.Unlock()

	if stopDiscovery != nil {
		close(stopDiscovery)
	}
	if ffmpegCmd != nil && ffmpegCmd.Process != nil {
		_ = ffmpegCmd.Process.Kill()
	}
	if mediamtxCmd != nil && mediamtxCmd.Process != nil {
		_ = mediamtxCmd.Process.Kill()
	}
}
