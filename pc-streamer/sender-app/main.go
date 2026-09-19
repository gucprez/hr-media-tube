package main

import (
	"encoding/json"
	"net"
	"net/http"
	"os/exec"
	"strings"
	"time"
)

const listenAddr = "127.0.0.1:5757"

func main() {
	// Bind first, before anything else. A failure here usually means a copy
	// of the program is already running in the background (closing the
	// browser tab doesn't stop it — only "Salir" does), OR — right after a
	// self-update — the old copy is a few hundred ms away from releasing the
	// port. Retry for a few seconds before giving up, so a self-update
	// relaunch succeeds instead of racing the old process's exit and dying.
	listener := bindWithRetry(5 * time.Second)
	if listener == nil {
		// Still busy after retrying: a copy is genuinely already running.
		// Rather than fail silently and look like "the program won't open",
		// just bring that existing copy's page back up.
		openBrowser("http://" + listenAddr)
		return
	}

	cleanupOldExe()

	// Kick off the one-time download in the background as soon as we start,
	// so it's ready (or already in progress, visible in the UI) immediately.
	go func() {
		if err := EnsureBinaries(); err != nil {
			state.mu.Lock()
			state.lastError = "No se pudo preparar el programa: " + err.Error()
			state.mu.Unlock()
		}
	}()

	http.HandleFunc("/", handleIndex)
	http.HandleFunc("/windows", handleWindows)
	http.HandleFunc("/status", handleStatus)
	http.HandleFunc("/start", handleStart)
	http.HandleFunc("/stop", handleStop)
	http.HandleFunc("/exit", handleExit)
	http.HandleFunc("/update/check", handleUpdateCheck)
	http.HandleFunc("/update/apply", handleUpdateApply)

	// The listener is already bound and accepting at this point, so there's
	// no race to open the browser immediately instead of guessing a delay.
	openBrowser("http://" + listenAddr)

	_ = http.Serve(listener, nil)
}

func bindWithRetry(timeout time.Duration) net.Listener {
	deadline := time.Now().Add(timeout)
	for {
		listener, err := net.Listen("tcp", listenAddr)
		if err == nil {
			return listener
		}
		if time.Now().After(deadline) {
			return nil
		}
		time.Sleep(200 * time.Millisecond)
	}
}

func openBrowser(url string) {
	cmd := exec.Command("cmd", "/c", "start", url)
	hidden(cmd)
	_ = cmd.Start()
}

func handleIndex(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	html := strings.ReplaceAll(pageHTML, "{{VERSION}}", appVersion)
	_, _ = w.Write([]byte(html))
}

func handleWindows(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, map[string]interface{}{"windows": listWindowTitles()})
}

func handleStatus(w http.ResponseWriter, r *http.Request) {
	snap := state.snapshot()
	snap["ip"] = localIP()
	writeJSON(w, snap)
}

func handleStart(w http.ResponseWriter, r *http.Request) {
	mode := r.FormValue("mode")
	windowTitle := r.FormValue("window")
	bitrate := r.FormValue("bitrate")
	if bitrate == "" {
		bitrate = "6M"
	}

	err := StartStreaming(mode, windowTitle, bitrate)
	if err != nil {
		writeJSON(w, map[string]interface{}{"ok": false, "error": err.Error()})
		return
	}
	writeJSON(w, map[string]interface{}{"ok": true})
}

func handleStop(w http.ResponseWriter, r *http.Request) {
	StopStreaming()
	writeJSON(w, map[string]interface{}{"ok": true})
}

func handleExit(w http.ResponseWriter, r *http.Request) {
	StopStreaming()
	writeJSON(w, map[string]interface{}{"ok": true})
	go func() {
		time.Sleep(300 * time.Millisecond)
		exitProcess()
	}()
}

func handleUpdateCheck(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, checkForUpdate())
}

func handleUpdateApply(w http.ResponseWriter, r *http.Request) {
	state.mu.Lock()
	running := state.running
	state.mu.Unlock()
	if running {
		writeJSON(w, map[string]interface{}{"ok": false, "error": "Detén la transmisión antes de actualizar."})
		return
	}

	if err := applyUpdate(); err != nil {
		writeJSON(w, map[string]interface{}{"ok": false, "error": err.Error()})
		return
	}
	writeJSON(w, map[string]interface{}{"ok": true})
}

func writeJSON(w http.ResponseWriter, v interface{}) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}
