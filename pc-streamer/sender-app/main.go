package main

import (
	"encoding/json"
	"net/http"
	"os/exec"
	"time"
)

const listenAddr = "127.0.0.1:5757"

func main() {
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

	go openBrowser("http://" + listenAddr)

	_ = http.ListenAndServe(listenAddr, nil)
}

func openBrowser(url string) {
	time.Sleep(500 * time.Millisecond)
	cmd := exec.Command("cmd", "/c", "start", url)
	hidden(cmd)
	_ = cmd.Start()
}

func handleIndex(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	_, _ = w.Write([]byte(pageHTML))
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

func writeJSON(w http.ResponseWriter, v interface{}) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}
