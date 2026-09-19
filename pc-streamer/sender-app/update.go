package main

import (
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

type updateStatus struct {
	Checked        bool   `json:"checked"`
	Available      bool   `json:"available"`
	RemoteVersion  string `json:"remoteVersion"`
	CurrentVersion string `json:"currentVersion"`
	Error          string `json:"error,omitempty"`
}

// cleanupOldExe removes the leftover ".old" copy of a previous version, if
// any is still sitting next to us from an earlier self-update.
func cleanupOldExe() {
	exePath, err := os.Executable()
	if err != nil {
		return
	}
	_ = os.Remove(exePath + ".old")
}

// checkForUpdate asks the repo what version is currently published and
// compares it against the version this binary was built with.
func checkForUpdate() updateStatus {
	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Get(rawURL("releases/version.txt"))
	if err != nil {
		return updateStatus{Checked: true, CurrentVersion: appVersion, Error: "no se pudo comprobar actualizaciones (sin internet o servidor no disponible)"}
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return updateStatus{Checked: true, CurrentVersion: appVersion, Error: fmt.Sprintf("no se pudo comprobar actualizaciones (código %d)", resp.StatusCode)}
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return updateStatus{Checked: true, CurrentVersion: appVersion, Error: "no se pudo leer la respuesta del servidor"}
	}
	remote := strings.TrimSpace(string(body))

	available := remote != appVersion
	if remoteNum, err1 := strconv.Atoi(remote); err1 == nil {
		if localNum, err2 := strconv.Atoi(appVersion); err2 == nil {
			available = remoteNum > localNum
		}
	}

	return updateStatus{Checked: true, Available: available, RemoteVersion: remote, CurrentVersion: appVersion}
}

// applyUpdate downloads the currently published .exe and swaps it in for
// the one that's running, then relaunches and exits this process.
//
// Windows won't let you overwrite or delete the bytes of a running .exe,
// but it DOES let you rename it — so: download the new build next to
// ourselves, rename the running exe out of the way (.old), rename the new
// download into its place, start it, and exit. The next run's
// cleanupOldExe() removes the .old leftover.
func applyUpdate() error {
	exePath, err := os.Executable()
	if err != nil {
		return err
	}

	client := &http.Client{Timeout: 5 * time.Minute}
	resp, err := client.Get(rawURL("releases/HRMediaTubeSender.exe"))
	if err != nil {
		return fmt.Errorf("descargando la nueva versión: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return fmt.Errorf("descargando la nueva versión: código %d", resp.StatusCode)
	}

	newPath := exePath + ".new"
	newFile, err := os.Create(newPath)
	if err != nil {
		return fmt.Errorf("no se pudo crear el archivo temporal: %w", err)
	}
	if _, err := io.Copy(newFile, resp.Body); err != nil {
		newFile.Close()
		return fmt.Errorf("guardando la nueva versión: %w", err)
	}
	newFile.Close()

	oldPath := exePath + ".old"
	_ = os.Remove(oldPath)
	if err := os.Rename(exePath, oldPath); err != nil {
		return fmt.Errorf("no se pudo reemplazar el programa actual: %w", err)
	}
	if err := os.Rename(newPath, exePath); err != nil {
		_ = os.Rename(oldPath, exePath) // best-effort restore
		return fmt.Errorf("no se pudo activar la nueva versión: %w", err)
	}

	cmd := exec.Command(exePath)
	cmd.Dir = filepath.Dir(exePath)
	hidden(cmd)
	if err := cmd.Start(); err != nil {
		return fmt.Errorf("no se pudo abrir la nueva versión: %w", err)
	}

	go func() {
		time.Sleep(500 * time.Millisecond)
		exitProcess()
	}()
	return nil
}
