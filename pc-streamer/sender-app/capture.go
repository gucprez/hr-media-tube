package main

import (
	"bytes"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
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

// detectSystemAudioDevice asks ffmpeg for the list of DirectShow audio
// capture devices and returns the first one that looks like a loopback /
// "hear what you hear" device. Returns "" if none is found (most Windows
// PCs don't have one enabled by default) — streaming then continues
// video-only instead of failing.
func detectSystemAudioDevice() string {
	cmd := exec.Command(ffmpegPath(), "-hide_banner", "-list_devices", "true", "-f", "dshow", "-i", "dummy")
	hidden(cmd)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	_ = cmd.Run() // ffmpeg exits non-zero here on purpose; we only want the device list

	output := stderr.String()
	candidates := []string{"virtual-audio-capturer", "stereo mix", "mezcla estéreo", "cable output", "what u hear"}

	inAudioSection := false
	for _, line := range strings.Split(output, "\n") {
		lower := strings.ToLower(line)
		if strings.Contains(lower, "directshow audio devices") {
			inAudioSection = true
			continue
		}
		if strings.Contains(lower, "directshow video devices") {
			inAudioSection = false
			continue
		}
		if !inAudioSection || !strings.Contains(line, "\"") {
			continue
		}
		start := strings.Index(line, "\"")
		end := strings.LastIndex(line, "\"")
		if start == -1 || end <= start {
			continue
		}
		name := line[start+1 : end]
		nameLower := strings.ToLower(name)
		for _, c := range candidates {
			if strings.Contains(nameLower, c) {
				return name
			}
		}
	}
	return ""
}

// StartStreaming launches MediaMTX + ffmpeg with the requested settings and
// starts the LAN discovery broadcast. windowTitle == "" captures the whole
// desktop; otherwise ffmpeg captures just that window.
func StartStreaming(mode, windowTitle, bitrate string, forceNoAudio bool) error {
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

	audioDevice := ""
	if !forceNoAudio {
		audioDevice = detectSystemAudioDevice()
	}

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

	if audioDevice != "" {
		args = append(args, "-f", "dshow", "-i", "audio="+audioDevice)
	}

	args = append(args,
		"-c:v", "libx264", "-preset", preset, "-tune", "zerolatency", "-bf", "0",
		"-b:v", bitrate, "-g", strconv.Itoa(gop),
	)

	if audioDevice != "" {
		args = append(args, "-c:a", "aac", "-b:a", "128k")
	}

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
	state.audioFound = audioDevice != ""
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
